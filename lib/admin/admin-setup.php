<?php
/**
 * 管理画面設定 & ユーザー権限管理
 *
 * 管理画面のロール権限、UI調整用アセット（CSS/JS）、ユーザー一覧カラム拡張、管理画面テーマ変更などを定義します。
 */

if (!defined('ABSPATH')) exit;

/**
 * 1. 管理画面のモバイル用ビューポート設定
 *
 * @return void
 */
add_action('admin_head', function () {
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, user-scalable=yes">';
}, 1);

/**
 * 2. 管理画面用アセット（CSS / JS）の読み込み
 *
 * @param string $hook 現在の管理画面ページフック
 * @return void
 */
add_action('admin_enqueue_scripts', function ($hook) {
    $theme_dir = get_stylesheet_directory();
    $theme_uri = get_stylesheet_directory_uri();

    // 管理画面UI調整・スマホ対応CSS
    $css_path = $theme_dir . '/lib/admin/admin-tweaks.css';
    if (file_exists($css_path)) {
        wp_enqueue_style(
            'koto-admin-tweaks-style',
            $theme_uri . '/lib/admin/admin-tweaks.css',
            [],
            filemtime($css_path)
        );
    }

    // ACF行複製・ショートカット操作JS
    $js_path = $theme_dir . '/lib/admin/admin-tweaks.js';
    if (file_exists($js_path)) {
        wp_enqueue_script(
            'koto-admin-tweaks-script',
            $theme_uri . '/lib/admin/admin-tweaks.js',
            ['jquery'],
            filemtime($js_path),
            true
        );
    }

    // 親テーマ/カスタムAJAXスクリプトの登録・読み込み
    wp_enqueue_script('custom-ajax-script', get_template_directory_uri() . '/js/custom-ajax.js', ['jquery'], null, true);

    // ローカル環境かどうかの判定
    $is_local = (wp_get_environment_type() === 'local') || (isset($_SERVER['HTTP_HOST']) && str_contains($_SERVER['HTTP_HOST'], 'local'));

    wp_localize_script('custom-ajax-script', 'AppDebugConfig', [
        'isLocal' => $is_local,
        'ajaxUrl' => admin_url('admin-ajax.php'),
    ]);
});

/**
 * 3. 寄稿者（contributor）ロールへの権限付与
 *
 * 記事編集、公開済み記事編集、タクソノミー管理、画像アップロード権限を追加します。
 *
 * @return void
 */
function add_extended_caps_to_contributor()
{
    $role = get_role('contributor');
    if (!$role) {
        return;
    }

    $capabilities_to_add = [
        'manage_categories',    // タクソノミー（カテゴリー・タグ）の追加・管理
        'edit_published_posts', // 公開済みの自分の記事を編集（更新）する権限
        'edit_posts',           // 下書き・レビュー待ちの自分の記事を編集する権限
        'upload_files',         // 画像アップロード権限
    ];

    foreach ($capabilities_to_add as $cap) {
        if (!$role->has_cap($cap)) {
            $role->add_cap($cap);
        }
    }
}
add_action('init', 'add_extended_caps_to_contributor');

/**
 * 4. 特定タクソノミー（event, affiliation, suitable_quest）の権限設定を上書き
 *
 * @param array $args タクソノミー登録引数
 * @param string $taxonomy タクソノミースラッグ
 * @return array 変更されたタクソノミー登録引数
 */
function override_event_affiliation_caps($args, $taxonomy)
{
    $target_taxonomies = ['event', 'affiliation', 'suitable_quest'];

    if (in_array($taxonomy, $target_taxonomies, true)) {
        $cap_suffix = 'custom_event_aff_terms';

        $args['capabilities'] = [
            'manage_terms' => 'manage_' . $cap_suffix,
            'edit_terms'   => 'edit_' . $cap_suffix,
            'delete_terms' => 'delete_' . $cap_suffix,
            'assign_terms' => 'assign_' . $cap_suffix,
        ];
    }
    return $args;
}
add_filter('register_taxonomy_args', 'override_event_affiliation_caps', 20, 2);

/**
 * 5. 対象ロールへのカスタムタクソノミー権限の配布
 *
 * 管理者、編集者、投稿者にカスタム権限を付与し、削除権限を適切に制御します。
 *
 * @return void
 */
function grant_custom_caps_to_roles()
{
    $roles_to_modify = ['administrator', 'editor', 'author'];
    $cap_suffix = 'custom_event_aff_terms';

    foreach ($roles_to_modify as $role_slug) {
        $role = get_role($role_slug);
        if ($role) {
            $role->add_cap('manage_' . $cap_suffix);
            $role->add_cap('edit_' . $cap_suffix);
            $role->add_cap('assign_' . $cap_suffix);

            if ($role_slug === 'administrator' || $role_slug === 'editor') {
                $role->add_cap('delete_' . $cap_suffix);
            } else {
                $role->remove_cap('manage_categories');
            }
        }
    }
}
add_action('admin_init', 'grant_custom_caps_to_roles');

/**
 * 6. ユーザー一覧テーブルにユーザーID列を追加
 *
 * @param array $columns ユーザー一覧テーブルのカラム配列
 * @return array ID列が追加されたカラム配列
 */
add_filter('manage_users_columns', function ($columns) {
    $columns['user_id'] = 'ID';
    return $columns;
});

/**
 * 7. 管理画面のダッシュボード上部（admin_notices）にランダムカラー変更ボタンを設置
 *
 * @return void
 */
function add_random_color_theme_button()
{
    $ajax_url = wp_nonce_url(add_query_arg('action', 'set_random_admin_color'), 'random_color_nonce');

    echo '<div class="notice notice-info is-dismissible" style="margin-top: 15px;">';
    echo '<p>🎨 <a href="' . esc_url($ajax_url) . '" class="button button-primary">管理者の管理画面のテーマ色をランダムに変える</a></p>';
    echo '</div>';
}
add_action('admin_notices', 'add_random_color_theme_button');

/**
 * 8. ランダムカラーテーマ変更ボタン押下時の処理
 *
 * @return void
 */
function handle_random_admin_color_change()
{
    if (isset($_GET['action']) && $_GET['action'] === 'set_random_admin_color') {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'random_color_nonce')) {
            wp_die('セキュリティエラーが発生しました。');
        }

        $schemes = ['fresh', 'light', 'blue', 'coffee', 'ectoplasm', 'midnight', 'ocean', 'sunrise'];
        $user_id = get_current_user_id();

        $random_scheme = $schemes[array_rand($schemes)];
        update_user_meta($user_id, 'admin_color', $random_scheme);
        $random_scheme = $schemes[array_rand($schemes)];
        update_user_meta(1, 'admin_color', $random_scheme);

        $redirect_url = remove_query_arg(['action', '_wpnonce']);
        wp_safe_redirect($redirect_url);
        exit;
    }
}
add_action('admin_init', 'handle_random_admin_color_change');
