<?php
// =========================================================
// 1. 1キャラ分のデータを抽出する共通関数（★キー名の短縮などはここを編集）
// =========================================================
function koto_get_flat_char_data($post_id)
{
    $json_str = get_post_meta($post_id, '_spec_json', true);
    $spec = $json_str ? json_decode($json_str, true) : [];

    if (!is_array($spec) || empty($spec)) {
        return null;
    }
    // 画像URLの取得
    $thumb_url = get_the_post_thumbnail_url($post_id, 'thumbnail') ?? '';

    // ギミック名の抽出
    $gimmicks = [];
    $gimmick_slugs = [];
    $contents_trait1   = $spec['trait1']['contents'] ?? [];
    $contents_trait2   = $spec['trait2']['contents'] ?? [];
    $contents_blessing = $spec['blessing']['contents'] ?? [];

    $traits = array_merge($contents_trait1, $contents_trait2, $contents_blessing);
    if (!empty($traits)) {
        foreach ($traits as $t) {
            if ($t['type'] === 'gimmick' && !empty($t['sub_type'])) {
                $term = get_term_by('slug', $t['sub_type'], 'gimmick');
                if ($term) $gimmicks[] = $term->name;
                if ($term) $gimmick_slugs[] = $term->slug;
            }
        }
    }
    $attr_num = koto_get_attr_num();
    $species_num = koto_get_species_num();
    $sub_attributes = array_map(function ($item) use ($attr_num) {
        return $attr_num[$item] ?? 0;
    }, $spec['sub_attributes']);
    $groups = array_map(function ($item) {
        return $item['slug'] ?? '';
    }, $spec['groups'] ?? []);
    $unlock_map = [
        'default' => 'def',
        'first_trait' => '1',
        'second_trait' => '2',
        'blessing'    => 'bl',
    ];
    $charas = array_map(function ($item) use ($attr_num, $unlock_map) {
        return [
            'val' => $item['val'] ?? '',
            'attr' => $attr_num[$item['attr']] ?? 0,
            'unlock' => $unlock_map[$item['unlock']] ?? 'def',
        ];
    }, $spec['chars'] ?? []);

    // ★5. レアリティの検索用配列を高速生成 (例: ["6", "legend"])
    $rarity_slugs = [];
    if (!empty($spec['rarity'])) {
        $rarity_slugs[] = (string) $spec['rarity'];
    }
    if (!empty($spec['rarity_detail']) && $spec['rarity_detail'] !== 'none') {
        $rarity_slugs[] = $spec['rarity_detail'];
    }

    // 4. イベントのスラッグ配列
    $events = wp_get_post_terms($post_id, 'event', ['fields' => 'slugs']);

    // 6. スキルタグ文字列 (カンマ区切りなどを配列にするか、文字列のままか。検索を簡単にするため文字列のままにして `includes` で判定するのも手です)
    $waza_tags = get_post_meta($post_id, '_waza_tags_str', true) ?: '';
    $sugo_tags = get_post_meta($post_id, '_sugo_tags_str', true) ?: '';
    $koto_tags = get_post_meta($post_id, '_kotowaza_tags_str', true) ?: '';

    // 7. とくせいタグ文字列
    $t1_tags = get_post_meta($post_id, '_trait_tags_str_1', true) ?: '';
    $t2_tags = get_post_meta($post_id, '_trait_tags_str_2', true) ?: '';
    $blessing_tags = get_post_meta($post_id, '_trait_tags_str_blessing', true) ?: '';

    return [
        'id'           => $post_id,
        'thumb_url'    => $thumb_url,
        'name'         => $spec['name'],
        'pre_name'      => $spec['pre_evo_name'],
        'ano_name'      => $spec['another_image_name'],
        'name_ruby'    => $spec['name_ruby'],
        'chars'        => $charas,
        'attr'        => $attr_num[$spec['attribute']],
        'sub_attrs'     => $sub_attributes,
        'spe'          => $species_num[$spec['species']],
        'grp'          => $groups,
        'events'       => is_array($events) ? $events : [],
        'rar'          => $spec['rarity'],
        'rar_d'        => $spec['rarity_detail'],
        'rar_t'        => array_values(array_unique($rarity_slugs)),
        'date'         => $spec['release_date'],
        'cv'           => $spec['cv'],
        'acq'          => $spec['acquisition'],
        'hp99'         => $spec['_val_99_hp'],
        'atk99'        => $spec['_val_99_atk'],
        'hp120'        => $spec['_val_120_hp'],
        'atk120'       => $spec['_val_120_atk'],
        'hptal'        => $spec['talent_hp'],
        'atktal'       => $spec['talent_atk'],
        'hnd_buff'     => $spec['buff_counts_hand'],
        'bd_buff'     => $spec['buff_counts_board'],
        'debuf'           => $spec['debuff_counts'],
        'gimmicks'     => array_values(array_unique($gimmicks)),
        'gim_t'        => array_values(array_unique($gimmick_slugs)),
        'ls_hp'        => ($spec['max_ls_hp'] ?? 0),
        'ls_atk'       => ($spec['max_ls_atk'] ?? 0),
        'est'          => !empty($spec['is_estimate']) ? 1 : 0,
        'koto_est'     => !empty($spec['is_koto_estimate']) ? 1 : 0,
        // スキル/とくせいは文字列として保持しておく (例: " type_attack_single type_atk_buff ")
        'waza_t'       => $waza_tags,
        'sugo_t'       => $sugo_tags,
        'koto_t'       => $koto_tags,
        't1_t'         => $t1_tags,
        't2_t'         => $t2_tags,
        'bles_t'       => $blessing_tags,
    ];
}

// =========================================================
// 2. 全件を再生成する処理（手動ボタン用）
// =========================================================
function koto_generate_search_json_all()
{
    $args = [
        'post_type'      => 'character',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ];
    $character_ids = get_posts($args);
    $flattened_data = [];

    foreach ($character_ids as $post_id) {
        $flat_char = koto_get_flat_char_data($post_id);
        if ($flat_char) {
            $flattened_data[] = $flat_char;
        }
    }

    $json_file_path = get_stylesheet_directory() . '/lib/character-search/all_characters_search.json';
    $json_output = json_encode($flattened_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json_output) {
        file_put_contents($json_file_path, $json_output);
    }
}

// =========================================================
// 3. 単体データを上書き・追記する処理（自動更新用・超軽量）
// =========================================================
function koto_update_search_json_single($post_id)
{
    $json_file_path = get_stylesheet_directory() . '/lib/character-search/all_characters_search.json';
    $existing_data = [];

    // 既存のJSONを読み込む
    if (file_exists($json_file_path)) {
        $json_content = file_get_contents($json_file_path);
        if ($json_content) {
            $existing_data = json_decode($json_content, true);
            if (!is_array($existing_data)) $existing_data = [];
        }
    }

    // 最新の1キャラ分を取得
    $flat_char = koto_get_flat_char_data($post_id);
    if (!$flat_char) return;

    $updated = false;
    // 既存データの中に同じIDがあれば上書き
    foreach ($existing_data as $index => $char) {
        if ($char['id'] == $post_id) {
            $existing_data[$index] = $flat_char;
            $updated = true;
            break;
        }
    }
    // 無ければ新規追加
    if (!$updated) {
        $existing_data[] = $flat_char;
    }

    // 再保存
    file_put_contents($json_file_path, json_encode($existing_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// =========================================================
// 4. 単体データを削除する処理（下書き・ゴミ箱移動時用）
// =========================================================
function koto_delete_search_json_single($post_id)
{
    $json_file_path = get_stylesheet_directory() . '/lib/character-search/all_characters_search.json';
    if (!file_exists($json_file_path)) return;

    $json_content = file_get_contents($json_file_path);
    if (!$json_content) return;

    $existing_data = json_decode($json_content, true);
    if (!is_array($existing_data)) return;

    // 該当ID以外のキャラだけを残す
    $new_data = array_filter($existing_data, function ($char) use ($post_id) {
        return $char['id'] != $post_id;
    });

    // 抜け番になったインデックスを詰めて再保存
    file_put_contents($json_file_path, json_encode(array_values($new_data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// =========================================================
// 5. 自動更新のフック設定
// =========================================================
add_action('save_post_character', 'koto_auto_update_json_on_save', 10, 3);
function koto_auto_update_json_on_save($post_id, $post, $update)
{
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

    if ($post->post_status === 'publish') {
        // 公開時は単体上書き処理のみ走る（激速）
        koto_update_search_json_single($post_id);
    }
}

add_action('transition_post_status', 'koto_auto_update_json_on_trash', 10, 3);
function koto_auto_update_json_on_trash($new_status, $old_status, $post)
{
    if ($post->post_type !== 'character') return;

    if ($old_status === 'publish' && $new_status !== 'publish') {
        // 非公開になったらJSONから抜き取る
        koto_delete_search_json_single($post->ID);
    }
}

// =========================================================
// 6. 管理画面のメニューとUI（常時プレビュー機能付き）
// =========================================================
add_action('admin_menu', 'koto_add_json_reform_page');
function koto_add_json_reform_page()
{
    add_submenu_page(
        'edit.php?post_type=character',
        '検索用JSON生成',
        '検索用JSON生成',
        'manage_options',
        'koto-json-reform',
        'koto_render_json_reform_page'
    );
}

function koto_render_json_reform_page()
{
    $message = '';
    $json_file_path = get_stylesheet_directory() . '/lib/character-search/all_characters_search.json';

    // 手動生成ボタンが押された時の処理
    if (isset($_POST['generate_koto_json']) && check_admin_referer('koto_generate_json_action', 'koto_generate_json_nonce')) {
        koto_generate_search_json_all();
        $message = '<div class="updated"><p>全キャラクターのJSONを手動で再生成しました。</p></div>';
    }

    // 常に現在のファイルの中身を読み込んで整形
    $current_json_preview = '';
    $char_count = 0;
    if (file_exists($json_file_path)) {
        $raw_data = json_decode(file_get_contents($json_file_path), true);
        if (is_array($raw_data)) {
            $char_count = count($raw_data);
            $current_json_preview = json_encode($raw_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
    }

    echo '<div class="wrap">';
    echo '<h1>検索用JSONファイル 管理画面</h1>';
    echo $message;
    echo '<p>キャラクター記事を保存・公開すると、対象の1キャラ分だけが自動的に以下のファイルへ高速上書きされます。</p>';

    echo '<form method="post" action="">';
    wp_nonce_field('koto_generate_json_action', 'koto_generate_json_nonce');
    echo '<p><input type="submit" name="generate_koto_json" class="button button-primary" value="全件を手動で再生成する (リセット用)"></p>';
    echo '</form>';

    echo '<h2>現在のファイル内容 (' . intval($char_count) . 'キャラ収録)</h2>';
    if (!empty($current_json_preview)) {
        echo '<textarea style="width: 100%; height: 600px; font-family: monospace; background: #fff; padding: 10px; border: 1px solid #ccc; white-space: pre;" readonly>' . esc_textarea($current_json_preview) . '</textarea>';
    } else {
        echo '<p style="color: red;">まだJSONファイルが存在しないか、データが空です。「全件を手動で再生成する」ボタンを押してください。</p>';
    }
    echo '</div>';
}
