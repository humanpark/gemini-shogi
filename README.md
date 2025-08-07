# Gemini Shogi (Gemini 将棋)

Gemini APIと連携し、AIとの将棋対戦を可能にするWordPressプラグインです。

This is a WordPress plugin that allows you to play Shogi against a Gemini-powered AI.

## 概要 (Overview)

このプラグインを導入すると、WordPressの投稿や固定ページに `[gemini-shogi]` というショートコードを記述するだけで、将棋盤を設置できます。プレイヤーは先手（☗）、Gemini APIを利用したAIが後手（☖）となり、対局を楽しむことができます。

By adding the `[gemini-shogi]` shortcode to any post or page, you can embed a Shogi board. The human player plays as Sente (Black), and the AI, powered by the Gemini API, plays as Gote (White).

## 主な機能 (Features)

*   **ショートコードによる簡単な埋め込み**: `[gemini-shogi]` だけで将棋盤を表示
*   **Gemini APIを活用したAI対戦**: GoogleのGeminiモデルが次の手を考えます
*   **3段階の難易度調整**: AIの強さを「やさしい」「ふつう」「プロ棋士」から選択可能
*   **合法手の表示**: 自分の駒を選択すると、移動可能なマスがハイライトされます
*   **持ち駒対応**: 取った駒を自分の持ち駒として使用できます
*   **厳密なルール判定**: 駒の動き、成り、王手、詰み、二歩や打ち歩詰めなどの禁じ手は、すべてサーバーサイド（PHP）で判定されるため、AIがルール違反の手を指すことはありません。

## 必須要件 (Requirements)

*   WordPress 5.0 以上
*   PHP 7.4 以上
*   Google Gemini APIキー

## インストール (Installation)

1.  このリポジトリをZIPファイルとしてダウンロードします。
2.  WordPressの管理画面にログインし、`[プラグイン] > [新規追加]` を選択します。
3.  画面上部の `[プラグインのアップロード]` ボタンをクリックします。
4.  ダウンロードしたZIPファイルを選択し、`[今すぐインストール]` をクリックします。
5.  インストールが完了したら、`[プラグインを有効化]` をクリックします。

## 設定と使い方 (Configuration and Usage)

1.  **APIキーの設定**:
    *   プラグインを有効化すると、管理画面のメニューに `[Gemini 将棋]` が追加されます。
    *   設定ページを開き、取得したGoogle Gemini APIキーを入力して保存してください。

2.  **将棋盤の表示**:
    *   将棋盤を表示したい投稿または固定ページの編集画面を開きます。
    *   本文中の好きな場所に、以下のショートコードを記述します。
        ```
        [gemini-shogi]
        ```
    *   ページを公開または更新すると、その場所に将棋盤が表示されます。
    *   将棋盤の下にあるドロップダウンメニューから、AIの強さをいつでも変更できます。

## 作者 (Author)

*   **HumanPark**
*   **Website**: [https://human-park.net/](https://human-park.net/)

## ライセンス (License)

[MIT License](LICENSE)