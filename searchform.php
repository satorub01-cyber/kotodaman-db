<script>
    const kotoColumnConfig = <?php echo wp_json_encode(koto_get_column_config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<?php
// lib/character-search/searchform-content.php を呼び出す
get_template_part('lib/character-search/searchform-content');
