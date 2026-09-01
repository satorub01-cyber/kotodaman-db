<?php
// =========================================================
// iPhone風トグルスイッチを出力する共通関数（CSS＆JS内包版）
// =========================================================
function render_ios_toggle($name, $current_val = 'OR', $label_off = 'OR', $label_on = 'AND', $width = 40, $has_frame = true)
{
    static $assets_outputted = false;

    $is_on = ($current_val === 'AND');
    $active_off = !$is_on ? 'active' : '';
    $active_on = $is_on ? 'active' : '';
    $capsule_class = $has_frame ? ' ios-toggle-capsule' : '';

    // スイッチの比率計算
    $height = round($width * 0.56); // 高さを横幅の約56%に
    $padding = 2; // ノブの余白
    $knob_size = $height - ($padding * 2); // ノブの直径
    $translate_x = $width - $knob_size - ($padding * 2); // 移動距離

    // JavaScriptで使うセレクタのクラス名
    // `ios-toggle-container-minimal` は `ios-toggle-wrapper` に変更
    // `ios-toggle-wrapper` は常に存在し、`ios-toggle-capsule` が外枠のスタイルを定義する
    $wrapper_class = 'ios-toggle-wrapper';
    $js_selector_class = '.' . $wrapper_class;

    // CSS変数名も変更
    $css_var_prefix = '--sw-';

    ob_start();

    if (!$assets_outputted) {
?>
        <style>
            .ios-toggle-wrapper {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                font-size: 14px;
                vertical-align: middle;
                box-sizing: border-box;
            }

            /* 外枠（カプセル型）のスタイル */
            .ios-toggle-wrapper.ios-toggle-capsule {
                background-color: #fff;
                border: 1px solid #ccc;
                border-radius: 4px;
                padding: 4px 8px;
            }

            .ios-toggle-label-text {
                cursor: pointer;
                transition: color 0.2s, font-weight 0.2s;
                user-select: none;
            }

            .ios-toggle-label-text.active {
                color: #2271b1;
                font-weight: bold;
            }

            .ios-toggle-label-text:not(.active) {
                color: #777;
            }

            .ios-toggle-switch-dynamic {
                position: relative;
                display: inline-block;
                width: var(<?php echo $css_var_prefix; ?>width);
                height: var(<?php echo $css_var_prefix; ?>height);
            }

            .ios-toggle-switch-dynamic input {
                opacity: 0;
                width: 0;
                height: 0;
                position: absolute;
            }

            .ios-toggle-slider-dynamic {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #e5e5ea;
                transition: .3s;
                border-radius: var(<?php echo $css_var_prefix; ?>height);
            }

            .ios-toggle-slider-dynamic:before {
                position: absolute;
                content: "";
                height: var(<?php echo $css_var_prefix; ?>knob);
                width: var(<?php echo $css_var_prefix; ?>knob);
                left: var(<?php echo $css_var_prefix; ?>pad);
                bottom: var(<?php echo $css_var_prefix; ?>pad);
                background-color: white;
                transition: .3s;
                border-radius: 50%;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            }

            .ios-toggle-checkbox:checked+.ios-toggle-slider-dynamic {
                background-color: #2271b1;
            }

            .ios-toggle-checkbox:checked+.ios-toggle-slider-dynamic:before {
                transform: translateX(var(<?php echo $css_var_prefix; ?>translate));
            }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // トグルスイッチの状態に合わせてラベルとHidden要素を更新する共通関数
                const updateLabels = function(checkbox) {
                    const container = checkbox.closest('<?php echo $js_selector_class; ?>');
                    if (container) {
                        const labels = container.querySelectorAll('.ios-toggle-label-text');
                        const isChecked = checkbox.checked;
                        labels[0].classList.toggle('active', !isChecked);
                        labels[1].classList.toggle('active', isChecked);

                        // チェックされている時は hidden(OR) を無効化してURLパラメータのダブりを防ぐ
                        const hiddenInput = container.querySelector('input[type="hidden"]');
                        if (hiddenInput) {
                            hiddenInput.disabled = isChecked;
                        }
                    }
                };

                // 文字クリックとスイッチを連動させる共通処理
                document.body.addEventListener('change', function(e) {
                    if (e.target.classList.contains('ios-toggle-checkbox')) {
                        updateLabels(e.target);
                    }
                });

                document.body.addEventListener('click', function(e) {
                    const label = e.target.closest('.ios-toggle-label-text');
                    if (!label) return;

                    const container = label.closest('<?php echo $js_selector_class; ?>');
                    const checkbox = container.querySelector('.ios-toggle-checkbox');
                    const labels = container.querySelectorAll('.ios-toggle-label-text');
                    const isFirstLabel = (label === labels[0]);

                    if ((isFirstLabel && checkbox.checked) || (!isFirstLabel && !checkbox.checked)) {
                        checkbox.checked = !checkbox.checked;
                        checkbox.dispatchEvent(new Event('change', {
                            bubbles: true
                        }));
                    }
                });

                // フォームリセット時（条件クリア時）にラベルのスタイルを追従させる
                document.body.addEventListener('reset', function(e) {
                    setTimeout(() => {
                        const toggles = e.target.querySelectorAll('.ios-toggle-checkbox');
                        toggles.forEach(checkbox => updateLabels(checkbox));
                    }, 0); // DOMの状態がリセットされた直後に実行
                });

                // 初期表示時にも hidden の disabled 状態をセットする
                const initialToggles = document.querySelectorAll('.ios-toggle-checkbox');
                initialToggles.forEach(checkbox => { // `closest` は `ios-toggle-wrapper` を探すように変更
                    const hiddenInput = checkbox.closest('<?php echo $js_selector_class; ?>').querySelector('input[type="hidden"]');
                    if (hiddenInput) hiddenInput.disabled = checkbox.checked;
                });
            });
        </script>
    <?php
        $assets_outputted = true;
    }
    // `ios-toggle-container-minimal` を `ios-toggle-wrapper` に変更し、`ios-toggle-capsule` を条件付きで追加
    ?>
    <span class="<?php echo $wrapper_class . $capsule_class; ?>" style="
        <?php echo $css_var_prefix; ?>width: <?php echo $width; ?>px;
        <?php echo $css_var_prefix; ?>height: <?php echo $height; ?>px;
        <?php echo $css_var_prefix; ?>knob: <?php echo $knob_size; ?>px;
        <?php echo $css_var_prefix; ?>pad: <?php echo $padding; ?>px;
        <?php echo $css_var_prefix; ?>translate: <?php echo $translate_x; ?>px;">
        <span class="ios-toggle-label-text <?php echo $active_off; ?>"><?php echo esc_html($label_off); ?></span>
        <label class="ios-toggle-switch-dynamic">
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="OR">
            <input type="checkbox" class="ios-toggle-checkbox" name="<?php echo esc_attr($name); ?>" value="AND" <?php checked($is_on, true); ?>>
            <span class="ios-toggle-slider-dynamic"></span>
        </label>
        <span class="ios-toggle-label-text <?php echo $active_on; ?>"><?php echo esc_html($label_on); ?></span>
    </span>
<?php
    return ob_get_clean();
}
// シンプルなチェックボックスでrender_ios_toggleを利用するための関数
function render_simple_relation_toggle($name, $initial = 'OR')
{
    $relation = isset($_GET["{$name}_relation"]) ? $_GET["{$name}_relation"] : $initial;
    if (function_exists('render_ios_toggle')) {
        echo render_ios_toggle("{$name}_relation", $relation, 'OR', 'AND', 40, false); // 外枠なしで呼び出す
    }
}
// =================================================================
// コトダマンDB 検索機能拡張ロジック
// =================================================================

/**
 * 1. 階層構造付きチェックボックスリストを出力 (所属・イベント用)
 */
function render_frontend_term_tree($taxonomy, $name_attr, $args = [])
{
    // デフォルト設定
    $defaults = [
        'open_all'      => false, // デフォルトで開くかどうか
        'show_relation' => true,  // AND/OR切り替えを表示するか
        'and_or'        => 'OR',  // デフォルトのAND/OR設定 (URLパラメータがない場合)
        'parent_sync'   => true   // ★追加：親子連動(全選択)を有効にするか
    ];
    $config = array_merge($defaults, $args);

    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'parent'     => 0,
    ]);

    if (empty($terms) || is_wp_error($terms)) return;

    // AND/OR の初期値取得
    $relation_val = isset($_GET[$name_attr . '_relation']) ? $_GET[$name_attr . '_relation'] : $config['and_or'];

    echo '<div class="custom-term-selector-ui" data-tax="' . esc_attr($taxonomy) . '">';

    // 絞り込み検索窓
    echo '<input type="text" class="term-tree-search" placeholder="絞り込み検索..." />';

    // AND/OR 切り替えスイッチ (iPhone風トグル関数を呼び出す)
    if ($config['show_relation']) {
        echo render_ios_toggle($name_attr . '_relation', $relation_val, 'OR', 'AND');
    }

    echo '<div class="term-tree-list">';

    // 再帰関数の定義
    $walker = function ($terms, $walker_func) use ($name_attr, $taxonomy, $config) {
        echo '<ul class="term-children-container">';
        foreach ($terms as $term) {
            $children = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'parent'     => $term->term_id,
            ]);

            $checked = '';
            if (isset($_GET[$name_attr]) && is_array($_GET[$name_attr])) {
                if (in_array($term->slug, $_GET[$name_attr])) $checked = 'checked';
            }

            echo '<li class="term-tree-item">';

            $has_children = !empty($children);
            $open_attr = $config['open_all'] ? 'open' : '';

            if ($has_children) {
                echo '<details ' . $open_attr . '>';
                echo '<summary class="term-summary">';
            }

            // ★変更点：子要素を持っていて、かつ連動機能がオンならクラスを付与する
            $checkbox_class = ($has_children && $config['parent_sync']) ? 'class="js-parent-checkbox"' : '';

            echo '<label class="term-label">';
            echo '<input type="checkbox" ' . $checkbox_class . ' name="' . esc_attr($name_attr) . '[]" value="' . esc_attr($term->slug) . '" ' . $checked . '>';
            echo '<span class="term-name">' . esc_html($term->name) . '</span>';
            echo '</label>';

            if ($has_children) {
                echo '</summary>';
                $walker_func($children, $walker_func);
                echo '</details>';
            }

            echo '</li>';
        }
        echo '</ul>';
    };

    $walker($terms, $walker);

    echo '</div>';
    echo '</div>';
}

/**
 * 2. フラットなチェックボックスリストを出力 (属性・種族用)
 * @param string $taxonomy タクソノミー名
 * @param string $name_attr inputのname属性
 * @param bool $icon_only trueの場合、テキストを隠してアイコンのみのCSSクラスを付与
 */
function render_simple_checkbox_list($taxonomy, $name_attr, $icon_only = false)
{
    $terms = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
    ]);

    if (empty($terms) || is_wp_error($terms)) return;

    // アイコンのみモードなら専用クラス、そうでなければ通常クラス
    $container_class = $icon_only ? 'icon-only-list' : 'simple-checkbox-list';
    $label_class     = $icon_only ? 'icon-only-label' : 'simple-term-label';
    $text_class      = $icon_only ? 'term-text-hidden' : 'term-text'; // アイコンのみなら文字を隠す

    echo '<div class="' . $container_class . '">';
    foreach ($terms as $term) {
        $checked = '';
        if (isset($_GET[$name_attr]) && is_array($_GET[$name_attr])) {
            if (in_array($term->slug, $_GET[$name_attr])) $checked = 'checked';
        }

        $icon = '';
        if (function_exists('get_term_icon_html')) {
            $icon = get_term_icon_html($term, 'search-term-icon');
        }

        // アイコン取得結果が「テキスト」だけの場合は、追加で名前を表示しない
        $display_name = esc_html($term->name);
        if (strpos($icon, '<img') === false) {
            $display_content = $icon;
        } else {
            $display_content = $icon . '<span class="' . $text_class . '">' . $display_name . '</span>';
        }

        echo '<label class="' . $label_class . '">';
        echo '<input type="checkbox" name="' . esc_attr($name_attr) . '[]" value="' . esc_attr($term->slug) . '" ' . $checked . '>';
        echo $display_content;
        echo '</label>';
    }
    echo '</div>';
}

add_action('template_redirect', 'redirect_taxonomy_archive_to_search');
function redirect_taxonomy_archive_to_search()
{
    if (is_tax() || is_category() || is_tag()) {
        $term = get_queried_object();

        if ($term && isset($term->taxonomy, $term->slug)) {
            $tax_name  = $term->taxonomy;
            if($tax_name === 'affiliation'){
                $tax_name = 'tx_group';
            } elseif($tax_name === 'attribute'){
                $tax_name = 'tx_attr';
            } elseif($tax_name === 'species'){
                $tax_name = 'tx_species';
            } elseif($tax_name === 'event'){
                $tax_name = 'tx_event';
            } elseif($tax_name === 'gimmick'){
                $tax_name = 'tx_gimmick';
            } elseif($tax_name === 'rarity'){
                $tax_name = 'tx_rarity';
            }
            $term_slug = $term->slug;

            $redirect_url = home_url('/?post_type=character&' . $tax_name . '%5B%5D=' . $term_slug);

            wp_redirect($redirect_url, 301);
            exit;
        }
    }
}
