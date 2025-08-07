jQuery(document).ready(function ($) {
    const boardElement = $('#gemini-shogi-game');
    if (boardElement.length === 0) return;

    // --- グローバル変数 ---
    let board = [];
    let captured = { b: [], w: [] };
    let turn = 'b';
    let selectedPiece = null;
    // validMoves はサーバー側で管理するためクライアントでは不要に
    let gameMode = 'player-vs-ai';
    let aiVsAiTimeoutId = null; // setIntervalからsetTimeoutに変更するため改名
    let isAiThinking = false;   // AIの多重思考を防ぐためのロックフラグ
    const pluginUrl = gemini_shogi_data.plugin_url;

    // =================================================================
    // 初期化処理
    // =================================================================
    function initializeGame() {
        boardElement.html(
            `<div class="game-controls"></div>
             <div class="ai-vs-ai-controls" style="display: none;"></div>
             <div class="current-models-display"></div>
             <div class="board-container"></div>
             <div class="info-container"></div>`
        );

        setupControls();
        createBoard();
        resetGame();
    }

    function setupControls() {
        const controls = `
            <div class="control-section">
                <label>ゲームモード:</label>
                <input type="radio" name="gameMode" value="player-vs-ai" checked> Player vs AI
                <input type="radio" name="gameMode" value="ai-vs-ai"> AI vs AI
            </div>
            <div id="player-controls">
                 <button id="new-game-button">新しいゲーム</button>
                 <select id="difficulty-selector">
                    <option value="easy">やさしい</option>
                    <option value="normal" selected>ふつう</option>
                    <option value="hard">プロ棋士</option>
                </select>
            </div>`;
        boardElement.find('.game-controls').html(controls);

        const aiVsAiControls = `
            <div class="control-section">
                <label for="gemini-model-selector">先手 (Gemini):</label>
                <select id="gemini-model-selector">
                    <option value="gemini-2.5-pro">Gemini 2.5 Pro</option>
                    <option value="gemini-2.5-flash" selected>Gemini 2.5 Flash</option>
                    <option value="gemini-2.5-flash-lite">Gemini 2.5 Flash-Lite</option>
                </select>
                <button id="start-ai-vs-ai-button">対戦開始</button>
                <button id="stop-ai-vs-ai-button" disabled>停止</button>
            </div>`;
        boardElement.find('.ai-vs-ai-controls').html(aiVsAiControls);

        // Event Listeners
        $('input[name="gameMode"]').on('change', handleGameModeChange);
        $('#new-game-button').on('click', resetGame);
        $('#start-ai-vs-ai-button').on('click', startAiVsAiGame);
        $('#stop-ai-vs-ai-button').on('click', stopAiVsAiGame);
    }

    function handleGameModeChange() {
        gameMode = $('input[name="gameMode"]:checked').val();
        resetGame();
        if (gameMode === 'ai-vs-ai') {
            $('#player-controls').hide();
            $('.ai-vs-ai-controls').show();
        } else {
            $('#player-controls').show();
            $('.ai-vs-ai-controls').hide();
        }
        updateCurrentModelsDisplay();
    }

    function resetGame() {
        stopAiVsAiGame();
        board = parseSfenBoard('lnsgkgsnl/1r5b1/ppppppppp/9/9/9/PPPPPPPPP/1B5R1/LNSGKGSNL');
        captured = { b: [], w: [] };
        turn = 'b';
        clearHighlights();
        renderBoard();
        renderCaptured();
        updateCurrentModelsDisplay();
        boardElement.find('.info-container').html('<div class="status-message">新しいゲームを開始します。</div>');
    }

    function startAiVsAiGame() {
        resetGame();
        $('#start-ai-vs-ai-button').prop('disabled', true);
        $('#stop-ai-vs-ai-button').prop('disabled', false);
        updateStatus('AI対戦を開始します...');
        isAiThinking = false; // ループ開始前にリセット
        requestAiVsAiMove(); // ループをキックスタート
    }

    function stopAiVsAiGame() {
        clearTimeout(aiVsAiTimeoutId);
        isAiThinking = false; // ループを停止し、ロックを解除
        $('#start-ai-vs-ai-button').prop('disabled', false);
        $('#stop-ai-vs-ai-button').prop('disabled', true);
    }

    // =================================================================
    // 盤面の描画と更新
    // =================================================================
    function createBoard() {
        const boardContainer = boardElement.find('.board-container');
        for (let row = 0; row < 9; row++) {
            for (let col = 0; col < 9; col++) {
                boardContainer.append(`<div class="square" data-row="${row}" data-col="${col}"></div>`);
            }
        }
    }

    function renderBoard() {
        $('.square').empty().removeClass('black white selected valid-move last-move');
        for (let row = 0; row < 9; row++) {
            for (let col = 0; col < 9; col++) {
                const piece = board[row] ? board[row][col] : null;
                if (piece) {
                    const square = $(`.square[data-row='${row}'][data-col='${col}']`);
                    let pieceImageFile = piece.type.replace('+', '%2B');
                    if (piece.type === 'K') pieceImageFile = `K_${piece.player}`;
                    const pieceImage = `<img src="${pluginUrl}images/${pieceImageFile}.svg" class="piece ${piece.player}">`;
                    square.html(pieceImage).addClass(piece.player);
                }
            }
        }
    }
    
    function renderCaptured() {
        boardElement.find('.info-container').find('.captured-pieces').remove();
        const infoContainer = boardElement.find('.info-container');
        const players = ['b', 'w'];
        players.forEach(player => {
            const capturedBox = $(`<div class="captured-pieces" id="captured-${player}"></div>`);
            const title = (player === 'b') ? '先手の持ち駒' : '後手の持ち駒';
            capturedBox.append(`<h3>${title}</h3>`);
            const piecesDiv = $('<div class="pieces"></div>');
            captured[player].sort().forEach(pieceType => {
                const pieceImage = `<img src="${pluginUrl}images/${pieceType}.svg" class="piece-captured ${player}" data-type="${pieceType}">`;
                piecesDiv.append(pieceImage);
            });
            capturedBox.append(piecesDiv);
            infoContainer.append(capturedBox);
        });
    }

    function updateCurrentModelsDisplay() {
        let text = '';
        if (gameMode === 'ai-vs-ai') {
            const geminiModel = $('#gemini-model-selector option:selected').text();
            text = `先手: ${geminiModel} vs 後手: OpenRouter`;
        } else {
            text = `Player vs AI (後手)`;
        }
        boardElement.find('.current-models-display').text(text);
    }

    // =================================================================
    // プレイヤーの操作 (Player vs AIモード)
    // =================================================================
     boardElement.on('click', '.square', function () {
        if (gameMode !== 'player-vs-ai' || turn !== 'b') return;
        const row = $(this).data('row');
        const col = $(this).data('col');
        handleSquareClick(row, col);
    });

    boardElement.on('click', '.piece-captured', function () {
        if (gameMode !== 'player-vs-ai' || turn !== 'b' || $(this).hasClass('w')) return;
        const pieceType = $(this).data('type');
        handleCapturedPieceClick(pieceType);
    });

    // ★★★ (刷新) プレイヤーのクリック処理 ★★★
    function handleSquareClick(row, col) {
        const pieceOnSquare = board[row] ? board[row][col] : null;

        if (selectedPiece) { // 何か駒が選択されている状態
            const moveUsi = selectedPiece.fromCaptured 
                ? `${selectedPiece.piece.type}*${coordsToUsi(row, col)}`
                : `${coordsToUsi(selectedPiece.row, selectedPiece.col)}${coordsToUsi(row, col)}`;
            
            let finalMoveUsi = moveUsi;
            
            // 成りの確認ダイアログ（UIとして残す）
            if (!selectedPiece.fromCaptured && canPromoteJs(selectedPiece.piece.type, turn, selectedPiece.row, row)) {
                // バックエンドで合法性はチェックされるが、UI上は成り/不成りを選べるようにする
                // バックエンドで不可能な成りは弾かれるので安全
                if (confirm('駒を成りますか？')) {
                    finalMoveUsi = moveUsi + '+';
                }
            }
            
            // サーバーに指し手を送信して検証・適用を依頼
            sendPlayerMove(finalMoveUsi);

        } else if (pieceOnSquare && pieceOnSquare.player === turn) { // 新しい駒を選択
            selectedPiece = { row, col, piece: pieceOnSquare, fromCaptured: false };
            clearHighlights(true);
            $(this).addClass('selected');
            // 有効な移動先のハイライトもサーバーに問い合わせるのが理想だが、
            // UXのため既存のロジックを流用（ただしこれは表示のみで、実際の合法性チェックはサーバーが行う）
            highlightValidMoves(row, col, false); 
        }
    }

    function handleCapturedPieceClick(pieceType) {
        if(turn !== 'b') return;
        clearHighlights(true);
        selectedPiece = { piece: { type: pieceType, player: turn }, fromCaptured: true };
        $(`.piece-captured[data-type='${pieceType}']`).first().addClass('selected');
        highlightValidMoves(null, null, true, pieceType);
    }
    
    // ★★★ (新規追加) プレイヤーの指し手をサーバーに送信する関数 ★★★
    function sendPlayerMove(moveUsi) {
        updateStatus('指し手を送信中...');
        clearHighlights();

        $.ajax({
            url: gemini_shogi_data.player_move_url, // 新しいエンドポイント
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            beforeSend: xhr => xhr.setRequestHeader('X-WP-Nonce', gemini_shogi_data.nonce),
            data: JSON.stringify({ 
                board: boardToSfen(), 
                captured: capturedToSfen(),
                move_usi: moveUsi
            }),
            success: response => {
                if (response.success) {
                    // サーバーから返された新しい盤面でクライアントを更新
                    board = parseSfenBoard(response.new_sfen_board);
                    captured = parseSfenCaptured(response.new_sfen_captured);
                    renderBoard();
                    renderCaptured();
                    highlightLastMove(moveUsi);

                    // AIの手番に移る
                    turn = 'w';
                    setTimeout(getPlayerVsAiMove, 500);
                } else {
                    // サーバーから非合法手と判断された場合
                    updateStatus(response.message || 'その手は指せません。');
                }
            },
            error: (jqXHR, textStatus) => {
                updateStatus(`通信エラー: ${textStatus}`);
            }
        });
    }


    function clearHighlights(keepSelection = false) {
        $('.square.valid-move').removeClass('valid-move');
        if (!keepSelection) {
            $('.square.selected, .piece-captured.selected').removeClass('selected');
            selectedPiece = null;
        }
    }

    // =================================================================
    // AIとの通信
    // =================================================================
    function getPlayerVsAiMove() {
        updateStatus('AIが考慮中です...');
        $.ajax({
            url: gemini_shogi_data.api_url,
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            beforeSend: xhr => xhr.setRequestHeader('X-WP-Nonce', gemini_shogi_data.nonce),
            data: JSON.stringify({ board: boardToSfen(), captured: capturedToSfen(), turn: 'w', difficulty: $('#difficulty-selector').val() }),
            success: response => {
                handleAiResponse(response, 'b');
            },
            error: (jqXHR, textStatus) => updateStatus(`AI通信エラー: ${textStatus}`)
        });
    }

    function requestAiVsAiMove() {
        if (isAiThinking) return; // 既にAIが思考中なら多重実行を防ぐ

        const currentTurnPlayer = turn === 'b' ? '先手(Gemini)' : '後手(OpenRouter)';
        updateStatus(`${currentTurnPlayer}が考慮中です...`);
        isAiThinking = true; // AIの思考を開始し、ロックをかける

        $.ajax({
            url: gemini_shogi_data.ai_vs_ai_url,
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            beforeSend: xhr => xhr.setRequestHeader('X-WP-Nonce', gemini_shogi_data.nonce),
            data: JSON.stringify({ 
                board: boardToSfen(), 
                captured: capturedToSfen(), 
                turn: turn, 
                gemini_model: $('#gemini-model-selector').val() 
            }),
            success: response => {
                const nextTurn = turn === 'b' ? 'w' : 'b';
                handleAiResponse(response, nextTurn);
            },
            error: (jqXHR, textStatus) => {
                updateStatus(`AI通信エラー: ${textStatus}`);
                isAiThinking = false; // エラーが発生した場合もロックを解除
                stopAiVsAiGame();
            }
        });
    }

    /**
     * ★★★ (修正) AIからのレスポンスを処理する関数 ★★★
     * サーバーから送られてきた新しい盤面状態でJSのグローバル変数を上書きし、再描画する。
     * setTimeoutで次の手をスケジュールする機能を追加。
     */
    function handleAiResponse(response, nextTurn) {
        isAiThinking = false; // レスポンスを受け取ったので、思考ロックを解除

        if (response.move && response.move !== 'resign') {
            if (response.new_sfen_board && response.new_sfen_captured) {
                // サーバーの状態を正として、クライアントの状態を強制的に同期
                board = parseSfenBoard(response.new_sfen_board);
                captured = parseSfenCaptured(response.new_sfen_captured);
                turn = nextTurn;

                // 盤面を再描画
                renderBoard();
                renderCaptured();
                clearHighlights();

                // AIが指した手をハイライト表示
                highlightLastMove(response.move);

                if (gameMode === 'player-vs-ai') {
                    updateStatus('あなたの番です。');
                } else if (gameMode === 'ai-vs-ai') {
                    // AI対AIモードの場合、次の手を2秒後にスケジュール
                    aiVsAiTimeoutId = setTimeout(requestAiVsAiMove, 2000);
                }
            } else {
                updateStatus(`エラー：サーバーからの応答が不正です。盤面を同期できませんでした。`);
                console.error("Invalid response from server, missing new SFEN data.", response);
                stopAiVsAiGame();
            }
        } else {
            const winnerText = turn === 'b' ? '後手' : '先手';
            updateStatus(`投了！ ${winnerText}の勝ちです！`);
            stopAiVsAiGame();
        }
    }
    
    function updateStatus(message) {
        boardElement.find('.info-container').find('.status-message').remove();
        boardElement.find('.info-container').prepend(`<div class="status-message">${message}</div>`);
    }
    // =================================================================
    // ゲームロジック & ユーティリティ
    // =================================================================
    function applyMove(moveUsi) {
        const isDrop = moveUsi.includes('*');
        let pieceType, toCoords, fromCoords, promotion = false, movingPlayer;

        if (isDrop) {
            movingPlayer = turn;
            [pieceType, toCoords] = [moveUsi.split('*')[0], usiToCoords(moveUsi.split('*')[1])];
            if (!toCoords) {
                console.error("Invalid drop coordinates", moveUsi);
                return false;
            }

            const capturedIndex = captured[movingPlayer].indexOf(pieceType);
            if (capturedIndex > -1) {
                captured[movingPlayer].splice(capturedIndex, 1);
            } else {
                console.error(`CRITICAL: AI attempted to drop a piece it does not have. Player: '${movingPlayer}', Piece: '${pieceType}', Move: '${moveUsi}'`);
                updateStatus(`エラー：AIが持っていない駒を打とうとしました。(${moveUsi})`);
                return false;
            }
        } else {
            fromCoords = usiToCoords(moveUsi.substring(0, 2));
            toCoords = usiToCoords(moveUsi.substring(2, 4));
            if (!fromCoords || !toCoords) {
                console.error("Invalid move coordinates", moveUsi);
                return false;
            }

            const pieceToMove = board[fromCoords.row][fromCoords.col];

            // --- ここで厳格な所有者チェックを行う ---
            if (!pieceToMove || pieceToMove.player !== turn) {
                console.error(`CRITICAL: AI attempted to move an invalid piece. Turn: '${turn}', Piece Owner: '${pieceToMove ? pieceToMove.player : 'none'}', Move: '${moveUsi}'`);
                updateStatus(`エラー：AIが相手の駒(${pieceToMove ? pieceToMove.type : '空'})を動かしました。(${moveUsi})`);
                return false;
            }

            // 移動先に自分の駒がある場合は不正な手（バックエンドでフィルタリングされるはずだが、念のため）
            const destPiece = board[toCoords.row][toCoords.col];
            if (destPiece && destPiece.player === turn) {
                console.error(`CRITICAL: AI attempted to capture its own piece. Move: '${moveUsi}'`);
                updateStatus(`エラー：AIが自分の駒を取ろうとしました。(${moveUsi})`);
                return false;
            }

            movingPlayer = pieceToMove.player;
            promotion = moveUsi.length === 5 && moveUsi[4] === '+';
            pieceType = promotion ? '+' + pieceToMove.type : pieceToMove.type;

            const capturedPiece = board[toCoords.row][toCoords.col];
            if (capturedPiece) {
                captured[movingPlayer].push(capturedPiece.type.replace('+', ''));
            }
            board[fromCoords.row][fromCoords.col] = null;
        }

        board[toCoords.row][toCoords.col] = { type: pieceType, player: movingPlayer };

        clearHighlights();
        renderBoard();
        renderCaptured();
        return true; // 正常に適用された
    }

/**
     * ★★★ (新規追加) AIの最後の動きをハイライトする関数 ★★★
     */
    function highlightLastMove(moveUsi) {
        $('.square.last-move').removeClass('last-move'); // 前回のハイライトを消す
        const isDrop = moveUsi.includes('*');
        if (isDrop) {
            const toCoords = usiToCoords(moveUsi.split('*')[1]);
            if (toCoords) {
                $(`.square[data-row='${toCoords.row}'][data-col='${toCoords.col}']`).addClass('last-move');
            }
        } else {
            const fromCoords = usiToCoords(moveUsi.substring(0, 2));
            const toCoords = usiToCoords(moveUsi.substring(2, 4));
            if (fromCoords) {
                $(`.square[data-row='${fromCoords.row}'][data-col='${fromCoords.col}']`).addClass('last-move');
            }
            if (toCoords) {
                $(`.square[data-row='${toCoords.row}'][data-col='${toCoords.col}']`).addClass('last-move');
            }
        }
    }

    function parseSfenBoard(sfen) {
        let newBoard = Array(9).fill(null).map(() => Array(9).fill(null));
        const rows = sfen.split('/');
        for (let r = 0; r < 9; r++) {
            let c = 0;
            let promoted = false;
            for (const char of rows[r]) {
                if (char === '+') { promoted = true; continue; }
                if (isNaN(char)) {
                    const player = (char === char.toUpperCase()) ? 'b' : 'w';
                    newBoard[r][c] = { type: (promoted ? '+' : '') + char.toUpperCase(), player };
                    c++;
                    promoted = false;
                } else {
                    c += parseInt(char, 10);
                }
            }
        }
        return newBoard;
    }

/**
     * ★★★ (新規追加) SFEN形式の持ち駒を解析する関数 ★★★
     */
    function parseSfenCaptured(sfen) {
        const newCaptured = { b: [], w: [] };
        if (sfen === '-' || !sfen) return newCaptured;

        let count = 1;
        for (const char of sfen) {
            if (!isNaN(parseInt(char, 10))) {
                count = parseInt(char, 10);
            } else {
                const player = (char === char.toUpperCase()) ? 'b' : 'w';
                const pieceType = char.toUpperCase();
                for (let i = 0; i < count; i++) {
                    newCaptured[player].push(pieceType);
                }
                count = 1; // reset count
            }
        }
        return newCaptured;
    }

    function boardToSfen() {
        let sfen = '';
        for (let r = 0; r < 9; r++) {
            let emptyCount = 0;
            for (let c = 0; c < 9; c++) {
                const piece = board[r][c];
                if (piece === null) {
                    emptyCount++;
                } else {
                    if (emptyCount > 0) { sfen += emptyCount; emptyCount = 0; }
                    let sfenChar = piece.type.startsWith('+') ? `+${piece.type.substring(1)}` : piece.type;
                    sfen += (piece.player === 'b') ? sfenChar.toUpperCase() : sfenChar.toLowerCase();
                }
            }
            if (emptyCount > 0) sfen += emptyCount;
            if (r < 8) sfen += '/';
        }
        return sfen;
    }

    function capturedToSfen() {
        let sfen = '';
        const pieceOrder = ['R', 'B', 'G', 'S', 'N', 'L', 'P'];
        pieceOrder.forEach(type => {
            const countB = captured.b.filter(p => p === type).length;
            if (countB > 0) sfen += (countB > 1 ? countB : '') + type.toUpperCase();
        });
        pieceOrder.forEach(type => {
            const countW = captured.w.filter(p => p === type).length;
            if (countW > 0) sfen += (countW > 1 ? countW : '') + type.toLowerCase();
        });
        return sfen === '' ? '-' : sfen;
    }

    function usiToCoords(usiSquare) {
        if (!usiSquare || usiSquare.length < 2) return null;
        const col = 9 - parseInt(usiSquare[0], 10);
        const row = 'abcdefghi'.indexOf(usiSquare[1]);
        return (isNaN(col) || row === -1) ? null : { row, col };
    }
    
    function coordsToUsi(row, col) {
        return `${9 - col}${'abcdefghi'.charAt(row)}`;
    }

    function canPromoteJs(pieceType, player, fromRow, toRow) {
        if (['K', 'G', '+P', '+L', '+N', '+S', '+B', '+R'].includes(pieceType)) return false;
        const promoZoneStart = (player === 'b') ? 0 : 6;
        const promoZoneEnd = (player === 'b') ? 2 : 8;
        return (fromRow >= promoZoneStart && fromRow <= promoZoneEnd) || (toRow >= promoZoneStart && toRow <= promoZoneEnd);
    }

    // --- ゲーム開始 ---
    initializeGame();
});