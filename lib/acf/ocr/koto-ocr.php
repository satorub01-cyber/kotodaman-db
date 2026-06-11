<?php
if (!defined('ABSPATH')) exit;

$koto_ocr_base = __DIR__;
require_once $koto_ocr_base . '/config.php';
require_once $koto_ocr_base . '/backend-interface.php';
require_once $koto_ocr_base . '/backends/openrouter-vlm.php';
require_once $koto_ocr_base . '/screen-classifier.php';
require_once $koto_ocr_base . '/ocr-normalizer.php';
require_once $koto_ocr_base . '/field-extractor.php';
require_once $koto_ocr_base . '/structure/spec-fragments.php';
require_once $koto_ocr_base . '/draft-spec-builder.php';
require_once $koto_ocr_base . '/spec-json-acf-adapter.php';
require_once $koto_ocr_base . '/draft-persister.php';
require_once $koto_ocr_base . '/admin.php';

function koto_ocr_run_pipeline(array $images, Koto_Ocr_Backend_Interface $backend)
{
    $payload = $backend->recognize($images);
    if (is_wp_error($payload)) {
        return $payload;
    }
    $normalized = koto_ocr_normalize_payload($payload, count($images));
    if (is_wp_error($normalized)) {
        return $normalized;
    }
    $extracted = koto_ocr_extract_fields($normalized);
    $fragment = koto_ocr_build_spec_fragments($extracted);
    $draft = koto_ocr_build_draft_spec($normalized, $extracted, $fragment);
    $post_id = koto_ocr_create_draft($draft, $normalized, $extracted, $backend);
    if (is_wp_error($post_id)) {
        return $post_id;
    }
    return [
        'post_id' => $post_id,
        'draft' => $draft,
        'normalized' => $normalized,
        'extracted' => $extracted,
    ];
}
