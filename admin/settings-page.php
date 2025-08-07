<?php
// Gemini 将棋 プラグイン設定ページ

if (!defined('ABSPATH')) {
    exit;
}

// --- メニューページの追加 ---
function gemini_shogi_add_admin_menu() {
    add_options_page(
        'Gemini 将棋 設定',
        'Gemini 将棋',
        'manage_options',
        'gemini-shogi-settings',
        'gemini_shogi_settings_page_html'
    );
}
add_action('admin_menu', 'gemini_shogi_add_admin_menu');

// --- 設定項目の登録 ---
function gemini_shogi_register_settings() {
    register_setting('gemini_shogi_options', 'gemini_shogi_api_provider', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'gemini'
    ]);
    register_setting('gemini_shogi_options', 'gemini_shogi_api_key', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => ''
    ]);
    register_setting('gemini_shogi_options', 'gemini_shogi_openrouter_api_key', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => ''
    ]);
    register_setting('gemini_shogi_options', 'gemini_shogi_openrouter_model_name', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'openrouter/horizon-beta'
    ]);
}
add_action('admin_init', 'gemini_shogi_register_settings');

// --- 設定ページのHTMLを描画 ---
function gemini_shogi_settings_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $api_provider = get_option('gemini_shogi_api_provider', 'gemini');
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <p>AIとの対局に使用するAPIプロバイダーを選択し、必要な情報を設定してください。</p>

        <form action="options.php" method="post">
            <?php
            settings_fields('gemini_shogi_options');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">APIプロバイダー</th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="gemini_shogi_api_provider" value="gemini" <?php checked($api_provider, 'gemini'); ?>>
                                <span>Gemini API</span>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="gemini_shogi_api_provider" value="openrouter" <?php checked($api_provider, 'openrouter'); ?>>
                                <span>OpenRouter API</span>
                            </label>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <div id="gemini-settings" style="<?php echo $api_provider === 'gemini' ? '' : 'display:none;'; ?>">
                <h2>Gemini API 設定</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="gemini_shogi_api_key">Gemini APIキー</label></th>
                        <td>
                            <input type="password" id="gemini_shogi_api_key" name="gemini_shogi_api_key" value="<?php echo esc_attr(get_option('gemini_shogi_api_key')); ?>" class="regular-text">
                            <p class="description">Google AI Studioで取得したAPIキーを入力してください。</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div id="openrouter-settings" style="<?php echo $api_provider === 'openrouter' ? '' : 'display:none;'; ?>">
                <h2>OpenRouter API 設定</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="gemini_shogi_openrouter_api_key">OpenRouter APIキー</label></th>
                        <td>
                            <input type="password" id="gemini_shogi_openrouter_api_key" name="gemini_shogi_openrouter_api_key" value="<?php echo esc_attr(get_option('gemini_shogi_openrouter_api_key')); ?>" class="regular-text">
                            <p class="description">OpenRouterで取得したAPIキー (<code>sk-or-</code>で始まる) を入力してください。</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="gemini_shogi_openrouter_model_name">モデル名</label></th>
                        <td>
                            <input type="text" id="gemini_shogi_openrouter_model_name" name="gemini_shogi_openrouter_model_name" value="<?php echo esc_attr(get_option('gemini_shogi_openrouter_model_name', 'openrouter/horizon-beta')); ?>" class="regular-text">
                            <p class="description">使用するモデル名を入力してください。例: <code>openrouter/horizon-beta</code>, <code>google/gemini-pro</code></p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button('設定を保存'); ?>
        </form>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const providerRadios = document.querySelectorAll('input[name="gemini_shogi_api_provider"]');
            const geminiSettings = document.getElementById('gemini-settings');
            const openrouterSettings = document.getElementById('openrouter-settings');

            function toggleSettings() {
                const selectedProvider = document.querySelector('input[name="gemini_shogi_api_provider"]:checked').value;
                if (selectedProvider === 'gemini') {
                    geminiSettings.style.display = '';
                    openrouterSettings.style.display = 'none';
                } else {
                    geminiSettings.style.display = 'none';
                    openrouterSettings.style.display = '';
                }
            }

            providerRadios.forEach(radio => {
                radio.addEventListener('change', toggleSettings);
            });
        });
    </script>
    <?php
}