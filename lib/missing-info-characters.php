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
function koto_generate_missing_info_json_all($rebuild_spec_data = false)
{
    $start_time = microtime(true);
    $batch_size = 100;
    $json_file_path = get_stylesheet_directory() . '/lib/missing-info.json';
    $tmp_file_path = $json_file_path . '.tmp';
    $using_temp_file = true;

    $tmp_handle = @fopen($tmp_file_path, 'wb');
    if (!$tmp_handle) {
        $tmp_handle = @fopen($json_file_path, 'wb');
        $using_temp_file = false;

        if (!$tmp_handle) {
            $last_error = error_get_last();
            return [
                'success' => false,
                'error' => '未入力JSONを書き込めませんでした: ' . ($last_error['message'] ?? 'unknown error'),
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

            $missing_char = koto_get_missing_char_data($post_id);
            if (!$missing_char) {
                continue;
            }

            $json_row = json_encode($missing_char, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    if ($using_temp_file) {
        if (function_exists('koto_json_reform_replace_file_atomically')) {
            if (!koto_json_reform_replace_file_atomically($tmp_file_path, $json_file_path)) {
                $last_error = error_get_last();
                @unlink($tmp_file_path);
                return [
                    'success' => false,
                    'error' => '未入力JSONの保存に失敗しました: ' . ($last_error['message'] ?? 'rename/copy failed'),
                ];
            }
        } else {
            if (!@rename($tmp_file_path, $json_file_path)) {
                if (!@copy($tmp_file_path, $json_file_path)) {
                    $last_error = error_get_last();
                    @unlink($tmp_file_path);
                    return [
                        'success' => false,
                        'error' => '未入力JSONの保存に失敗しました: ' . ($last_error['message'] ?? 'rename/copy failed'),
                    ];
                }
                @unlink($tmp_file_path);
            }
        }
    }

    $result = [
        'success' => true,
        'processed' => $processed_count,
        'written' => $written_count,
        'elapsed_sec' => microtime(true) - $start_time,
        'peak_memory' => memory_get_peak_usage(true),
    ];
    update_option('koto_missing_info_json_last_regen_stats', $result, false);

    return $result;
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
