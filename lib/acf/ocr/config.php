<?php
if (!defined('ABSPATH')) exit;

function koto_ocr_env_truthy($name, $default = false)
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}

function koto_ocr_openrouter_api_key()
{
    if (defined('KOTO_OCR_OPENROUTER_API_KEY') && KOTO_OCR_OPENROUTER_API_KEY) {
        return (string) KOTO_OCR_OPENROUTER_API_KEY;
    }
    $key = getenv('OPENROUTER_API_KEY');
    return $key ? (string) $key : '';
}

function koto_ocr_openrouter_model()
{
    if (defined('KOTO_OCR_OPENROUTER_MODEL') && KOTO_OCR_OPENROUTER_MODEL) {
        return (string) KOTO_OCR_OPENROUTER_MODEL;
    }
    return 'google/gemini-3.1-flash-lite';
}

function koto_ocr_openrouter_timeout()
{
    if (defined('KOTO_OCR_OPENROUTER_TIMEOUT')) {
        return max(1, (int) KOTO_OCR_OPENROUTER_TIMEOUT);
    }
    return 180;
}

function koto_ocr_max_images()
{
    return 20;
}

function koto_ocr_max_image_bytes()
{
    if (defined('KOTO_OCR_MAX_IMAGE_BYTES')) {
        return max(1, (int) KOTO_OCR_MAX_IMAGE_BYTES);
    }
    $value = getenv('KOTO_OCR_MAX_IMAGE_BYTES');
    return $value ? max(1, (int) $value) : 2 * 1024 * 1024;
}

function koto_ocr_upload_target_bytes()
{
    $upload_limit = (int) wp_max_upload_size();
    return max((int) floor($upload_limit * 0.9), $upload_limit - 1024 * 1024);
}

function koto_ocr_allowed_mime_types()
{
    return ['image/png', 'image/jpeg', 'image/webp'];
}

function koto_ocr_allow_meta_fallback()
{
    if (defined('KOTO_OCR_ALLOW_META_FALLBACK')) {
        return (bool) KOTO_OCR_ALLOW_META_FALLBACK;
    }
    return koto_ocr_env_truthy('KOTO_OCR_ALLOW_META_FALLBACK', false);
}

function koto_ocr_debug_enabled()
{
    return defined('KOTO_OCR_DEBUG') && KOTO_OCR_DEBUG;
}
