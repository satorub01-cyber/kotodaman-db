<?php
// 管理画面にメニューを追加
add_action('admin_menu', 'koto_add_json_reform_page');
function koto_add_json_reform_page()
{
    add_submenu_page(
        'edit.php?post_type=character',
        '検索用JSON生成',
        '検索用JSON生成',
        'manage_options',
        'koto-json-reform',
        'koto_render_json_reform_page'
    );
}

// 管理画面のHTMLと処理ロジック
function koto_render_json_reform_page()
{
    $message = '';
    $json_file_path = get_stylesheet_directory() . '/all_characters_search.json';

    if (isset($_POST['generate_koto_json']) && check_admin_referer('koto_generate_json_action', 'koto_generate_json_nonce')) {

        $args = [
            'post_type'      => 'character',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ];
        $character_ids = get_posts($args);

        $flattened_data = [];

        foreach ($character_ids as $post_id) {
            $json_str = get_post_meta($post_id, '_spec_json', true);
            $spec = $json_str ? json_decode($json_str, true) : [];

            if (!is_array($spec) || empty($spec)) {
                continue;
            }

            $flat_char = [
                'id'           => $post_id,
                'name_ruby'    => $spec['name_ruby'] ?? get_the_title($post_id),
                'attribute'    => $spec['attribute'] ?? '',
                'species'      => $spec['species'] ?? '',
                'rarity'       => $spec['rarity'] ?? '',
                'release_date' => $spec['release_date'] ?? '',
                'cv'           => $spec['cv'] ?? '',
                'acq'          => $spec['acquisition'] ?? '',
                'hp99'         => (int) get_post_meta($post_id, '99_hp', true),
                'atk99'        => (int) get_post_meta($post_id, '99_atk', true),
                'hp120'        => (int) get_post_meta($post_id, '120_hp', true),
                'atk120'       => (int) get_post_meta($post_id, '120_atk', true),
                'ls_hp'        => (int) ($spec['max_ls_hp'] ?? 0),
                'ls_atk'       => (int) ($spec['max_ls_atk'] ?? 0),
                'power'        => (int) ($spec['firepower_index'] ?? 0),
                'is_estimate'  => !empty($spec['is_estimate']) ? 1 : 0,
            ];

            $flattened_data[] = $flat_char;
        }

        $json_output = json_encode($flattened_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($json_file_path, $json_output) !== false) {
            $message = '<div class="updated"><p>検索用JSONファイルを生成しました。（保存先: ' . esc_html($json_file_path) . '）</p></div>';
        } else {
            $message = '<div class="error"><p>JSONファイルの保存に失敗しました。ディレクトリの権限を確認してください。</p></div>';
        }
    }

    echo '<div class="wrap">';
    echo '<h1>検索用JSONファイル生成</h1>';
    echo $message;
    echo '<p>フロントエンドのJS検索で使用する静的JSONファイルを生成・更新します。キャラクターを追加・更新した後はこのボタンを押してください。</p>';

    echo '<form method="post" action="">';
    wp_nonce_field('koto_generate_json_action', 'koto_generate_json_nonce');
    echo '<p><input type="submit" name="generate_koto_json" class="button button-primary button-large" value="JSONを生成する"></p>';
    echo '</form>';
    echo '</div>';
}
