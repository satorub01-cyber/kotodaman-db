<?php
/**
 * Template Name: 編集協力・AI連携ポータル (collaboration-page)
 * Description: コトダマンDB 編集協力案内・協力者アカウント登録・外部AI連携ガイド専用固定ページテンプレート
 */

if (!defined('ABSPATH')) {
    exit;
}

// 【一時非公開メモ】MCPを使った協力者募集ページ（/collaborate/）を一時非公開化中。
// 処理自体は残し、このテンプレート経由で外部から閲覧できないよう404応答＋描画処理をコメントアウトしている。
// 再公開時は下の404応答ブロックをコメントアウトし、テンプレート描画処理のコメントアウトを解除すること。
// //要チェック WordPress管理画面の固定ページ（collaborate）自体の公開ステータスも「非公開」または「下書き」に設定すること
global $wp_query;
if (isset($wp_query) && $wp_query instanceof WP_Query) {
    $wp_query->set_404();
}
status_header(404);
nocache_headers();
$template_404 = function_exists('get_404_template') ? get_404_template() : '';
if ($template_404 !== '' && file_exists($template_404)) {
    include $template_404;
}
exit;

// 以下、再公開時にコメントアウト解除する元のテンプレート描画処理
// if (!function_exists('koto_mcp_render_collaboration_portal')) {
//     $mcp_collab_path = get_stylesheet_directory() . '/lib/mcp/portal/mcp-collaboration-page.php';
//     if (file_exists($mcp_collab_path)) {
//         require_once $mcp_collab_path;
//     }
// }
//
// if (function_exists('koto_mcp_collab_enqueue_assets')) {
//     koto_mcp_collab_enqueue_assets();
// }
//
// get_header();
//
// if (function_exists('cocoon_template_part') && function_exists('is_page_breadcrumbs_position_main_top') && is_page_breadcrumbs_position_main_top()) {
//     cocoon_template_part('tmp/breadcrumbs-page');
// }
//
// echo '<div class="koto-collab-page-shell">';
// while (have_posts()) :
//     the_post();
//     $page_title = trim((string) get_the_title());
//     if (function_exists('koto_mcp_render_collaboration_portal')) {
//         echo koto_mcp_render_collaboration_portal([
//             'is_template' => true,
//             'page_title'  => $page_title !== '' ? $page_title : 'コトダマンDB 編集協力・AI連携ポータル',
//         ]);
//     }
// endwhile;
// echo '</div>';
//
// if (function_exists('cocoon_template_part') && function_exists('is_page_breadcrumbs_position_main_bottom') && is_page_breadcrumbs_position_main_bottom()) {
//     cocoon_template_part('tmp/breadcrumbs-page');
// }
//
// get_footer();
