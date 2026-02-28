<?php
// =================================================================
//  ★Step 0: マスター設定 (ここですべてを管理する)
// =================================================================
function koto_get_column_config()
{
    return [
        'icon' => [
            'label' => 'アイコン',
            'class' => 'col-icon',
            'sort'  => null,
            'show'  => true,
            'header_class' => 'th-icon'
        ],
        'id'   =>[
            'label' => 'ID',
            'class' => 'col-id',
            'sort'  => 'id',
            'show'  => false,
            'header_class' => 'th-id'
        ],
        'name' => [
            'label' => 'キャラ名',
            'class' => 'col-name',
            'sort'  => 'name_ruby',
            'type'  => 'CHAR',
            'show'  => true,
            'header_class' => 'th-name'
        ],
        'moji' => [
            'label' => '文字',
            'class' => 'col-moji',
            'sort'  => null,
            'show'  => true,
            'header_class' => 'th-moji'
        ],
        'attr' => [
            'label' => '属性',
            'class' => 'col-attr',
            'sort'  => 'attr',
            'meta'  => '_sort_attr_index',
            'type'  => 'NUMERIC',
            'show'  => false,
            'header_class' => 'th-attr'
        ],
        'species' => [
            'label' => '種族',
            'class' => 'col-species',
            'sort'  => 'spe',
            'meta'  => '_sort_species_index',
            'type'  => 'NUMERIC',
            'show'  => false,
            'header_class' => 'th-species'
        ],
        'hp99' => [
            'label' => 'HP<span class="th-sub">99</span>',
            'txt_label' => 'HP(99)',
            'class' => 'col-hp99',
            'sort'  => 'hp99',
            'meta'  => '99_hp',
            'type'  => 'NUMERIC',
            'show'  => true,
            'header_class' => 'th-stat'
        ],
        'atk99' => [
            'label' => 'ATK<span class="th-sub">99</span>',
            'txt_label' => 'ATK(99)',
            'class' => 'col-atk99',
            'sort'  => 'atk99',
            'meta'  => '99_atk',
            'type'  => 'NUMERIC',
            'show'  => true,
            'header_class' => 'th-stat'
        ],
        'hp120' => [
            'label' => 'HP<span class="th-sub">120</span>',
            'txt_label' => 'HP(120)',
            'class' => 'col-hp120',
            'sort'  => 'hp120',
            'meta'  => '120_hp',
            'type'  => 'NUMERIC',
            'show'  => false,
            'header_class' => 'th-stat th-120'
        ],
        'atk120' => [
            'label' => 'ATK<span class="th-sub">120</span>',
            'txt_label' => 'ATK(120)',
            'class' => 'col-atk120',
            'sort'  => 'atk120',
            'meta'  => '120_atk',
            'type'  => 'NUMERIC',
            'show'  => false,
            'header_class' => 'th-stat th-120'
        ],
        'ls_hp' => [
            'label' => 'L HP', // 以前のコードに合わせて「最大」は外でつけるか、ここでつけるか。search-character.phpで調整済み
            'txt_label' => 'L HP',
            'class' => 'col-ls-hp',
            'sort'  => 'ls_hp',
            'meta'  => 'max_ls_hp',
            'type'  => 'NUMERIC',
            'show'  => false,
            'header_class' => 'th-stat'
        ],
        'ls_atk' => [
            'label' => 'L ATK',
            'txt_label' => 'L ATK',
            'class' => 'col-ls-atk',
            'sort'  => 'ls_atk',
            'meta'  => 'max_ls_atk',
            'type'  => 'NUMERIC',
            'show'  => false,
            'header_class' => 'th-stat'
        ],
        'buff_board' => [
            'label' => '盤バフ', // 短めに
            'txt_label' => '盤面バフ',
            'class' => 'col-buff-board',
            'sort'  => 'buff_board',
            'meta'  => 'buff_count_board_lv5',
            'type'  => 'NUMERIC',
            'show'  => true,
            'header_class' => 'th-buff'
        ],
        'buff_hand' => [
            'label' => '手バフ', // 短めに
            'txt_label' => '手札バフ',
            'class' => 'col-buff-hand',
            'sort'  => 'buff_hand',
            'meta'  => 'buff_count_hand_lv5',
            'type'  => 'NUMERIC',
            'show'  => false,
            'header_class' => 'th-buff'
        ],
        'debuff' => [
            'label' => 'デバフ',
            'class' => 'col-debuff',
            'sort'  => 'debuff',
            'meta'  => 'debuff_count_lv5',
            'type'  => 'NUMERIC',
            'show'  => true,
            'header_class' => 'th-debuff'
        ],
        'gimmick' => [
            'label' => 'ギミック',
            'class' => 'col-gimmick',
            'sort'  => null,
            'show'  => true,
            'header_class' => 'th-gimmick'
        ],
        'cv' => [
            'label' => 'CV',
            'class' => 'col-cv',
            'sort'  => null,
            'show'  => false,
            'header_class' => 'th-cv'
        ],
        'acq' => [
            'label' => '入手',
            'class' => 'col-acq',
            'sort'  => null,
            'show'  => false,
            'header_class' => 'th-acq'
        ],
        'date' => [
            'label' => '実装日',
            'class' => 'col-date',
            'sort'  => 'date',
            'meta'  => 'impl_date',
            'type'  => 'DATE',
            'show'  => true,
            'header_class' => 'th-date'
        ],
        'power' => [
            'label' => '火力指数',
            'class' => 'col-power',
            'sort'  => 'power',
            'meta'  => 'firepower_index',
            'type'  => 'NUMERIC',
            'show'  => false,
            'header_class' => 'th-power'
        ],
    ];
}

// =================================================================
//  ★Step 1: 共通ヘルパー (HTML生成用)
// =================================================================
if (!function_exists('koto_get_term_html_helper')) {
    function koto_get_term_html_helper($slug, $taxonomy, $type, $is_small = false, $prefix = '')
    {
        if (!$slug) return '';
        $term = get_term_by('slug', $slug, $taxonomy);
        if (!$term || is_wp_error($term)) return '';

        $class_name = 'koto-icon';
        if ($type === 'attr') $class_name = 'attr-icon-img';
        if ($type === 'species') $class_name = 'species-icon-img';
        if ($is_small) $class_name .= ' koto-icon-small';

        $html_content = function_exists('get_term_icon_html') ? get_term_icon_html($term, $class_name) : $term->name;

        // 画像タグを含まない場合（テキスト表示）の処理
        if (strpos($html_content, '<img') === false) {
            if ($type === 'gimmick') $html_content = "<span class='badge-gimmick'>{$term->name}</span>";
            elseif ($type === 'attr') $html_content = "<span class='attr-text attr-{$slug}'>{$prefix}{$term->name}</span>";
        } else {
            if ($prefix) $html_content = "<span class='icon-prefix'>{$prefix}</span>" . $html_content;
        }
        return "<a href='" . get_term_link($term) . "' class='term-link-wrapper'>{$html_content}</a>";
    }
}

// =================================================================
//  ★Step 2: クエリ引数生成 (設定から自動生成)
// =================================================================
function get_koto_character_args($request_data, $paged = 1)
{
    $config = koto_get_column_config();
    $sort_key   = $request_data['orderby'] ?? 'name_ruby';
    $sort_order = $request_data['order'] ?? 'ASC';

    // 1. 基本引数
    $args = [
        'post_type'      => 'character',
        'posts_per_page' => 20,
        'paged'          => $paged,
        'post_status'    => 'publish',
        's'              => $request_data['s'] ?? '', // キーワード検索を反映
        'meta_query'     => ['relation' => 'AND'],
        'tax_query'      => ['relation' => 'AND'],
    ];

    // 2. タクソノミー絞り込み (属性・種族・所属・イベント・ギミック)
    $tax_params = [
        'tx_attr'    => 'attribute',
        'tx_species' => 'species',
        'tx_group'   => 'affiliation',
        'tx_event'   => 'event',
        'tx_gimmick' => 'gimmick'
    ];
    foreach ($tax_params as $param => $tax) {
        if (!empty($request_data[$param]) && is_array($request_data[$param])) {
            $args['tax_query'][] = [
                'taxonomy' => $tax,
                'field'    => 'slug',
                'terms'    => array_filter($request_data[$param]),
                'operator' => 'IN',
            ];
        }
    }

    // 3. 使用可能文字の入力検索 (OR検索)
    if (!empty($request_data['search_char'])) {
        $chars = preg_split('//u', $request_data['search_char'], -1, PREG_SPLIT_NO_EMPTY);
        if (!empty($chars)) {
            $char_query = ['relation' => 'OR'];
            foreach ($chars as $char) {
                if (trim($char) === '' || $char === ',' || $char === '、') continue;
                $char_query[] = [
                    'taxonomy' => 'available_moji',
                    'field'    => 'name',
                    'terms'    => $char,
                ];
            }
            if (count($char_query) > 1) $args['tax_query'][] = $char_query;
        }
    }

    // 4. スキル・とくせいタグ検索 (スペース区切り・LIKE検索対応)
    // --- スキル詳細検索 ---
    if (!empty($request_data['tx_skill_tags']) && is_array($request_data['tx_skill_tags'])) {
        $scopes = !empty($request_data['scope_skill']) ? $request_data['scope_skill'] : ['waza', 'sugo', 'kotowaza'];
        foreach ($request_data['tx_skill_tags'] as $tag) {
            $scope_query = ['relation' => 'OR'];
            foreach ($scopes as $scope) {
                $key = ($scope === 'waza') ? '_waza_tags_str' : (($scope === 'sugo') ? '_sugo_tags_str' : '_kotowaza_tags_str');
                $scope_query[] = [
                    'key'     => $key,
                    'value'   => ' ' . $tag . ' ', // 前後にスペースを入れて誤爆防止
                    'compare' => 'LIKE'
                ];
            }
            $args['meta_query'][] = $scope_query;
        }
    }

    // --- とくせい詳細検索 ---
    if (!empty($request_data['tx_trait_tags']) && is_array($request_data['tx_trait_tags'])) {
        $scopes = !empty($request_data['scope_trait']) ? $request_data['scope_trait'] : ['t1', 't2', 'blessing'];
        foreach ($request_data['tx_trait_tags'] as $tag) {
            $scope_query = ['relation' => 'OR'];
            foreach ($scopes as $scope) {
                $key = ($scope === 't1') ? '_trait_tags_str_1' : (($scope === 't2') ? '_trait_tags_str_2' : '_trait_tags_str_blessing');
                $scope_query[] = [
                    'key'     => $key,
                    'value'   => ' ' . $tag . ' ',
                    'compare' => 'LIKE'
                ];
            }
            $args['meta_query'][] = $scope_query;
        }
    }

    // 5. ソート設定 (既存のロジックを維持)
    $sort_definitions = ['name_ruby' => ['key' => 'name_ruby', 'type' => 'CHAR']];
    foreach ($config as $col) {
        if (!empty($col['sort']) && !empty($col['meta'])) {
            $sort_definitions[$col['sort']] = ['key' => $col['meta'], 'type' => $col['type'] ?? 'NUMERIC'];
        }
    }

    $def = $sort_definitions[$sort_key] ?? $sort_definitions['name_ruby'];
    $args['meta_query']['primary_sort'] = ['key' => $def['key'], 'type' => $def['type']];

    if ($def['key'] !== 'name_ruby') {
        $args['meta_query']['secondary_sort'] = ['key' => 'name_ruby', 'type' => 'CHAR'];
        $args['orderby'] = ['primary_sort' => strtoupper($sort_order), 'secondary_sort' => 'ASC'];
    } else {
        $args['orderby'] = ['primary_sort' => strtoupper($sort_order)];
    }

    return $args;
}


// =================================================================
//  ★Step 3: Ajaxハンドラ (HTML出力の変更)
// =================================================================
add_action('wp_ajax_load_more_characters', 'ajax_load_more_characters');
add_action('wp_ajax_nopriv_load_more_characters', 'ajax_load_more_characters');

function ajax_load_more_characters()
{
    $paged = isset($_POST['paged']) ? intval($_POST['paged']) : 1;
    $args = get_koto_character_args($_POST, $paged);
    $the_query = new WP_Query($args);

    if ($the_query->have_posts()) {
        while ($the_query->have_posts()) {
            $the_query->the_post();
            // ★共通関数を呼ぶだけ
            echo get_koto_character_row_html(get_the_ID());
        }
    }
    wp_reset_postdata();
    wp_die();
}

// =================================================================
//  ★Step 4: 行生成関数 (データを取得してHTMLにはめる)
// =================================================================
function get_koto_character_row_html($post_id)
{
    // 1. データ取得
    $json = get_post_meta($post_id, '_spec_json', true);
    $spec = $json ? json_decode($json, true) : [];

    // JSONパース再試行
    $loop = 0;
    while (is_string($spec) && $loop < 3) {
        $spec = json_decode($spec, true);
        $loop++;
    }
    if (!is_array($spec) || empty($spec)) return '';

    // 2. HTMLパーツ生成

    // 属性
    $attr_html = koto_get_term_html_helper($spec['attribute'] ?? '', 'attribute', 'attr');
    $sub_attrs_html = [];
    if (!empty($spec['sub_attributes'])) {
        foreach ($spec['sub_attributes'] as $s) $sub_attrs_html[] = koto_get_term_html_helper($s, 'attribute', 'attr', true);
    }
    // 種族
    $species_html = koto_get_term_html_helper($spec['species'] ?? '', 'species', 'species');

    // 文字
    $chars_html = [];
    if (!empty($spec['chars'])) {
        foreach ($spec['chars'] as $c) {
            $val = esc_html($c['val']);
            $suffix = ($c['unlock'] === 'super_copy') ? '<span class="char-suffix">(Sコ)</span>' : (($c['unlock'] === 'super_change') ? '<span class="char-suffix">(Sチ)</span>' : '');
            $attr_slug = !empty($c['attr']) ? esc_attr($c['attr']) : 'none';
            $link = '#';
            if (!empty($c['slug'])) {
                $ct = get_term_by('slug', $c['slug'], 'available_moji');
                if ($ct) $link = get_term_link($ct);
            }
            $chars_html[] = "<a href='{$link}' class='char-link-item'><span class='char-font attr-{$attr_slug}'>{$val}</span>{$suffix}</a>";
        }
    }

    // ギミック
    $gimmick_htmls = [];
    if (!empty($spec['traits'])) {
        foreach ($spec['traits'] as $t) {
            if ($t['type'] === 'gimmick' && !empty($t['gimmick_slug'])) {
                $gimmick_htmls[] = koto_get_term_html_helper($t['gimmick_slug'], 'gimmick', 'gimmick');
            }
        }
    }
    $gimmick_htmls = array_unique($gimmick_htmls);

    // バフ・デバフ (盤面 / 手札)
    // ※未定義の場合は配列エラー回避のため null合体演算子を使用
    $bb_min = $spec['buff_counts_board'][0] ?? 0;
    $bb_max = $spec['buff_counts_board'][5] ?? 0;
    $buff_board_disp = ($bb_max == 0) ? '<span class="text-muted">0</span>' : (($bb_min == $bb_max) ? "<span class='bd-val'>{$bb_max}</span>" : "<span class='bd-val'>{$bb_min}➡{$bb_max}</span>");

    $bh_min = $spec['buff_counts_hand'][0] ?? 0;
    $bh_max = $spec['buff_counts_hand'][5] ?? 0;
    $buff_hand_disp = ($bh_max == 0) ? '<span class="text-muted">0</span>' : (($bh_min == $bh_max) ? "<span class='bd-val'>{$bh_max}</span>" : "<span class='bd-val'>{$bh_min}➡{$bh_max}</span>");

    $d_min = $spec['debuff_counts'][0] ?? 0;
    $d_max = $spec['debuff_counts'][5] ?? 0;
    $debuff_disp = ($d_max == 0) ? '<span class="text-muted">0</span>' : (($d_min == $d_max) ? "<span class='bd-val'>{$d_max}</span>" : "<span class='bd-val'>{$d_min}➡{$d_max}</span>");

    // 火力・実装日
    $firepower = $spec['firepower_index'] ?? 0;
    $is_estimate = !empty($spec['is_estimate']);

    $fp_disp = ($firepower > 0) ? number_format($firepower) : '<span class="text-muted">-</span>';
    if ($is_estimate && $firepower > 0) {
        $fp_disp .= '<span class="est-mark" style="font-size:0.8em; color:#999; margin-left:2px;" title="倍率は推定値です">(推定)</span>';
    }
    $date = $spec['release_date'] ?? '-';

    // ステータス (Lv99 / Lv120)
    $hp99  = get_post_meta($post_id, '99_hp', true);
    $atk99 = get_post_meta($post_id, '99_atk', true);
    $hp120  = get_post_meta($post_id, '120_hp', true);
    $atk120 = get_post_meta($post_id, '120_atk', true);

    $val_hp99  = $hp99 ? number_format($hp99) : '<span class="text-muted">-</span>';
    $val_atk99 = $atk99 ? number_format($atk99) : '<span class="text-muted">-</span>';
    $val_hp120  = $hp120 ? number_format($hp120) : '<span class="text-muted">-</span>';
    $val_atk120 = $atk120 ? number_format($atk120) : '<span class="text-muted">-</span>';

    // リーダー特性
    $ls_hp = !empty($spec['max_ls_hp']) ? number_format($spec['max_ls_hp']) . '%' : '<span class="text-muted">-</span>';
    $ls_atk = !empty($spec['max_ls_atk']) ? number_format($spec['max_ls_atk']) . '%' : '<span class="text-muted">-</span>';

    // 基本情報
    $cv = esc_html($spec['cv'] ?? '-');
    $acq = esc_html($spec['acquisition'] ?? '-');
    $thumb = has_post_thumbnail($post_id) ? get_the_post_thumbnail($post_id, 'thumbnail', ['class' => 'chara-thumb']) : '<div class="no-img"></div>';

    $full_name = $spec['name'] ?? get_the_title($post_id);
    $parts = explode('・', $full_name, 2);
    $d_name = (count($parts) > 1) ? $parts[1] : $full_name;
    $link = get_permalink($post_id);

    // 3. HTML出力 (バッファリング)
    // クラス名などは koto_get_column_config と連動している前提
    ob_start();
?>
    <tr>
        <td class="td-icon"><a href="<?php echo $link; ?>"><?php echo $thumb; ?></a></td>
        <td class="td-name"><a href="<?php echo $link; ?>" class="chara-link"><?php echo esc_html($d_name); ?></a></td>

        <td class="td-moji col-moji">
            <div class="char-list"><?php echo implode('', $chars_html); ?></div>
        </td>
        <td class="td-attr col-attr">
            <div class="attr-box-row"><?php echo $attr_html;
                                        if ($sub_attrs_html) echo implode('', $sub_attrs_html); ?></div>
        </td>
        <td class="td-species col-species"><?php echo $species_html; ?></td>

        <td class="td-stat hp-val col-hp99"><?php echo $val_hp99; ?></td>
        <td class="td-stat atk-val col-atk99"><?php echo $val_atk99; ?></td>

        <td class="td-stat hp-val-120 col-hp120"><?php echo $val_hp120; ?></td>
        <td class="td-stat atk-val-120 col-atk120"><?php echo $val_atk120; ?></td>

        <td class="td-stat ls-val col-ls-hp"><?php echo $ls_hp; ?></td>
        <td class="td-stat ls-val col-ls-atk"><?php echo $ls_atk; ?></td>

        <td class="td-buff buff-cell col-buff-board"><?php echo $buff_board_disp; ?></td>
        <td class="td-buff buff-cell col-buff-hand"><?php echo $buff_hand_disp; ?></td>

        <td class="td-debuff debuff-cell col-debuff"><?php echo $debuff_disp; ?></td>
        <td class="td-gimmick col-gimmick">
            <div class="gimmick-list"><?php echo implode('', $gimmick_htmls); ?></div>
        </td>
        <td class="td-cv col-cv"><?php echo $cv; ?></td>
        <td class="td-acq col-acq"><?php echo $acq; ?></td>
        <td class="td-date col-date"><?php echo $date; ?></td>
        <td class="td-power col-power"><?php echo $fp_disp; ?></td>
    </tr>
<?php
    return ob_get_clean();
}
