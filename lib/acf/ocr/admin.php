<?php
if (!defined('ABSPATH')) exit;

add_action('admin_enqueue_scripts', 'koto_ocr_admin_enqueue_assets');
add_action('wp_ajax_koto_ocr_create_draft', 'koto_ocr_ajax_create_draft');

function koto_ocr_admin_enqueue_assets($hook)
{
    if ($hook !== 'toplevel_page_koto-acf-editor') {
        return;
    }
    $base_dir = __DIR__;
    $base_uri = get_stylesheet_directory_uri() . '/lib/acf/ocr';
    wp_enqueue_style('koto-ocr-draft', $base_uri . '/acf-ocr-draft.css', [], filemtime($base_dir . '/acf-ocr-draft.css'));
    wp_enqueue_script('koto-ocr-draft', $base_uri . '/acf-ocr-draft.js', [], filemtime($base_dir . '/acf-ocr-draft.js'), true);
    wp_add_inline_script('koto-ocr-draft', 'window.KOTO_OCR_DRAFT_CONFIG = ' . wp_json_encode(koto_ocr_public_config(), JSON_UNESCAPED_UNICODE) . ';', 'before');
}

function koto_ocr_public_config()
{
    return [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('koto_ocr_create_draft'),
        'action' => 'koto_ocr_create_draft',
        'hasApiKey' => koto_ocr_openrouter_api_key() !== '',
        'model' => koto_ocr_openrouter_model(),
        'maxImages' => koto_ocr_max_images(),
        'maxImageBytes' => koto_ocr_max_image_bytes(),
        'uploadTargetBytes' => koto_ocr_upload_target_bytes(),
        'allowedMimeTypes' => koto_ocr_allowed_mime_types(),
        'timeoutSeconds' => koto_ocr_openrouter_timeout(),
        'debug' => koto_ocr_debug_enabled(),
    ];
}

function koto_ocr_render_draft_panel()
{
    if (!current_user_can('edit_posts')) {
        return;
    }
    $has_key = koto_ocr_openrouter_api_key() !== '';
    ?>
    <div class="koto-ocr-panel" data-koto-ocr-panel>
        <button type="button" class="koto-ocr-panel__toggle" data-koto-ocr-toggle aria-expanded="false">
            OCRから新規下書きを作成
        </button>
        <div class="koto-ocr-panel__body" data-koto-ocr-body hidden>
            <?php if (!$has_key) : ?>
                <div class="notice notice-warning inline"><p>OpenRouter APIキーが未設定です。<code>KOTO_OCR_OPENROUTER_API_KEY</code> 定数または <code>OPENROUTER_API_KEY</code> 環境変数を設定してください。</p></div>
            <?php endif; ?>
            <p class="description">スクリーンショット画像を選択すると、OCR結果から <code>character</code> 下書きを新規作成します。画像自体は保存しません。</p>
            <label class="koto-ocr-dropzone" data-koto-ocr-dropzone>
                <span>画像を選択 / ドラッグ&ドロップ</span>
                <input type="file" data-koto-ocr-input accept="image/png,image/jpeg,image/webp" multiple>
            </label>
            <div class="koto-ocr-preview" data-koto-ocr-preview></div>
            <div class="koto-ocr-actions">
                <button type="button" class="button button-primary" data-koto-ocr-submit <?php disabled(!$has_key); ?>>OCR実行して下書きを作成</button>
                <span class="spinner" data-koto-ocr-spinner></span>
                <span class="koto-ocr-status" data-koto-ocr-status></span>
            </div>
            <div class="koto-ocr-result" data-koto-ocr-result></div>
        </div>
    </div>
    <?php
}

function koto_ocr_render_existing_draft_review($post_id)
{
    $post_id = (int) $post_id;
    if (!$post_id || get_post_meta($post_id, '_koto_ocr_draft', true) !== '1') {
        return;
    }

    $warnings = json_decode((string) get_post_meta($post_id, '_koto_ocr_warnings', true), true);
    $raw_text = json_decode((string) get_post_meta($post_id, '_koto_ocr_raw_text', true), true);
    $normalized = json_decode((string) get_post_meta($post_id, '_koto_ocr_normalized', true), true);
    ?>
    <div class="koto-ocr-review-panel">
        <h2>OCR下書き確認</h2>
        <p class="description">この投稿はOCRから作成された下書きです。公開前に、下のOCR raw textと警告を見ながらDBエディタで手修正してください。</p>
        <?php if (!empty($warnings) && is_array($warnings)) : ?>
            <div class="notice notice-warning inline">
                <ul>
                    <?php foreach ($warnings as $warning) : ?>
                        <li><strong><?php echo esc_html($warning['field'] ?? ''); ?></strong>: <?php echo esc_html($warning['message'] ?? ''); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (!empty($raw_text) && is_array($raw_text)) : ?>
            <div class="koto-ocr-review-panel__raw">
                <?php foreach ($raw_text as $item) : ?>
                    <details>
                        <summary><?php echo esc_html($item['source_image'] ?? 'image'); ?> OCR raw text</summary>
                        <pre><?php echo esc_html($item['text'] ?? ''); ?></pre>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (koto_ocr_debug_enabled() && !empty($normalized)) : ?>
            <details>
                <summary>normalized OCR JSON</summary>
                <pre><?php echo esc_html(wp_json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
            </details>
        <?php endif; ?>
    </div>
    <?php
}

function koto_ocr_ajax_create_draft()
{
    check_ajax_referer('koto_ocr_create_draft', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'OCR実行権限がありません。'], 403);
    }
    if (koto_ocr_openrouter_api_key() === '') {
        wp_send_json_error(['message' => 'OpenRouter APIキーが設定されていません。'], 400);
    }

    $images = koto_ocr_validate_uploaded_images($_FILES['images'] ?? null);
    if (is_wp_error($images)) {
        wp_send_json_error(['message' => $images->get_error_message()], 400);
    }

    $backend = new Koto_Ocr_Openrouter_Vlm(koto_ocr_openrouter_api_key(), koto_ocr_openrouter_model(), koto_ocr_openrouter_timeout());
    $result = koto_ocr_run_pipeline($images, $backend);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 400);
    }

    $post_id = (int) $result['post_id'];
    $links = [];
    if (current_user_can('edit_post', $post_id)) {
        $links['editPost'] = get_edit_post_link($post_id, 'raw');
        $links['dbEditor'] = admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post_id . '&acf_group=group_69204fa4dd82e');
    }

    wp_send_json_success([
        'postId' => $post_id,
        'title' => get_the_title($post_id),
        'links' => $links,
        'warnings' => $result['draft']['warnings'] ?? [],
        'rawText' => array_map(function ($image) {
            return ['source_image' => $image['source_image'] ?? '', 'text' => $image['fullText'] ?? ''];
        }, $result['normalized']['images'] ?? []),
        'debug' => koto_ocr_debug_enabled() ? $result : null,
    ]);
}

function koto_ocr_validate_uploaded_images($files)
{
    if (!is_array($files) || empty($files['tmp_name'])) {
        return new WP_Error('koto_ocr_no_files', '画像ファイルが送信されていません。');
    }

    $tmp_names = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
    $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];

    if (count($tmp_names) > koto_ocr_max_images()) {
        return new WP_Error('koto_ocr_too_many_files', '画像は一度に' . koto_ocr_max_images() . '枚までです。');
    }

    $allowed = koto_ocr_allowed_mime_types();
    $validated = [];
    foreach ($tmp_names as $index => $tmp_name) {
        if (($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new WP_Error('koto_ocr_upload_error', '画像アップロードに失敗しました。');
        }
        if (!is_uploaded_file($tmp_name) || !is_readable($tmp_name)) {
            return new WP_Error('koto_ocr_unreadable_file', 'アップロード画像を読み取れません。');
        }
        if ((int) ($sizes[$index] ?? 0) > koto_ocr_max_image_bytes()) {
            return new WP_Error('koto_ocr_file_too_large', '画像が1ファイル上限を超えています。ブラウザ側の自動縮小に失敗した可能性があります。');
        }

        $check = wp_check_filetype_and_ext($tmp_name, $names[$index] ?? 'image');
        $mime = $check['type'] ?? '';
        if (!$mime && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $tmp_name) : '';
            if ($finfo) finfo_close($finfo);
        }
        if (!in_array($mime, $allowed, true) || @getimagesize($tmp_name) === false) {
            return new WP_Error('koto_ocr_invalid_mime', '対応していない画像形式です。PNG/JPEG/WebPのみ利用できます。');
        }

        $validated[] = [
            'source_image' => 'image_' . (count($validated) + 1),
            'mime_type' => $mime,
            'path' => $tmp_name,
        ];
    }
    return $validated;
}
