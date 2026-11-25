<?php
/**
 * コトダマンDB OGPモジュール - 管理画面 メディアサブページ
 *
 * メディア配下「サムネ生成 (OGP/YT)」ページ、キャラ検索Ajax(LIMIT 40)、キャッシュクリア機能を提供します。
 */

if (!defined('ABSPATH')) exit;

// =================================================================
// 7. 管理画面「メディア」サブページ（YouTube / 任意キャラサムネ生成UI）
// =================================================================

add_action('admin_menu', function () {
    add_media_page(
        'サムネイル画像生成 (OGP / YouTube)',
        'サムネ生成 (OGP/YT)',
        'edit_others_posts',
        'koto-ogp-generator',
        'koto_ogp_render_admin_page'
    );
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'media_page_koto-ogp-generator') {
        return;
    }
    $theme_dir = get_stylesheet_directory();
    $theme_uri = get_stylesheet_directory_uri();
    $css_path  = $theme_dir . '/lib/ogp/ogp-admin.css';
    $js_path   = $theme_dir . '/lib/ogp/ogp-admin.js';

    if (file_exists($css_path)) {
        wp_enqueue_style('koto-ogp-admin-style', $theme_uri . '/lib/ogp/ogp-admin.css', [], filemtime($css_path));
    }
    if (file_exists($js_path)) {
        wp_enqueue_script('koto-ogp-admin-script', $theme_uri . '/lib/ogp/ogp-admin.js', [], filemtime($js_path), true);
        wp_localize_script('koto-ogp-admin-script', 'KotoOgpAdmin', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'adminNonce'   => wp_create_nonce('koto_ogp_admin_nonce'),
            'previewNonce' => wp_create_nonce('koto_ogp_custom_render'),
            'homeUrl'      => home_url('/'),
            'hasTestJson'  => file_exists($theme_dir . '/lib/character-search/test_characters_search.json'),
        ]);
    }
});

/**
 * 管理画面用キャラクター検索 Ajax（256MBメモリ制限対応: SQL LIMIT 40 一括抽出）。
 */
add_action('wp_ajax_koto_ogp_search_characters', function () {
    check_ajax_referer('koto_ogp_admin_nonce', 'nonce');
    if (!current_user_can('edit_others_posts')) {
        wp_send_json_error(['message' => '権限がありません'], 403);
    }

    $raw_kw  = isset($_POST['keyword']) && is_scalar($_POST['keyword']) ? (string)$_POST['keyword'] : '';
    $keyword = mb_substr(sanitize_text_field(wp_unslash($raw_kw)), 0, 100, 'UTF-8');
    $raw_src = isset($_POST['source']) && is_scalar($_POST['source']) ? (string)$_POST['source'] : 'db';
    $source  = sanitize_key(wp_unslash($raw_src));
    $results = [];

    if ($source === 'test') {
        $all = koto_ogp_load_characters_from_json('test');
        $sorted = koto_ogp_sort_characters_for_thumbnail($all);
        unset($all);
        $norm_kw = koto_ogp_normalize_text($keyword);
        foreach ($sorted as $c) {
            $name = (string)($c['name'] ?? '');
            $ruby = (string)($c['name_ruby'] ?? '');
            if ($norm_kw !== '') {
                $hay = koto_ogp_normalize_text($name . ' ' . $ruby);
                if (mb_strpos($hay, $norm_kw, 0, 'UTF-8') === false) {
                    continue;
                }
            }
            $results[] = [
                'id'     => (int)($c['id'] ?? 0),
                'name'   => $name,
                'thumb'  => esc_url_raw((string)($c['thumb_url'] ?? '')),
                'rarity' => (string)($c['rar_d'] ?? '') !== 'none' && !empty($c['rar_d'])
                    ? (string)$c['rar_d']
                    : ('★' . (string)($c['rar'] ?? '')),
            ];
            if (count($results) >= 40) {
                break;
            }
        }
        wp_send_json_success(['items' => $results]);
    }

    global $wpdb;
    $protected_ids = function_exists('koto_mcp_template_post_ids')
        ? array_filter(array_map('intval', (array)koto_mcp_template_post_ids()))
        : [2947, 2023, 2637, 2638, 3845, 4603, 5294, 3683];
    if (empty($protected_ids)) {
        $protected_ids = [3683];
    }
    $protected_sql = implode(',', $protected_ids);

    $like_sql = '';
    $params = [
        '%' . $wpdb->esc_like('雛型') . '%',
        '%' . $wpdb->esc_like('雛形') . '%',
    ];
    if ($keyword !== '') {
        $like_sql = 'AND p.post_title LIKE %s';
        $params[] = '%' . $wpdb->esc_like($keyword) . '%';
    }

    $sql = "
        SELECT
            p.ID,
            p.post_title,
            f_thumb.meta_value AS thumb_file,
            f_char.meta_value AS char_file
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} m_thumb ON (p.ID = m_thumb.post_id AND m_thumb.meta_key = '_thumbnail_id')
        LEFT JOIN {$wpdb->postmeta} f_thumb ON (CAST(m_thumb.meta_value AS UNSIGNED) > 0 AND CAST(m_thumb.meta_value AS UNSIGNED) = f_thumb.post_id AND f_thumb.meta_key = '_wp_attached_file')
        LEFT JOIN {$wpdb->postmeta} m_char ON (p.ID = m_char.post_id AND m_char.meta_key = 'character_image')
        LEFT JOIN {$wpdb->postmeta} f_char ON (CAST(m_char.meta_value AS UNSIGNED) > 0 AND CAST(m_char.meta_value AS UNSIGNED) = f_char.post_id AND f_char.meta_key = '_wp_attached_file')
        WHERE p.post_type = 'character'
          AND p.post_status = 'publish'
          AND p.post_password = ''
          AND p.ID NOT IN ({$protected_sql})
          AND p.post_title NOT LIKE %s
          AND p.post_title NOT LIKE %s
          {$like_sql}
        ORDER BY p.ID DESC
        LIMIT 40
    ";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params));

    $uploads = wp_upload_dir();
    $baseurl = rtrim((string)($uploads['baseurl'] ?? ''), '/');
    foreach ((array)$rows as $row) {
        $rel = $row->thumb_file ?: $row->char_file;
        $raw_thumb = $rel ? ($baseurl . '/' . ltrim((string)$rel, '/')) : '';
        $results[] = [
            'id'     => (int)$row->ID,
            'name'   => (string)$row->post_title,
            'thumb'  => $raw_thumb !== '' ? esc_url_raw($raw_thumb) : '',
            'rarity' => 'DB',
        ];
    }

    wp_send_json_success(['items' => $results]);
});

/**
 * 管理画面からのカスタムサムネイルPNG生成 Ajax（同一オリジン・キャッシュ/404干渉回避）。
 */
add_action('wp_ajax_koto_ogp_render_custom', function () {
    if (!check_ajax_referer('koto_ogp_admin_nonce', 'nonce', false)) {
        koto_ogp_send_error_response(403, 'Invalid nonce');
    }
    if (!current_user_can('edit_others_posts')) {
        koto_ogp_send_error_response(403, 'Forbidden');
    }

    $raw = !empty($_POST) ? wp_unslash($_POST) : wp_unslash($_GET);
    $options = koto_ogp_parse_custom_options($raw);
    $rate_limited = false;
    $file = koto_ogp_generate_image_file($options, $rate_limited);
    if ($rate_limited) {
        koto_ogp_send_error_response(429, 'Too Many Requests');
    }
    if (!$file || !file_exists($file)) {
        koto_ogp_send_error_response(500, 'Failed to generate OGP image');
    }

    $is_tmp_file = str_starts_with(basename($file), 'tmp_');
    $download_filename = '';
    if (!empty($raw['download'])) {
        $download_filename = sprintf(
            'kotodaman-thumb-%dx%d.png',
            (int)($options['w'] ?? KOTO_OGP_DEFAULT_WIDTH),
            (int)($options['h'] ?? KOTO_OGP_DEFAULT_HEIGHT)
        );
    }
    koto_ogp_send_png_file_response($file, $is_tmp_file, true, $download_filename);
});

/**
 * 管理画面からのOGPキャッシュ一括クリア Ajax。
 */
add_action('wp_ajax_koto_ogp_clear_cache', function () {
    check_ajax_referer('koto_ogp_admin_nonce', 'nonce');
    if (!current_user_can('edit_others_posts')) {
        wp_send_json_error(['message' => '権限がありません'], 403);
    }
    $deleted = koto_ogp_purge_all_caches(true);
    wp_send_json_success(['deleted' => $deleted]);
});

/**
 * 管理画面メディアサブページのHTMLを描画する（インラインスタイル禁止）。
 */
function koto_ogp_render_admin_page()
{
    if (!current_user_can('edit_others_posts')) {
        wp_die('権限がありません。');
    }
    ?>
    <div class="wrap koto-ogp-admin-wrap">
        <h1 class="koto-ogp-page-title">サムネイル画像生成（X OGP / YouTubeサムネ）</h1>
        <p class="koto-ogp-lead">
            任意のキャラクター（最大6体）を選択して、X（1200×630）やYouTube（1280×720）向けのタイル・単体サムネイルを生成・ダウンロードできます。
        </p>

        <div class="koto-ogp-grid">
            <div class="koto-ogp-panel">
                <h2 class="koto-ogp-section-title">1. キャラクターを選択</h2>

                <div class="koto-ogp-control-row">
                    <label for="koto-ogp-source">取得元:</label>
                    <select id="koto-ogp-source" class="koto-ogp-select">
                        <option value="db">公開済みキャラクター (WordPress DB)</option>
                        <?php if (file_exists(get_stylesheet_directory() . '/lib/character-search/test_characters_search.json')) : ?>
                            <option value="test">テストデータ (test_characters_search.json)</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="koto-ogp-search-bar">
                    <input type="text" id="koto-ogp-search-input" class="regular-text" placeholder="キャラ名で絞り込み（例：カエン）">
                    <button type="button" id="koto-ogp-search-btn" class="button button-primary">検索</button>
                </div>

                <div id="koto-ogp-candidates" class="koto-ogp-candidates">
                    <p class="koto-ogp-muted">読み込み中...</p>
                </div>
            </div>

            <div class="koto-ogp-panel">
                <h2 class="koto-ogp-section-title">2. 選択中のキャラ・設定・プレビュー</h2>

                <div class="koto-ogp-selected-header">
                    <span>選択済み（<strong id="koto-ogp-selected-count">0</strong> / 6体）</span>
                    <button type="button" id="koto-ogp-clear-selected" class="button button-small">すべて解除</button>
                </div>
                <ul id="koto-ogp-selected-list" class="koto-ogp-selected-list">
                    <li class="koto-ogp-empty-note">左の一覧からキャラクターをクリックして追加してください。</li>
                </ul>

                <div class="koto-ogp-options-box">
                    <div class="koto-ogp-option-item">
                        <label for="koto-ogp-preset">出力サイズ</label>
                        <select id="koto-ogp-preset">
                            <option value="1200x630">X / OGP 標準 (1200 × 630)</option>
                            <option value="1280x720">YouTube サムネイル (1280 × 720)</option>
                        </select>
                    </div>
                    <div class="koto-ogp-option-item">
                        <label for="koto-ogp-bg-style">背景スタイル（1〜5体時）</label>
                        <select id="koto-ogp-bg-style">
                            <option value="blur">ソフトぼかし背景</option>
                            <option value="flat">フラット背景 (#f7ede3)</option>
                        </select>
                    </div>
                    <div class="koto-ogp-option-item koto-ogp-option-check">
                        <label>
                            <input type="checkbox" id="koto-ogp-show-logo" checked>
                            右下に DB ロゴを重ねる
                        </label>
                    </div>
                </div>

                <div class="koto-ogp-actions">
                    <button type="button" id="koto-ogp-generate-btn" class="button button-primary button-large" disabled>プレビュー生成</button>
                    <a id="koto-ogp-download-link" class="button button-secondary button-large is-disabled" href="#" download="kotodaman-thumb.png">PNGダウンロード</a>
                    <button type="button" id="koto-ogp-clear-cache-btn" class="button">OGPキャッシュ削除</button>
                </div>

                <div id="koto-ogp-status" class="koto-ogp-status"></div>

                <div class="koto-ogp-preview-frame">
                    <img id="koto-ogp-preview-img" class="koto-ogp-preview-img is-hidden" alt="サムネイルプレビュー">
                    <div id="koto-ogp-preview-placeholder" class="koto-ogp-preview-placeholder">
                        ここに生成されたサムネイルが表示されます
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
