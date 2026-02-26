<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'koto_acf_editor_menu');
function koto_acf_editor_menu()
{
    $page_hook = add_menu_page(
        'DBエディタ',
        'DBエディタ',
        'edit_posts',
        'koto-acf-editor',
        'koto_acf_editor_page_html',
        'dashicons-edit-page',
        30
    );

    add_action('load-' . $page_hook, 'koto_acf_editor_handle_actions');
    add_action('admin_enqueue_scripts', function ($hook) use ($page_hook) {
        if ($hook !== $page_hook) return;

        // ★追加: ACFのリピーター内で画像やエディタを正常に動かすための、WP標準スクリプトを強制ロード
        if (function_exists('acf_enqueue_scripts')) acf_enqueue_scripts();
        $theme_uri = get_stylesheet_directory_uri();
        wp_enqueue_style('acf-editor-style', $theme_uri . '/lib/acf/acf-editor.css', [], time());
        wp_enqueue_script('acf-editor-script', $theme_uri . '/lib/acf/acf-editor.js', ['jquery', 'acf-input'], time(), true);
    });
}
// =================================================================
// ACF関係フィールドの検索クエリをカスタマイズ（下書き対応＆権限絞り込み）
// =================================================================
add_filter('acf/fields/relationship/query/key=field_editor_edit_post', 'koto_acf_relationship_query_custom', 10, 3);
add_filter('acf/fields/relationship/query/key=field_editor_source_post', 'koto_acf_relationship_query_custom', 10, 3);
add_filter('acf/fields/relationship/query/key=field_editor_search_template', 'koto_acf_relationship_query_custom', 10, 3);

function koto_acf_relationship_query_custom($args, $field, $post_id)
{
    // 1. 下書きのキャラも検索結果に出るようにする（超重要！）
    $args['post_status'] = ['publish', 'draft', 'pending', 'private'];

    // 2. 左側（編集先）の検索で、他人の記事を編集できない権限の場合は自分の記事のみに絞る
    if ($field['key'] === 'field_editor_edit_post' && !current_user_can('edit_others_posts')) {
        $args['author'] = get_current_user_id();
    }
    // ★追加: 検索キーワードが数字（ID）だった場合、ID検索に切り替える
    if (!empty($args['s']) && is_numeric($args['s'])) {
        $args['p'] = intval($args['s']); // IDでの完全一致検索をセット
        unset($args['s']); // 通常のタイトルあいまい検索を解除
    }

    return $args;
}
// =================================================================
// ACF関係フィールドをシステムに仮登録する（AJAX検索を機能させるため）
// =================================================================
add_action('acf/init', function () {
    acf_add_local_field([
        'key'           => 'field_editor_edit_post',
        'label'         => 'Edit Post',
        'name'          => 'edit_post_id',
        'type'          => 'relationship',
        'post_type'     => ['character'],
        'filters'       => ['search', 'taxonomy'],
        'elements'      => ['featured_image'],
        'return_format' => 'id',
    ]);
    acf_add_local_field([
        'key'           => 'field_editor_source_post',
        'label'         => 'Source Post',
        'name'          => 'source_post_id',
        'type'          => 'relationship',
        'post_type'     => ['character'],
        'filters'       => ['search', 'taxonomy'],
        'elements'      => ['featured_image'],
        'return_format' => 'id',
    ]);
    acf_add_local_field([
        'key'           => 'field_editor_search_template',
        'label'         => 'Search Template',
        'name'          => 'search_template_id',
        'type'          => 'relationship',
        'post_type'     => ['character'],
        'filters'       => ['search'],
        'elements'      => ['featured_image'],
        'return_format' => 'id',
    ]);
});

function koto_acf_editor_handle_actions()
{
    $current_url = admin_url('admin.php?page=koto-acf-editor');
    // A. 雛型・既存キャラの複製
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acf_action']) && $_POST['acf_action'] === 'copy_template') {
        $search_temp_id = 0;
        // こちらも $_POST の実際のキー名から取得する
        if (!empty($_POST['field_editor_search_template']) && is_array($_POST['field_editor_search_template'])) {
            $search_temp_id = intval($_POST['field_editor_search_template'][0]);
        }
        $template_id = $search_temp_id ? $search_temp_id : intval($_POST['template_id']);
        $target_group = sanitize_text_field($_POST['target_group']);
        if ($template_id) {
            $template_post = get_post($template_id);

            // 投稿を作成（強制的に下書き）
            $new_post_id = wp_insert_post([
                'post_title'  => $template_post->post_title . '（コピー）',
                'post_status' => 'draft',
                'post_type'   => $template_post->post_type,
            ]);

            // 1. メタデータのコピー（データの破損を防ぐため maybe_unserialize を挟む）
            $meta_data = get_post_meta($template_id);
            foreach ($meta_data as $key => $values) {
                foreach ($values as $value) {
                    add_post_meta($new_post_id, $key, maybe_unserialize($value));
                }
            }

            // 2. タクソノミー（ターム情報）のコピー
            $taxonomies = get_object_taxonomies($template_post->post_type);
            foreach ($taxonomies as $taxonomy) {
                $terms = wp_get_object_terms($template_id, $taxonomy, ['fields' => 'ids']);
                if (!empty($terms) && !is_wp_error($terms)) {
                    wp_set_object_terms($new_post_id, $terms, $taxonomy);
                }
            }

            wp_safe_redirect(add_query_arg(['edit_post_id' => $new_post_id, 'acf_group' => $target_group], $current_url));
            exit;
        }
    }

    // B. フィールド全体の上書きコピペ処理
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acf_action']) && $_POST['acf_action'] === 'import_single_field') {
        $target_post_id = intval($_POST['target_post_id']);
        $source_post_id = intval($_POST['source_post_id']);
        $field_key      = sanitize_text_field($_POST['field_key']);
        $field_label    = sanitize_text_field($_POST['field_label']);

        if ($target_post_id && $source_post_id && $field_key) {
            $value = get_field($field_key, $source_post_id, false);
            update_field($field_key, $value, $target_post_id);

            $redirect_url = add_query_arg([
                'edit_post_id'   => $target_post_id,
                'acf_group'      => sanitize_text_field($_GET['acf_group']),
                'source_post_id' => $source_post_id,
                'source_group'   => sanitize_text_field($_GET['source_group']),
                'imported_field' => urlencode($field_label)
            ], $current_url);
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    // C. リピーターの「特定の1行」だけを末尾に追加コピペする処理
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acf_action']) && $_POST['acf_action'] === 'import_single_row') {
        $target_post_id = intval($_POST['target_post_id']);
        $source_post_id = intval($_POST['source_post_id']);
        $field_key      = sanitize_text_field($_POST['field_key']);
        $row_index      = intval($_POST['row_index']);
        $field_label    = sanitize_text_field($_POST['field_label']);

        if ($target_post_id && $source_post_id && $field_key) {
            // ソースの特定行を取得
            $source_data = get_field($field_key, $source_post_id, false);
            $row_data = isset($source_data[$row_index]) ? $source_data[$row_index] : null;

            if ($row_data) {
                // ターゲットの既存データを取得（無い場合は空配列にする）
                $target_data = get_field($field_key, $target_post_id, false);
                if (!is_array($target_data)) {
                    $target_data = [];
                }
                // 末尾に行を追加
                $target_data[] = $row_data;
                update_field($field_key, $target_data, $target_post_id);
            }

            $redirect_url = add_query_arg([
                'edit_post_id'   => $target_post_id,
                'acf_group'      => sanitize_text_field($_GET['acf_group']),
                'source_post_id' => $source_post_id,
                'source_group'   => sanitize_text_field($_GET['source_group']),
                'imported_row'   => urlencode($field_label . ' の 行' . ($row_index + 1))
            ], $current_url);
            wp_safe_redirect($redirect_url);
            exit;
        }
    }
    // --- 追加: 複数行の一括追加コピペ処理 ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acf_action']) && $_POST['acf_action'] === 'import_multiple_rows') {
        $target_post_id = intval($_POST['target_post_id']);
        $source_post_id = intval($_POST['source_post_id']);
        $copy_items_json = stripslashes($_POST['copy_items_json']);
        $copy_items = json_decode($copy_items_json, true);

        if ($target_post_id && $source_post_id && is_array($copy_items)) {
            $fields_to_update = [];
            foreach ($copy_items as $item) {
                $fields_to_update[$item['field_key']][] = intval($item['row_index']);
            }
            foreach ($fields_to_update as $field_key => $row_indices) {
                $source_data = get_field($field_key, $source_post_id, false);
                $target_data = get_field($field_key, $target_post_id, false);
                if (!is_array($target_data)) $target_data = [];

                foreach ($row_indices as $row_index) {
                    if (isset($source_data[$row_index])) {
                        $target_data[] = $source_data[$row_index];
                    }
                }
                update_field($field_key, $target_data, $target_post_id);
            }
            $redirect_url = add_query_arg([
                'edit_post_id' => $target_post_id,
                'acf_group' => sanitize_text_field($_GET['acf_group']),
                'source_post_id' => $source_post_id,
                'source_group' => sanitize_text_field($_GET['source_group']),
                'imported_multiple' => count($copy_items)
            ], $current_url);
            wp_safe_redirect($redirect_url);
            exit;
        }
    }


    // D. カスタムステータス・アイキャッチ保存
    add_action('acf/save_post', function ($post_id) {
        if (isset($_POST['custom_post_status']) && in_array($_POST['custom_post_status'], ['draft', 'publish'])) {
            wp_update_post(['ID' => $post_id, 'post_status' => $_POST['custom_post_status']]);
        }
        $image_id = get_field('character_image', $post_id);
        if ($image_id) set_post_thumbnail($post_id, $image_id);
        else delete_post_thumbnail($post_id);
    }, 20);

    if (function_exists('acf_form_head')) acf_form_head();
}

// プレビュー表示用ヘルパー
if (!function_exists('koto_acf_render_preview_html')) {
    function koto_acf_render_preview_html($value, $depth = 0)
    {
        if (empty($value) && $value !== '0' && $value !== 0) return '<span style="color:#aaa;">データなし</span>';
        if (is_array($value)) {
            if (isset($value['url']) && isset($value['title'])) return '🖼️ ' . esc_html($value['title']);
            if (isset($value['term_id']) && isset($value['name'])) return esc_html($value['name']);

            $items = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && strpos($k, 'field_') === 0) continue;
                $rendered = koto_acf_render_preview_html($v, $depth + 1);
                if ($rendered !== '<span style="color:#aaa;">データなし</span>' && $rendered !== '') {

                    // ★修正: 英語のフィールド名($k)から、日本語のラベルを取得する
                    $key_text = '';
                    if (is_numeric($k)) {
                        $key_text = '行' . ($k + 1);
                    } else {
                        $f_obj = function_exists('acf_get_field') ? acf_get_field($k) : false;
                        // ラベルが取得できればラベルを、できなければ元のフィールド名を表示
                        $key_text = ($f_obj && isset($f_obj['label'])) ? esc_html($f_obj['label']) : esc_html($k);
                    }

                    $items[] = "<div style='margin-bottom:3px;'><strong style='color:#555;'>{$key_text}:</strong> {$rendered}</div>";
                }
            }
            if (empty($items)) return '<span style="color:#aaa;">データなし</span>';
            $margin = $depth > 0 ? 'margin-left: 10px; border-left: 2px solid #ddd; padding-left: 8px;' : '';
            return '<div style="' . $margin . '">' . implode('', $items) . '</div>';
        } elseif (is_object($value)) {
            if (isset($value->name)) return esc_html($value->name);
            if (isset($value->post_title)) return esc_html($value->post_title);
            return 'Object';
        } else {
            return esc_html(wp_trim_words((string)$value, 15));
        }
    }
}

function koto_acf_editor_page_html()
{
    $field_group_keys = [
        'group_69204fa4dd82e' => '',
        'group_6937900895bf1' => '',
        'group_693790bd6b499' => '',
        'group_693969515ca4d' => '',
        'group_693790ee221c3' => '',
        'group_693971a11a6b2' => '',
        'group_693c070768756' => '',
    ];
    $template_post_ids = [2023 => '', 2637 => '', 2638 => ''];

    if (function_exists('acf_get_field_group')) {
        foreach ($field_group_keys as $key => $name) {
            if ($name === '') {
                $group = acf_get_field_group($key);
                $field_group_keys[$key] = $group ? $group['title'] : '未定義グループ';
            }
        }
    }
    foreach ($template_post_ids as $id => $name) {
        if ($name === '') {
            $title = get_the_title($id);
            $template_post_ids[$id] = $title ? $title : '未定義の投稿';
        }
    }

    // ★修正: ACFが実際に送信してくるキー（field_editor_***）からIDを抽出する
    // ★修正: ACFの検索から来た場合と、コピー後のリダイレクトで来た場合の両方に対応
    $edit_post_id = 0;
    if (!empty($_GET['field_editor_edit_post']) && is_array($_GET['field_editor_edit_post'])) {
        // ACFの関係フィールド検索から飛んできた場合
        $edit_post_id = intval($_GET['field_editor_edit_post'][0]);
    } elseif (!empty($_GET['edit_post_id'])) {
        // コピー処理や保存直後のシンプルなURLパラメータから飛んできた場合
        $edit_post_id = intval($_GET['edit_post_id']);
    }

    $edit_group = isset($_GET['acf_group']) ? sanitize_text_field($_GET['acf_group']) : '';

    $source_post_id = 0;
    if (!empty($_GET['field_editor_source_post']) && is_array($_GET['field_editor_source_post'])) {
        $source_post_id = intval($_GET['field_editor_source_post'][0]);
    } elseif (!empty($_GET['source_post_id'])) {
        $source_post_id = intval($_GET['source_post_id']);
    }

    $source_group = isset($_GET['source_group']) ? sanitize_text_field($_GET['source_group']) : '';

    $target_title = $edit_post_id ? get_the_title($edit_post_id) : '【未選択】';
    $source_title = $source_post_id ? get_the_title($source_post_id) : '【未選択】';
?>

    <div class="wrap acf-editor-wrap">
        <h1 class="wp-heading-inline">コトダマンDB エディタ</h1>
        <div class="notice notice-info" style="margin-bottom: 15px;">
            <p style="font-size:14px;"><strong>⌨️ ショートカットキー一覧：</strong>
                <code style="background:#e6f0fa;">Ctrl + S</code>: 公開/更新&emsp;|&emsp;
                <code style="background:#e6f0fa;">Ctrl + Enter</code>: チェックした行を一括コピー&emsp;|&emsp;
                <code style="background:#e6f0fa;">Ctrl + Shift + Alt + D</code>: フォーカス中の行を削除&emsp;|&emsp;
                <code style="background:#e6f0fa;">Ctrl + Shift + Alt + T</code>: リピーター先頭へ
            </p>
        </div>

        <?php if (isset($_GET['imported_multiple'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p><strong><?php echo intval($_GET['imported_multiple']); ?> 件</strong> の行データを一括で左へ追加コピーしました。</p>
            </div>
        <?php endif; ?>

        <div class="acf-editor-top-panel" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 15px;">
            <form method="GET" action="">
                <input type="hidden" name="page" value="koto-acf-editor">
                <div class="acf-sync-panel-flex" style="display: flex; gap: 20px; align-items: flex-start;">
                    <div class="acf-sync-col" style="flex: 1; width: 100%;">
                        <strong style="color: #2271b1;">📝【左】編集・インポート先のキャラと項目:</strong><br>
                        <input type="hidden" name="edit_post_id" id="real_edit_post_id" value="<?php echo esc_attr($edit_post_id ? $edit_post_id : ''); ?>">

                        <div class="acf-field acf-field-relationship" data-type="relationship" data-name="_dummy_edit_post_id" data-key="field_editor_edit_post" style="padding:0; border:none;">
                            <div class="acf-input">
                                <?php
                                acf_render_field([
                                    'type'          => 'relationship',
                                    'name'          => '_dummy_edit_post_id', // ★修正: ダミーの名前に変更
                                    'key'           => 'field_editor_edit_post', // 先ほどの権限フックと連動するキー
                                    'post_type'     => ['character'],
                                    'filters'       => ['search', 'taxonomy'], // 検索窓とタクソノミー絞り込みを表示
                                    'elements'      => ['featured_image'], // アイキャッチ画像を表示
                                    'return_format' => 'id',
                                    'value'         => $edit_post_id ? [$edit_post_id] : [],
                                ]);
                                ?>
                            </div>
                        </div>
                        <select name="acf_group" style="width:100%; margin-top:5px;">
                            <?php foreach ($field_group_keys as $key => $name) echo '<option value="' . esc_attr($key) . '" ' . selected($edit_group, $key, false) . '>' . esc_html($name) . '</option>'; ?>
                        </select>
                    </div>

                    <div class="acf-sync-arrow" style="display: flex; align-items: center; padding-top: 20px;">
                        <span style="font-size: 24px; color: #ccc;">⇔</span>
                    </div>

                    <div class="acf-sync-col" style="flex: 1; width: 100%;">
                        <strong style="color: #d63638;">📦【右】コピー元のキャラと項目:</strong><br>
                        <input type="hidden" name="source_post_id" id="real_source_post_id" value="<?php echo esc_attr($source_post_id ? $source_post_id : ''); ?>">

                        <div class="acf-field acf-field-relationship" data-type="relationship" data-name="_dummy_source_post_id" data-key="field_editor_source_post" style="padding:0; border:none;">
                            <div class="acf-input">
                                <?php
                                acf_render_field([
                                    'type'          => 'relationship',
                                    'name'          => '_dummy_source_post_id', // ★修正: ダミーの名前に変更
                                    'key'           => 'field_editor_source_post',
                                    'post_type'     => ['character'],
                                    'filters'       => ['search', 'taxonomy'],
                                    'elements'      => ['featured_image'],
                                    'return_format' => 'id',
                                    'value'         => $source_post_id ? [$source_post_id] : [],
                                ]);
                                ?>
                            </div>
                        </div>
                        <select name="source_group" style="width:100%; margin-top:5px;">
                            <?php foreach ($field_group_keys as $key => $name) echo '<option value="' . esc_attr($key) . '" ' . selected($source_group, $key, false) . '>' . esc_html($name) . '</option>'; ?>
                        </select>
                    </div>
                </div>

                <div style="text-align: center; margin-top: 15px;">
                    <button type="submit" class="button button-primary button-large" style="width: 50%;">この組み合わせで左右を同時に読み込む</button>
                </div>
            </form>
        </div>

        <?php if (isset($_GET['imported_field'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>「<?php echo esc_html(urldecode($_GET['imported_field'])); ?>」</strong> の全体データを上書きコピーしました。</p>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['imported_row'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>「<?php echo esc_html(urldecode($_GET['imported_row'])); ?>」</strong> のデータを末尾に追加コピーしました。</p>
            </div>
        <?php endif; ?>

        <div class="acf-editor-top-panel">
            <form method="POST" action="" class="acf-template-form" style="display:flex; gap:10px; align-items:center; flex-wrap: wrap;">
                <input type="hidden" name="acf_action" value="copy_template">
                <strong>雛型から新規作成:</strong>
                <select name="template_id">
                    <option value="">-- 雛型を選択 --</option>
                    <?php foreach ($template_post_ids as $id => $name) echo '<option value="' . esc_attr($id) . '">' . esc_html($name) . '</option>'; ?>
                </select>
                <span style="font-size: 12px; color: #666; margin: 0 5px;">または任意のキャラを検索:</span>
                <input type="hidden" name="search_template_id" id="real_search_template_id" value="">

                <div class="acf-field acf-field-relationship" data-type="relationship" data-name="_dummy_search_template_id" data-key="field_editor_search_template" style="padding:0; border:none; display:inline-block; vertical-align:middle; width:300px;">
                    <div class="acf-input">
                        <?php
                        acf_render_field([
                            'type'          => 'relationship',
                            'name'          => '_dummy_search_template_id', // ★修正: ダミーの名前に変更
                            'key'           => 'field_editor_search_template',
                            'post_type'     => ['character'],
                            'filters'       => ['search'],
                            'elements'      => ['featured_image'],
                            'return_format' => 'id',
                            'value'         => [],
                        ]);
                        ?>
                    </div>
                </div>

                <select name="target_group">
                    <?php foreach ($field_group_keys as $key => $name) echo '<option value="' . esc_attr($key) . '">' . esc_html($name) . '</option>'; ?>
                </select>
                <button type="submit" class="button button-secondary" onclick="return confirm('選択した雛型を複製して新しい下書きを作成しますか？');">複製して作成</button>
            </form>
        </div>
        <div class="acf-sticky-actions" style="position: sticky; top: 32px; z-index: 999; background: #fff; padding: 10px 20px; border-bottom: 2px solid #ccc; border-radius: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div style="display:flex; gap:10px;">
                <strong style="line-height: 2;">🌐 サイト確認:</strong>
                <?php
                if ($edit_post_id) {
                    $t_status = get_post_status($edit_post_id);
                    $t_link = ($t_status === 'publish') ? get_permalink($edit_post_id) : get_preview_post_link($edit_post_id);
                    echo '<a href="' . esc_url($t_link) . '" target="_blank" class="button">📝 左(編集中)を見る</a>';
                }
                if ($source_post_id) {
                    $s_status = get_post_status($source_post_id);
                    $s_link = ($s_status === 'publish') ? get_permalink($source_post_id) : get_preview_post_link($source_post_id);
                    echo '<a href="' . esc_url($s_link) . '" target="_blank" class="button">📦 右(コピー元)を見る</a>';
                }
                ?>
                <a href="https://kotodaman-db.com/magnification-calc/" target="_blank" class="button">倍率計算</a>
            </div>

            <script>
                var kotoCurrentPostStatus = "<?php echo $edit_post_id ? esc_js(get_post_status($edit_post_id)) : ''; ?>";
            </script>
            <div style="display:flex; gap:10px;">
                <?php if ($edit_post_id && $edit_group): ?>
                    <button type="button" class="button" id="btn_draft_sticky">下書き保存</button>
                    <button type="button" class="button button-primary button-large" id="btn_publish_sticky">公開 / 更新 </button>
                <?php else: ?>
                    <span style="color:#888; font-size:12px;">※左のキャラを指定すると保存できます</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="acf-editor-columns">
            <div class="acf-editor-col-left">
                <div class="acf-editor-panel-header">
                    <h2 class="target-heading">📝【編集中・コピー先】</h2>
                    <?php if ($edit_post_id && $edit_group) : ?>
                        <p style="margin:5px 0 0 0;"><strong>対象:</strong> <?php echo esc_html($target_title); ?> <br><strong>項目:</strong> <?php echo esc_html($field_group_keys[$edit_group] ?? ''); ?></p>
                    <?php endif; ?>
                </div>

                <div class="acf-editor-main-form">
                    <?php if ($edit_post_id && $edit_group) : ?>
                        <div class="acf-editor-post-info">
                            <strong>現在の編集対象: <?php echo esc_html($target_title); ?></strong>
                        </div>
                        <?php
                        acf_form([
                            'post_id' => $edit_post_id,
                            'field_groups' => [$edit_group],
                            'post_title' => true,
                            'html_submit_button' => '
                                <input type="hidden" name="custom_post_status" id="custom_post_status" value="">
                                <input type="submit" id="acf_real_submit" class="acf-button button button-primary button-large" value="変更を保存" style="display:none;">
                            ',
                        ]);
                        ?>
                    <?php else: ?>
                        <p>IDとグループを指定して「表示」を押してください。</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="acf-editor-col-right">
                <div class="acf-editor-panel-header source-header">
                    <h2 class="source-heading">📦【データ取得元】</h2>
                    <?php if ($source_post_id && $source_group) : ?>
                        <p style="margin:5px 0 0 0;"><strong>対象:</strong> <?php echo esc_html($source_title); ?> <br><strong>項目:</strong> <?php echo esc_html($field_group_keys[$source_group] ?? ''); ?></p>
                    <?php endif; ?>
                </div>

                <div class="acf-editor-export-area">
                    <?php
                    if ($source_post_id && $source_group) :
                    ?>
                        <?php if ($edit_post_id) : ?>
                            <form id="multi-copy-form" method="POST" action="" style="background:#e0f0fa; padding:10px; border-radius:4px; margin-bottom:15px; border:1px solid #b8e0f9; position:sticky; top:40px; z-index:10;">
                                <input type="hidden" name="acf_action" value="import_multiple_rows">
                                <input type="hidden" name="target_post_id" value="<?php echo esc_attr($edit_post_id); ?>">
                                <input type="hidden" name="source_post_id" value="<?php echo esc_attr($source_post_id); ?>">
                                <input type="hidden" name="copy_items_json" id="copy_items_json" value="">
                                <strong style="color:#135e96;">☑ 複数チェックして一括コピー</strong><br>
                                <button type="button" id="btn_execute_multi_copy" class="button button-primary" style="margin-top:5px; width:100%;">
                                    チェックした行をすべて左へコピー (Ctrl + Enter)
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php
                        echo '<p><strong>コピー元キャラ: ' . esc_html($source_title) . '</strong></p>';
                        $fields = acf_get_fields($source_group);

                        if ($fields) :
                            foreach ($fields as $field) :
                                if ($field['type'] !== 'repeater') continue;
                                $raw_val = get_field($field['key'], $source_post_id, false);
                                $formatted_val = get_field($field['key'], $source_post_id, true);
                                $preview = koto_acf_render_preview_html($formatted_val);
                        ?>
                                <div class="acf-single-copy-box">
                                    <div class="copy-box-info">
                                        <h4><?php echo esc_html($field['label']); ?> <span class="field-type-badge"><?php echo esc_html($field['type']); ?></span></h4>
                                        <?php if ($field['type'] !== 'repeater') : ?>
                                            <div class="copy-preview"><?php echo $preview; ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="copy-box-action">
                                        <?php if ($edit_post_id) :
                                            $confirm_msg = "【上書き警告】\n「{$field['label']}」の全体データを上書きコピーします。左側の既存データは消えます。\nよろしいですか？";
                                        ?>
                                            <form method="POST" action="">
                                                <input type="hidden" name="acf_action" value="import_single_field">
                                                <input type="hidden" name="target_post_id" value="<?php echo esc_attr($edit_post_id); ?>">
                                                <input type="hidden" name="source_post_id" value="<?php echo esc_attr($source_post_id); ?>">
                                                <input type="hidden" name="field_key" value="<?php echo esc_attr($field['key']); ?>">
                                                <input type="hidden" name="field_label" value="<?php echo esc_attr($field['label']); ?>">
                                                <button type="submit" class="button my-acf-copy-btn" onclick="return confirm('<?php echo esc_js($confirm_msg); ?>');">
                                                    全体を上書きコピー
                                                </button>
                                            </form>
                                        <?php else : ?>
                                            <span style="color:#888; font-size:12px;">※左で編集先を選ぶとコピー可能</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($field['type'] === 'repeater' && is_array($raw_val) && !empty($raw_val)) : ?>
                                        <div style="margin-top: 15px; border-top: 1px dashed #ccc; padding-top: 10px;">
                                            <strong style="font-size:12px; color:#555;">▼ 行ごとの追加コピー（左側の末尾に追加）</strong>

                                            <?php foreach ($raw_val as $row_index => $row_data) :
                                                $row_formatted = isset($formatted_val[$row_index]) ? $formatted_val[$row_index] : $row_data;
                                                $row_preview = koto_acf_render_preview_html($row_formatted);

                                                // ========================================================
                                                // ★ 行の概要（サマリー）テキストを生成するスケルトン
                                                // ========================================================
                                                $row_summary_text = '行 ' . ($row_index + 1) . ' のデータ'; // デフォルトの表示

                                                // リピーターフィールドの名前（英字キー）で分岐させます
                                                if ($field['name'] === 'your_repeater_name_1') {
                                                    // $row_formatted['サブフィールドの名前'] で中の値を取得できます
                                                    $val = isset($row_formatted['sub_field_name_1']) ? $row_formatted['sub_field_name_1'] : '未設定';
                                                    $row_summary_text = '▶ ' . esc_html($val);
                                                } elseif ($field['name'] === 'your_repeater_name_2') {
                                                    $val1 = isset($row_formatted['sub_field_1']) ? $row_formatted['sub_field_1'] : '';
                                                    $val2 = isset($row_formatted['sub_field_2']) ? $row_formatted['sub_field_2'] : '';
                                                    $row_summary_text = '▶ 属性: ' . esc_html($val1) . ' / 数値: ' . esc_html($val2);
                                                }
                                                // 必要に応じて elseif を増やしてください
                                                // ========================================================
                                            ?>
                                                <div style="margin-top:8px; background:#fff; border:1px solid #eee; border-radius:4px; overflow:hidden;">

                                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; background: #fdfdfd; border-bottom: 1px solid #eee;">
                                                        <label style="display:flex; align-items:center; gap:5px; cursor:pointer; margin:0;">
                                                            <input type="checkbox" class="multi-copy-check" data-field-key="<?php echo esc_attr($field['key']); ?>" data-row-index="<?php echo esc_attr($row_index); ?>">
                                                            <strong style="font-size: 12px; color: #135e96;">対象にする</strong>
                                                        </label>
                                                        <span style="flex-grow: 1; margin-left: 10px; font-size: 13px; font-weight: bold; color: #333; overflow: hidden; white-space: nowrap; text-overflow: ellipsis;">
                                                            <?php echo $row_summary_text; ?>
                                                        </span>
                                                    </div>

                                                    <details style="padding:0 10px 10px 10px;">
                                                        <summary style="font-size:12px; margin:10px 0 5px 0; cursor:pointer; color:#007cba; outline:none;">詳細を展開して確認</summary>

                                                        <div class="copy-preview" style="margin-bottom:8px; margin-top:8px;"><?php echo $row_preview; ?></div>

                                                        <?php if ($edit_post_id) :
                                                            $confirm_msg_row = "【追加コピー】\n「{$field['label']}」の行" . ($row_index + 1) . "のデータを、左の投稿の末尾に追加します。\nよろしいですか？";
                                                        ?>
                                                            <form method="POST" action="">
                                                                <input type="hidden" name="acf_action" value="import_single_row">
                                                                <input type="hidden" name="target_post_id" value="<?php echo esc_attr($edit_post_id); ?>">
                                                                <input type="hidden" name="source_post_id" value="<?php echo esc_attr($source_post_id); ?>">
                                                                <input type="hidden" name="field_key" value="<?php echo esc_attr($field['key']); ?>">
                                                                <input type="hidden" name="row_index" value="<?php echo esc_attr($row_index); ?>">
                                                                <input type="hidden" name="field_label" value="<?php echo esc_attr($field['label']); ?>">
                                                                <button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js($confirm_msg_row); ?>');">
                                                                    この行を追加
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </details>

                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                </div>

                        <?php
                            endforeach;
                        else:
                            echo '<p>フィールドが見つかりません。</p>';
                        endif;
                    else :
                        ?>
                        <p>IDとグループを指定して「取得」を押すと、フィールドごとのコピーボタンが表示されます。</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php
}
