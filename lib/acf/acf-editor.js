// グローバルで$を使用できるように
var $ = window.jQuery;

(function ($) {
    $(document).ready(function () {
        // グローバルで$を使用できるように
        window.kotoJQuery = $;

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
        // ★究極版: ローカルストレージを使った、絶対に失敗しない切り替え＆保存処理
        // =================================================================
        $('.group-switch-btn').on('click', function (e) {
            e.preventDefault();
            if ($(this).hasClass('button-primary')) return;

            var nextGroup = $(this).data('group');

            // 1. 次に開きたいグループと、スクロールの指示をブラウザにメモさせる
            localStorage.setItem('koto_next_group', nextGroup);
            localStorage.setItem('koto_auto_scroll', '1');

            if ($('#btn_draft_sticky').length || $('#btn_publish_sticky').length) {
                // 2. URL操作などの細工は一切せず、純粋に ACF 純正の「変更を保存」ボタンを叩く！
                $('#acf_real_submit').click();
            } else {
                // 保存ボタンがない（キャラ未選択）場合は、URLを書き換えて普通に遷移
                var url = new URL(window.location.href);
                url.searchParams.set('acf_group', nextGroup);
                window.location.href = url.toString();
            }
        });

        // =================================================================
        // ★追加: 画面が読み込まれた時、メモがあれば切り替え or スクロールを実行
        // =================================================================
        var savedNextGroup = localStorage.getItem('koto_next_group');
        var savedAutoScroll = localStorage.getItem('koto_auto_scroll');

        // ① 次のグループへの切り替え待ちがある場合
        if (savedNextGroup) {
            // メモを消す（無限ループ防止）
            localStorage.removeItem('koto_next_group');

            var currentUrl = new URL(window.location.href);
            // ★修正: 左(acf_group)か右(source_group)のどちらかが古いままなら、両方とも新しく書き換えて遷移させる
            if (currentUrl.searchParams.get('acf_group') !== savedNextGroup || currentUrl.searchParams.get('source_group') !== savedNextGroup) {
                currentUrl.searchParams.set('acf_group', savedNextGroup);
                currentUrl.searchParams.set('source_group', savedNextGroup); // ★追加: 右側のグループも同期する
                window.location.href = currentUrl.toString();
            }
        }
        // ② グループ切り替えが完了していて、スクロールの指示だけ残っている場合
        else if (savedAutoScroll === '1') {
            localStorage.removeItem('koto_auto_scroll');

            setTimeout(function () {
                var $target = $('#koto-sticky-bar');
                if ($target.length === 0) $target = $('.acf-sticky-actions');

                if ($target.length > 0) {
                    var targetOffset = $target.offset().top - 42;
                    $('html, body').stop().animate({ scrollTop: targetOffset }, 500, 'swing');
                }
            }, 600);
        }

        // =================================================================
        // ★追加: 関係フィールドのタクソノミーフィルターを検索可能にする
        // =================================================================
        setTimeout(function () {
            if ($.fn.select2) {
                $('.acf-relationship .filters select').each(function () {
                    if ($(this).hasClass('select2-hidden-accessible')) {
                        $(this).select2('destroy');
                    }
                    $(this).select2({
                        width: '250px',
                        dropdownAutoWidth: true,
                        language: {
                            noResults: function () { return '見つかりません'; }
                        }
                    });
                });
            }
        }, 500);

        // =================================================================
        // 2. 一括コピー、ショートカット、保存機能、警告解除
        // =================================================================

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

        if (typeof acf !== 'undefined') {
            acf.unload.active = false;
            acf.addAction('append', function () { acf.unload.active = false; });
        }
        window.onbeforeunload = null;
        $(window).off('beforeunload');

        // =================================================================
        // ★追加: いかなる理由での再読み込み・離脱時にも保存処理を走らせる
        // =================================================================
        var isFormSubmitting = false;
        $(document).on('submit', 'form', function () {
            // POST送信を行うフォーム（保存、コピー、雛型作成など）の場合はフラグを立てて二重保存を防ぐ
            if ($(this).attr('method') && $(this).attr('method').toUpperCase() === 'POST') {
                isFormSubmitting = true;
            }
        });

        window.addEventListener('beforeunload', function (e) {
            // POSTフォームの送信中ではなく、かつ編集フォームが存在する場合にバックグラウンド保存
            if (!isFormSubmitting && $('#post').length > 0 && $('#acf_real_submit').length > 0) {
                var form = document.getElementById('post');
                var formData = new FormData(form);

                // ブラウザの再読み込みボタンやタブ閉じ、GETフォームでのキャラ切り替え時に強制保存する
                if (typeof fetch !== 'undefined') {
                    fetch(form.action || window.location.href, {
                        method: 'POST',
                        body: formData,
                        keepalive: true
                    }).catch(function () { });
                } else if (navigator.sendBeacon) {
                    navigator.sendBeacon(form.action || window.location.href, formData);
                }
            }
        });

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
            // 日本語入力中（変換確定のためのEnterなど）は処理を無視して文字入力を優先する
            if (e.isComposing || e.keyCode === 229) {
                return;
            }

            // ★追加: F5 または Ctrl+R での再読み込みをキャッチして保存処理にすり替える
            if (e.key === 'F5' || (e.ctrlKey && (e.key === 'r' || e.key === 'R'))) {
                if ($('#acf_real_submit').length > 0) {
                    e.preventDefault();
                    $('#acf_real_submit').click();
                    return;
                }
            }

            // ★修正: 素のEnterキーでは「絶対に何も起こらない」ようにする
            // Ctrlキー等が押されていない純粋なEnterキーの場合
            if (e.key === 'Enter' && !e.ctrlKey && !e.shiftKey && !e.altKey) {
                // INPUTタグ（1行テキスト等）でのEnterは、ブラウザ標準のフォーム送信(再読み込み)を引き起こすため、それをストップする
                // ※TEXTAREAタグは普通に改行させたいのでストップしない
                if (e.target.tagName === 'INPUT' && !$(e.target).hasClass('select2-search__field')) {
                    e.preventDefault();
                    return; // 保存処理などは一切行わず、ここで処理を捨てる！
                }
            }

            // Ctrl+S (保存)
            if (e.ctrlKey && (e.key === 's' || e.key === 'S')) {
                e.preventDefault();
                $('#acf_real_submit').click();
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

                // =========================================================
                // 追加されたリピーター行の先頭要素に自動フォーカス
                // =========================================================
                if ($el.hasClass('acf-row')) {
                    setTimeout(function () {
                        var $firstInput = $el.find('input:not([type="hidden"]):not([disabled]):not([readonly]), textarea:not([disabled]):not([readonly]), select:not([disabled]):not([readonly])').first();
                        if ($firstInput.length) {
                            $firstInput[0].focus({ preventScroll: true }); // 意図しない画面ジャンプを防ぐためにネイティブAPIを使用
                        }
                    }, 100); // 描画・各種フィールドの初期化待ち
                }
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

        // =================================================================
        // ★追加: テキスト入力からのリピーター一括生成
        // =================================================================
        // 使い方:
        // 1. PHP側で <textarea id="bulk-repeater-input" rows="5" style="width: 100%;"></textarea> と
        //    <button type="button" id="bulk-repeater-generate-btn" class="button">テキストから生成</button>
        //    のようなHTML要素を、この機能を使いたいリピーターの近くに配置します。
        // 2. 下の repeaterFieldKey と、サブフィールドの data-name を実際の値に書き換えます。
        $(document).on('click', '.bulk_acf_auto_inputer', function () {
            const fieldKeyMap = {
                'faund_data': '',     // ★要設定: 「入手方法」リピーターのフィールドキー
                'waza': '',           // ★要設定: 「わざ」リピーターのフィールドキー
                'sugowaza': '',       // ★要設定: 「すごわざ」リピーターのフィールドキー
                'kotowaza': '',       // ★要設定: 「ことわざ」リピーターのフィールドキー
                'leader': '',         // ★要設定: 「リーダー特性」リピーターのフィールドキー
                'trait_1': '',        // ★要設定: 「とくせい1」リピーターのフィールドキー
                'trait_2': '',        // ★要設定: 「とくせい2」リピーターのフィールドキー
                'blessing_trait': 'field_693981fe6d220',
            };
            const attrIdMap = {
                'fire': '85',
                'water': '86',
                'wood': '87',
                'light': '88',
                'dark': '89',
                'void': '90',
                'heaven': '91',
            };
            const fieldType = $(this).attr('id').replace('_bulk', '');
            const inputTextAreaId = fieldType + '_auto_input_textarea';
            const fieldKey = fieldKeyMap[fieldType];

            // ★修正: fieldKeyが空か未定義の場合は、設定がまだだと警告して処理を止める
            if (!fieldKey) {
                alert('JavaScriptエラー: fieldKeyMapに「' + fieldType + '」のフィールドキーが設定されていません。\n\nacf-editor.jsを確認してください。');
                return;
            }

            const $repeater = $('.acf-field[data-key="' + fieldKey + '"]');
            if (!$repeater.length) {
                // ★修正: どのキーで探して見つからなかったか、具体的に表示する
                alert('リピーターが見つかりません。\n\n探したフィールドキー: ' + fieldKey + '\n\nフィールドキーが正しいか、またはこのキャラクター編集画面にそのリピーターが存在するか確認してください。');
                return;
            }

            // テキストエリアから入力値を取得し、空行を除外して配列に変換
            const inputText = $('#' + inputTextAreaId).val();
            const lines = inputText.split('\n').filter(line => line.trim() !== '');

            if (lines.length === 0) {
                alert('入力がありません。');
                return;
            }

            // 処理するデータのキューとして保持
            let dataQueue = [...lines];

            // ACFで行が追加された（append）時のイベントリスナーを定義
            const eventListener = function ($el) {
                // $elの直属の親フィールドのキーが、目的のキーと完全に一致するか判定
                if ($el.closest('.acf-field').attr('data-key') !== fieldKey) {
                    return;
                }

                const currentLine = dataQueue.shift();
                if (typeof currentLine === 'undefined') {
                    setTimeout(() => acf.removeAction('append', eventListener), 0);
                    return;
                }

                const values = currentLine.split(',');
                switch (fieldType) {
                    case 'faund_data':
                        break;
                    case 'waza':
                        break;
                    case 'sugowaza':
                        break;
                    case 'kotowaza':
                        break;
                    case 'leader':
                        break;
                    case 'trait_1':
                        break;
                    case 'trait_2':
                        break;
                    case 'blessing_trait':
                        const traitType = values[0] === 'キラー' ? 'damage_correction' : '';
                        const traitSubType = values[0] === 'キラー' ? 'killer' : '';
                        const traitTarget = values[1] || '';

                        $el.find('[data-name="trait_type"] .acf-input select').val(traitType || '').trigger('change');

                        if (traitType) {
                            $el.find('[data-name="' + traitType + '"] .acf-input select').val(traitSubType || '').trigger('change');
                        }

                        $el.find('[data-name="target_field_group"] [data-name="target_type"] .acf-input select').val('attr' || '').trigger('change');
                        $el.find('[data-name="target_field_group"] [data-name="target_attr"] .acf-input input[type="checkbox"][value="' + attrIdMap[traitTarget] + '"]').prop('checked', true).trigger('change');
                        break;
                }

                if (dataQueue.length > 0) {
                    // 直属の階層にある追加ボタンのみを狙い撃ちしてクリック
                    $repeater.find('> .acf-input > .acf-repeater > .acf-actions [data-event="add-row"]').trigger('click');
                } else {
                    setTimeout(() => acf.removeAction('append', eventListener), 0);
                }
            };

            acf.addAction('append', eventListener);
            // 直属の階層にある追加ボタンのみを狙い撃ちしてクリック
            $repeater.find('> .acf-input > .acf-repeater > .acf-actions [data-event="add-row"]').trigger('click');
        });


        // =================================================================
        // ★追加: テキストエリアの自動リサイズ（改行に応じて広げる）
        // =================================================================
        $(document).on('input', '.auto-resize', function () {
            this.style.height = 'auto'; // 一旦高さをリセット
            this.style.height = (this.scrollHeight + 2) + 'px'; // 中身の高さ(+枠線分)に合わせて広げる
        });

    });
})(jQuery);

jQuery(document).ready(function ($) {

    // =========================================================
    // ページ再読み込みでリセットされる、共有のMapとインデックス
    // (コンソールで確認できるよう window オブジェクトに割り当てます)
    // =========================================================
    window.kotoSharedActiveMap = null;
    window.kotoSharedMapIndex = null;

    // 1. あらかじめ用意した対応表(Map)のリストを共通化
    window.kotoMaps = {
        allOppoMaps: [
            { 'name': 'ブラスト', 'strong': '1.5', 'very_strong': '3.75', 'super_strong': '5.1', 'most_strong': '7.5' },
            { 'name': 'ストーム', 'strong': '1.8', 'very_strong': '3', 'super_strong': '3.6', 'most_strong': '4.8' }
        ],
        singleOppoMaps: [
            { 'name': 'ランス', 'strong': '3.75', 'very_strong': '4.25', 'super_strong': '4.75', 'most_strong': '' },
            { 'name': 'クロー', 'strong': '3.5', 'very_strong': '4.75', 'super_strong': '5.25', 'most_strong': '' },
            { 'name': 'スラッシュ', 'strong': '3', 'very_strong': '5', 'super_strong': '6', 'most_strong': '11' },
            { 'name': 'ショット', 'strong': '2.5', 'very_strong': '6.25', 'super_strong': '8', 'most_strong': '14' },
            { 'name': 'ブロー', 'strong': '2', 'very_strong': '8.5', 'super_strong': '10', 'most_strong': '16' },
            { 'name': 'ブレイド', 'strong': ['4', '0.65', '1.05'], 'very_strong': ['6', '0.7', '1.9'], 'super_strong': ['8', '0.75', '2.35'], 'most_strong': ['10', '1.3', ''] },
            { 'name': 'ナックル', 'strong': ['3', '1', '1.3'], 'very_strong': ['', '', ''], 'super_strong': ['5', '1.2', '2.2'], 'most_strong': ['', '', ''] },
        ],
        multiRandomMaps: [
            { 'name': 'ブラスター', 'strong': ['5', '0.65', '0.9'], 'very_strong': ['7', '0.75', '1.1'], 'super_strong': ['9', '0.85', '1.3'], 'most_strong': ['12', '1.05', ''] },
            { 'name': 'ラッシュ', 'strong': ['4', '0.6', '1.2'], 'very_strong': ['', '', ''], 'super_strong': ['8', '1.1', '2.5'], 'most_strong': ['12', '0.9', '1.1'] },
        ]
    };

    // =========================================================
    // 関係フィールドを一キャラのみ入力可能に
    // =========================================================
    $(document).on('click keydown', '.acf-field-relationship .choices .acf-rel-item', function (e) {
        // キーボード操作の場合は、Enterキー以外は処理を中断
        if (e.type === 'keydown' && e.key !== 'Enter') {
            return;
        }

        if ($(this).closest('.acf-fields').length > 0) return;
        var $field = $(this).closest('.acf-field-relationship');
        var currentId = $(this).data('id'); // 今クリックまたはEnterで選択しようとしているキャラのID

        // 選択済みエリア（.values）にある要素を順番にチェック
        $field.find('.values .acf-rel-item').each(function () {
            var selectedId = $(this).data('id');
            // すでに入っているキャラが、今選んだキャラと違うIDだったら削除ボタンを押す！
            if (selectedId != currentId) {
                $(this).find('[data-name="remove_item"]').click();
            }
        });
    });

    // =========================================================
    // 左側の編集フォーム（acf-editor-main-form）先頭要素への自動フォーカス
    // =========================================================
    setTimeout(function () {
        var $firstInput = $('.acf-editor-main-form').find('input:not([type="hidden"]):not([disabled]):not([readonly]), textarea:not([disabled]):not([readonly]), select:not([disabled]):not([readonly])').first();
        if ($firstInput.length) {
            $firstInput[0].focus({ preventScroll: true }); // 意図しない画面ジャンプを防ぐためにネイティブAPIを使用
        }
    }, 500); // Select2などの初期化や独自のスクロール処理を待つための遅延
});

// =========================================================
// 【ダミーフィールドの連携】公式の new_field アクションを使用
// =========================================================
acf.addAction('new_field/name=_dummy_edit_post_id', function (field) {
    field.on('change', function () {
        var val = field.val();
        var idToSet = (val && val.length > 0) ? val[0] : '';
        $('#real_edit_post_id').val(idToSet);
    });
});

acf.addAction('new_field/name=_dummy_source_post_id', function (field) {
    field.on('change', function () {
        var val = field.val();
        var idToSet = (val && val.length > 0) ? val[0] : '';
        $('#real_source_post_id').val(idToSet);
    });
});

acf.addAction('new_field/name=_dummy_search_template_id', function (field) {
    field.on('change', function () {
        var val = field.val();
        var idToSet = (val && val.length > 0) ? val[0] : '';
        $('#real_search_template_id').val(idToSet);
    });
});

// =========================================================
// トースト通知を表示するグローバル関数
// =========================================================
function showToast(message, duration) {
    duration = duration || 3000;
    var $ = window.jQuery;

    var $toast = $('<div class="koto-toast-notification" style="' +
        'position: fixed;' +
        'top: 50px;' +
        'left: 50%;' +
        'transform: translateX(-50%);' +
        'background: #0071A1;' +
        'color: white;' +
        'padding: 16px 32px;' +
        'border-radius: 50px;' +
        'box-shadow: 0 4px 15px rgba(0,0,0,0.3);' +
        'font-size: 16px;' +
        'font-weight: bold;' +
        'z-index: 99999;' +
        'opacity: 0;' +
        'transition: all 0.3s ease;' +
        'pointer-events: none;' +
        '">' + message + '</div>');

    $('body').append($toast);

    // 表示アニメーション
    setTimeout(function () {
        $toast.css({
            'opacity': '1',
            'top': '60px'
        });
    }, 100);

    // 自動消去
    setTimeout(function () {
        $toast.css({
            'opacity': '0',
            'top': '40px'
        });
        setTimeout(function () {
            $toast.remove();
        }, 300);
    }, duration);
}

// =========================================================
// 【自動入力機能】過去のAとBの組み合わせから使用する対応表(Map)を判定し、自動入力する
// =========================================================

// 行の情報からMapを推測し、学習（保存）するグローバル関数
function learnMapFromRow($row) {
    if (!$row || !$row.length) return false;
    var $ = window.jQuery;

    var prefixField = acf.getField($row.find('.acf-field[data-name="attack_prefix"]'));
    var valueField = acf.getField($row.find('.acf-field[data-name="waza_value"]'));
    var targetField = acf.getField($row.find('.acf-field[data-name="waza_target"]'));
    var hitCountField = acf.getField($row.find('.acf-field[data-name="hit_count"]'));

    if (!prefixField || !valueField) return false;

    var valB = prefixField.val();
    var valA = valueField.val();
    var targetVal = targetField ? targetField.val() : '';
    var hitCountVal = hitCountField ? parseInt(hitCountField.val(), 10) || 1 : 1;

    if (valB && valB !== 'none' && valA) {
        var availableMaps = [];
        if (targetVal === 'all_oppo') {
            availableMaps = window.kotoMaps.allOppoMaps;
        } else if (targetVal === 'random_oppo' || targetVal === 'randomOppo') {
            availableMaps = window.kotoMaps.multiRandomMaps;
        } else if (targetVal === 'single_oppo') {
            availableMaps = window.kotoMaps.singleOppoMaps;
        }

        for (var i = 0; i < availableMaps.length; i++) {
            var mapVal = availableMaps[i][valB];
            if (mapVal === undefined) continue;

            // 配列Mapの場合: [0]=hit_count, [1]=waza_value, [2]=waza_value_last
            // 比較すべきはwaza_value (mapVal[1])
            var compareVal = Array.isArray(mapVal) ? mapVal[1] : mapVal;
            if (compareVal == valA) {
                var prevIndex = window.kotoSharedMapIndex;
                window.kotoSharedActiveMap = availableMaps[i];
                window.kotoSharedMapIndex = i;
                var mapName = availableMaps[i].name || ('Map ' + i);
                console.log('🧠 Learned Map Index:', i, 'from row!');
                if (prevIndex !== i) {
                    showToast('"' + mapName + '" マップを読み込みました！', 3000);
                    // 新しいMapに切り替わった場合、attack_prefixを'none'に変更（直接入力優先）
                    if (prefixField) {
                        prefixField.val('none');
                        prefixField.trigger('change'); // ACFに変更を通知
                    }
                }
                return true;
            }
        }
    }
    return false;
}

// 指定された行に対して自動入力を実行する関数
function autoFillRow($row, selectedType) {
    if (!selectedType || selectedType === 'none') return;
    if (!window.kotoSharedActiveMap) return;

    var targetField = acf.getField($row.find('.acf-field[data-name="waza_target"]'));
    var hitCountField = acf.getField($row.find('.acf-field[data-name="hit_count"]'));
    var targetVal = targetField ? targetField.val() : '';
    var hitCountVal = hitCountField ? parseInt(hitCountField.val(), 10) || 1 : 1;

    var currentAvailableMaps = [];
    if (targetVal === 'all_oppo') {
        currentAvailableMaps = window.kotoMaps.allOppoMaps;
    } else if (targetVal === 'random_oppo' || targetVal === 'randomOppo') {
        currentAvailableMaps = window.kotoMaps.multiRandomMaps;
    } else if (targetVal === 'single_oppo') {
        currentAvailableMaps = window.kotoMaps.singleOppoMaps;
    }

    var activeMap = null;
    if (window.kotoSharedMapIndex !== null && currentAvailableMaps.length > 0 && currentAvailableMaps[window.kotoSharedMapIndex] !== undefined) {
        activeMap = currentAvailableMaps[window.kotoSharedMapIndex];
    } else if (window.kotoSharedActiveMap) {
        activeMap = window.kotoSharedActiveMap;
    }

    if (activeMap && activeMap[selectedType] !== undefined) {
        var targetHitCount = acf.getField($row.find('.acf-field[data-name="hit_count"]'));
        var targetAttackValue = acf.getField($row.find('.acf-field[data-name="waza_value"]'));
        var targetAttackValueLast = acf.getField($row.find('.acf-field[data-name="waza_value_last"]'));
        var mapData = activeMap[selectedType];

        if (Array.isArray(mapData)) {
            // 配列の場合: [0]=hit_count, [1]=waza_value, [2]=waza_value_last
            if (targetHitCount && mapData[0] !== undefined) {
                targetHitCount.val(mapData[0]);
            }
            if (targetAttackValue && mapData[1] !== undefined) {
                targetAttackValue.val(mapData[1]);
            }
            if (targetAttackValueLast && mapData[2] !== undefined) {
                targetAttackValueLast.val(mapData[2]);
            }
        } else {
            // スカラーの場合: waza_valueのみ
            if (targetAttackValue) {
                targetAttackValue.val(mapData);
            }
            if (targetHitCount) {
                targetHitCount.val('');
            }
            if (targetAttackValueLast) {
                targetAttackValueLast.val('');
            }
        }
    }
}

// マップ固定状態を管理
window.kotoMapLocked = false;

// マップ固定ボタンの処理
$(document).on('click', '#koto-lock-map-btn', function () {
    window.kotoMapLocked = !window.kotoMapLocked;
    var $btn = $(this);
    if (window.kotoMapLocked) {
        $btn.html('🔐');
        $btn.css('background', '#0071A1');
        $btn.css('color', 'white');
        var mapName = window.kotoSharedActiveMap ? window.kotoSharedActiveMap.name : '不明';
        showToast('"' + mapName + '" マップを固定しました', 3000);
        console.log('🔐 Map locked:', mapName);
    } else {
        $btn.html('🔒');
        $btn.css('background', '');
        $btn.css('color', '');
        showToast('マップ固定を解除しました', 3000);
        console.log('🔓 Map unlocked');
    }
});

// 手動Map選択ドロップダウンの処理（常に上書き、ただし固定時は無効）
$(document).on('change', '#koto-manual-map-select', function () {
    var selectedValue = $(this).val();
    if (!selectedValue) return;

    // マップ固定時は変更を拒否
    if (window.kotoMapLocked) {
        showToast('マップが固定されています。解除してください。', 3000);
        $(this).val('');
        return;
    }

    var parts = selectedValue.split('_');
    var mapType = parts[0];
    var mapIndex = parseInt(parts[1], 10);

    if (window.kotoMaps[mapType] && window.kotoMaps[mapType][mapIndex]) {
        // 常に上書き（prevIndexチェックなし）
        window.kotoSharedActiveMap = window.kotoMaps[mapType][mapIndex];
        window.kotoSharedMapIndex = mapIndex;
        var mapName = window.kotoMaps[mapType][mapIndex].name || (mapType + ' ' + mapIndex);
        console.log('🗺️ Manually selected Map:', mapType, mapIndex);
        showToast('"' + mapName + '" マップを手動選択しました！', 3000);
    }

    // 選択をリセット
    $(this).val('');
});

// attack_prefix の変更時
acf.addAction('new_field/name=attack_prefix', function (field) {
    // 既にボタンが存在する場合はスキップ（複製対策）
    if (field.$el.siblings('.koto-load-map-btn').length > 0) return;

    // Map読み込みボタンを追加
    var $loadMapBtn = $('<button type="button" class="button koto-load-map-btn" style="margin-left: 10px;">Map読み込み</button>');
    field.$el.after($loadMapBtn);

    $loadMapBtn.on('click', function (e) {
        e.preventDefault();
        var $currentRow = field.$el.closest('.acf-row');
        var learned = learnMapFromRow($currentRow);
        if (!learned) {
            showToast('この行からMapを読み込めませんでした', 3000);
        }
    });

    // 新規行追加時: Map指定済みなら自動入力（現在の値が空/noneでなくても上書き）
    console.log('🔍 [new_field] attack_prefix initialized. currentVal:', field.val(), 'Map:', window.kotoSharedActiveMap ? window.kotoSharedActiveMap.name : 'none');
    if (window.kotoSharedActiveMap) {
        var $currentRow = field.$el.closest('.acf-row');
        console.log('✅ Map exists! Will auto-fill row in 800ms...');
        // ACFの初期化完了を待つ（より長い遅延）
        setTimeout(function () {
            console.log('🚀 Executing autoFillRow with Map:', window.kotoSharedActiveMap.name);
            // 自動入力実行（現在の値があっても上書き）
            var currentPrefix = field.val() || 'strong';
            var result = autoFillRow($currentRow, currentPrefix);
            console.log('📝 Auto-fill result:', result, 'for Map:', window.kotoSharedActiveMap.name);
        }, 800);
    } else {
        console.log('❌ Auto-fill skipped. No Map loaded.');
    }

    field.on('change', function () {
        console.log('✅ attack_prefix changed! New value:', field.val());

        var selectedType = field.val();
        var $currentRow = field.$el.closest('.acf-row');

        if (!selectedType || selectedType === 'none') {
            // "倍率" が "なし" になった場合、連撃系のフィールドをクリアする
            var targetHitCount = acf.getField($currentRow.find('.acf-field[data-name="hit_count"]'));
            var targetAttackValueLast = acf.getField($currentRow.find('.acf-field[data-name="waza_value_last"]'));

            if (targetHitCount) {
                targetHitCount.val('');
            }
            if (targetAttackValueLast) {
                targetAttackValueLast.val('');
            }
        } else {
            // "倍率" が選択された場合は、Mapに基づいて自動入力
            autoFillRow($currentRow, selectedType);
        }
    });
});

// waza_value の変更時もMapを学習・自動入力
acf.addAction('new_field/name=waza_value', function (field) {
    // EnterキーでMap読み込み
    field.$el.on('keydown', function (e) {
        if (e.key === 'Enter') {
            var $currentRow = field.$el.closest('.acf-row');
            learnMapFromRow($currentRow);
        }
    });

    // changeとblur両方で処理（クリックフォーカス移動対策）
    field.$el.on('change blur', function () {
        console.log('✅ waza_value changed/blurred! New value:', field.val());

        var $currentRow = field.$el.closest('.acf-row');
        var prefixField = acf.getField($currentRow.find('.acf-field[data-name="attack_prefix"]'));
        var selectedType = prefixField ? prefixField.val() : '';

        // 編集中の行のみからMapを学習
        learnMapFromRow($currentRow);

        autoFillRow($currentRow, selectedType);
    });
});

// waza_value_last の変更時もMapを学習（ただし配列Mapの場合のみ）
acf.addAction('new_field/name=waza_value_last', function (field) {
    // EnterキーでMap読み込み
    field.$el.on('keydown', function (e) {
        if (e.key === 'Enter') {
            var $currentRow = field.$el.closest('.acf-row');
            learnMapFromRow($currentRow);
        }
    });

    // changeとblur両方で処理（クリックフォーカス移動対策）
    field.$el.on('change blur', function () {
        console.log('✅ waza_value_last changed/blurred! New value:', field.val());

        var $currentRow = field.$el.closest('.acf-row');
        var prefixField = acf.getField($currentRow.find('.acf-field[data-name="attack_prefix"]'));
        var selectedType = prefixField ? prefixField.val() : '';

        // 編集中の行のみからMapを学習
        learnMapFromRow($currentRow);
    });
});