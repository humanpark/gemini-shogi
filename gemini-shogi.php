<?php
/**
 * Plugin Name: Gemini 将棋
 * Description: Gemini APIと連携し、AIと会話しながら将棋が指せるプラグインです。
 * Version: 0.7 (安定化バージョン)
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// プラグイン有効化時の処理
function gemini_shogi_activate_plugin() {}
register_activation_hook(__FILE__, 'gemini_shogi_activate_plugin');

// --- ショートコード登録 ---
function gemini_shogi_game()
{
    return "<div id='gemini-shogi-game'>将棋盤を読み込んでいます...</div>";
}
add_shortcode('gemini-shogi', 'gemini_shogi_game');

// バージョンを0.7に更新
function gemini_shogi_enqueue_scripts()
{
    if (is_singular() && has_shortcode(get_post()->post_content, 'gemini-shogi')) {
        wp_enqueue_style('gemini-shogi-css', plugin_dir_url(__FILE__) . 'css/shogi.css');
        wp_enqueue_script('gemini-shogi-js', plugin_dir_url(__FILE__) . 'js/shogi.js', array('jquery'), '0.7', true);
        wp_localize_script('gemini-shogi-js', 'gemini_shogi_data', array(
            'api_url' => esc_url_raw(rest_url('gemini-shogi/v1/move')),
            'valid_moves_url' => esc_url_raw(rest_url('gemini-shogi/v1/valid_moves_for_piece')),
            'ai_vs_ai_url' => esc_url_raw(rest_url('gemini-shogi/v1/ai_vs_ai_move')),
            'player_move_url' => esc_url_raw(rest_url('gemini-shogi/v1/player_move')),
            'nonce' => wp_create_nonce('wp_rest'),
            'plugin_url' => plugin_dir_url(__FILE__),
            'openrouter_model_name' => get_option('gemini_shogi_openrouter_model_name', 'mistralai/mistral-7b-instruct'),
        ));
    }
}
add_action('wp_enqueue_scripts', 'gemini_shogi_enqueue_scripts');
// --- 管理画面 ---
if (is_admin()) {
    require_once(plugin_dir_path(__FILE__) . 'admin/settings-page.php');
}

// --- REST API エンドポイント登録 ---
function gemini_shogi_register_rest_route()
{
    // プレイヤー vs AI の指し手を取得するエンドポイント
    register_rest_route('gemini-shogi/v1', '/move', array(
        'methods' => 'POST',
        'callback' => 'gemini_shogi_handle_player_vs_ai_move',
        'permission_callback' => function ($request) {
            return wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest');
        }
    ));

    // AI vs AI の指し手を取得するエンドポイント
    register_rest_route('gemini-shogi/v1', '/ai_vs_ai_move', array(
        'methods' => 'POST',
        'callback' => 'gemini_shogi_handle_ai_vs_ai_move',
        'permission_callback' => function ($request) {
            return wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest');
        }
    ));

    // 特定駒の有効な移動先を取得するエンドポイント
    register_rest_route('gemini-shogi/v1', '/valid_moves_for_piece', array(
        'methods' => 'POST',
        'callback' => 'gemini_shogi_handle_get_piece_moves',
        'permission_callback' => function ($request) {
            return wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest');
        }
    ));
 // ★★★ (新規追加) プレイヤーの指し手を処理するエンドポイント ★★★
    register_rest_route('gemini-shogi/v1', '/player_move', [
        'methods' => 'POST',
        'callback' => 'gemini_shogi_handle_player_move',
        'permission_callback' => function ($request) {
            return wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest');
        }
    ]);

}
add_action('rest_api_init', 'gemini_shogi_register_rest_route');

// ★★★ (新規追加) プレイヤーの指し手を処理するハンドラ関数 ★★★
function gemini_shogi_handle_player_move($request) {
    $params = $request->get_json_params();
    $sfen_board = sanitize_text_field($params['board'] ?? '');
    $sfen_captured = sanitize_text_field($params['captured'] ?? '');
    $move_usi = sanitize_text_field($params['move_usi'] ?? '');
    $player = 'b'; // プレイヤーは常に先手 'b'

    if (empty($sfen_board) || empty($sfen_captured) || empty($move_usi)) {
        return new WP_Error('bad_request', 'Missing parameters.', ['status' => 400]);
    }

    // サーバー側で合法手リストを生成
    $valid_moves = gemini_shogi_get_valid_moves($sfen_board, $sfen_captured, $player);

    // プレイヤーの指し手が合法手リストに含まれているかチェック
    if (in_array($move_usi, $valid_moves)) {
        // 合法手なので、盤面を更新
        $board_array = gemini_shogi_parse_sfen_board($sfen_board);
        $captured_array = gemini_shogi_parse_sfen_captured($sfen_captured);
        
        $new_state_array = gemini_shogi_apply_move($board_array, $captured_array, $move_usi, $player);

        return new WP_REST_Response([
            'success' => true,
            'new_sfen_board' => gemini_shogi_board_to_sfen($new_state_array['board']),
            'new_sfen_captured' => gemini_shogi_captured_to_sfen($new_state_array['captured']),
        ], 200);

    } else {
        // 非合法手
        return new WP_REST_Response([
            'success' => false,
            'message' => 'その手はルール違反です。',
        ], 200);
    }
}
// =================================================================
// 特定の駒の有効な移動先を取得するAPIコールバック
// =================================================================
function gemini_shogi_handle_get_piece_moves($request) {
    $sfen_board = sanitize_text_field($request->get_param('board'));
    $sfen_captured = sanitize_text_field($request->get_param('captured'));
    $player = sanitize_text_field($request->get_param('turn'));
    $is_drop = $request->get_param('is_drop');

    // 全ての有効な手を取得
    $all_valid_moves = gemini_shogi_get_valid_moves($sfen_board, $sfen_captured, $player);
    $piece_specific_moves = [];

    if ($is_drop) {
        $piece_type = sanitize_text_field($request->get_param('piece_type'));
        $prefix = $piece_type . '*';
        foreach ($all_valid_moves as $move) {
            if (strpos($move, $prefix) === 0) {
                $piece_specific_moves[] = $move;
            }
        }
    } else {
        $row = intval($request->get_param('row'));
        $col = intval($request->get_param('col'));
        // 指定された駒からの手のみをフィルタリング
        $start_square_usi = gemini_shogi_coords_to_usi_square($row, $col);
        foreach ($all_valid_moves as $move) {
            if (strpos($move, $start_square_usi) === 0) {
                $piece_specific_moves[] = $move;
            }
        }
    }

    return new WP_REST_Response(['moves' => $piece_specific_moves], 200);
}


// =================================================================
// 将棋のゲームロジック (PHP側) - ここからが実装のメインパートです
// =================================================================

/**
 * SFEN形式の盤面文字列をPHPの多次元配列に変換する
 * @param string $sfen_board SFENの盤面部分 (例: 'lnsgkgsnl/1r5b1/ppppppppp/9/9/9/PPPPPPPPP/1B5R1/LNSGKGSNL')
 * @return array 9x9の盤面配列。駒がないマスはnull。駒があるマスは ['type' => 'K', 'player' => 'b'] のような連想配列。
 */
function gemini_shogi_parse_sfen_board($sfen_board) {
    $board = array_fill(0, 9, array_fill(0, 9, null));
    $rows = explode('/', $sfen_board);

    foreach ($rows as $row_index => $row_sfen) {
        $col_index = 0;
        $sfen_chars = str_split($row_sfen);
        $promoted = false;

        foreach ($sfen_chars as $char) {
            if ($char === '+') {
                $promoted = true;
                continue;
            }

            if (is_numeric($char)) {
                $col_index += intval($char);
            } else {
                $player = ctype_upper($char) ? 'b' : 'w'; // 大文字=先手(b), 小文字=後手(w)
                $type = strtoupper($char);
                
                $board[$row_index][$col_index] = [
                    'type' => ($promoted ? '+' : '') . $type,
                    'player' => $player
                ];
                
                $col_index++;
                $promoted = false;
            }
        }
    }
    return $board;
}

/**
 * (新規追加) PHPの盤面配列をSFEN形式の盤面文字列に変換する
 * @param array $board 9x9の盤面配列
 * @return string SFEN形式の盤面文字列
 */
function gemini_shogi_board_to_sfen($board) {
    $sfen_rows = [];
    foreach ($board as $row) {
        $sfen_row = '';
        $empty_count = 0;
        foreach ($row as $piece) {
            if ($piece === null) {
                $empty_count++;
            } else {
                if ($empty_count > 0) {
                    $sfen_row .= $empty_count;
                    $empty_count = 0;
                }
                $is_promoted = (strpos($piece['type'], '+') !== false);
                $type = str_replace('+', '', $piece['type']);
                // SFENでは先手の駒は大文字、後手の駒は小文字
                $sfen_char = ($piece['player'] === 'b') ? strtoupper($type) : strtolower($type);
                if ($is_promoted) {
                    $sfen_row .= '+' . $sfen_char;
                } else {
                    $sfen_row .= $sfen_char;
                }
            }
        }
        if ($empty_count > 0) {
            $sfen_row .= $empty_count;
        }
        $sfen_rows[] = $sfen_row;
    }
    return implode('/', $sfen_rows);
}

/**
 * (新規追加) PHPの持ち駒配列をSFEN形式の持ち駒文字列に変換する
 * @param array $captured_pieces 持ち駒配列
 * @return string SFEN形式の持ち駒文字列
 */
function gemini_shogi_captured_to_sfen($captured_pieces) {
    $sfen = '';
    // SFENの慣例的な駒の順序
    $piece_order = ['R', 'B', 'G', 'S', 'N', 'L', 'P'];

    // 先手の持ち駒 (大文字)
    $b_counts = isset($captured_pieces['b']) ? array_count_values($captured_pieces['b']) : [];
    foreach ($piece_order as $p) {
        if (isset($b_counts[$p])) {
            if ($b_counts[$p] > 1) {
                $sfen .= $b_counts[$p];
            }
            $sfen .= strtoupper($p);
        }
    }

    // 後手の持ち駒 (小文字)
    $w_counts = isset($captured_pieces['w']) ? array_count_values($captured_pieces['w']) : [];
    foreach ($piece_order as $p) {
        if (isset($w_counts[$p])) {
            if ($w_counts[$p] > 1) {
                $sfen .= $w_counts[$p];
            }
            $sfen .= strtolower($p);
        }
    }

    return empty($sfen) ? '-' : $sfen;
}


/**
 * 駒の動きを定義する
 * @param string $piece_type 駒の種類 (例: 'P', '+R')
 * @return array ['short' => [[-1, 0]], 'long' => []] のような形式で返す
 */
function gemini_shogi_get_piece_moves($piece_type) {
    $moves = [
        // --- 1マスずつ進む駒 ---
        'P'  => ['short' => [[-1, 0]], 'long' => []], // 歩
        'K'  => ['short' => [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]], 'long' => []], // 玉
        'G'  => ['short' => [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, 0]], 'long' => []], // 金
        'S'  => ['short' => [[-1, -1], [-1, 0], [-1, 1], [1, -1], [1, 1]], 'long' => []], // 銀
        'N'  => ['short' => [[-2, -1], [-2, 1]], 'long' => []], // 桂
        // --- 成り駒 (金と同じ動き) ---
        '+P' => ['short' => [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, 0]], 'long' => []], // と金
        '+S' => ['short' => [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, 0]], 'long' => []], // 成銀
        '+N' => ['short' => [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, 0]], 'long' => []], // 成桂
        '+L' => ['short' => [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, 0]], 'long' => []], // 成香
        // --- 遠くまで進める駒 ---
        'L'  => ['short' => [], 'long' => [[-1, 0]]], // 香車
        'B'  => ['short' => [], 'long' => [[-1, -1], [-1, 1], [1, -1], [1, 1]]], // 角
        'R'  => ['short' => [], 'long' => [[-1, 0], [1, 0], [0, -1], [0, 1]]], // 飛車
        // --- 遠くまで進める駒 (成り) ---
        '+B' => ['short' => [[-1, 0], [1, 0], [0, -1], [0, 1]], 'long' => [[-1, -1], [-1, 1], [1, -1], [1, 1]]], // 馬
        '+R' => ['short' => [[-1, -1], [-1, 1], [1, -1], [1, 1]], 'long' => [[-1, 0], [1, 0], [0, -1], [0, 1]]], // 竜
    ];
    return $moves[$piece_type] ?? ['short' => [], 'long' => []];
}

/**
 * 盤上の座標をUSI形式のマス文字列に変換する
 * @param int $row 行 (0-8)
 * @param int $col 列 (0-8)
 * @return string USI形式のマス (例: '7f', '1a')
 */
function gemini_shogi_coords_to_usi_square($row, $col) {
    $files = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'];
    // USIの列は右から1..9, 配列のcolは左から0..8 => USI file = 9 - col
    // USIの段は上からa..i, 配列のrowは上から0..8 => USI rank = a,b,c..
    return (string)(9 - $col) . $files[$row];
}

/**
 * USI形式のマス文字列を盤上の座標に変換する
 * @param string $usi_square USI形式のマス (例: '7f', '1a')
 * @return array ['row' => int, 'col' => int]
 */
function gemini_shogi_usi_square_to_coords($usi_square) {
    $col = 9 - intval($usi_square[0]);
    $row = strpos('abcdefghi', $usi_square[1]);
    return ['row' => $row, 'col' => $col];
}

/**
 * 指し手を適用し、新しいゲーム状態（盤面と持ち駒）を返す
 * @param array $board 現在の盤面
 * @param array $captured_pieces 現在の持ち駒
 * @param string $move_usi USI形式の指し手
 * @param string $player 手番のプレイヤー
 * @return array|null 新しい状態 ['board' => ..., 'captured' => ...] or null (if move is invalid)
 */
function gemini_shogi_apply_move($board, $captured_pieces, $move_usi, $player) {
    // 盤面をディープコピーして、元の盤面を変更しないようにする
    $new_board = array_map(function($row) {
        return array_map(function($piece) {
            return is_array($piece) ? $piece : $piece; // 駒の連想配列をコピー
        }, $row);
    }, $board);
    $new_captured = $captured_pieces;

    if (strpos($move_usi, '*') !== false) { // 持ち駒を打つ手
        list($piece_type, $to_usi) = explode('*', $move_usi);
        $to_coords = gemini_shogi_usi_square_to_coords($to_usi);

        // 持ち駒から駒を削除
        $piece_index = array_search($piece_type, $new_captured[$player]);
        if ($piece_index === false) {
            return null; // 持っていない駒は打てない
        }
        unset($new_captured[$player][$piece_index]);
        $new_captured[$player] = array_values($new_captured[$player]);

        // 打つ場所に駒がある場合は打てない
        if ($new_board[$to_coords['row']][$to_coords['col']] !== null) {
            return null;
        }

        $new_board[$to_coords['row']][$to_coords['col']] = ['type' => $piece_type, 'player' => $player];

    } else { // 盤上の駒を動かす手
        $from_usi = substr($move_usi, 0, 2);
        $to_usi = substr($move_usi, 2, 2);
        $promote = (substr($move_usi, 4, 1) === '+');

        $from_coords = gemini_shogi_usi_square_to_coords($from_usi);
        $to_coords = gemini_shogi_usi_square_to_coords($to_usi);

        // 移動元に駒がない、または手番の駒ではない場合は不正な手
        if (!isset($new_board[$from_coords['row']][$from_coords['col']]) || $new_board[$from_coords['row']][$from_coords['col']] === null || $new_board[$from_coords['row']][$from_coords['col']]['player'] !== $player) {
            return null;
        }
        $piece_to_move = $new_board[$from_coords['row']][$from_coords['col']];

        // 移動先に自分の駒がある場合は不正な手
        if ($new_board[$to_coords['row']][$to_coords['col']] !== null && $new_board[$to_coords['row']][$to_coords['col']]['player'] === $player) {
            return null;
        }

        // 相手の駒を取る処理
        $captured_piece_on_dest = $new_board[$to_coords['row']][$to_coords['col']];
        if ($captured_piece_on_dest !== null) {
            $captured_type = str_replace('+', '', $captured_piece_on_dest['type']);
            $new_captured[$player][] = $captured_type;
            sort($new_captured[$player]);
        }

        // 駒を移動
        if ($promote) {
            $piece_to_move['type'] = '+' . $piece_to_move['type'];
        }
        $new_board[$to_coords['row']][$to_coords['col']] = $piece_to_move;
        $new_board[$from_coords['row']][$from_coords['col']] = null;
    }

    return ['board' => $new_board, 'captured' => $new_captured];
}


/**
 * 指定されたプレイヤーの王がチェックされているか判定する
 * @param array $board 現在の盤面
 * @param string $player チェックされる側のプレイヤー ('b' or 'w')
 * @return bool チェックされている場合はtrue
 */
function gemini_shogi_is_king_in_check($board, $player) {
    $king_pos = null;
    // 王の位置を探す
    for ($r = 0; $r < 9; $r++) {
        for ($c = 0; $c < 9; $c++) {
            if ($board[$r][$c] !== null && $board[$r][$c]['type'] === 'K' && $board[$r][$c]['player'] === $player) {
                $king_pos = ['row' => $r, 'col' => $c];
                break 2;
            }
        }
    }

    if ($king_pos === null) {
        return true; // 王が盤面から消えたら負け（チェックされている扱い）
    }

    $opponent = ($player === 'b') ? 'w' : 'b';

    // 相手の駒が王を攻撃しているかチェック
    for ($r = 0; $r < 9; $r++) {
        for ($c = 0; $c < 9; $c++) {
            $piece = $board[$r][$c];
            if ($piece === null || $piece['player'] !== $opponent) {
                continue;
            }

            $piece_moves = gemini_shogi_get_piece_moves($piece['type']);
            $move_directions = array_merge($piece_moves['short'], $piece_moves['long']);
            $is_long_range = !empty($piece_moves['long']);

            // 1マスずつ進む動き
            foreach ($piece_moves['short'] as $move) {
                $dr = ($opponent === 'b') ? $move[0] : -$move[0];
                $dc = $move[1];
                $nr = $r + $dr;
                $nc = $c + $dc;

                if ($nr === $king_pos['row'] && $nc === $king_pos['col']) {
                    return true;
                }
            }

            // 遠くまで進む動き
            foreach ($piece_moves['long'] as $move) {
                $dr_base = ($opponent === 'b') ? $move[0] : -$move[0];
                $dc_base = $move[1];
                
                for ($i = 1; $i < 9; $i++) {
                    $nr = $r + ($dr_base * $i);
                    $nc = $c + ($dc_base * $i);

                    if ($nr < 0 || $nr >= 9 || $nc < 0 || $nc >= 9) {
                        break; // 盤外
                    }
                    
                    $dest_piece = $board[$nr][$nc];
                    if ($nr === $king_pos['row'] && $nc === $king_pos['col']) {
                        return true;
                    }
                    if ($dest_piece !== null) {
                        break; // 自分の駒か相手の駒（王以外）に当たった
                    }
                }
            }
        }
    }
    return false;
}

/**
 * SFEN形式の持ち駒文字列をPHPの連想配列に変換する
 * @param string $sfen_captured SFENの持ち駒部分 (例: 'P2p')
 * @return array 持ち駒の連想配列 (例: ['b' => ['P'], 'w' => ['P', 'P']])
 */
function gemini_shogi_parse_sfen_captured($sfen_captured) {
    $captured_pieces = ['b' => [], 'w' => []];
    if ($sfen_captured === '-') {
        return $captured_pieces;
    }

    $sfen_chars = str_split($sfen_captured);
    $count = 1;
    foreach ($sfen_chars as $char) {
        if (is_numeric($char)) {
            $count = intval($char);
        } else {
            $player = ctype_upper($char) ? 'b' : 'w';
            $piece_type = strtoupper($char);
            for ($i = 0; $i < $count; $i++) {
                $captured_pieces[$player][] = $piece_type;
            }
            $count = 1;
        }
    }
    // 駒をソートしておくと後々便利
    sort($captured_pieces['b']);
    sort($captured_pieces['w']);
    return $captured_pieces;
}

/**
 * 駒が成れるかどうかを判定する
 * @param string $piece_type 駒の種類 (例: 'P', 'S')
 * @param string $player プレイヤー ('b' or 'w')
 * @param int $from_row 移動元の行
 * @param int $to_row 移動先の行
 * @return bool 成れる場合はtrue
 */
function gemini_shogi_can_promote($piece_type, $player, $from_row, $to_row) {
    // 成れない駒
    if (in_array($piece_type, ['K', 'G', '+P', '+L', '+N', '+S', '+B', '+R'])) {
        return false;
    }

    // 成れる範囲 (先手は0-2段目、後手は6-8段目)
    $promotion_zone_start = ($player === 'b') ? 0 : 6;
    $promotion_zone_end = ($player === 'b') ? 2 : 8;

    // 移動元または移動先が成れる範囲内であれば成れる
    return (($from_row >= $promotion_zone_start && $from_row <= $promotion_zone_end) ||
            ($to_row >= $promotion_zone_start && $to_row <= $promotion_zone_end));
}

/**
 * SFEN形式の盤面と持ち駒から、指定されたプレイヤーの有効な手をすべて見つける
 * @param string $sfen_board 盤面
 * @param string $sfen_captured 持ち駒
 * @param string $player 手番 ('b' or 'w')
 * @return array 有効な手の配列 (USI形式)
 */
function gemini_shogi_get_valid_moves($sfen_board, $sfen_captured, $player) {
    $board = gemini_shogi_parse_sfen_board($sfen_board);
    $captured_pieces = gemini_shogi_parse_sfen_captured($sfen_captured);
    $valid_moves = [];

    // --- 盤上の駒の動き ---
    for ($r = 0; $r < 9; $r++) {
        for ($c = 0; $c < 9; $c++) {
            $piece = $board[$r][$c];
            if ($piece === null || $piece['player'] !== $player) continue;

            $piece_moves = gemini_shogi_get_piece_moves($piece['type']);
            
            // 短距離の動き
            foreach ($piece_moves['short'] as $move) {
                $dr = ($player === 'b') ? $move[0] : -$move[0];
                $dc = $move[1];
                $nr = $r + $dr;
                $nc = $c + $dc;

                if ($nr >= 0 && $nr < 9 && $nc >= 0 && $nc < 9) {
                    $dest_piece = $board[$nr][$nc];
                    if ($dest_piece === null || $dest_piece['player'] !== $player) {
                        $from_usi = gemini_shogi_coords_to_usi_square($r, $c);
                        $to_usi = gemini_shogi_coords_to_usi_square($nr, $nc);
                        
                        // 成りを含めた手を検証
                        $moves_to_check = [];
                        $can_promote = gemini_shogi_can_promote($piece['type'], $player, $r, $nr);
                        $is_forced_promotion = $can_promote && (
                            (($piece['type'] === 'P' || $piece['type'] === 'L') && (($player === 'b' && $nr === 0) || ($player === 'w' && $nr === 8))) ||
                            ($piece['type'] === 'N' && (($player === 'b' && $nr <= 1) || ($player === 'w' && $nr >= 7)))
                        );

                        if ($is_forced_promotion) {
                            $moves_to_check[] = $from_usi . $to_usi . '+';
                        } else {
                            $moves_to_check[] = $from_usi . $to_usi;
                            if ($can_promote) {
                                $moves_to_check[] = $from_usi . $to_usi . '+';
                            }
                        }

                        foreach($moves_to_check as $move_usi) {
                            $new_state = gemini_shogi_apply_move($board, $captured_pieces, $move_usi, $player);
                            if ($new_state && !gemini_shogi_is_king_in_check($new_state['board'], $player)) {
                                $valid_moves[] = $move_usi;
                            }
                        }
                    }
                }
            }

            // 長距離の動き
            foreach ($piece_moves['long'] as $move) {
                $dr_base = ($player === 'b') ? $move[0] : -$move[0];
                $dc_base = $move[1];
                
                for ($i = 1; $i < 9; $i++) {
                    $nr = $r + ($dr_base * $i);
                    $nc = $c + ($dc_base * $i);

                    if ($nr < 0 || $nr >= 9 || $nc < 0 || $nc >= 9) break;

                    $dest_piece = $board[$nr][$nc];
                    if ($dest_piece === null || $dest_piece['player'] !== $player) {
                        $from_usi = gemini_shogi_coords_to_usi_square($r, $c);
                        $to_usi = gemini_shogi_coords_to_usi_square($nr, $nc);

                        $moves_to_check = [];
                        $can_promote = gemini_shogi_can_promote($piece['type'], $player, $r, $nr);
                        
                        // 強制成りはない（香車が最奥段に到達するのは短距離の動きで処理される）
                        $moves_to_check[] = $from_usi . $to_usi;
                        if ($can_promote) {
                            $moves_to_check[] = $from_usi . $to_usi . '+';
                        }

                        foreach($moves_to_check as $move_usi) {
                            $new_state = gemini_shogi_apply_move($board, $captured_pieces, $move_usi, $player);
                            if ($new_state && !gemini_shogi_is_king_in_check($new_state['board'], $player)) {
                                $valid_moves[] = $move_usi;
                            }
                        }
                    }
                    if ($dest_piece !== null) break;
                }
            }
        }
    }

    // --- 持ち駒を打つロジック ---
    $unique_captured_pieces = array_unique($captured_pieces[$player] ?? []);
    foreach ($unique_captured_pieces as $piece_type) {
        for ($r = 0; $r < 9; $r++) {
            for ($c = 0; $c < 9; $c++) {
                if ($board[$r][$c] === null) {
                    // --- 禁じ手チェック ---
                    // 二歩
                    if ($piece_type === 'P') {
                        $pawn_on_file = false;
                        for ($row_idx = 0; $row_idx < 9; $row_idx++) {
                            $p = $board[$row_idx][$c];
                            if ($p !== null && $p['type'] === 'P' && $p['player'] === $player) {
                                $pawn_on_file = true;
                                break;
                            }
                        }
                        if ($pawn_on_file) continue;
                    }
                    // 行き所のない駒
                    if (($piece_type === 'P' || $piece_type === 'L') && (($player === 'b' && $r === 0) || ($player === 'w' && $r === 8))) continue;
                    if ($piece_type === 'N' && (($player === 'b' && $r <= 1) || ($player === 'w' && $r >= 7))) continue;

                    $move_usi = $piece_type . '*' . gemini_shogi_coords_to_usi_square($r, $c);
                    $new_state = gemini_shogi_apply_move($board, $captured_pieces, $move_usi, $player);

                    if ($new_state && !gemini_shogi_is_king_in_check($new_state['board'], $player)) {
                        // 打ち歩詰めチェック
                        if ($piece_type === 'P') {
                            $opponent = ($player === 'b' ? 'w' : 'b');
                            if (gemini_shogi_is_checkmate($new_state['board'], $opponent, $new_state['captured'])) {
                                continue; // 打ち歩詰めは禁じ手
                            }
                        }
                        $valid_moves[] = $move_usi;
                    }
                }
            }
        }
    }

    if (empty($valid_moves)) {
        return ['resign'];
    }

    return array_unique($valid_moves);
}

/**
 * 指定されたプレイヤーが詰んでいるか判定する
 * @param array $board 現在の盤面
 * @param string $player 詰んでいるかチェックする側のプレイヤー ('b' or 'w')
 * @param array $captured_pieces 持ち駒
 * @return bool 詰んでいる場合はtrue
 */
function gemini_shogi_is_checkmate($board, $player, $captured_pieces) {
    // 1. 王が現在チェックされているか？
    if (!gemini_shogi_is_king_in_check($board, $player)) {
        return false;
    }

    // 2. 王を動かして逃げられる合法手は存在するか？
    // この関数の内部で get_valid_moves を呼ぶと無限再帰に陥るため、
    // ここでは「王が動くことでチェックが外れるか」という観点でのみ合法手をチェックする
    
    // 盤上の駒を動かす
    for ($r = 0; $r < 9; $r++) {
        for ($c = 0; $c < 9; $c++) {
            $piece = $board[$r][$c];
            if ($piece === null || $piece['player'] !== $player) continue;

            $piece_moves = gemini_shogi_get_piece_moves($piece['type']);
            
            // 短距離
            foreach ($piece_moves['short'] as $move) {
                $dr = ($player === 'b') ? $move[0] : -$move[0];
                $dc = $move[1];
                $nr = $r + $dr;
                $nc = $c + $dc;
                if ($nr >= 0 && $nr < 9 && $nc >= 0 && $nc < 9) {
                    $dest_piece = $board[$nr][$nc];
                    if ($dest_piece === null || $dest_piece['player'] !== $player) {
                        $move_usi = gemini_shogi_coords_to_usi_square($r, $c) . gemini_shogi_coords_to_usi_square($nr, $nc);
                        $new_state = gemini_shogi_apply_move($board, $captured_pieces, $move_usi, $player);
                        if ($new_state && !gemini_shogi_is_king_in_check($new_state['board'], $player)) return false; // 逃げ道発見

                        if (gemini_shogi_can_promote($piece['type'], $player, $r, $nr)) {
                            $new_state_promo = gemini_shogi_apply_move($board, $captured_pieces, $move_usi . '+', $player);
                            if ($new_state_promo && !gemini_shogi_is_king_in_check($new_state_promo['board'], $player)) return false; // 逃げ道発見
                        }
                    }
                }
            }
            // 長距離
            foreach ($piece_moves['long'] as $move) {
                $dr_base = ($player === 'b') ? $move[0] : -$move[0];
                $dc_base = $move[1];
                for ($i = 1; $i < 9; $i++) {
                    $nr = $r + ($dr_base * $i);
                    $nc = $c + ($dc_base * $i);
                    if ($nr < 0 || $nr >= 9 || $nc < 0 || $nc >= 9) break;
                    $dest_piece = $board[$nr][$nc];
                    if ($dest_piece === null || $dest_piece['player'] !== $player) {
                        $move_usi = gemini_shogi_coords_to_usi_square($r, $c) . gemini_shogi_coords_to_usi_square($nr, $nc);
                        $new_state = gemini_shogi_apply_move($board, $captured_pieces, $move_usi, $player);
                        if ($new_state && !gemini_shogi_is_king_in_check($new_state['board'], $player)) return false; // 逃げ道発見

                        if (gemini_shogi_can_promote($piece['type'], $player, $r, $nr)) {
                             $new_state_promo = gemini_shogi_apply_move($board, $captured_pieces, $move_usi . '+', $player);
                            if ($new_state_promo && !gemini_shogi_is_king_in_check($new_state_promo['board'], $player)) return false; // 逃げ道発見
                        }
                    }
                    if ($dest_piece !== null) break;
                }
            }
        }
    }

    // 持ち駒を打つ
    $unique_captured_pieces = array_unique($captured_pieces[$player] ?? []);
    foreach ($unique_captured_pieces as $piece_type) {
        for ($r = 0; $r < 9; $r++) {
            for ($c = 0; $c < 9; $c++) {
                if ($board[$r][$c] === null) {
                    // 禁じ手チェックは get_valid_moves に任せ、ここでは単純なチェックのみ
                    if (($piece_type === 'P' || $piece_type === 'L') && (($player === 'b' && $r === 0) || ($player === 'w' && $r === 8))) continue;
                    if ($piece_type === 'N' && (($player === 'b' && $r <= 1) || ($player === 'w' && $r >= 7))) continue;
                    
                    $move_usi = $piece_type . '*' . gemini_shogi_coords_to_usi_square($r, $c);
                    $new_state = gemini_shogi_apply_move($board, $captured_pieces, $move_usi, $player);
                    if ($new_state && !gemini_shogi_is_king_in_check($new_state['board'], $player)) return false; // 逃げ道発見
                }
            }
        }
    }

    // 合法な逃げ道が一つも見つからなかった
    return true;
}


// =================================================================
// AIの応答を処理するメイン関数
// =================================================================

/**
 * プレイヤー vs AI モードのハンドラ
 */
function gemini_shogi_handle_player_vs_ai_move($request) {
    $params = $request->get_json_params();
    if (empty($params)) {
        return new WP_Error('bad_json', 'Invalid JSON format.', ['status' => 400]);
    }

    $sfen_board = sanitize_text_field($params['board'] ?? '');
    $sfen_captured = sanitize_text_field($params['captured'] ?? '');
    $player_to_move = sanitize_text_field($params['turn'] ?? '');
    $difficulty = sanitize_text_field($params['difficulty'] ?? 'normal');
    
$ai_player = 'w';
    $difficulty = sanitize_text_field($params['difficulty'] ?? 'normal');
    // プレイヤー対戦時のAIはOpenRouterから取得する（管理画面でモデルを設定可能）
    $api_provider = 'openrouter'; 
    $model_name = get_option('gemini_shogi_openrouter_model_name', 'mistralai/mistral-7b-instruct');

    return gemini_shogi_get_ai_move_from_api($sfen_board, $sfen_captured, $ai_player, $difficulty, $api_provider, $model_name);
}

/**
 * ★★★ (修正) AI vs AI モードのハンドラ ★★★
 */
function gemini_shogi_handle_ai_vs_ai_move($request) {
    $params = $request->get_json_params();
    if (empty($params)) {
        return new WP_Error('bad_json', 'Invalid JSON format.', ['status' => 400]);
    }

    $sfen_board = sanitize_text_field($params['board'] ?? '');
    $sfen_captured = sanitize_text_field($params['captured'] ?? '');
    $player_to_move = sanitize_text_field($params['turn'] ?? 'b');
    $difficulty = 'hard';

    // 手番によってAPIプロバイダーとモデル名を正しく切り替える
    $api_provider = ($player_to_move === 'b') ? 'gemini' : 'openrouter';
    
    $model_name = '';
    if ($api_provider === 'gemini') {
        // 先手(Gemini)のモデル名はJSから受け取る
        $model_name = sanitize_text_field($params['gemini_model'] ?? 'gemini-2.5-flash');
    } else {
        // 後手(OpenRouter)のモデル名はWordPressのオプションから取得する
        $model_name = get_option('gemini_shogi_openrouter_model_name', 'openrouter/horizon-beta'); 
        if (empty($model_name)) {
            // 管理画面で設定されていない場合のデフォルト値
            $model_name = 'openrouter/horizon-beta';
        }
    }
    
    // 共通関数を呼び出す
    return gemini_shogi_get_ai_move_from_api($sfen_board, $sfen_captured, $player_to_move, $difficulty, $api_provider, $model_name);
}


/**
 * ★★★ (改善) APIに問い合わせてAIの指し手を取得する共通関数 ★★★
 * 引数 $gemini_model を $model_name に変更し、汎用化
 */
function gemini_shogi_get_ai_move_from_api($sfen_board, $sfen_captured, $ai_player, $difficulty, $api_provider, $model_name) {
    $debug_info = [
        'api_provider' => $api_provider,
        'model_used' => $model_name, // 汎用的な引数を使用
        'received_sfen' => "sfen {$sfen_board} {$ai_player} {$sfen_captured} 1",
        'difficulty' => $difficulty,
        'php_valid_moves' => [],
        'prompt_sent_to_api' => '',
        'final_move_source' => ''
    ];

    $valid_moves = gemini_shogi_get_valid_moves($sfen_board, $sfen_captured, $ai_player);
    $debug_info['php_valid_moves'] = $valid_moves;

    if (empty($valid_moves) || $valid_moves[0] === 'resign') {
        $debug_info['final_move_source'] = 'PHP_NO_VALID_MOVES_OR_MATE';
        return new WP_REST_Response([ 'move' => 'resign', 'new_sfen_board' => $sfen_board, 'new_sfen_captured' => $sfen_captured, 'debug' => $debug_info ], 200);
    }

    $valid_moves_string = implode(', ', $valid_moves);
    $sfen_string = "sfen {$sfen_board} {$ai_player} {$sfen_captured} 1";
    $player_name_jp = ($ai_player === 'b') ? '先手' : '後手';
    $player_name_en = ($ai_player === 'b') ? 'Black(先手)' : 'White(後手)';
    $opponent_name_en = ($ai_player === 'b') ? 'White(後手)' : 'Black(先手)';
    
    // --- ★★★ プロンプトの改善 ★★★ ---
    $base_prompt = <<<PROMPT
あなたは世界トップクラスの将棋AIです。
あなたの役割は **{$player_name_en}** です。

# ルールと制約
- **最重要**: あなたは、以下に示す「合法手のリスト」の中から、戦略的に最善と思われる手を **1つだけ** 選び、指定されたJSON形式で回答してください。
- **リストにない手は絶対に出力してはいけません。**
- あなたは **{$player_name_en}** です。{$opponent_name_en}の駒を動かすことはルール違反です。
- 思考プロセスや余計な説明は一切含めず、JSONオブジェクトのみを出力してください。

# 現在の状況
- **あなたの手番**: {$player_name_en}
- **盤面 (SFEN形式)**: `{$sfen_string}`
- **あなたが指すことのできる合法手のリスト (USI形式)**:
`{$valid_moves_string}`

# あなたのタスク
上記の状況と合法手のリストを分析し、{$player_name_jp}にとって戦略的に最も優れた手をリストから1つ選び、以下のJSON形式で出力してください。

# 出力形式 (JSON)
{
  "move": "ここに合法手のリストから選んだUSI形式の手を記述"
}
PROMPT;

    $difficulty_instruction = "";
    switch ($difficulty) {
        case 'easy':
            $difficulty_instruction = "\n# 追加指示: 思考レベル\nあなたは将棋の初心者です。戦略的なことは考えず、上記「合法手のリスト」の中から**ランダムに近い手**を1つ選んでください。";
            break;
        case 'hard':
            $difficulty_instruction = "\n# 追加指示: 戦略的思考（エキスパートレベル）\nあなたは世界将棋AI選手権の優勝候補です。「合法手のリスト」の中から、以下の高度な戦略的思考プロセスに従って、最善の手を1つだけ厳密に選んでください。\n\n"
            . "1. **詰みの確認と思考の深度**: \n"
            . "   - **必達**: 相手玉に3手以上の詰み筋があれば、それを必ず実行してください。\n"
            . "   - **必達**: 自分の玉に詰みがあれば、それを回避する手を最優先してください。\n\n"
            . "2. **形勢判断と戦略立案**: \n"
            . "   - **優勢時**: 無理な攻めは避け、駒損をせず、相手の反撃の芽を摘みながら、着実に勝ちに繋げる手（玉の包囲、駒の価値の最大化）を選んでください。\n"
            . "   - **劣勢時**: 局面を複雑化させ、逆転のチャンスを生むような勝負手（リスキーでも大きなリターンが期待できる手、例えば大駒を敵陣に打ち込むなど）を積極的に選んでください。\n"
    	    . "   - **互角時**: 駒の効率（働き）を高め、玉を安全にし、将来の攻めの拠点を作るような、局面の主導権を握る手を選んでください。\n\n"
            . "3. **手筋と価値評価**: \n"
            . "   - **王手**: 単なる王手ではなく、相手の守備を崩壊させるような厳しい王手（両取り、守りの金銀を剥がすなど）を優先します。\n"
            . "   - **駒の損得**: 単純な駒の価値だけでなく、その駒が盤上でどれだけ働いているか（位置エネルギー）を評価してください。価値の低い駒でも、重要な働きをしていれば温存します。\n"
            . "   - **守備**: 自玉の安全度が最も重要です。金銀3枚の堅い囲いを維持し、相手の攻め駒を近づけないようにしてください。";
            break;
        default: // normal
            $difficulty_instruction = "\n# 追加指示: 戦略\nあなたは有段者レベルの将棋プレイヤーです。「合法手のリスト」の中から、以下の戦略を考慮して良い手を選んでください。\n- 相手の価値の高い駒(飛車、角、金、銀)を取れる手があれば、それを優先的に検討してください。\n- 自分の駒が相手の駒に取られそうな場合は、それを守る手を検討してください。\n- 相手の玉に王手をかける手を検討してください。\n- 駒をより戦略的に有利な位置に動かすことを目指してください。";
            break;
    }

    $prompt = $base_prompt . $difficulty_instruction;
    $debug_info['prompt_sent_to_api'] = $prompt;

    $api_response = null;
    if ($api_provider === 'openrouter') {
        $api_key = get_option('gemini_shogi_openrouter_api_key');
        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'OpenRouter APIキーが設定されていません。', ['status' => 500]);
        }
        $api_url = 'https://openrouter.ai/api/v1/chat/completions';
        $api_response = wp_remote_post($api_url, [
            'method'    => 'POST',
            'headers'   => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key ],
            'body'      => json_encode([
                'model' => $model_name, // 引数 $model_name を使用
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_object']
            ]),
            'timeout'   => 45,
        ]);
    } else { // Gemini
        $api_key = get_option('gemini_shogi_api_key');
        if (empty($api_key)) {
             return new WP_Error('no_api_key', 'Gemini APIキーが設定されていません。', ['status' => 500]);
        }
        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model_name . ':generateContent?key=' . $api_key;
        $api_response = wp_remote_post($api_url, [
            'method'    => 'POST',
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['response_mime_type' => 'application/json'],
            ]),
            'timeout'   => 45,
        ]);
    }

    // ★★★ (修正) APIエラー時のフォールバック処理を改善 ★★★
    if (is_wp_error($api_response) || wp_remote_retrieve_response_code($api_response) !== 200) {
        $debug_info['final_move_source'] = 'API_ERROR_FALLBACK';
        $debug_info['api_error_details'] = is_wp_error($api_response) ? $api_response->get_error_message() : wp_remote_retrieve_body($api_response);
        
        // APIエラーでも新しい盤面情報を計算して返すことで、クライアントエラーを防ぐ
        $chosen_move = $valid_moves[array_rand($valid_moves)];

        $final_response_data = [ 'move' => $chosen_move, 'debug' => $debug_info ];
        $board_array = gemini_shogi_parse_sfen_board($sfen_board);
        $captured_array = gemini_shogi_parse_sfen_captured($sfen_captured);
        $new_state_array = gemini_shogi_apply_move($board_array, $captured_array, $chosen_move, $ai_player);

        if ($new_state_array) {
            $final_response_data['new_sfen_board'] = gemini_shogi_board_to_sfen($new_state_array['board']);
            $final_response_data['new_sfen_captured'] = gemini_shogi_captured_to_sfen($new_state_array['captured']);
        } else {
            $final_response_data['new_sfen_board'] = $sfen_board;
            $final_response_data['new_sfen_captured'] = $sfen_captured;
        }
        
        return new WP_REST_Response($final_response_data, 200);
    }

   $response_body = wp_remote_retrieve_body($api_response);
    $data = json_decode($response_body, true);
    $ai_move = null;
    $chosen_move = null;

    if ($api_provider === 'openrouter') {
        $ai_text_response = $data['choices'][0]['message']['content'] ?? '';
        $parsed_response = json_decode($ai_text_response, true);
        // ★改善: trim()で不要な空白を除去
        $ai_move = isset($parsed_response['move']) ? trim($parsed_response['move']) : null;
    } else { // Gemini
        $ai_text_response = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $parsed_response = json_decode($ai_text_response, true);
        // ★改善: trim()で不要な空白を除去
        $ai_move = isset($parsed_response['move']) ? trim($parsed_response['move']) : null;
    }
    
    $debug_info['ai_suggested_move'] = $ai_move;

    if ($ai_move && in_array($ai_move, $valid_moves)) {
        $debug_info['final_move_source'] = 'AI_SUGGESTED_VALID_MOVE';
        $chosen_move = $ai_move;
    } else {
        $debug_info['final_move_source'] = 'INVALID_MOVE_FALLBACK';
        $chosen_move = $valid_moves[array_rand($valid_moves)];
        error_log("Gemini Shogi Debug: Invalid Move Fallback. AI Suggested: '{$ai_move}', Fallback Chosen: '{$chosen_move}'");
    }

    // --- ★★★ ここからが新しいレスポンス構築部分 ★★★ ---
    $final_response_data = [
        'move' => $chosen_move,
        'debug' => $debug_info
    ];

    // 'resign'でなければ、新しい盤面状態を計算してレスポンスに含める
    if ($chosen_move !== 'resign') {
        $board_array = gemini_shogi_parse_sfen_board($sfen_board);
        $captured_array = gemini_shogi_parse_sfen_captured($sfen_captured);
        
        $new_state_array = gemini_shogi_apply_move($board_array, $captured_array, $chosen_move, $ai_player);
        
        // ★改善: 堅牢性の向上
        if ($new_state_array) {
            $final_response_data['new_sfen_board'] = gemini_shogi_board_to_sfen($new_state_array['board']);
            $final_response_data['new_sfen_captured'] = gemini_shogi_captured_to_sfen($new_state_array['captured']);
            $debug_info['generated_new_sfen'] = "sfen " . $final_response_data['new_sfen_board'] . " " . (($ai_player === 'b') ? 'w' : 'b') . " " . $final_response_data['new_sfen_captured'];
        } else {
            // apply_moveが何らかの理由で失敗した場合のフォールバック
            $debug_info['final_move_source'] = 'APPLY_MOVE_ERROR_FALLBACK';
            $final_response_data['new_sfen_board'] = $sfen_board; // 状態を更新せず、元の盤面を返す
            $final_response_data['new_sfen_captured'] = $sfen_captured;
            error_log("Gemini Shogi CRITICAL: Failed to apply a chosen valid move. Move: '{$chosen_move}', SFEN: '{$sfen_string}'");
        }
    } else {
        // 投了の場合も、盤面情報はそのまま返す
        $final_response_data['new_sfen_board'] = $sfen_board;
        $final_response_data['new_sfen_captured'] = $sfen_captured;
    }

    $final_response_data['debug'] = $debug_info;

    return new WP_REST_Response($final_response_data, 200);
}

