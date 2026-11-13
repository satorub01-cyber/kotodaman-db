<?php
/**
 * Cocoon Child Theme - functions.php
 *
 * コトダマンDB子テーマのメインブートストラップファイル。
 * 各機能モジュールの読み込みと、テーマ共通のコアフック・設定を行います。
 */

if (!defined('ABSPATH')) exit;

// 子テーマ用のビジュアルエディタースタイルを適用
add_editor_style();

// =================================================================
// 1. 機能モジュールの読み込み（依存関係順）
// =================================================================
$theme_dir = get_stylesheet_directory();

// 定義・マスタ変数
require_once $theme_dir . '/lib/koto-variables.php';

// コトダマン共通ヘルパー関数（表示・タクソノミー・偏差値計算）
require_once $theme_dir . '/lib/koto-helpers.php';

// キャラクター検索モジュール
require_once $theme_dir . '/lib/character-search/koto-search.php';
require_once $theme_dir . '/lib/character-search/chara-list-functions.php';
require_once $theme_dir . '/lib/character-search/koto-json-reformer.php';
require_once $theme_dir . '/lib/character-search/koto-taxonomy-sync.php';

// キャラクター詳細表示・モーダル表示
require_once $theme_dir . '/lib/koto-modal-displayer.php';
require_once $theme_dir . '/lib/koto-display.php';

// 計算ロジック・スペック生成
require_once $theme_dir . '/lib/koto-calc.php';

// エディタ拡張・ACF
require_once $theme_dir . '/editor.php';
require_once $theme_dir . '/lib/acf/acf-editor.php';

// MCPサーバー・OAuth 2.1・REST・協力者ポータル
require_once $theme_dir . '/lib/mcp/loader.php';

// 各種専用機能
require_once $theme_dir . '/lib/missing-info-characters.php';
require_once $theme_dir . '/lib/term-setter/term-setter-ajax.php';
require_once $theme_dir . '/lib/image-setter/image-setter-ajax.php';
require_once $theme_dir . '/lib/media-functions.php';

// 管理画面設定・ユーザー権限管理
require_once $theme_dir . '/lib/admin/admin-setup.php';

// 開発・メンテナンス用デバッグツール
require_once $theme_dir . '/lib/debug-tools.php';

// =================================================================
// 2. テーマ共通アセット読み込み (CSS)
// =================================================================
add_action('wp_enqueue_scripts', function () {
    $theme_dir = get_stylesheet_directory();
    $theme_uri = get_stylesheet_directory_uri();

    // --- A. キャラクター詳細ページ ---
    if (is_singular('character')) {
        $css_path = $theme_dir . '/style-character-detail.css';
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'koto-detail-style',
                $theme_uri . '/style-character-detail.css',
                [],
                filemtime($css_path)
            );
        }
    }
    // --- B. 検索結果ページ (キャラ検索の場合) ---
    elseif (is_search()) {
        if (get_query_var('post_type') === 'character' || (isset($_GET['post_type']) && $_GET['post_type'] === 'character')) {
            $css_path = $theme_dir . '/style-character-search.css';
            if (file_exists($css_path)) {
                wp_enqueue_style(
                    'koto-search-style',
                    $theme_uri . '/style-character-search.css',
                    [],
                    filemtime($css_path)
                );
            }
        }
    }
});

// =================================================================
// 3. 画像サイズ制御フィルター
// =================================================================
/**
 * 一覧やナビ等での切り取られていない「large」サイズへの強制変換処理
 *
 * @param array $attr 画像属性の配列
 * @param WP_Post $attachment アタッチメント投稿オブジェクト
 * @param string|array $size 要求された画像サイズ
 * @return array 変更された画像属性
 */
add_filter('wp_get_attachment_image_attributes', function ($attr, $attachment, $size) {
    if (is_admin()) {
        return $attr;
    }

    // フルサイズ（メイン画像）の時はそのまま
    if ($size === 'large') {
        return $attr;
    }

    $image_data = wp_get_attachment_image_src($attachment->ID, 'large');

    if ($image_data) {
        $attr['src'] = $image_data[0];

        if (isset($attr['srcset'])) {
            unset($attr['srcset']);
        }
    }

    return $attr;
}, 10, 3);

// =================================================================
// 4. 投稿スラッグ自動設定（投稿ID）
// =================================================================
/**
 * 投稿保存時にスラッグを自動で投稿IDに書き換える
 *
 * 対象投稿タイプ: character, monster, item
 *
 * @param int $post_id 投稿ID
 * @param WP_Post $post 投稿オブジェクト
 * @return void
 */
function auto_set_slug_to_id_multi($post_id, $post)
{
    $target_post_types = ['character', 'monster', 'item'];

    if (!$post || !in_array($post->post_type, $target_post_types, true)) {
        return;
    }

    if ($post->post_name == $post_id) {
        return;
    }

    remove_action('save_post', 'auto_set_slug_to_id_multi');

    wp_update_post([
        'ID'        => $post_id,
        'post_name' => $post_id,
    ]);

    add_action('save_post', 'auto_set_slug_to_id_multi', 10, 2);
}
add_action('save_post', 'auto_set_slug_to_id_multi', 10, 2);

// =================================================================
// 5. メタデータ定義
// =================================================================
/**
 * _spec_json メタデータをREST API露出から保護
 */
add_action('init', function () {
    register_meta('post', '_spec_json', [
        'object_subtype' => 'character',
        'show_in_rest'   => false,
        'single'         => true,
        'type'           => 'string',
    ]);
});

// =================================================================
// 6. フロントエンド UX / アクセシビリティ
// =================================================================
/**
 * フォーカスされていない状態でのTabキー移動をスムーズにするスクリプト
 */
add_action('wp_footer', function () {
?>
    <script>
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Tab' && (document.activeElement === document.body || !document.activeElement)) {
                var focusableElements = Array.from(document.querySelectorAll(
                    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
                ));

                var topElement = focusableElements.find(function(el) {
                    if (el.closest('.nojq') || el.closest('.interface-interface-skeleton-header') || el.closest('.header') || el.closest('#header')) {
                        return false;
                    }
                    var rect = el.getBoundingClientRect();
                    return rect.top > 0;
                });

                if (topElement) {
                    e.preventDefault();
                    topElement.focus();
                }
            }
        });
    </script>
<?php
});

// =================================================================
// 7. ACF JSON 保存・読み込み先設定 (本番ドメイン用)
// =================================================================
$current_domain = $_SERVER['HTTP_HOST'] ?? '';
if (str_ends_with($current_domain, 'kotodaman-db.com')) {
    add_filter('acf/settings/save_json', function ($path) {
        return get_stylesheet_directory() . '/acf-json';
    }, 20);

    add_filter('acf/settings/load_json', function ($paths) {
        return [get_stylesheet_directory() . '/acf-json'];
    }, 20);
}

// =================================================================
// 8. 外部プラグイン・広告表示制御 (Site Kit / Cocoon)
// =================================================================
/**
 * ユーザーが広告非表示権限を持つか判定する。
 * 管理者・編集者、または公開済み投稿を1件以上所有しているログインユーザーのみ true。
 *
 * @param int $user_id ユーザーID（省略時は現在ログイン中のユーザー）。
 * @return bool
 */
function koto_user_can_hide_ads($user_id = 0)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        if (!is_user_logged_in()) {
            return false;
        }
        $user_id = get_current_user_id();
    }
    if ($user_id <= 0) {
        return false;
    }

    $user = get_userdata($user_id);
    if (!$user instanceof WP_User) {
        return false;
    }

    if (user_can($user, 'manage_options') || user_can($user, 'edit_others_posts')) {
        return true;
    }

    $cached = get_user_meta($user_id, '_koto_has_published_post', true);
    if ($cached === '1' || $cached === 1) {
        return true;
    }
    if ($cached === '0' || $cached === 0) {
        return false;
    }

    global $wpdb;
    $has_published = (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d AND post_status = 'publish' AND post_type IN ('character', 'post') LIMIT 1",
        $user_id
    ));
    update_user_meta($user_id, '_koto_has_published_post', $has_published ? '1' : '0');
    return $has_published;
}

add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if (!$post instanceof WP_Post || (int) $post->post_author <= 0) {
        return;
    }
    if (!in_array($post->post_type, ['character', 'post'], true)) {
        return;
    }
    $author_id = (int) $post->post_author;
    if ($new_status === 'publish') {
        update_user_meta($author_id, '_koto_has_published_post', '1');
    } elseif ($old_status === 'publish' && $new_status !== 'publish') {
        delete_user_meta($author_id, '_koto_has_published_post');
    }
}, 10, 3);

add_action('deleted_post', function ($post_id, $post = null) {
    if (!$post instanceof WP_Post || (int) $post->post_author <= 0) {
        return;
    }
    if (!in_array($post->post_type, ['character', 'post'], true)) {
        return;
    }
    delete_user_meta((int) $post->post_author, '_koto_has_published_post');
}, 10, 2);

/**
 * Cocoon 広告表示判定のオーバーライド（公開済み投稿1件以上または管理者・編集者のみ非表示）。
 *
 * @return bool
 */
if (!function_exists('is_ads_visible')) {
    function is_ads_visible()
    {
        if (koto_user_can_hide_ads()) {
            return false;
        }
        $tmp_post_ids = function_exists('get_ad_exclude_post_ids') ? get_ad_exclude_post_ids() : '';
        $post_ids = explode(',', (string) $tmp_post_ids);
        $post_ids_empty = empty($tmp_post_ids);

        $category_ids = function_exists('get_ad_exclude_category_ids') ? get_ad_exclude_category_ids() : '';
        $category_ids_empty = empty($category_ids);

        $is_exclude_ids = (
            (!$post_ids_empty && is_single($post_ids))
            || (!$post_ids_empty && is_page($post_ids))
            || (!$category_ids_empty && is_single() && in_category($category_ids))
            || (!$category_ids_empty && is_category($category_ids))
        );

        return (function_exists('is_all_ads_visible') ? is_all_ads_visible() : true)
            && !$is_exclude_ids
            && (function_exists('is_the_page_ads_visible') ? is_the_page_ads_visible() : true)
            && !is_attachment()
            && !is_404()
            && !is_search();
    }
}

/**
 * ログイン中ユーザーに対してSite KitのGA4タグ出力を抑止
 */
add_filter('googlesitekit_analytics-4_tag_blocked', function ($is_blocked) {
    if (is_user_logged_in()) {
        return true;
    }
    return $is_blocked;
}, 100);

/**
 * Google Site Kit AdSense タグ出力の制御（公開済み投稿1件以上または管理者・編集者のみ非表示）
 */
add_filter('googlesitekit_adsense_tag_blocked', function ($is_blocked) {
    // //要チェック 本番の Google Site Kit 設定側で「ログイン中のユーザーを広告表示から除外」が有効な場合はオフにすること
    return koto_user_can_hide_ads();
}, 100);
