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

    // ローカル環境かどうかの判定（WP環境タイプ、WP_DEBUG、サイトホスト等から総合判定）
    $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $is_local = (wp_get_environment_type() === 'local')
        || (defined('WP_DEBUG') && WP_DEBUG)
        || ($site_host !== '' && (
            $site_host === 'localhost'
            || $site_host === '127.0.0.1'
            || str_ends_with($site_host, '.local')
            || str_ends_with($site_host, '.test')
        ));

    $debug_config = [
        'isLocal' => $is_local,
        'ajaxUrl' => admin_url('admin-ajax.php'),
    ];

    wp_localize_script('custom-ajax-script', 'AppDebugConfig', $debug_config);
    if (wp_script_is('koto-admin-tweaks-script', 'enqueued') || wp_script_is('koto-admin-tweaks-script', 'registered')) {
        wp_localize_script('koto-admin-tweaks-script', 'AppDebugConfig', $debug_config);
    }
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
    $roles_to_modify = ['administrator', 'editor', 'author', 'checker'];
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
    if (!current_user_can('manage_options')) {
        return;
    }

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
        if (!current_user_can('manage_options')) {
            wp_die('この操作を行う権限がありません。', 'permission_denied', ['response' => 403]);
        }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])), 'random_color_nonce')) {
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

/**
 * 9. checker ロール定義・AI-assistant 投稿権限・チェック実績管理・標準編集画面制限
 */

/**
 * AI-assistant アカウントのユーザーIDを取得する。
 *
 * @return int ユーザーID（未存在時は 0）。
 */
function koto_get_ai_assistant_user_id()
{
    $user = get_user_by('login', 'AI-assistant');
    return ($user instanceof WP_User && $user->exists()) ? (int) $user->ID : 0;
}

/**
 * 指定ユーザー（省略時は現在のユーザー）が checker ロール相当か判定する。
 * 上位権限（administrator / editor 等）を併有するユーザーは対象外。
 *
 * @param int|WP_User|null $user ユーザーID、WP_User、または null（現在ユーザー）。
 * @return bool
 */
function koto_user_is_checker($user = null)
{
    if ($user === null) {
        $user = wp_get_current_user();
    } elseif (is_numeric($user)) {
        $user = get_userdata((int) $user);
    }

    if (!$user instanceof WP_User || !$user->exists()) {
        return false;
    }

    $roles = (array) $user->roles;
    if (!in_array('checker', $roles, true)) {
        return false;
    }

    if (array_intersect(['administrator', 'editor'], $roles) || !empty($user->allcaps['edit_others_posts'])) {
        return false;
    }

    return true;
}

/**
 * ユーザーがチェック完了（フラグ下げ）した投稿ID配列を取得する。
 *
 * @param int $user_id ユーザーID。
 * @return array<int, int>
 */
function koto_user_get_checked_post_ids($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return [];
    }

    $raw = get_user_meta($user_id, '_koto_checked_post_ids', true);
    if (!is_array($raw)) {
        return [];
    }

    $ids = [];
    foreach ($raw as $val) {
        $pid = (int) $val;
        if ($pid > 0) {
            $ids[$pid] = $pid;
        }
    }

    return array_values($ids);
}

/**
 * ユーザーのチェック実績件数を取得する。
 *
 * @param int $user_id ユーザーID。
 * @return int
 */
function koto_user_get_checked_count($user_id)
{
    return count(koto_user_get_checked_post_ids($user_id));
}

/**
 * ユーザーのチェック実績を記録する（1記事につき1回まで）。
 * ロールを問わず全ユーザーに対して記録する。
 *
 * @param int $user_id ユーザーID。
 * @param int $post_id 投稿ID。
 * @return bool 新規に1件追加された場合 true、重複・無効時は false。
 */
function koto_record_user_check_achievement($user_id, $post_id)
{
    $user_id = (int) $user_id;
    $post_id = (int) $post_id;
    if ($user_id <= 0 || $post_id <= 0 || !get_userdata($user_id)) {
        return false;
    }

    $ids = koto_user_get_checked_post_ids($user_id);
    if (in_array($post_id, $ids, true)) {
        return false;
    }

    $ids[] = $post_id;
    update_user_meta($user_id, '_koto_checked_post_ids', $ids);
    update_user_meta($user_id, '_koto_checked_count', count($ids));
    return true;
}

/**
 * checker ロールを登録・権限同期する。
 *
 * @return void
 */
function koto_register_checker_role()
{
    $caps = [
        'read'                          => true,
        'edit_posts'                    => true,
        'edit_published_posts'          => true,
        'publish_posts'                 => true,
        'delete_posts'                  => true,
        'delete_published_posts'        => true,
        'upload_files'                  => true,
        'manage_custom_event_aff_terms' => true,
        'edit_custom_event_aff_terms'   => true,
        'assign_custom_event_aff_terms' => true,
    ];

    $role = get_role('checker');
    if (!$role) {
        add_role('checker', 'チェッカー', $caps);
        return;
    }

    foreach ($caps as $cap => $grant) {
        if ($grant && !$role->has_cap($cap)) {
            $role->add_cap($cap);
        }
    }

    foreach (['edit_others_posts', 'delete_others_posts', 'delete_custom_event_aff_terms', 'manage_categories', 'manage_options'] as $deny_cap) {
        if ($role->has_cap($deny_cap)) {
            $role->remove_cap($deny_cap);
        }
    }
}
add_action('init', 'koto_register_checker_role', 5);

/**
 * checker に対して AI-assistant 作者の投稿の編集・公開を許可し、削除および他ユーザー投稿を制限する。
 */
add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
    if (!in_array($cap, ['edit_post', 'publish_post', 'read_post', 'delete_post'], true)) {
        return $caps;
    }

    $post_id = isset($args[0]) ? (int) $args[0] : 0;
    if ($post_id <= 0 || !koto_user_is_checker($user_id)) {
        return $caps;
    }

    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return $caps;
    }

    if ((int) $post->post_author === (int) $user_id) {
        return $caps;
    }

    $ai_id = koto_get_ai_assistant_user_id();
    if ($ai_id > 0 && (int) $post->post_author === $ai_id) {
        if ($cap === 'delete_post') {
            return ['do_not_allow'];
        }
        if ($post->post_status === 'private' || (function_exists('koto_mcp_is_protected_template_post') && koto_mcp_is_protected_template_post($post))) {
            return ['do_not_allow'];
        }
        return $cap === 'publish_post' ? ['publish_posts'] : ['edit_posts'];
    }

    if (in_array($cap, ['edit_post', 'publish_post', 'delete_post'], true)) {
        return ['do_not_allow'];
    }

    return $caps;
}, 20, 4);

/**
 * checker が AI-assistant 等の投稿を更新・公開しても post_author を元の作者のまま維持する。
 */
add_filter('wp_insert_post_data', function ($data, $postarr) {
    $post_id = !empty($postarr['ID']) ? (int) $postarr['ID'] : 0;
    if ($post_id <= 0 || !koto_user_is_checker()) {
        return $data;
    }

    $existing = get_post($post_id);
    if ($existing instanceof WP_Post && (int) $existing->post_author !== get_current_user_id()) {
        $data['post_author'] = (int) $existing->post_author;
    }

    return $data;
}, 20, 2);

/**
 * checker 向けの character 投稿編集リンクを DBエディタへ差し替える。
 */
add_filter('get_edit_post_link', function ($link, $post_id, $context) {
    if (!koto_user_is_checker()) {
        return $link;
    }

    $post = get_post($post_id);
    if (!$post instanceof WP_Post || $post->post_type !== 'character') {
        return $link;
    }

    if (!current_user_can('edit_post', $post->ID)) {
        return $link;
    }

    $editor_url = admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post->ID . '&acf_group=group_69204fa4dd82e');
    return $context === 'display' ? esc_url($editor_url) : $editor_url;
}, 20, 3);

/**
 * checker が character の標準編集画面（post.php / post-new.php）へアクセスした場合は DBエディタへリダイレクトする。
 */
add_action('load-post.php', function () {
    if (!koto_user_is_checker()) {
        return;
    }

    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if ($post_id > 0 && get_post_type($post_id) === 'character') {
        $editor_url = admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post_id . '&acf_group=group_69204fa4dd82e');
        wp_safe_redirect($editor_url);
        exit;
    }
});

add_action('load-post-new.php', function () {
    if (!koto_user_is_checker()) {
        return;
    }

    $post_type = isset($_GET['post_type']) ? sanitize_key((string) $_GET['post_type']) : 'post';
    if ($post_type === 'character') {
        wp_safe_redirect(admin_url('admin.php?page=koto-acf-editor'));
        exit;
    }
});

/**
 * checker に対してキャラクター標準「新規追加」サブメニューを非表示にする。
 */
add_action('admin_menu', function () {
    if (!koto_user_is_checker()) {
        return;
    }

    remove_submenu_page('edit.php?post_type=character', 'post-new.php?post_type=character');
}, 999);
