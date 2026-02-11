<?php
/*
Template Name: 汎用ターム一覧ページ（階層対応）
*/
get_header();

// 1. ACFの設定を取得
$target_tax = get_field('target_taxonomy');

if ( ! $target_tax || ! taxonomy_exists($target_tax) ) {
    echo '<div class="db-top-container"><p>タクソノミー設定エラー</p></div>';
    get_footer();
    exit;
}

$tax_obj = get_taxonomy($target_tax);
$tax_label = $tax_obj->label;

// 2. 全タームを取得して「辞書」形式に整理する
// Pythonでいう { parent_id : [term_obj, term_obj...] } の形を作ります
$terms = get_terms(array(
    'taxonomy'   => $target_tax,
    'hide_empty' => false,
));

$term_hierarchy = array();
foreach ($terms as $term) {
    // 親IDをキーにして配列に放り込む
    $term_hierarchy[$term->parent][] = $term;
}

// 整理が終わったので、表示用の関数（またはロジック）
?>

<div class="db-top-container">
    <div class="db-section-header">
        <h2><?php echo esc_html($tax_label); ?> 一覧</h2>
    </div>

    <div class="hierarchy-container">
        <?php
        // 親（parent = 0）が存在するかチェック
        if ( ! empty($term_hierarchy[0]) ) :
            foreach ( $term_hierarchy[0] as $parent_term ) :
        ?>
            <section class="parent-section">
                <h3 class="parent-title">
                    <a href="<?php echo get_term_link($parent_term); ?>">
                        <?php echo esc_html($parent_term->name); ?>
                    </a>
                </h3>

                <div class="child-grid">
                    <?php
                    // この親に属する子（Level 2）がいるかチェック
                    if ( isset($term_hierarchy[$parent_term->term_id]) ) :
                        foreach ( $term_hierarchy[$parent_term->term_id] as $child_term ) :
                    ?>
                        <div class="child-card">
                            <a href="<?php echo get_term_link($child_term); ?>" class="child-link">
                                <span class="child-name"><?php echo esc_html($child_term->name); ?></span>
                            </a>

                            <?php
                            // この子に属する孫（Level 3）がいるかチェック → アコーディオンへ
                            if ( isset($term_hierarchy[$child_term->term_id]) ) :
                            ?>
                                <details class="grandchild-accordion">
                                    <summary class="accordion-trigger">
                                        詳しく (<?php echo count($term_hierarchy[$child_term->term_id]); ?>)
                                    </summary>
                                    <div class="grandchild-list">
                                        <?php foreach ( $term_hierarchy[$child_term->term_id] as $grandchild_term ) : ?>
                                            <a href="<?php echo get_term_link($grandchild_term); ?>" class="grandchild-link">
                                                - <?php echo esc_html($grandchild_term->name); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </div>

                    <?php
                        endforeach; // 子ループ終了
                    else:
                    ?>
                        <p class="no-child">サブ項目はありません</p>
                    <?php endif; ?>
                </div>
            </section>
        <?php
            endforeach; // 親ループ終了
        else :
        ?>
            <p>タームが見つかりませんでした。</p>
        <?php endif; ?>
    </div>

    <div class="back-link">
        <a href="<?php echo home_url('/'); ?>">← トップへ戻る</a>
    </div>
</div>

<style>
/* レイアウト調整 */
.parent-section {
    margin-bottom: 50px;
    border-top: 2px solid #eee;
    padding-top: 30px;
}

.parent-title {
    font-size: 1.5rem;
    color: #333;
    margin-bottom: 20px;
    padding-left: 10px;
    border-left: 5px solid #444; /* 親のアクセントカラー */
}
.parent-title a {
    text-decoration: none;
    color: inherit;
}

/* 子要素のグリッド */
.child-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 20px;
}

/* 子カードのデザイン */
.child-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}

.child-link {
    display: block;
    font-weight: bold;
    font-size: 1.1em;
    text-decoration: none;
    color: #333;
    margin-bottom: 10px;
    text-align: center;
    padding-bottom: 10px;
    border-bottom: 1px solid #f0f0f0;
}
.child-link:hover {
    color: #0056b3;
}

/* 孫アコーディオン (details/summaryタグ) */
.grandchild-accordion {
    margin-top: 10px;
    font-size: 0.9em;
}

.accordion-trigger {
    cursor: pointer;
    color: #666;
    font-size: 0.85em;
    padding: 5px;
    background: #f9f9f9;
    border-radius: 4px;
    list-style: none; /* デフォルトの三角マーカーを消す場合 */
    text-align: center;
}
/* Chrome等で三角マーカーを消すおまじない */
.accordion-trigger::-webkit-details-marker {
    display: none; 
}
.accordion-trigger:after {
    content: " ▼";
    font-size: 0.8em;
}
details[open] .accordion-trigger:after {
    content: " ▲";
}

.grandchild-list {
    margin-top: 10px;
    display: flex;
    flex-direction: column;
    gap: 5px;
    background: #fafafa;
    padding: 10px;
    border-radius: 4px;
}

.grandchild-link {
    text-decoration: none;
    color: #555;
    font-size: 0.9em;
    transition: 0.2s;
}
.grandchild-link:hover {
    color: #d32f2f;
    padding-left: 5px;
}

.back-link {
    margin-top: 50px;
    text-align: center;
}
</style>

<?php get_footer(); ?>