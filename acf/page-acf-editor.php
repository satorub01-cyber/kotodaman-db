<?php
/*
Template Name: ACFフロントエディター
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acf_action']) && $_POST['acf_action'] === 'copy_template') {
    $template_id = intval($_POST['template_id']);
    $target_group = sanitize_text_field($_POST['target_group']);

    if ($template_id) {
        $template_post = get_post($template_id);
        $new_post_id = wp_insert_post([
            'post_title'  => $template_post->post_title . '（コピー）',
            'post_status' => 'draft',
            'post_type'   => $template_post->post_type,
        ]);

        $meta_data = get_post_meta($template_id);
        foreach ($meta_data as $key => $values) {
            foreach ($values as $value) {
                add_post_meta($new_post_id, $key, $value);
            }
        }

        $redirect_url = add_query_arg([
            'edit_post_id' => $new_post_id,
            'acf_group'    => $target_group
        ], acf_get_current_url());
        wp_safe_redirect($redirect_url);
        exit;
    }
}

add_action('acf/save_post', function ($post_id) {
    if (isset($_POST['custom_post_status']) && in_array($_POST['custom_post_status'], ['draft', 'publish'])) {
        wp_update_post([
            'ID'          => $post_id,
            'post_status' => $_POST['custom_post_status']
        ]);
    }

    $image_id = get_field('character_image', $post_id);
    if ($image_id) {
        set_post_thumbnail($post_id, $image_id);
    } else {
        delete_post_thumbnail($post_id);
    }
}, 20);

acf_form_head();
get_header();

$field_group_keys = [
    'group_693c070768756' => '',
    'group_693790bd6b499' => '',
    'group_693790ee221c3' => '',
    'group_693969515ca4d' => '',
    'group_6937900895bf1' => '',
    'group_69204fa4dd82e' => '',
    'group_693971a11a6b2' => '',
];

$template_post_ids = [
    2023 => '',
    2637 => '',
    2638 => '',
];

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

$current_post_id = isset($_GET['edit_post_id']) ? intval($_GET['edit_post_id']) : '';
$current_group = isset($_GET['acf_group']) ? sanitize_text_field($_GET['acf_group']) : '';

$theme_uri = get_stylesheet_directory_uri();
wp_enqueue_style('acf-editor-style', $theme_uri . '/acf/acf-editor.css');
wp_enqueue_script('acf-editor-script', $theme_uri . '/acf/acf-editor.js', array('jquery'), null, true);
?>

<div class="acf-editor-container">

    <div class="acf-editor-controls">
        <div class="acf-control-panel">
            <h3>既存の投稿を編集</h3>
            <form method="GET" action="" class="acf-control-form">
                <label>投稿IDを入力して編集:
                    <input type="number" name="edit_post_id" value="<?php echo esc_attr($current_post_id); ?>" required>
                </label>
                <label>編集する項目:
                    <select name="acf_group">
                        <?php
                        foreach ($field_group_keys as $key => $name) {
                            $selected = selected($current_group, $key, false);
                            echo '<option value="' . esc_attr($key) . '" ' . $selected . '>' . esc_html($name) . '</option>';
                        }
                        ?>
                    </select>
                </label>
                <button type="submit" class="button button-primary">表示</button>
            </form>
        </div>

        <div class="acf-control-panel">
            <h3>雛型から新規作成</h3>
            <form method="POST" action="" class="acf-control-form">
                <input type="hidden" name="acf_action" value="copy_template">
                <label>使用する雛型:
                    <select name="template_id">
                        <?php
                        foreach ($template_post_ids as $id => $name) {
                            echo '<option value="' . esc_attr($id) . '">' . esc_html($name) . '</option>';
                        }
                        ?>
                    </select>
                </label>
                <label>初期表示する項目:
                    <select name="target_group">
                        <?php
                        foreach ($field_group_keys as $key => $name) {
                            echo '<option value="' . esc_attr($key) . '">' . esc_html($name) . '</option>';
                        }
                        ?>
                    </select>
                </label>
                <button type="submit" class="button" onclick="return confirm('選択した雛型を複製して新しい下書きを作成しますか？');">複製して作成</button>
            </form>
        </div>
    </div>

    <div class="acf-editor-layout">
        <div class="acf-editor-main">
            <?php
            if ($current_post_id) {
                $post_status = get_post_status($current_post_id);
                $view_link = ($post_status === 'publish') ? get_permalink($current_post_id) : get_preview_post_link($current_post_id);
                $link_text = ($post_status === 'publish') ? '公開済みページを確認' : 'プレビューを確認';

                echo '<div class="acf-editor-header">';
                echo '<h2>編集中: ' . esc_html(get_the_title($current_post_id)) . ' (ID: ' . esc_html($current_post_id) . ')</h2>';
                echo '<a href="' . esc_url($view_link) . '" target="_blank" rel="noopener noreferrer" class="acf-view-link">' . esc_html($link_text) . '</a>';
                echo '</div>';

                if ($current_group) {
                    acf_form([
                        'post_id'      => $current_post_id,
                        'field_groups' => [$current_group],
                        'post_title'   => true,
                        'html_submit_button'  => '
                            <input type="hidden" name="custom_post_status" id="custom_post_status" value="">
                            <input type="submit" class="acf-button button button-primary button-large" value="変更を保存" style="display:none;">
                            <button type="button" class="acf-btn-draft" onclick="document.getElementById(\'custom_post_status\').value=\'draft\'; this.form.submit();">下書き保存</button>
                            <button type="button" class="acf-btn-publish" onclick="document.getElementById(\'custom_post_status\').value=\'publish\'; this.form.submit();">公開 / 更新</button>
                        ',
                        'return'       => add_query_arg('updated', 'true', acf_get_current_url()),
                    ]);
                } else {
                    echo '<p>編集するフィールドグループを選択してください。</p>';
                }
            } else {
                echo '<p>上のパネルから、編集したい投稿を選択するか、雛型から新規作成してください。</p>';
            }
            ?>
        </div>

        <div class="acf-editor-sidebar">
            <h3>JSON出力</h3>
            <textarea id="acf_json_output" readonly></textarea>
        </div>
    </div>

</div>

<?php get_footer(); ?>