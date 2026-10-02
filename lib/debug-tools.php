<?php
/**
 * 開発・運用メンテナンス用デバッグツール
 *
 * 全キャラクターデータの一括インデックス更新、個別キャラクターの再計算、
 * JSON確認用ショートコード、ACFマッピングテスト用ショートコードを定義します。
 */

if (!defined('ABSPATH')) exit;

// =================================================================
// 1. 【管理用】全キャラクターデータ一括更新機能
// URL末尾に ?run_update_index=1 をつけてアクセスすると実行
// =================================================================
add_action('init', 'force_update_all_characters_index');

/**
 * 全キャラクターのスペックJSONおよび検索データをバッチ単位で一括更新する
 *
 * @return void
 */
function force_update_all_characters_index()
{
    // 1. 管理者権限チェック & パラメータチェック
    if (!current_user_can('administrator') || !isset($_GET['run_update_index'])) {
        return;
    }

    // 2. タイムアウト対策
    set_time_limit(300); // 5分

    // 3. 計算用ファイルの読み込み（lib/koto-calc.php または直下）
    $calc_file = get_stylesheet_directory() . '/lib/koto-calc.php';
    if (!file_exists($calc_file)) {
        $calc_file = get_stylesheet_directory() . '/koto-calc.php';
    }
    if (file_exists($calc_file)) {
        require_once $calc_file;
    }

    // 4. 全キャラクター取得を小さめバッチで実行（ACF使用時のメモリ急増を抑える）
    $posts_per_page = 100;
    $paged = isset($_GET['batch_page']) ? max(1, intval($_GET['batch_page'])) : 1;
    $count = 0;

    echo '<div style="background:#fff; padding:20px; border:2px solid #00a0d2; margin:20px; z-index:9999; position:relative;">';
    echo "<h3>デバッグ情報</h3>";
    echo "<ul>";
    echo "<li>計算ファイルパス: " . esc_html($calc_file) . " (" . (file_exists($calc_file) ? '発見' : '見つかりません') . ")</li>";
    echo "<li>保存関数 (on_save_character_specs): " . (function_exists('on_save_character_specs') ? '有効' : '無効(見つかりません)') . "</li>";
    echo "</ul>";

    $args = [
        'post_type'      => 'character',
        'posts_per_page' => $posts_per_page,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'paged'          => $paged,
    ];

    $query = new WP_Query($args);
    $found_posts = $query->found_posts;

    echo "<ul>";
    echo "<li>対象キャラクター数: " . intval($found_posts) . " 体</li>";
    echo "<li>現在のバッチ: " . intval($paged) . " / " . max(1, $query->max_num_pages) . "</li>";
    echo "</ul>";

    if ($query->have_posts()) {
        foreach ($query->posts as $post_id) {
            if (function_exists('on_save_character_specs')) {
                on_save_character_specs($post_id);
                $count++;
            }
        }
    }

    wp_reset_postdata();

    echo "<h3>更新結果</h3>";
    echo "<p><strong>" . intval($count) . "</strong> 体のデータを更新しました。</p>";

    if ($count === 0 && $found_posts > 0) {
        echo "<p style='color:red;'>※キャラクターはいるのに更新数が0です。保存関数が読み込めていません。<br>koto-calc.php が正しく読み込まれているか確認してください。</p>";
    }

    if ($query->max_num_pages > $paged) {
        $next_page = $paged + 1;
        $next_url = add_query_arg(['run_update_index' => '1', 'batch_page' => $next_page]);
        echo '<a href="' . esc_url($next_url) . '" style="display:inline-block; margin-top:10px; padding:10px 20px; background:#00a0d2; color:#fff; text-decoration:none;">次の' . intval($posts_per_page) . '体を更新</a>';
    } else {
        echo '<a href="' . esc_url(home_url('/wp-admin/edit.php?post_type=character&page=koto-json-reform')) . '" style="display:inline-block; margin-top:10px; padding:10px 20px; background:#00a0d2; color:#fff; text-decoration:none;">元の画面に戻る</a>';
    }

    echo '</div>';
    exit;
}

// =================================================================
// 2. 単一キャラクター強制再計算フック
// サイトURL/wp-admin/?force_calc_id=123 でアクセス
// =================================================================
add_action('admin_init', function () {
    if (isset($_GET['force_calc_id']) && current_user_can('edit_posts')) {
        $post_id = intval($_GET['force_calc_id']);
        if (function_exists('on_save_character_specs')) {
            on_save_character_specs($post_id);
            wp_die("ID: {$post_id} のJSONとタグを再生成しました。");
        } else {
            wp_die("エラー: on_save_character_specs 関数が見つかりません。");
        }
    }
});

// =================================================================
// 3. デバッグ用ショートコード: [debug_koto_json id=123]
// =================================================================
add_shortcode('debug_koto_json', function ($atts) {
    static $instance_count = 0;
    $instance_count++;

    // 1. IDの決定
    $default_id = get_the_ID();
    $atts = shortcode_atts(['id' => $default_id], $atts);

    // このショートコードインスタンス専用のパラメータ名 (例: debug_id_1)
    $param_name = 'debug_id_' . $instance_count;

    // GETパラメータがあればそれを優先
    $target_id = isset($_GET[$param_name]) ? intval($_GET[$param_name]) : intval($atts['id']);

    // 2. データ取得
    $json = get_post_meta($target_id, '_spec_json', true);

    // HTML要素用のユニークID
    $html_id_suffix = $target_id . '_' . $instance_count;

    // 3. 出力バッファリング開始
    ob_start();
?>
    <div class="debug-json-box" style="border:1px solid #ccc; padding:15px; background:#f9f9f9; margin:20px 0;">
        <!-- ID切り替えフォーム -->
        <form method="get" action="" style="margin-bottom:10px; display:flex; gap:10px; align-items:center;">
            <label style="font-weight:bold;">確認したい記事ID:
                <input type="number" name="<?php echo esc_attr($param_name); ?>" value="<?php echo esc_attr($target_id); ?>" style="width:100px; padding:5px;">
            </label>

            <?php
            foreach ($_GET as $key => $val) {
                if ($key !== $param_name && !is_array($val)) {
                    echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '">';
                }
            }
            ?>

            <button type="submit" style="padding:5px 15px; cursor:pointer; background:#2271b1; color:#fff; border:none; border-radius:3px;">表示</button>

            <?php if ($json): ?>
                <button type="button" id="copy-json-btn-<?php echo esc_attr($html_id_suffix); ?>" style="padding:5px 15px; cursor:pointer; background:#fff; border:1px solid #2271b1; color:#2271b1; border-radius:3px;">📋 JSONをコピー</button>
            <?php endif; ?>
        </form>

        <?php if (!$json): ?>
            <p style="color:red; font-weight:bold;">ID: <?php echo esc_html($target_id); ?> のJSONデータが見つかりません。<br>記事を保存し直すか、一括更新を実行してください。</p>
        <?php else: ?>
            <!-- タブ切り替え -->
            <div style="display:flex; gap:10px; margin-bottom:10px; border-bottom:2px solid #ddd;">
                <button type="button" class="debug-tab-btn" data-tab="spec" data-suffix="<?php echo esc_attr($html_id_suffix); ?>" style="padding:8px 15px; cursor:pointer; background:#2271b1; color:#fff; border:none; border-radius:3px 3px 0 0; font-weight:bold;">_spec_json</button>
                <button type="button" class="debug-tab-btn" data-tab="search" data-suffix="<?php echo esc_attr($html_id_suffix); ?>" style="padding:8px 15px; cursor:pointer; background:#ccc; color:#333; border:none; border-radius:3px 3px 0 0; font-weight:bold;">検索用JSON</button>
            </div>

            <!-- _spec_json タブ -->
            <div id="debug-tab-spec-<?php echo esc_attr($html_id_suffix); ?>" class="debug-tab-content" style="display:block;">
                <?php
                $data = json_decode($json, true);
                $pretty_json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                ?>
                <textarea id="json-textarea-<?php echo esc_attr($html_id_suffix); ?>" style="width:100%; height:500px; font-family:monospace; font-size:12px; line-height:1.5; white-space:pre; background:#fff; border:1px solid #ddd; padding:10px;" readonly><?php echo esc_textarea($pretty_json); ?></textarea>
                <button type="button" id="copy-json-btn-<?php echo esc_attr($html_id_suffix); ?>" style="margin-top:10px; padding:5px 15px; cursor:pointer; background:#fff; border:1px solid #2271b1; color:#2271b1; border-radius:3px;">📋 JSONをコピー</button>
            </div>

            <!-- 検索用JSON タブ -->
            <div id="debug-tab-search-<?php echo esc_attr($html_id_suffix); ?>" class="debug-tab-content" style="display:none;">
                <?php
                if (function_exists('koto_get_flat_char_data')) {
                    $search_data = koto_get_flat_char_data($target_id);
                    if ($search_data) {
                        $pretty_search_json = json_encode($search_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    } else {
                        $pretty_search_json = json_encode(['error' => '検索用JSONデータが取得できません'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    }
                } else {
                    $pretty_search_json = json_encode(['error' => 'koto_get_flat_char_data()関数が見つかりません'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                }
                ?>
                <textarea id="search-json-textarea-<?php echo esc_attr($html_id_suffix); ?>" style="width:100%; height:500px; font-family:monospace; font-size:12px; line-height:1.5; white-space:pre; background:#fff; border:1px solid #ddd; padding:10px;" readonly><?php echo esc_textarea($pretty_search_json); ?></textarea>
                <button type="button" id="copy-search-json-btn-<?php echo esc_attr($html_id_suffix); ?>" style="margin-top:10px; padding:5px 15px; cursor:pointer; background:#fff; border:1px solid #2271b1; color:#2271b1; border-radius:3px;">📋 JSONをコピー</button>
            </div>

            <script>
                document.querySelectorAll('.debug-tab-btn[data-suffix="<?php echo esc_js($html_id_suffix); ?>"]').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var tab = this.getAttribute('data-tab');
                        var suffix = this.getAttribute('data-suffix');

                        document.querySelectorAll('.debug-tab-btn[data-suffix="' + suffix + '"]').forEach(function(b) {
                            b.style.background = '#ccc';
                            b.style.color = '#333';
                        });
                        document.querySelectorAll('.debug-tab-content').forEach(function(content) {
                            if (content.id.includes(suffix)) {
                                content.style.display = 'none';
                            }
                        });

                        this.style.background = '#2271b1';
                        this.style.color = '#fff';
                        document.getElementById('debug-tab-' + tab + '-' + suffix).style.display = 'block';
                    });
                });

                document.getElementById('copy-json-btn-<?php echo esc_js($html_id_suffix); ?>').addEventListener('click', function() {
                    var copyText = document.getElementById("json-textarea-<?php echo esc_js($html_id_suffix); ?>");
                    copyText.select();
                    copyText.setSelectionRange(0, 99999);

                    navigator.clipboard.writeText(copyText.value).then(function() {
                        alert("_spec_json データをクリップボードにコピーしました！");
                    }).catch(function(err) {
                        console.error('コピーに失敗しました', err);
                    });
                });

                document.getElementById('copy-search-json-btn-<?php echo esc_js($html_id_suffix); ?>').addEventListener('click', function() {
                    var copyText = document.getElementById("search-json-textarea-<?php echo esc_js($html_id_suffix); ?>");
                    copyText.select();
                    copyText.setSelectionRange(0, 99999);

                    navigator.clipboard.writeText(copyText.value).then(function() {
                        alert("検索用JSONデータをクリップボードにコピーしました！");
                    }).catch(function(err) {
                        console.error('コピーに失敗しました', err);
                    });
                });
            </script>
        <?php endif; ?>
    </div>
<?php
    return ob_get_clean();
});

// =================================================================
// 4. ショートコード [test_acf_mapping]
// =================================================================
/**
 * ゲーム内文言とACF対応表CSVの正規表現マッピングテストツールショートコード
 *
 * @return string 生成されたHTML
 */
function test_acf_mapping_shortcode()
{
    ob_start();

    $csv_path = get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv';

    $mock_csv = [
        ['種別' => 'とくせい', '文言' => '福{$val}で{$gimmick_name}が解放', 'ACFに入力するJSON' => '{"trait_type" : "gimmick","gimmick" : "$gimmick_name","condition_type_loop" : [{"condition_type" : "fuku_count","condition_value" : "$val"}]}'],
        ['種別' => 'とくせい', '文言' => '【{$val}】属性のATKを{$val}UP', 'ACFに入力するJSON' => '{"trait_type" : "status_up","target" : "$val","rate" : "$val"}']
    ];

    $csv_data = [];
    if (file_exists($csv_path)) {
        $file = fopen($csv_path, 'r');
        $headers = fgetcsv($file);
        while (($row = fgetcsv($file)) !== FALSE) {
            if (count($headers) == count($row)) {
                $csv_data[] = array_combine($headers, $row);
            }
        }
        fclose($file);
    } else {
        $csv_data = $mock_csv;
        echo "<p style='color:red;'>※CSVファイルが見つかりません。テスト用のモックデータで実行します。</p>";
    }

    $input_text = isset($_POST['test_text']) ? sanitize_text_field($_POST['test_text']) : '';
    $result_output = '';

    if ($input_text) {
        $match_found = false;
        foreach ($csv_data as $row) {
            $template = $row['文言'] ?? '';
            $json_template = $row['ACFに入力するJSON'] ?? '';

            preg_match_all('/\{\$(.*?)\}/', $template, $ph_matches);
            $pattern = preg_quote($template, '/');

            $seen_vars = [];

            if (!empty($ph_matches[1])) {
                foreach ($ph_matches[1] as $idx => $var_name) {
                    $quoted_ph = preg_quote($ph_matches[0][$idx], '/');
                    $pos = strpos($pattern, $quoted_ph);
                    if ($pos !== false) {
                        if (!in_array($var_name, $seen_vars)) {
                            $replacement = '(?P<' . $var_name . '>.+?)';
                            $seen_vars[] = $var_name;
                        } else {
                            $replacement = '(?P=' . $var_name . ')';
                        }
                        $pattern = substr_replace($pattern, $replacement, $pos, strlen($quoted_ph));
                    }
                }
            }
            $pattern = '/^' . $pattern . '$/u';

            if (preg_match($pattern, $input_text, $matches)) {
                $match_found = true;
                $json_str = $json_template;

                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $json_str = str_replace('$' . $key, $value, $json_str);
                    }
                }

                $acf_data = json_decode($json_str, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $result_output = "<p style='color:red;'>JSONパースエラー: " . json_last_error_msg() . "<br>生成された文字列: " . esc_html($json_str) . "</p>";
                } else {
                    if (isset($acf_data['gimmick_prefix'])) {
                        if ($acf_data['gimmick_prefix'] === '大きく') {
                            $acf_data['gimmick'] = 'スーパー' . $acf_data['gimmick'];
                        }
                        unset($acf_data['gimmick_prefix']);
                    }

                    $result_output = "<h3 style='color:green;'>マッチ成功！</h3>";
                    $result_output .= "<p><strong>適用されたテンプレート:</strong> " . esc_html($template) . "</p>";
                    $result_output .= "<h4>▼ 生成されたACF用配列</h4>";
                    $result_output .= "<pre style='background:#f4f4f4; padding:10px; border:1px solid #ccc;'>" . esc_html(print_r($acf_data, true)) . "</pre>";
                }
                break;
            }
        }

        if (!$match_found) {
            $result_output = "<h3 style='color:orange;'>一致するテンプレートが見つかりませんでした。</h3>";
        }
    }
?>
    <div style="max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
        <h2>CSV対応表 テストツール</h2>
        <form method="post" action="">
            <label for="test_text"><strong>AI抽出テキスト（ゲーム内文言）:</strong></label><br>
            <input type="text" id="test_text" name="test_text" value="<?php echo esc_attr($input_text); ?>" style="width: 100%; padding: 8px; margin: 10px 0;" placeholder="例: 福30でシールドブレイカーが解放">
            <button type="submit" style="padding: 10px 20px; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer;">テスト実行</button>
        </form>
        <hr>
        <div>
            <?php echo $result_output; ?>
        </div>
    </div>
<?php
    return ob_get_clean();
}
add_shortcode('test_acf_mapping', 'test_acf_mapping_shortcode');
