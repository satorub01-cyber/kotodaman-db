<?php
// =================================================================
//  コトダマン キャラクター詳細用 共通関数群
// =================================================================

/**
 * 1. 倍率詳細表のHTML生成
 */
function get_koto_multiplier_table_html($table_group, $target_break_lv = null, $show_break_col = false)
{
    if (empty($table_group) || empty($table_group['use_maltiplier_table'])) {
        return '';
    }

    $rows = $table_group['maltiplier_table'];
    if (empty($rows)) return '';

    // 表示行フィルタリング
    $display_rows = [];
    foreach ($rows as $row) {
        $row_break = isset($row['break_count']) ? $row['break_count'] : '';
        if ($target_break_lv !== null && $row_break !== '') {
            if ((int)$row_break !== (int)$target_break_lv) continue;
        }
        $display_rows[] = $row;
    }

    if (empty($display_rows)) return '';

    // ヘッダー作成
    $type = $table_group['multi_cond_type']; // enemy, moji, both, target
    $headers = [];
    if ($show_break_col) $headers[] = '凸数';
    if ($type === 'enemy' || $type === 'both') $headers[] = '敵の数';
    if ($type === 'moji' || $type === 'both')  $headers[] = '文字数';
    if ($type === 'target') $headers[] = '対象';
    $headers[] = '倍率・補正';

    ob_start();
?>
    <div class="skill-multiplier-table mt-bottom-style">
        <div class="mt-title">▼ 倍率詳細</div>
        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $th): ?>
                        <th><?php echo esc_html($th); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($display_rows as $row):
                    $rate_val = isset($row['rate']) ? $row['rate'] : '';
                    if ($rate_val === '') $rate_val = '(未入力)';
                ?>
                    <tr>
                        <?php if ($show_break_col):
                            $bk = isset($row['break_count']) ? $row['break_count'] : '';
                            $bk_text = ($bk !== '') ? $bk . '凸' : '-';
                        ?>
                            <td class="mt-break"><?php echo esc_html($bk_text); ?></td>
                        <?php endif; ?>

                        <?php if ($type === 'enemy' || $type === 'both'): ?>
                            <td><?php echo esc_html($row['enemy_count']); ?>体</td>
                        <?php endif; ?>

                        <?php if ($type === 'moji' || $type === 'both'): ?>
                            <td><?php echo esc_html($row['moji_count']); ?>文字</td>
                        <?php endif; ?>

                        <?php if ($type === 'target'):
                            $target_label = '';
                            if (!empty($table_group['advantage_target']) && function_exists('get_koto_target_label')) {
                                $target_label = str_replace('の味方', '', get_koto_target_label($table_group['advantage_target']));
                            }
                            if (!$target_label) $target_label = '特定対象';
                        ?>
                            <td><?php echo esc_html($target_label); ?></td>
                        <?php endif; ?>

                        <td class="mt-rate"><?php echo esc_html($rate_val); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php
    return ob_get_clean();
}

/**
 * 2. 文字追加情報の取得関数
 */
function get_koto_add_moji_html($trait_slug)
{
    // 投稿IDを取得できるよう、関数内で呼び出すか引数で渡すのが一般的ですが、get_fieldは現在の投稿を参照します
    $moji_loop = get_field('available_moji_loop');
    $html_parts = [];

    if ($moji_loop) {
        foreach ($moji_loop as $m) {
            if (isset($m['unlock_place']) && $m['unlock_place'] === $trait_slug) {
                $attr = isset($m['moji_attr']) ? $m['moji_attr'] : null;
                $slug = (is_object($attr) && isset($attr->slug)) ? $attr->slug : 'none';
                $chars = isset($m['available_moji']) ? $m['available_moji'] : null;

                $chars_array = [];
                if (is_array($chars)) $chars_array = $chars;
                elseif (is_object($chars)) $chars_array = [$chars];

                $pt_html = '';
                if ($trait_slug === 'blessing' && !empty($m['unlock_need_point'])) {
                    $pt_html = "<span class='blessing-pt'>({$m['unlock_need_point']}pt)</span>";
                }

                $current_row_chars = [];
                foreach ($chars_array as $c_obj) {
                    if (isset($c_obj->name)) {
                        $current_row_chars[] = '<span class="char-font attr-' . esc_attr($slug) . '">' . esc_html($c_obj->name) . '</span>';
                    }
                }

                if (!empty($current_row_chars)) {
                    $html_parts[] = implode('・', $current_row_chars) . $pt_html;
                }
            }
        }
    }
    if (!empty($html_parts)) {
        return '追加文字：' . implode('・', $html_parts);
    }
    return '';
}

/**
 * 3. 汎用とくせいテキスト生成関数
 */
function get_koto_trait_text_from_row($row)
{
    // ステータスマップ定義
    $status_map = ['poison' => '毒', 'sleep' => '睡眠', 'curse' => '呪い', 'confusion' => '混乱', 'pollution' => '汚染', 'burn' => '炎上', 'remodel' => '改造', 'weakness' => '衰弱', 'mutation' => '変異', 'erasure' => '消去', 'all' => '全て'];
    // 1. 発動条件
    $cond_parts = [];
    $cond_loop = isset($row['condition_type_loop']) ? $row['condition_type_loop'] : null;

    if ($cond_loop && is_array($cond_loop)) {
        foreach ($cond_loop as $cond_item) {
            $c_type = $cond_item['condition_type'];
            $c_val    = $cond_item['condition_value'];
            $c_attrs  = isset($cond_item['condition_attr']) ? $cond_item['condition_attr'] : null;
            $c_groups = isset($cond_item['condition_group']) ? $cond_item['condition_group'] : null;

            if ($c_type && $c_type !== 'none') {
                switch ($c_type) {
                    case 'comb':
                        // 「～コンボ以上」
                        if ($c_val) $cond_parts[] = "{$c_val}コンボ以上";
                        break;

                    case 'hpcond':
                        // 「HP〇〇%以上/以下」
                        if ($c_val) {
                            $hp_detail = isset($cond_item['hp_cond_detail']) ? $cond_item['hp_cond_detail'] : 'more';
                            $hp_label = '以上';
                            if ($hp_detail === 'less') {
                                $hp_label = '以下';
                            } elseif ($hp_detail === 'just') {
                                $hp_label = '';
                            }
                            $cond_parts[] = "HP{$c_val}%{$hp_label}";
                        }
                        break;

                    case 'attr':
                        // 「〇〇属性」
                        if ($c_attrs) {
                            $names = wp_list_pluck($c_attrs, 'name');
                            $cond_parts[] = implode('・', $names) . "属性";
                        }
                        break;

                    case 'group':
                        // 「デッキ内に〇〇がいる」
                        if ($c_groups) {
                            // ★追加: melody特例処理
                            $is_melody = false;
                            $check_groups = is_array($c_groups) ? $c_groups : [$c_groups];
                            foreach ($check_groups as $g) {
                                $g_slug = is_object($g) ? ($g->slug ?? '') : ($g['slug'] ?? '');
                                if ($g_slug === 'melody') {
                                    $is_melody = true;
                                    break;
                                }
                            }

                            if ($is_melody) {
                                $cond_parts[] = "デッキ内に「全の戦律」または「斬・砲・突・重・超・打の戦律」がいる";
                            } else {
                                $names = wp_list_pluck($c_groups, 'name');
                                $names = array_map(function ($item) {
                                    return "「{$item}」";
                                }, $names);
                                $cond_parts[] = "デッキ内に" . implode('・', $names) . "がいる";
                            }
                        }
                        break;

                    case 'moji_count':
                        $cond_val = $c_val ? $c_val : '（未入力）';
                        $cond_parts[] = "{$cond_val}文字以上";
                        break;

                    case 'theme':
                        // 「テーマ『〇〇』の言葉を作った」
                        if ($c_val) $cond_parts[] = "テーマ「{$c_val}」の言葉を作った";
                        break;

                    case 'char':
                        // 「文字『〇〇』」
                        if ($c_val) {
                            $clean_val = str_replace(array("\r", "\n"), '', $c_val);
                            $cond_parts[] = "文字「{$clean_val}」";
                        }
                        break;

                    case 'raid_battle':
                        // 「大討伐」
                        $cond_parts[] = "大討伐";
                        break;

                    case 'attacked':
                        // 「敵からの攻撃を受けた」
                        $cond_parts[] = "敵からの攻撃を受けた";
                        break;

                    case 'fuku_count':
                        // 「福〇〇以上」
                        $cond_parts[] = "福{$c_val}以上";
                        break;

                    case 'other':
                        // 自由入力
                        if ($c_val) $cond_parts[] = $c_val;
                        break;
                }
            }
        }
    }

    // -----------------------------------------------------------
    // 文末の自動判定ロジック
    // -----------------------------------------------------------
    $cond_text = '';

    if (!empty($cond_parts)) {
        // 条件を「かつ」でつなぐ
        $joined = implode('かつ', $cond_parts);

        // 最後の1文字を取得
        $last_char = mb_substr($joined, -1);

        // 「の」が必要な文字リスト
        $nouns = ['上', '下', '伐', 'み', 'き', '%', '回', '体', '性', '字', '」'];

        if (in_array($last_char, $nouns)) {
            $cond_text = $joined . 'のとき';
        } else {
            // 動詞系（受けた、いる、作った 等）
            $cond_text = $joined . 'とき';
        }
    }
    // 2. メイン効果
    $type = $row['trait_type'];
    $effect_text = '';

    $target_label = '';
    if (isset($row['target_field_group']) && function_exists('get_koto_target_label')) {
        $target_label = get_koto_target_label($row['target_field_group']);
    }
    $target_prefix = $target_label ? $target_label . 'の' : '';

    // 数値と単位
    $rate = isset($row['trait_rate']) ? $row['trait_rate'] : '';
    $rate_type = isset($row['rate_type']) ? $row['rate_type'] : '';
    $unit = ($rate_type === 'fixed') ? '' : '%';

    $per_unit_text = '';
    if (!empty($row['per_unit_tf'])) {
        $unit_target = '';
        if (isset($row['deck_ally_field_group']) && function_exists('get_koto_target_label')) {
            $unit_target = get_koto_target_label($row['deck_ally_field_group']);
            $unit_target = str_replace('の味方', '', $unit_target);
        }
        if (!$unit_target) $unit_target = '味方';
        $per_unit_text = "デッキ内の{$unit_target}1体につき";
    }

    $turn_val = isset($row['draw_triat_turn']) ? $row['draw_triat_turn'] : '';
    $turn_text = $turn_val ? "{$turn_val}ターンの間、" : "";

    switch ($type) {
        case 'gimmick':
            $term = $row['gimmick'];
            if ($term) {
                $gimmick_url = 'https://kotodaman-db.com/gimmick/' . esc_attr($term->slug);
                $effect_text = '<a href="' . $gimmick_url . '">' . esc_html($term->name) . '</a>';

                $super_txts = [];
                if ($row['super_gimmick_healing']) $super_txts[] = "回復量: {$row['super_gimmick_healing']}";
                if (strpos($term->name, 'ビリビリ') !== false) {
                    if (!empty($super_txts)) $effect_text .= " (" . implode(' / ', $super_txts) . ")";
                }
            }
            break;

        case 'damage_correction':
            $sub = $row['damage_correction'];
            $prefix = $per_unit_text ? $per_unit_text : '';

            if ($sub === 'oneself') $effect_text = "{$prefix}{$target_label}の威力{$rate}%UP";
            elseif ($sub === 'killer') $effect_text = "{$target_label}キラー{$rate}%";
            elseif ($sub === 'break_limit') {
                $limit = $row['limit_break_rate'];
                $effect_text = "{$prefix}自身のダメージ上限を+{$limit}";
            } elseif ($sub === 'single_shot') {
                $limit_txt = $row['limit_break_rate'] ? "、ダメージ上限を+{$row['limit_break_rate']}" : '';
                $effect_text = "{$target_prefix}単体単発攻撃のダメージ{$rate}%UP{$limit_txt}";
            } elseif ($sub === 'week_killer') {
                $effect_text = "{$prefix}自身が弱点を突いた時のダメージ{$rate}%UP";
            }
            break;

        case 'status_up':
            $sub = $row['status_up'];
            $prefix = $per_unit_text ? $per_unit_text : '';
            if ($sub !== 'mitigation') {
                if (!str_contains($target_prefix, '自身')) {
                    $target_prefix = 'デッキ内の' . $target_prefix;
                }
            }
            if ($sub === 'resistance') {
                $res = $row['resistance'];
                $res_name = isset($status_map[$res]) ? $status_map[$res] : $res;
                $effect_text = "{$target_prefix}{$prefix}{$res_name}耐性{$rate}{$unit}";
            } elseif ($sub === 'dodge') {
                $effect_text = "{$target_prefix}心眼回避{$rate}{$unit}";
            } elseif ($sub === 'mitigation') {
                $target_prefix = str_replace('の', '', $target_prefix);
                $effect_text = "{$prefix}{$target_prefix}から受けるダメージを{$rate}{$unit}軽減";
            } elseif ($sub === 'critical_rate') {
                $effect_text = "{$target_prefix}{$prefix}クリティカル率{$rate}{$unit}UP";
            } elseif ($sub === 'critical_damage') {
                $effect_text = "{$target_prefix}{$prefix}クリティカル時のダメージ{$rate}{$unit}UP";
            } elseif ($sub === 'healing_effect') {
                $effect_text = "{$target_prefix}{$prefix}受ける回復効果{$rate}{$unit}UP";
            } else {
                $param = strtoupper($sub);
                $effect_text = "{$prefix}{$target_prefix}{$param}{$rate}{$unit}UP";
            }
            break;
        case 'on_play_eff':
            $turn_text = '';
        case 'draw_eff':
            $timing = ($type === 'draw_eff') ? 'ドロー時' : '実体化時';
            $sub = ($type === 'draw_eff') ? $row['draw_eff'] : $row['on_play_eff'];

            $target_simple = str_replace('の味方', '', $target_label);
            if ($target_simple === '自身' || empty($target_simple)) $target_simple = '自身';
            else $target_simple = "{$target_simple}の味方";

            $prefix = $per_unit_text ? $per_unit_text : '';

            if (strpos($sub, 'buff') !== false) {
                $param = (strpos($sub, 'atk') !== false) ? 'ATK' : 'DEF';
                $effect_text = "{$timing}、{$prefix}{$target_simple}に{$turn_text}{$param}{$rate}段階バフを付与";
            } elseif ($sub === 'healling') {
                $effect_text = "{$timing}、HPを{$rate}{$unit}回復";
            } elseif ($sub === 'status_healing') {
                $res = isset($row['resistance']) ? $row['resistance'] : '';
                $res_name = isset($status_map[$res]) ? $status_map[$res] : $res;
                $res_text = $res_name ? "{$res_name}の" : "";
                $effect_text = "{$timing}、手札の味方の{$res_text}状態異常を回復";
            }
            break;

        case 'core_gimmick':
            $core = $row['core_gimmick'];
            $core_map = ['healing_core' => 'ヒール', 'attack_core' => 'アタック', 'super_attack_core' => 'スーパーアタック'];
            $core_name = isset($core_map[$core]) ? $core_map[$core] : $core;
            if ($core === 'super_attack_core') {
                $effect_text = "{$core_name}ギミック ";
                $c1 = $row['need_combo_first'];
                $c2 = $row['need_combo_second'];
                $c3 = $row['need_combo_third'];
                $c4 = $row['need_combo_forth'];
                if ($c1) $effect_text .= "自身のATK<br>・{$c1}コンボ：+1<br>・{$c2}コンボ：+2<br>・{$c3}コンボ：+3<br>・{$c4}コンボ：+4<br>段階バフ";
            } else {
                $need = $row['need_combo'];
                if ($core === 'healing_core') $effect_text = "自身を含む言葉で{$need}コンボ以上するとHPを{$rate}回復";
                if ($core === 'attack_core') $effect_text = "自身を含む言葉で{$need}コンボ以上すると敵全体に{$rate}の無属性ダメージ";
            }
            break;

        case 'after_attack':
            $sub = $row['after_attack'];
            switch ($sub) {
                case 'reflection':
                    $effect_text = "敵ターン終了時、敵全体に自身が受けた合計ダメージの{$rate}倍の無属性ダメージを与える";
                    break;
                case 'counter':
                    $effect_text = "攻撃を受けたとき{$rate}%の確率でわざを発動";
                    break;
                case 'sugo_counter':
                    $effect_text = "攻撃を受けたとき{$rate}%の確率ですごわざを発動";
                    break;
                case 'corruption':
                    empty($turn_text) ? '（未入力）' : $turn_text;
                    $effect_text = "わざ、すごわざ、コトわざで攻撃時、各ターゲットに腐敗（敵の行動時、このキャラがこのターン与えた合計ダメージの{$rate}%分の固定ダメージを与える効果）を{$turn_text}ターン付与する";
                    break;
            }
            break;

        case 'new_traits':
            $sub = $row['new_traits'];
            $new_map = ['support' => '応援', 'see_through' => '看破', 'assistance' => '援護', 'resonance' => '共鳴'];
            $name = isset($new_map[$sub]) ? $new_map[$sub] : $sub;
            $limit = $row['limit_break_rate'];

            if ($sub === 'see_through') {
                $effect_text = "{$name}：敵全体に1ターンの間{$rate}段階デバフ、受けるダメージの上限+{$limit}を付与";
            } elseif ($sub === 'support') {
                $effect_text = "{$name}：盤面の{$target_label}に{$rate}段階バフ";
            } elseif ($sub === 'assistance') {
                $effect_text = "{$name}：{$target_label}がすごわざを発動したとき、このコトダマンが盤面、手札にいないならわざを発動する";
            } elseif ($sub === 'resonance') {
                if (empty($row['resonance_crit_rate'])) {
                    $effect_text = "{$name}：自身と同じ文字のコトダマンが実体化している場合、これらのコトダマンの威力{$rate}%UP+ダメージ上限+{$limit}";
                } else {
                    $effect_text = "{$name}：自身と同じ文字のコトダマンが実体化している場合、これらのコトダマンのクリティカル率{$row['resonance_crit_rate']}%UP+クリティカルダメージ{$row['resonance_crit_damage']}%UP";
                }
            }
            break;

        case 'mode_shift':
            $rel = $row['relation_ship'];
            $forms = $row['related_form'];
            $names = [];
            if (!empty($forms)) {
                foreach ($forms as $f) {
                    if ($f->ID !== get_the_ID()) {
                        $permalink = get_permalink($f->ID);
                        $title = get_the_title($f->ID);
                        $names[] = '<a href="' . esc_url($permalink) . '">' . esc_html($title) . '</a>';
                    }
                }
            }
            $joined_names = implode('・', $names);
            if ($rel === 'mode_shift') $effect_text = "モードシフト先：{$joined_names}";
            elseif ($rel === 'another_image') {
                $joined_names = get_field('another_image_name');
                $effect_text = "モードシフト先：{$joined_names}";
            } elseif ($rel === 'before_transform') $effect_text = "変身先：{$joined_names}";
            else $effect_text = "変身前：{$joined_names}";
            break;

        case 'other_traits':
            $sub = $row['other_traits'];
            if ($sub === 'combo_plus') $effect_text = "コンボ＋{$rate}";
            elseif ($sub === 'other') $effect_text = $row['other_text'];
            elseif ($sub === 'penetration') $effect_text = "バリア貫通{$rate}{$unit}";
            elseif ($sub === 'over_healing') $effect_text = "{$target_prefix}オーバーヒールを得る";
            elseif ($sub === 'exp_up') $effect_text = "獲得経験値{$rate}{$unit}UP";
            elseif ($sub === 'pressure_break') {
                $limit = $row['limit_break_rate'];
                $effect_text = "重圧のダメージ上限を+{$limit}";
            }
            break;
    }

    if (!empty($row['attack_limit_count'])) {
        $effect_text .= " ({$row['attack_limit_count']}回まで)";
    }

    // =========================================================
    // ★追加：追加文字の取得処理 (番号指定 ＆ 自動判定 両対応)
    // =========================================================
    $found_chars_html = [];
    $all_moji_data = get_field('available_moji_loop'); // 基本データの文字リストを取得

    if ($all_moji_data) {
        // -------------------------------------------------
        // パターンA：番号指定がある場合 (例: "1, 3")
        // -------------------------------------------------
        $raw_indices = isset($row['super_gimmick_moji_index']) ? $row['super_gimmick_moji_index'] : '';

        if (!empty($raw_indices)) {
            $indices = [];
            // カンマ区切りを分解
            if (is_array($raw_indices)) {
                $indices = $raw_indices;
            } else {
                $parts = explode(',', (string)$raw_indices);
                foreach ($parts as $p) {
                    $val = (int)trim($p);
                    if ($val > 0) $indices[] = $val;
                }
            }

            // 指定番号の行を取得
            foreach ($indices as $char_index) {
                $target_key = $char_index - 1; // 配列は0始まりなので-1
                if (isset($all_moji_data[$target_key])) {
                    $m_row = $all_moji_data[$target_key];
                    // 文字HTML生成
                    $chars = isset($m_row['available_moji']) ? $m_row['available_moji'] : null;
                    $attr = isset($m_row['moji_attr']) ? $m_row['moji_attr'] : null;
                    $slug = (is_object($attr) && isset($attr->slug)) ? $attr->slug : 'none';

                    if ($chars) {
                        $chars_arr = is_array($chars) ? $chars : [$chars];
                        foreach ($chars_arr as $c) {
                            $found_chars_html[] = '<span class="char-font attr-' . esc_attr($slug) . '">' . esc_html($c->name) . '</span>';
                        }
                    }
                }
            }
        }
        // -------------------------------------------------
        // パターンB：番号がない場合 (自動判定)
        // -------------------------------------------------
        else {
            $target_unlock_key = '';

            // 1. ギミック名判定 (スーパーコピー/チェンジ)
            if ($row['trait_type'] === 'gimmick' && !empty($row['gimmick'])) {
                $gimmick_name = $row['gimmick']->name;
                if (strpos($gimmick_name, 'スーパーコピー') !== false) {
                    $target_unlock_key = 'super_copy';
                } elseif (strpos($gimmick_name, 'スーパーチェンジ') !== false) {
                    $target_unlock_key = 'super_change';
                }
            }
            // 2. その他テキスト判定 (祝福とくせい等)
            elseif (isset($row['other_traits']) && $row['other_traits'] === 'other' && !empty($row['other_text'])) {
                $target_unlock_key = $row['other_text'];
            }

            // キーに一致する行を探す
            if ($target_unlock_key) {
                foreach ($all_moji_data as $m_row) {
                    if (isset($m_row['unlock_place']) && $m_row['unlock_place'] === $target_unlock_key) {
                        // 文字HTML生成
                        $chars = isset($m_row['available_moji']) ? $m_row['available_moji'] : null;
                        $attr = isset($m_row['moji_attr']) ? $m_row['moji_attr'] : null;
                        $slug = (is_object($attr) && isset($attr->slug)) ? $attr->slug : 'none';

                        if ($chars) {
                            $chars_arr = is_array($chars) ? $chars : [$chars];
                            foreach ($chars_arr as $c) {
                                $found_chars_html[] = '<span class="char-font attr-' . esc_attr($slug) . '">' . esc_html($c->name) . '</span>';
                            }
                        }
                    }
                }
            }
        }
    }

    // -------------------------------------------------
    // 結果を結合して表示
    // -------------------------------------------------
    if (!empty($found_chars_html)) {
        // 重複削除して結合
        $unique_chars = array_unique($found_chars_html);
        $suffix = '<span class="added-moji-note"> (追加文字：' . implode('・', $unique_chars) . ')</span>';

        // 効果テキストの後ろに追加
        $effect_text .= $suffix;
    }

    // =========================================================
    // ★追加：他者への付与 (whose_trait) の処理
    // =========================================================

    // 1. まず「条件」と「効果」を結合して、本来のテキストを完成させる
    $final_text = $effect_text;
    if ($cond_text && $effect_text) {
        $final_text = $cond_text . '、' . $effect_text;
    }

    // 2. 付与対象 (whose_trait) が設定されているかチェック
    if ($final_text && !empty($row['whose_trait']) && function_exists('get_koto_target_label')) {
        // グループからラベル（例：火属性、自身）を取得
        $whose_label = get_koto_target_label($row['whose_trait']);

        // 対象が「自身」以外の場合のみ、特別な書き方に変換
        if ($whose_label && $whose_label !== '自身') {
            $target_name = $whose_label . 'の味方';
            if (empty($target_name)) $target_name = '全キャラ';

            // テキストをラップする
            $final_text = "とくせい「{$final_text}」をデッキ内の{$target_name}に付与";
        }
    }

    return koto_replace_icons($final_text);
}

/**
 * 4. わざ・すごわざ・コトワザのHTMLボディ生成 (waza_add_cond_loop 対応版)
 */
function get_koto_sugowaza_html($condition_data = null, $group_data, $skill_type = '')
{
    ob_start();

    // =========================================================
    // A. 発動条件 (青タグ)
    // =========================================================
    $or_texts = [];

    if (!empty($condition_data) && is_array($condition_data)) {
        foreach ($condition_data as $pattern) {
            // 1. 条件テキスト生成
            $conditions = isset($pattern['sugo_cond_loop']) ? $pattern['sugo_cond_loop'] : null;
            $and_texts = [];

            if ($conditions && is_array($conditions)) {
                foreach ($conditions as $cond) {
                    $type = $cond['sugo_cond_type'];
                    $val  = $cond['sugo_cond_val'];
                    $vals = explode(',', (string)$val);
                    $formatted_vals = array_map('trim', $vals);
                    $join_char = 'または';

                    switch ($type) {
                        case 'char_count':
                            $and_texts[] = "{$val}文字以上";
                            break;
                        case 'combo':
                            $and_texts[] = "{$val}コンボ以上";
                            break;
                        case 'theme':
                            $and_texts[] = "テーマ「{$val}」";
                            break;
                        case 'start_char':
                            $f_vals = array_map(function ($v) {
                                return "「{$v}」";
                            }, $formatted_vals);
                            $and_texts[] = "文字" . implode($join_char, $f_vals) . "〜で始まる";
                            break;
                        case 'end_char':
                            $f_vals = array_map(function ($v) {
                                return "「{$v}」";
                            }, $formatted_vals);
                            $and_texts[] = "文字" . implode($join_char, $f_vals) . "で終わる";
                            break;
                        case 'char_contain':
                            $f_vals = array_map(function ($v) {
                                return "「{$v}」";
                            }, $formatted_vals);
                            $and_texts[] = "文字" . implode($join_char, $f_vals) . "を含む";
                            break;
                    }
                }
            }

            // 2. 祝福解放の注釈チェック
            $place = isset($pattern['get_palce']) ? $pattern['get_palce'] : 'default';
            $blessing_suffix = '';

            if ($place === 'blessing') {
                $pt = isset($pattern['need_blessing_point']) ? $pattern['need_blessing_point'] : '';
                $pt_text = $pt ? "(pt:{$pt})" : "";
                $blessing_suffix = '<span style="color:#e79245; font-weight:bold; font-size:0.9em; margin-left:5px;">※祝福解放' . esc_html($pt_text) . '</span>';
            }

            // テキスト結合
            if (!empty($and_texts)) {
                $line_text = implode(' <span class="cond-and"> </span> ', $and_texts);
                $or_texts[] = $line_text . $blessing_suffix;
            }
        }
    }

    // 表示出力
    if (!empty($or_texts)) {
        echo '<div class="skill-row">';
        echo '<span class="label-tag tag-cond">条件</span>';
        echo '<div class="skill-text-block">';
        foreach ($or_texts as $line) {
            echo '<div class="condition-line" style="margin-bottom:4px;">';
            echo '<span class="skill-text">' . koto_replace_icons($line) . '</span>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }

    // =========================================================
    // B. 効果リスト (赤タグ) - waza_add_cond_loop 対応
    // =========================================================
    if ($group_data && is_array($group_data)) {

        $target_field = ($skill_type === 'kotowaza') ? 'koto_shift_type' : 'sugo_shift_type';
        $shift_type = get_field($target_field);
        $is_shift_mode = (!empty($shift_type) && $shift_type !== 'none');

        echo '<div class="skill-row"><span class="label-tag tag-effect">効果</span><div class="skill-text-block">';

        $grouped_data = [];
        foreach ($group_data as $group) {
            $header_text = '通常';
            $is_shift = false;

            if ($is_shift_mode) {
                switch ($shift_type) {
                    case 'attr':
                        $shift_attr = isset($group['sugo_shift_attr']) ? $group['sugo_shift_attr'] : null;
                        if ($shift_attr) {
                            $t_arr = is_array($shift_attr) ? $shift_attr : [$shift_attr];
                            $names = wp_list_pluck($t_arr, 'name');
                            $header_text = implode('・', $names) . '属性';
                            $is_shift = true;
                        }
                        break;
                    case 'moji':
                        $shift_moji = isset($group['sugo_shift_moji']) ? $group['sugo_shift_moji'] : null;
                        if ($shift_moji) {
                            $t_arr = is_array($shift_moji) ? $shift_moji : [$shift_moji];
                            $names = array_map(function ($t) {
                                return "「{$t->name}」";
                            }, $t_arr);
                            $header_text = implode('', $names);
                            $is_shift = true;
                        }
                        break;
                    case 'attacked':
                        $shift_val = isset($group['sugo_shift_attacked']) ? $group['sugo_shift_attacked'] : '';
                        if ($shift_val) {
                            $header_text = "{$shift_val}回以上被弾時";
                            $is_shift = true;
                        }
                        break;
                    case 'random':
                        $shift_val = isset($group['random_count']) ? $group['random_count'] : '';
                        if ($shift_val) {
                            $header_text = "ランダム[{$shift_val}]";
                            $is_shift = true;
                        }
                        break;
                }
            }

            $key = $header_text;
            if (!isset($grouped_data[$key])) {
                $grouped_data[$key] = ['text' => $header_text, 'is_shift' => $is_shift, 'items' => []];
            }

            // ★修正：条件リピーター (waza_add_cond_loop) の内容をすべて取得して配列化
            $parent_conds_list = [];
            $cond_loop = isset($group['waza_add_cond_loop']) ? $group['waza_add_cond_loop'] : [];

            if ($cond_loop && is_array($cond_loop)) {
                foreach ($cond_loop as $c_row) {
                    $parent_conds_list[] = [
                        'type' => $c_row['condition_type'] ?? '',
                        'val'  => $c_row['condition_value'] ?? '',
                        'attr' => $c_row['condition_attr'] ?? null,        // 属性
                        'aff'  => $c_row['condition_affiliation'] ?? null, // グループ
                        'species' => $c_row['condition_species'] ?? null, // 種族
                        'hp'   => $c_row['hp_cond_detail'] ?? 'more'       // HP詳細
                    ];
                }
            }

            $details = !empty($group['sugo_detail_loop']) ? $group['sugo_detail_loop'] : (!empty($group['waza_detail_loop']) ? $group['waza_detail_loop'] : []);
            if ($details) {
                foreach ($details as $item) {
                    // 各効果に、先ほど取得した「条件リスト」を持たせる
                    $item['_parent_cond'] = $parent_conds_list;
                    $grouped_data[$key]['items'][] = $item;
                }
            }
        }

        $circle_nums = ['①', '②', '③', '④', '⑤', '⑥', '⑦', '⑧', '⑨', '⑩'];
        $group_idx = 0;

        foreach ($grouped_data as $group_info) {
            $items = $group_info['items'];
            if (empty($items)) continue;

            if ($is_shift_mode) {
                $num_icon = $circle_nums[$group_idx] ?? ($group_idx + 1);
                echo '<div class="skill-group-header" style="margin-top: 0.8em; margin-bottom: 0.3em; font-weight:bold; color:#444; border-bottom:1px solid #eee; padding-bottom:2px;">';
                echo '<span class="circle-num" style="color:#d00; margin-right:5px; font-size:1.1em;">' . $num_icon . '</span>';
                echo '<span class="shift-text" style="color:#333;">' . esc_html($group_info['text']) . '</span>';
                echo '</div>';
            }

            $effect_counter = 1;
            $all_normal_effects = [];

            foreach ($items as $item) {
                // 条件テキスト生成 (★リピーター対応：複数の条件を結合)
                $cond_text_parts = [];
                $p_conds = isset($item['_parent_cond']) ? $item['_parent_cond'] : [];

                if ($p_conds && is_array($p_conds)) {
                    foreach ($p_conds as $p_cond) {
                        if (empty($p_cond['type']) || $p_cond['type'] === 'none') continue;

                        $cv = $p_cond['val'];
                        $part_text = "";

                        switch ($p_cond['type']) {
                            case 'char':
                                $part_text = "自身が「{$cv}」の時";
                                break;
                            case 'moji_count':
                                $part_text = "{$cv}文字以上の時";
                                break;
                            case 'comb':
                                $part_text = "{$cv}コンボ以上の時";
                                break;
                            case 'theme':
                                $part_text = "「{$cv}」テーマのことばを作った時";
                                break;
                            case 'attr':
                                // 属性条件
                                $attr_name = $cv;
                                if (!empty($p_cond['attr'])) {
                                    $objs = $p_cond['attr'];
                                    if (is_array($objs)) {
                                        $names = wp_list_pluck($objs, 'name');
                                    } elseif (is_object($objs)) {
                                        $names = [$objs->name];
                                    } else {
                                        $names = [];
                                    }
                                    if (!empty($names)) $attr_name = implode('・', $names);
                                }
                                $part_text = "同時に{$attr_name}属性のコトダマンがわざ・すごわざを発動した時";
                                break;
                            case 'species':
                                // 属性条件
                                $spe_name = $cv;
                                if (!empty($p_cond['species'])) {
                                    $objs = $p_cond['species'];
                                    if (is_array($objs)) {
                                        $names = wp_list_pluck($objs, 'name');
                                    } elseif (is_object($objs)) {
                                        $names = [$objs->name];
                                    } else {
                                        $names = [];
                                    }
                                    if (!empty($names)) $spe_name = implode('・', $names);
                                }
                                $part_text = "同時に{$spe_name}種族のコトダマンがわざ・すごわざを発動した時";
                                break;
                            case 'hpcond':
                                // HP条件
                                $hp_label = ($p_cond['hp'] === 'less') ? '以下' : '以上';
                                $part_text = "HP{$cv}%{$hp_label}の時";
                                break;
                            case 'group':
                                // グループ条件
                                $grp_name = $cv;
                                $is_melody = false; // ★追加

                                if (!empty($p_cond['aff'])) {
                                    $objs = $p_cond['aff'];

                                    // ★追加: melody判定
                                    $check_objs = is_array($objs) ? $objs : [$objs];
                                    foreach ($check_objs as $o) {
                                        $o_slug = is_object($o) ? ($o->slug ?? '') : ($o['slug'] ?? '');
                                        if ($o_slug === 'melody') {
                                            $is_melody = true;
                                            break;
                                        }
                                    }

                                    if (is_array($objs)) {
                                        $names = wp_list_pluck($objs, 'name');
                                    } elseif (is_object($objs)) {
                                        $names = [$objs->name];
                                    } else {
                                        $names = [];
                                    }
                                    if (!empty($names)) $grp_name = implode('・', $names);
                                }

                                if ($is_melody) {
                                    $part_text = "同時に「全の戦律」または「斬・砲・突・重・超・打の戦律」のコトダマンがわざ・すごわざを発動した時";
                                } else {
                                    $part_text = "同時に「{$grp_name}」のコトダマンがわざ・すごわざを発動した時";
                                }
                                break;
                            case 'attacked':
                                $part_text = "敵からの攻撃を{$cv}回受けた時";
                                break;
                            case 'other':
                                $part_text = "{$cv}の時";
                                break;
                        }

                        if ($part_text) {
                            $cond_text_parts[] = $part_text;
                        }
                    }
                }

                // 複数条件がある場合は「、かつ」などで繋ぐ（または単に読点）
                $cond_text = !empty($cond_text_parts) ? implode('、または', $cond_text_parts) . '、' : '';

                // 効果テキスト生成 (ここは既存ロジックのまま)
                $effect_text = "";
                $eff_type   = isset($item['waza_type']) ? $item['waza_type'] : '';
                $target_key = isset($item['waza_target']) ? $item['waza_target'] : '';
                $target_map = ['single_oppo' => '敵単体', 'all_oppo' => '敵全体', 'random_oppo' => 'ランダムな敵', 'all_ally' => '味方全体', 'limited_ally' => '味方', 'hand_ally' => '手札の味方全員', 'limited_hand' => '手札の味方', 'self' => '自身'];
                $base_target_name = isset($target_map[$target_key]) ? $target_map[$target_key] : '';

                $detail_type = isset($item['waza_target_detail']) ? $item['waza_target_detail'] : 'none';
                $detail_text = "";
                if ($target_key === 'limited_ally' || $target_key === 'limited_hand') {
                    if ($detail_type !== 'none') {
                        $suffix = ($detail_type === 'group') ? 'group' : $detail_type;
                        $target_field_name = 'target_detail_' . $suffix;
                        $terms = isset($item[$target_field_name]) ? $item[$target_field_name] : null;
                        $joined_names = '';
                        if ($terms) {
                            $term_array = is_array($terms) ? $terms : [$terms];
                            $names_list = wp_list_pluck($term_array, 'name');
                            if ($detail_type === 'group') {
                                $names_list = array_map(function ($n) {
                                    return "「{$n}」";
                                }, $names_list);
                            }
                            $joined_names = implode('・', $names_list);
                        } elseif ($detail_type === 'other') {
                            $joined_names = isset($item['target_detail_other']) ? $item['target_detail_other'] : '';
                        }
                        switch ($detail_type) {
                            case 'attr':
                                $detail_text = "{$joined_names}属性の";
                                break;
                            case 'species':
                                $detail_text = "{$joined_names}種族の";
                                break;
                            case 'group':
                                $detail_text = "{$joined_names}に属する";
                                break;
                            case 'other':
                                $detail_text = "{$joined_names}の";
                                break;
                        }
                    }
                }
                $target_name = $detail_text . $base_target_name;

                $eff_val = isset($item['waza_value']) ? $item['waza_value'] : '（未入力）';
                if (trim((string)$eff_val) === '') $eff_val = '（未入力）';
                $turn_count = isset($item['turn_count']) ? $item['turn_count'] : '';
                $turn_text = $turn_count ? "{$turn_count}ターンの間、" : "";
                $eff_attr_obj = isset($item['attack_attr']) ? $item['attack_attr'] : null;
                $attack_attr = (is_object($eff_attr_obj)) ? $eff_attr_obj->name : '未入力';
                $target_status_key = isset($item['target_status']) ? $item['target_status'] : '';
                $s_map = ['poison' => '毒', 'sleep' => '睡眠', 'curse' => '呪い', 'confusion' => '混乱', 'pollution' => '汚染', 'burn' => '炎上', 'remodel' => '改造', 'weakness' => '衰弱', 'mutation' => '変異', 'erasure' => '消去', 'all' => '全て'];
                $st_name = isset($s_map[$target_status_key]) ? $s_map[$target_status_key] : $target_status_key;

                if (!empty($eff_type)) {
                    switch ($eff_type) {
                        case 'attack':
                        case 'converged_attack':
                            $raw_types = isset($item['attack_type']) ? $item['attack_type'] : [];
                            $saidai_text = '';
                            if (!is_array($raw_types)) $raw_types = [$raw_types];
                            foreach ($raw_types as $raw) {
                                if ($raw !== 'normal' && $raw !== 'target') $saidai_text = '最大';
                                break;
                            }
                            $modifiers = [];
                            $is_converged = false;
                            $is_target_adv = false;
                            $needs_link = false;

                            foreach ($raw_types as $t) {
                                if ($t === 'converged' || $eff_type === 'converged_attack') {
                                    $is_converged = true;
                                    $needs_link = true;
                                } elseif ($t === 'moji') {
                                    $modifiers[] = '文字数';
                                    $needs_link = true;
                                } elseif ($t === 'fuku') {
                                    $modifiers[] = '福';
                                } elseif ($t === 'target') {
                                    $is_target_adv = true;
                                }
                            }

                            $atk_noun = $is_converged ? "収束攻撃" : "攻撃";
                            $mod_text = "";
                            if (!empty($modifiers)) $mod_text = implode('・', $modifiers) . "に応じた";

                            if ($is_target_adv) {
                                $adv_label = "対象";
                                if (!empty($item['advantage_target']) && function_exists('get_koto_target_label')) {
                                    $t_label = str_replace('の味方', '', get_koto_target_label($item['advantage_target']));
                                    if ($t_label) $adv_label = $t_label;
                                }
                                $target_adv_text = "（{$adv_label}へは{$item['advantage_rate']}倍の）";
                                $mod_text = $mod_text ? "{$mod_text}、{$target_adv_text}" : $target_adv_text;
                            }

                            $attr_text = "{$attack_attr}属性";
                            $is_omni = !empty($item['omni_advantage']);
                            $omni_text = $is_omni ? "全属性に有利な" : "";
                            $base_phrase = "{$saidai_text}{$eff_val}倍の{$omni_text}{$mod_text}{$attr_text}{$atk_noun}";

                            $hit_count = isset($item['hit_count']) ? $item['hit_count'] : 1;
                            $val_last  = isset($item['waza_value_last']) ? $item['waza_value_last'] : 0;

                            if ($hit_count > 1) {
                                if ($val_last > 0) {
                                    $loop = $hit_count - 1;
                                    $effect_text = "{$target_name}に{$base_phrase}×{$loop}回＋{$val_last}倍の{$omni_text}{$attr_text}攻撃";
                                } else {
                                    $effect_text = "{$target_name}に{$base_phrase}×{$hit_count}回";
                                }
                            } else {
                                $effect_text = "{$target_name}に{$base_phrase}";
                            }

                            if ($needs_link) {
                                $anchor_id = '';
                                if ($skill_type === 'waza') $anchor_id = '#rate-table-waza';
                                elseif ($skill_type === 'sugo') $anchor_id = '#rate-table-sugo';
                                elseif ($skill_type === 'kotowaza') $anchor_id = '#rate-table-kotowaza';
                                if ($anchor_id) $effect_text .= ' <a href="' . $anchor_id . '" class="detail-ref-link">（詳細はこちら）</a>';
                            }
                            break;

                        case 'colorfull_attack':
                            $order_names = [];
                            
                            // A. JSONデータ (slug配列) がある場合
                            if (!empty($item['color_sequence']) && is_array($item['color_sequence'])) {
                                foreach ($item['color_sequence'] as $slug) {
                                    $t = get_term_by('slug', $slug, 'attribute');
                                    if ($t && !is_wp_error($t)) $order_names[] = $t->name;
                                }
                            }
                            // B. ACF生データ (オブジェクト配列) がある場合 (フォールバック)
                            elseif (!empty($item['colorfull_attack_attr']) && is_array($item['colorfull_attack_attr'])) {
                                foreach ($item['colorfull_attack_attr'] as $o) {
                                    if (is_object($o)) $order_names[] = $o->name;
                                }
                            }
                            
                            $order_text = implode('・', $order_names);
                            $effect_text = "{$target_name}に{$eff_val}倍の{$order_text}属性の各一回攻撃";
                            break;
                        case 'coop_attack':
                            $coop_grp = isset($item['coop_target']) ? $item['coop_target'] : null;
                            $grp_name = '（未設定）';
                            if ($coop_grp) {
                                if (is_array($coop_grp)) {
                                    $names = wp_list_pluck($coop_grp, 'name');
                                    $grp_name = '「' . implode('・', $names) . '」';
                                } elseif (is_object($coop_grp) && isset($coop_grp->name)) {
                                    $grp_name = "「{$coop_grp->name}」";
                                }
                            }
                            $is_omni = !empty($item['omni_advantage']);
                            $omni_text = $is_omni ? "全属性に有利な" : "";
                            $effect_text = "{$target_name}に{$eff_val}倍の{$omni_text}、わざ・すごわざを発動した{$grp_name}と同じ属性で攻撃";
                            break;
                        case 'command':
                            $effect_text = "わざ・すごわざを発動した味方が{$target_name}に{$eff_val}倍の{$attack_attr}属性攻撃";
                            break;
                        case 'waza_command':
                            $effect_text = "わざ・すごわざを発動した味方がわざを発動";
                            break;
                        case 'token':
                            $tokens = isset($item['related_token']) ? $item['related_token'] : [];
                            $t_names = [];
                            if ($tokens && is_array($tokens)) {
                                foreach ($tokens as $t) $t_names[] = get_the_title($t->ID);
                            }
                            $t_str = implode('・', $t_names);
                            $effect_text = "{$t_str}を生成する";
                            break;
                        case 'pressure':
                            $debuffs = isset($item['pressure_debuff_count']) ? $item['pressure_debuff_count'] : '';
                            $debuffs = str_replace(',', '、', $debuffs);
                            $effect_text = "{$target_name}に重圧{$eff_val}（敵ターン開始時に{$debuffs}段階デバフ）を付与";
                            break;
                        case 'impersonation':
                            $effect_text = "ひとつ前の味方のすごわざを発動";
                            break;
                        case 'heal':
                            $effect_text = "HPをATK×{$eff_val}回復";
                            break;
                        case 'atk_buff':
                        case 'atk_debuff':
                        case 'def_buff':
                        case 'def_debuff':
                            $param = (strpos($eff_type, 'atk') !== false) ? 'ATK' : 'DEF';
                            $prefix = (strpos($eff_type, 'debuff') === false) ? 'バフ' : 'デバフ';
                            if (strpos($target_name, '手札') !== false || strpos($target_name, '敵') !== false) {
                                $effect_text = "{$target_name}に{$turn_text}{$param}{$eff_val}段階{$prefix}を付与";
                            } else {
                                $effect_text = "{$target_name}に{$param}{$eff_val}段階{$prefix}を付与";
                            }
                            break;
                        case 'taunt':
                            $effect_text = "{$target_name}にターゲット集中{$eff_val}を付与";
                            break;
                        case 'barrier':
                            $effect_text = "{$target_name}に1回ダメージを無効化するバリアを展開";
                            break;
                        case 'status_barrier':
                            $effect_text = "{$target_name}に{$st_name}を無効化するバリアを展開";
                            break;
                        case 'battle_field':
                            $field_text = [];
                            if (!empty($item['battle_field_loop']) && is_array($item['battle_field_loop'])) {
                                foreach ($item['battle_field_loop'] as $field_item) {
                                    $category = $field_item['battle_field_target'];
                                    $value = $field_item['battle_field_value'];
                                    $suffix = '';
                                    $raw_targets = null;
                                    switch ($category) {
                                        case 'attribute':
                                            $raw_targets = $field_item['battle_field_attr'];
                                            $suffix = '属性';
                                            break;
                                        case 'species':
                                            $raw_targets = $field_item['battle_field_species'];
                                            $suffix = '種族';
                                            break;
                                        case 'affiliation':
                                            $raw_targets = $field_item['battle_field_affiliation'];
                                            $suffix = '';
                                            break;
                                        case 'moji':
                                            $raw_targets = $field_item['battle_field_moji'];
                                            $suffix = '';
                                            break;
                                    }
                                    $names = [];
                                    if ($raw_targets) {
                                        if (is_array($raw_targets)) $names = wp_list_pluck($raw_targets, 'name');
                                        elseif (is_object($raw_targets)) $names = [$raw_targets->name];
                                    }
                                    if (!empty($names)) {
                                        $disp = ($category === 'group' || $category === 'moji') ? implode('', array_map(function ($n) {
                                            return "「{$n}」";
                                        }, $names)) : implode('・', $names) . $suffix;
                                        $field_text[] = "{$disp}の火力{$value}%UP";
                                    }
                                }
                            }
                            $d_text = implode('、', $field_text);
                            $effect_text = "{$turn_text}" . ($d_text ? "{$d_text}のフィールドを展開" : "フィールドを展開");
                            break;
                    }
                }

                if (!empty($item['moji_exhaust'])) $effect_text .= "（この文字は失効する）";
                $cond_text = koto_replace_icons($cond_text);
                $effect_text = koto_replace_icons($effect_text);
                if ($effect_text) {
                    if ($is_shift_mode) {
                        echo "<div class='skill-effect-line' style='margin-left: 1em;'>";
                        echo "<span class='effect-num'>({$effect_counter}) </span>";
                        echo "{$cond_text}{$effect_text}";
                        echo "</div>";
                        $effect_counter++;
                    } else {
                        $key = empty($cond_text) ? 'base' : $cond_text;
                        $all_normal_effects[$key][] = $effect_text;
                    }
                }
            }

            $group_idx++;
        }

        if (!$is_shift_mode && !empty($all_normal_effects)) {
            $counter = 1;
            foreach ($all_normal_effects as $cond_key => $effects) {
                $cond_key = koto_replace_icons($cond_key);
                $effects = koto_replace_icons($effects);
                $combined = implode('＋', $effects);
                echo "<div class='skill-effect-line'><span class='effect-num'>({$counter}) </span>";
                if ($cond_key !== 'base') echo "<span class='skill-sub-cond-text'>{$cond_key}</span> ";
                echo "{$combined}</div>";
                $counter++;
            }
        }

        echo '</div></div>';
    }
    return ob_get_clean();
}
/**
 * 5. リーダーとくせいHTML生成関数 (新規作成)
 */
function get_koto_leader_skill_html($post_id = null)
{
    if (!$post_id) $post_id = get_the_ID();
    $ls_patterns = get_field('ls_loop', $post_id);

    if (empty($ls_patterns)) return '';

    $status_map = ['poison' => '毒', 'sleep' => '睡眠', 'curse' => '呪い', 'confusion' => '混乱', 'pollution' => '汚染', 'burn' => '炎上', 'remodel' => '改造', 'weakness' => '衰弱', 'mutation' => '変異', 'erasure' => '消去', 'all' => '全て'];

    $ls_text = [];
    $count = 1;

    foreach ($ls_patterns as $pattern) {
        // 1. 対象キャラ文
        $target_parts = [];
        if (!empty($pattern['ls_target_chara_loop'])) {
            foreach ($pattern['ls_target_chara_loop'] as $target_row) {
                if (function_exists('get_koto_target_label')) {
                    $label = get_koto_target_label($target_row['target_field_group']);
                    if ($label) $target_parts[] = $label;
                }
            }
        }
        $target_raw = implode('・', $target_parts);
        $target_text = !empty($target_raw) ? '<span class="ls-target">' . esc_html($target_raw) . '</span>は' : '<span class="ls-target">味方全体</span>は';

        // 2. 条件文
        $or_parts = [];
        if (!empty($pattern['ls_cond_pattern_loop'])) {
            foreach ($pattern['ls_cond_pattern_loop'] as $cond_pat) {
                $and_parts = [];
                if (!empty($cond_pat['ls_cond_loop'])) {
                    foreach ($cond_pat['ls_cond_loop'] as $cond) {
                        $type = $cond['ls_cond_type'];
                        $val  = $cond['ls_cond_val'];
                        switch ($type) {
                            case 'combo':
                                if ($val) $and_parts[] = "{$val}コンボ以上の時";
                                break;
                            case 'moji_count':
                                if ($val) $and_parts[] = "{$val}文字以上の時";
                                break;
                            case 'theme':
                                if ($val) $and_parts[] = "テーマ「{$val}」の言葉を作ると";
                                break;
                            case 'moji_contain':
                                if ($val) {
                                    $normalized_val = str_replace('，', ',', $val);
                                    $chars = array_filter(array_map('trim', explode(',', $normalized_val)));
                                    $joined_text = implode('・', $chars);
                                    if ($joined_text) $and_parts[] = (count($chars) > 1) ? "「{$joined_text}」のいずれかを含む言葉を作ると" : "「{$joined_text}」を含む言葉を作ると";
                                }
                                break;
                            case 'chara_num':
                                if (!empty($cond['ls_party_cond_loop'])) {
                                    $party_parts = [];
                                    foreach ($cond['ls_party_cond_loop'] as $p_cond) {
                                        if (function_exists('get_koto_target_label')) {
                                            $p_label = get_koto_target_label($p_cond['target_field_group']);
                                            $p_num   = $p_cond['need_chara_num'];
                                            $is_total = !empty($p_cond['total_tf']);
                                            if ($p_label && $p_num) {
                                                if ($is_total) {
                                                    $party_parts[] = "{$p_label}が合計{$p_num}体以上";
                                                } else {
                                                    $suffix = (mb_strpos($p_label, '・') !== false) ? '各' : '';
                                                    $party_parts[] = "{$p_label}が{$suffix}{$p_num}体以上";
                                                }
                                            }
                                        }
                                    }
                                    if (!empty($party_parts)) $and_parts[] = "デッキに" . implode('、', $party_parts) . "いると";
                                }
                                break;
                            case 'wave_count':
                                $and_parts[] = "WAVEが進むごとに";
                                $limit_wave = isset($pattern['limit_wave_count']) ? $pattern['limit_wave_count'] : '';
                                if ($limit_wave) $and_parts[] = "({$limit_wave}WAVEまで)";
                                break;
                            case 'cooperate':
                                $cooperater = [];
                                if (!empty($cond['cooperate_target_loop'])) {
                                    foreach ($cond['cooperate_target_loop'] as $target_obj) {
                                        $cooperater[] = get_koto_target_label($target_obj);
                                    }
                                }
                                $cooperater_text = implode('と', $cooperater) . 'の味方';
                                $and_parts[] = "{$cooperater_text}が同時に文字を作った時";
                                break;
                        }
                    }
                }
                if (!empty($and_parts)) $or_parts[] = implode('、', $and_parts);
            }
        }
        $condition_text = !empty($or_parts) ? implode(' または ', $or_parts) . '、' : '';

        // 3. 効果文
        $effect_parts = [];
        $ls_type = $pattern['ls_type'];
        $effect_text = '';

        if ($ls_type === 'exp_up') {
            $mag = $pattern['exp_magnification'];
            if ($mag) $effect_parts[] = "獲得経験値{$mag}%UP";
            $target_text = '';
        } elseif ($ls_type === 'converged') {
            $conv_rate_1 = $pattern['converge_rate_1'] ? $pattern['converge_rate_1'] : '(未入力)';
            $conv_rate_2 = $pattern['converge_rate_2'] ? $pattern['converge_rate_2'] : '(未入力)';
            if (!empty($conv_rate_1) && !empty($conv_rate_2)) {
                $adjusted_target_text = str_replace('は', '', $target_text);
                $target_text = '';
                $effect_parts[] = "{$adjusted_target_text}の全体攻撃に収束効果を付与<br>一体：{$conv_rate_1}倍、二体：{$conv_rate_2}倍";
            }
        } elseif ($ls_type === 'over_healing') {
            $effect_parts[] = "HP上限を超えて回復した時、超過した分だけ固定ダメージ";
        } elseif ($ls_type === 'over_attack') {
            $buff_count = $pattern['buff_count'];
            $adjusted_target_text = str_replace('は', '', $target_text);
            $effect_parts[] = "攻撃が10回を超えた次のターン開始時、手札の{$adjusted_target_text}のATKを、2ターンの間{$buff_count}段階バフ";
            $target_text = '';
        } elseif ($ls_type === 'per_unit') {
            if (!empty($pattern['per_unit_loop'])) {
                foreach ($pattern['per_unit_loop'] as $pu_row) {
                    $p_label = '味方';
                    if (function_exists('get_koto_target_label')) {
                        $p_label = str_replace(['の味方'], '', get_koto_target_label($pu_row['target_field_group']));
                    }
                    if ($p_label === '味方全体') $p_label = '全キャラ';
                    if (!empty($pu_row['ls_status_loop'])) {
                        foreach ($pu_row['ls_status_loop'] as $status) {
                            $s_type = $status['ls_status'];
                            $s_rate = isset($status['rate']) && $status['rate'] !== '' ? $status['rate'] : '（未入力）';
                            if ($s_rate !== '') {
                                switch ($s_type) {
                                    case 'hp':
                                        $effect_parts[] = "HPをデッキ内の{$p_label}×{$s_rate}%UP";
                                        break;
                                    case 'atk':
                                        $effect_parts[] = "ATKをデッキ内の{$p_label}×{$s_rate}%UP";
                                        break;
                                    case 'mitigation':
                                        $effect_parts[] = "受けるダメージをデッキ内の{$p_label}×{$s_rate}%軽減";
                                        break;
                                    case 'resistance':
                                        $r_type  = isset($status['resist_status']) ? $status['resist_status'] : '';
                                        $r_label = isset($status_map[$r_type]) ? $status_map[$r_type] : $r_type;
                                        $effect_parts[] = "{$r_label}耐性をデッキ内の{$p_label}×{$s_rate}%";
                                        break;
                                    case 'crit_rate':
                                        $effect_parts[] = "クリティカル率をデッキ内の{$p_label}×{$s_rate}%UP";
                                        break;
                                    case 'crit_damage':
                                        $effect_parts[] = "クリティカルダメージをデッキ内の{$p_label}×{$s_rate}%UP";
                                        break;
                                }
                            }
                        }
                    }
                }
            }
        } else { // fixed
            if (!empty($pattern['ls_status_loop'])) {
                foreach ($pattern['ls_status_loop'] as $status) {
                    $s_type = $status['ls_status'];
                    $s_rate = isset($status['rate']) && $status['rate'] !== '' ? $status['rate'] : '（未入力）';
                    if ($s_rate !== '') {
                        switch ($s_type) {
                            case 'hp':
                                $effect_parts[] = "HP{$s_rate}%UP";
                                break;
                            case 'atk':
                                $effect_parts[] = "ATK{$s_rate}%UP";
                                break;
                            case 'mitigation':
                                $effect_parts[] = "受けるダメージを{$s_rate}%軽減";
                                break;
                            case 'resistance':
                                $r_type  = isset($status['resist_status']) ? $status['resist_status'] : '';
                                $r_label = isset($status_map[$r_type]) ? $status_map[$r_type] : $r_type;
                                $effect_parts[] = "{$r_label}耐性{$s_rate}%";
                                break;
                            case 'crit_rate':
                                $effect_parts[] = "クリティカル率を{$s_rate}%UP";
                                break;
                            case 'crit_damage':
                                $effect_parts[] = "クリティカルダメージを{$s_rate}%UP";
                                break;
                        }
                    }
                }
            }
        }
        if (empty($effect_text)) $effect_text = implode('、', $effect_parts);
        $ls_text[] = '<span class ="effect-num">（' . $count . '）</span>' . $condition_text . $target_text . $effect_text;
        $count++;
    }

    return koto_replace_icons(implode('<br>', $ls_text));
}

/**
 * 6. 祝福とくせい用：すごわざ条件解放リスト取得関数
 */
function get_koto_blessing_sugo_list($post_id = null)
{
    if (!$post_id) $post_id = get_the_ID();
    $cond_rows = get_field('sugowaza_condition', $post_id);
    $results = [];

    if ($cond_rows) {
        foreach ($cond_rows as $row) {
            // 解放場所が 'blessing' かチェック
            // ※フィールド名は get_palce (placeのタイポ) のまま対応
            if (isset($row['get_palce']) && $row['get_palce'] === 'blessing') {
                $cond_texts = [];
                $loop = isset($row['sugo_cond_loop']) ? $row['sugo_cond_loop'] : [];
                if ($loop) {
                    foreach ($loop as $item) {
                        $type = $item['sugo_cond_type'];
                        $val  = $item['sugo_cond_val'];
                        switch ($type) {
                            case 'char_count':
                                $cond_texts[] = "{$val}文字以上";
                                break;
                            case 'combo':
                                $cond_texts[] = "{$val}コンボ以上";
                                break;
                            case 'theme':
                                $cond_texts[] = "テーマ「{$val}」";
                                break;
                            case 'start_char':
                                $cond_texts[] = "「{$val}」から始まる";
                                break;
                            case 'end_char':
                                $cond_texts[] = "「{$val}」で終わる";
                                break;
                            case 'char_contain':
                                $cond_texts[] = "「{$val}」を含む";
                                break;
                        }
                    }
                }
                $cond_str = !empty($cond_texts) ? implode('、', $cond_texts) : '条件';

                $pt = isset($row['need_blessing_point']) ? $row['need_blessing_point'] : '';
                $pt_html = $pt ? "<span class='blessing-pt'>({$pt}pt)</span>" : "";

                $results[] = "すごわざ条件追加：{$cond_str} {$pt_html}";
            }
        }
    }
    return $results;
}
?>