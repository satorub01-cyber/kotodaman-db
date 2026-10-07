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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'image_setter_save_image') {
    image_setter_handle_save_image();
    exit;
}

// =================================================================
// データ準備
// =================================================================
$nonce = wp_create_nonce('image_setter_nonce');
$ajax_url = admin_url('admin-ajax.php');

// --- A. キャラクター一覧取得 ---
// 条件: アイキャッチ画像が「未設定」または「準備中画像（無題51_20260205005357）」
$char_posts = get_posts([
    'post_type'      => 'character',
    'posts_per_page' => -1,
    'post_status'    => ['publish', 'draft'],
    'orderby'        => 'ID',
    'order'          => 'DESC',
]);

$pending_characters = [];
$prep_target_filename = '無題51_20260205005357';

foreach ($char_posts as $cp) {
    $c_id = $cp->ID;
    $thumb_id = get_post_thumbnail_id($c_id);
    $is_target = false;

    if (!$thumb_id) {
        $is_target = true; // アイキャッチ未設定
    } else {
        $thumb_url = wp_get_attachment_url($thumb_id);
        if (!$thumb_url) {
            $is_target = true;
        } elseif (
            strpos($thumb_url, $prep_target_filename) !== false ||
            strpos(rawurldecode($thumb_url), $prep_target_filename) !== false
        ) {
            $is_target = true; // 準備中画像が設定されている
        }
    }

    if ($is_target) {
        $ruby = get_post_meta($c_id, 'name_ruby', true) ?: '';
        $pre_id = get_post_meta($c_id, 'pre_evo_image', true);
        $main_id = get_post_meta($c_id, 'character_image', true);
        $ano_id = get_post_meta($c_id, 'another_character_image', true);

        $pending_characters[] = [
            'id'          => $c_id,
            'title'       => $cp->post_title,
            'ruby'        => $ruby,
            'status'      => $cp->post_status,
            'has_pre'     => !empty($pre_id),
            'has_main'    => !empty($main_id),
            'has_another' => !empty($ano_id),
            'edit_url'    => admin_url('post.php?post=' . $c_id . '&action=edit'),
        ];
    }
}

// --- B. どの記事にも紐づいていない画像一覧取得 ---
// 参考: /var/www/html/wp-content/themes/cocoon-child-master/lib/media-functions.php
global $wpdb;
$meta_keys = ['character_image', 'another_character_image', 'pre_evo_image', '_thumbnail_id'];
$keys_placeholder = implode("','", array_map('esc_sql', $meta_keys));

$used_image_ids = $wpdb->get_col("
    SELECT DISTINCT CAST(meta_value AS UNSIGNED)
    FROM {$wpdb->postmeta}
    WHERE meta_key IN ('$keys_placeholder')
    AND meta_value REGEXP '^[0-9]+$'
");

// 準備中画像のIDもメディア一覧から除外
$prep_image_ids = $wpdb->get_col("
    SELECT ID FROM {$wpdb->posts}
    WHERE post_type = 'attachment'
    AND (guid LIKE '%無題51_20260205005357%' OR post_name LIKE '%無題51_20260205005357%')
");
if (!empty($prep_image_ids)) {
    $used_image_ids = array_merge($used_image_ids, $prep_image_ids);
}

$not_in_sql = '';
if (!empty($used_image_ids)) {
    $used_clean = implode(',', array_map('intval', array_unique($used_image_ids)));
    $not_in_sql = "AND ID NOT IN ({$used_clean})";
}

$raw_media = $wpdb->get_results("
    SELECT ID, post_title, post_name, guid, post_date
    FROM {$wpdb->posts}
    WHERE post_type = 'attachment'
    AND post_mime_type LIKE 'image/%'
    {$not_in_sql}
    ORDER BY ID DESC
");

$unused_images = [];
foreach ($raw_media as $m) {
    $full_src = wp_get_attachment_url($m->ID);
    $thumb_src = wp_get_attachment_image_url($m->ID, 'medium') ?: $full_src;
    $file_name = basename(get_attached_file($m->ID) ?: $full_src);

    $unused_images[] = [
        'id'        => $m->ID,
        'title'     => $m->post_title ?: $file_name,
        'filename'  => $file_name,
        'thumb_url' => $thumb_src,
        'full_url'  => $full_src,
    ];
}

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
                <span class="setter-count-badge" id="media-count">(<?php echo count($unused_images); ?>件)</span>
                <span style="font-size: 13px; color: #777777;">※画像ホバーで「進化前」「進化後」「絵違い」ボタンが表示されます</span>
            </div>
            <div class="setter-search-box">
                <input type="text" id="media-search-input" class="setter-search-input" placeholder="画像名で検索" autocomplete="off">
                <button type="button" class="setter-search-clear" id="media-search-clear" title="クリア">×</button>
            </div>
        </div>

        <div class="media-grid-scroll">
            <?php if (empty($unused_images)) : ?>
                <div class="setter-empty-msg" id="media-empty-msg">未紐付けの画像はありません。</div>
            <?php else : ?>
                <ul class="media-grid" id="media-grid">
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
                </ul>
            <?php endif; ?>
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
