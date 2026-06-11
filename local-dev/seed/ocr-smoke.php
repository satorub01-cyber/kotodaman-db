<?php
if (!defined('ABSPATH')) exit;

class Koto_Ocr_Smoke_Backend implements Koto_Ocr_Backend_Interface
{
    public function recognize(array $images)
    {
        return [
            'images' => [
                [
                    'source_image' => 'image_1',
                    'screen_type' => 'main',
                    'fullText' => "テストキャラ\n文字: あ・い",
                    'blocks' => [
                        ['region' => 'main_name_text', 'text' => 'テストキャラ'],
                    ],
                ],
                [
                    'source_image' => 'image_2',
                    'screen_type' => 'trait',
                    'fullText' => '①コピーガード\n②ビリビリガード',
                    'blocks' => [],
                ],
            ],
        ];
    }

    public function get_name()
    {
        return 'smoke-backend';
    }

    public function get_model()
    {
        return 'smoke-model';
    }
}

$result = koto_ocr_run_pipeline([
    ['source_image' => 'image_1', 'mime_type' => 'image/png', 'path' => __FILE__],
    ['source_image' => 'image_2', 'mime_type' => 'image/png', 'path' => __FILE__],
], new Koto_Ocr_Smoke_Backend());

if (is_wp_error($result)) {
    WP_CLI::error($result->get_error_message());
}

$post_id = (int) $result['post_id'];
$spec = json_decode((string) get_post_meta($post_id, '_spec_json', true), true);
$warnings = json_decode((string) get_post_meta($post_id, '_koto_ocr_warnings', true), true);

if (get_post_type($post_id) !== 'character' || get_post_status($post_id) !== 'draft') {
    WP_CLI::error('OCR draft post was not created correctly.');
}
if (($spec['name'] ?? '') !== 'テストキャラ') {
    WP_CLI::error('_spec_json.name was not saved.');
}
if (empty($spec['_ocr_placeholders']['trait1'][0]['text'])) {
    WP_CLI::error('trait raw text placeholder was not saved.');
}
if (!is_array($warnings) || empty($warnings)) {
    WP_CLI::error('_koto_ocr_warnings was not saved.');
}

wp_delete_post($post_id, true);
WP_CLI::success('OCR smoke test passed.');
