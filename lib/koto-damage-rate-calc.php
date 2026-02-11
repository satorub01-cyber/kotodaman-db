<?php
// =================================================================
//  火力シミュレーター機能 (CSS/JS読込 & HTML出力)
// =================================================================

/**
 * 1. キャラクターページ専用のCSSとJSを読み込む
 */
function enqueue_koto_calc_assets()
{
    if (!is_singular('character')) return;

    $theme_uri = get_stylesheet_directory_uri();
    $theme_dir = get_stylesheet_directory();

    // CSS読み込み
    $css_file = '/calc-style.css';
    if (file_exists($theme_dir . $css_file)) {
        wp_enqueue_style('koto-calc-style', $theme_uri . $css_file, [], filemtime($theme_dir . $css_file));
    }

    // JS読み込み
    $js_file = '/calc-script.js';
    if (file_exists($theme_dir . $js_file)) {
        wp_enqueue_script('koto-calc-script', $theme_uri . $js_file, ['jquery'], filemtime($theme_dir . $js_file), true);

        // PHPデータをJSに渡す
        $post_id = get_the_ID();
        $json = get_post_meta($post_id, '_spec_json', true);

        if ($json) {
            $data = json_decode($json, true);
            wp_localize_script('koto-calc-script', 'kotoCalcObj', [
                'specs' => $data
            ]);
        }
    }
}
add_action('wp_enqueue_scripts', 'enqueue_koto_calc_assets');

/**
 * 2. 計算機のHTMLを出力するショートコード [koto_damage_calc]
 */
add_shortcode('koto_damage_calc', function () {
    ob_start();
?>
    <div id="koto-calc-container" class="koto-calc-wrapper skill-card card-calc">
        <div class="skill-badge-area">
            <span class="skill-badge">火力シミュレーター</span>
        </div>

        <div class="calc-body">
            <div class="calc-row">
                <span class="calc-label">基礎ATK:</span>
                <span class="calc-value" id="calc-base-atk">0</span>
            </div>

            <div class="calc-control-box">
                <label>使用スキル:</label>
                <select id="calc-skill-selector">
                    <option value="sugowaza">すごわざ</option>
                    <option value="waza">わざ</option>
                </select>
            </div>

            <div id="calc-scenario-box" style="display:none;">
                <label style="color:#e65100; font-weight:bold;">発動条件パターン:</label>
                <select id="calc-scenario-selector" style="width:100%;">
                </select>
            </div>

            <div class="slider-container">
                <div class="slider-group">
                    <label>ATKバフ: <span id="val-buff-atk">0</span>段階</label>
                    <input type="range" id="input-buff-atk" min="0" max="10" value="0" step="1">
                </div>
                <div class="slider-group">
                    <label>防御デバフ: <span id="val-debuff-def">0</span>段階</label>
                    <input type="range" id="input-debuff-def" min="0" max="7" value="0" step="1">
                </div>
            </div>

            <div class="manual-input-container" style="background:#f9f9f9; padding:10px; margin:10px 0; border-radius:5px; border:1px solid #ddd;">
                <div style="font-weight:bold; font-size:0.9em; margin-bottom:5px; color:#555;">▼ 手動調整 (倍率・補正)</div>
                <div style="display:flex; gap:10px; align-items:center;">
                    <div style="flex:1;">
                        <label style="font-size:0.8em; display:block;">技倍率(倍)</label>
                        <input type="number" id="input-manual-rate" step="0.1" placeholder="自動" style="width:100%; padding:5px;">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:0.8em; display:block;">追加補正(%)</label>
                        <input type="number" id="input-manual-correction" value="0" step="5" style="width:100%; padding:5px;">
                    </div>
                </div>
            </div>

            <div class="calc-corrections">
                <div class="calc-corrections-title">とくせい・装備補正</div>
                <div id="calc-checkbox-area"></div>
            </div>

            <div class="calc-result-box">
                <div style="font-size:0.85em; color:#666;">
                    合計補正倍率: <span id="calc-total-correction">1.00</span>倍
                </div>
                <div class="calc-final-val">
                    予測ダメージ: <span id="calc-final-damage">0</span>
                </div>
                <div style="font-size:0.8em; color:#999; margin-top:5px;">※あくまで目安の指数です</div>
            </div>
        </div>
    </div>
<?php
    return ob_get_clean();
});

/**
 * 3. (互換性用) 既存のテンプレート関数
 * single-character.php で display_koto_damage_calculator() を呼んでいる場合用
 */
function display_koto_damage_calculator()
{
    echo do_shortcode('[koto_damage_calc]');
}
?>