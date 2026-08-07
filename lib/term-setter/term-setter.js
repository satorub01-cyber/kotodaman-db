/**
 * ターム一括付与ページ JavaScript
 */

(function ($) {
    'use strict';

    // 状態管理
    const state = {
        allCharacters: [],
        filteredCharacters: [],
        selectedCharacters: new Set(),
        selectedTerms: [],
    };

    // 検索用に全角/半角を吸収して小文字化するユーティリティ
    const normalizeForSearchLocal = (v) => {
        try { return String(v || '').normalize('NFKC').toLowerCase().trim(); } catch (e) { return String(v || '').toLowerCase().trim(); }
    };

    // DOM要素
    const elements = {
        characterGrid: $('#character-grid'),
        charCount: $('#char-count'),
        selectedCount: $('#selected-count'),
        searchInput: $('#char-search-input'),
        searchById: $('#search-by-id'),
        btnSearch: $('#btn-search-char'),
        btnClear: $('#btn-clear-search'),
        btnSelectAll: $('#btn-select-all'),
        btnDeselectAll: $('#btn-deselect-all'),
        btnApplyTerms: $('#btn-apply-terms'),
        applyResult: $('#apply-result'),
        newTermName: $('#new-term-name'),
        newTermSlug: $('#new-term-slug'),
        btnCreateTerm: $('#btn-create-term'),
        termCreateResult: $('#term-create-result'),
        selectedTermsList: $('#selected-terms-list'),
        termSearchInput: $('#term-search-input'),
        btnClearTermSearch: $('#btn-clear-term-search'),
        parentTermSearch: $('#parent-term-search'),
        btnClearParentSearch: $('#btn-clear-parent-search'),
        btnParentNone: $('#btn-parent-none'),
        parentTermList: $('#parent-term-list'),
        selectedParentId: $('#selected-parent-id'),
        selectedParentDisplay: $('#selected-parent-display'),
    };

    // 親タームデータ
    let parentTermsData = [];

    /**
     * 初期化
     */
    function init() {
        loadCharacters();
        loadParentTerms();
        bindEvents();
    }

    /**
     * 親タームデータを読み込み
     */
    function loadParentTerms() {
        if (!TERM_SETTER_CONFIG.targetTaxonomy) {
            elements.parentTermList.html('<div class="no-parent-terms">タクソノミーが設定されていません</div>');
            return;
        }

        $.ajax({
            url: TERM_SETTER_CONFIG.ajaxUrl,
            type: 'POST',
            data: {
                action: 'term_setter_get_term_hierarchy',
                nonce: TERM_SETTER_CONFIG.nonce,
                taxonomy: TERM_SETTER_CONFIG.targetTaxonomy,
            },
            success: function (response) {
                if (response.success) {
                    parentTermsData = response.data.flat_terms;
                    renderParentTermList(response.data.terms);
                } else {
                    elements.parentTermList.html('<div class="error">親タームの読み込みに失敗しました</div>');
                }
            },
            error: function () {
                elements.parentTermList.html('<div class="error">通信エラーが発生しました</div>');
            }
        });
    }

    /**
     * 親タームリストを描画（階層構造）
     */
    function renderParentTermList(terms, container) {
        const $container = container || elements.parentTermList;
        $container.empty();

        if (terms.length === 0) {
            $container.html('<div class="no-parent-terms">タームがありません</div>');
            return;
        }

        const $list = $('<div class="parent-term-hierarchy"></div>');
        buildHierarchyHtml(terms, $list, 0);
        $container.append($list);

        // 選択イベントをバインド
        $container.on('click', '.parent-term-item', function (e) {
            e.stopPropagation();
            const termId = $(this).data('term-id');
            const termName = $(this).data('term-name');
            selectParentTerm(termId, termName);
        });
    }

    /**
     * 階層HTMLを構築
     */
    function buildHierarchyHtml(terms, $container, level) {
        terms.forEach(function (term) {
            const hasChildren = term.children && term.children.length > 0;
            const indent = '  '.repeat(level);
            const prefix = level > 0 ? '└ ' : '';

            if (hasChildren) {
                const $details = $('<details class="parent-term-details"></details>');
                const $summary = $('<summary class="parent-term-summary"></summary>');
                const $item = $(`<div class="parent-term-item has-children" data-term-id="${term.id}" data-term-name="${escapeHtml(term.name)}" style="padding-left: ${level * 16}px">${prefix}${escapeHtml(term.name)}</div>`);

                $summary.append($item);
                $details.append($summary);

                const $childrenContainer = $('<div class="parent-term-children"></div>');
                buildHierarchyHtml(term.children, $childrenContainer, level + 1);
                $details.append($childrenContainer);

                $container.append($details);
            } else {
                const $item = $(`<div class="parent-term-item" data-term-id="${term.id}" data-term-name="${escapeHtml(term.name)}" style="padding-left: ${level * 16 + (level > 0 ? 8 : 0)}px">${prefix}${escapeHtml(term.name)}</div>`);
                $container.append($item);
            }
        });
    }

    /**
     * HTMLエスケープ
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * 親タームを選択
     */
    function selectParentTerm(termId, termName) {
        elements.selectedParentId.val(termId);
        elements.selectedParentDisplay.find('span').text(termName);

        // 選択状態のスタイル更新
        $('.parent-term-item').removeClass('selected');
        $(`.parent-term-item[data-term-id="${termId}"]`).addClass('selected');
    }

    /**
     * 親タームを検索で絞り込み
     */
    function filterParentTerms() {
        const keyword = normalizeForSearchLocal(elements.parentTermSearch.val());

        if (!keyword) {
            // 検索が空の場合は全て表示
            $('.parent-term-item').show();
            $('details.parent-term-details').removeAttr('open');
            return;
        }

        // 全てのターム項目を一旦非表示
        $('.parent-term-item').hide();

        // キーワードに一致するタームを検索
        const matchedIds = [];
        parentTermsData.forEach(function (term) {
            if (normalizeForSearchLocal(term.name).includes(keyword)) {
                matchedIds.push(term.id);
            }
        });

        // 一致したタームを表示し、親を展開
        matchedIds.forEach(function (id) {
            const $item = $(`.parent-term-item[data-term-id="${id}"]`);
            $item.show();

            // 親details要素を展開
            $item.closest('details').attr('open', true).show();
            $item.parents('details').attr('open', true).show();
        });
    }

    /**
     * 親ターム選択を解除
     */
    function clearParentSelection() {
        elements.selectedParentId.val('');
        elements.selectedParentDisplay.find('span').text('なし（トップレベル）');
        $('.parent-term-item').removeClass('selected');
    }

    /**
     * キャラクターデータを読み込み
     */
    function loadCharacters() {
        elements.characterGrid.html('<div class="loading-message">読み込み中...</div>');

        fetch(TERM_SETTER_CONFIG.jsonUrl + '?v=' + new Date().getTime())
            .then(response => response.json())
            .then(data => {
                state.allCharacters = data;
                state.filteredCharacters = data;
                renderCharacterGrid();
                updateCounts();
            })
            .catch(error => {
                console.error('Failed to load characters:', error);
                elements.characterGrid.html('<div class="error-message">データの読み込みに失敗しました。</div>');
            });
    }

    /**
     * キャラクターグリッドを描画
     */
    function renderCharacterGrid() {
        if (state.filteredCharacters.length === 0) {
            elements.characterGrid.html('<div class="no-results">該当するキャラクターが見つかりません。</div>');
            return;
        }

        const html = state.filteredCharacters.map(char => {
            const isSelected = state.selectedCharacters.has(char.id);
            // 名前から読み（name_ruby）を表示用に処理
            const re = /^(?:(?![^・]*[\(（])(?!(?:[\u30A0-\u30FF]+)・)(?:[^・]+)・(.+)|(.+))$/u;
            const match = char.name.match(re);
            const dispName = match ? (match[1] || match[2]) : char.name;

            return `
                <div class="character-card ${isSelected ? 'is-selected' : ''}" data-id="${char.id}">
                    <label class="character-card-inner">
                        <input type="checkbox" class="char-checkbox" value="${char.id}" ${isSelected ? 'checked' : ''}>
                        <div class="char-thumb">
                            ${char.thumb_url ? `<img src="${char.thumb_url}" alt="${char.name}">` : '<div class="no-thumb">No Image</div>'}
                        </div>
                        <div class="char-info">
                            <div class="char-id">ID: ${char.id}</div>
                            <div class="char-name">${dispName}</div>
                            ${char.name_ruby ? `<div class="char-ruby">${char.name_ruby}</div>` : ''}
                        </div>
                    </label>
                </div>
            `;
        }).join('');

        elements.characterGrid.html(html);
    }

    /**
     * 検索を実行
     */
    function performSearch() {
        const keyword = elements.searchInput.val().trim().toLowerCase();
        const searchById = elements.searchById.is(':checked');

        if (!keyword) {
            state.filteredCharacters = state.allCharacters;
        } else {
            state.filteredCharacters = state.allCharacters.filter(char => {
                if (searchById) {
                    return String(char.id).includes(keyword);
                } else {
                    const searchTarget = [
                        char.name,
                        char.name_ruby || '',
                        String(char.id)
                    ].join(' ').toLowerCase();
                    return searchTarget.includes(keyword);
                }
            });
        }

        renderCharacterGrid();
        updateCounts();
    }

    /**
     * 件数表示を更新
     */
    function updateCounts() {
        elements.charCount.text(`(${state.filteredCharacters.length}件)`);
        elements.selectedCount.text(`選択中: ${state.selectedCharacters.size}件`);

        // 付与ボタンの有効/無効
        const canApply = state.selectedCharacters.size > 0 && state.selectedTerms.length > 0;
        elements.btnApplyTerms.prop('disabled', !canApply);
    }

    /**
     * イベントバインド
     */
    function bindEvents() {
        // 検索
        elements.btnSearch.on('click', performSearch);
        elements.searchInput.on('keypress', function (e) {
            if (e.which === 13) performSearch();
        });
        elements.btnClear.on('click', function () {
            elements.searchInput.val('');
            state.filteredCharacters = state.allCharacters;
            renderCharacterGrid();
            updateCounts();
        });

        // キャラクター選択（デリゲート）
        elements.characterGrid.on('change', '.char-checkbox', function () {
            const id = parseInt($(this).val());
            if ($(this).is(':checked')) {
                state.selectedCharacters.add(id);
                $(this).closest('.character-card').addClass('is-selected');
            } else {
                state.selectedCharacters.delete(id);
                $(this).closest('.character-card').removeClass('is-selected');
            }
            updateCounts();
        });

        // カードクリックでチェック切り替え（ラベル全体をクリック対象に）
        elements.characterGrid.on('click', '.character-card', function (e) {
            // label要素内やチェックボックス自体のクリックはブラウザの標準動作に任せる
            if ($(e.target).closest('label').length > 0 || $(e.target).is('.char-checkbox')) {
                return;
            }

            // チェックボックス要素を取得し、ネイティブのclick()メソッドを実行
            $(this).find('.char-checkbox')[0].click();
        });

        // 全選択/全解除
        elements.btnSelectAll.on('click', function () {
            state.filteredCharacters.forEach(char => {
                state.selectedCharacters.add(char.id);
            });
            renderCharacterGrid();
            updateCounts();
        });

        elements.btnDeselectAll.on('click', function () {
            state.filteredCharacters.forEach(char => {
                state.selectedCharacters.delete(char.id);
            });
            renderCharacterGrid();
            updateCounts();
        });

        // ターム作成
        elements.btnCreateTerm.on('click', createNewTerm);

        // ターム付与
        elements.btnApplyTerms.on('click', applyTerms);

        // タームチェックボックスの変更監視
        $(document).on('change', '.term-checkbox', updateSelectedTerms);

        // ターム検索
        elements.termSearchInput.on('input', filterTerms);
        elements.termSearchInput.on('keypress', function (e) {
            if (e.which === 13) filterTerms();
        });
        elements.btnClearTermSearch.on('click', function () {
            elements.termSearchInput.val('');
            filterTerms();
        });

        // 親ターム選択
        elements.parentTermSearch.on('input', filterParentTerms);
        elements.btnClearParentSearch.on('click', function () {
            elements.parentTermSearch.val('');
            filterParentTerms();
        });
        elements.btnParentNone.on('click', clearParentSelection);

        // ターム階層の展開/折りたたみ（▶ボタン）
        $(document).on('click', '.term-expand-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            toggleTermExpand($(this));
        });

        // ターム階層アイテムのクリック（ラベル以外の空白部分で展開）
        $(document).on('click', '.term-hierarchy-item.has-children', function (e) {
            // ラベルまたはその子要素（チェックボックス、テキスト）がクリックされた場合は何もしない
            if ($(e.target).closest('.term-hierarchy-label').length) {
                return;
            }

            // ▶ボタンの場合は上のハンドラで処理されるので無視
            if ($(e.target).hasClass('term-expand-btn')) {
                return;
            }

            // それ以外（空白部分）をクリックしたら展開/折りたたみ
            const $btn = $(this).find('.term-expand-btn');
            toggleTermExpand($btn);
        });
    }

    /**
     * ターム階層の展開/折りたたみを切り替え
     */
    function toggleTermExpand($btn) {
        const termId = $btn.data('term-id');
        const $children = $(`.term-hierarchy-children[data-parent-id="${termId}"]`);
        const isExpanded = $children.is(':visible');

        if (isExpanded) {
            $children.slideUp(200);
            $btn.text('▶');
        } else {
            $children.slideDown(200);
            $btn.text('▼');
        }
    }

    /**
     * タームを検索で絞り込み（階層対応）
     */
    function filterTerms() {
        const keyword = elements.termSearchInput.val().trim().toLowerCase();

        if (!keyword) {
            // 検索が空の場合は全て表示、子は折りたたみ
            $('.term-hierarchy-item').show();
            $('.term-hierarchy-children').hide();
            $('.term-expand-btn').text('▶');
            return;
        }

        // 全て一旦非表示
        $('.term-hierarchy-item').hide();

        // キーワードに一致するタームを検索して表示
        $('.term-hierarchy-checkbox').each(function () {
            const termName = $(this).data('term-name');
            if (termName.includes(keyword)) {
                const $item = $(this).closest('.term-hierarchy-item');
                const termId = $item.find('.term-expand-btn').data('term-id');

                // このアイテムを表示
                $item.show();

                // 子があれば展開して表示
                if (termId) {
                    const $children = $(`.term-hierarchy-children[data-parent-id="${termId}"]`);
                    $children.show();
                    $item.find('.term-expand-btn').text('▼');
                }

                // 親の子コンテナも表示して親を表示
                $item.parents('.term-hierarchy-children').each(function () {
                    $(this).show();
                    const parentId = $(this).data('parent-id');
                    $(`.term-expand-btn[data-term-id="${parentId}"]`).text('▼');
                });

                // 親アイテムも表示
                $item.parents('.term-hierarchy-children').prev('.term-hierarchy-item').show();
            }
        });
    }

    /**
     * 選択中のタームを更新
     */
    function updateSelectedTerms() {
        const selected = [];

        // タームチェックボックスから選択値を取得
        $('.term-checkbox:checked').each(function () {
            const val = $(this).val();
            if (val) selected.push(parseInt(val));
        });

        state.selectedTerms = [...new Set(selected)];

        // 表示更新
        if (state.selectedTerms.length === 0) {
            elements.selectedTermsList.text('なし');
        } else {
            // ターム名を取得して表示
            const termNames = state.selectedTerms.map(termId => {
                const $checkbox = $(`.term-checkbox[value="${termId}"]`);
                return $checkbox.closest('label').find('span').text() || termId;
            });
            elements.selectedTermsList.text(termNames.join(', '));
        }

        updateCounts();
    }

    /**
     * 新規タームを作成
     */
    function createNewTerm() {
        const name = elements.newTermName.val().trim();
        const slug = elements.newTermSlug.val().trim();

        if (!name) {
            elements.termCreateResult.html('<div class="error">ターム名を入力してください。</div>');
            return;
        }

        if (!TERM_SETTER_CONFIG.targetTaxonomy) {
            elements.termCreateResult.html('<div class="error">対象タクソノミーが設定されていません。</div>');
            return;
        }

        elements.btnCreateTerm.prop('disabled', true);
        elements.termCreateResult.html('<div class="loading">作成中...</div>');

        const parentId = elements.selectedParentId.val();

        $.ajax({
            url: TERM_SETTER_CONFIG.ajaxUrl,
            type: 'POST',
            data: {
                action: 'term_setter_create_term',
                nonce: TERM_SETTER_CONFIG.nonce,
                taxonomy: TERM_SETTER_CONFIG.targetTaxonomy,
                name: name,
                slug: slug || undefined,
                parent: parentId || 0,
            },
            success: function (response) {
                if (response.success) {
                    elements.termCreateResult.html(`<div class="success">ターム「${response.data.name}」を作成しました。</div>`);
                    elements.newTermName.val('');
                    elements.newTermSlug.val('');

                    // ACFフィールドの選択肢を更新（ページリロード）
                    setTimeout(function () {
                        location.reload();
                    }, 1000);
                } else {
                    elements.termCreateResult.html(`<div class="error">${response.data.message}</div>`);
                }
            },
            error: function () {
                elements.termCreateResult.html('<div class="error">通信エラーが発生しました。</div>');
            },
            complete: function () {
                elements.btnCreateTerm.prop('disabled', false);
            }
        });
    }

    /**
     * タームを一括付与
     */
    function applyTerms() {
        const characterIds = Array.from(state.selectedCharacters);
        const termIds = state.selectedTerms;

        if (characterIds.length === 0 || termIds.length === 0) {
            elements.applyResult.html('<div class="error">キャラクターとタームを選択してください。</div>');
            return;
        }

        elements.btnApplyTerms.prop('disabled', true).text('処理中...');
        elements.applyResult.html('<div class="loading">タームを付与しています...</div>');

        $.ajax({
            url: TERM_SETTER_CONFIG.ajaxUrl,
            type: 'POST',
            data: {
                action: 'term_setter_apply_terms',
                nonce: TERM_SETTER_CONFIG.nonce,
                taxonomy: TERM_SETTER_CONFIG.targetTaxonomy,
                term_ids: termIds,
                character_ids: characterIds,
            },
            success: function (response) {
                if (response.success) {
                    elements.applyResult.html(`
                        <div class="success">
                            <p>${response.data.message}</p>
                            <p>成功: ${response.data.success_count}件 / 失敗: ${response.data.failed_count}件</p>
                            ${response.data.errors.length > 0 ? '<ul class="error-list"><li>' + response.data.errors.join('</li><li>') + '</li></ul>' : ''}
                        </div>
                    `);
                } else {
                    elements.applyResult.html(`<div class="error">${response.data.message}</div>`);
                }
            },
            error: function () {
                elements.applyResult.html('<div class="error">通信エラーが発生しました。</div>');
            },
            complete: function () {
                elements.btnApplyTerms.prop('disabled', false).text('選択したタームを付与');
                updateCounts();
            }
        });
    }

    // DOM Ready
    $(document).ready(init);

})(jQuery);
