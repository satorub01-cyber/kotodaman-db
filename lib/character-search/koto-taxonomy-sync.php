<?php
/**
 * koto-taxonomy-sync.php
 *
 * タクソノミーターム更新・削除時の _spec_json および 検索用JSON 差分同期モジュール
 *
 * 1. event などのタクソノミーターム自体にスラッグ変更・名称変更があった場合、またはタームが削除された場合に、
 *    該当タームを持つキャラクターの `_spec_json` および `all_characters_search.json` の該当箇所のみをピンポイントで修整します。
 * 2. プラグイン「Taxonomy Terms Order」による並べ替え操作（term_order の更新）には一切反応しません。
 */

if (!defined('ABSPATH')) exit;

class Koto_Taxonomy_Sync {

    /** @var array 更新前のターム情報を保持する一時キャッシュ [term_id => ['slug' => ..., 'name' => ...]] */
    private static $term_before_update = [];

    /** @var array 削除前のターム情報を保持する一時キャッシュ [term_id => ['slug' => ..., 'name' => ..., 'target_ids' => ...]] */
    private static $term_before_delete = [];

    /**
     * フック登録
     */
    public static function init() {
        // --- ターム更新フック ---
        // DB更新前に旧スラッグ・旧名称をキャプチャ
        add_action('edit_terms', [__CLASS__, 'on_edit_terms'], 10, 2);
        // DB更新後に差分比較し、同期処理を実行
        add_action('edited_term', [__CLASS__, 'on_edited_term'], 20, 3);

        // --- ターム削除フック ---
        // 削除前に旧スラッグ・対象キャラクターIDをキャプチャ
        add_action('pre_delete_term', [__CLASS__, 'on_pre_delete_term'], 10, 2);
        // 削除後に _spec_json および 検索用JSON から削除
        add_action('delete_term', [__CLASS__, 'on_delete_term'], 20, 4);
    }

    /**
     * 対象タクソノミーかどうかの判定
     *
     * @param string $taxonomy
     * @return bool
     */
    public static function is_target_taxonomy($taxonomy) {
        $allowed = [
            'event',
            'suitable_quest',
            'affiliation',
            'attribute',
            'species',
            'gimmick',
            'rarity',
            'available_moji',
        ];
        return in_array($taxonomy, $allowed, true);
    }

    /**
     * Taxonomy Terms Order の並べ替え処理中かどうかを判定
     *
     * @return bool
     */
    public static function is_tto_reorder_request() {
        // 1. TTOのAJAXリクエスト
        if (wp_doing_ajax() && isset($_REQUEST['action']) && $_REQUEST['action'] === 'update-taxonomy-order') {
            return true;
        }

        // 2. TTOのアクションフック実行中
        if (doing_action('tto/update-order')) {
            return true;
        }

        return false;
    }

    // =================================================================
    // 1. ターム更新時のハンドラー
    // =================================================================

    /**
     * ターム更新前 (edit_terms フック)
     */
    public static function on_edit_terms($term_id, $taxonomy) {
        if (!self::is_target_taxonomy($taxonomy)) return;
        if (self::is_tto_reorder_request()) return;

        $term = get_term($term_id, $taxonomy);
        if ($term && !is_wp_error($term)) {
            self::$term_before_update[(int)$term_id] = [
                'slug' => (string)$term->slug,
                'name' => (string)$term->name,
            ];
        }
    }

    /**
     * ターム更新後 (edited_term フック)
     */
    public static function on_edited_term($term_id, $tt_id, $taxonomy) {
        if (!self::is_target_taxonomy($taxonomy)) return;
        if (self::is_tto_reorder_request()) return;

        $term_id = (int)$term_id;
        $old_info = self::$term_before_update[$term_id] ?? null;
        unset(self::$term_before_update[$term_id]);

        $new_term = get_term($term_id, $taxonomy);
        if (!$new_term || is_wp_error($new_term)) return;

        $old_slug = $old_info['slug'] ?? '';
        $old_name = $old_info['name'] ?? '';
        $new_slug = (string)$new_term->slug;
        $new_name = (string)$new_term->name;

        // スラッグも名称も変わっていなければスキップ (並べ替えや説明文変更等)
        if ($old_slug === $new_slug && $old_name === $new_name) {
            return;
        }

        self::sync_term_update_to_characters($taxonomy, $term_id, $old_slug, $new_slug, $old_name, $new_name);
    }

    // =================================================================
    // 2. ターム削除時のハンドラー
    // =================================================================

    /**
     * ターム削除前 (pre_delete_term フック)
     */
    public static function on_pre_delete_term($term_id, $taxonomy) {
        if (!self::is_target_taxonomy($taxonomy)) return;

        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) return;

        $slug = (string)$term->slug;
        $name = (string)$term->name;

        // 削除前に関連付けられたキャラクターを取得
        $target_ids = self::find_characters_for_term((int)$term_id, $taxonomy, $slug);

        self::$term_before_delete[(int)$term_id] = [
            'slug'       => $slug,
            'name'       => $name,
            'target_ids' => $target_ids,
        ];
    }

    /**
     * ターム削除後 (delete_term フック)
     */
    public static function on_delete_term($term_id, $tt_id, $taxonomy, $deleted_term) {
        if (!self::is_target_taxonomy($taxonomy)) return;

        $term_id = (int)$term_id;
        $cached = self::$term_before_delete[$term_id] ?? null;
        unset(self::$term_before_delete[$term_id]);

        $slug = $cached['slug'] ?? ($deleted_term->slug ?? '');
        $name = $cached['name'] ?? ($deleted_term->name ?? '');
        $target_ids = $cached['target_ids'] ?? [];

        if (empty($slug)) return;

        self::sync_term_delete_to_characters($taxonomy, $term_id, $slug, $name, $target_ids);
    }

    // =================================================================
    // 3. 対象キャラクターの探索
    // =================================================================

    /**
     * タームに関連するキャラクター投稿ID一覧を取得
     * (WordPressのタームリレーション + _spec_json内に対象スラッグを含むキャラクター)
     *
     * @param int $term_id
     * @param string $taxonomy
     * @param string $slug
     * @return array
     */
    public static function find_characters_for_term($term_id, $taxonomy, $slug) {
        global $wpdb;

        // 1. wp_term_relationships 経由で取得
        $term_post_ids = get_objects_in_term($term_id, $taxonomy);
        if (is_wp_error($term_post_ids) || !is_array($term_post_ids)) {
            $term_post_ids = [];
        }

        // 2. _spec_json 内にスラッグが含まれるキャラクターを検索 (リレーション欠落対策)
        $spec_post_ids = [];
        if ($slug !== '') {
            $query = $wpdb->prepare(
                "SELECT pm.post_id 
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type = 'character'
                 AND pm.meta_key = '_spec_json'
                 AND pm.meta_value LIKE %s",
                '%' . $wpdb->esc_like($slug) . '%'
            );
            $spec_post_ids = $wpdb->get_col($query);
        }

        $all_ids = array_unique(array_merge($term_post_ids, $spec_post_ids));

        // character 投稿タイプのみにフィルタ
        $valid_character_ids = [];
        foreach ($all_ids as $pid) {
            $pid = (int)$pid;
            if (get_post_type($pid) === 'character') {
                $valid_character_ids[] = $pid;
            }
        }

        return $valid_character_ids;
    }

    // =================================================================
    // 4. 更新同期処理
    // =================================================================

    /**
     * スラッグまたは名称変更を該当キャラクターの _spec_json および 検索用JSON に反映
     */
    public static function sync_term_update_to_characters($taxonomy, $term_id, $old_slug, $new_slug, $old_name, $new_name) {
        $target_ids = self::find_characters_for_term($term_id, $taxonomy, $old_slug);
        if (empty($target_ids)) return;

        $updated_post_ids = [];

        foreach ($target_ids as $post_id) {
            $json_str = get_post_meta($post_id, '_spec_json', true);
            if (!$json_str) continue;

            $spec = json_decode($json_str, true);
            if (!is_array($spec)) continue;

            $modified = false;

            switch ($taxonomy) {
                case 'event':
                case 'suitable_quest':
                    $key = ($taxonomy === 'event') ? 'event' : 'suitable_quest';
                    if (!empty($spec[$key]) && is_array($spec[$key]) && $old_slug !== $new_slug) {
                        foreach ($spec[$key] as &$val) {
                            if ($val === $old_slug) {
                                $val = $new_slug;
                                $modified = true;
                            }
                        }
                        unset($val);
                        if ($modified) {
                            $spec[$key] = array_values(array_unique($spec[$key]));
                        }
                    }
                    break;

                case 'affiliation':
                    if (!empty($spec['groups']) && is_array($spec['groups'])) {
                        foreach ($spec['groups'] as &$g) {
                            if (isset($g['slug']) && $g['slug'] === $old_slug) {
                                if ($old_slug !== $new_slug) {
                                    $g['slug'] = $new_slug;
                                    $modified = true;
                                }
                                if ($old_name !== $new_name) {
                                    $g['name'] = $new_name;
                                    $modified = true;
                                }
                            }
                        }
                        unset($g);
                    }
                    break;

                case 'attribute':
                    if ($old_slug !== $new_slug) {
                        if (($spec['attribute'] ?? '') === $old_slug) {
                            $spec['attribute'] = $new_slug;
                            $modified = true;
                        }
                        if (!empty($spec['sub_attributes']) && is_array($spec['sub_attributes'])) {
                            foreach ($spec['sub_attributes'] as &$sub) {
                                if ($sub === $old_slug) {
                                    $sub = $new_slug;
                                    $modified = true;
                                }
                            }
                            unset($sub);
                        }
                        // 属性ソート用メタ
                        if (function_exists('koto_get_attr_num')) {
                            $order_attr = koto_get_attr_num();
                            update_post_meta($post_id, '_sort_attr_index', $order_attr[$new_slug] ?? 99);
                        }
                    }
                    break;

                case 'species':
                    if ($old_slug !== $new_slug) {
                        if (($spec['species'] ?? '') === $old_slug) {
                            $spec['species'] = $new_slug;
                            $modified = true;
                        }
                        // 種族ソート用メタ
                        if (function_exists('koto_get_species_num')) {
                            $order_species = koto_get_species_num();
                            update_post_meta($post_id, '_sort_species_index', $order_species[$new_slug] ?? 99);
                        }
                    }
                    break;

                case 'gimmick':
                    if ($old_slug !== $new_slug) {
                        $modified = self::replace_gimmick_in_spec($spec, $old_slug, $new_slug);
                    }
                    break;

                case 'rarity':
                    if ($old_slug !== $new_slug && ($spec['rarity_detail'] ?? '') === $old_slug) {
                        $spec['rarity_detail'] = $new_slug;
                        $modified = true;
                    }
                    break;

                case 'available_moji':
                    if (!empty($spec['chars']) && is_array($spec['chars'])) {
                        foreach ($spec['chars'] as &$ch) {
                            if (isset($ch['slug']) && $ch['slug'] === $old_slug) {
                                if ($old_slug !== $new_slug) {
                                    $ch['slug'] = $new_slug;
                                    $modified = true;
                                }
                                if ($old_name !== $new_name) {
                                    $ch['val'] = $new_name;
                                    $modified = true;
                                }
                            }
                        }
                        unset($ch);
                    }
                    break;
            }

            if ($modified) {
                update_post_meta($post_id, '_spec_json', wp_slash(json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)));
                $updated_post_ids[] = $post_id;
            }
        }

        // 検索用JSON (all_characters_search.json) の対象キャラクター行を更新
        if (!empty($updated_post_ids)) {
            self::update_search_json_for_posts($updated_post_ids);
        }
    }

    // =================================================================
    // 5. 削除同期処理
    // =================================================================

    /**
     * ターム削除を該当キャラクターの _spec_json および 検索用JSON に反映
     */
    public static function sync_term_delete_to_characters($taxonomy, $term_id, $slug, $name, array $pre_cached_ids = []) {
        $target_ids = !empty($pre_cached_ids) ? $pre_cached_ids : self::find_characters_for_term($term_id, $taxonomy, $slug);
        if (empty($target_ids)) return;

        $updated_post_ids = [];

        foreach ($target_ids as $post_id) {
            $json_str = get_post_meta($post_id, '_spec_json', true);
            if (!$json_str) continue;

            $spec = json_decode($json_str, true);
            if (!is_array($spec)) continue;

            $modified = false;

            switch ($taxonomy) {
                case 'event':
                case 'suitable_quest':
                    $key = ($taxonomy === 'event') ? 'event' : 'suitable_quest';
                    if (!empty($spec[$key]) && is_array($spec[$key])) {
                        $before_count = count($spec[$key]);
                        $spec[$key] = array_values(array_filter($spec[$key], function ($item) use ($slug) {
                            return $item !== $slug;
                        }));
                        if (count($spec[$key]) !== $before_count) {
                            $modified = true;
                        }
                    }
                    break;

                case 'affiliation':
                    if (!empty($spec['groups']) && is_array($spec['groups'])) {
                        $before_count = count($spec['groups']);
                        $spec['groups'] = array_values(array_filter($spec['groups'], function ($g) use ($slug) {
                            return ($g['slug'] ?? '') !== $slug;
                        }));
                        if (count($spec['groups']) !== $before_count) {
                            $modified = true;
                        }
                    }
                    break;

                case 'attribute':
                    if (($spec['attribute'] ?? '') === $slug) {
                        $spec['attribute'] = '';
                        $modified = true;
                    }
                    if (!empty($spec['sub_attributes']) && is_array($spec['sub_attributes'])) {
                        $before_count = count($spec['sub_attributes']);
                        $spec['sub_attributes'] = array_values(array_filter($spec['sub_attributes'], function ($s) use ($slug) {
                            return $s !== $slug;
                        }));
                        if (count($spec['sub_attributes']) !== $before_count) {
                            $modified = true;
                        }
                    }
                    break;

                case 'species':
                    if (($spec['species'] ?? '') === $slug) {
                        $spec['species'] = '';
                        $modified = true;
                    }
                    break;

                case 'gimmick':
                    $modified = self::delete_gimmick_in_spec($spec, $slug);
                    break;

                case 'rarity':
                    if (($spec['rarity_detail'] ?? '') === $slug) {
                        $spec['rarity_detail'] = 'none';
                        $modified = true;
                    }
                    break;

                case 'available_moji':
                    if (!empty($spec['chars']) && is_array($spec['chars'])) {
                        $before_count = count($spec['chars']);
                        $spec['chars'] = array_values(array_filter($spec['chars'], function ($ch) use ($slug) {
                            return ($ch['slug'] ?? '') !== $slug;
                        }));
                        if (count($spec['chars']) !== $before_count) {
                            $modified = true;
                        }
                    }
                    break;
            }

            if ($modified) {
                update_post_meta($post_id, '_spec_json', wp_slash(json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)));
                $updated_post_ids[] = $post_id;
            }
        }

        // 検索用JSONの更新
        if (!empty($updated_post_ids)) {
            self::update_search_json_for_posts($updated_post_ids);
        }
    }

    // =================================================================
    // 6. ヘルパー関数: ギミック処理
    // =================================================================

    /**
     * spec内のギミック置換
     */
    private static function replace_gimmick_in_spec(&$spec, $old_slug, $new_slug) {
        $modified = false;
        $sections = ['trait1', 'trait2', 'blessing', 'traits'];

        foreach ($sections as $sec) {
            if (!isset($spec[$sec])) continue;

            if (isset($spec[$sec]['contents']) && is_array($spec[$sec]['contents'])) {
                foreach ($spec[$sec]['contents'] as &$c) {
                    if (($c['type'] ?? '') === 'gimmick' && ($c['sub_type'] ?? '') === $old_slug) {
                        $c['sub_type'] = $new_slug;
                        $modified = true;
                    }
                }
                unset($c);
            } elseif (is_array($spec[$sec])) {
                foreach ($spec[$sec] as &$c) {
                    if (is_array($c) && ($c['type'] ?? '') === 'gimmick' && ($c['sub_type'] ?? '') === $old_slug) {
                        $c['sub_type'] = $new_slug;
                        $modified = true;
                    }
                }
                unset($c);
            }
        }

        return $modified;
    }

    /**
     * spec内のギミック削除
     */
    private static function delete_gimmick_in_spec(&$spec, $slug) {
        $modified = false;
        $sections = ['trait1', 'trait2', 'blessing', 'traits'];

        foreach ($sections as $sec) {
            if (!isset($spec[$sec])) continue;

            if (isset($spec[$sec]['contents']) && is_array($spec[$sec]['contents'])) {
                $before_count = count($spec[$sec]['contents']);
                $spec[$sec]['contents'] = array_values(array_filter($spec[$sec]['contents'], function ($c) use ($slug) {
                    return !(($c['type'] ?? '') === 'gimmick' && ($c['sub_type'] ?? '') === $slug);
                }));
                if (count($spec[$sec]['contents']) !== $before_count) {
                    $modified = true;
                }
            } elseif (is_array($spec[$sec])) {
                $before_count = count($spec[$sec]);
                $spec[$sec] = array_values(array_filter($spec[$sec], function ($c) use ($slug) {
                    return !(is_array($c) && ($c['type'] ?? '') === 'gimmick' && ($c['sub_type'] ?? '') === $slug);
                }));
                if (count($spec[$sec]) !== $before_count) {
                    $modified = true;
                }
            }
        }

        return $modified;
    }

    // =================================================================
    // 7. 検索用JSON差分更新
    // =================================================================

    /**
     * all_characters_search.json の対象キャラクター行のみを更新（アトミック書き込み）
     */
    public static function update_search_json_for_posts(array $post_ids) {
        if (empty($post_ids)) return;

        $json_file_path = get_stylesheet_directory() . '/lib/character-search/all_characters_search.json';
        if (!file_exists($json_file_path)) return;

        $json_content = @file_get_contents($json_file_path);
        if (!$json_content) return;

        $all_data = json_decode($json_content, true);
        if (!is_array($all_data)) return;

        $id_map = [];
        foreach ($all_data as $idx => $char) {
            if (isset($char['id'])) {
                $id_map[(int)$char['id']] = $idx;
            }
        }

        $changed = false;
        foreach ($post_ids as $post_id) {
            $post_id = (int)$post_id;
            if (isset($id_map[$post_id])) {
                if (function_exists('koto_get_flat_char_data')) {
                    $flat_char = koto_get_flat_char_data($post_id);
                    if ($flat_char) {
                        $all_data[$id_map[$post_id]] = $flat_char;
                        $changed = true;
                    }
                }
            }
        }

        if ($changed) {
            $tmp_file = $json_file_path . '.tmp';
            $encoded = json_encode($all_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false && @file_put_contents($tmp_file, $encoded) !== false) {
                if (function_exists('koto_json_reform_replace_file_atomically')) {
                    koto_json_reform_replace_file_atomically($tmp_file, $json_file_path);
                } else {
                    @rename($tmp_file, $json_file_path);
                }
            }
        }
    }
}

Koto_Taxonomy_Sync::init();

