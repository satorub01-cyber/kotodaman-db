// =========================================================
// 検索URLデフォルト定義・共通パラメータ構築ヘルパー
// =========================================================
const KOTO_SEARCH_DEFAULT_PARAMS = {
    'search_char_relation': 'AND',
    'tx_axis_relation': 'OR',
    'tx_attr_relation': 'OR',
    'tx_attr_sub': 'OR',
    'tx_species_relation': 'OR',
    'tx_acq_relation': 'OR',
    'tx_group_relation': 'OR',
    'tx_event_relation': 'OR',
    'tx_gimmick_relation': 'OR',
    'tx_rarity_relation': 'OR',
    'tx_skill_tags_relation': 'OR',
    'tx_trait_tags_relation': 'OR',
    'string_match_target': 'OR',
    'include_trait_status_resistance': '',
    'tx_quest_relation': 'OR',
    'leader_target_relation': 'OR'
};

const KOTO_DEFAULT_SCOPE_SKILL = ['waza', 'sugo', 'kotowaza'];
const KOTO_DEFAULT_SCOPE_TRAIT = ['t1', 't2', 'blessing'];

let kotoActiveSearchForm = null;

function kotoResolveSearchForm(preferredForm) {
    if (preferredForm && preferredForm.nodeType === 1) {
        return preferredForm;
    }
    if (kotoActiveSearchForm && kotoActiveSearchForm.isConnected) {
        return kotoActiveSearchForm;
    }
    return document.querySelector('.character-search-box .js-search-form')
        || document.getElementById('searchform')
        || document.querySelector('.js-search-form')
        || null;
}

function setActiveSearchForm(form) {
    if (form && form.nodeType === 1) {
        kotoActiveSearchForm = form;
    }
}

function getActiveSearchForm() {
    return kotoResolveSearchForm(null);
}

function kotoArraysEqual(a, b) {
    if (a.length !== b.length) return false;
    for (let i = 0; i < a.length; i++) {
        if (a[i] !== b[i]) return false;
    }
    return true;
}

function kotoIsDefaultSearchValue(key, value, formData) {
    const strVal = (value === null || value === undefined) ? '' : String(value);
    if (!strVal || strVal.trim() === '') return true;

    if (Object.prototype.hasOwnProperty.call(KOTO_SEARCH_DEFAULT_PARAMS, key)) {
        return strVal === KOTO_SEARCH_DEFAULT_PARAMS[key];
    }

    if (key === 'scope_skill[]') {
        const currentValues = formData.getAll('scope_skill[]').map(String).sort();
        const defaultValues = [...KOTO_DEFAULT_SCOPE_SKILL].sort();
        return kotoArraysEqual(currentValues, defaultValues);
    }

    if (key === 'scope_trait[]') {
        const currentValues = formData.getAll('scope_trait[]').map(String).sort();
        const defaultValues = [...KOTO_DEFAULT_SCOPE_TRAIT].sort();
        return kotoArraysEqual(currentValues, defaultValues);
    }

    return false;
}

function buildKotoSearchParams(targetForm, options) {
    const form = kotoResolveSearchForm(targetForm);
    if (!form) return new URLSearchParams();

    const opts = options || {};
    const formData = new FormData(form);
    const params = new URLSearchParams();

    params.append('post_type', 'character');
    const searchTerm = String(formData.get('s') || '');
    if (searchTerm.trim() !== '') {
        params.append('s', searchTerm);
    }

    // iOSトグルスイッチはDOM上のcheckbox状態を正とする（hidden disabled競合を完全回避）
    const toggleValueMap = new Map();
    form.querySelectorAll('input.ios-toggle-checkbox[name]').forEach(cb => {
        if (cb.name) {
            toggleValueMap.set(cb.name, cb.checked ? 'AND' : 'OR');
        }
    });

    const processedKeys = new Set();
    const hasLeaderTargetSelection =
        formData.getAll('leader_target_attr[]').length > 0 ||
        formData.getAll('leader_target_species[]').length > 0 ||
        formData.getAll('leader_target_group[]').length > 0;

    const inactiveCondPrefixes = [];
    form.querySelectorAll('.js-leader-cond-item').forEach(item => {
        const condType = item.querySelector('.js-leader-cond-type-select')?.value || '';
        const idx = item.getAttribute('data-cond-index');
        if (!condType && idx !== null) {
            inactiveCondPrefixes.push(`leader_cond_${idx}_`);
        }
    });

    for (const [key, rawValue] of formData.entries()) {
        if (key === 's' || key === 'post_type') continue;
        if (toggleValueMap.has(key)) continue;

        const value = (rawValue === null || rawValue === undefined) ? '' : String(rawValue);
        if (key === 'leader_target_type' && value !== 'all' && !hasLeaderTargetSelection) continue;
        if (key === 'leader_status_type[]' || key === 'leader_status_val[]' || key === 'leader_other_type[]' || key === 'leader_other_val[]') continue;
        if (inactiveCondPrefixes.some(prefix => key.startsWith(prefix))) continue;
        if (value.trim() === '') continue;

        if (key.endsWith('[]')) {
            if (processedKeys.has(key)) continue;
            processedKeys.add(key);

            if (kotoIsDefaultSearchValue(key, value, formData)) continue;

            const allValues = formData.getAll(key);
            allValues.forEach(v => {
                const sv = (v === null || v === undefined) ? '' : String(v);
                if (sv.trim() !== '') params.append(key, sv);
            });
        } else {
            if (kotoIsDefaultSearchValue(key, value, formData)) continue;
            params.append(key, value);
        }
    }

    // トグルスイッチ値をデフォルト比較のうえで追加
    toggleValueMap.forEach((toggleVal, toggleName) => {
        if (!kotoIsDefaultSearchValue(toggleName, toggleVal, formData)) {
            params.append(toggleName, toggleVal);
        }
    });

    form.querySelectorAll('.js-leader-status-row').forEach(row => {
        const type = row.querySelector('select[name="leader_status_type[]"]')?.value || '';
        const val = row.querySelector('input[name="leader_status_val[]"]')?.value?.trim() || '';
        if (type !== '') {
            params.append('leader_status_type[]', type);
            params.append('leader_status_val[]', val);
        }
    });

    form.querySelectorAll('.js-leader-other-row').forEach(row => {
        const type = row.querySelector('select[name="leader_other_type[]"]')?.value || '';
        const val = row.querySelector('input[name="leader_other_val[]"]')?.value?.trim() || '';
        if (type !== '') {
            params.append('leader_other_type[]', type);
            params.append('leader_other_val[]', val);
        }
    });

    if (opts.sortKey && opts.sortKey !== 'name_ruby') {
        params.append('sort_key', opts.sortKey);
        params.append('sort_order', opts.sortOrder || 'ASC');
    }

    return params;
}

window.KOTO_SEARCH_DEFAULT_PARAMS = KOTO_SEARCH_DEFAULT_PARAMS;
window.KOTO_DEFAULT_SCOPE_SKILL = KOTO_DEFAULT_SCOPE_SKILL;
window.KOTO_DEFAULT_SCOPE_TRAIT = KOTO_DEFAULT_SCOPE_TRAIT;
window.getActiveSearchForm = getActiveSearchForm;
window.setActiveSearchForm = setActiveSearchForm;
window.buildKotoSearchParams = buildKotoSearchParams;

function handleKotoSearchSubmit(event, form) {
    event.preventDefault();
    if (typeof event.stopImmediatePropagation === 'function') {
        event.stopImmediatePropagation();
    }

    setActiveSearchForm(form);
    if (typeof window.handleKotoCharacterSearchSubmit === 'function') {
        window.handleKotoCharacterSearchSubmit(form);
        return;
    }

    window.location.href = '/?' + buildKotoSearchParams(form).toString();
}

document.addEventListener('submit', function (event) {
    const form = event.target;
    if (form && form.classList && form.classList.contains('js-search-form')) {
        handleKotoSearchSubmit(event, form);
    }
}, true);

document.addEventListener('DOMContentLoaded', function () {

    // 各種ボタンとモーダルの要素をクラス名で一括取得
    const overlays = document.querySelectorAll('.js-search-modal-overlay');
    const openBtns = document.querySelectorAll('.js-toggle-advanced-search');
    const closeBtns = document.querySelectorAll('.js-close-modal-btn');
    const applyBtns = document.querySelectorAll('.js-apply-modal-btn');
    const resetButtons = document.querySelectorAll('.js-reset-search-btn, .js-modal-reset-search-btn');

    let modalOpenSnapshot = ''; // モーダル開いた時の検索条件を保存
    let activeModalForm = null; // 現在モーダルを開いている対象フォーム

    // 現在の検索条件を文字列で取得（比較用）
    const getSearchSnapshot = (targetForm) => {
        const form = kotoResolveSearchForm(targetForm);
        if (!form) return '';
        return buildKotoSearchParams(form).toString();
    };

    // モーダルを開き、背景のスクロールを無効化する処理
    const openModal = (targetForm) => {
        const form = kotoResolveSearchForm(targetForm);
        activeModalForm = form;
        if (form) {
            setActiveSearchForm(form);
        }

        // モーダルを開く時に現在の条件をスナップショット
        modalOpenSnapshot = getSearchSnapshot(form);

        // 対象フォーム配下のオーバーレイのみを表示（複数フォーム競合を防止）
        const targetOverlay = form ? form.querySelector('.js-search-modal-overlay') : overlays[0];
        overlays.forEach(overlay => {
            overlay.style.display = (overlay === targetOverlay) ? 'flex' : 'none';
        });
        document.body.style.overflow = 'hidden';

        // bodyにモーダル検知クラスを付与
        document.body.classList.add('is-modal-active');

        // 【修正】存在する全てのフッター要素を安全に非表示にする
        const footerElements = document.querySelectorAll('footer, .site-footer, #colophon');
        footerElements.forEach(footer => footer.style.setProperty('display', 'none', 'important'));
    };

    // モーダルを閉じ、背景のスクロールを有効化する処理
    const closeModal = (targetForm) => {
        const form = kotoResolveSearchForm(targetForm || activeModalForm);
        if (form) {
            setActiveSearchForm(form);
        }
        activeModalForm = null;

        overlays.forEach(overlay => overlay.style.display = 'none');
        document.body.style.overflow = '';

        // bodyからクラスを削除
        document.body.classList.remove('is-modal-active');

        // 【修正】非表示にしたフッター要素を元に戻す
        const footerElements = document.querySelectorAll('footer, .site-footer, #colophon');
        footerElements.forEach(footer => footer.style.display = '');

        const currentParams = getSearchSnapshot(form);
        const hasChanged = modalOpenSnapshot !== currentParams;

        // 常に検索実行（変更があればGA4も送信）
        if (typeof window.executeSearch === 'function') {
            window.executeSearch(hasChanged, form);
        } else {
            // 他ページで条件が変更された場合は検索ページへ遷移
            redirectToSearchPage(form);
        }
    };

    // 他ページからの遷移処理（デフォルト値除外による短縮URLを生成）
    const redirectToSearchPage = (targetForm) => {
        const form = kotoResolveSearchForm(targetForm);
        if (!form) {
            return;
        }

        const params = buildKotoSearchParams(form);
        window.location.href = '/?' + params.toString();
    };

    // 取得した全てのボタンに開閉イベントを付与（ボタンが属するフォームを渡す）
    openBtns.forEach(btn => btn.addEventListener('click', () => {
        openModal(btn.closest('.js-search-form'));
    }));
    [...closeBtns, ...applyBtns].forEach(btn => btn.addEventListener('click', () => {
        closeModal(btn.closest('.js-search-form'));
    }));

    // 背景のオーバーレイ部分のクリックでモーダルを閉じる処理
    overlays.forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal(overlay.closest('.js-search-form'));
        });
    });

    // フォーム操作時にアクティブフォームを記録し、他ページでのSubmitを短縮URL遷移にする
    const allForms = document.querySelectorAll('.js-search-form');
    allForms.forEach(form => {
        const markActive = () => setActiveSearchForm(form);
        form.addEventListener('focusin', markActive);
        form.addEventListener('change', markActive, true);
        form.addEventListener('input', markActive, true);
    });

    // フォーム内の入力を初期状態に戻す処理
    const performReset = function (e) {
        const triggerForm = kotoResolveSearchForm(e?.currentTarget?.closest?.('.js-search-form'));
        if (triggerForm) {
            setActiveSearchForm(triggerForm);
        }
        const forms = document.querySelectorAll('.js-search-form');

        forms.forEach(form => {
            const textInputs = form.querySelectorAll('input[type="text"], input[type="number"]');
            textInputs.forEach(input => input.value = '');

            const checkboxes = form.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(box => {
                if (box.name === 'scope_skill[]' || box.name === 'scope_trait[]') return;
                const defaultIsAnd = KOTO_SEARCH_DEFAULT_PARAMS[box.name] === 'AND';
                box.checked = defaultIsAnd;
                box.indeterminate = false;
                if (box.classList.contains('ios-toggle-checkbox')) {
                    const wrapper = box.closest('.ios-toggle-wrapper');
                    if (wrapper) {
                        const labels = wrapper.querySelectorAll('.ios-toggle-label-text');
                        if (labels.length >= 2) {
                            labels[0].classList.toggle('active', !defaultIsAnd);
                            labels[1].classList.toggle('active', defaultIsAnd);
                        }
                        const hiddenInput = wrapper.querySelector('input[type="hidden"]');
                        if (hiddenInput) {
                            hiddenInput.value = 'OR';
                            hiddenInput.disabled = defaultIsAnd;
                        }
                    }
                }
            });

            const selects = form.querySelectorAll('select');
            selects.forEach(sel => sel.selectedIndex = 0);

            const statusRows = form.querySelectorAll('.js-leader-status-list .js-leader-status-row');
            statusRows.forEach((row, idx) => {
                if (idx > 0) {
                    row.remove();
                } else {
                    const sel = row.querySelector('.js-leader-status-select');
                    const inp = row.querySelector('input[type="number"]');
                    if (sel) sel.selectedIndex = 0;
                    if (inp) inp.value = '';
                }
            });

            form.querySelectorAll('.js-leader-other-list .js-leader-other-row').forEach(row => row.remove());
            form.querySelectorAll('.js-leader-cond-list .js-leader-cond-item').forEach(item => item.remove());

            syncLeaderFormUI(form);
            if (typeof window.syncAllParentCheckboxes === 'function') {
                window.syncAllParentCheckboxes(form);
            }
        });

        const treeItems = document.querySelectorAll('.term-tree-item');
        treeItems.forEach(el => el.style.display = '');

        if (typeof window.requestFilterCharacters === 'function') {
            window.requestFilterCharacters(true, triggerForm);
        } else if (typeof window.filterCharacters === 'function') {
            window.filterCharacters({ form: triggerForm, immediateUrl: true });
        }
    };

    // 取得した全てのリセットボタンにイベントを付与
    resetButtons.forEach(btn => btn.addEventListener('click', performReset));

    // ツリー検索フィルター（動的追加されたグループツリーにも対応するため委譲）
    document.addEventListener('input', function (e) {
        const input = e.target;
        if (!input || !input.classList || !input.classList.contains('term-tree-search')) return;
        const container = input.closest('.custom-term-selector-ui');
        if (!container) return;

        const normalizeForSearchLocal = (v) => {
            try {
                const value = String(v || '').normalize('NFKC').toLowerCase().trim();
                if (value === '&' || value === '＆') return value;
                return value.replace(/[&＆]/g, '');
            } catch (err) {
                const value = String(v || '').toLowerCase().trim();
                if (value === '&' || value === '＆') return value;
                return value.replace(/[&＆]/g, '');
            }
        };
        const keyword = normalizeForSearchLocal(input.value);
        const items = container.querySelectorAll('.term-tree-item');

        if (keyword === '') {
            items.forEach(el => el.style.display = '');
            return;
        }

        items.forEach(el => el.style.display = 'none');

        container.querySelectorAll('.term-name').forEach(function (span) {
            if (normalizeForSearchLocal(span.textContent).includes(keyword)) {
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

    // 親子チェックボックスの連動ロジック（トップダウン伝播＋ボトムアップ再計算）
    const getParentDetails = (parentCheckbox) => {
        const summary = parentCheckbox.closest('summary');
        return summary ? summary.closest('details') : parentCheckbox.closest('details');
    };

    const setDescendantsState = (parentCheckbox, isChecked) => {
        parentCheckbox.indeterminate = false;
        const details = getParentDetails(parentCheckbox);
        if (!details) return;

        const childContainer = details.querySelector('.tag-children, .term-children-container');
        if (!childContainer) return;

        const childCheckboxes = childContainer.querySelectorAll('input[type="checkbox"]');
        childCheckboxes.forEach(child => {
            child.checked = isChecked;
            child.indeterminate = false;
        });
    };

    const syncAllParentCheckboxes = (rootContainer = document) => {
        // 最下層の親から最上位の親へボトムアップ順に評価するため reverse() する
        const parentCheckboxes = Array.from(rootContainer.querySelectorAll('.js-parent-checkbox')).reverse();
        parentCheckboxes.forEach(parentCheckbox => {
            const details = getParentDetails(parentCheckbox);
            if (!details) return;

            const childContainer = details.querySelector('.tag-children, .term-children-container');
            if (!childContainer) return;

            const childCheckboxes = Array.from(childContainer.querySelectorAll('input[type="checkbox"]'));
            if (childCheckboxes.length === 0) return;

            const total = childCheckboxes.length;
            const checkedCount = childCheckboxes.filter(cb => cb.checked).length;
            const indeterminateCount = childCheckboxes.filter(cb => cb.indeterminate).length;

            if (checkedCount === 0 && indeterminateCount === 0) {
                parentCheckbox.checked = false;
                parentCheckbox.indeterminate = false;
            } else if (checkedCount === total) {
                parentCheckbox.checked = true;
                parentCheckbox.indeterminate = false;
            } else {
                parentCheckbox.checked = false;
                parentCheckbox.indeterminate = true;
            }
        });
    };

    const syncCheckboxHierarchy = (targetCheckbox, rootContainer = document) => {
        if (!targetCheckbox) return;
        if (targetCheckbox.classList.contains('js-parent-checkbox')) {
            setDescendantsState(targetCheckbox, targetCheckbox.checked);
        }
        const scope = targetCheckbox.closest('.custom-term-selector-ui, .tag-accordion-group, .js-search-form') || rootContainer;
        syncAllParentCheckboxes(scope);
    };

    window.syncCheckboxHierarchy = syncCheckboxHierarchy;
    window.syncAllParentCheckboxes = syncAllParentCheckboxes;

    // 初期表示時の親状態同期
    syncAllParentCheckboxes(document);

    // Captureフェーズでchangeを捕捉し、filterCharacters()が走る前に親子状態を確定させる
    document.addEventListener('change', function (e) {
        if (e.target && e.target.matches('input[type="checkbox"]')) {
            if (e.target.closest('.custom-term-selector-ui, .tag-accordion-group')) {
                syncCheckboxHierarchy(e.target);
            }
        }
    }, true);

    // 親チェックボックスクリック時のアコーディオン開閉を防ぐ処理
    const parentLabels = document.querySelectorAll('summary .term-label, summary .parent-label');
    parentLabels.forEach(label => {
        label.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    });
    // リーダー検索UI（対象・状態異常・その他効果・追加条件）の動的制御
    let leaderIndex = 0;

    function syncLeaderTargetPanels(root) {
        const container = root || document;
        const targetSelect = container.querySelector('.js-leader-target-select');
        if (!targetSelect) return;
        const selectedValue = targetSelect.value || 'attribute';
        container.querySelectorAll('.js-leader-target-panel').forEach(panel => {
            const matches = panel.getAttribute('data-target-panel') === selectedValue;
            panel.classList.toggle('is-hidden', !matches);
        });
    }

    function syncLeaderCondTargetPanels(condItem) {
        if (!condItem) return;
        const condTypeSelect = condItem.querySelector('.js-leader-cond-type-select');
        const condType = condTypeSelect ? condTypeSelect.value : '';
        const targetSelect = condItem.querySelector('.js-leader-cond-target-select');
        const showTarget = condType === 'chara_num' || condType === 'cooperate';
        const selectedTarget = (showTarget && targetSelect) ? targetSelect.value : '';

        condItem.querySelectorAll('.js-leader-cond-target-panel').forEach(panel => {
            const matches = showTarget && selectedTarget !== '' && panel.getAttribute('data-cond-panel') === selectedTarget;
            panel.classList.toggle('is-hidden', !matches);
        });
    }

    function syncLeaderCondItemUI(condItem) {
        if (!condItem) return;
        const condTypeSelect = condItem.querySelector('.js-leader-cond-type-select');
        const selectedValue = condTypeSelect ? condTypeSelect.value : '';
        const condTargetSelect = condItem.querySelector('.js-leader-cond-target-select');
        const condNumInput = condItem.querySelector('.js-leader-cond-num-val');
        const condTextInput = condItem.querySelector('.js-leader-cond-text-val');

        const needsTarget = selectedValue === 'chara_num' || selectedValue === 'cooperate';
        const needsNum = selectedValue === 'chara_num' || selectedValue === 'moji_count' || selectedValue === 'combo' || selectedValue === 'wave_count';
        const needsText = selectedValue === 'theme' || selectedValue === 'moji_contain';

        if (condTargetSelect) {
            condTargetSelect.classList.toggle('is-hidden', !needsTarget);
        }
        if (condNumInput) {
            condNumInput.classList.toggle('is-hidden', !needsNum);
            condNumInput.placeholder = selectedValue === 'chara_num' ? '必要編成数を入力' : '条件の値を入力';
        }
        if (condTextInput) {
            condTextInput.classList.toggle('is-hidden', !needsText);
        }

        syncLeaderCondTargetPanels(condItem);
    }

    function syncLeaderFormUI(root) {
        const container = root || document;
        container.querySelectorAll('.js-leader-main-box').forEach(box => syncLeaderTargetPanels(box));
        container.querySelectorAll('.js-leader-cond-item').forEach(item => syncLeaderCondItemUI(item));
    }

    function appendLeaderStatusRow(container) {
        if (!container) return null;
        const form = container.closest('.js-search-form') || document;
        const template = form.querySelector('.js-leader-status-row-template');
        if (!template) return null;
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = template.innerHTML.trim();
        const newRow = tempDiv.firstElementChild;
        if (newRow) container.appendChild(newRow);
        return newRow;
    }

    function appendLeaderOtherRow(container) {
        if (!container) return null;
        const form = container.closest('.js-search-form') || document;
        const template = form.querySelector('.js-leader-other-row-template');
        if (!template) return null;
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = template.innerHTML.trim();
        const newRow = tempDiv.firstElementChild;
        if (newRow) container.appendChild(newRow);
        return newRow;
    }

    function appendLeaderCondItem(container, explicitIndex) {
        if (!container) return null;
        const form = container.closest('.js-search-form') || document;
        const template = form.querySelector('.js-leader-cond-template');
        if (!template) return null;
        const idx = (typeof explicitIndex === 'number' && !Number.isNaN(explicitIndex))
            ? explicitIndex
            : leaderIndex++;
        if (idx >= leaderIndex) {
            leaderIndex = idx + 1;
        }
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = template.innerHTML.replace(/__IDX__/g, String(idx)).trim();
        const newItem = tempDiv.firstElementChild;
        if (newItem) {
            container.appendChild(newItem);
            syncLeaderCondItemUI(newItem);
        }
        return newItem;
    }

    window.syncLeaderFormUI = syncLeaderFormUI;
    window.syncLeaderCondItemUI = syncLeaderCondItemUI;
    window.appendLeaderStatusRow = appendLeaderStatusRow;
    window.appendLeaderOtherRow = appendLeaderOtherRow;
    window.appendLeaderCondItem = appendLeaderCondItem;

    // 初期表示の同期
    syncLeaderFormUI(document);

    // クリックイベント（状態異常追加・その他効果追加・条件追加・各削除）
    document.addEventListener('click', function (e) {
        const addStatusBtn = e.target.closest('.js-add-leader-status-btn');
        if (addStatusBtn) {
            const form = addStatusBtn.closest('.js-search-form') || document;
            appendLeaderStatusRow(form.querySelector('.js-leader-status-list'));
            return;
        }

        const addOtherBtn = e.target.closest('.js-add-leader-other-btn');
        if (addOtherBtn) {
            const form = addOtherBtn.closest('.js-search-form') || document;
            appendLeaderOtherRow(form.querySelector('.js-leader-other-list'));
            return;
        }

        const addCondBtn = e.target.closest('.js-add-leader-cond-btn');
        if (addCondBtn) {
            const form = addCondBtn.closest('.js-search-form') || document;
            appendLeaderCondItem(form.querySelector('.js-leader-cond-list'));
            return;
        }

        const removeRowBtn = e.target.closest('.js-remove-leader-row-btn');
        if (removeRowBtn) {
            const form = removeRowBtn.closest('.js-search-form');
            const row = removeRowBtn.closest('.js-leader-status-row, .js-leader-other-row');
            if (row) {
                if (form) setActiveSearchForm(form);
                row.remove();
                if (typeof window.requestFilterCharacters === 'function') {
                    window.requestFilterCharacters(true, form);
                } else if (typeof window.filterCharacters === 'function') {
                    window.filterCharacters({ form, immediateUrl: true });
                }
            }
            return;
        }

        const removeCondBtn = e.target.closest('.js-remove-cond-btn');
        if (removeCondBtn) {
            const form = removeCondBtn.closest('.js-search-form');
            const condItem = removeCondBtn.closest('.js-leader-cond-item');
            if (condItem) {
                if (form) setActiveSearchForm(form);
                condItem.remove();
                if (typeof window.requestFilterCharacters === 'function') {
                    window.requestFilterCharacters(true, form);
                } else if (typeof window.filterCharacters === 'function') {
                    window.filterCharacters({ form, immediateUrl: true });
                }
            }
        }
    });

    // 対象タイプ・条件タイプ・条件対象の切り替え
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('js-leader-target-select')) {
            syncLeaderTargetPanels(e.target.closest('.js-leader-main-box') || document);
        } else if (e.target.classList.contains('js-leader-cond-type-select')) {
            syncLeaderCondItemUI(e.target.closest('.js-leader-cond-item'));
        } else if (e.target.classList.contains('js-leader-cond-target-select')) {
            syncLeaderCondTargetPanels(e.target.closest('.js-leader-cond-item'));
        }

        if (e.target.name && e.target.name.includes('leader_')) {
            if (e.target.type === 'checkbox' || e.target.type === 'radio') return;
            const form = e.target.closest('.js-search-form');
            if (form) setActiveSearchForm(form);
            if (typeof window.requestFilterCharacters === 'function') {
                window.requestFilterCharacters(true, form);
            } else if (typeof window.filterCharacters === 'function') {
                window.filterCharacters({ form, immediateUrl: true });
            }
        }
    });

    // リーダー検索の数値・テキスト入力時に検索を実行
    document.addEventListener('input', function (e) {
        if (e.target.name && e.target.name.includes('leader_')) {
            const form = e.target.closest('.js-search-form');
            if (form) setActiveSearchForm(form);
            if (typeof window.scheduleFilterCharacters === 'function') {
                window.scheduleFilterCharacters(undefined, form);
            } else if (typeof window.filterCharacters === 'function') {
                window.filterCharacters({ form, immediateUrl: true });
            }
        }
    });

    // タブ切り替え処理
    const searchForms = document.querySelectorAll('.js-search-form');
    searchForms.forEach(form => {
        const tabButtons = form.querySelectorAll('.tab-select-area .area-select-button'); // より具体的なセレクタ
        const tabContents = form.querySelectorAll('.tab-content');

        tabButtons.forEach(button => {
            button.addEventListener('click', function () {
                // タブとコンテンツの初期化
                tabButtons.forEach(btn => btn.classList.remove('is-active'));
                tabContents.forEach(content => content.classList.remove('is-active'));

                // クリックされたタブをアクティブ化
                this.classList.add('is-active');

                // 対象コンテンツのアクティブ化
                const targetId = this.getAttribute('data-target');
                const targetContent = form.querySelector(`.tab-content[data-content="${targetId}"]`);

                if (targetContent) {
                    targetContent.classList.add('is-active');
                }
            });
        });
        // 検索対象切り替え
        const targetSelectToggle = form.querySelectorAll('input[name="string_match_target"][type="checkbox"]');
        const freewordInput = form.querySelectorAll('.js-freeword-search');
        const mojiInput = form.querySelectorAll('.js-search-char-input');
        targetSelectToggle.forEach(toggle => {
            const toggleInputDisplay = () => {
                if (!toggle.checked) {
                    freewordInput.forEach(el => el.style.display = 'block');
                    mojiInput.forEach(el => el.style.display = 'none');
                } else {
                    freewordInput.forEach(el => el.style.display = 'none');
                    mojiInput.forEach(el => el.style.display = 'block');
                }
            };
            // 初期状態の反映
            toggleInputDisplay();
            toggle.addEventListener('change', toggleInputDisplay);
        });

    });

    // Minimize search bar functionality
    const searchWrapper = document.querySelector('.search-wrapper');
    const minimizeButton = document.querySelector('.js-minimize-search-btn');
    const isSearchPage = typeof window.isSearchResultPage === 'function' && window.isSearchResultPage();

    if (searchWrapper && minimizeButton && isSearchPage) {
        const localStorageKey = 'koto_search_bar_minimized';

        // Function to apply the minimized state
        const applyMinimizedState = (isMinimized) => {
            const iconSpan = minimizeButton.querySelector('span');
            if (isMinimized) {
                searchWrapper.classList.add('is-minimized');
                if (iconSpan) iconSpan.textContent = '+'; // Plus sign for expand
            } else {
                searchWrapper.classList.remove('is-minimized');
                if (iconSpan) iconSpan.textContent = '−'; // Minus sign for minimize
            }
        };

        // Load state from localStorage on page load
        const savedState = localStorage.getItem(localStorageKey);
        if (savedState === 'true') {
            applyMinimizedState(true);
        } else {
            applyMinimizedState(false);
        }

        // Add event listener to toggle state
        minimizeButton.addEventListener('click', () => {
            const currentState = searchWrapper.classList.contains('is-minimized');
            applyMinimizedState(!currentState);
            localStorage.setItem(localStorageKey, !currentState);
        });
    }

});

// =========================================================
// ▼▼▼ リーダー検索第一段階：入力収集（all_characters_search準拠）▼▼▼
// =========================================================

const ALL_ATTR_IDS = [1, 2, 3, 4, 5, 6, 7, 8];

// 属性スラッグ → ID変換マップ（all_characters_search準拠）
const ATTR_SLUG_TO_ID = {
    'fire': 1, 'water': 2, 'wood': 3, 'light': 4,
    'dark': 5, 'void': 6, 'heaven': 7, 'rainbow': 8
};

// 種族スラッグ → ID変換マップ（all_characters_search準拠）
const SPECIES_SLUG_TO_ID = {
    'god': 1, 'demon': 2, 'hero': 3, 'dragon': 4,
    'beast': 5, 'spirit': 6, 'artifact': 7, 'yokai': 8
};

// 補正タイプの変換マップ（フォーム値 → JSON.ty / JSON.valsキー）
const BUFF_TYPE_MAP = {
    // === valsキーで照合 ===
    'hp': { ty: 'fixed', stat: 'hp', matchBy: 'vals_key', ignoreTarget: false },
    'atk': { ty: 'fixed', stat: 'atk', matchBy: 'vals_key', ignoreTarget: false },
    'status_resistance': { ty: 'fixed', stat: null, matchBy: 'vals_key', ignoreTarget: false },
    'crit_rate': { ty: 'fixed', stat: 'crit_rate', matchBy: 'vals_key', ignoreTarget: false },
    'crit_damage': { ty: 'fixed', stat: 'crit_damage', matchBy: 'vals_key', ignoreTarget: false },
    'damage_reduction': { ty: 'fixed', stat: 'mitigation', matchBy: 'vals_key', ignoreTarget: false },

    // === ty または valsキーで照合 ===
    'exp_up': { ty: 'exp_up', stat: 'exp', matchBy: 'ty', ignoreTarget: true },
    'over_healing': { ty: 'over_healing', stat: 'over_heal', matchBy: 'ty', ignoreTarget: false },
    'converged': { ty: 'converged', stat: 'converged', matchBy: 'ty', ignoreTarget: false },
    'corruption': { ty: 'corruption', stat: 'corruption_rate', matchBy: 'ty_or_vals', ignoreTarget: false },
    'over_attack': { ty: 'over_attack', stat: 'over_atk', matchBy: 'ty', ignoreTarget: false },
    'random_crit': { ty: 'random_crit', stat: 'rand_crit', matchBy: 'ty', ignoreTarget: false },
    'damage_cut': { ty: 'damage_cut', stat: 'corruption_rate', matchBy: 'ty', ignoreTarget: false },
    'combo_buff': { ty: 'combo_buff', stat: null, matchBy: 'ty', ignoreTarget: false },
    'reflection': { ty: 'reflection', stat: null, matchBy: 'ty', ignoreTarget: false }
};

/**
 * フォームから新UIのリーダー検索条件を収集する
 * @returns {Array} 有効な検索条件があれば1要素の配列、すべて未入力なら空配列
 */
function collectLeaderSearchConditions(targetForm) {
    const form = kotoResolveSearchForm(targetForm) || document;
    const mainBox = form.querySelector('.js-leader-main-box');
    if (!mainBox) return [];

    // 1. 対象
    const rawTargetType = mainBox.querySelector('select[name="leader_target_type"]')?.value || 'attribute';
    const targetRelCb = mainBox.querySelector('input[type="checkbox"][name="leader_target_relation"]');
    const targetRelation = targetRelCb && targetRelCb.checked ? 'AND' : 'OR';

    let target = { active: false, type: 'attr', slugs: [], relation: targetRelation, isAll: false };
    if (rawTargetType === 'all') {
        target = { active: true, type: 'attr', slugs: [...ALL_ATTR_IDS], relation: 'AND', isAll: true };
    } else if (rawTargetType === 'attribute') {
        const slugs = Array.from(mainBox.querySelectorAll('input[name="leader_target_attr[]"]:checked'))
            .map(cb => ATTR_SLUG_TO_ID[cb.value])
            .filter(Boolean);
        target = { active: slugs.length > 0, type: 'attr', slugs, relation: targetRelation, isAll: false };
    } else if (rawTargetType === 'species') {
        const slugs = Array.from(mainBox.querySelectorAll('input[name="leader_target_species[]"]:checked'))
            .map(cb => SPECIES_SLUG_TO_ID[cb.value])
            .filter(Boolean);
        target = { active: slugs.length > 0, type: 'species', slugs, relation: targetRelation, isAll: false };
    } else if (rawTargetType === 'affiliation') {
        const slugs = Array.from(mainBox.querySelectorAll('input[name="leader_target_group[]"]:checked'))
            .map(cb => cb.value)
            .filter(Boolean);
        target = { active: slugs.length > 0, type: 'group', slugs, relation: targetRelation, isAll: false };
    }

    // 2. HP / ATK
    const hpRaw = mainBox.querySelector('input[name="leader_hp_val"]')?.value?.trim() || '';
    const hpNum = hpRaw !== '' ? Number(hpRaw) : NaN;
    const hp = (Number.isFinite(hpNum) && hpNum > 0) ? hpNum : null;

    const atkRaw = mainBox.querySelector('input[name="leader_atk_val"]')?.value?.trim() || '';
    const atkNum = atkRaw !== '' ? Number(atkRaw) : NaN;
    const atk = (Number.isFinite(atkNum) && atkNum > 0) ? atkNum : null;

    // 3. 状態異常耐性
    const statuses = [];
    mainBox.querySelectorAll('.js-leader-status-row').forEach(row => {
        const status = row.querySelector('select[name="leader_status_type[]"]')?.value || '';
        if (!status) return;
        const valRaw = row.querySelector('input[name="leader_status_val[]"]')?.value?.trim() || '';
        const valNum = valRaw !== '' ? Number(valRaw) : NaN;
        const minVal = (Number.isFinite(valNum) && valNum > 0) ? valNum : 0;
        statuses.push({ status, minVal });
    });

    // 4. その他効果
    const others = [];
    mainBox.querySelectorAll('.js-leader-other-row').forEach(row => {
        const type = row.querySelector('select[name="leader_other_type[]"]')?.value || '';
        if (!type) return;
        const valRaw = row.querySelector('input[name="leader_other_val[]"]')?.value?.trim() || '';
        const valNum = valRaw !== '' ? Number(valRaw) : NaN;
        const minVal = (Number.isFinite(valNum) && valNum > 0) ? valNum : 0;
        others.push({ type, minVal });
    });

    // 5. 追加条件
    const conditions = [];
    mainBox.querySelectorAll('.js-leader-cond-item').forEach(item => {
        const condType = item.querySelector('.js-leader-cond-type-select')?.value || '';
        if (!condType) return;

        let condTargetType = '';
        let condTargetSlugs = [];
        if (condType === 'chara_num' || condType === 'cooperate') {
            const rawCondTarget = item.querySelector('.js-leader-cond-target-select')?.value || '';
            if (rawCondTarget === 'attribute') {
                condTargetType = 'attr';
                condTargetSlugs = Array.from(item.querySelectorAll('input[name$="_attr[]"]:checked'))
                    .map(cb => ATTR_SLUG_TO_ID[cb.value])
                    .filter(Boolean);
            } else if (rawCondTarget === 'species') {
                condTargetType = 'species';
                condTargetSlugs = Array.from(item.querySelectorAll('input[name$="_species[]"]:checked'))
                    .map(cb => SPECIES_SLUG_TO_ID[cb.value])
                    .filter(Boolean);
            } else if (rawCondTarget === 'affiliation') {
                condTargetType = 'group';
                condTargetSlugs = Array.from(item.querySelectorAll('input[name$="_group[]"]:checked'))
                    .map(cb => cb.value)
                    .filter(Boolean);
            }
        }

        let numVal = null;
        if (condType === 'chara_num' || condType === 'moji_count' || condType === 'combo' || condType === 'wave_count') {
            const numRaw = item.querySelector('.js-leader-cond-num-val')?.value?.trim() || '';
            const parsedNum = numRaw !== '' ? Number(numRaw) : NaN;
            if (Number.isFinite(parsedNum) && parsedNum > 0) {
                numVal = parsedNum;
            }
        }

        let textVal = '';
        if (condType === 'theme' || condType === 'moji_contain') {
            textVal = (item.querySelector('.js-leader-cond-text-val')?.value || '').trim();
        }

        conditions.push({
            type: condType,
            targetType: condTargetType,
            targetSlugs: condTargetSlugs,
            numVal,
            textVal
        });
    });

    const isActive = target.active || hp !== null || atk !== null || statuses.length > 0 || others.length > 0 || conditions.length > 0;
    if (!isActive) return [];

    return [{
        isNewLeaderQuery: true,
        target,
        hp,
        atk,
        statuses,
        others,
        conditions
    }];
}

// グローバルスコープで公開
window.collectLeaderSearchConditions = collectLeaderSearchConditions;
window.LEADER_CONSTANTS = {
    ALL_ATTR_IDS,
    ATTR_SLUG_TO_ID,
    SPECIES_SLUG_TO_ID,
    BUFF_TYPE_MAP
};

// =========================================================
// ▼▼▼ moji_searchイベント送信機能 ▼▼▼
// =========================================================

/**
 * 単一文字をGA4に送信する関数
 * @param {string} char - 送信する文字
 */
function sendMojiSearchEvent(char) {
    if (typeof gtag !== 'function') return;
    if (window.location.hostname !== 'www.kotodaman-db.com') return;
    if (!char || !char.trim()) return;

    gtag('event', 'moji_search', {
        event_category: 'search',
        event_label: 'character',
        search_char: char
    });
}

/**
 * 文字列を個別の文字に分解して送信
 * @param {string} searchChar - 入力された文字列（例: "あいう"）
 */
function sendMojiSearchEvents(searchChar) {
    if (!searchChar) return;

    // 文字を個別に分解し、重複を除去
    const uniqueChars = [...new Set(searchChar.split(''))];

    uniqueChars.forEach(char => {
        if (char.trim()) {
            sendMojiSearchEvent(char);
        }
    });
}

// デバウンス用タイマー
let mojiSearchDebounceTimer = null;

// モーダル外の使用可能文字入力欄にイベントリスナーを設定（複数フォーム対応）
document.addEventListener('DOMContentLoaded', function () {
    const searchCharInputs = document.querySelectorAll('.js-search-char-input');
    if (!searchCharInputs.length) return;

    searchCharInputs.forEach(searchCharInput => {
        searchCharInput.addEventListener('input', function () {
            const value = this.value;

            // 既存のタイマーをクリア
            clearTimeout(mojiSearchDebounceTimer);

            // 入力が空なら送信しない
            if (!value.trim()) return;

            // 300msのデバウンスで送信（入力中の連続送信を防止）
            mojiSearchDebounceTimer = setTimeout(() => {
                sendMojiSearchEvents(value);
            }, 300);
        });
    });
});