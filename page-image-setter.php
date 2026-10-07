<?php
/**
 * Template Name: キャラ画像設定
 * Description: コトダマンDB キャラクター画像設定専用ツール
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

// 権限チェック（投稿編集権限が必要）
if (!current_user_can('edit_posts')) {
    wp_die('このページにアクセスする権限がありません。');
}

// Ajax処理ファイルの読み込み
require_once get_stylesheet_directory() . '/lib/image-setter/image-setter-ajax.php';

// 固定ページ宛ての直接POSTにも対応
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'image_setter_save_image') {
        image_setter_handle_save_image();
        exit;
    } elseif ($_POST['action'] === 'image_setter_search_media') {
        image_setter_handle_search_media();
        exit;
    }
}

// =================================================================
// データ準備（メモリ最適化済み）
// =================================================================
global $wpdb;
$nonce = wp_create_nonce('image_setter_nonce');
$ajax_url = admin_url('admin-ajax.php');

// --- A. キャラクター一覧取得（SQLで直接絞り込み、メモリ消費を激減） ---
// 条件: アイキャッチ画像が「未設定」または「準備中画像（無題51_20260205005357）」
$prep_ids = $wpdb->get_col("
    SELECT ID FROM {$wpdb->posts}
    WHERE post_type = 'attachment'
      AND (guid LIKE '%無題51_20260205005357%' OR post_name LIKE '%無題51_20260205005357%')
");
$prep_sql = '';
if (!empty($prep_ids)) {
    $prep_clean = implode(',', array_map('intval', $prep_ids));
    $prep_sql = "OR m_thumb.meta_value IN ({$prep_clean})";
}

$char_sql = "
    SELECT p.ID, p.post_title, p.post_status,
           m_ruby.meta_value AS ruby,
           m_pre.meta_value AS pre_id,
           m_main.meta_value AS main_id,
           m_ano.meta_value AS ano_id
    FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->postmeta} m_thumb ON (p.ID = m_thumb.post_id AND m_thumb.meta_key = '_thumbnail_id')
    LEFT JOIN {$wpdb->postmeta} m_ruby  ON (p.ID = m_ruby.post_id AND m_ruby.meta_key = 'name_ruby')
    LEFT JOIN {$wpdb->postmeta} m_pre   ON (p.ID = m_pre.post_id AND m_pre.meta_key = 'pre_evo_image')
    LEFT JOIN {$wpdb->postmeta} m_main  ON (p.ID = m_main.post_id AND m_main.meta_key = 'character_image')
    LEFT JOIN {$wpdb->postmeta} m_ano   ON (p.ID = m_ano.post_id AND m_ano.meta_key = 'another_character_image')
    WHERE p.post_type = 'character'
      AND p.post_status IN ('publish', 'draft')
      AND p.ID NOT IN (3683)
      AND p.post_title NOT LIKE '%雛型%'
      AND p.post_title NOT LIKE '%雛形%'
      AND (
          m_thumb.meta_value IS NULL 
          OR m_thumb.meta_value = '' 
          OR m_thumb.meta_value = '0'
          {$prep_sql}
      )
    ORDER BY p.ID DESC
";
$pending_raw = $wpdb->get_results($char_sql);

$pending_characters = [];
foreach ($pending_raw as $c) {
    if ((int)$c->ID === 3683) {
        continue;
    }
    if (strpos($c->post_title, '雛型') !== false || strpos($c->post_title, '雛形') !== false) {
        continue;
    }

    $pending_characters[] = [
        'id'          => $c->ID,
        'title'       => $c->post_title,
        'ruby'        => $c->ruby ?: '',
        'status'      => $c->post_status,
        'has_pre'     => !empty($c->pre_id),
        'has_main'    => !empty($c->main_id),
        'has_another' => !empty($c->ano_id),
        'edit_url'    => admin_url('post.php?post=' . $c->ID . '&action=edit'),
    ];
}

// --- B. どの記事にも紐づいていない画像（初期60件を取得） ---
$initial_media_data = image_setter_get_unused_images('', 1, 60);
$unused_images = $initial_media_data['items'];
$has_more_media = $initial_media_data['has_more'];

// CSS 読み込み
$css_path = get_stylesheet_directory() . '/lib/image-setter/image-setter.css';
$css_ver = file_exists($css_path) ? filemtime($css_path) : '1.0';
wp_enqueue_style('koto-image-setter-style', get_stylesheet_directory_uri() . '/lib/image-setter/image-setter.css', [], $css_ver);

get_header();
?>

<div class="image-setter-wrap">

    <div class="setter-page-header">
        <div>
            <h1 class="setter-page-title">キャラ画像設定</h1>
            <p class="setter-page-desc">アイキャッチ画像が準備中・未設定のキャラを選択し、下の未使用画像から進化前・後・絵違いを割り当てます。</p>
        </div>
    </div>

    <!-- 添付画像の最上部通知バー: 「キャラ１の進化前/後に設定しました」 -->
    <div id="setter-toast-bar" class="setter-toast-bar" role="status">
        <span id="setter-toast-message"></span>
    </div>

    <!-- 上ブロック：キャラクター一覧 -->
    <div class="setter-panel" id="panel-chara">
        <div class="setter-panel-header">
            <div class="setter-panel-title-group">
                <h2 class="setter-panel-title">対象キャラクター</h2>
                <span class="setter-count-badge" id="chara-count">(<?php echo count($pending_characters); ?>件)</span>
                <span class="setter-current-char-pill" id="current-char-pill">
                    選択中: <strong id="current-char-name">未選択</strong>
                </span>
            </div>
            <div class="setter-search-box">
                <input type="text" id="chara-search-input" class="setter-search-input" placeholder="キャラ名で検索" autocomplete="off">
                <button type="button" class="setter-search-clear" id="chara-search-clear" title="クリア">×</button>
            </div>
        </div>

        <div class="chara-list-scroll">
            <?php if (empty($pending_characters)) : ?>
                <div class="setter-empty-msg">対象となるキャラクター（未設定・準備中）はありません。</div>
            <?php else : ?>
                <ul class="chara-list" id="chara-list">
                    <?php foreach ($pending_characters as $c) : ?>
                        <li class="chara-item"
                            data-id="<?php echo esc_attr($c['id']); ?>"
                            data-title="<?php echo esc_attr($c['title']); ?>"
                            data-ruby="<?php echo esc_attr($c['ruby']); ?>"
                            tabindex="0"
                            role="button"
                            aria-pressed="false">
                            <div class="chara-item-main">
                                <span class="chara-radio-indicator"></span>
                                <span class="chara-name"><?php echo esc_html($c['title']); ?></span>
                                <?php if (!empty($c['ruby'])) : ?>
                                    <span class="chara-ruby">（<?php echo esc_html($c['ruby']); ?>）</span>
                                <?php endif; ?>
                            </div>
                            <div class="chara-badges">
                                <span class="chara-badge badge-pre <?php echo $c['has_pre'] ? 'is-set' : ''; ?>" id="badge-pre-<?php echo $c['id']; ?>">
                                    進化前:<?php echo $c['has_pre'] ? '済' : '未'; ?>
                                </span>
                                <span class="chara-badge badge-main <?php echo $c['has_main'] ? 'is-set' : ''; ?>" id="badge-main-<?php echo $c['id']; ?>">
                                    進化後:<?php echo $c['has_main'] ? '済' : '未'; ?>
                                </span>
                                <span class="chara-badge badge-another <?php echo $c['has_another'] ? 'is-set' : ''; ?>" id="badge-ano-<?php echo $c['id']; ?>">
                                    絵違い:<?php echo $c['has_another'] ? '済' : '未'; ?>
                                </span>
                                <a href="<?php echo esc_url($c['edit_url']); ?>" target="_blank" class="chara-badge" onclick="event.stopPropagation();" title="WordPress編集画面を開く">編集 ↗</a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- 下ブロック：画像一覧 -->
    <div class="setter-panel" id="panel-media">
        <div class="setter-panel-header">
            <div class="setter-panel-title-group">
                <h2 class="setter-panel-title">未紐付け画像</h2>
                <span class="setter-count-badge" id="media-count">(<?php echo count($unused_images); ?>件<?php echo $has_more_media ? '+' : ''; ?>)</span>
                <span style="font-size: 13px; color: #777777;">※画像ホバーで「進化前」「進化後」「絵違い」ボタンが表示されます</span>
            </div>
            <div class="setter-search-box">
                <input type="text" id="media-search-input" class="setter-search-input" placeholder="画像名で検索" autocomplete="off">
                <button type="button" class="setter-search-clear" id="media-search-clear" title="クリア">×</button>
            </div>
        </div>

        <div class="media-grid-scroll" id="media-scroll-container">
            <ul class="media-grid" id="media-grid">
                <?php if (empty($unused_images)) : ?>
                    <li class="setter-empty-msg" id="media-empty-msg" style="grid-column: 1/-1;">未紐付けの画像はありません。</li>
                <?php else : ?>
                    <?php foreach ($unused_images as $img) : ?>
                        <li class="media-card"
                            data-id="<?php echo esc_attr($img['id']); ?>"
                            data-title="<?php echo esc_attr($img['title']); ?>"
                            data-filename="<?php echo esc_attr($img['filename']); ?>"
                            id="media-card-<?php echo esc_attr($img['id']); ?>">
                            
                            <div class="media-thumb-box">
                                <img src="<?php echo esc_url($img['thumb_url']); ?>" alt="<?php echo esc_attr($img['title']); ?>" loading="lazy">

                                <!-- ホバー時に現れる3つのボタン -->
                                <div class="media-actions-overlay">
                                    <button type="button" class="btn-image-action" data-type="pre_evo" data-id="<?php echo esc_attr($img['id']); ?>">進化前</button>
                                    <button type="button" class="btn-image-action" data-type="character" data-id="<?php echo esc_attr($img['id']); ?>">進化後</button>
                                    <button type="button" class="btn-image-action" data-type="another" data-id="<?php echo esc_attr($img['id']); ?>">絵違い</button>
                                </div>
                            </div>

                            <div class="media-title-box" title="<?php echo esc_attr($img['title']); ?> (<?php echo esc_attr($img['filename']); ?>)">
                                <?php echo esc_html($img['title']); ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>

            <!-- さらに読み込むボタン -->
            <div class="media-load-more-wrap" id="media-load-more-wrap" style="<?php echo $has_more_media ? '' : 'display:none;'; ?>">
                <button type="button" id="btn-media-load-more" class="btn-media-load-more">さらに画像を読み込む</button>
            </div>
        </div>
    </div>

</div>

<!-- JS設定渡し & スクリプト読み込み -->
<script>
    window.IMAGE_SETTER_CONFIG = {
        ajaxUrl: '<?php echo esc_url($ajax_url); ?>',
        nonce: '<?php echo esc_js($nonce); ?>'
    };
</script>

<?php
$js_path = get_stylesheet_directory() . '/lib/image-setter/image-setter.js';
$js_ver = file_exists($js_path) ? filemtime($js_path) : '1.0';
wp_enqueue_script('koto-image-setter-script', get_stylesheet_directory_uri() . '/lib/image-setter/image-setter.js', [], $js_ver, true);

get_footer();
