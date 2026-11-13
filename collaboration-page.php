<?php
/**
 * Template Name: 編集協力・AI連携ポータル (collaboration-page)
 * Description: コトダマンDB 編集協力案内・協力者アカウント登録・外部AI連携ガイド専用固定ページテンプレート
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
?>

<div class="koto-collab-page-shell">
    <?php
    while (have_posts()) :
        the_post();
        $page_title = trim((string) get_the_title());
        if (function_exists('koto_mcp_render_collaboration_portal')) {
            echo koto_mcp_render_collaboration_portal([
                'is_template' => true,
                'page_title'  => $page_title !== '' ? $page_title : 'コトダマンDB 編集協力・AI連携ポータル',
            ]);
        }
    endwhile;
    ?>
</div>

<?php
if (function_exists('cocoon_template_part') && function_exists('is_page_breadcrumbs_position_main_bottom') && is_page_breadcrumbs_position_main_bottom()) {
    cocoon_template_part('tmp/breadcrumbs-page');
}

get_footer();
