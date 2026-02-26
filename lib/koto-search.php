<?php
// =================================================================
// コトダマンDB 検索機能拡張ロジック
// =================================================================

/**
 * 1. 階層構造付きチェックボックスリストを出力 (所属・イベント用)
 */
function render_frontend_term_tree($taxonomy, $name_attr, $args = [])
{
    // デフォルト設定
    $defaults = [
        'open_all' => false,    // デフォルトで開くかどうか
        'show_relation' => true, // AND/OR切り替えを表示するか
        'and_or' => 'OR'         // デフォルトのAND/OR設定 (URLパラメータがない場合)
    ];
    $config = array_merge($defaults, $args);

    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'parent'     => 0,
    ]);

    if (empty($terms) || is_wp_error($terms)) return;

    // AND/OR の初期値取得
    $relation_val = isset($_GET[$name_attr . '_relation']) ? $_GET[$name_attr . '_relation'] : $config['and_or'];

    echo '<div class="custom-term-selector-ui" data-tax="' . esc_attr($taxonomy) . '">';

    // 絞り込み検索窓
    echo '<input type="text" class="term-tree-search" placeholder="絞り込み検索..." />';

    // AND/OR 切り替えスイッチ
    if ($config['show_relation']) {
        echo '<div class="search-options">';
        echo '<label><input type="radio" name="' . esc_attr($name_attr) . '_relation" value="AND" ' . checked($relation_val, 'AND', false) . '> 全て含む(AND)</label> ';
        echo '<label><input type="radio" name="' . esc_attr($name_attr) . '_relation" value="OR" ' . checked($relation_val, 'OR', false) . '> いずれかを含む(OR)</label>';
        echo '</div>';
    }

    echo '<div class="term-tree-list">';

    // 再帰関数の定義
    $walker = function ($terms, $walker_func) use ($name_attr, $taxonomy, $config) {
        echo '<ul class="term-children-container">';
        foreach ($terms as $term) {
            $children = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'parent'     => $term->term_id,
            ]);

            $checked = '';
            if (isset($_GET[$name_attr]) && is_array($_GET[$name_attr])) {
                if (in_array($term->slug, $_GET[$name_attr])) $checked = 'checked';
            }

            echo '<li class="term-tree-item">';

            $has_children = !empty($children);
            $open_attr = $config['open_all'] ? 'open' : '';

            if ($has_children) {
                echo '<details ' . $open_attr . '>';
                echo '<summary class="term-summary">';
            }

            echo '<label class="term-label">';
            echo '<input type="checkbox" name="' . esc_attr($name_attr) . '[]" value="' . esc_attr($term->slug) . '" ' . $checked . '>';
            echo '<span class="term-name">' . esc_html($term->name) . '</span>';
            echo '</label>';

            if ($has_children) {
                echo '</summary>';
                $walker_func($children, $walker_func);
                echo '</details>';
            }

            echo '</li>';
        }
        echo '</ul>';
    };

    $walker($terms, $walker);

    echo '</div>';
    echo '</div>';
}

/**
 * 2. フラットなチェックボックスリストを出力 (属性・種族用)
 * @param string $taxonomy タクソノミー名
 * @param string $name_attr inputのname属性
 * @param bool $icon_only trueの場合、テキストを隠してアイコンのみのCSSクラスを付与
 */
function render_simple_checkbox_list($taxonomy, $name_attr, $icon_only = false)
{
    $terms = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
    ]);

    if (empty($terms) || is_wp_error($terms)) return;

    // アイコンのみモードなら専用クラス、そうでなければ通常クラス
    $container_class = $icon_only ? 'icon-only-list' : 'simple-checkbox-list';
    $label_class     = $icon_only ? 'icon-only-label' : 'simple-term-label';
    $text_class      = $icon_only ? 'term-text-hidden' : 'term-text'; // アイコンのみなら文字を隠す

    echo '<div class="' . $container_class . '">';
    foreach ($terms as $term) {
        $checked = '';
        if (isset($_GET[$name_attr]) && is_array($_GET[$name_attr])) {
            if (in_array($term->slug, $_GET[$name_attr])) $checked = 'checked';
        }

        $icon = '';
        if (function_exists('get_term_icon_html')) {
            $icon = get_term_icon_html($term, 'search-term-icon');
        }

        // アイコン取得結果が「テキスト」だけの場合は、追加で名前を表示しない
        $display_name = esc_html($term->name);
        if (strpos($icon, '<img') === false) {
            $display_content = $icon;
        } else {
            $display_content = $icon . '<span class="' . $text_class . '">' . $display_name . '</span>';
        }

        echo '<label class="' . $label_class . '">';
        echo '<input type="checkbox" name="' . esc_attr($name_attr) . '[]" value="' . esc_attr($term->slug) . '" ' . $checked . '>';
        echo $display_content;
        echo '</label>';
    }
    echo '</div>';
}

/**
 * 3. 検索クエリのカスタマイズ (pre_get_posts)
 * URLパラメータ (?tx_attr[]=...) をWordPressが理解できる検索条件に変換
 * ★ソート処理を共通設定から自動生成するように変更
 */
add_action('pre_get_posts', 'custom_search_filter_query');
function custom_search_filter_query($query)
{
    // 管理画面やメインクエリ以外は無視
    if (is_admin() || !$query->is_main_query()) return;

    // 検索ページの場合のみ実行
    if ($query->is_search()) {

        // ------------------------------------------------
        // A. 基本設定
        // ------------------------------------------------
        $query->set('post_type', 'character');
        $query->set('posts_per_page', 20);

        // ------------------------------------------------
        // B. 絞り込み (Tax Query)
        // ------------------------------------------------
        $tax_query = $query->get('tax_query') ?: ['relation' => 'AND'];

        $targets = [
            'tx_attr'    => 'attribute',
            'tx_species' => 'species',
            'tx_group'   => 'affiliation',
            'tx_event'   => 'event',
            'tx_gimmick' => 'gimmick',
            'tx_rarity'  => 'rarity',
        ];

        foreach ($targets as $param => $tax) {
            if (isset($_GET[$param]) && is_array($_GET[$param])) {
                $terms = array_filter($_GET[$param]);
                if (!empty($terms)) {
                    // ★重要：ANDかORの判定
                    // パラメータ名 + _relation (例: tx_group_relation) を取得
                    $relation = isset($_GET[$param . '_relation']) ? $_GET[$param . '_relation'] : 'OR';
                    // operatorを決定 (ORなら'IN'、ANDなら'AND')
                    $operator = ($relation === 'AND') ? 'AND' : 'IN';
                    $tax_query[] = [
                        'taxonomy' => $tax,
                        'field'    => 'slug',
                        'terms'    => $terms,
                        'operator' => $operator,
                    ];
                }
            }
        }

        // --- B. 【追加】使用可能文字の入力検索 (OR検索) ---
        if (!empty($_GET['search_char'])) {
            $input_chars = $_GET['search_char'];

            // 1文字ずつに分割 (例: "あい" → ["あ", "い"])
            // 空白文字を除去して分割
            $chars = preg_split('//u', $input_chars, -1, PREG_SPLIT_NO_EMPTY);

            if (!empty($chars)) {
                // ★重要: OR検索用の箱を作る
                $char_query_block = ['relation' => 'OR'];

                foreach ($chars as $char) {
                    // 空白や記号(カンマ等)はスキップ
                    if (trim($char) === '' || $char === ',' || $char === '、') continue;

                    // 「その文字を持っている」条件を追加
                    $char_query_block[] = [
                        'taxonomy' => 'available_moji', // 文字のタクソノミースラッグ
                        'field'    => 'name',           // 名前で検索（あ、い...）
                        'terms'    => $char,
                    ];
                }

                // 条件が1つ以上あれば、メインの検索条件に追加
                if (count($char_query_block) > 1) {
                    $tax_query[] = $char_query_block;
                }
            }
        }
        $query->set('tax_query', $tax_query);

        // ------------------------------------------------
        // D. 【追加】タグ検索 (tx_tags)
        // ------------------------------------------------
        if (!empty($_GET['tx_tags']) && is_array($_GET['tx_tags'])) {
            $tag_query = ['relation' => 'AND'];
            foreach ($_GET['tx_tags'] as $tag) {
                // _search_tags_str はスペース区切り文字列なので LIKE で検索
                $tag_query[] = [
                    'key'     => '_search_tags_str',
                    'value'   => ' ' . $tag . ' ', // スペースで囲むことで完全一致に近づける
                    'compare' => 'LIKE'
                ];
            }
            // 既存の meta_query とマージ
            $meta_query = $query->get('meta_query') ?: [];
            $meta_query[] = $tag_query;
            $query->set('meta_query', $meta_query);
        }

        // ------------------------------------------------
        // E. 【追加】スキル詳細検索 (tx_skill_tags + scope_skill)
        // ------------------------------------------------
        if (!empty($_GET['tx_skill_tags']) && is_array($_GET['tx_skill_tags'])) {
            $skill_tags = $_GET['tx_skill_tags'];
            // スコープ（検索対象）の取得。未指定なら全対象
            $skill_scopes = !empty($_GET['scope_skill']) ? $_GET['scope_skill'] : ['waza', 'sugo', 'kotowaza'];

            $meta_query = $query->get('meta_query') ?: [];

            // ★変更: タグ同士をOR検索にするためのコンテナを作成
            $tags_or_query = ['relation' => 'OR'];

            foreach ($skill_tags as $tag) {
                $scope_query = ['relation' => 'OR'];

                foreach ($skill_scopes as $scope) {
                    $target_key = '';
                    if ($scope === 'waza') $target_key = '_waza_tags_str';
                    elseif ($scope === 'sugo') $target_key = '_sugo_tags_str';
                    elseif ($scope === 'kotowaza') $target_key = '_kotowaza_tags_str';

                    if ($target_key) {
                        $scope_query[] = [
                            'key'     => $target_key,
                            'value'   => ' ' . $tag . ' ', // スペースで囲むことで完全一致に近づける
                            'compare' => 'LIKE'
                        ];
                    }
                }
                // コンテナに追加
                $tags_or_query[] = $scope_query;
            }
            // ORコンテナをメインのクエリに追加
            $meta_query[] = $tags_or_query;
            $query->set('meta_query', $meta_query);
        }

        // ------------------------------------------------
        // F. 【追加】とくせい詳細検索 (tx_trait_tags + scope_trait)
        // ------------------------------------------------
        if (!empty($_GET['tx_trait_tags']) && is_array($_GET['tx_trait_tags'])) {
            $trait_tags = $_GET['tx_trait_tags'];
            $trait_scopes = !empty($_GET['scope_trait']) ? $_GET['scope_trait'] : ['t1', 't2', 'blessing'];

            $meta_query = $query->get('meta_query') ?: [];

            // ★変更: タグ同士をOR検索にするためのコンテナを作成
            $tags_or_query = ['relation' => 'OR'];

            foreach ($trait_tags as $tag) {
                $scope_query = ['relation' => 'OR'];

                foreach ($trait_scopes as $scope) {
                    $target_key = '';
                    if ($scope === 't1') $target_key = '_trait_tags_str_1';
                    elseif ($scope === 't2') $target_key = '_trait_tags_str_2';
                    elseif ($scope === 'blessing') $target_key = '_trait_tags_str_blessing';

                    if ($target_key) {
                        $scope_query[] = [
                            'key'     => $target_key,
                            'value'   => ' ' . $tag . ' ', // スペースで囲むことで完全一致に近づける
                            'compare' => 'LIKE'
                        ];
                    }
                }
                // コンテナに追加
                $tags_or_query[] = $scope_query;
            }
            // ORコンテナをメインのクエリに追加
            $meta_query[] = $tags_or_query;
            $query->set('meta_query', $meta_query);
        }
        // ------------------------------------------------
        // G. 【追加】声優名検索 (tx_cv)
        // ------------------------------------------------
        if (!empty($_GET['tx_cv'])) {
            $cv_name = sanitize_text_field($_GET['tx_cv']);
            $meta_query = $query->get('meta_query') ?: [];

            $meta_query[] = [
                'key'     => 'voice_actor', // ACFのフィールド名
                'value'   => $cv_name,
                'compare' => 'LIKE'        // あいまい検索
            ];
            // TODO自動で声優列を表示する
            $query->set('meta_query', $meta_query);
        }
        // ------------------------------------------------
        // C. ソート (Meta Query & Orderby)
        // ★ここを共通設定ファイルから自動生成する形に変更
        // ------------------------------------------------
        $sort_key   = $_GET['orderby'] ?? 'name_ruby';
        $sort_order = $_GET['order'] ?? 'ASC';

        // 共通設定の読み込み
        // ※ chara-list-functions.php がロードされている前提
        $config = function_exists('koto_get_column_config') ? koto_get_column_config() : [];

        // ソート定義の自動構築
        $sort_definitions = [
            'name_ruby' => ['key' => 'name_ruby', 'type' => 'CHAR'], // デフォルト
        ];

        foreach ($config as $col) {
            // sortキーとmetaキーの両方が定義されている項目だけ対象
            if (!empty($col['sort']) && !empty($col['meta'])) {
                $sort_definitions[$col['sort']] = [
                    'key'  => $col['meta'],
                    'type' => $col['type'] ?? 'NUMERIC'
                ];
            }
        }

        // 実際にクエリへ適用
        if (array_key_exists($sort_key, $sort_definitions)) {
            $def = $sort_definitions[$sort_key];

            // meta_query をセット
            $meta_query = $query->get('meta_query') ?: [];
            $meta_query['primary_sort'] = [
                'key'  => $def['key'],
                'type' => $def['type'],
            ];

            // 第2ソートキー（名前順）
            if ($sort_key !== 'name_ruby') {
                $meta_query['secondary_sort'] = [
                    'key'  => 'name_ruby',
                    'type' => 'CHAR',
                ];
                $query->set('orderby', [
                    'primary_sort'   => $sort_order,
                    'secondary_sort' => 'ASC',
                ]);
            } else {
                $query->set('orderby', [
                    'primary_sort' => $sort_order,
                ]);
            }
            $query->set('meta_query', $meta_query);
        } else {
            // デフォルトソート (実装日順 -> 名前順)
            $meta_query = $query->get('meta_query') ?: [];
            $meta_query['primary_sort'] = [
                'key'  => 'impl_date',
                'type' => 'DATE',
            ];
            $meta_query['secondary_sort'] = [
                'key'  => 'name_ruby',
                'type' => 'CHAR',
            ];
            $query->set('meta_query', $meta_query);
            $query->set('orderby', [
                'primary_sort'   => 'DESC',
                'secondary_sort' => 'ASC'
            ]);
        }
    }
}
