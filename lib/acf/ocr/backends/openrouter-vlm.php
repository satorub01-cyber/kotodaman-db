<?php
if (!defined('ABSPATH')) exit;

class Koto_Ocr_Openrouter_Vlm implements Koto_Ocr_Backend_Interface
{
    private $api_key;
    private $model;
    private $timeout;

    public function __construct($api_key, $model, $timeout)
    {
        $this->api_key = (string) $api_key;
        $this->model = (string) $model;
        $this->timeout = (int) $timeout;
    }

    public function get_name()
    {
        return 'openrouter-vlm-structured';
    }

    public function get_model()
    {
        return $this->model;
    }

    public function recognize(array $images)
    {
        if ($this->api_key === '') {
            return new WP_Error('koto_ocr_no_api_key', 'OpenRouter APIキーが設定されていません。');
        }

        $content = [
            ['type' => 'text', 'text' => $this->build_prompt(count($images))],
        ];

        foreach ($images as $image) {
            $bytes = file_get_contents($image['path']);
            if ($bytes === false) {
                return new WP_Error('koto_ocr_file_read_failed', '画像ファイルを読み取れませんでした。');
            }
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:' . $image['mime_type'] . ';base64,' . base64_encode($bytes),
                ],
            ];
        }

        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => $this->timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => home_url('/'),
                'X-Title' => 'Kotodaman DB OCR Draft',
            ],
            'body' => wp_json_encode([
                'model' => $this->model,
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [[
                    'role' => 'user',
                    'content' => $content,
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status < 200 || $status >= 300) {
            $decoded_error = json_decode($body, true);
            $message = $decoded_error['error']['message'] ?? $decoded_error['message'] ?? mb_substr($body, 0, 300);
            return new WP_Error('koto_ocr_openrouter_error', 'OpenRouter APIエラー: HTTP ' . $status . ' ' . $message, ['status' => $status]);
        }

        $decoded = json_decode($body, true);
        $text = $decoded['choices'][0]['message']['content'] ?? '';
        if (!is_string($text) || trim($text) === '') {
            return new WP_Error('koto_ocr_empty_response', 'OpenRouter応答からOCR JSONを取得できませんでした。');
        }

        $payload = json_decode($text, true);
        if (!is_array($payload)) {
            return new WP_Error('koto_ocr_json_parse_failed', 'OCR JSONの解析に失敗しました: ' . mb_substr($text, 0, 300));
        }

        if (koto_ocr_debug_enabled()) {
            $payload['_debug_openrouter_response'] = $body;
        }

        return $payload;
    }

    private function build_prompt($image_count)
    {
        $source_rule = $image_count > 1
            ? 'トップレベルに images 配列を必ず置き、各要素の source_image は image_1, image_2 の順にしてください。'
            : '単一画像でも source_image は image_1 としてください。';

        return implode("\n", [
            'あなたは日本語ゲーム「コトダマン」のスクリーンショット専用OCRです。翻訳、要約、推測、ゲーム知識による補完は禁止です。',
            $source_rule,
            'DB field候補やspec_jsonは作らず、OCR結果だけをJSONで返してください。',
            '許可するscreen_type: main, waza, sugowaza, trait, blessing, leader, kotowaza, EX_skill, charge_skill, unknown。',
            '各画像は fullText と blocks を持ちます。blocksは text と region を含め、可能ならbbox/boxも含めてください。',
            '重要region例: main_name_text, main_waza_preview, modal_body, modal_trigger, trait_body, blessing_body, leader_body。',
            '小さいラベル、属性、種族、文字玉、発動条件を省略しないでください。倍率や数値は読めた文字だけを書き、推測しないでください。',
            '出力例: {"images":[{"source_image":"image_1","screen_type":"main","fullText":"...","blocks":[{"region":"main_name_text","text":"..."}]}]}',
        ]);
    }
}
