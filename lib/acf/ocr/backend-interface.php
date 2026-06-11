<?php
if (!defined('ABSPATH')) exit;

interface Koto_Ocr_Backend_Interface
{
    /**
     * @param array $images Each item: source_image, mime_type, path.
     * @return array|WP_Error Decoded OCR payload.
     */
    public function recognize(array $images);

    public function get_name();

    public function get_model();
}
