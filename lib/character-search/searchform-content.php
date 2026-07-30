<!-- TODO小さくたためるようにする -->
<form role="search" method="get" id="searchform" class="searchform js-search-form" action="<?php echo home_url('/'); ?>">
    <input type="hidden" name="post_type" value="character" />
    <?php
    $current_target = isset($_GET['string_match_target']) ? $_GET['string_match_target'] : 'OR';
    $is_moji = ($current_target === 'AND');
    $is_search_page = is_search() && get_query_var('post_type') === 'character';
    ?>
    <div class="search-wrapper">
        <div class="search-row-top">
            <input type="text" value="<?php echo get_search_query(); ?>" name="s" id="s" placeholder="キャラ名・グループ名・ギミック名・状態異常耐性..." class="js-freeword-search" <?php if ($is_moji) echo 'style="display:none;"'; ?> />
            <input type="text" name="search_char" class="js-search-char-input"
                value="<?php echo isset($_GET['search_char']) ? esc_attr($_GET['search_char']) : ''; ?>"
                placeholder="ほしい文字を入力" <?php if (!$is_moji) echo 'style="display:none;"'; ?> />
            <button type="submit" class="submit-btn">検索</button>
        </div>
        <div style="display:none;">
            <?php
            render_simple_relation_toggle('search_char', 'AND');
            ?>
        </div>
        <div class="search-configs">
            <?php if ($is_search_page): ?>
                <div class="config-item">
                    <button type="button" class="js-minimize-search-btn" aria-label="検索バーを最小化/最大化"><span>−</span></button>
                </div>
            <?php endif; ?>
            <div class="config-item">
                <?php echo render_ios_toggle('string_match_target', $current_target, 'フリーワード', '文字'); ?>
            </div>
            <div class="config-item">
                <div class="search-row-bottom">
                    <button type="button" class="toggle-btn js-toggle-advanced-search" aria-label="詳細検索を開く" style="width:100%;">
                        <span class="filter-icon">🔍</span> 詳細フィルターを開く
                    </button>
                    <button type="button" class="reset-btn js-reset-search-btn">条件クリア</button>
                </div>
            </div>
        </div>
    </div>

    <div class="search-modal-overlay js-search-modal-overlay" style="display: none;">
        <div class="search-modal-content">
            <div class="search-modal-header">
                <h2 class="modal-title">詳細検索</h2>
                <div class="modal-header-btns">
                    <button type="button" class="js-modal-reset-search-btn">条件クリア</button>
                    <button type="button" class="modal-close-btn js-close-modal-btn">✕</button>
                </div>
            </div>
            <div class="tab-select-container">
                <ul class="tab-select-area">
                    <li class="area-select-button is-active" data-target="tab-basic">基本情報</li>
                    <li class="area-select-button" data-target="tab-waza">わざ</li>
                    <li class="area-select-button" data-target="tab-trait">とくせい</li>
                    <li class="area-select-button" data-target="tab-leader">リーダー</li>
                </ul>
            </div>

            <div class="search-modal-body" id="advanced-search-panel">
                <div class="tab-content is-active" data-content="tab-basic">
                    <div class="search-section">
                        <input type="checkbox" name="include_trait_status_resistance" value="1" <?php checked(isset($_GET['include_trait_status_resistance']) && $_GET['include_trait_status_resistance'] === '1'); ?>>
                        <span>自由入力で個別とくせい由来の状態異常耐性も含める</span>
                        <p class="section-title">文字の軸
                            <?php
                            render_simple_relation_toggle('tx_axis');
                            ?>
                        </p>
                        <div class="simple-tag-row">
                            <label><input type="checkbox" name="tx_axis[]" value="axis_i"> <span>イ軸</span></label>
                            <label><input type="checkbox" name="tx_axis[]" value="axis_u"> <span>ウ軸</span></label>
                            <label><input type="checkbox" name="tx_axis[]" value="axis_youon"> <span>ゃゅょ軸</span></label>
                            <label><input type="checkbox" name="tx_axis[]" value="char_connector"> <span>つなげ文字</span></label>
                            <label><input type="checkbox" name="tx_axis[]" value="char_small_yuyo"> <span>小さいゆよ</span></label>
                        </div>
                    </div>

                    <div class="search-section">
                        <div class="section-title">属性
                            <?php
                            render_simple_relation_toggle('tx_attr');
                            ?>
                        </div>
                        <p class="section-sub-title">副属性</p>
                        <?php
                        echo render_ios_toggle('tx_attr_sub', isset($_GET['tx_attr_sub']) ? $_GET['tx_attr_sub'] : 'OR', '含む', '含まない', 40, false);
                        ?>
                        <?php if (function_exists('render_simple_checkbox_list')) render_simple_checkbox_list('attribute', 'tx_attr', true); ?>
                    </div>

                    <div class="search-section">
                        <div class="section-title">種族
                            <?php
                            render_simple_relation_toggle('tx_species');
                            ?>
                        </div>
                        <?php if (function_exists('render_simple_checkbox_list')) render_simple_checkbox_list('species', 'tx_species', true); ?>
                    </div>

                    <div class="search-section">
                        <p class="section-title">入手方法
                            <?php
                            render_simple_relation_toggle('tx_acq');
                            ?>
                        </p>
                        <div class="simple-tag-row">
                            <label><input type="checkbox" name="tx_acq[]" value="ガチャ"> <span>ガチャ</span></label>
                            <label><input type="checkbox" name="tx_acq[]" value="その他"> <span>その他</span></label>
                        </div>
                    </div>

                    <div class="search-divider"></div>

                    <details class="tree-accordion">
                        <summary class="tree-summary">所属・グループを選択</summary>
                        <div class="tree-content">
                            <?php if (function_exists('render_frontend_term_tree')) render_frontend_term_tree('affiliation', 'tx_group'); ?>
                        </div>
                    </details>

                    <details class="tree-accordion">
                        <summary class="tree-summary">実装イベントを選択</summary>
                        <div class="tree-content">
                            <?php if (function_exists('render_frontend_term_tree')) render_frontend_term_tree('event', 'tx_event'); ?>
                        </div>
                    </details>
                    <details class="tree-accordion">
                        <summary class="tree-summary">適正クエストを選択</summary>
                        <div class="tree-content">
                            <?php if (function_exists('render_frontend_term_tree')) render_frontend_term_tree('suitable_quest', 'tx_quest'); ?>
                        </div>
                    </details>

                    <details class="tree-accordion">
                        <summary class="tree-summary">ギミック耐性を選択</summary>
                        <div class="tree-content">
                            <?php if (function_exists('render_frontend_term_tree')) render_frontend_term_tree('gimmick', 'tx_gimmick', ['open_all' => true, 'and_or' => 'AND', 'parent_sync' => false]); ?>
                        </div>
                    </details>

                    <details class="tree-accordion">
                        <summary class="tree-summary">レアリティを選択</summary>
                        <div class="tree-content">
                            <?php if (function_exists('render_frontend_term_tree')) render_frontend_term_tree('rarity', 'tx_rarity', ['open_all' => true, 'parent_sync' => false]); ?>
                        </div>
                    </details>

                    <div class="search-divider"></div>
                    <div class="search-section">
                        <div class="section-title">声優名</div>
                        <input type="text" name="tx_cv" class="term-tree-search"
                            value="<?php echo isset($_GET['tx_cv']) ? esc_attr($_GET['tx_cv']) : ''; ?>"
                            placeholder="例：石見舞菜香（苗字・名前のみも可）" />
                    </div>
                    <div class="search-divider"></div>
                </div>

                <div class="tab-content" data-content="tab-waza">
                    <div class="search-section">
                        <div class="section-title">わざ・すごわざ・コトワザ
                            <?php
                            render_simple_relation_toggle('tx_skill_tags');
                            ?>
                        </div>
                        <p class="section-sub-title">行動順</p>
                        <div class="simple-checkbox">
                            <label><input type="checkbox" name="tx_priority[]" value="1"> <span>フィールド</span></label>
                            <label><input type="checkbox" name="tx_priority[]" value="2"> <span>バフ/デバフ</span></label>
                            <label><input type="checkbox" name="tx_priority[]" value="3"> <span>回復</span></label>
                            <label><input type="checkbox" name="tx_priority[]" value="4"> <span>全体攻撃</span></label>
                            <label><input type="checkbox" name="tx_priority[]" value="5"> <span>単体攻撃</span></label>
                        </div>

                        <div class="scope-selector">
                            <span class="scope-label">検索対象:</span>
                            <label><input type="checkbox" name="scope_skill[]" value="waza" checked> わざ</label>
                            <label><input type="checkbox" name="scope_skill[]" value="sugo" checked> すごわざ</label>
                            <label><input type="checkbox" name="scope_skill[]" value="kotowaza" checked> コトワザ</label>
                        </div>


                        <div class="tag-accordion-group">
                            <details class="tag-details">
                                <summary class="tag-summary">
                                    <label class="parent-label" onclick="event.stopPropagation();">
                                        <input type="checkbox" name="tx_skill_tags[]" value="type_attack"> 攻撃タイプ (全体)
                                    </label>
                                </summary>
                                <div class="tag-children">
                                    <label><input type="checkbox" name="tx_skill_tags[]" value="type_attack_single"> 単体単発攻撃</label>
                                    <label><input type="checkbox" name="tx_skill_tags[]" value="type_attack_all"> 全体単発攻撃</label>
                                    <label><input type="checkbox" name="tx_skill_tags[]" value="type_attack_single_multi"> 単体連撃</label>
                                    <label><input type="checkbox" name="tx_skill_tags[]" value="type_attack_all_multi"> 全体連撃</label>
                                    <label><input type="checkbox" name="tx_skill_tags[]" value="type_attack_random"> ランダム攻撃</label>
                                    <label><input type="checkbox" name="tx_skill_tags[]" value="attack_type_converged"> 収束攻撃</label>
                                    <label><input type="checkbox" name="tx_skill_tags[]" value="type_omni_advantage"> 全属性有利</label>
                                    <label><input type="checkbox" name="tx_skill_tags[]" value="type_colorfull_attack"> カラフル攻撃</label>
                                    <label><input type="checkbox" name="tx_skill_tags[]" value="type_coop_attack"> 連携攻撃</label>
                                </div>
                            </details>
                            <details class="tag-details">
                                <summary class="tag-summary">
                                    <label class="parent-label" onclick="event.stopPropagation();">
                                        <input type="checkbox" name="tx_skill_tags[]" value="type_buff"> バフ (強化)
                                    </label>
                                </summary>
                                <div class="tag-children">
                                    <label><input type="checkbox" name="tx_skill_tags[]" value="type_atk_buff"> ATKバフ</label>
                                    <label><input type="checkbox" name="tx_skill_tags[]" value="type_def_buff"> DEFバフ</label>
                                </div>
                            </details>
                            <details class="tag-details">
                                <summary class="tag-summary">
                                    <label class="parent-label" onclick="event.stopPropagation();">
                                        <input type="checkbox" name="tx_skill_tags[]" value="type_debuff"> デバフ (弱体化)
                                    </label>
                                </summary>
                                <div class="tag-children">
                                    <label><input type="checkbox" name="tx_skill_tags[]" value="type_atk_debuff"> ATKデバフ</label>
                                    <label><input type="checkbox" name="tx_skill_tags[]" value="type_def_debuff"> DEFデバフ</label>
                                </div>
                            </details>
                            <div class="simple-tag-row">
                                <label><input type="checkbox" name="tx_skill_tags[]" value="type_heal"> 回復</label>
                                <label><input type="checkbox" name="tx_skill_tags[]" value="type_status_barrier"> 状態異常バリア</label>
                                <label><input type="checkbox" name="tx_skill_tags[]" value="type_barrier"> 無敵バリア</label>
                                <label><input type="checkbox" name="tx_skill_tags[]" value="type_command"> 号令</label>
                                <label><input type="checkbox" name="tx_skill_tags[]" value="type_waza_command"> わざ号令</label>
                                <label><input type="checkbox" name="tx_skill_tags[]" value="type_pressure"> 重圧</label>
                                <label><input type="checkbox" name="tx_skill_tags[]" value="type_taunt"> ターゲット集中</label>
                                <label><input type="checkbox" name="tx_skill_tags[]" value="type_battle_field"> フィールド</label>
                                <label><input type="checkbox" name="tx_skill_tags[]" value="type_impersonation"> ものまね</label>
                                <label><input type="checkbox" name="tx_skill_tags[]" value="type_token"> トークン生成</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-content" data-content="tab-trait">
                    <div class="search-section">
                        <div class="section-title">とくせい・祝福
                            <?php
                            render_simple_relation_toggle('tx_trait_tags');
                            ?>
                        </div>
                        <div class="scope-selector">
                            <span class="scope-label">検索対象:</span>
                            <label><input type="checkbox" name="scope_trait[]" value="t1" checked> とくせい1</label>
                            <label><input type="checkbox" name="scope_trait[]" value="t2" checked> とくせい2</label>
                            <label><input type="checkbox" name="scope_trait[]" value="blessing" checked> 祝福</label>
                        </div>

                        <div class="tag-accordion-group">
                            <details class="tag-details">
                                <summary class="tag-summary">
                                    <label class="parent-label" onclick="event.stopPropagation();">
                                        <input type="checkbox" name="tx_trait_tags[]" value="trait_damage_correction"> 火力補正
                                    </label>
                                </summary>
                                <div class="tag-children">
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_damage_correction_oneself"> 自身の威力up</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_damage_correction_killer"> キラー</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_damage_correction_break_limit"> 自身の上限解放</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_damage_correction_single_shot"> 単体単発補正</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_damage_correction_week_killer"> 弱点キラー</label>
                                </div>
                            </details>
                            <details class="tag-details">
                                <summary class="tag-summary">
                                    <label class="parent-label" onclick="event.stopPropagation();">
                                        <input type="checkbox" name="tx_trait_tags[]" value="trait_status_up">ステータス・クリティカル補正
                                    </label>
                                </summary>
                                <div class="tag-children">
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_status_up_atk"> ATKUP</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_status_up_hp"> HPUP</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_status_up_critical_rate"> クリティカル率</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_status_up_critical_damage"> クリティカルダメージ</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_status_up_resistance"> 状態異常耐性</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_status_up_healing_effect"> 回復効果UP</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_status_up_mitigation"> ダメージ軽減</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_status_up_dodge"> 心眼回避</label>
                                </div>
                            </details>
                            <details class="tag-details">
                                <summary class="tag-summary">
                                    <label class="parent-label" onclick="event.stopPropagation();">
                                        <input type="checkbox" name="tx_trait_tags[]" value="trait_draw_eff">ドロー時効果
                                    </label>
                                </summary>
                                <div class="tag-children">
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_draw_eff_atk_buff"> 攻撃バフ</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_draw_eff_def_buff"> 防御バフ</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_draw_eff_healing"> 回復</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_draw_eff_status_healing"> 状態異常回復</label>
                                </div>
                            </details>
                            <details class="tag-details">
                                <summary class="tag-summary">
                                    <label class="parent-label" onclick="event.stopPropagation();">
                                        <input type="checkbox" name="tx_trait_tags[]" value="trait_on_play_eff">実体時効果
                                    </label>
                                </summary>
                                <div class="tag-children">
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_on_play_eff_atk_buff"> 攻撃バフ</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_on_play_eff_def_buff"> 防御バフ</label>
                                </div>
                            </details>
                            <details class="tag-details">
                                <summary class="tag-summary">
                                    <label class="parent-label" onclick="event.stopPropagation();">
                                        <input type="checkbox" name="tx_trait_tags[]" value="trait_new_traits"> 新とくせい
                                    </label>
                                </summary>
                                <div class="tag-children">
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_new_traits_support"> 応援</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_new_traits_see_through"> 看破</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_new_traits_assistance"> 援護</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_new_traits_resonance_atk"> 共鳴</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_new_traits_resonance_crit"> クリティカル共鳴</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_new_traits_poke"> 牽制</label>
                                </div>
                            </details>
                            <details class="tag-details">
                                <summary class="tag-summary">
                                    <label class="parent-label" onclick="event.stopPropagation();">
                                        <input type="checkbox" name="tx_trait_tags[]" value="trait_unique_buff"> 特殊な攻撃バフ
                                    </label>
                                </summary>
                                <div class="tag-children">
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_unique_buff_gimmick_count"> ギミックカウントATKバフ</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_unique_buff_block_break"> ブロック破壊時ATKバフ</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_unique_buff_passed_turn"> ターン経過ATKバフ</label>
                                </div>
                            </details>
                            <details class="tag-details">
                                <summary class="tag-summary">
                                    <label class="parent-label" onclick="event.stopPropagation();">
                                        <input type="checkbox" name="tx_trait_tags[]" value="trait_after_attack"> 反撃・腐敗など
                                    </label>
                                </summary>
                                <div class="tag-children">
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_after_attack_counter"> わざ反撃</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_after_attack_sugo_counter"> すごわざ反撃</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_after_attack_absolute_counter"> 確定反撃</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_after_attack_corruption"> 腐敗</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_after_attack_reflection"> ダメージ反射</label>
                                </div>
                            </details>
                            <details class="tag-details">
                                <summary class="tag-summary">
                                    <label class="parent-label" onclick="event.stopPropagation();">
                                        <input type="checkbox" name="tx_trait_tags[]" value="trait_mode_shift"> モードシフト・変身
                                    </label>
                                </summary>
                                <div class="tag-children">
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_mode_shift_mode_shift"> モードシフト</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_mode_shift_transform"> 変身</label>
                                </div>
                            </details>
                            <details class="tag-details">
                                <summary class="tag-summary">
                                    <label class="parent-label" onclick="event.stopPropagation();">
                                        <input type="checkbox" name="tx_trait_tags[]" value="trait_other"> その他
                                    </label>
                                </summary>
                                <div class="tag-children">
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_other_combo_plus"> コンボ＋</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_other_penetration"> バリア貫通</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_other_over_healing"> オーバーヒール</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_other_exp_up"> 経験値UP</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_other_pressure_break"> 重圧の上限解放</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_other_kokusen"> 黒閃</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_other_kyouwa"> 協和</label>
                                    <label><input type="checkbox" name="tx_trait_tags[]" value="trait_other_other"> その他の固有とくせい</label>
                                </div>
                            </details>
                        </div>
                    </div>
                </div>
                <div class="tab-content" data-content="tab-leader">
                    <div class="search-section">
                        <div class="section-title">リーダー</div>
                        <div class="leader-container"></div>
                        <input type="button" class="cond-add-btn js-add-leader-btn" value="項目を追加"></input>
                    </div>
                    <template class="js-leader-template">
                        <div class="leader-item">
                            <div class="leader-section leader-buff-section">
                                <div class="leader-section-label">効果（補正）</div>
                                <select name="__IDX__leader_type" class="leader-cond-select js-leader-type-select">
                                    <option value="">効果タイプを選択</option>
                                    <option value="all">すべて</option>
                                    <option value="hp">HP UP</option>
                                    <option value="atk">ATK UP</option>
                                    <option value="status_resistance">状態異常耐性</option>
                                    <option value="exp_up">プレイヤー経験値UP</option>
                                    <option value="over_healing">オーバーヒール</option>
                                    <option value="crit_rate">CRIT率 UP</option>
                                    <option value="crit_damage">CRITダメージ UP</option>
                                    <option value="damage_reduction">被ダメージ軽減</option>
                                    <option value="converged">収束付与</option>
                                    <option value="corruption">腐敗</option>
                                    <option value="over_attack">攻撃回数に応じたバフ</option>
                                    <option value="random_crit">乱打クリティカル</option>
                                </select>
                                <select name="__IDX__leader_status_resistance" class="leader-cond-select js-leader-status-resistance" style="display: none;">
                                    <option value="">状態異常耐性の種類を選択</option>
                                    <option value="poison">毒</option>
                                    <option value="sleep">睡眠</option>
                                    <option value="curse">呪い</option>
                                    <option value="confusion">混乱</option>
                                    <option value="pollution">汚染</option>
                                    <option value="burn">炎上</option>
                                    <option value="remodel">改造</option>
                                    <option value="weakness">衰弱</option>
                                    <option value="mutation">変異</option>
                                    <option value="erasure">消去</option>
                                </select>
                                <input type="number" min="0" step="5" name="__IDX__leader_val" placeholder="数値を入力">
                                <div class="leader-cond-type-container" style="display: flex; justify-content: center; gap: 12px;">
                                    <input type="checkbox" name="__IDX__leader_cond_type" value="fixed" checked>固定値式</input>
                                    <input type="checkbox" name="__IDX__leader_cond_type" value="per_unit" checked>キャラ数式</input>
                                </div>
                            </div>
                            <div class="leader-section leader-target-section">
                                <div class="leader-section-label">対象</div>
                                <select name="__IDX__leader_target" class="leader-cond-select js-leader-target-select">
                                    <option value="">対象タイプを選択</option>
                                    <option value="all">すべて</option>
                                    <option value="attribute">属性</option>
                                    <option value="species">種族</option>
                                    <option value="affiliation">グループ</option>
                                </select>
                                <div class="leader-target-attr" style="display: none;">
                                    <?php if (function_exists('render_simple_checkbox_list')) render_simple_checkbox_list('attribute', '__IDX__target_attr', true); ?>
                                </div>
                                <div class="leader-target-species" style="display: none;">
                                    <?php if (function_exists('render_simple_checkbox_list')) render_simple_checkbox_list('species', '__IDX__target_species', true); ?>
                                </div>
                                <div class="leader-target-group" style="display: none;">
                                    <?php if (function_exists('render_frontend_term_tree')) render_frontend_term_tree('affiliation', '__IDX__target_group'); ?>
                                </div>
                                <div class="leader-target-aim">
                                    編成条件にこの属性/種族を含むキャラを含む
                                    <?php if (function_exists('render_ios_toggle')) echo render_ios_toggle('__IDX__leader_target_aim', '含む', '含む', '含まない'); ?>
                                </div>
                            </div>
                            <div class="leader-section leader-condition-section">
                                <div class="leader-section-label">条件</div>
                                <select name="__IDX__leader_cond_type_select" class="leader-cond-select js-leader-cond-type-select">
                                    <option value="">条件タイプを選択</option>
                                    <option value="chara_num">キャラ編成指定</option>
                                    <option value="moji_count">文字数</option>
                                    <option value="combo">コンボ数</option>
                                    <option value="theme">テーマ指定</option>
                                    <option value="moji_contain">含まれる文字指定</option>
                                    <option value="cooperate">同時攻撃</option>
                                    <option value="wave_count">WAVEが進むごとに</option>
                                </select>
                                <select name="__IDX__cond_target" class="leader-cond-select js-leader-cond-target-select" style="display: none;">
                                    <option value="">対象タイプを選択</option>
                                    <option value="attribute">属性</option>
                                    <option value="species">種族</option>
                                </select>
                                <div class="leader-cond-attr" style="display: none; justify-content: center;">
                                    <?php if (function_exists('render_simple_checkbox_list')) render_simple_checkbox_list('attribute', '__IDX__cond_attr', true); ?>
                                </div>
                                <div class="leader-cond-species" style="display: none; justify-content: center;">
                                    <?php if (function_exists('render_simple_checkbox_list')) render_simple_checkbox_list('species', '__IDX__cond_species', true); ?>
                                </div>
                                <input type="number" min="0" class="leader-cond-val js-leader-cond-val" name="__IDX__leader_cond_val" placeholder="条件の値を入力" style="display: none;">
                            </div>
                            <button type="button" class="js-remove-cond-btn reset-btn">削除</button>
                        </div>
                    </template>
                </div>
            </div>

            <div class="search-modal-footer">
                <button type="button" class="modal-apply-btn js-apply-modal-btn">この条件で絞り込む</button>
            </div>

        </div>
    </div>
</form>