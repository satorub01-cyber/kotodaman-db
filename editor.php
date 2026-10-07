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
// 管理画面のダッシュボードにウィジェットを登録
add_action('wp_dashboard_setup', 'add_kotodaman_search_dashboard_widget');

function add_kotodaman_search_dashboard_widget()
{
    wp_add_dashboard_widget(
        'kotodaman_official_search_widget',
        'コトダマン公式サイト内検索',
        'render_kotodaman_search_widget'
    );
}

// ウィジェットのHTMLと検索クエリを構築するJSを出力
function render_kotodaman_search_widget()
{
?>
    <div class="kotodaman-search-container">
        <form id="kotodaman-search-form" action="https://www.google.com/search" method="get" target="_blank">
            <input type="text" id="kotodaman-search-input" placeholder="キーワードを入力...">
            <input type="hidden" name="q" id="kotodaman-search-query">
            <button type="submit" class="button button-primary">検索</button>
        </form>
    </div>
    <script>
        // 送信時にhiddenフィールドへ指定の演算子を付与した文字列をセット
        document.getElementById('kotodaman-search-form').addEventListener('submit', function() {
            const inputVal = document.getElementById('kotodaman-search-input').value;
            const queryInput = document.getElementById('kotodaman-search-query');
            queryInput.value = inputVal + ' site:kotodaman.jp -site:portal.kotodaman.jp';
        });
    </script>
    <style>
        .kotodaman-search-container {
            padding: 10px 0;
        }

        #kotodaman-search-form {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        #kotodaman-search-input {
            flex-grow: 1;
            max-width: 100%;
        }
    </style>
<?php
}

// =================================================================
// 4. 非公開固定ページ＆トップページへのリンク集ダッシュボードウィジェット
// =================================================================
add_action('wp_dashboard_setup', 'add_private_pages_dashboard_widget');

function add_private_pages_dashboard_widget()
{
    // 非公開ページを閲覧・編集できる権限があるユーザーにのみ表示
    if (!current_user_can('read_private_pages') && !current_user_can('edit_pages')) {
        return;
    }

    wp_add_dashboard_widget(
        'private_pages_link_list_widget',
        '非公開の固定ページ・トップページ',
        'render_private_pages_dashboard_widget'
    );
}

// 非公開固定ページ＆トップページウィジェットのHTMLを出力
function render_private_pages_dashboard_widget()
{
    // --- 1. トップページ（フロントページ）情報の取得 ---
    $home_url = home_url('/');
    $front_page_id = (int) get_option('page_on_front');
    $show_on_front = get_option('show_on_front');

    $home_title_detail = '';
    $home_edit_url = '';
    $home_updated = '';

    if ($show_on_front === 'page' && $front_page_id > 0) {
        $front_page = get_post($front_page_id);
        $front_title = $front_page ? get_the_title($front_page) : '';
        $home_title_detail = $front_title ? ' (' . $front_title . ')' : '';
        $home_edit_url = get_edit_post_link($front_page_id);
        $home_updated = $front_page ? get_the_modified_date('Y/m/d', $front_page) : '';
    } else {
        // スラッグが home や top の固定ページが存在するかフォールバック確認
        $fallback_page = get_page_by_path('home') ?: get_page_by_path('top');
        if ($fallback_page) {
            $home_title_detail = ' (' . get_the_title($fallback_page) . ')';
            $home_edit_url = get_edit_post_link($fallback_page->ID);
            $home_updated = get_the_modified_date('Y/m/d', $fallback_page);
        } else {
            $site_name = get_bloginfo('name');
            $home_title_detail = $site_name ? ' (' . $site_name . ')' : '';
            // 固定ページではない場合はカスタマイザー編集画面
            $home_edit_url = admin_url('customize.php');
            $home_updated = '';
        }
    }

    // --- 2. 非公開固定ページの取得 ---
    $args = [
        'post_type'      => 'page',
        'post_status'    => 'private',
        'posts_per_page' => -1,
        'orderby'        => ['menu_order' => 'ASC', 'title' => 'ASC'],
    ];

    $private_pages = get_posts($args);
?>
    <div class="private-pages-widget-container">
        <!-- トップページ クイックアクセス -->
        <div class="top-page-card">
            <div class="private-page-info">
                <div class="top-page-header-row">
                    <span class="top-page-badge">TOP</span>
                    <a href="<?php echo esc_url($home_url); ?>" class="private-page-title" target="_blank" rel="noopener noreferrer" title="トップページを表示（新しいタブで開く）">
                        トップページ<?php echo esc_html($home_title_detail); ?>
                        <span class="dashicons dashicons-external" aria-hidden="true"></span>
                    </a>
                </div>
                <div class="private-page-meta">
                    <span class="private-page-slug">/</span>
                    <?php if (!empty($home_updated)) : ?>
                        <span class="private-page-date">更新日: <?php echo esc_html($home_updated); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="private-page-actions">
                <?php if ($home_edit_url) : ?>
                    <a href="<?php echo esc_url($home_edit_url); ?>" class="button button-small" title="トップページの編集画面を開く">編集</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- セクション見出し -->
        <div class="private-pages-section-divider">
            <span class="dashicons dashicons-lock" aria-hidden="true"></span>
            <strong>非公開の固定ページ (<?php echo count($private_pages); ?>件)</strong>
        </div>

        <!-- 非公開固定ページ一覧 -->
        <?php if (empty($private_pages)) : ?>
            <p class="private-pages-empty">現在、状態が「非公開」の固定ページはありません。</p>
        <?php else : ?>
            <ul class="private-pages-list">
                <?php foreach ($private_pages as $page) :
                    // トップページと同一IDの場合は重複を防ぐためスキップ
                    if ($front_page_id && $page->ID === $front_page_id) {
                        continue;
                    }

                    $view_url = get_permalink($page->ID);
                    $edit_url = get_edit_post_link($page->ID);
                    $title    = get_the_title($page->ID);
                    if (empty($title)) {
                        $title = '(タイトルなし)';
                    }

                    // 親ページがある場合は階層構造を取得
                    $ancestor_names = [];
                    if ($page->post_parent) {
                        $ancestors = array_reverse(get_post_ancestors($page->ID));
                        foreach ($ancestors as $ancestor_id) {
                            $ancestor_names[] = get_the_title($ancestor_id);
                        }
                    }
                ?>
                    <li class="private-page-item">
                        <div class="private-page-info">
                            <?php if (!empty($ancestor_names)) : ?>
                                <div class="private-page-parents">
                                    <?php echo esc_html(implode(' / ', $ancestor_names)); ?> /
                                </div>
                            <?php endif; ?>
                            <a href="<?php echo esc_url($view_url); ?>" class="private-page-title" target="_blank" rel="noopener noreferrer" title="ページを表示（新しいタブで開く）">
                                <?php echo esc_html($title); ?>
                                <span class="dashicons dashicons-external" aria-hidden="true"></span>
                            </a>
                            <div class="private-page-meta">
                                <span class="private-page-date">更新日: <?php echo esc_html(get_the_modified_date('Y/m/d', $page)); ?></span>
                                <?php if ($page->post_name) : ?>
                                    <span class="private-page-slug">/<?php echo esc_html($page->post_name); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="private-page-actions">
                            <?php if ($edit_url) : ?>
                                <a href="<?php echo esc_url($edit_url); ?>" class="button button-small" title="編集画面を開く">編集</a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <!-- フッター操作部 -->
        <div class="private-pages-widget-footer">
            <a href="<?php echo esc_url(admin_url('edit.php?post_status=private&post_type=page')); ?>" class="button button-link">
                管理画面で一覧を開く (全<?php echo count($private_pages); ?>件) &rarr;
            </a>
            <a href="<?php echo esc_url(admin_url('post-new.php?post_type=page')); ?>" class="button button-secondary">
                + 新規固定ページ作成
            </a>
        </div>
    </div>

    <style>
        .private-pages-widget-container {
            margin: -6px -12px -12px;
        }

        .top-page-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 12px;
            background: #f0f6fc;
            border-bottom: 2px solid #c5d9ed;
        }

        .top-page-header-row {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .top-page-badge {
            background-color: #2271b1;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 3px;
            line-height: 1.2;
            letter-spacing: 0.5px;
        }

        .private-pages-section-divider {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            background: #f6f7f7;
            border-bottom: 1px solid #dcdcde;
            font-size: 12px;
            color: #50575e;
        }

        .private-pages-section-divider .dashicons {
            font-size: 15px;
            width: 15px;
            height: 15px;
            color: #646970;
        }

        .private-pages-list {
            margin: 0;
            padding: 0;
            list-style: none;
            max-height: 350px;
            overflow-y: auto;
        }

        .private-page-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 12px;
            margin: 0;
            border-bottom: 1px solid #f0f0f1;
            transition: background-color 0.15s ease;
        }

        .private-page-item:hover {
            background-color: #f6f7f7;
        }

        .private-page-item:last-child {
            border-bottom: none;
        }

        .private-page-info {
            flex-grow: 1;
            min-width: 0;
        }

        .private-page-parents {
            font-size: 11px;
            color: #646970;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .private-page-title {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 13px;
            font-weight: 600;
            color: #2271b1;
            text-decoration: none;
            line-height: 1.4;
            word-break: break-word;
        }

        .private-page-title:hover {
            color: #135e96;
            text-decoration: underline;
        }

        .private-page-title .dashicons {
            font-size: 14px;
            width: 14px;
            height: 14px;
            color: #8c8f94;
            flex-shrink: 0;
        }

        .private-page-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            font-size: 11px;
            color: #646970;
            margin-top: 3px;
        }

        .private-page-slug {
            background: #f0f0f1;
            padding: 0 4px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 10px;
        }

        .private-page-actions {
            flex-shrink: 0;
        }

        .private-pages-widget-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            background-color: #f6f7f7;
            border-top: 1px solid #dcdcde;
            gap: 8px;
            flex-wrap: wrap;
        }

        .private-pages-empty {
            padding: 12px;
            color: #646970;
            margin: 0;
        }
    </style>
<?php
}

