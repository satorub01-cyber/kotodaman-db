<?php
// =========================================================
// ヘルパー関数
// =========================================================
// parse_target_groupの戻り値を展開してslug配列を生成する関数
function flatten_target_group($item)
{
    // 戻り値となるtypeの確保と初期化
    $type = $item['type'] ?? '';
    $slgs = [];
    $objs = $item['obj'] ?? [];

    // 対象が自身・全体の場合はループを回さず早期リターン
    if ($type === 'self' || $type === 'all') {
        return [
            'ty' => $type,
            'slgs' => ''
        ];
    }

    // 必要な場合のみ連想配列（辞書）を取得する遅延評価
    $attr_num = ($type === 'attr') ? koto_get_attr_num() : [];
    $species_num = ($type === 'species') ? koto_get_species_num() : [];

    // 各要素から必要な値を抽出
    foreach ($objs as $obj) {
        $slug = $obj['slug'] ?? '';
        $name = $obj['name'] ?? '';

        if ($type === 'other') {
            $slgs[] = $name;
        } elseif ($type === 'attr') {
            // 連想配列にキーが存在しない場合のフォールバックを追加
            $slgs[] = $slug ? ($attr_num[$slug] ?? 1) : 1;
        } elseif ($type === 'species') {
            $slgs[] = $slug ? ($species_num[$slug] ?? 1) : 1;
        } else {
            $slgs[] = $slug;
        }
    }

    // 値が「0」のデータを保持しつつ、空文字（''）のみを厳密に除外
    $filtered_slgs = array_filter($slgs, function ($val) {
        return $val !== '';
    });

    return [
        'ty' => $type,
        'slgs' => $filtered_slgs
    ];
}

function flatten_leader($item)
{
    return [
        'ty' => $item['type'] ?? '',
        'cond' => array_map(function ($c) {
            return [
                'ty' => $c['type'] ?? '',
                'vals' => $c['val'] ?? [],
                'tgts' => array_map(function ($t) {
                    // ターゲットの基本情報を取得し、編成条件の数値を統合する
                    $parsed = flatten_target_group($t);
                    $parsed['ttl'] = $t['total_tf'] ?? false;
                    $parsed['num'] = $t['need_num'] ?? 0;
                    return $parsed;
                }, $c['cond_targets'] ?? [])
            ];
        }, $item['conditions'] ?? []),
        'lm_wave' => $item['limit_wave'] ?? 0,
        'per_unit' => $item['per_unit'] ?? false,
        'effs' => array_map(function ($e) {
            // ステータス補正値や耐性を1つの連想配列（辞書型）にまとめる
            $merged_vals = [];
            foreach ($e['value_raws'] ?? [] as $val) {
                $key = ($val['status'] === 'resistance') ? ($val['resist'] ?? '') : ($val['status'] ?? '');
                if ($key !== '') {
                    $merged_vals[$key] = $val['value'] ?? 0;
                }
            }

            return [
                'tgts' => array_map('flatten_target_group', $e['targets'] ?? []),
                'vals' => $merged_vals
            ];
        }, $item['main_eff'] ?? []),
        'oth_val' => $item['exp'] ?? ($item['buff_count'] ?? 0),
        'convs' => $item['converge_rate'] ?? [],
        'trn' => $item['turn_count'] ?? 0,
    ];
}

function koto_unique_bilingual_pairs($pairs)
{
    $seen = [];
    $en_values = [];
    $jp_values = [];

    foreach ($pairs as $pair) {
        $en = trim((string) ($pair['en'] ?? ''));
        $jp = trim((string) ($pair['jp'] ?? ''));

        if ($en === '' || $jp === '') {
            continue;
        }

        $pair_key = $en . "\t" . $jp;
        if (isset($seen[$pair_key])) {
            continue;
        }

        $seen[$pair_key] = true;
        $en_values[] = $en;
        $jp_values[] = $jp;
    }

    return [
        'en' => $en_values,
        'jp' => $jp_values,
    ];
}

function koto_collect_status_resistance_pairs($status_slugs, $status_map)
{
    $pairs = [];

    foreach ($status_slugs as $status_slug) {
        $status_slug = trim((string) $status_slug);
        if ($status_slug === '' || empty($status_map[$status_slug])) {
            continue;
        }

        $pairs[] = [
            'en' => $status_slug,
            'jp' => $status_map[$status_slug],
        ];
    }

    return koto_unique_bilingual_pairs($pairs);
}

function koto_extract_trait_contents($section)
{
    if (!is_array($section)) {
        return [];
    }

    if (isset($section['contents']) && is_array($section['contents'])) {
        return $section['contents'];
    }

    if ($section === []) {
        return [];
    }

    return array_keys($section) === range(0, count($section) - 1) ? $section : [];
}

function koto_get_trait_whose_type($trait)
{
    $whose = $trait['whose'] ?? 'self';
    $whose_type = '';

    if (is_array($whose)) {
        $whose_type = trim((string) ($whose['type'] ?? ''));
    } elseif (is_object($whose)) {
        $whose_type = trim((string) ($whose->type ?? ''));
    } else {
        $whose_type = trim((string) $whose);
    }

    return $whose_type === '' ? 'self' : $whose_type;
}

function koto_normalize_trait_search_slug($trait)
{
    $type = trim((string) ($trait['type'] ?? ''));
    if ($type === '') {
        return [];
    }

    $canonical_type = ($type === 'other_traits') ? 'other' : $type;
    $slugs = [$canonical_type];

    if ($canonical_type === 'mode_shift') {
        $relation = trim((string) ($trait['shift_relation'] ?? ($trait['relation_ship'] ?? '')));
        if ($relation === 'mode_shift') {
            $slugs[] = 'mode_shift_mode_shift';
        } elseif ($relation === 'before_transform' || $relation === 'after_transform') {
            $slugs[] = 'mode_shift_transform';
        }
    } else {
        $sub_type = trim((string) ($trait['sub_type'] ?? ''));
        if ($sub_type === 'healling') {
            $sub_type = 'healing';
        }

        if ($canonical_type === 'new_traits' && ($sub_type === 'resonance' || $sub_type === 'resonance_crit')) {
            $has_crit_resonance = !empty($trait['crit_rate'])
                || !empty($trait['crit_damage'])
                || !empty($trait['resonance_crit_rate'])
                || !empty($trait['resonance_crit_damage'])
                || $sub_type === 'resonance_crit';
            $sub_type = $has_crit_resonance ? 'resonance_crit' : 'resonance_atk';
        }

        if ($sub_type !== '') {
            $slugs[] = $canonical_type . '_' . $sub_type;
        }
    }

    if (koto_get_trait_whose_type($trait) !== 'self') {
        $slugs[] = 'give_trait';
    }

    return array_values(array_unique(array_filter($slugs, function ($slug) {
        return $slug !== '';
    })));
}

function koto_collect_trait_search_pairs($trait_contents, $label_map)
{
    $pairs = [];

    foreach ($trait_contents as $trait) {
        if (!is_array($trait)) {
            continue;
        }

        foreach (koto_normalize_trait_search_slug($trait) as $slug) {
            if (empty($label_map[$slug])) {
                continue;
            }

            $pairs[] = [
                'en' => $slug,
                'jp' => $label_map[$slug],
            ];
        }
    }

    return koto_unique_bilingual_pairs($pairs);
}


// =========================================================
// 1. 1キャラ分のデータを抽出する共通関数（★キー名の短縮などはここを編集）
// =========================================================
function koto_get_flat_char_data($post_id)
{
    $json_str = get_post_meta($post_id, '_spec_json', true);
    $spec = $json_str ? json_decode($json_str, true) : [];
    $attr_num = koto_get_attr_num();
    $species_num = koto_get_species_num();
    $status_map = function_exists('koto_get_status_map') ? koto_get_status_map() : [];
    $trait_label_map = function_exists('koto_get_trait_search_label_map') ? koto_get_trait_search_label_map() : [];
    $japanese_tags = '';

    if (!is_array($spec) || empty($spec)) {
        return null;
    }
    // 画像URLの取得
    $thumb_url = get_the_post_thumbnail_url($post_id, 'thumbnail') ?? '';

    // ギミック名の抽出
    $gimmick_pairs = [];
    $contents_trait1 = koto_extract_trait_contents($spec['trait1'] ?? []);
    $contents_trait2 = koto_extract_trait_contents($spec['trait2'] ?? []);
    $contents_blessing = koto_extract_trait_contents($spec['blessing'] ?? []);

    $traits = array_merge($contents_trait1, $contents_trait2, $contents_blessing);
    if (!empty($traits)) {
        foreach ($traits as $t) {
            if (($t['type'] ?? '') === 'gimmick' && !empty($t['sub_type'])) {
                $term = get_term_by('slug', $t['sub_type'], 'gimmick');
                if ($term) {
                    $gimmick_pairs[] = [
                        'en' => $term->slug,
                        'jp' => $term->name,
                    ];
                }
            }
        }
    }
    $gimmicks = koto_unique_bilingual_pairs($gimmick_pairs);
    $trait1_pairs = koto_collect_trait_search_pairs($contents_trait1, $trait_label_map);
    $trait2_pairs = koto_collect_trait_search_pairs($contents_trait2, $trait_label_map);
    $blessing_pairs = koto_collect_trait_search_pairs($contents_blessing, $trait_label_map);
    $trait_status_resistance_slugs = [];
    foreach ($traits as $trait) {
        if (($trait['type'] ?? '') !== 'status_up' || ($trait['sub_type'] ?? '') !== 'resistance') {
            continue;
        }

        $trait_status_slug = $trait['resist_status'] ?? '';
        if ($trait_status_slug !== '') {
            $trait_status_resistance_slugs[] = $trait_status_slug;
        }
    }
    $trait_status_resistances = koto_collect_status_resistance_pairs($trait_status_resistance_slugs, $status_map);
    $sub_attributes = array_map(function ($item) use ($attr_num) {
        return $attr_num[$item] ?? 0;
    }, $spec['sub_attributes'] ?? []);
    $group_pairs = array_map(function ($item) {
        return [
            'en' => $item['slug'] ?? '',
            'jp' => $item['name'] ?? '',
        ];
    }, $spec['groups'] ?? []);
    $groups = koto_unique_bilingual_pairs($group_pairs);
    $unlock_map = [
        'default' => 'def',
        'first_trait' => '1',
        'second_trait' => '2',
        'blessing'    => 'bl',
        'super_change' => 'schange',
        'super_copy' => 'scopy',
        'super_both' => 'sboth'
    ];
    $charas = array_map(function ($item) use ($attr_num, $unlock_map) {
        $unlock_key = isset($item['unlock']) ? trim((string)$item['unlock']) : 'default';
        $attr_key   = isset($item['attr']) ? trim((string)$item['attr']) : '';
        return [
            'val' => $item['val'] ?? '',
            'attr' => $attr_num[$attr_key] ?? 0,
            'unlock' => $unlock_map[$unlock_key] ?? 'def',
        ];
    }, $spec['chars'] ?? []);

    // ★5. レアリティの検索用配列を高速生成 (例: ["6", "legend"])
    $rarity_slugs = [];
    if (!empty($spec['rarity'])) {
        $rarity_slugs[] = (string) $spec['rarity'];
    }
    if (!empty($spec['rarity_detail'])) {
        $rarity_slugs[] = $spec['rarity_detail'];
    }

    // 4. イベントのスラッグ配列
    $events = wp_get_post_terms($post_id, 'event', ['fields' => 'slugs']);

    // 6. スキルタグ文字列 (カンマ区切りなどを配列にするか、文字列のままか。検索を簡単にするため文字列のままにして `includes` で判定するのも手です)
    $waza_tags = get_post_meta($post_id, '_waza_tags_str', true) ?: '';
    $sugo_tags = get_post_meta($post_id, '_sugo_tags_str', true) ?: '';
    $koto_tags = get_post_meta($post_id, '_kotowaza_tags_str', true) ?: '';

    $other_tags = get_post_meta($post_id, '_search_tags_str', true) ?: '';

    // リーダーとくせい
    $leader_raws = $spec['leader'] ?? [];
    $learder_flat = [];
    $learder_flat = array_map('flatten_leader', $leader_raws);
    $leader_status_resistance_slugs = [];
    foreach ($leader_raws as $leader_raw) {
        foreach ($leader_raw['main_eff'] ?? [] as $main_effect) {
            foreach ($main_effect['value_raws'] ?? [] as $value_raw) {
                if (($value_raw['status'] ?? '') !== 'resistance') {
                    continue;
                }

                $leader_status_slug = $value_raw['resist'] ?? '';
                if ($leader_status_slug !== '') {
                    $leader_status_resistance_slugs[] = $leader_status_slug;
                }
            }
        }
    }
    $leader_status_resistances = koto_collect_status_resistance_pairs($leader_status_resistance_slugs, $status_map);

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
        'group_en'     => $groups['en'],
        'group_jp'     => $groups['jp'],
        'events'       => is_array($events) ? $events : [],
        'rar'          => $spec['rarity'], //検索では不使用
        'rar_d'        => $spec['rarity_detail'], //検索では不使用
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
        'pri'          => $spec['priority'],
        'hnd_buff'     => $spec['buff_counts_hand'],
        'bd_buff'     => $spec['buff_counts_board'],
        'debuf'           => $spec['debuff_counts'],
        'gimmick_en'   => $gimmicks['en'],
        'gimmick_jp'   => $gimmicks['jp'],
        'trait_status_resistance_en' => $trait_status_resistances['en'],
        'trait_status_resistance_jp' => $trait_status_resistances['jp'],
        'leader_status_resistance_en' => $leader_status_resistances['en'],
        'leader_status_resistance_jp' => $leader_status_resistances['jp'],
        'leader'       => $learder_flat,
        'ls_hp'        => ($spec['max_ls_hp'] ?? 0),
        'ls_atk'       => ($spec['max_ls_atk'] ?? 0),
        // スキル/とくせいは文字列として保持しておく (例: " type_attack_single type_atk_buff ")
        'axis'      => $other_tags,
        'waza_t'       => $waza_tags,
        'sugo_t'       => $sugo_tags,
        'koto_t'       => $koto_tags,
        'trait1_en'    => $trait1_pairs['en'],
        'trait1_jp'    => $trait1_pairs['jp'],
        'trait2_en'    => $trait2_pairs['en'],
        'trait2_jp'    => $trait2_pairs['jp'],
        'blessing_en'  => $blessing_pairs['en'],
        'blessing_jp'  => $blessing_pairs['jp'],
        'jp_t'         => $japanese_tags,
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
add_action('acf/save_post', 'koto_auto_update_json_on_save', 99, 1);
function koto_auto_update_json_on_save($post_id)
{
    // オプションページなど投稿ID以外の保存時はスキップ
    if (!is_numeric($post_id)) return;

    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'character') return;

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
    echo '<p>';
    echo '<input type="submit" name="generate_koto_json" class="button button-primary" value="全件を手動で再生成する (リセット用)">';
    if (!empty($current_json_preview)) {
        echo ' <button type="button" id="download-koto-json" class="button">JSONをダウンロード</button>';
    }
    echo '</p>';
    echo '</form>';

    echo '<h2>現在のファイル内容 (' . intval($char_count) . 'キャラ収録)</h2>';
    if (!empty($current_json_preview)) {
        echo '<textarea id="koto-json-preview-area" style="width: 100%; height: 600px; font-family: monospace; background: #fff; padding: 10px; border: 1px solid #ccc; white-space: pre;" readonly>' . esc_textarea($current_json_preview) . '</textarea>';
        echo '<script>
            document.getElementById("download-koto-json").addEventListener("click", function() {
                var content = document.getElementById("koto-json-preview-area").value;
                if (!content) {
                    alert("ダウンロードするデータがありません。");
                    return;
                }
                var blob = new Blob([content], { type: "application/json" });
                var url = window.URL.createObjectURL(blob);
                var a = document.createElement("a");
                a.href = url;
                a.download = "all_characters_search.json";
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            });
        </script>';
    } else {
        echo '<p style="color: red;">まだJSONファイルが存在しないか、データが空です。「全件を手動で再生成する」ボタンを押してください。</p>';
    }
    echo '</div>';
}
