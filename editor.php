<?php
// =================================================================
// 1. 親ターム選択肢の読み込み上限解除 (PHP)
// =================================================================
add_filter('wp_dropdown_cats_args', 'force_load_large_amount_terms_wp', 99, 2);
function force_load_large_amount_terms_wp($args, $r)
{
    if (isset($args['name']) && ($args['name'] === 'parent' || $args['name'] === 'term_parent' || $args['name'] === 'newparent')) {
        $args['number'] = 5000;
        $args['hide_empty'] = 0;
    }
    return $args;
}

add_filter('acf/fields/taxonomy/query', 'force_load_large_amount_terms_acf', 20, 3);
function force_load_large_amount_terms_acf($args, $field, $post_id)
{
    $args['number'] = 5000;
    $args['hide_empty'] = false;
    return $args;
}

// =================================================================
// 2. スマホ対応「自作リストUI」への置換 (JavaScript)
// =================================================================
add_action('admin_footer', 'replace_select_with_custom_ui');

function replace_select_with_custom_ui()
{
    global $pagenow;
    $valid_pages = ['post.php', 'post-new.php', 'edit-tags.php', 'term.php'];
    if (!in_array($pagenow, $valid_pages)) return;
?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {

            // ------------------------------------------------
            // プルダウンを「折りたたみ対応リスト」に置き換える関数
            // ------------------------------------------------
            function createCustomSelector($container) {
                var $selects = $container.find('select[name="term_parent"], select#term_parent, select[name="parent"], select#parent');

                $selects.each(function() {
                    var $originSelect = $(this);

                    // 既に変換済みならスキップ
                    if ($originSelect.next('.custom-term-selector-wrap').length) return;

                    // 1. 元のプルダウンを隠す
                    $originSelect.hide();

                    // 2. 外枠と検索窓を作る
                    var $wrap = $('<div class="custom-term-selector-wrap"></div>');
                    var $search = $('<input type="text" class="custom-term-search" placeholder="親タームを検索..." />');
                    var $list = $('<div class="custom-term-list"></div>');

                    // ------------------------------------------------
                    // 3. 階層構造の解析とリスト生成
                    // ------------------------------------------------
                    var currentVal = $originSelect.val();
                    var options = [];

                    // まず全てのオプション情報を配列化
                    $originSelect.find('option').each(function() {
                        var $opt = $(this);
                        var text = $opt.text();
                        var val = $opt.val();

                        // インデント（空白やハイフン）の長さを測って階層レベルを判定
                        // \u00A0 は &nbsp; (non-breaking space) です
                        var prefixMatch = text.match(/^[\s\u00A0\-]*/);
                        var level = prefixMatch ? prefixMatch[0].length : 0;
                        var cleanText = text.replace(/^[\s\u00A0\-]+/, ''); // インデント除去した名前

                        if (val !== '-1') {
                            options.push({
                                val: val,
                                text: cleanText,
                                fullText: text, // 検索用（念のため）
                                level: level,
                                selected: (val == currentVal),
                                $element: null // 後でDOMを入れる
                            });
                        }
                    });

                    // 階層構造をDOMとして構築
                    // スタックを使って「現在の親コンテナ」を管理します
                    var stack = [{
                        level: -1,
                        container: $list
                    }];

                    for (var i = 0; i < options.length; i++) {
                        var opt = options[i];
                        var nextOpt = options[i + 1];

                        // アイテムのDOM作成
                        var $item = $('<div class="term-item" data-val="' + opt.val + '">' + opt.text + '</div>');
                        if (opt.selected) $item.addClass('selected');
                        opt.$element = $item; // 検索時に参照できるように保存

                        // 次の要素が「自分の子供」か判定（レベルが深くなっているか）
                        var isParent = (nextOpt && nextOpt.level > opt.level);

                        // 適切な親コンテナを探して追加
                        // 現在のスタックのレベルより浅い場合は、スタックから戻る
                        while (stack.length > 1 && stack[stack.length - 1].level >= opt.level) {
                            stack.pop();
                        }
                        var parentContainer = stack[stack.length - 1].container;

                        if (isParent) {
                            // 子供がいる場合：detailsタグで包む
                            var $details = $('<details>'); // 初期状態は閉じる（open属性なし）
                            var $summary = $('<summary class="term-summary">').append($item);
                            var $childrenContainer = $('<div class="term-children">');

                            $details.append($summary).append($childrenContainer);
                            parentContainer.append($details);

                            // 子供用コンテナをスタックに追加
                            stack.push({
                                level: opt.level,
                                container: $childrenContainer
                            });
                        } else {
                            // 子供がいない場合：そのまま追加
                            parentContainer.append($item);
                        }
                    }

                    // 4. 組み立てて挿入
                    $wrap.append($search).append($list);
                    $originSelect.after($wrap);

                    // ------------------------------------------------
                    // 動作ロジック
                    // ------------------------------------------------

                    // A. クリック時の動作 (選択反映)
                    $list.on('click', '.term-item', function(e) {
                        // detailsの開閉クリックと被らないように制御
                        // (term-item自体をクリックした時のみ反応)
                        var $clicked = $(this);
                        var val = $clicked.data('val');

                        // 見た目の更新
                        $list.find('.term-item').removeClass('selected');
                        $clicked.addClass('selected');

                        // 元の隠れたプルダウンに値をセット
                        $originSelect.val(val).trigger('change');
                    });

                    // B. 検索時の動作 (ヒットした親の子も表示＆展開)
                    $search.on('input', function() {
                        var keyword = $(this).val().toLowerCase().trim();

                        // まず全部隠す & 閉じる
                        $list.find('.term-item').hide();
                        $list.find('details').removeAttr('open');
                        $list.find('.term-children').hide(); // 子供エリアも念の為隠す

                        if (keyword === '') {
                            // --- キーワード空欄時：初期状態に戻す ---
                            $list.find('.term-item').show();
                            $list.find('.term-children').show();
                            // detailsは閉じたまま（初期状態）
                            return;
                        }

                        // --- 検索実行 ---
                        // options配列を使って判定
                        options.forEach(function(opt) {
                            // 名前が一致するか？
                            if (opt.text.toLowerCase().indexOf(keyword) > -1) {
                                var $el = opt.$element;

                                // 1. 自分を表示
                                $el.show();

                                // 2. 自分が「親」の場合 (detailsの中のsummaryにいる)
                                //    => その下の子供たち(.term-children内の要素)をすべて表示し、detailsを開く
                                var $detailsAsParent = $el.closest('details');
                                if ($detailsAsParent.length && $detailsAsParent.find('summary').has($el).length) {
                                    $detailsAsParent.attr('open', true);
                                    $detailsAsParent.find('.term-children').show();
                                    $detailsAsParent.find('.term-children .term-item').show(); // 子タームを強制表示
                                }

                                // 3. 自分が「子」の場合
                                //    => 上流の親(details)をすべて開いていく
                                $el.parents('details').each(function() {
                                    var $parentDetails = $(this);
                                    $parentDetails.attr('open', true);
                                    $parentDetails.show();
                                    $parentDetails.find('> summary .term-item').show(); // 親の名前も表示
                                    $parentDetails.find('> .term-children').show(); // コンテナ表示
                                });
                            }
                        });
                    });

                    // C. Enterキー無効化
                    $search.on('keypress', function(e) {
                        if (e.which === 13) {
                            e.preventDefault();
                            return false;
                        }
                    });
                });
            }

            // ------------------------------------------------
            // 実行と監視
            // ------------------------------------------------
            createCustomSelector($('body'));

            var observer = new MutationObserver(function(mutations) {
                var shouldScan = false;
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes.length) shouldScan = true;
                });
                if (shouldScan) createCustomSelector($('body'));
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        });
    </script>
    <style>
        /* --- 自作リストのデザイン --- */
        .custom-term-selector-wrap {
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
            padding: 5px;
        }

        .custom-term-search {
            width: 100%;
            box-sizing: border-box;
            padding: 8px;
            margin-bottom: 5px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 16px !important;
        }

        .custom-term-list {
            height: 250px;
            overflow-y: auto;
            border-top: 1px solid #eee;
            padding: 5px 0;
        }

        /* アイテム */
        .term-item {
            padding: 6px 8px;
            cursor: pointer;
            font-size: 13px;
            border-radius: 3px;
        }

        .term-item:hover {
            background-color: #f0f0f1;
        }

        .term-item.selected {
            background-color: #2271b1;
            color: #fff;
            font-weight: bold;
        }

        .term-item.selected:hover {
            background-color: #135e96;
        }

        /* 階層・折りたたみデザイン */
        details {
            margin-bottom: 2px;
        }

        summary {
            list-style: none;
            /* デフォルトの三角を消す(お好みで) */
            cursor: pointer;
        }

        /* 三角アイコンのカスタマイズ（必要な場合） */
        summary::-webkit-details-marker {
            display: inline-block;
            /* 表示する場合 */
            color: #666;
        }

        .term-children {
            margin-left: 20px;
            /* 子供のインデント */
            border-left: 1px solid #eee;
            /* ツリーっぽい線 */
        }
    </style>
<?php
}
?>