<form role="search" method="get" id="searchform" class="searchform js-search-form" action="<?php echo home_url('/'); ?>">
    <input type="hidden" name="post_type" value="character" />

    <div class="search-wrapper">
        <div class="search-row-top">
            <input type="text" value="<?php echo get_search_query(); ?>" name="s" id="s" placeholder="キャラ名・キーワード..." />
            <button type="submit" class="submit-btn">検索</button>
        </div>

        <div class="search-row-bottom">
            <button type="button" class="toggle-btn js-toggle-advanced-search" aria-label="詳細検索を開く">
                <span class="filter-icon">🔍</span> 詳細フィルターを開く
            </button>
            <button type="button" class="reset-btn js-reset-search-btn">条件クリア</button>
        </div>
    </div>

    <div class="search-modal-overlay js-search-modal-overlay" style="display: none;">
        <div class="search-modal-content">

            <div class="search-modal-header">
                <h2 class="modal-title">詳細検索</h2>
                <div class="modal-header-btns">
                    <button type="button" class="reset-btn js-modal-reset-search-btn">条件クリア</button>
                    <button type="button" class="modal-close-btn js-close-modal-btn">✕</button>
                </div>
            </div>

            <div class="search-modal-body" id="advanced-search-panel">

                <div class="search-section">
                    <div class="section-title">使用可能文字 (OR検索)</div>
                    <input type="text" name="search_char" class="term-tree-search"
                        value="<?php echo isset($_GET['search_char']) ? esc_attr($_GET['search_char']) : ''; ?>"
                        placeholder="例：あい（「あ」または「い」を持つキャラ）" />
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
                    echo render_ios_toggle('tx_attr_sub', isset($_GET['tx_attr_sub']) ? $_GET['tx_attr_sub'] : 'OR', '含む', '含まない');
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
                                    <input type="checkbox" name="tx_trait_tags[]" value="trait_after_attack"> 反撃・腐敗など
                                </label>
                            </summary>
                            <div class="tag-children">
                                <label><input type="checkbox" name="tx_trait_tags[]" value="trait_after_attack_counter"> わざ反撃</label>
                                <label><input type="checkbox" name="tx_trait_tags[]" value="trait_after_attack_sugo_counter"> すごわざ反撃</label>
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
                                <label><input type="checkbox" name="tx_trait_tags[]" value="trait_other_other"> その他の固有とくせい</label>
                            </div>
                        </details>
                    </div>
                </div>
            </div>

            <div class="search-modal-footer">
                <button type="button" class="modal-apply-btn js-apply-modal-btn">この条件で絞り込む</button>
            </div>

        </div>
    </div>
</form>

<script>
    const kotoColumnConfig = <?php echo json_encode(koto_get_column_config()); ?>;
</script>
<script>
    document.addEventListener('DOMContentLoaded', function() {

        // 各種ボタンとモーダルの要素をクラス名で一括取得
        const overlays = document.querySelectorAll('.js-search-modal-overlay');
        const openBtns = document.querySelectorAll('.js-toggle-advanced-search');
        const closeBtns = document.querySelectorAll('.js-close-modal-btn');
        const applyBtns = document.querySelectorAll('.js-apply-modal-btn');
        const resetButtons = document.querySelectorAll('.js-reset-search-btn, .js-modal-reset-search-btn');

        // モーダルを開き、背景のスクロールを無効化する処理
        const openModal = () => {
            overlays.forEach(overlay => overlay.style.display = 'flex');
            document.body.style.overflow = 'hidden';
        };

        // モーダルを閉じ、背景のスクロールを有効化する処理
        const closeModal = () => {
            overlays.forEach(overlay => overlay.style.display = 'none');
            document.body.style.overflow = '';
            if (typeof updateSearchUrlAndAnalytics === 'function') {
                updateSearchUrlAndAnalytics();
            }
        };

        // 取得した全てのボタンに開閉イベントを付与
        openBtns.forEach(btn => btn.addEventListener('click', openModal));
        [...closeBtns, ...applyBtns].forEach(btn => btn.addEventListener('click', closeModal));

        // 背景のオーバーレイ部分のクリックでモーダルを閉じる処理
        overlays.forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) closeModal();
            });
        });

        // フォーム内の入力を初期状態に戻す処理
        const performReset = function() {
            const forms = document.querySelectorAll('.js-search-form');

            forms.forEach(form => {
                const textInputs = form.querySelectorAll('input[type="text"]');
                textInputs.forEach(input => input.value = '');

                const checkboxes = form.querySelectorAll('input[type="checkbox"]');
                checkboxes.forEach(box => {
                    if (box.name === 'scope_skill[]' || box.name === 'scope_trait[]') return;
                    box.checked = false;
                    box.indeterminate = false;
                });

                const selects = form.querySelectorAll('select');
                selects.forEach(sel => sel.selectedIndex = 0);
            });

            const treeItems = document.querySelectorAll('.term-tree-item');
            treeItems.forEach(el => el.style.display = '');

            if (typeof window.filterCharacters === 'function') {
                window.filterCharacters();
            }
        };

        // 取得した全てのリセットボタンにイベントを付与
        resetButtons.forEach(btn => btn.addEventListener('click', performReset));

        // ツリー検索フィルター
        const treeSearches = document.querySelectorAll('.term-tree-search');
        treeSearches.forEach(function(input) {
            input.addEventListener('input', function() {
                const keyword = this.value.toLowerCase().trim();
                const container = this.closest('.custom-term-selector-ui');
                if (!container) return;

                const items = container.querySelectorAll('.term-tree-item');

                if (keyword === '') {
                    items.forEach(el => el.style.display = '');
                    return;
                }

                items.forEach(el => el.style.display = 'none');

                container.querySelectorAll('.term-name').forEach(function(span) {
                    if (span.textContent.toLowerCase().includes(keyword)) {
                        let item = span.closest('.term-tree-item');
                        item.style.display = '';
                        let parent = item.parentElement.closest('.term-tree-item');
                        while (parent) {
                            parent.style.display = '';
                            const details = parent.querySelector('details');
                            if (details) details.open = true;
                            parent = parent.parentElement.closest('.term-tree-item');
                        }
                    }
                });
            });
        });

        // 親子チェックボックスの連動ロジック
        const parentCheckboxes = document.querySelectorAll('.js-parent-checkbox');
        parentCheckboxes.forEach(parentCheckbox => {
            const details = parentCheckbox.closest('details');
            if (!details) return;

            const childContainer = details.querySelector('.tag-children, .term-children-container');
            if (!childContainer) return;

            const childCheckboxes = childContainer.querySelectorAll('input[type="checkbox"]');

            if (childCheckboxes.length > 0) {
                const updateParentState = () => {
                    const total = childCheckboxes.length;
                    const checkedCount = Array.from(childCheckboxes).filter(cb => cb.checked).length;

                    if (checkedCount === 0) {
                        parentCheckbox.checked = false;
                        parentCheckbox.indeterminate = false;
                    } else if (checkedCount === total) {
                        parentCheckbox.checked = true;
                        parentCheckbox.indeterminate = false;
                    } else {
                        parentCheckbox.checked = false;
                        parentCheckbox.indeterminate = true;
                    }
                };

                updateParentState();

                parentCheckbox.addEventListener('change', function() {
                    const isChecked = this.checked;
                    childCheckboxes.forEach(child => {
                        child.checked = isChecked;
                    });

                    if (typeof window.filterCharacters === 'function') {
                        window.filterCharacters();
                    }
                });

                childCheckboxes.forEach(child => {
                    child.addEventListener('change', function() {
                        updateParentState();
                    });
                });
            }
        });

        // 親チェックボックスクリック時のアコーディオン開閉を防ぐ処理
        const parentLabels = document.querySelectorAll('summary .term-label, summary .parent-label');
        parentLabels.forEach(label => {
            label.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
    });
</script>

<style>
    /* --- メイン画面の検索フォーム枠 --- */
    .search-wrapper {
        background: #f7ede3;
        padding: 15px;
        border-radius: 8px;
        border: 1px solid #ddd;
        margin-bottom: 20px;
        width: 100% !important;
    }

    .search-row-top {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        margin-bottom: 10px;
    }

    .search-row-bottom {
        display: flex;
        gap: 8px;
        width: 100%;
    }

    .search-row-top input[type="text"]#s {
        flex: 1;
        height: 44px;
        padding: 0 12px;
        font-size: 16px;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box;
        margin: 0;
    }

    button.submit-btn {
        flex: 0 0 80px;
        height: 44px;
        padding: 0;
        font-size: 14px;
        font-weight: bold;
        background: #2271b1;
        color: #fff;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }

    button.submit-btn:hover {
        background: #135e96;
    }

    /* モーダルを開くボタン */
    button.js-toggle-advanced-search.toggle-btn {
        flex: 1;
        height: 36px;
        font-size: 14px;
        font-weight: bold;
        background: #fff;
        border: 2px solid #2271b1;
        border-radius: 4px;
        color: #2271b1;
        cursor: pointer;
        text-align: center;
        transition: all 0.2s;
    }

    button.js-toggle-advanced-search.toggle-btn:hover {
        background: #2271b1;
        color: #fff;
    }

    button.js-reset-search-btn.reset-btn {
        flex: 0 0 80px;
        height: 36px;
        font-size: 12px;
        background: #fff;
        border: 1px solid #ccc;
        border-radius: 4px;
        color: #d63638;
        cursor: pointer;
        text-align: center;
    }

    button.js-reset-search-btn.reset-btn:hover {
        background: #fff0f0;
        border-color: #d63638;
    }

    /* =========================================================
   ▼▼▼ モーダル（ポップアップ）用のCSS ▼▼▼
========================================================= */
    .search-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.6);
        z-index: 99999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 10px;
        box-sizing: border-box;
        backdrop-filter: blur(2px);
    }

    .search-modal-content {
        background: #fff;
        width: 100%;
        max-width: 600px;
        height: 85%;
        max-height: 90vh;
        border-radius: 8px;
        display: flex;
        flex-direction: column;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        overflow: hidden;
        animation: modalFadeIn 0.2s ease-out;
    }

    @keyframes modalFadeIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .search-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 15px;
        background: #f7ede3;
        border-bottom: 1px solid #ddd;
    }

    .search-modal-header .modal-title {
        margin: 0;
        font-size: 16px;
        font-weight: bold;
        color: #333;
    }

    .modal-header-btns {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    /* モーダル内のリセットボタンの微調整 */
    .js-modal-reset-search-btn {
        padding: 4px 10px;
        font-size: 12px;
        border: 1px solid #d63638;
        color: #d63638;
        background: #fff;
        cursor: pointer;
        border-radius: 4px;
    }

    .js-modal-reset-search-btn:hover {
        background: #fff0f0;
    }

    .modal-close-btn {
        background: none;
        border: none;
        font-size: 20px;
        color: #666;
        cursor: pointer;
        padding: 0 5px;
        line-height: 1;
    }

    .modal-close-btn:hover {
        color: #d63638;
    }

    .search-modal-body {
        padding: 15px;
        overflow-y: auto;
        flex: 1;
    }

    .search-modal-footer {
        padding: 10px 15px;
        border-top: 1px solid #eee;
        background: #fff;
        text-align: center;
    }

    .modal-apply-btn {
        width: 100%;
        padding: 12px;
        font-size: 16px;
        font-weight: bold;
        color: #fff;
        background: #2271b1;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }

    .modal-apply-btn:hover {
        background: #135e96;
    }

    /* =========================================================
   ▼▼▼ モーダル内の各項目（既存のCSSを引き継ぎ） ▼▼▼
========================================================= */
    .search-section {
        margin-bottom: 20px;
    }

    .section-title {
        font-weight: bold;
        font-size: 14px;
        margin-bottom: 8px;
        border-left: 4px solid #2271b1;
        padding-left: 8px;
        color: #333;
    }

    .section-sub-title {
        font-weight: bold;
        font-size: 13px;
        margin: 10px 0 5px;
        color: #555;
    }

    .icon-only-list {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: flex-start;
    }

    .icon-only-label {
        cursor: pointer;
        position: relative;
        display: inline-block;
        transition: all 0.2s;
    }

    .icon-only-label img {
        width: 32px;
        height: 32px;
        object-fit: contain;
        filter: grayscale(100%) opacity(0.5);
        transition: all 0.2s;
        vertical-align: bottom;
    }

    .icon-only-label:has(input:checked) img {
        filter: grayscale(0%) opacity(1);
        transform: scale(1.1);
    }

    .icon-only-label .term-text-hidden {
        display: none;
    }

    .icon-only-label input {
        display: none;
    }

    .custom-term-selector-ui {
        background: #fff;
        border: 1px solid #ccc;
        border-radius: 4px;
        padding: 10px;
    }

    .term-tree-search {
        width: 100%;
        margin-bottom: 10px;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 3px;
        font-size: 14px;
        box-sizing: border-box;
    }

    .term-tree-list {
        max-height: 250px;
        overflow-y: auto;
        font-size: 13px;
        border: 1px solid #f0f0f0;
        padding: 5px;
    }

    .term-tree-item {
        margin: 2px 0;
    }

    .term-children-container {
        margin-left: 18px;
        border-left: 1px solid #eee;
        padding-left: 5px;
    }

    .tree-accordion {
        border: 1px solid #eee;
        border-radius: 4px;
        background: #fff;
        margin-bottom: 10px;
    }

    .tree-summary {
        padding: 10px;
        font-weight: bold;
        font-size: 14px;
        cursor: pointer;
        background: #f5f5f5;
        list-style: none;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .tree-summary::-webkit-details-marker {
        display: none;
    }

    .tree-summary::after {
        content: '▼';
        font-size: 10px;
        color: #777;
        transition: transform 0.2s;
    }

    details[open]>.tree-summary::after {
        transform: rotate(180deg);
    }

    .tree-content {
        padding: 10px;
    }

    .scope-selector {
        background: #f9f9f9;
        padding: 8px;
        border-radius: 4px;
        margin-bottom: 10px;
        font-size: 13px;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        justify-content: flex-start;
    }

    .scope-label {
        font-weight: bold;
        color: #555;
    }

    .tag-accordion-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .tag-details {
        border: 1px solid #eee;
        border-radius: 4px;
    }

    .tag-summary {
        padding: 8px;
        background: #fff;
        font-size: 14px;
        cursor: pointer;
    }

    .tag-children {
        padding: 8px 8px 8px 25px;
        background: #fafafa;
        border-top: 1px solid #eee;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: flex-start;
    }

    .simple-tag-row {
        padding: 5px;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: flex-start;
    }

    .simple-checkbox {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        font-size: 13px;
    }
</style>