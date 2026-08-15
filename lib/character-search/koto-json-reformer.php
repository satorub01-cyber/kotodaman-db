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

function koto_json_reform_format_bytes($bytes)
{
    $bytes = (float) $bytes;
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }

    return number_format($bytes, 0) . ' B';
}

function koto_json_reform_replace_file_atomically($tmp_file_path, $target_file_path)
{
    if (@rename($tmp_file_path, $target_file_path)) {
        return true;
    }

    if (@copy($tmp_file_path, $target_file_path)) {
        @unlink($tmp_file_path);
        return true;
    }

    return false;
}

function koto_json_reform_file_meta($path)
{
    if (!file_exists($path)) {
        return [
            'exists' => false,
            'size' => 0,
            'modified' => 0,
        ];
    }

    return [
        'exists' => true,
        'size' => (int) @filesize($path),
        'modified' => (int) @filemtime($path),
    ];
}

function koto_json_reform_normalize_regen_result($result, $fallback_label)
{
    if (is_array($result)) {
        return $result;
    }

    if ($result === false) {
        return [
            'success' => false,
            'error' => $fallback_label . 'の再生成に失敗しました。',
            'processed' => 0,
            'written' => 0,
            'elapsed_sec' => 0,
            'peak_memory' => memory_get_peak_usage(true),
            'legacy_return' => true,
        ];
    }

    // 旧実装（戻り値なし/null）との互換: 処理自体は完了している前提で成功扱いにする
    return [
        'success' => true,
        'processed' => 0,
        'written' => 0,
        'elapsed_sec' => 0,
        'peak_memory' => memory_get_peak_usage(true),
        'legacy_return' => true,
    ];
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
    $attribute_slug = $spec['attribute'] ?? '';
    $species_slug = $spec['species'] ?? '';

    $live_attr_terms = get_the_terms($post_id, 'attribute');
    if ($live_attr_terms && !is_wp_error($live_attr_terms) && !empty($live_attr_terms[0]->slug)) {
        $attribute_slug = $live_attr_terms[0]->slug;
    }

    $live_species_terms = get_the_terms($post_id, 'species');
    if ($live_species_terms && !is_wp_error($live_species_terms) && !empty($live_species_terms[0]->slug)) {
        $species_slug = $live_species_terms[0]->slug;
    }

    $group_source = $spec['groups'] ?? [];
    $live_group_terms = get_the_terms($post_id, 'affiliation');
    if ($live_group_terms && !is_wp_error($live_group_terms)) {
        $group_source = array_map(function ($term) {
            return [
                'slug' => $term->slug,
                'name' => $term->name,
            ];
        }, $live_group_terms);
    }

    $group_pairs = array_map(function ($item) {
        return [
            'en' => $item['slug'] ?? '',
            'jp' => $item['name'] ?? '',
        ];
    }, $group_source);
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
    $suitable_quests = wp_get_post_terms($post_id, 'suitable_quest', ['fields' => 'slugs']);

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
        'attr'        => $attr_num[$attribute_slug] ?? 0,
        'sub_attrs'     => $sub_attributes,
        'spe'          => $species_num[$species_slug] ?? 0,
        'group_en'     => $groups['en'],
        'group_jp'     => $groups['jp'],
        'events'       => is_array($events) ? $events : [],
        'quests' => is_array($suitable_quests) ? $suitable_quests : [],
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
        'firepower_index' => is_array($spec['firepower_index']) ? max($spec['firepower_index']) : 0,
        'healingpower_index' => is_array($spec['healingpower_index']) ? max($spec['healingpower_index']) : 0,
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
function koto_generate_search_json_all($rebuild_spec_data = false)
{
    $start_time = microtime(true);
    $batch_size = 100;
    $json_file_path = get_stylesheet_directory() . '/lib/character-search/all_characters_search.json';
    $tmp_file_path = $json_file_path . '.tmp';
    $write_path = $tmp_file_path;
    $using_temp_file = true;

    $tmp_handle = @fopen($tmp_file_path, 'wb');
    if (!$tmp_handle) {
        $tmp_handle = @fopen($json_file_path, 'wb');
        $write_path = $json_file_path;
        $using_temp_file = false;

        if (!$tmp_handle) {
            $last_error = error_get_last();
            return [
                'success' => false,
                'error' => '検索用JSONを書き込めませんでした: ' . ($last_error['message'] ?? 'unknown error'),
            ];
        }
    }

    $processed_count = 0;
    $written_count = 0;
    $has_prev_item = false;

    fwrite($tmp_handle, '[');

    $paged = 1;
    while (true) {
        $query = new WP_Query([
            'post_type' => 'character',
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'paged' => $paged,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'cache_results' => false,
            'lazy_load_term_meta' => false,
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);

        if (empty($query->posts)) {
            wp_reset_postdata();
            break;
        }

        foreach ($query->posts as $post_id) {
            $processed_count++;

            if ($rebuild_spec_data && function_exists('on_save_character_specs')) {
                on_save_character_specs($post_id);
                clean_post_cache($post_id);
            }

            $flat_char = koto_get_flat_char_data($post_id);
            if (!$flat_char) {
                continue;
            }

            $json_row = json_encode($flat_char, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json_row === false) {
                continue;
            }

            if ($has_prev_item) {
                fwrite($tmp_handle, ',');
            }
            fwrite($tmp_handle, $json_row);
            $has_prev_item = true;
            $written_count++;
        }

        wp_reset_postdata();
        $paged++;

        unset($query);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    fwrite($tmp_handle, ']');
    fclose($tmp_handle);

    if ($using_temp_file && !koto_json_reform_replace_file_atomically($tmp_file_path, $json_file_path)) {
        $last_error = error_get_last();
        @unlink($tmp_file_path);
        return [
            'success' => false,
            'error' => '検索用JSONの保存に失敗しました: ' . ($last_error['message'] ?? 'rename/copy failed'),
        ];
    }

    $elapsed_sec = microtime(true) - $start_time;
    $peak_memory = memory_get_peak_usage(true);
    $result = [
        'success' => true,
        'processed' => $processed_count,
        'written' => $written_count,
        'elapsed_sec' => $elapsed_sec,
        'peak_memory' => $peak_memory,
    ];
    update_option('koto_search_json_last_regen_stats', $result, false);

    return $result;
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
        if (function_exists('koto_update_missing_info_json_single')) {
            koto_update_missing_info_json_single($post_id);
        }
    }
}

add_action('transition_post_status', 'koto_auto_update_json_on_trash', 10, 3);
function koto_auto_update_json_on_trash($new_status, $old_status, $post)
{
    if ($post->post_type !== 'character') return;

    if ($old_status === 'publish' && $new_status !== 'publish') {
        // 非公開になったらJSONから抜き取る
        koto_delete_search_json_single($post->ID);
        if (function_exists('koto_delete_missing_info_json_single')) {
            koto_delete_missing_info_json_single($post->ID);
        }
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
    $missing_json_file_path = get_stylesheet_directory() . '/lib/missing-info.json';
    $lock_key = 'koto_json_regeneration_lock';

    // 手動生成ボタンが押された時の処理
    if (isset($_POST['generate_koto_json']) && check_admin_referer('koto_generate_json_action', 'koto_generate_json_nonce')) {
        if (get_transient($lock_key)) {
            $message = '<div class="notice notice-warning"><p>再生成処理がすでに実行中です。完了後に再度お試しください。</p></div>';
        } else {
            set_transient($lock_key, 1, 15 * MINUTE_IN_SECONDS);

            try {
                // 手動全件再生成はJSON再構築に専念し、spec全件再計算は別バッチ導線に分離する。
                $search_result = koto_generate_search_json_all(false);
                $missing_result = [
                    'success' => true,
                    'processed' => 0,
                    'written' => 0,
                    'elapsed_sec' => 0,
                    'peak_memory' => memory_get_peak_usage(true),
                ];
                if (function_exists('koto_generate_missing_info_json_all')) {
                    $missing_result = koto_generate_missing_info_json_all(false);
                }
            } finally {
                delete_transient($lock_key);
            }

            $search_result = koto_json_reform_normalize_regen_result($search_result, '検索用JSON');
            $missing_result = koto_json_reform_normalize_regen_result($missing_result, '未入力JSON');

            if (!empty($search_result['success']) && !empty($missing_result['success'])) {
                $total_elapsed = (float) ($search_result['elapsed_sec'] ?? 0) + (float) ($missing_result['elapsed_sec'] ?? 0);
                $peak = max((int) ($search_result['peak_memory'] ?? 0), (int) ($missing_result['peak_memory'] ?? 0));
                $legacy_note = '';
                if (!empty($search_result['legacy_return']) || !empty($missing_result['legacy_return'])) {
                    $legacy_note = ' （一部処理は旧戻り値互換モードで集計）';
                }

                $message = '<div class="updated"><p>'
                    . '全キャラクターの検索用JSONおよび未入力情報JSONを再生成しました。'
                    . ' 検索JSON: ' . intval($search_result['written'] ?? 0) . '件 / 未入力JSON: ' . intval($missing_result['written'] ?? 0) . '件'
                    . ' / 実行時間: ' . esc_html(number_format($total_elapsed, 2)) . '秒'
                    . ' / ピークメモリ: ' . esc_html(koto_json_reform_format_bytes($peak))
                    . $legacy_note
                    . '</p></div>';
            } else {
                $search_error = $search_result['error'] ?? '';
                $missing_error = $missing_result['error'] ?? '';
                $error_message = trim($search_error . ' ' . $missing_error);
                if ($error_message === '') {
                    $error_message = '再生成に失敗しました。';
                }
                $message = '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
            }
        }
    }

    $search_stats = get_option('koto_search_json_last_regen_stats', []);
    $missing_stats = get_option('koto_missing_info_json_last_regen_stats', []);
    $search_meta = koto_json_reform_file_meta($json_file_path);
    $missing_meta = koto_json_reform_file_meta($missing_json_file_path);

    echo '<div class="wrap">';
    echo '<h1>検索用JSONファイル 管理画面</h1>';
    echo $message;
    echo '<p>キャラクター記事を保存・公開すると、対象の1キャラ分だけが自動的に更新されます。</p>';
    echo '<p>この画面では巨大JSONのプレビューを表示しません（メモリ節約のため）。</p>';
    echo '<p>タクソノミーslug変更は、投稿の更新有無に関係なくこの全件再生成で反映されます。</p>';
    echo '<p>spec（_spec_json）を全件再計算したい場合は、<a href="' . esc_url(add_query_arg(['run_update_index' => '1'], home_url('/'))) . '">こちらのバッチ更新導線</a>を使ってください（JSON再生成とは分離）。</p>';

    echo '<form method="post" action="">';
    wp_nonce_field('koto_generate_json_action', 'koto_generate_json_nonce');
    echo '<p>';
    echo '<input type="submit" name="generate_koto_json" class="button button-primary" value="全件を手動で再生成する (リセット用)">';
    echo '</p>';
    echo '</form>';

    echo '<h2>検索用JSONファイル</h2>';
    if ($search_meta['exists']) {
        echo '<ul>';
        echo '<li>保存先: ' . esc_html($json_file_path) . '</li>';
        echo '<li>ファイルサイズ: ' . esc_html(koto_json_reform_format_bytes($search_meta['size'])) . '</li>';
        echo '<li>最終更新: ' . esc_html(wp_date('Y-m-d H:i:s', $search_meta['modified'])) . '</li>';
        if (!empty($search_stats['written'])) {
            echo '<li>最終全件再生成時の収録数: ' . intval($search_stats['written']) . '件</li>';
        }
        echo '</ul>';
    } else {
        echo '<p style="color: red;">検索用JSONファイルが未生成です。「全件を手動で再生成する」ボタンを押してください。</p>';
    }

    echo '<h2 style="margin-top: 30px;">未入力キャラJSONファイル</h2>';
    if ($missing_meta['exists']) {
        echo '<ul>';
        echo '<li>保存先: ' . esc_html($missing_json_file_path) . '</li>';
        echo '<li>ファイルサイズ: ' . esc_html(koto_json_reform_format_bytes($missing_meta['size'])) . '</li>';
        echo '<li>最終更新: ' . esc_html(wp_date('Y-m-d H:i:s', $missing_meta['modified'])) . '</li>';
        if (isset($missing_stats['written'])) {
            echo '<li>最終全件再生成時の収録数: ' . intval($missing_stats['written']) . '件</li>';
        }
        echo '</ul>';
    } else {
        echo '<p style="color: green;">未入力JSONファイルが未生成です。</p>';
    }
    echo '</div>';
}
