"use strict";

jQuery(document).ready(function ($) {
  var boardElement = $('#gemini-shogi-game');
  if (boardElement.length === 0) return; // --- グローバル変数 ---

  var board = [];
  var captured = {
    b: [],
    w: []
  };
  var turn = 'b';
  var selectedPiece = null; // validMoves はサーバー側で管理するためクライアントでは不要に

  var gameMode = 'player-vs-ai';
  var aiVsAiInterval = null;
  var pluginUrl = gemini_shogi_data.plugin_url; // =================================================================
  // 初期化処理
  // =================================================================

  function initializeGame() {
    boardElement.html("<div class=\"game-controls\"></div>\n             <div class=\"ai-vs-ai-controls\" style=\"display: none;\"></div>\n             <div class=\"current-models-display\"></div>\n             <div class=\"board-container\"></div>\n             <div class=\"info-container\"></div>");
    setupControls();
    createBoard();
    resetGame();
  }

  function setupControls() {
    var controls = "\n            <div class=\"control-section\">\n                <label>\u30B2\u30FC\u30E0\u30E2\u30FC\u30C9:</label>\n                <input type=\"radio\" name=\"gameMode\" value=\"player-vs-ai\" checked> Player vs AI\n                <input type=\"radio\" name=\"gameMode\" value=\"ai-vs-ai\"> AI vs AI\n            </div>\n            <div id=\"player-controls\">\n                 <button id=\"new-game-button\">\u65B0\u3057\u3044\u30B2\u30FC\u30E0</button>\n                 <select id=\"difficulty-selector\">\n                    <option value=\"easy\">\u3084\u3055\u3057\u3044</option>\n                    <option value=\"normal\" selected>\u3075\u3064\u3046</option>\n                    <option value=\"hard\">\u30D7\u30ED\u68CB\u58EB</option>\n                </select>\n            </div>";
    boardElement.find('.game-controls').html(controls);
    var aiVsAiControls = "\n            <div class=\"control-section\">\n                <label for=\"gemini-model-selector\">\u5148\u624B (Gemini):</label>\n                <select id=\"gemini-model-selector\">\n                    <option value=\"gemini-2.5-pro\">Gemini 2.5 Pro</option>\n                    <option value=\"gemini-2.5-flash\" selected>Gemini 2.5 Flash</option>\n                    <option value=\"gemini-2.5-flash-lite\">Gemini 2.5 Flash-Lite</option>\n                </select>\n                <button id=\"start-ai-vs-ai-button\">\u5BFE\u6226\u958B\u59CB</button>\n                <button id=\"stop-ai-vs-ai-button\" disabled>\u505C\u6B62</button>\n            </div>";
    boardElement.find('.ai-vs-ai-controls').html(aiVsAiControls); // Event Listeners

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
    captured = {
      b: [],
      w: []
    };
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
    aiVsAiInterval = setInterval(requestAiVsAiMove, 2000); // 2秒ごとに手を進める
  }

  function stopAiVsAiGame() {
    if (aiVsAiInterval) {
      clearInterval(aiVsAiInterval);
      aiVsAiInterval = null;
    }

    $('#start-ai-vs-ai-button').prop('disabled', false);
    $('#stop-ai-vs-ai-button').prop('disabled', true);
  } // =================================================================
  // 盤面の描画と更新
  // =================================================================


  function createBoard() {
    var boardContainer = boardElement.find('.board-container');

    for (var row = 0; row < 9; row++) {
      for (var col = 0; col < 9; col++) {
        boardContainer.append("<div class=\"square\" data-row=\"".concat(row, "\" data-col=\"").concat(col, "\"></div>"));
      }
    }
  }

  function renderBoard() {
    $('.square').empty().removeClass('black white selected valid-move last-move');

    for (var row = 0; row < 9; row++) {
      for (var col = 0; col < 9; col++) {
        var piece = board[row] ? board[row][col] : null;

        if (piece) {
          var square = $(".square[data-row='".concat(row, "'][data-col='").concat(col, "']"));
          var pieceImageFile = piece.type.replace('+', '%2B');
          if (piece.type === 'K') pieceImageFile = "K_".concat(piece.player);
          var pieceImage = "<img src=\"".concat(pluginUrl, "images/").concat(pieceImageFile, ".svg\" class=\"piece ").concat(piece.player, "\">");
          square.html(pieceImage).addClass(piece.player);
        }
      }
    }
  }

  function renderCaptured() {
    boardElement.find('.info-container').find('.captured-pieces').remove();
    var infoContainer = boardElement.find('.info-container');
    var players = ['b', 'w'];
    players.forEach(function (player) {
      var capturedBox = $("<div class=\"captured-pieces\" id=\"captured-".concat(player, "\"></div>"));
      var title = player === 'b' ? '先手の持ち駒' : '後手の持ち駒';
      capturedBox.append("<h3>".concat(title, "</h3>"));
      var piecesDiv = $('<div class="pieces"></div>');
      captured[player].sort().forEach(function (pieceType) {
        var pieceImage = "<img src=\"".concat(pluginUrl, "images/").concat(pieceType, ".svg\" class=\"piece-captured ").concat(player, "\" data-type=\"").concat(pieceType, "\">");
        piecesDiv.append(pieceImage);
      });
      capturedBox.append(piecesDiv);
      infoContainer.append(capturedBox);
    });
  }

  function updateCurrentModelsDisplay() {
    var text = '';

    if (gameMode === 'ai-vs-ai') {
      var geminiModel = $('#gemini-model-selector option:selected').text();
      text = "\u5148\u624B: ".concat(geminiModel, " vs \u5F8C\u624B: OpenRouter");
    } else {
      text = "Player vs AI (\u5F8C\u624B)";
    }

    boardElement.find('.current-models-display').text(text);
  } // =================================================================
  // プレイヤーの操作 (Player vs AIモード)
  // =================================================================


  boardElement.on('click', '.square', function () {
    if (gameMode !== 'player-vs-ai' || turn !== 'b') return;
    var row = $(this).data('row');
    var col = $(this).data('col');
    handleSquareClick(row, col);
  });
  boardElement.on('click', '.piece-captured', function () {
    if (gameMode !== 'player-vs-ai' || turn !== 'b' || $(this).hasClass('w')) return;
    var pieceType = $(this).data('type');
    handleCapturedPieceClick(pieceType);
  }); // ★★★ (刷新) プレイヤーのクリック処理 ★★★

  function handleSquareClick(row, col) {
    var pieceOnSquare = board[row] ? board[row][col] : null;

    if (selectedPiece) {
      // 何か駒が選択されている状態
      var moveUsi = selectedPiece.fromCaptured ? "".concat(selectedPiece.piece.type, "*").concat(coordsToUsi(row, col)) : "".concat(coordsToUsi(selectedPiece.row, selectedPiece.col)).concat(coordsToUsi(row, col));
      var finalMoveUsi = moveUsi; // 成りの確認ダイアログ（UIとして残す）

      if (!selectedPiece.fromCaptured && canPromoteJs(selectedPiece.piece.type, turn, selectedPiece.row, row)) {
        // バックエンドで合法性はチェックされるが、UI上は成り/不成りを選べるようにする
        // バックエンドで不可能な成りは弾かれるので安全
        if (confirm('駒を成りますか？')) {
          finalMoveUsi = moveUsi + '+';
        }
      } // サーバーに指し手を送信して検証・適用を依頼


      sendPlayerMove(finalMoveUsi);
    } else if (pieceOnSquare && pieceOnSquare.player === turn) {
      // 新しい駒を選択
      selectedPiece = {
        row: row,
        col: col,
        piece: pieceOnSquare,
        fromCaptured: false
      };
      clearHighlights(true);
      $(this).addClass('selected'); // 有効な移動先のハイライトもサーバーに問い合わせるのが理想だが、
      // UXのため既存のロジックを流用（ただしこれは表示のみで、実際の合法性チェックはサーバーが行う）

      highlightValidMoves(row, col, false);
    }
  }

  function handleCapturedPieceClick(pieceType) {
    if (turn !== 'b') return;
    clearHighlights(true);
    selectedPiece = {
      piece: {
        type: pieceType,
        player: turn
      },
      fromCaptured: true
    };
    $(".piece-captured[data-type='".concat(pieceType, "']")).first().addClass('selected');
    highlightValidMoves(null, null, true, pieceType);
  } // ★★★ (新規追加) プレイヤーの指し手をサーバーに送信する関数 ★★★


  function sendPlayerMove(moveUsi) {
    updateStatus('指し手を送信中...');
    clearHighlights();
    $.ajax({
      url: gemini_shogi_data.player_move_url,
      // 新しいエンドポイント
      method: 'POST',
      contentType: 'application/json; charset=utf-8',
      beforeSend: function beforeSend(xhr) {
        return xhr.setRequestHeader('X-WP-Nonce', gemini_shogi_data.nonce);
      },
      data: JSON.stringify({
        board: boardToSfen(),
        captured: capturedToSfen(),
        move_usi: moveUsi
      }),
      success: function success(response) {
        if (response.success) {
          // サーバーから返された新しい盤面でクライアントを更新
          board = parseSfenBoard(response.new_sfen_board);
          captured = parseSfenCaptured(response.new_sfen_captured);
          renderBoard();
          renderCaptured();
          highlightLastMove(moveUsi); // AIの手番に移る

          turn = 'w';
          setTimeout(getPlayerVsAiMove, 500);
        } else {
          // サーバーから非合法手と判断された場合
          updateStatus(response.message || 'その手は指せません。');
        }
      },
      error: function error(jqXHR, textStatus) {
        updateStatus("\u901A\u4FE1\u30A8\u30E9\u30FC: ".concat(textStatus));
      }
    });
  }

  function clearHighlights() {
    var keepSelection = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : false;
    $('.square.valid-move').removeClass('valid-move');

    if (!keepSelection) {
      $('.square.selected, .piece-captured.selected').removeClass('selected');
      selectedPiece = null;
    }
  } // =================================================================
  // AIとの通信
  // =================================================================


  function getPlayerVsAiMove() {
    updateStatus('AIが考慮中です...');
    $.ajax({
      url: gemini_shogi_data.api_url,
      method: 'POST',
      contentType: 'application/json; charset=utf-8',
      beforeSend: function beforeSend(xhr) {
        return xhr.setRequestHeader('X-WP-Nonce', gemini_shogi_data.nonce);
      },
      data: JSON.stringify({
        board: boardToSfen(),
        captured: capturedToSfen(),
        turn: 'w',
        difficulty: $('#difficulty-selector').val()
      }),
      success: function success(response) {
        handleAiResponse(response, 'b');
      },
      error: function error(jqXHR, textStatus) {
        return updateStatus("AI\u901A\u4FE1\u30A8\u30E9\u30FC: ".concat(textStatus));
      }
    });
  }

  function requestAiVsAiMove() {
    var currentTurnPlayer = turn === 'b' ? '先手(Gemini)' : '後手(OpenRouter)';
    updateStatus("".concat(currentTurnPlayer, "\u304C\u8003\u616E\u4E2D\u3067\u3059..."));
    $.ajax({
      url: gemini_shogi_data.ai_vs_ai_url,
      method: 'POST',
      contentType: 'application/json; charset=utf-8',
      beforeSend: function beforeSend(xhr) {
        return xhr.setRequestHeader('X-WP-Nonce', gemini_shogi_data.nonce);
      },
      data: JSON.stringify({
        board: boardToSfen(),
        captured: capturedToSfen(),
        turn: turn,
        gemini_model: $('#gemini-model-selector').val()
      }),
      success: function success(response) {
        var nextTurn = turn === 'b' ? 'w' : 'b';
        handleAiResponse(response, nextTurn);
      },
      error: function error(jqXHR, textStatus) {
        updateStatus("AI\u901A\u4FE1\u30A8\u30E9\u30FC: ".concat(textStatus));
        stopAiVsAiGame();
      }
    });
  }
  /**
   * ★★★ (修正) AIからのレスポンスを処理する関数 ★★★
   * サーバーから送られてきた新しい盤面状態でJSのグローバル変数を上書きし、再描画する。
   */


  function handleAiResponse(response, nextTurn) {
    if (response.move && response.move !== 'resign') {
      if (response.new_sfen_board && response.new_sfen_captured) {
        // サーバーの状態を正として、クライアントの状態を強制的に同期
        board = parseSfenBoard(response.new_sfen_board);
        captured = parseSfenCaptured(response.new_sfen_captured);
        turn = nextTurn; // 盤面を再描画

        renderBoard();
        renderCaptured();
        clearHighlights(); // AIが指した手をハイライト表示

        highlightLastMove(response.move);

        if (gameMode === 'player-vs-ai') {
          updateStatus('あなたの番です。');
        }
      } else {
        stopAiVsAiGame();
        var errorMessage = "\u30A8\u30E9\u30FC\uFF1A\u30B5\u30FC\u30D0\u30FC\u304B\u3089\u306E\u5FDC\u7B54\u304C\u4E0D\u6B63\u3067\u3059\u3002\u76E4\u9762\u3092\u540C\u671F\u3067\u304D\u307E\u305B\u3093\u3067\u3057\u305F\u3002";
        updateStatus(errorMessage);
        console.error("Invalid response from server, missing new SFEN data.", response);
      }
    } else {
      var winnerText = turn === 'b' ? '後手' : '先手';
      updateStatus("\u6295\u4E86\uFF01 ".concat(winnerText, "\u306E\u52DD\u3061\u3067\u3059\uFF01"));
      stopAiVsAiGame();
    }
  }

  function updateStatus(message) {
    boardElement.find('.info-container').find('.status-message').remove();
    boardElement.find('.info-container').prepend("<div class=\"status-message\">".concat(message, "</div>"));
  } // =================================================================
  // ゲームロジック & ユーティリティ
  // =================================================================


  function applyMove(moveUsi) {
    var isDrop = moveUsi.includes('*');
    var pieceType,
        toCoords,
        fromCoords,
        promotion = false,
        movingPlayer;

    if (isDrop) {
      movingPlayer = turn;
      var _ref = [moveUsi.split('*')[0], usiToCoords(moveUsi.split('*')[1])];
      pieceType = _ref[0];
      toCoords = _ref[1];

      if (!toCoords) {
        console.error("Invalid drop coordinates", moveUsi);
        return false;
      }

      var capturedIndex = captured[movingPlayer].indexOf(pieceType);

      if (capturedIndex > -1) {
        captured[movingPlayer].splice(capturedIndex, 1);
      } else {
        console.error("CRITICAL: AI attempted to drop a piece it does not have. Player: '".concat(movingPlayer, "', Piece: '").concat(pieceType, "', Move: '").concat(moveUsi, "'"));
        updateStatus("\u30A8\u30E9\u30FC\uFF1AAI\u304C\u6301\u3063\u3066\u3044\u306A\u3044\u99D2\u3092\u6253\u3068\u3046\u3068\u3057\u307E\u3057\u305F\u3002(".concat(moveUsi, ")"));
        return false;
      }
    } else {
      fromCoords = usiToCoords(moveUsi.substring(0, 2));
      toCoords = usiToCoords(moveUsi.substring(2, 4));

      if (!fromCoords || !toCoords) {
        console.error("Invalid move coordinates", moveUsi);
        return false;
      }

      var pieceToMove = board[fromCoords.row][fromCoords.col]; // --- ここで厳格な所有者チェックを行う ---

      if (!pieceToMove || pieceToMove.player !== turn) {
        console.error("CRITICAL: AI attempted to move an invalid piece. Turn: '".concat(turn, "', Piece Owner: '").concat(pieceToMove ? pieceToMove.player : 'none', "', Move: '").concat(moveUsi, "'"));
        updateStatus("\u30A8\u30E9\u30FC\uFF1AAI\u304C\u76F8\u624B\u306E\u99D2(".concat(pieceToMove ? pieceToMove.type : '空', ")\u3092\u52D5\u304B\u3057\u307E\u3057\u305F\u3002(").concat(moveUsi, ")"));
        return false;
      } // 移動先に自分の駒がある場合は不正な手（バックエンドでフィルタリングされるはずだが、念のため）


      var destPiece = board[toCoords.row][toCoords.col];

      if (destPiece && destPiece.player === turn) {
        console.error("CRITICAL: AI attempted to capture its own piece. Move: '".concat(moveUsi, "'"));
        updateStatus("\u30A8\u30E9\u30FC\uFF1AAI\u304C\u81EA\u5206\u306E\u99D2\u3092\u53D6\u308D\u3046\u3068\u3057\u307E\u3057\u305F\u3002(".concat(moveUsi, ")"));
        return false;
      }

      movingPlayer = pieceToMove.player;
      promotion = moveUsi.length === 5 && moveUsi[4] === '+';
      pieceType = promotion ? '+' + pieceToMove.type : pieceToMove.type;
      var capturedPiece = board[toCoords.row][toCoords.col];

      if (capturedPiece) {
        captured[movingPlayer].push(capturedPiece.type.replace('+', ''));
      }

      board[fromCoords.row][fromCoords.col] = null;
    }

    board[toCoords.row][toCoords.col] = {
      type: pieceType,
      player: movingPlayer
    };
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

    var isDrop = moveUsi.includes('*');

    if (isDrop) {
      var toCoords = usiToCoords(moveUsi.split('*')[1]);

      if (toCoords) {
        $(".square[data-row='".concat(toCoords.row, "'][data-col='").concat(toCoords.col, "']")).addClass('last-move');
      }
    } else {
      var fromCoords = usiToCoords(moveUsi.substring(0, 2));

      var _toCoords = usiToCoords(moveUsi.substring(2, 4));

      if (fromCoords) {
        $(".square[data-row='".concat(fromCoords.row, "'][data-col='").concat(fromCoords.col, "']")).addClass('last-move');
      }

      if (_toCoords) {
        $(".square[data-row='".concat(_toCoords.row, "'][data-col='").concat(_toCoords.col, "']")).addClass('last-move');
      }
    }
  }

  function parseSfenBoard(sfen) {
    var newBoard = Array(9).fill(null).map(function () {
      return Array(9).fill(null);
    });
    var rows = sfen.split('/');

    for (var r = 0; r < 9; r++) {
      var c = 0;
      var promoted = false;
      var _iteratorNormalCompletion = true;
      var _didIteratorError = false;
      var _iteratorError = undefined;

      try {
        for (var _iterator = rows[r][Symbol.iterator](), _step; !(_iteratorNormalCompletion = (_step = _iterator.next()).done); _iteratorNormalCompletion = true) {
          var _char = _step.value;

          if (_char === '+') {
            promoted = true;
            continue;
          }

          if (isNaN(_char)) {
            var player = _char === _char.toUpperCase() ? 'b' : 'w';
            newBoard[r][c] = {
              type: (promoted ? '+' : '') + _char.toUpperCase(),
              player: player
            };
            c++;
            promoted = false;
          } else {
            c += parseInt(_char, 10);
          }
        }
      } catch (err) {
        _didIteratorError = true;
        _iteratorError = err;
      } finally {
        try {
          if (!_iteratorNormalCompletion && _iterator["return"] != null) {
            _iterator["return"]();
          }
        } finally {
          if (_didIteratorError) {
            throw _iteratorError;
          }
        }
      }
    }

    return newBoard;
  }
  /**
       * ★★★ (新規追加) SFEN形式の持ち駒を解析する関数 ★★★
       */


  function parseSfenCaptured(sfen) {
    var newCaptured = {
      b: [],
      w: []
    };
    if (sfen === '-' || !sfen) return newCaptured;
    var count = 1;
    var _iteratorNormalCompletion2 = true;
    var _didIteratorError2 = false;
    var _iteratorError2 = undefined;

    try {
      for (var _iterator2 = sfen[Symbol.iterator](), _step2; !(_iteratorNormalCompletion2 = (_step2 = _iterator2.next()).done); _iteratorNormalCompletion2 = true) {
        var _char2 = _step2.value;

        if (!isNaN(parseInt(_char2, 10))) {
          count = parseInt(_char2, 10);
        } else {
          var player = _char2 === _char2.toUpperCase() ? 'b' : 'w';

          var pieceType = _char2.toUpperCase();

          for (var i = 0; i < count; i++) {
            newCaptured[player].push(pieceType);
          }

          count = 1; // reset count
        }
      }
    } catch (err) {
      _didIteratorError2 = true;
      _iteratorError2 = err;
    } finally {
      try {
        if (!_iteratorNormalCompletion2 && _iterator2["return"] != null) {
          _iterator2["return"]();
        }
      } finally {
        if (_didIteratorError2) {
          throw _iteratorError2;
        }
      }
    }

    return newCaptured;
  }

  function boardToSfen() {
    var sfen = '';

    for (var r = 0; r < 9; r++) {
      var emptyCount = 0;

      for (var c = 0; c < 9; c++) {
        var piece = board[r][c];

        if (piece === null) {
          emptyCount++;
        } else {
          if (emptyCount > 0) {
            sfen += emptyCount;
            emptyCount = 0;
          }

          var sfenChar = piece.type.startsWith('+') ? "+".concat(piece.type.substring(1)) : piece.type;
          sfen += piece.player === 'b' ? sfenChar.toUpperCase() : sfenChar.toLowerCase();
        }
      }

      if (emptyCount > 0) sfen += emptyCount;
      if (r < 8) sfen += '/';
    }

    return sfen;
  }

  function capturedToSfen() {
    var sfen = '';
    var pieceOrder = ['R', 'B', 'G', 'S', 'N', 'L', 'P'];
    pieceOrder.forEach(function (type) {
      var countB = captured.b.filter(function (p) {
        return p === type;
      }).length;
      if (countB > 0) sfen += (countB > 1 ? countB : '') + type.toUpperCase();
    });
    pieceOrder.forEach(function (type) {
      var countW = captured.w.filter(function (p) {
        return p === type;
      }).length;
      if (countW > 0) sfen += (countW > 1 ? countW : '') + type.toLowerCase();
    });
    return sfen === '' ? '-' : sfen;
  }

  function usiToCoords(usiSquare) {
    if (!usiSquare || usiSquare.length < 2) return null;
    var col = 9 - parseInt(usiSquare[0], 10);
    var row = 'abcdefghi'.indexOf(usiSquare[1]);
    return isNaN(col) || row === -1 ? null : {
      row: row,
      col: col
    };
  }

  function coordsToUsi(row, col) {
    return "".concat(9 - col).concat('abcdefghi'.charAt(row));
  }

  function canPromoteJs(pieceType, player, fromRow, toRow) {
    if (['K', 'G', '+P', '+L', '+N', '+S', '+B', '+R'].includes(pieceType)) return false;
    var promoZoneStart = player === 'b' ? 0 : 6;
    var promoZoneEnd = player === 'b' ? 2 : 8;
    return fromRow >= promoZoneStart && fromRow <= promoZoneEnd || toRow >= promoZoneStart && toRow <= promoZoneEnd;
  } // --- ゲーム開始 ---


  initializeGame();
});