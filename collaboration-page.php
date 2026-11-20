<?php
/**
 * Template Name: 編集協力ポータル (collaboration-page)
 * Description: コトダマンDB 編集協力案内・チェッカーアカウント登録・チェック＆倍率調査ガイド専用固定ページテンプレート
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('koto_mcp_render_collaboration_portal')) {
    $mcp_collab_path = get_stylesheet_directory() . '/lib/mcp/portal/mcp-collaboration-page.php';
    if (file_exists($mcp_collab_path)) {
        require_once $mcp_collab_path;
    }
}

if (function_exists('koto_mcp_collab_enqueue_assets')) {
    koto_mcp_collab_enqueue_assets();
}

get_header();

if (function_exists('cocoon_template_part') && function_exists('is_page_breadcrumbs_position_main_top') && is_page_breadcrumbs_position_main_top()) {
    cocoon_template_part('tmp/breadcrumbs-page');
}

echo '<div class="koto-collab-page-shell">';
while (have_posts()) :
    the_post();
    $page_title = trim((string) get_the_title());
    if (function_exists('koto_mcp_render_collaboration_portal')) {
        echo koto_mcp_render_collaboration_portal([
            'is_template' => true,
            'page_title'  => $page_title !== '' ? $page_title : 'コトダマンDB 編集協力ポータル',
        ]);
    }
endwhile;
echo '</div>';

if (function_exists('cocoon_template_part') && function_exists('is_page_breadcrumbs_position_main_bottom') && is_page_breadcrumbs_position_main_bottom()) {
    cocoon_template_part('tmp/breadcrumbs-page');
}

get_footer();
