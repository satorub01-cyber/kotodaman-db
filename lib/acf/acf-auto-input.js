document.addEventListener('DOMContentLoaded', function () {
    // 手動でXdebugを常に強制する場合はここを true に変更できます
    const MANUAL_FORCE_XDEBUG = false;

    // 現在のページURLまたはCookieにXdebugが指定されているか判定
    const urlParams = new URLSearchParams(window.location.search);
    const hasUrlXdebug = urlParams.has('XDEBUG_SESSION_START') || urlParams.has('XDEBUG_SESSION') || urlParams.has('XDEBUG_TRIGGER');
    const hasCookieXdebug = typeof document !== 'undefined' && document.cookie.includes('XDEBUG_SESSION=');

    // ローカル環境判定（AppDebugConfig、またはホスト名から判定）
    const isLocal = Boolean(
        MANUAL_FORCE_XDEBUG ||
        hasUrlXdebug ||
        (window.AppDebugConfig && window.AppDebugConfig.isLocal) ||
        ['localhost', '127.0.0.1'].includes(window.location.hostname) ||
        window.location.hostname.endsWith('.local')
    );

    // Xdebug トグルボタンの生成（ローカル環境のみ）
    if (isLocal) {
        let isXdebugActive = MANUAL_FORCE_XDEBUG || hasUrlXdebug || localStorage.getItem('app_xdebug_active') === 'true';

        const btnToggle = document.createElement('button');
        btnToggle.type = 'button';
        btnToggle.className = 'xdebug-toggle-btn' + (isXdebugActive ? ' is-active' : '');
        btnToggle.textContent = isXdebugActive ? 'Xdebug: ON' : 'Xdebug: OFF';

        btnToggle.addEventListener('click', function () {
            isXdebugActive = !isXdebugActive;
            localStorage.setItem('app_xdebug_active', isXdebugActive);
            btnToggle.textContent = isXdebugActive ? 'Xdebug: ON' : 'Xdebug: OFF';
            btnToggle.classList.toggle('is-active', isXdebugActive);
        });

        const container = document.createElement('div');
        container.className = 'xdebug-toggle-container';
        container.appendChild(btnToggle);
        document.body.appendChild(container);
    }

    // Ajaxリクエスト用 URL取得関数
    function getAjaxUrl() {
        const base = (window.AppDebugConfig && window.AppDebugConfig.ajaxUrl)
            ? window.AppDebugConfig.ajaxUrl
            : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');

        const isXdebugActive = MANUAL_FORCE_XDEBUG ||
            hasUrlXdebug ||
            hasCookieXdebug ||
            localStorage.getItem('app_xdebug_active') === 'true';

        if (isLocal && isXdebugActive) {
            const separator = base.indexOf('?') === -1 ? '?' : '&';
            // Xdebug 3 (XDEBUG_SESSION, XDEBUG_TRIGGER) および Xdebug 2 (XDEBUG_SESSION_START) に対応
            return base + separator + 'XDEBUG_SESSION=VSCODE&XDEBUG_SESSION_START=VSCODE&XDEBUG_TRIGGER=1';
        }
        return base;
    }

    var form = document.getElementById('multi-copy-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            var items = [];
            document.querySelectorAll('.multi-copy-check:checked').forEach(function (chk) {
                var fieldKey = chk.getAttribute('data-field-key');
                var rowIndex = chk.getAttribute('data-row-index');

                var targetKey = fieldKey;
                var container = chk.closest('.acf-single-copy-box');
                if (container) {
                    var select = container.querySelector('select[name="target_field_key"]');
                    if (select) {
                        targetKey = select.value;
                    }
                }

                items.push({
                    field_key: fieldKey,
                    row_index: rowIndex,
                    target_field_key: targetKey
                });
            });
            document.getElementById('copy_items_json').value = JSON.stringify(items);
        });
    }

    // 自動入力用スクリプト
    const btnAutoInputFill = document.getElementById('btn_auto_input_fill');
    const btnAutoInputMake = document.getElementById('btn_auto_input_make');

    // =================================================================
    // 祝福・EX祝福 ハイブリッド並び替えUI（トークン方式＋自由入力方式、最大8項目）
    // =================================================================
    const blessingConfig = window.KotoBlessingConfig || {
        tokens: {},
        templates: {},
        attrs: ['火', '水', '木', '光', '闇', '冥', '天'],
        species: ['神', '魔', '英', '龍', '獣', '霊', '物', '妖'],
        maxItems: 8
    };
    const MAX_BLESSING_ITEMS = blessingConfig.maxItems || 8;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatSelect2TokenOption(state) {
        if (!state.id || !state.element) {
            return state.text;
        }
        const el = state.element;
        const isSuper = el.getAttribute('data-is-super') === '1';
        const isGimmick = el.getAttribute('data-is-gimmick') === '1';
        const iconUrl = el.getAttribute('data-icon') || '';

        const $wrapper = jQuery('<span class="koto-select2-token-item"></span>');
        if (isSuper) {
            $wrapper.addClass('is-super-gimmick-option');
        }

        if (iconUrl) {
            $wrapper.append(jQuery('<img class="koto-select2-icon" />').attr('src', iconUrl));
        } else if (isGimmick) {
            $wrapper.append(jQuery('<span class="koto-select2-icon-placeholder">G</span>'));
        }

        const $label = jQuery('<span class="koto-select2-label"></span>').text(state.text);
        if (isSuper) {
            $label.addClass('is-super-label');
        }
        $wrapper.append($label);

        return $wrapper;
    }

    function initBlessingSelect2() {
        if (window.jQuery && window.jQuery.fn.select2) {
            jQuery('.koto-blessing-token-select').each(function () {
                if (jQuery(this).hasClass('select2-hidden-accessible')) {
                    jQuery(this).select2('destroy');
                }
                jQuery(this).select2({
                    width: '320px',
                    dropdownAutoWidth: true,
                    placeholder: '-- トークンを選択 --',
                    allowClear: true,
                    templateResult: formatSelect2TokenOption,
                    templateSelection: formatSelect2TokenOption,
                    language: {
                        noResults: function () { return '見つかりません'; }
                    }
                });
            });
            jQuery('.koto-blessing-template-select').each(function () {
                if (jQuery(this).hasClass('select2-hidden-accessible')) {
                    jQuery(this).select2('destroy');
                }
                jQuery(this).select2({
                    width: '100%',
                    dropdownAutoWidth: true,
                    placeholder: '-- テンプレートを選択 --',
                    allowClear: true,
                    language: {
                        noResults: function () { return '見つかりません'; }
                    }
                });
            });
        }
    }

    function initBlessingSortable() {
        if (window.jQuery && window.jQuery.fn.sortable) {
            jQuery('.koto-blessing-items-list').each(function () {
                if (jQuery(this).hasClass('ui-sortable')) {
                    jQuery(this).sortable('destroy');
                }
                jQuery(this).sortable({
                    handle: '.koto-drag-handle',
                    axis: 'y',
                    placeholder: 'koto-blessing-item-placeholder',
                    forcePlaceholderSize: true,
                    stop: function (event, ui) {
                        const listEl = ui.item.closest('.koto-blessing-items-list')[0];
                        if (listEl) {
                            const prefix = listEl.getAttribute('data-prefix');
                            updateBlessingCount(prefix);
                        }
                    }
                });
            });
        }
    }

    function updateBlessingCount(prefix) {
        const list = document.getElementById(prefix + '_items_list');
        if (!list) return 0;
        const items = list.querySelectorAll('.koto-blessing-item');
        const count = items.length;
        const countEl = document.getElementById(prefix + '_count');
        if (countEl) {
            countEl.textContent = count;
        }
        const box = document.getElementById(prefix + '_auto_input_box');
        if (box) {
            const isMax = count >= MAX_BLESSING_ITEMS;
            box.querySelectorAll('.koto-btn-add-token, .koto-btn-add-free, .koto-btn-append-template').forEach(function (btn) {
                btn.disabled = isMax;
            });
            if (isMax) {
                box.classList.add('is-at-max-items');
            } else {
                box.classList.remove('is-at-max-items');
            }
        }
        return count;
    }

    function buildTargetSelectorHtml(targetType, selectedVal) {
        const selected = selectedVal ? String(selectedVal).trim() : '';
        if (targetType === 'attr') {
            const attrs = blessingConfig.attrs || ['火', '水', '木', '光', '闇', '冥', '天'];
            let opts = '<option value="">-- 対象属性 --</option>';
            attrs.forEach(function (a) {
                const isSel = (a === selected) ? ' selected' : '';
                opts += '<option value="' + escapeHtml(a) + '"' + isSel + '>' + escapeHtml(a) + '</option>';
            });
            return '<span class="koto-blessing-item-target-wrap"><label>対象属性:</label><select class="koto-item-target koto-item-target-attr">' + opts + '</select></span>';
        }
        if (targetType === 'species') {
            const species = blessingConfig.species || ['神', '魔', '英', '龍', '獣', '霊', '物', '妖'];
            let opts = '<option value="">-- 対象種族 --</option>';
            species.forEach(function (s) {
                const isSel = (s === selected) ? ' selected' : '';
                opts += '<option value="' + escapeHtml(s) + '"' + isSel + '>' + escapeHtml(s) + '</option>';
            });
            return '<span class="koto-blessing-item-target-wrap"><label>対象種族:</label><select class="koto-item-target koto-item-target-species">' + opts + '</select></span>';
        }
        return '';
    }

    function createTokenItemElement(prefix, tokenKey, targetVal) {
        const tokenDef = blessingConfig.tokens[tokenKey] || {};
        const tokenLabel = tokenDef.label || tokenKey;
        const targetType = tokenDef.target_type || 'self';
        const isGimmick = Boolean(tokenDef.is_gimmick);
        const isSuper = Boolean(tokenDef.is_super);
        const iconUrl = tokenDef.icon_url || '';
        const targetHtml = buildTargetSelectorHtml(targetType, targetVal);

        let badgeHtml = '<span class="koto-blessing-item-badge koto-badge-token">トークン</span>';
        if (isSuper) {
            badgeHtml = '<span class="koto-blessing-item-badge koto-badge-super-gimmick">スーパー</span>';
        } else if (isGimmick) {
            badgeHtml = '<span class="koto-blessing-item-badge koto-badge-gimmick">ギミック</span>';
        }

        let iconHtml = '';
        if (iconUrl) {
            iconHtml = '<span class="koto-gimmick-icon-wrap"><img src="' + escapeHtml(iconUrl) + '" class="koto-gimmick-icon-img" alt="' + escapeHtml(tokenLabel) + '" /></span>';
        } else if (isGimmick) {
            iconHtml = '<span class="koto-gimmick-icon-wrap"><span class="koto-gimmick-icon-placeholder">G</span></span>';
        }

        const item = document.createElement('div');
        item.className = 'koto-blessing-item koto-blessing-item-token' + (isSuper ? ' is-super-gimmick' : '') + (isGimmick ? ' is-gimmick' : '');
        item.setAttribute('data-item-type', 'token');
        item.setAttribute('data-token', tokenKey);
        item.innerHTML =
            '<span class="koto-drag-handle" title="ドラッグして並び替え">⋮⋮</span>' +
            '<div class="koto-blessing-item-move-btns">' +
                '<button type="button" class="button button-small koto-btn-move-up" title="上へ移動">▲</button>' +
                '<button type="button" class="button button-small koto-btn-move-down" title="下へ移動">▼</button>' +
            '</div>' +
            badgeHtml +
            iconHtml +
            '<span class="koto-blessing-item-label">' + escapeHtml(tokenLabel) + '</span>' +
            targetHtml +
            '<button type="button" class="button button-small koto-btn-remove-item" title="この項目を削除">×</button>';
        return item;
    }

    function createFreeItemElement(prefix, point, text) {
        const item = document.createElement('div');
        item.className = 'koto-blessing-item koto-blessing-item-free';
        item.setAttribute('data-item-type', 'free');
        item.innerHTML =
            '<span class="koto-drag-handle" title="ドラッグして並び替え">⋮⋮</span>' +
            '<div class="koto-blessing-item-move-btns">' +
                '<button type="button" class="button button-small koto-btn-move-up" title="上へ移動">▲</button>' +
                '<button type="button" class="button button-small koto-btn-move-down" title="下へ移動">▼</button>' +
            '</div>' +
            '<span class="koto-blessing-item-badge koto-badge-free">自由入力</span>' +
            '<span class="koto-blessing-item-param koto-item-pt-wrap">' +
                '<label>pt:</label>' +
                '<input type="text" class="acf-auto-input-text koto-item-pt" value="' + escapeHtml(point) + '" placeholder="pt">' +
            '</span>' +
            '<span class="koto-blessing-item-param koto-item-text-wrap">' +
                '<label>文言:</label>' +
                '<input type="text" class="acf-auto-input-text koto-item-text" value="' + escapeHtml(text) + '" placeholder="とくせい文言">' +
            '</span>' +
            '<button type="button" class="button button-small koto-btn-remove-item" title="この項目を削除">×</button>';
        return item;
    }

    function applyTemplateToItems(prefix, replace) {
        const templateSelect = document.getElementById(prefix + '_template_select');
        const tplKey = (templateSelect?.value || '').trim();
        if (!tplKey) {
            alert('展開するテンプレートを選択してください。');
            return;
        }

        const tplObj = blessingConfig.templates[tplKey] || {};
        let tplDef = (tplObj.def || '').trim();
        if (!tplDef && tplKey.startsWith('{')) {
            tplDef = tplKey;
        }
        if (!tplDef) {
            alert('選択されたテンプレートの定義が見つかりません: ' + tplKey);
            return;
        }

        const trimmed = tplDef.replace(/^[{\s]+|[}\s]+$/g, '');
        if (!trimmed) {
            alert('テンプレート定義が空です。');
            return;
        }
        const tokens = trimmed.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        if (tokens.length === 0) {
            alert('テンプレートに有効なトークンが含まれていません。');
            return;
        }

        const list = document.getElementById(prefix + '_items_list');
        if (!list) return;

        const currentCount = replace ? 0 : list.querySelectorAll('.koto-blessing-item').length;
        if (currentCount + tokens.length > MAX_BLESSING_ITEMS) {
            alert('祝福は最大' + MAX_BLESSING_ITEMS + '項目までです。テンプレートを展開すると上限を超過します（展開後: ' + (currentCount + tokens.length) + '件）。');
            return;
        }

        if (replace && list.children.length > 0) {
            if (!confirm('現在の祝福の並びを置き換えてテンプレートを展開しますか？')) {
                return;
            }
            list.innerHTML = '';
        }

        const presetAttr1 = (document.getElementById(prefix + '_preset_attr1')?.value || '').trim();
        const presetAttr2 = (document.getElementById(prefix + '_preset_attr2')?.value || '').trim();
        const presetSpecies1 = (document.getElementById(prefix + '_preset_species1')?.value || '').trim();
        const presetSpecies2 = (document.getElementById(prefix + '_preset_species2')?.value || '').trim();

        tokens.forEach(function (token) {
            let baseToken = token;
            let targetVal = '';

            const mAttr = token.match(/^attr_killer_?(\d+)$/i);
            const mSpecies = token.match(/^species_killer_?(\d+)$/i);
            const mMitigation = token.match(/^(?:attr_)?mitigation_?(\d+)$/i);
            const mAttr30 = token.match(/^attr_30_killer_?(\d+)$/i);

            if (mAttr) {
                baseToken = 'attr_killer';
                const idx = parseInt(mAttr[1], 10);
                targetVal = (idx === 1) ? presetAttr1 : ((idx === 2) ? presetAttr2 : '');
            } else if (mSpecies) {
                baseToken = 'species_killer';
                const idx = parseInt(mSpecies[1], 10);
                targetVal = (idx === 1) ? presetSpecies1 : ((idx === 2) ? presetSpecies2 : '');
            } else if (mMitigation) {
                baseToken = 'attr_mitigation';
                const idx = parseInt(mMitigation[1], 10);
                targetVal = (idx === 1) ? presetAttr1 : ((idx === 2) ? presetAttr2 : '');
            } else if (mAttr30) {
                baseToken = 'attr_30_killer';
                const idx = parseInt(mAttr30[1], 10);
                targetVal = (idx === 1) ? presetAttr1 : ((idx === 2) ? presetAttr2 : '');
            }

            const itemEl = createTokenItemElement(prefix, baseToken, targetVal);
            list.appendChild(itemEl);
        });

        updateBlessingCount(prefix);
    }

    // イベントリスナー登録
    document.addEventListener('click', function (e) {
        // 1. トークン追加ボタン
        const addTokenBtn = e.target.closest('.koto-btn-add-token');
        if (addTokenBtn) {
            const prefix = addTokenBtn.getAttribute('data-prefix');
            const tokenSelect = document.getElementById(prefix + '_token_select');
            const tokenKey = (tokenSelect?.value || '').trim();
            if (!tokenKey) {
                alert('追加するトークンを選択してください。');
                return;
            }
            const list = document.getElementById(prefix + '_items_list');
            if (list) {
                const currentCount = list.querySelectorAll('.koto-blessing-item').length;
                if (currentCount >= MAX_BLESSING_ITEMS) {
                    alert('祝福は最大' + MAX_BLESSING_ITEMS + '項目までです。これ以上追加できません。');
                    return;
                }
                const itemEl = createTokenItemElement(prefix, tokenKey, '');
                list.appendChild(itemEl);
                updateBlessingCount(prefix);
                if (window.jQuery && jQuery(tokenSelect).hasClass('select2-hidden-accessible')) {
                    jQuery(tokenSelect).val('').trigger('change');
                } else if (tokenSelect) {
                    tokenSelect.value = '';
                }
            }
            return;
        }

        // 2. 自由入力追加ボタン
        const addFreeBtn = e.target.closest('.koto-btn-add-free');
        if (addFreeBtn) {
            const prefix = addFreeBtn.getAttribute('data-prefix');
            const ptInput = document.getElementById(prefix + '_free_pt');
            const textInput = document.getElementById(prefix + '_free_text');
            const pt = (ptInput?.value || '').trim();
            const text = (textInput?.value || '').trim();
            if (!text) {
                alert('とくせい文言を入力してください。');
                return;
            }
            const list = document.getElementById(prefix + '_items_list');
            if (list) {
                const currentCount = list.querySelectorAll('.koto-blessing-item').length;
                if (currentCount >= MAX_BLESSING_ITEMS) {
                    alert('祝福は最大' + MAX_BLESSING_ITEMS + '項目までです。これ以上追加できません。');
                    return;
                }
                const itemEl = createFreeItemElement(prefix, pt, text);
                list.appendChild(itemEl);
                updateBlessingCount(prefix);
                if (ptInput) ptInput.value = '';
                if (textInput) textInput.value = '';
            }
            return;
        }

        // 3. テンプレート適用（置換展開）
        const applyTplBtn = e.target.closest('.koto-btn-apply-template');
        if (applyTplBtn) {
            const prefix = applyTplBtn.getAttribute('data-prefix');
            applyTemplateToItems(prefix, true);
            return;
        }

        // 4. テンプレート追加（末尾展開）
        const appendTplBtn = e.target.closest('.koto-btn-append-template');
        if (appendTplBtn) {
            const prefix = appendTplBtn.getAttribute('data-prefix');
            applyTemplateToItems(prefix, false);
            return;
        }

        // 5. アイテム行削除（×）
        const removeItemBtn = e.target.closest('.koto-btn-remove-item');
        if (removeItemBtn) {
            const item = removeItemBtn.closest('.koto-blessing-item');
            const list = item ? item.closest('.koto-blessing-items-list') : null;
            if (item && list) {
                const prefix = list.getAttribute('data-prefix');
                item.remove();
                updateBlessingCount(prefix);
            }
            return;
        }

        // 6. アイテム上へ移動（▲）
        const moveUpBtn = e.target.closest('.koto-btn-move-up');
        if (moveUpBtn) {
            const item = moveUpBtn.closest('.koto-blessing-item');
            const prev = item ? item.previousElementSibling : null;
            if (item && prev) {
                item.parentElement.insertBefore(item, prev);
            }
            return;
        }

        // 7. アイテム下へ移動（▼）
        const moveDownBtn = e.target.closest('.koto-btn-move-down');
        if (moveDownBtn) {
            const item = moveDownBtn.closest('.koto-blessing-item');
            const next = item ? item.nextElementSibling : null;
            if (item && next) {
                item.parentElement.insertBefore(next, item);
            }
            return;
        }

        // 8. すべてクリア
        const clearBtn = e.target.closest('.koto-btn-clear-items');
        if (clearBtn) {
            const prefix = clearBtn.getAttribute('data-prefix');
            const list = document.getElementById(prefix + '_items_list');
            if (list && list.children.length > 0) {
                if (confirm('祝福の並びをすべてクリアしますか？')) {
                    list.innerHTML = '';
                    updateBlessingCount(prefix);
                }
            }
            return;
        }
    });

    // 初期化実行
    setTimeout(function () {
        initBlessingSelect2();
        initBlessingSortable();
        updateBlessingCount('blessing');
        updateBlessingCount('ex_blessing');
    }, 200);

    function getBlessingBoxPayload(prefix) {
        const rarity = (document.getElementById(prefix + '_rarity_select')?.value || '').trim();
        const list = document.getElementById(prefix + '_items_list');
        if (!list) return '';

        const itemEls = list.querySelectorAll('.koto-blessing-item');
        if (itemEls.length === 0) {
            return '';
        }

        const items = [];
        itemEls.forEach(function (el) {
            const itemType = el.getAttribute('data-item-type');
            if (itemType === 'token') {
                const tokenKey = el.getAttribute('data-token');
                const targetSel = el.querySelector('select.koto-item-target');
                const targetVal = (targetSel?.value || '').trim();
                items.push({
                    type: 'token',
                    token: tokenKey,
                    target: targetVal
                });
            } else if (itemType === 'free') {
                const pt = (el.querySelector('.koto-item-pt')?.value || '').trim();
                const text = (el.querySelector('.koto-item-text')?.value || '').replace(/[^\S\r\n]+/g, '').trim();
                if (text !== '') {
                    items.push({
                        type: 'free',
                        point: pt,
                        text: text
                    });
                }
            }
        });

        if (items.length === 0) {
            return '';
        }

        return JSON.stringify({
            rarity: rarity,
            items: items
        });
    }

    function getAutoInputData() {
        // 汎用テキスト用: 改行以外の空白を取り除く
        const getSanitizedText = (id) => {
            const val = document.getElementById(id)?.value || '';
            return val.replace(/[^\S\r\n]+/g, '');
        };

        // キャラクター名用: 前後の空白のみ除去して内部スペースは保持
        const getName = (id) => {
            const val = document.getElementById(id)?.value || '';
            return val.trim();
        };

        return {
            character_name: getName('auto_input_character_name'),
            // auto-input-追加必須: HTML側の入力欄を texts に追加
            texts: {
                auto_input_waza_name: getSanitizedText('auto_input_waza_name'),
                auto_input_waza: getSanitizedText('auto_input_waza'),
                auto_input_sugowaza_name: getSanitizedText('auto_input_sugowaza_name'),
                auto_input_sugowaza: getSanitizedText('auto_input_sugowaza'),
                auto_input_sugowaza_condition: getSanitizedText('auto_input_sugowaza_condition'),
                auto_input_leader: getSanitizedText('auto_input_leader'),
                auto_input_trait1: getSanitizedText('auto_input_trait1'),
                auto_input_trait2: getSanitizedText('auto_input_trait2'),
                auto_input_blessing: getBlessingBoxPayload('blessing'),
                auto_input_ex_blessing: getBlessingBoxPayload('ex_blessing'),
                // 0〜4凸の入力値を配列化して取得
                auto_input_kotowaza: [0, 1, 2, 3, 4].map(i => ({
                    condition: getSanitizedText(`auto_input_kotowaza_cond_${i}`),
                    effect: getSanitizedText(`auto_input_kotowaza_effect_${i}`)
                }))
            }
        };
    }

    if (btnAutoInputFill) {
        btnAutoInputFill.addEventListener('click', function () {
            if (window.kotoIsFormSubmitting) {
                alert('現在フォームの保存処理中です。画面の切り替え・保存が完了してから実行してください。');
                return;
            }

            const data = getAutoInputData();
            const editPostId = document.getElementById('real_edit_post_id')?.value;

            if (!editPostId) {
                alert('反映先の記事（左側）が選択されていません。');
                return;
            }

            if (!confirm('現在の記事（左側）に自動入力の内容を反映してよろしいですか？\n※保存済みのデータに追記されます。画面がリロードされます。')) {
                return;
            }

            window.kotoSkipBeforeUnloadSave = true;
            btnAutoInputFill.disabled = true;
            btnAutoInputFill.textContent = '反映中...';

            jQuery.ajax({
                // 手動でXdebugを開始する場合は下記コメントを外して直接指定も可能:
                // url: ajaxurl + (ajaxurl.indexOf('?') === -1 ? '?' : '&') + 'XDEBUG_SESSION_START=VSCODE',
                url: getAjaxUrl(),
                type: 'POST',
                data: {
                    action: 'koto_update_post_from_auto_input',
                    post_id: editPostId,
                    texts: data.texts,
                },
                success: function (response) {
                    if (response.success) {
                        window.kotoSkipBeforeUnloadSave = true;
                        let msg = response.data?.message || '自動入力を反映しました。内容を保存します。';
                        if (response.data?.warnings && response.data.warnings.length > 0 && !msg.includes('【警告】')) {
                            msg += '\n\n【警告】\n・' + response.data.warnings.join('\n・');
                        }
                        alert(msg);
                        location.reload();
                    } else {
                        window.kotoSkipBeforeUnloadSave = false;
                        alert('エラー: ' + (response.data?.message || '通信に失敗しました'));
                        btnAutoInputFill.disabled = false;
                        btnAutoInputFill.textContent = 'これらの内容を自動入力';
                    }
                },
                error: function () {
                    window.kotoSkipBeforeUnloadSave = false;
                    alert('サーバーエラーが発生しました。');
                    btnAutoInputFill.disabled = false;
                    btnAutoInputFill.textContent = 'これらの内容を自動入力';
                }
            });
        });
    }

    if (btnAutoInputMake) {
        btnAutoInputMake.addEventListener('click', function () {
            if (window.kotoIsFormSubmitting) {
                alert('現在フォームの保存処理中です。画面の切り替え・保存が完了してから実行してください。');
                return;
            }

            const data = getAutoInputData();

            if (!confirm('入力された内容で新しい記事を作成してよろしいですか？')) {
                return;
            }

            window.kotoSkipBeforeUnloadSave = true;
            btnAutoInputMake.disabled = true;
            btnAutoInputMake.textContent = '作成中...';

            jQuery.ajax({
                // 手動でXdebugを開始する場合は下記コメントを外して直接指定も可能:
                // url: ajaxurl + (ajaxurl.indexOf('?') === -1 ? '?' : '&') + 'XDEBUG_SESSION_START=VSCODE',
                url: getAjaxUrl(),
                type: 'POST',
                data: {
                    action: 'koto_create_post_from_auto_input',
                    character_name: data.character_name,
                    texts: data.texts
                },
                success: function (response) {
                    if (response.success) {
                        window.kotoSkipBeforeUnloadSave = true;
                        let msg = response.data?.message || '記事を作成しました';
                        if (response.data?.warnings && response.data.warnings.length > 0 && !msg.includes('【警告】')) {
                            msg += '\n\n【警告】\n・' + response.data.warnings.join('\n・');
                        }
                        alert(msg);
                        window.location.href = response.data.edit_url;
                    } else {
                        window.kotoSkipBeforeUnloadSave = false;
                        alert('エラー: ' + (response.data?.message || '通信に失敗しました'));
                        btnAutoInputMake.disabled = false;
                        btnAutoInputMake.textContent = 'これらの内容を自動入力して記事を作成';
                    }
                },
                error: function () {
                    window.kotoSkipBeforeUnloadSave = false;
                    alert('サーバーエラーが発生しました。');
                    btnAutoInputMake.disabled = false;
                    btnAutoInputMake.textContent = 'これらの内容を自動入力して記事を作成';
                }
            });
        });
    }
});