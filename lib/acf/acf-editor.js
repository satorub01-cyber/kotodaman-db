(function ($) {
    $(document).ready(function () {


        // ★修正: フィールドグループ選択を一本化し、自動保存を廃止
        // 右側の選択肢（source_group）を隠して、左側（acf_group）のみで操作させる
        var $sourceGroup = $('select[name="source_group"]');
        if ($sourceGroup.length) {
            var $fieldWrapper = $sourceGroup.closest('.acf-field');
            if ($fieldWrapper.length) {
                $fieldWrapper.hide();
            } else {
                $sourceGroup.hide();
            }
        }

        $('select[name="acf_group"]').on('change', function () {
            var nextGroup = $(this).val();

            // 隠れている右側にも同期（念のため）
            if ($sourceGroup.length) {
                $sourceGroup.val(nextGroup);
            }

            // 保存後に「次に開くグループ」のURLへ飛ぶようにフォーム送信先を書き換え
            var url = new URL(window.location.href);
            url.searchParams.set('acf_group', nextGroup);
            $('#post').attr('action', url.toString());

            // 自動保存（再読み込み）処理は削除しました
        });

        // =================================================================
        // 2. 一括コピー、ショートカット、保存機能、警告解除
        // =================================================================

        // --- 一括コピーの実行 (ボタンクリック ＆ Ctrl+Enter) ---
        function executeMultiCopy() {
            var items = [];
            $('.multi-copy-check:checked').each(function () {
                items.push({
                    field_key: $(this).data('field-key'),
                    row_index: $(this).data('row-index')
                });
            });
            if (items.length === 0) {
                alert('コピーする行がチェックされていません。');
                return;
            }
            if (confirm(items.length + ' 件の行を左の投稿へ一括コピーします。よろしいですか？')) {
                $('#copy_items_json').val(JSON.stringify(items));
                $('#multi-copy-form').submit();
            }
        }
        $('#btn_execute_multi_copy').on('click', executeMultiCopy);

        // --- 「サイトを離れますか？」のアラートを完全に無効化 ---
        if (typeof acf !== 'undefined') {
            acf.unload.active = false;
            acf.addAction('append', function () { acf.unload.active = false; });
        }
        window.onbeforeunload = null;
        $(window).off('beforeunload');

        // --- 追従アクションバーの保存ボタン処理 ---
        $('#btn_draft_sticky').on('click', function () {
            $('#custom_post_status').val('draft');
            $('#acf_real_submit').click();
        });
        $('#btn_publish_sticky').on('click', function () {
            $('#custom_post_status').val('publish');
            $('#acf_real_submit').click();
        });

        // --- 各種ショートカット ---
        $(document).on('keydown', function (e) {
            // Ctrl+S (保存)
            if (e.ctrlKey && (e.key === 's' || e.key === 'S')) {
                e.preventDefault();
                // ★修正: ステータスに応じてクリックするボタンを分ける
                if (typeof kotoCurrentPostStatus !== 'undefined' && kotoCurrentPostStatus === 'draft') {
                    if ($('#btn_draft_sticky').length) $('#btn_draft_sticky').click();
                } else {
                    if ($('#btn_publish_sticky').length) $('#btn_publish_sticky').click();
                }
            }
            // Ctrl+Enter (一括コピー)
            if (e.ctrlKey && e.key === 'Enter') {
                e.preventDefault();
                executeMultiCopy();
            }
        });

        // =================================================================
        // 3. 親ターム選択の自作UI化（新規追加ポップアップ乗っ取り）
        // =================================================================
        function createCustomSelector($container) {
            // ★修正: ACFは `data-name` を select 自身ではなく親の div に付ける仕様のため、親の div を基準に select を探す
            // 加えて、新規追加ポップアップ (.acf-popup) の中にある select を徹底的に狙い撃ちします
            var $selects = $container.find('.acf-popup select, .acf-field[data-name="parent"] select, .acf-field[data-name="term_parent"] select').filter(function () {
                // 通常の選択肢などを巻き込まないよう、名前に「parent」を含むものだけを厳選
                var name = $(this).attr('name') || '';
                var dataName = $(this).closest('.acf-field').attr('data-name') || '';
                return name.indexOf('parent') !== -1 || dataName.indexOf('parent') !== -1;
            });

            $selects.each(function () {
                var $originSelect = $(this);

                // すでに適用済みならスキップ
                if ($originSelect.next('.custom-term-selector-wrap').length) return;

                // ACFの既存Select2を破壊して隠す
                if ($originSelect.hasClass('select2-hidden-accessible')) {
                    $originSelect.select2('destroy');
                }
                $originSelect.hide();

                // UI生成
                var $wrap = $('<div class="custom-term-selector-wrap" style="margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; background: #fff; padding: 5px;"></div>');
                var $search = $('<input type="text" class="custom-term-search" placeholder="親タームを検索..." style="width: 100%; box-sizing: border-box; padding: 8px; margin-bottom: 5px; border: 1px solid #ddd; border-radius: 3px; font-size: 14px !important;" />');
                var $list = $('<div class="custom-term-list" style="height: 250px; overflow-y: auto; border-top: 1px solid #eee; padding: 5px 0;"></div>');

                var currentVal = $originSelect.val();
                var options = [];

                $originSelect.find('option').each(function () {
                    var $opt = $(this);
                    var text = $opt.text();
                    var val = $opt.val();
                    var prefixMatch = text.match(/^[\s\u00A0\-]*/);
                    var level = prefixMatch ? prefixMatch[0].length : 0;
                    var cleanText = text.replace(/^[\s\u00A0\-]+/, '');

                    if (val !== '-1' && val !== '') {
                        options.push({
                            val: val, text: cleanText, level: level,
                            selected: (val == currentVal),
                            $element: null
                        });
                    }
                });

                var stack = [{ level: -1, container: $list }];

                for (var i = 0; i < options.length; i++) {
                    var opt = options[i];
                    var nextOpt = options[i + 1];

                    var $item = $('<div class="term-item" data-val="' + opt.val + '" style="padding: 6px 8px; cursor: pointer; font-size: 13px; border-radius: 3px;">' + opt.text + '</div>');
                    if (opt.selected) {
                        $item.addClass('selected').css({ 'background-color': '#2271b1', 'color': '#fff', 'font-weight': 'bold' });
                    }
                    opt.$element = $item;

                    var isParent = (nextOpt && nextOpt.level > opt.level);

                    while (stack.length > 1 && stack[stack.length - 1].level >= opt.level) {
                        stack.pop();
                    }
                    var parentContainer = stack[stack.length - 1].container;

                    if (isParent) {
                        var $details = $('<details style="margin-bottom: 2px;">');
                        var $summary = $('<summary class="term-summary" style="list-style: none; cursor: pointer;">').append($item);
                        var $childrenContainer = $('<div class="term-children" style="margin-left: 20px; border-left: 1px solid #eee;">');
                        $details.append($summary).append($childrenContainer);
                        parentContainer.append($details);
                        stack.push({ level: opt.level, container: $childrenContainer });
                    } else {
                        parentContainer.append($item);
                    }
                }

                $wrap.append($search).append($list);
                $originSelect.after($wrap);

                // --- アクションのバインド ---
                $list.on('click', '.term-item', function (e) {
                    var $clicked = $(this);
                    var val = $clicked.data('val');
                    $list.find('.term-item').removeClass('selected').css({ 'background-color': '', 'color': '', 'font-weight': 'normal' });
                    $clicked.addClass('selected').css({ 'background-color': '#2271b1', 'color': '#fff', 'font-weight': 'bold' });
                    $originSelect.val(val).trigger('change');
                });

                $search.on('input', function () {
                    var keyword = $(this).val().toLowerCase().trim();
                    $list.find('.term-item').hide();
                    $list.find('details').removeAttr('open');
                    $list.find('.term-children').hide();

                    if (keyword === '') {
                        $list.find('.term-item, .term-children').show();
                        return;
                    }

                    options.forEach(function (opt) {
                        if (opt.text.toLowerCase().indexOf(keyword) > -1) {
                            var $el = opt.$element;
                            $el.show();
                            var $detailsAsParent = $el.closest('details');
                            if ($detailsAsParent.length && $detailsAsParent.find('summary').has($el).length) {
                                $detailsAsParent.attr('open', true);
                                $detailsAsParent.find('.term-children, .term-children .term-item').show();
                            }
                            $el.parents('details').each(function () {
                                var $parentDetails = $(this);
                                $parentDetails.attr('open', true).show();
                                $parentDetails.find('> summary .term-item, > .term-children').show();
                            });
                        }
                    });
                });

                $search.on('keypress', function (e) { if (e.which === 13) e.preventDefault(); });
                $list.on('mouseenter', '.term-item:not(.selected)', function () { $(this).css('background-color', '#f0f0f1'); })
                    .on('mouseleave', '.term-item:not(.selected)', function () { $(this).css('background-color', ''); });
            });
        }

        // --- 初期ロード ＆ ACFのアクションフックで確実に発火させる ---
        createCustomSelector($('body'));
        if (typeof acf !== 'undefined') {
            acf.addAction('append', function ($el) {
                createCustomSelector($el);
            });
            acf.addAction('new_field', function (field) {
                // ★修正: new_fieldフックは要素ではなくオブジェクトを返すため、.$el プロパティを取り出して渡す
                if (field && field.$el) {
                    createCustomSelector(field.$el);
                }
            });
        }

        // ★修正: リピーターの追加ボタンに誤爆して処理を破壊しないよう、タクソノミーの新規追加ボタンのみに限定
        $(document).on('click', '[data-name="add_term"]', function () {
            // 対象も body 全体ではなく、ポップアップの中だけに限定して安全にする
            setTimeout(function () { createCustomSelector($('.acf-popup')); }, 50);
            setTimeout(function () { createCustomSelector($('.acf-popup')); }, 200);
            setTimeout(function () { createCustomSelector($('.acf-popup')); }, 500);
        });

        // 念のためのDOM監視（MutationObserver）
        var observer = new MutationObserver(function (mutations) {
            var shouldScan = false;
            mutations.forEach(function (mutation) {
                if (mutation.addedNodes.length) shouldScan = true;
            });
            if (shouldScan) createCustomSelector($('body'));
        });
        observer.observe(document.body, { childList: true, subtree: true });

    });
})(jQuery);