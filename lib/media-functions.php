<?php
add_action('restrict_manage_posts', 'add_unused_image_filter_dropdown');
// メディアライブラリのリスト表示に絞り込み用ドロップダウンを追加する関数
function add_unused_image_filter_dropdown()
{
    global $typenow;
    if ($typenow === 'attachment') {
        $selected = isset($_GET['acf_unused_filter']) ? $_GET['acf_unused_filter'] : '';
        echo '<select name="acf_unused_filter">';
        echo '<option value="">すべてのメディア</option>';
        echo '<option value="unused" ' . selected($selected, 'unused', false) . '>未使用</option>';
        echo '</select>';
    }
}

add_action('pre_get_posts', 'filter_unused_images_query');
// ドロップダウンで「指定ACF未使用」が選ばれた際にメインクエリを書き換える関数
function filter_unused_images_query($query)
{
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'attachment') {
        return;
    }

    if (isset($_GET['acf_unused_filter']) && $_GET['acf_unused_filter'] === 'unused') {
        global $wpdb;

        // 未使用画像の判定基準: character_image（進化後）/ another_character_image（絵違い）/ pre_evo_image（進化前）を参照
        // ※画像紐付けツール（lib/image-setter/image-setter-ajax.php）では _thumbnail_id も含め、準備中画像（無題51_20260205005357）を除外する
        $meta_keys = array('character_image', 'another_character_image', 'pre_evo_image');
        $keys_placeholder = implode("','", esc_sql($meta_keys));

        // 対象フィールドに値として登録されている画像IDをデータベースから直接取得
        $used_ids = $wpdb->get_col("
            SELECT CAST(meta_value AS UNSIGNED)
            FROM {$wpdb->postmeta}
            WHERE meta_key IN ('$keys_placeholder')
            AND meta_value REGEXP '^[0-9]+$'
        ");

        if (!empty($used_ids)) {
            // 取得した使用中の画像IDを一覧表示の対象から除外
            $query->set('post__not_in', $used_ids);
        }
    }
}
