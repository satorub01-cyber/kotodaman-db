<?php
/*
Template Name: ターム一括付与
*/

// 管理者権限チェック
// Author(投稿者)以上の権限チェック
if (!current_user_can('publish_posts')) {
    wp_die('このページにアクセスする権限がありません。from page-term-list.php');
}

// ACFフィールドから対象タクソノミーを取得
$target_taxonomy = get_field('target_taxonomy');
if (empty($target_taxonomy)) {
    echo '<div class="notice notice-error"><p>ACFフィールド「target_taxonomy」にタクソノミー名が設定されていません。</p></div>';
}

get_header();
?>

<div class="term-setter-container">
    <h1 class="term-setter-title">ターム一括付与</h1>
    
    <?php if ($target_taxonomy) : ?>
    <div class="term-setter-info">
        <p>対象タクソノミー: <strong><?php echo esc_html($target_taxonomy); ?></strong></p>
    </div>
    <?php endif; ?>
    
    <!-- ターム選択エリア -->
    <div class="term-setter-section">
        <h2>付与するタームを選択</h2>
        <div class="term-selector-wrapper">
            <?php 
            if ($target_taxonomy && taxonomy_exists($target_taxonomy)) {
                $terms = get_terms([
                    'taxonomy' => $target_taxonomy,
                    'hide_empty' => false,
                    'orderby' => 'name',
                    'order' => 'ASC',
                ]);
                if (!empty($terms) && !is_wp_error($terms)) {
                    // ターム検索入力
                    echo '<div class="term-search-box">';
                    echo '<input type="text" id="term-search-input" placeholder="ターム名で検索..." class="term-search-input">';
                    echo '<button type="button" id="btn-clear-term-search" class="button">クリア</button>';
                    echo '</div>';
                    
                    // 階層構造でタームを表示
                    echo '<div class="term-hierarchy" id="term-hierarchy-container">';
                    render_term_hierarchy_checkboxes($terms, 0, 0);
                    echo '</div>';
                } else {
                    echo '<p>タームが見つかりません。「新規ターム作成」からタームを作成してください。</p>';
                }
            } elseif ($target_taxonomy) {
                echo '<p>タクソノミー「' . esc_html($target_taxonomy) . '」が存在しません。</p>';
            } else {
                echo '<p>ACFフィールド「target_taxonomy」にタクソノミー名を設定してください。</p>';
            }
            
            /**
             * ターム階層を再帰的に描画（チェックボックス付き）
             */
            function render_term_hierarchy_checkboxes($terms, $parent_id = 0, $level = 0) {
                $children = array_filter($terms, function($term) use ($parent_id) {
                    return $term->parent == $parent_id;
                });
                
                if (empty($children)) {
                    return;
                }
                
                foreach ($children as $term) {
                    $has_children = false;
                    foreach ($terms as $t) {
                        if ($t->parent == $term->term_id) {
                            $has_children = true;
                            break;
                        }
                    }
                    
                    $prefix = $level > 0 ? '└ ' : '';
                    $indent_style = 'padding-left: ' . ($level * 16) . 'px;';
                    
                    if ($has_children) {
                        echo '<div class="term-hierarchy-item has-children" data-term-name="' . esc_attr(strtolower($term->name)) . '" style="' . esc_attr($indent_style) . '">';
                        echo '<span class="term-expand-btn" data-term-id="' . esc_attr($term->term_id) . '">▶</span>';
                        echo '<label class="term-hierarchy-label">';
                        echo '<input type="checkbox" name="selected_terms[]" value="' . esc_attr($term->term_id) . '" class="term-checkbox term-hierarchy-checkbox" data-term-name="' . esc_attr(strtolower($term->name)) . '">';
                        echo '<span>' . esc_html($term->name) . '</span>';
                        echo '</label>';
                        echo '</div>';
                        echo '<div class="term-hierarchy-children" data-parent-id="' . esc_attr($term->term_id) . '" style="display: none;">';
                        render_term_hierarchy_checkboxes($terms, $term->term_id, $level + 1);
                        echo '</div>';
                    } else {
                        $child_indent = $level > 0 ? 'padding-left: ' . (($level * 16) + 20) . 'px;' : $indent_style;
                        echo '<div class="term-hierarchy-item" data-term-name="' . esc_attr(strtolower($term->name)) . '" style="' . esc_attr($child_indent) . '">';
                        echo '<label class="term-hierarchy-label term-hierarchy-leaf">';
                        echo '<input type="checkbox" name="selected_terms[]" value="' . esc_attr($term->term_id) . '" class="term-checkbox term-hierarchy-checkbox" data-term-name="' . esc_attr(strtolower($term->name)) . '">';
                        echo '<span>' . esc_html($term->name) . '</span>';
                        echo '</label>';
                        echo '</div>';
                    }
                }
            }
            ?>
            <div id="selected-terms-display" class="selected-terms-display">
                <p>選択中のターム: <span id="selected-terms-list">なし</span></p>
            </div>
        </div>
    </div>
    
    <!-- ターム新規作成エリア -->
    <div class="term-setter-section">
        <h2>新規ターム作成</h2>
        <div class="term-creator-form">
            <input type="text" id="new-term-name" placeholder="ターム名" class="term-input">
            <input type="text" id="new-term-slug" placeholder="スラッグ（省略可）" class="term-input">
            <button type="button" id="btn-create-term" class="button">タームを作成</button>
        </div>
        <div class="parent-term-selector" id="parent-term-selector">
            <p class="parent-term-label">親ターム（省略可）:</p>
            <div class="parent-term-search-box">
                <input type="text" id="parent-term-search" placeholder="親タームを検索..." class="parent-term-search-input">
                <button type="button" id="btn-clear-parent-search" class="button">クリア</button>
                <button type="button" id="btn-parent-none" class="button">親なし</button>
            </div>
            <div id="parent-term-list" class="parent-term-list">
                <div class="loading-parent-terms">親ターム一覧を読み込み中...</div>
            </div>
            <input type="hidden" id="selected-parent-id" value="">
            <div id="selected-parent-display" class="selected-parent-display">選択中: <span>なし（トップレベル）</span></div>
        </div>
        <div id="term-create-result"></div>
    </div>
    
    <!-- キャラクター検索エリア -->
    <div class="term-setter-section">
        <h2>キャラクター検索</h2>
        <div class="character-search-form">
            <input type="text" id="char-search-input" placeholder="キャラクター名またはIDで検索..." class="search-input">
            <button type="button" id="btn-search-char" class="button">検索</button>
            <button type="button" id="btn-clear-search" class="button">クリア</button>
        </div>
        <div class="search-options">
            <label><input type="checkbox" id="search-by-id" value="1"> IDで検索</label>
        </div>
    </div>
    
    <!-- キャラクター一覧エリア -->
    <div class="term-setter-section">
        <div class="character-list-header">
            <h2>キャラクター一覧 <span id="char-count">(0件)</span></h2>
            <div class="list-actions">
                <button type="button" id="btn-select-all" class="button">全選択</button>
                <button type="button" id="btn-deselect-all" class="button">全解除</button>
                <span id="selected-count">選択中: 0件</span>
            </div>
        </div>
        
        <div id="character-grid" class="character-grid">
            <!-- JavaScriptで読み込み -->
            <div class="loading-message">読み込み中...</div>
        </div>
    </div>
    
    <!-- 実行エリア -->
    <div class="term-setter-section term-setter-actions">
        <button type="button" id="btn-apply-terms" class="button button-primary button-large" disabled>
            選択したタームを付与
        </button>
        <div id="apply-result"></div>
    </div>
</div>

<?php
// nonce生成
$nonce = wp_create_nonce('term_setter_nonce');
$ajax_url = admin_url('admin-ajax.php');
$json_url = function_exists('koto_json_generation_output_file_url') ? koto_json_generation_output_file_url('all_characters_search.json') : get_stylesheet_directory_uri() . '/lib/character-search/all_characters_search.json';

// CSS読み込み
$css_version = file_exists(get_stylesheet_directory() . '/lib/term-setter/term-setter.css') 
    ? filemtime(get_stylesheet_directory() . '/lib/term-setter/term-setter.css') 
    : '1.0';
wp_enqueue_style('term-setter', get_stylesheet_directory_uri() . '/lib/term-setter/term-setter.css', [], $css_version);

wp_enqueue_script('jquery');
?>

<script>
    const TERM_SETTER_CONFIG = {
        ajaxUrl: '<?php echo $ajax_url; ?>',
        nonce: '<?php echo $nonce; ?>',
        jsonUrl: '<?php echo $json_url; ?>',
        targetTaxonomy: '<?php echo $target_taxonomy ? esc_js($target_taxonomy) : ''; ?>',
    };
</script>

<?php
// JSファイル読み込み
$js_path = get_stylesheet_directory() . '/lib/term-setter/term-setter.js';
$version = file_exists($js_path) ? filemtime($js_path) : '1.0';
wp_enqueue_script('term-setter', get_stylesheet_directory_uri() . '/lib/term-setter/term-setter.js', ['jquery'], $version, true);

get_footer();
