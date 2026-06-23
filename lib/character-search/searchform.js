document.addEventListener('DOMContentLoaded', function () {

    // 各種ボタンとモーダルの要素をクラス名で一括取得
    const overlays = document.querySelectorAll('.js-search-modal-overlay');
    const openBtns = document.querySelectorAll('.js-toggle-advanced-search');
    const closeBtns = document.querySelectorAll('.js-close-modal-btn');
    const applyBtns = document.querySelectorAll('.js-apply-modal-btn');
    const resetButtons = document.querySelectorAll('.js-reset-search-btn, .js-modal-reset-search-btn');

    let modalOpenSnapshot = ''; // モーダル開いた時の検索条件を保存

    // 現在の検索条件を文字列で取得（比較用）
    const getSearchSnapshot = () => {
        const form = document.querySelector('.js-search-form');
        if (!form) return '';

        const formData = new FormData(form);
        const params = new URLSearchParams();

        for (const [key, value] of formData.entries()) {
            if (value.trim() !== '') params.append(key, value);
        }

        return params.toString();
    };

    // モーダルを開き、背景のスクロールを無効化する処理
    const openModal = () => {
        // モーダルを開く時に現在の条件をスナップショット
        modalOpenSnapshot = getSearchSnapshot();
        overlays.forEach(overlay => overlay.style.display = 'flex');
        document.body.style.overflow = 'hidden';

        // bodyにモーダル検知クラスを付与
        document.body.classList.add('is-modal-active');

        // 【修正】存在する全てのフッター要素を安全に非表示にする
        const footerElements = document.querySelectorAll('footer, .site-footer, #colophon');
        footerElements.forEach(footer => footer.style.setProperty('display', 'none', 'important'));
    };

    // モーダルを閉じ、背景のスクロールを有効化する処理
    const closeModal = () => {
        overlays.forEach(overlay => overlay.style.display = 'none');
        document.body.style.overflow = '';

        // bodyからクラスを削除
        document.body.classList.remove('is-modal-active');

        // 【修正】非表示にしたフッター要素を元に戻す
        const footerElements = document.querySelectorAll('footer, .site-footer, #colophon');
        footerElements.forEach(footer => footer.style.display = '');

        const currentParams = getSearchSnapshot();
        const hasChanged = modalOpenSnapshot !== currentParams;

        // 常に検索実行（変更があればGA4も送信）
        if (typeof window.executeSearch === 'function') {
            window.executeSearch(hasChanged);
        } else {
            // 他ページで条件が変更された場合は検索ページへ遷移
            redirectToSearchPage();
        }
    };

    // 他ページからの遷移処理
    const redirectToSearchPage = () => {
        const form = document.querySelector('.js-search-form');
        if (!form) {
            return;
        }

        const formData = new FormData(form);
        const params = new URLSearchParams();
        params.append('post_type', 'character');

        const defaultScopes = {
            'scope_skill[]': ['waza', 'sugo', 'kotowaza'].sort(),
            'scope_trait[]': ['t1', 't2', 'blessing'].sort()
        };

        const processedKeys = new Set();

        for (const [key, value] of formData.entries()) {
            if (key === 'post_type' || value.trim() === '') continue;

            if (key.endsWith('[]')) {
                if (processedKeys.has(key)) continue;
                processedKeys.add(key);

                const allValues = formData.getAll(key);
                // 全チェックの場合はURLをスッキリさせるため除外
                if (defaultScopes[key] && JSON.stringify([...allValues].sort()) === JSON.stringify(defaultScopes[key])) continue;

                allValues.forEach(v => params.append(key, v));
            } else {
                params.append(key, value);
            }
        }

        window.location.href = '/?' + params.toString();
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

    // 他ページ（search-engine.js未読み込み時）でのSubmit（エンターキーなど）イベント処理
    const allForms = document.querySelectorAll('.js-search-form');
    allForms.forEach(form => {
        form.addEventListener('submit', function (e) {
            if (typeof window.executeSearch !== 'function') {
                e.preventDefault();
                redirectToSearchPage();
            }
        });
    });

    // フォーム内の入力を初期状態に戻す処理
    const performReset = function () {
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
    treeSearches.forEach(function (input) {
        input.addEventListener('input', function () {
            const keyword = this.value.toLowerCase().trim();
            const container = this.closest('.custom-term-selector-ui');
            if (!container) return;

            const items = container.querySelectorAll('.term-tree-item');

            if (keyword === '') {
                items.forEach(el => el.style.display = '');
                return;
            }

            items.forEach(el => el.style.display = 'none');

            container.querySelectorAll('.term-name').forEach(function (span) {
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

            parentCheckbox.addEventListener('change', function () {
                const isChecked = this.checked;
                childCheckboxes.forEach(child => {
                    child.checked = isChecked;
                });

                if (typeof window.filterCharacters === 'function') {
                    window.filterCharacters();
                }
            });

            childCheckboxes.forEach(child => {
                child.addEventListener('change', function () {
                    updateParentState();
                });
            });
        }
    });

    // 親チェックボックスクリック時のアコーディオン開閉を防ぐ処理
    const parentLabels = document.querySelectorAll('summary .term-label, summary .parent-label');
    parentLabels.forEach(label => {
        label.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    });
    // リーダー検索項目の追加・削除機能
    const addLeaderCondBtns = document.querySelectorAll('.js-add-leader-btn');
    let leaderIndex = 0;

    addLeaderCondBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            // 現在の検索フォーム全体を取得
            const form = this.closest('.js-search-form');
            // 追加先のコンテナと、複製元のテンプレートをクラス名で取得
            const container = form.querySelector('.leader-container');
            const template = form.querySelector('.js-leader-template');
            if (template && container) {
                // テンプレートのHTMLを文字列として取得し、プレースホルダーを置換
                const currentIndex = leaderIndex++;
                const templateHtml = template.innerHTML.replace(/__IDX__/g, `leader_${currentIndex}_`);

                // 一時的なdivを作成してHTMLをパース
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = templateHtml;
                const newItem = tempDiv.firstElementChild;

                // コンテナの末尾に追加
                container.appendChild(newItem);
            }
        });
    });

    // 動的に追加された要素に対するクリックイベントの捕捉
    document.addEventListener('click', function (e) {
        // クリックされた要素が削除ボタンであるか判定
        if (e.target && e.target.classList.contains('js-remove-cond-btn')) {
            // 削除ボタンの親にあたる項目全体を削除
            e.target.closest('.leader-item').remove();
        }
    });

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('js-leader-type-select')) {
            const leaderItem = e.target.closest('.leader-item');
            if (leaderItem) {
                const statusResistanceSelect = leaderItem.querySelector('.js-leader-status-resistance');
                if (statusResistanceSelect) {
                    if (e.target.value === 'status_resistance') {
                        statusResistanceSelect.style.display = '';
                    } else {
                        statusResistanceSelect.style.display = 'none';
                    }
                }
            }
        }
    });

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('js-leader-target-select')) {
            const leaderItem = e.target.closest('.leader-item');
            if (leaderItem) {
                const attrContainer = leaderItem.querySelector('.leader-target-attr');
                const speciesContainer = leaderItem.querySelector('.leader-target-species');
                const groupContainer = leaderItem.querySelector('.leader-target-group');
                const selectedValue = e.target.value;

                if (attrContainer) {
                    attrContainer.style.display = selectedValue === 'attribute' ? 'flex' : 'none';
                    if (selectedValue === 'attribute') {
                        attrContainer.style.justifyContent = 'center';
                    }
                }
                if (speciesContainer) {
                    speciesContainer.style.display = selectedValue === 'species' ? 'flex' : 'none';
                    if (selectedValue === 'species') {
                        speciesContainer.style.justifyContent = 'center';
                    }
                }
                if (groupContainer) {
                    groupContainer.style.display = selectedValue === 'affiliation' ? 'block' : 'none';
                }
            }
        }
    });

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('js-leader-cond-type-select')) {
            const leaderItem = e.target.closest('.leader-item');
            if (leaderItem) {
                const condTypeSelect = leaderItem.querySelector('.js-leader-cond-type-select');
                const selectedValue = condTypeSelect.value;
                const condTargetSelect = leaderItem.querySelector('.js-leader-cond-target-select');
                const selectedTarget = condTargetSelect ? condTargetSelect.value : '';
                const condValueInput = leaderItem.querySelector('.js-leader-cond-val');
                const condAttrContainer = leaderItem.querySelector('.leader-cond-attr');
                const condSpeciesContainer = leaderItem.querySelector('.leader-cond-species');
                if (selectedValue === '') {
                    condTargetSelect.style.display = 'none';
                    condValueInput.style.display = 'none';
                } else if (selectedValue === 'chara_num') {
                    condTargetSelect.style.display = 'block';
                    condValueInput.style.display = 'block';
                    condValueInput.placeholder = '必要編成数を入力';
                } else {
                    condValueInput.placeholder = '条件の値を入力';
                    condValueInput.style.display = 'block';
                    condTargetSelect.style.display = 'none';
                }
                if (condAttrContainer) {
                    condAttrContainer.style.display = selectedTarget === 'attribute' ? 'flex' : 'none';
                }
                if (condSpeciesContainer) {
                    condSpeciesContainer.style.display = selectedTarget === 'species' ? 'flex' : 'none';
                }
            }
        }
    });

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('js-leader-cond-target-select')) {
            const leaderItem = e.target.closest('.leader-item');
            if (leaderItem) {
                const selectedTarget = e.target.value;
                const condAttrContainer = leaderItem.querySelector('.leader-cond-attr');
                const condSpeciesContainer = leaderItem.querySelector('.leader-cond-species');
                if (condAttrContainer) {
                    condAttrContainer.style.display = selectedTarget === 'attribute' ? 'flex' : 'none';
                }
                if (condSpeciesContainer) {
                    condSpeciesContainer.style.display = selectedTarget === 'species' ? 'flex' : 'none';
                }
            }
        }
    });

    // ★追加：リーダー検索フォームの変更時に検索を実行
    document.addEventListener('change', function (e) {
        if (e.target.name && e.target.name.includes('leader_')) {
            if (typeof window.filterCharacters === 'function') {
                window.filterCharacters();
            }
        }
    });

    // ★追加：リーダー検索のテキスト入力時に検索を実行
    document.addEventListener('input', function (e) {
        if (e.target.name && e.target.name.includes('leader_')) {
            if (typeof window.filterCharacters === 'function') {
                window.filterCharacters();
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
    // === valsキーで照合（ty: 'fixed'）===
    'hp': { ty: 'fixed', stat: 'hp', matchBy: 'vals_key' },
    'atk': { ty: 'fixed', stat: 'atk', matchBy: 'vals_key' },
    'status_resistance': { ty: 'fixed', stat: null, matchBy: 'vals_key' }, // 動的に決定
    'crit_rate': { ty: 'fixed', stat: 'crit_rate', matchBy: 'vals_key' },
    'crit_damage': { ty: 'fixed', stat: 'critical_damage', matchBy: 'vals_key' },
    'damage_reduction': { ty: 'fixed', stat: 'mitigation', matchBy: 'vals_key' },

    // === tyで照合（valsは別途存在または空）===
    'exp_up': { ty: 'exp_up', stat: 'exp', matchBy: 'ty' },
    'over_healing': { ty: 'over_healing', stat: 'over_heal', matchBy: 'ty' },
    'converged': { ty: 'converged', stat: 'converged', matchBy: 'ty' },
    'corruption': { ty: 'corruption', stat: 'corruption_rate', matchBy: 'ty' },
    'over_attack': { ty: 'over_attack', stat: 'over_atk', matchBy: 'ty' },
    'random_crit': { ty: 'random_crit', stat: 'rand_crit', matchBy: 'ty' }
};

/**
 * フォームからリーダー検索条件を収集し、all_characters_search準拠に正規化する
 * @returns {Array} 正規化されたリーダー検索条件の配列
 */
function collectLeaderSearchConditions() {
    const conditions = [];
    const leaderItems = document.querySelectorAll('.leader-item');

    leaderItems.forEach((item, index) => {
        // 基本入力収集
        const leaderType = item.querySelector('[name*="leader_type"]')?.value || '';
        const formData = {
            type: leaderType,
            value: item.querySelector('[name*="leader_val"]')?.value || '',
            targetType: item.querySelector('[name*="leader_target"]')?.value || '',
            attrs: Array.from(item.querySelectorAll('[name*="target_attr"]:checked')).map(cb => cb.value),
            species: Array.from(item.querySelectorAll('[name*="target_species"]:checked')).map(cb => cb.value),
            groups: Array.from(item.querySelectorAll('[name*="target_group"]:checked')).map(cb => cb.value),
            calcType: Array.from(item.querySelectorAll('[name*="leader_cond_type"]:checked')).map(cb => cb.value),
            // 状態異常耐性の種類（status_resistance選択時）
            statusResistance: leaderType === 'status_resistance'
                ? item.querySelector('[name*="leader_status_resistance"]')?.value || ''
                : ''
        };

        // 空の条件はスキップ（typeかtargetTypeのどちらかがあれば有効）
        if (!formData.type && !formData.targetType) return;

        // all_characters_search準拠の正規化
        const normalized = normalizeLeaderCondition(formData, index);
        conditions.push(normalized);
    });

    return conditions;
}

/**
 * フォーム入力をall_characters_search準拠に正規化する
 * @param {Object} formData - フォーム入力データ
 * @param {number} index - リーダー条件のインデックス
 * @returns {Object} 正規化されたリーダー条件オブジェクト
 */
function normalizeLeaderCondition(formData, index) {
    const buffTypeInfo = BUFF_TYPE_MAP[formData.type] || { ty: 'fixed', stat: null };

    // 対象タイプの変換
    let targetType = 'all';
    let targetSlugs = [];

    switch (formData.targetType) {
        case 'attribute':
            targetType = 'attr';
            targetSlugs = formData.attrs.map(slug => ATTR_SLUG_TO_ID[slug]).filter(Boolean);
            break;
        case 'species':
            targetType = 'species';
            targetSlugs = formData.species.map(slug => SPECIES_SLUG_TO_ID[slug]).filter(Boolean);
            break;
        case 'affiliation':
            targetType = 'affiliation';
            targetSlugs = formData.groups;
            break;
        case 'all':
        default:
            targetType = 'all';
            targetSlugs = [];
    }

    // per_unit判定: tyが'fixed'の時のみtrue
    const perUnit = buffTypeInfo.ty === 'fixed' && formData.calcType.includes('per_unit');

    return {
        index: index,
        buffType: buffTypeInfo.ty,
        buffStat: buffTypeInfo.stat,
        buffValue: parseInt(formData.value, 10) || 0,
        perUnit: perUnit,
        target: {
            type: targetType,
            slugs: targetSlugs
        },
        raw: formData
    };
}

// グローバルスコープで公開
window.collectLeaderSearchConditions = collectLeaderSearchConditions;
window.LEADER_CONSTANTS = {
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

// モーダル外の使用可能文字入力欄にイベントリスナーを設定
document.addEventListener('DOMContentLoaded', function () {
    const searchCharInput = document.querySelector('.js-search-char-input');
    if (!searchCharInput) return;

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