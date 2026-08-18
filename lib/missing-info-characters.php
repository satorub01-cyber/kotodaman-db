<?php
// =========================================================
// ヘルパー関数
// =========================================================

/**
 * 判定用関数：指定された技データの配列内に、
 * type='attack' かつ valueが未入力(0 or 空)のものがあるかチェック
 *
 * @param array $move_data _spec_json内の [waza] や [sugowaza] の配列
 * @param bool $is_estimate 推定フラグ
 * @return boolean 未入力があれば true
 */
function koto_is_attack_value_missing($move_data, $is_estimate = false)
{
    if ($is_estimate) return true;

    if (empty($move_data) || !is_array($move_data)) return false;
    if (empty($move_data['variations']) || !is_array($move_data['variations'])) return false;

    foreach ($move_data['variations'] as $variation) {
        $timelines = [];
        if (!empty($variation['timelines']) && is_array($variation['timelines'])) {
            $timelines = $variation['timelines'];
        } elseif (!empty($variation['timeline']) && is_array($variation['timeline'])) {
            $timelines = $variation['timeline'];
        }

        if (empty($timelines)) continue;

        foreach ($timelines as $action) {
            if (isset($action['type']) && (strpos($action['type'], 'attack') !== false || strpos($action['type'], 'heal') !== false)) {
                if (empty($action['value'])) {
                    return true;
                }
            }
        }
    }
    return false;
}

function koto_get_missing_char_data($post_id)
{
    $raw_data = get_post_meta($post_id, '_spec_json', true);

    if (is_string($raw_data)) {
        $data = json_decode($raw_data, true);
    } elseif (is_array($raw_data)) {
        $data = $raw_data;
    } else {
        $data = null;
    }

    if (empty($data)) return null;

    $is_estimate = !empty($data['is_estimate']);

    $missing_parts = [];

    // わざ
    if (isset($data['waza']) && koto_is_attack_value_missing($data['waza'], $is_estimate)) {
        $missing_parts[] = 'わざ';
    }
    // すごわざ
    if (isset($data['sugowaza']) && koto_is_attack_value_missing($data['sugowaza'], $is_estimate)) {
        $missing_parts[] = 'すごわざ';
    }
    // ことわざ
    if (isset($data['kotowaza']) && is_array($data['kotowaza']) && !empty($data['kotowaza'])) {
        $all_koto_missing = true;
        foreach ($data['kotowaza'] as $k_level) {
            if (!koto_is_attack_value_missing($k_level, $is_estimate)) {
                $all_koto_missing = false;
                break;
            }
        }
        if ($all_koto_missing) {
            $missing_parts[] = 'ことわざ';
        }
    }

    if (!empty($missing_parts)) {
        return [
            'id' => $post_id,
            'name' => get_the_title($post_id),
            'missing' => $missing_parts
        ];
    }

    return null;
}

// =========================================================
// 全件を再生成する処理
// =========================================================
function koto_generate_missing_info_json_all()
{
    $args = [
        'post_type'      => 'character',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ];
    $character_ids = get_posts($args);
    $missing_data = [];

    foreach ($character_ids as $post_id) {
        $missing_char = koto_get_missing_char_data($post_id);
        if ($missing_char) {
            $missing_data[] = $missing_char;
        }
    }

    $json_file_path = get_stylesheet_directory() . '/lib/missing-info.json';
    $json_output = json_encode($missing_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json_output !== false) {
        file_put_contents($json_file_path, $json_output);
    }
}

// =========================================================
// 単体データを上書き・追記する処理
// =========================================================
function koto_update_missing_info_json_single($post_id)
{
    $json_file_path = get_stylesheet_directory() . '/lib/missing-info.json';
    $existing_data = [];

    if (file_exists($json_file_path)) {
        $json_content = file_get_contents($json_file_path);
        if ($json_content) {
            $existing_data = json_decode($json_content, true);
            if (!is_array($existing_data)) $existing_data = [];
        }
    }

    $missing_char = koto_get_missing_char_data($post_id);
    
    $updated = false;
    foreach ($existing_data as $index => $char) {
        if ($char['id'] == $post_id) {
            if ($missing_char) {
                $existing_data[$index] = $missing_char;
            } else {
                unset($existing_data[$index]);
            }
            $updated = true;
            break;
        }
    }

    if (!$updated && $missing_char) {
        $existing_data[] = $missing_char;
    }

    file_put_contents($json_file_path, json_encode(array_values($existing_data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// =========================================================
// 単体データを削除する処理
// =========================================================
function koto_delete_missing_info_json_single($post_id)
{
    $json_file_path = get_stylesheet_directory() . '/lib/missing-info.json';
    if (!file_exists($json_file_path)) return;

    $json_content = file_get_contents($json_file_path);
    if (!$json_content) return;

    $existing_data = json_decode($json_content, true);
    if (!is_array($existing_data)) return;

    $new_data = array_filter($existing_data, function ($char) use ($post_id) {
        return $char['id'] != $post_id;
    });

    file_put_contents($json_file_path, json_encode(array_values($new_data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// =========================================================
// 自動更新のフック設定 (koto-json-reformer内で全て回す際に統合するためフックのみ登録)
// =========================================================