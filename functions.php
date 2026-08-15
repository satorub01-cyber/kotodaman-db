<?php //子テーマ用関数
if (!defined('ABSPATH')) exit;

//子テーマ用のビジュアルエディタースタイルを適用
add_editor_style();

//以下に子テーマ用の関数を書く
// ▼▼▼ コトダマンDB用 カスタム関数 ▼▼▼

/**
 * ターム（属性・種族）に設定されたアイコン画像を取得する関数
 * * @param object $term タームオブジェクト
 * @param string $class_name imgタグにつけるクラス名
 * @return string imgタグまたはターム名
 */
require_once get_stylesheet_directory() . '/lib/koto-variables.php';
function get_term_icon_html($term, $class_name = 'term-icon')
{
    if (!$term || !is_object($term)) return '未設定';

    // ACFでタームに紐付いた画像IDを取得
    // ※第2引数に "taxonomy_term_ID" 形式の文字列、またはタームオブジェクトを渡す
    $icon_id = get_field('term_icon', $term);

    if ($icon_id) {
        // 画像があればIMGタグを返す
        return wp_get_attachment_image($icon_id, 'small', false, ['class' => $class_name, 'alt' => $term->name]);
    } else {
        // 画像がなければ文字（名前）を返す
        return $term->name;
    }
}
/**
 * 所属グループのリストから、表示すべき「メインの1つ」の名前を返す関数
 * @param array|object $terms タクソノミーオブジェクトの配列
 * @return string グループ名
 */
function get_primary_affiliation_obj($terms)
{
    if (empty($terms)) return false;
    if (!is_array($terms)) $terms = [$terms]; // 配列でなければ配列化

    // 1つだけならそれを返す
    if (count($terms) === 1) {
        return $terms[0];
    }

    // 複数ある場合の優先順位リスト (Slug)
    $priority_slugs = [
        'omni_melody',          // 全の戦律
        'wish_of_three_kingdoms',
        'journey_to_dream',
        // ここに追加していけば全ページに反映される！
    ];

    $found_term = null;

    // 優先リストと照合
    foreach ($priority_slugs as $slug) {
        foreach ($terms as $term) {
            if ($term->slug === $slug) {
                $found_term = $term;
                break 2;
            }
        }
    }

    // 子要素優先
    if (!$found_term) {
        foreach ($terms as $term) {
            if ($term->parent != 0) {
                $found_term = $term;
                break;
            }
        }
    }

    // 決まらなければ最初のもの
    if (!$found_term) {
        $found_term = $terms[0];
    }

    return $found_term;
}

/**
 * タクソノミーのリストを受け取り、名前を連結して文字列で返す汎用関数
 * * @param array|object|WP_Error $terms get_the_terms() や get_field() の戻り値
 * @param string $separator 区切り文字（デフォルトは '・'）
 * @param string $default データがない時の表示（デフォルトは '未入力'）
 * @return string 整形された文字列
 */
function get_terms_string($terms, $separator = '・', $default = '未入力')
{
    // エラーチェックや空チェック
    if (empty($terms) || is_wp_error($terms)) {
        return $default;
    }

    // A. 配列の場合（複数選択）
    if (is_array($terms)) {
        // 名前だけを抜き出して連結
        $names = wp_list_pluck($terms, 'name');
        return implode($separator, $names);
    }

    // B. 単体オブジェクトの場合（単数選択）
    if (is_object($terms)) {
        return $terms->name;
    }

    // C.もとから文字列が来てた時
    return (string)$terms;

    return $default;
}

// ▼▼▼ 一覧ページの画像を「フルサイズ」にする処理 ▼▼▼
// ▼▼▼ 画像を「ラージサイズ（軽量・トリミングなし）」に強制変換する処理 ▼▼▼
add_filter('wp_get_attachment_image_attributes', function ($attr, $attachment, $size) {

    // 1. 管理画面は除外
    if (is_admin()) {
        return $attr;
    }

    // 2. ★重要：フルサイズ（メイン画像）の時は何もしない
    // これを入れないと、詳細ページのメイン画像まで画質が変わってしまうのを防ぎます
    if ($size === 'large') {
        return $attr;
    }

    // 3. それ以外（一覧やナビのサムネイル）なら「large」に書き換える
    // これで「勝手に切り取られていない画像」を持ってこれます
    $image_data = wp_get_attachment_image_src($attachment->ID, 'large');

    if ($image_data) {
        $attr['src'] = $image_data[0];

        // 勝手に小さい画像に戻されるのを防ぐ
        if (isset($attr['srcset'])) {
            unset($attr['srcset']);
        }
    }

    return $attr;
}, 10, 3);

// --------------------------------------------------
// 投稿保存時に、スラッグを自動で「投稿ID」に書き換える設定
// --------------------------------------------------
// 引数に $post を追加
function auto_set_slug_to_id_multi($post_id, $post)
{
    $target_post_types = array('character', 'monster', 'item');

    // $post が受け取れているので get_post($post_id) は不要

    // ガード節
    if (!$post || !in_array($post->post_type, $target_post_types)) {
        return;
    }

    // すでにIDと同じなら終了
    if ($post->post_name == $post_id) {
        return;
    }

    // 無限ループ防止のため一時的にフック解除
    remove_action('save_post', 'auto_set_slug_to_id_multi');

    // 更新処理
    wp_update_post(array(
        'ID' => $post_id,
        'post_name' => $post_id
    ));

    // フック戻す
    add_action('save_post', 'auto_set_slug_to_id_multi', 10, 2);
}
// 引数を2つ受け取ることを指定（10は優先順位、2は引数の数）
add_action('save_post', 'auto_set_slug_to_id_multi', 10, 2);

// =================================================================
// 1. 検索ロジックファイルの読み込み (正しいファイル名に修正)
// =================================================================
// ※必ずサーバー上のファイル名を koto-search.php に直してから実行してください
require_once get_stylesheet_directory() . '/lib/character-search/koto-search.php';
require_once get_stylesheet_directory() . '/lib/koto-modal-displayer.php';


// =================================================================
// 2. CSSファイルの条件分岐読み込み (詳細用 / 検索用)
// =================================================================
add_action('wp_enqueue_scripts', function () {

    // --- A. キャラクター詳細ページ ---
    if (is_singular('character')) {
        $css_path = get_stylesheet_directory() . '/style-character-detail.css';
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'koto-detail-style',
                get_stylesheet_directory_uri() . '/style-character-detail.css',
                [],
                filemtime($css_path)
            );
        }
    }
    // --- B. 検索結果ページ (キャラ検索の場合) ---
    elseif (is_search()) {
        // キャラクター検索かどうか判定
        // (URLパラメータに post_type=character があるか、クエリ変数がセットされているか)
        if (get_query_var('post_type') === 'character' || (isset($_GET['post_type']) && $_GET['post_type'] === 'character')) {
            $css_path = get_stylesheet_directory() . '/style-character-search.css';
            if (file_exists($css_path)) {
                wp_enqueue_style(
                    'koto-search-style',
                    get_stylesheet_directory_uri() . '/style-character-search.css',
                    [],
                    filemtime($css_path)
                );
            }
        }
    }
});

function get_koto_target_label($group_data)
{
    if (empty($group_data)) return '';

    // 1. タイプ自動検出
    $type = isset($group_data['target_type']) ? $group_data['target_type'] : '';
    if (!$type) {
        if (!empty($group_data['target_species'])) $type = 'species';
        elseif (!empty($group_data['target_attr'])) $type = 'attr';
        elseif (!empty($group_data['target_group'])) $type = 'group';
        elseif (!empty($group_data['target_moji'])) $type = 'moji';
        elseif (!empty($group_data['target_other'])) $type = 'other';
    }

    // 2. データから名前をすべて取り出してつなぐ便利関数
    $get_names = function ($data) {
        if (empty($data)) return '';
        if (is_object($data)) $data = [$data]; // 1個でも配列化

        $names = [];
        if (is_array($data)) {
            foreach ($data as $term) {
                if (is_object($term) && isset($term->name)) {
                    $names[] = $term->name;
                }
            }
        }
        return implode('・', $names);
    };

    // 3. ラベル生成（末尾に言葉を追加！）
    switch ($type) {
        case 'self':
            return '自身';
        case 'all':
            return '味方全体';

        case 'attr':
            $text = $get_names($group_data['target_attr']);
            // 名前がある場合のみ「属性の味方」をつける
            return $text ? $text . '属性' : '';

        case 'species':
            $text = $get_names($group_data['target_species']);
            return $text ? $text . '種族' : '';

        case 'group':
            $terms = $group_data['target_group'];
            if (empty($terms)) return '';
            if (is_object($terms)) $terms = [$terms]; // 配列化

            // ★追加: melody特例処理
            foreach ($terms as $t) {
                if (isset($t->slug) && $t->slug === 'melody') {
                    return '「全の戦律」または「斬・砲・突・重・超・打の戦律」の味方';
                }
            }

            // さっき覚えた array_map で「名前取得」と「カッコつけ」を一気にやります
            $wrapped_names = array_map(fn($t) => "「{$t->name}」", $terms);

            // 結合する（区切り文字なしで「グループA」「グループB」のように繋げます）
            $text = implode('・', $wrapped_names);

            return $text ? $text . 'の味方' : '';

        case 'moji':
            $terms = $group_data['target_moji'];
            if (empty($terms)) return '';
            if (is_object($terms)) $terms = [$terms]; // 配列化

            // さっき覚えた array_map で「名前取得」と「カッコつけ」を一気にやります
            $wrapped_names = array_map(fn($t) => "「{$t->name}」", $terms);

            // 結合する（区切り文字なしで「グループA」「グループB」のように繋げます）
            $text = implode('・', $wrapped_names);

            return $text ? $text . 'の味方' : '';

        case 'other':
            return $group_data['target_other']; // その他はそのまま

        default:
            return '';
    }
}

// 寄稿者の権限コントロール
function add_upload_files_to_contributor()
{
    $role = get_role('contributor');
    if ($role) {
        $role->add_cap('upload_files');
    }
}
add_action('admin_init', 'add_upload_files_to_contributor');

// 【完成形V7】スマホ管理画面修正 ＋ 複製ボタン ＋ 強力レイアウト固定
function fix_admin_mobile_issues_ultimate()
{
    // 1. ビューポート設定
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, user-scalable=yes">';

    // 2. CSS
    echo '<style>
    /* --- 複製ボタンのデザイン --- */
    .my-acf-duplicate-bottom {
        display: block; /* ブロック要素にして堂々と配置 */
        width: 100%;
        margin-top: 15px !important;
        margin-bottom: 5px !important;
        background-color: #2271b1 !important;
        color: #fff !important;
        border: none !important;
        border-radius: 4px !important;
        padding: 10px 0 !important; /* 高さを確保 */
        font-weight: bold;
        font-size: 13px !important;
        text-decoration: none;
        cursor: pointer;
        text-align: center; /* 文字中央揃え */
        box-shadow: 0 2px 0 #135e96;
        clear: both;
    }
    .my-acf-duplicate-bottom:hover {
        background-color: #135e96 !important;
        color: #fff !important;
    }
    .my-acf-duplicate-bottom:active {
        transform: translateY(1px);
        box-shadow: none;
    }
    .my-acf-duplicate-bottom:before {
        content: "\f105";
        font-family: dashicons;
        margin-right: 5px;
        vertical-align: middle;
    }

    /* --- スマホ調整 (ここが修正のキモです) --- */
    @media screen and (max-width: 782px) {
        
        /* 1. 横揺れ（スクロール）の完全防止 */
        html, body, #wpwrap ,#wpcontent, #wpbody{
            overflow-x: hidden !important;
            width: 100% !important;
            max-width: 100vw !important;
        }

        /* 2. ACFテーブル構造の強制リセット */
        .acf-table, .acf-tbody, .acf-tr, .acf-th, .acf-td {
            display: block !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }

        /* 3. 行（Row）をFlexboxで整形 */
        /* 左の帯と右の中身を喧嘩させない */
        .acf-repeater .acf-row {
            display: flex !important;
            flex-wrap: nowrap !important; /* 折り返さない */
            width: 100% !important;
            box-sizing: border-box !important;
            margin: 0 !important;
        }

        /* 4. 左側のハンドル（行番号・削除ボタン） */
        .acf-repeater .acf-row-handle {
            display: flex !important;
            flex-direction: column !important; /* 縦並び */
            align-items: center !important;
            
            /* 幅を完全に固定する */
            flex: 0 0 20px !important; 
            width: 20px !important;
            max-width: 20px !important;
            min-width: 20px !important;
            
            background: #f9f9f9 !important;
            border-right: 1px solid #ccd0d4 !important;
            padding-top: 10px !important;
            box-sizing: border-box !important;

            font-size: 12px !important;
        }
        
        /* 削除ボタンなどがはみ出さないように */
        .acf-row-handle .acf-icon {
            position: static !important;
            margin: 0 auto 10px auto !important;
            float: none !important;
        }

        /* 4. 入れ子（ネスト）対策：深くなっても左余白を作らせない */
        .acf-field .acf-input {
            padding: 0 !important;
        }
        .acf-field-repeater .acf-input .acf-repeater {
            margin-left: 0 !important;
            border-left: none !important;
        }

        /* 3. 長い英数字やURLによる強制改行 */
        .acf-label label, .acf-input, p, span, div {
            word-break: break-word !important;
            overflow-wrap: break-word !important;
        }
        /* 5. 右側の入力エリア（ここが縮んでいた原因の修正） */
        .acf-repeater .acf-fields {
            /* 残りの幅を全部使う */
            flex: 1 1 auto !important;
            max-width: 100% !important;
            width: 100% !important;
            
            /* ★最重要：中身が大きくてもはみ出させない魔法の記述 */
            min-width: 0 !important; 
            
            padding: 4px 2px !important;
            box-sizing: border-box !important;
        }

        /* 6. 個別の入力項目（フィールド）の余白も詰める */
        .acf-fields > .acf-field {
            padding: 2px 1px !important; /* デフォルトよりかなり狭く */
            margin: 0 !important;
            border-top: 1px solid #eee; /* 薄い線で区切る */
        }
        /* 最初の項目の上の線は消す */
        .acf-fields > .acf-field:first-child {
            border-top: none;
        }

        /* 入力欄自体の文字サイズ確保 */
        input, textarea, select, .acf-input {
            font-size: 16px !important;
            max-width: 100% !important; /* はみ出し防止 */
        }
        
        /* 管理バー固定 */
        #wpadminbar { position: fixed; top: 0; width: 100%; z-index: 99999; }
        html { margin-top: 46px !important; }
    }
    </style>';

    // 3. JavaScript (ボタン挿入ロジックはそのまま)
?>
    <script type="text/javascript">
        (function($) {
            $(document).ready(function() {
                var duplicateBtnHtml = '<a href="#" class="my-acf-duplicate-bottom" data-event="duplicate-row">この行を複製</a>';

                function appendDuplicateButtons() {
                    $('.acf-repeater .acf-row').each(function() {
                        var $row = $(this);
                        // 右側のエリア(.acf-fields)の中にボタンを追加
                        var $fields = $row.children('.acf-fields');

                        if ($fields.length > 0 && $fields.find('> .my-acf-duplicate-bottom').length === 0) {
                            $fields.append(duplicateBtnHtml);
                        }
                    });
                }

                setTimeout(appendDuplicateButtons, 500);
                if (window.acf) {
                    acf.addAction('append', function($el) {
                        setTimeout(appendDuplicateButtons, 100);
                    });
                }
            });
        })(jQuery);
    </script>
<?php
}
add_action('admin_head', 'fix_admin_mobile_issues_ultimate', 1);

function add_extended_caps_to_contributor()
{
    // 寄稿者ロールオブジェクトを取得
    $role = get_role('contributor');

    // ロールが存在しない場合は中断
    if (! $role) {
        return;
    }

    // 付与したい権限のリスト（配列）
    $capabilities_to_add = [
        'manage_categories',    // 1. タクソノミー（カテゴリー・タグ）の追加・管理
        'edit_published_posts', // 2. 公開済みの自分の記事を編集（更新）する権限
        'edit_posts',           // 3. 下書き・レビュー待ちの自分の記事を編集する権限
        'upload_files'          // 4. (推奨) 画像アップロード権限
    ];

    // 配列をループして、持っていない権限があれば付与する
    foreach ($capabilities_to_add as $cap) {
        if (! $role->has_cap($cap)) {
            $role->add_cap($cap);
        }
    }
}
add_action('init', 'add_extended_caps_to_contributor');

// =================================================================
//  外部ファイルの読み込み
// =================================================================
// 表示関連の関数
require_once get_stylesheet_directory() . '/lib/koto-display.php';

// 計算・データ保存関連の関数
require_once get_stylesheet_directory() . '/lib/koto-calc.php';

require_once get_stylesheet_directory() . '/lib/character-search/chara-list-functions.php';
/**
 * 1. event と affiliation の権限設定を強制的に上書き（特注の鍵穴にする）
 */
function override_event_affiliation_caps($args, $taxonomy)
{
    $target_taxonomies = ['event', 'affiliation', 'suitable_quest'];

    if (in_array($taxonomy, $target_taxonomies, true)) {
        // 合鍵の名前
        $cap_suffix = 'custom_event_aff_terms';

        $args['capabilities'] = [
            'manage_terms' => 'manage_' . $cap_suffix,
            'edit_terms'   => 'edit_' . $cap_suffix,
            'delete_terms' => 'delete_' . $cap_suffix,
            'assign_terms' => 'assign_' . $cap_suffix,
        ];
    }
    return $args;
}
add_filter('register_taxonomy_args', 'override_event_affiliation_caps', 20, 2);


/**
 * 2. 管理者を含む全対象ロールに権限を配布
 */
function grant_custom_caps_to_roles()
{
    // 権限を与えるロール一覧
    $roles_to_modify = ['administrator', 'editor', 'author'];

    // 合鍵の名前
    $cap_suffix = 'custom_event_aff_terms';

    foreach ($roles_to_modify as $role_slug) {
        $role = get_role($role_slug);

        if ($role) {
            // --- 全員に共通して与える権限（基本操作） ---
            $role->add_cap('manage_' . $cap_suffix);
            $role->add_cap('edit_' . $cap_suffix);
            $role->add_cap('assign_' . $cap_suffix);

            // --- 削除権限の制御 ---
            // 管理者は「削除」も絶対に必要
            // 投稿者・寄稿者にも削除させて良いなら、このif文を外して無条件でadd_capしてください
            if ($role_slug === 'administrator' || $role_slug === 'editor') {
                $role->add_cap('delete_' . $cap_suffix);
            } else {
                // 投稿者たちは削除させない（必要ならここを有効化）
                // $role->add_cap( 'delete_' . $cap_suffix );

                // 以前の間違い（manage_categories）を管理者以外からは消しておく
                $role->remove_cap('manage_categories');
            }
        }
    }
}
add_action('admin_init', 'grant_custom_caps_to_roles');

require_once get_stylesheet_directory() . '/editor.php';

// =================================================================
//  【管理用】全キャラクターデータ一括更新機能（デバッグ版）
//  URL末尾に ?run_update_index=1 をつけてアクセスすると実行
// =================================================================
add_action('init', 'force_update_all_characters_index');

function force_update_all_characters_index()
{
    // 1. 管理者権限チェック & パラメータチェック
    if (!current_user_can('administrator') || !isset($_GET['run_update_index'])) {
        return;
    }

    // 2. タイムアウト対策
    set_time_limit(300); // 5分

    // 3. 計算用ファイルの強制読み込み（パスは環境に合わせて自動取得）
    $calc_file = get_stylesheet_directory() . '/koto-calc.php';
    if (file_exists($calc_file)) {
        require_once $calc_file;
    }

    // 4. 全キャラクター取得を小さめバッチで実行（ACF使用時のメモリ急増を抑える）
    $posts_per_page = 20;
    $paged = isset($_GET['batch_page']) ? max(1, intval($_GET['batch_page'])) : 1;
    $count = 0;

    echo '<div style="background:#fff; padding:20px; border:2px solid #00a0d2; margin:20px; z-index:9999; position:relative;">';
    echo "<h3>デバッグ情報</h3>";
    echo "<ul>";
    echo "<li>計算ファイルパス: " . $calc_file . " (" . (file_exists($calc_file) ? '発見' : '見つかりません') . ")</li>";
    echo "<li>保存関数 (on_save_character_specs): " . (function_exists('on_save_character_specs') ? '有効' : '無効(見つかりません)') . "</li>";
    echo "</ul>";

    $args = [
        'post_type'      => 'character',
        'posts_per_page' => $posts_per_page,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'paged'          => $paged,
    ];

    $query = new WP_Query($args);
    $found_posts = $query->found_posts;

    echo "<ul>";
    echo "<li>対象キャラクター数: " . $found_posts . " 体</li>";
    echo "<li>現在のバッチ: " . $paged . " / " . max(1, $query->max_num_pages) . "</li>";
    echo "</ul>";

    if ($query->have_posts()) {
        foreach ($query->posts as $post_id) {
            if (function_exists('on_save_character_specs')) {
                on_save_character_specs($post_id);
                $count++;
            }
        }
    }

    wp_reset_postdata();

    echo "<h3>更新結果</h3>";
    echo "<p><strong>{$count}</strong> 体のデータを更新しました。</p>";

    if ($count === 0 && $found_posts > 0) {
        echo "<p style='color:red;'>※キャラクターはいるのに更新数が0です。保存関数が読み込めていません。<br>koto-calc.php がテーマフォルダ直下にあるか確認してください。</p>";
    }

    if ($query->max_num_pages > $paged) {
        $next_page = $paged + 1;
        $next_url = add_query_arg(['run_update_index' => '1', 'batch_page' => $next_page]);
        echo '<a href="' . esc_url($next_url) . '" style="display:inline-block; margin-top:10px; padding:10px 20px; background:#00a0d2; color:#fff; text-decoration:none;">次の20体を更新</a>';
    } else {
        echo '<a href="' . remove_query_arg(['run_update_index', 'batch_page']) . '" style="display:inline-block; margin-top:10px; padding:10px 20px; background:#00a0d2; color:#fff; text-decoration:none;">元の画面に戻る</a>';
    }

    echo '</div>';
    exit;
}
// デバッグ用ショートコード: [debug_koto_json id=123]
add_shortcode('debug_koto_json', function ($atts) {
    static $instance_count = 0;
    $instance_count++;

    // 1. IDの決定
    $default_id = get_the_ID();
    $atts = shortcode_atts(['id' => $default_id], $atts);

    // このショートコードインスタンス専用のパラメータ名 (例: debug_id_1)
    $param_name = 'debug_id_' . $instance_count;

    // GETパラメータがあればそれを優先
    $target_id = isset($_GET[$param_name]) ? intval($_GET[$param_name]) : intval($atts['id']);

    // 2. データ取得
    $json = get_post_meta($target_id, '_spec_json', true);

    // HTML要素用のユニークID (ターゲットID + インスタンス番号)
    $html_id_suffix = $target_id . '_' . $instance_count;

    // 3. 出力バッファリング開始
    ob_start();
?>
    <div class="debug-json-box" style="border:1px solid #ccc; padding:15px; background:#f9f9f9; margin:20px 0;">
        <!-- ID切り替えフォーム -->
        <form method="get" action="" style="margin-bottom:10px; display:flex; gap:10px; align-items:center;">
            <label style="font-weight:bold;">確認したい記事ID:
                <input type="number" name="<?php echo esc_attr($param_name); ?>" value="<?php echo esc_attr($target_id); ?>" style="width:100px; padding:5px;">
            </label>

            <?php
            // 他のパラメータを維持するためのhiddenフィールド
            foreach ($_GET as $key => $val) {
                if ($key !== $param_name && !is_array($val)) {
                    echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '">';
                }
            }
            ?>

            <button type="submit" style="padding:5px 15px; cursor:pointer; background:#2271b1; color:#fff; border:none; border-radius:3px;">表示</button>

            <?php if ($json): ?>
                <button type="button" id="copy-json-btn-<?php echo esc_attr($html_id_suffix); ?>" style="padding:5px 15px; cursor:pointer; background:#fff; border:1px solid #2271b1; color:#2271b1; border-radius:3px;">📋 JSONをコピー</button>
            <?php endif; ?>
        </form>

        <?php if (!$json): ?>
            <p style="color:red; font-weight:bold;">ID: <?php echo esc_html($target_id); ?> のJSONデータが見つかりません。<br>記事を保存し直すか、一括更新を実行してください。</p>
        <?php else: ?>
            <?php
            $data = json_decode($json, true);
            // 見やすく整形 (JSON形式)
            $pretty_json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            ?>
            <textarea id="json-textarea-<?php echo esc_attr($html_id_suffix); ?>" style="width:100%; height:500px; font-family:monospace; font-size:12px; line-height:1.5; white-space:pre; background:#fff; border:1px solid #ddd; padding:10px;" readonly><?php echo esc_textarea($pretty_json); ?></textarea>

            <script>
                document.getElementById('copy-json-btn-<?php echo esc_js($html_id_suffix); ?>').addEventListener('click', function() {
                    var copyText = document.getElementById("json-textarea-<?php echo esc_js($html_id_suffix); ?>");
                    copyText.select();
                    copyText.setSelectionRange(0, 99999); // スマホ対応

                    navigator.clipboard.writeText(copyText.value).then(function() {
                        alert("JSONデータをクリップボードにコピーしました！");
                    }).catch(function(err) {
                        console.error('コピーに失敗しました', err);
                    });
                });
            </script>
        <?php endif; ?>
    </div>
<?php
    return ob_get_clean();
});

// -----------------------------------------------------------------
// ▼▼▼ 属性・種族アイコン 自動置換機能（修正版） ▼▼▼
// -----------------------------------------------------------------

function global_replace_buffer_start()
{
    // 管理画面では動かない
    if (is_admin()) return;

    // characterの個別ページ以外なら何もしない
    if (! is_singular('character')) {
        return;
    }

    // バッファリング開始
    ob_start('global_replace_callback');
}

// 検索結果からパスワード保護ページを除外する
function exclude_password_protected_from_search($where, $query)
{
    global $wpdb;

    // 管理画面ではなく、メインの検索クエリである場合のみ実行
    if (! is_admin() && $query->is_search() && $query->is_main_query()) {
        // パスワードが空（＝保護されていない）記事のみを対象にする条件を追加
        $where .= " AND {$wpdb->posts}.post_password = '' ";
    }

    return $where;
}
add_filter('posts_where', 'exclude_password_protected_from_search', 10, 2);

/**
 * 指定したメタキーの統計情報（平均・標準偏差）を取得する
 * ★改良版: 'total_99_hp', 'total_99_atk' 指定時に、基礎値+超化を合算して計算します
 */
function get_koto_stat_distribution($meta_key)
{
    $cache_key = 'koto_stat_dist_' . $meta_key;
    $stats = get_transient($cache_key);

    if ($stats !== false) {
        return $stats;
    }

    global $wpdb;
    $values = [];

    // ★ 特殊対応: Lv99の「基礎 + 超化」合計値の集計
    if ($meta_key === 'total_99_hp' || $meta_key === 'total_99_atk') {

        // キー名の決定 (HPかATKか)
        $base_key   = ($meta_key === 'total_99_hp') ? 'lv_99_hp' : 'lv_99_atk';
        $chouka_key = ($meta_key === 'total_99_hp') ? 'hp_chouka' : 'atk_chouka';

        // SQL: 基礎値(m1) と 超化(m2) を結合して足し合わせる
        // ※超化が未設定(NULL)の場合は0として扱う
        $sql = $wpdb->prepare("
            SELECT (CAST(m1.meta_value AS SIGNED) + COALESCE(CAST(m2.meta_value AS SIGNED), 0)) as total_val
            FROM {$wpdb->postmeta} m1
            LEFT JOIN {$wpdb->postmeta} m2 ON m1.post_id = m2.post_id AND m2.meta_key = %s
            JOIN {$wpdb->posts} p ON m1.post_id = p.ID
            WHERE p.post_type = 'character' 
            AND p.post_status = 'publish' 
            AND m1.meta_key = %s
            AND m1.meta_value > 0
        ", $chouka_key, $base_key);

        $values = $wpdb->get_col($sql);
    } else {
        // 通常の処理 (1つのキーのみ集計)
        $sql = $wpdb->prepare("
            SELECT meta_value 
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE p.post_type = 'character' 
            AND p.post_status = 'publish' 
            AND pm.meta_key = %s
            AND pm.meta_value > 0
        ", $meta_key);
        $values = $wpdb->get_col($sql);
    }

    if (empty($values)) {
        return ['avg' => 0, 'std_dev' => 1];
    }

    // 統計計算
    $count = count($values);
    $sum = array_sum($values);
    $avg = $sum / $count;

    $variance_sum = 0;
    foreach ($values as $val) {
        $variance_sum += pow((float)$val - $avg, 2);
    }
    $std_dev = sqrt($variance_sum / $count);

    $stats = [
        'avg' => $avg,
        'std_dev' => $std_dev,
        'count' => $count
    ];

    set_transient($cache_key, $stats, 3600); // 1時間キャッシュ

    return $stats;
}

/**
 * 数値とメタキーを渡して「偏差値」を返す関数
 */
function get_koto_deviation_score($value, $meta_key = '120_atk', $precision = 1)
{
    // ★修正: 値が空、または「数値ではない（未入力など）」場合は計算せずハイフンを返す
    if (empty($value) || !is_numeric($value)) return '-';

    // 統計データを取得
    $stats = get_koto_stat_distribution($meta_key);

    if ($stats['std_dev'] == 0) return '50.0'; // 全員同じ数値の場合

    // 偏差値 = ( (個人の値 - 平均) / 標準偏差 ) * 10 + 50
    $score = (($value - $stats['avg']) / $stats['std_dev']) * 10 + 50;

    // ★念のため: 0未満や100超えの異常値が出ないよう丸めることも可能ですが、今回はそのまま
    return number_format($score, $precision);
}


// =================================================================
// 検索結果テンプレートの振り分け設定 (正しい設定)
// =================================================================
add_filter('template_include', 'koto_custom_search_template');

function koto_custom_search_template($template)
{
    if (is_search()) {
        // 検索対象の投稿タイプを取得（URLパラメータもチェック）
        $post_type = get_query_var('post_type');
        if (empty($post_type) && isset($_GET['post_type'])) {
            $post_type = $_GET['post_type'];
        }

        // キャラクター検索の場合 -> search-character.php を使用
        if ($post_type === 'character') {
            $new_template = locate_template(['/lib/character-search/search-character.php']);
            if ($new_template) return $new_template;
        }
    }
    return $template;
}

// =================================================================
// キャラクターアーカイブ (/character/) を検索結果へリダイレクト
// =================================================================
add_action('template_redirect', function () {
    // キャラクターのアーカイブページ、かつ検索ページ（sパラメータが存在する状態）でない場合
    if (is_post_type_archive('character') && !is_search()) {

        // 現在のURLパラメータを連想配列として取得
        $url_params = $_GET;

        // s パラメータが含まれていない、またはnullの場合に空文字で追加
        if (!isset($url_params['s'])) {
            $url_params['s'] = '';
        }

        // 現在のベースURLを取得
        $base_url = home_url('/');

        // パラメータをURLクエリ文字列に再構築して合体させる
        // 例: ?post_type=character&tx_attr%5B0%5D=light&s=
        $redirect_url = add_query_arg($url_params, $base_url);

        // リダイレクトを実行
        wp_safe_redirect($redirect_url);
        exit;
    }
});

add_action('admin_init', function () {
    if (isset($_GET['force_calc_id'])) {
        $post_id = intval($_GET['force_calc_id']);
        // calc.phpの関数を直接呼ぶ
        on_save_character_specs($post_id);
        wp_die("ID: {$post_id} のJSONとタグを再生成しました。");
    }
});
// サイトURL/wp-admin/?force_calc_id=123 (出ないキャラのID) にアクセス

// ACFの繰り返しフィールドを行削除するショートカットJSを管理画面に追加
add_action('admin_footer', function () {
?>
    <script>
        document.addEventListener('keydown', function(e) {
            const activeEl = document.activeElement;
            if (!activeEl) return;

            const isInputTarget = ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON', 'A', 'DIV'].includes(activeEl.tagName);
            const isInsideRow = activeEl.closest('.acf-row') !== null;

            if (!isInputTarget && !isInsideRow) return;

            if (e.ctrlKey && e.shiftKey && e.altKey && e.code === 'KeyD') {
                e.preventDefault();
                const row = activeEl ? activeEl.closest('.acf-row') : null;
                if (row) {
                    const deleteBtn = row.querySelector('.acf-row-handle .acf-icon.-minus');
                    if (deleteBtn) {
                        deleteBtn.click();
                        setTimeout(() => {
                            let confirmBtn = row.querySelector('.acf-row-handle .acf-icon.-minus.-confirm');
                            if (!confirmBtn && deleteBtn.classList.contains('-confirm')) {
                                confirmBtn = deleteBtn;
                            }
                            if (confirmBtn) confirmBtn.click();
                        }, 100);
                    }
                }
            }

            if (e.ctrlKey && e.shiftKey && e.altKey && e.code === 'KeyT') {
                e.preventDefault();
                let current = activeEl;
                let topRow = null;

                while (current && current.parentElement) {
                    const row = current.closest('.acf-row');
                    if (row) {
                        topRow = row;
                        current = row.parentElement;
                    } else {
                        break;
                    }
                }

                if (topRow) {
                    const inputs = topRow.querySelectorAll('.acf-accordion-title, button:not([disabled]), div[tabindex], input:not([type="hidden"]):not([disabled]):not([readonly]), select:not([disabled]):not([readonly]), textarea:not([disabled]):not([readonly])');
                    let targetInput = null;

                    for (let i = 0; i < inputs.length; i++) {
                        if (inputs[i].offsetWidth > 0 || inputs[i].offsetHeight > 0) {
                            targetInput = inputs[i];
                            break;
                        }
                    }

                    if (targetInput) {
                        if (!['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON'].includes(targetInput.tagName) && !targetInput.hasAttribute('tabindex')) {
                            targetInput.setAttribute('tabindex', '-1');
                        }
                        targetInput.focus();
                        targetInput.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                    }
                }
            }
        });
    </script>
<?php
});
?>
<?php
// 投稿画面のショートカット
add_action('wp_footer', function () {
?>
    <script>
        document.addEventListener('keydown', (e) => {
            // Tabキーが押された、かつ何もフォーカスされていない（bodyがアクティブ）時
            if (e.key === 'Tab' && (document.activeElement === document.body || !document.activeElement)) {

                // ページ内のフォーカス可能な要素をすべて取得
                const focusableElements = Array.from(document.querySelectorAll(
                    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
                ));

                // 現在のスクロール位置（表示領域の最上部）より下にある最初の要素を探す
                const topElement = focusableElements.find(el => {
                    // ★追加: 特定のクラス（固定ヘッダーなど）に含まれる要素は無視
                    // '.your-fixed-class' の部分を無視したいクラス名（例: .site-header, .fixed-nav など）に書き換えてください
                    // ※Cocoonのヘッダー(.header, #header)なども追加しておくと安心です
                    if (el.closest('.nojq') || el.closest('.interface-interface-skeleton-header') || el.closest('.header') || el.closest('#header')) {
                        return false;
                    }

                    const rect = el.getBoundingClientRect();
                    return rect.top > 0; // 画面内に少しでも入っているもの
                });

                if (topElement) {
                    e.preventDefault(); // デフォルトの「ページ先頭へジャンプ」を阻止
                    topElement.focus();
                }
            }
        });
    </script>
<?php
});
// ACFフロントエディター（管理画面版）の読み込み
require_once get_stylesheet_directory() . '/lib/acf/acf-editor.php';
require_once get_stylesheet_directory() . '/lib/character-search/koto-json-reformer.php';
require_once get_stylesheet_directory() . '/lib/missing-info-characters.php';

$current_domain = $_SERVER['HTTP_HOST'] ?? '';
if (str_ends_with($current_domain, 'kotodaman-db.com')) {
    // 保存先の指定
    add_filter('acf/settings/save_json', function ($path) {
        // 子テーマ直下の acf-json フォルダを自動取得
        $path = get_stylesheet_directory() . '/acf-json';
        return $path;
    }, 20);

    // 読み込み先の指定
    add_filter('acf/settings/load_json', function ($paths) {
        // 配列を一度クリアして、子テーマの acf-json だけを登録
        $paths = array(get_stylesheet_directory() . '/acf-json');
        return $paths;
    }, 20);
}

// 解説：
// register_meta は、特定のメタデータ（_spec_json）の「振る舞い」を定義する関数です。
// これにより、WordPress の標準的なデータ露出ルートを塞ぎます。

add_action('init', function () {
    register_meta('post', '_spec_json', [
        'object_subtype' => 'character', // 投稿タイプ「character」に限定
        'show_in_rest'   => false,        // 【重要】REST API（JSON形式での外部露出）を無効化
        'single'        => true,
        'type'          => 'string',
    ]);
});

/**
 * ログイン中ならSite KitのGA4タグ出力をシステムレベルで止める
 */
add_filter('googlesitekit_analytics-4_tag_blocked', function ($is_blocked) {
    // ログインしている（管理バーが出ている状態）なら、強制的に「ブロック=true」を返す
    if (is_user_logged_in()) {
        return true;
    }
    return $is_blocked;
}, 100); // 100は優先順位。他の設定より後から上書きするために大きくしています。

// キャラクター検索フォームのCSSとJSを読み込む
function enqueue_character_search_assets()
{
    // CSSの読み込み
    wp_enqueue_style('character-search-style', get_stylesheet_directory_uri() . '/lib/character-search/searchform.css');

    // JSの読み込み
    wp_enqueue_script('character-search-script', get_stylesheet_directory_uri() . '/lib/character-search/searchform.js', array(), false, true);
}
add_action('wp_enqueue_scripts', 'enqueue_character_search_assets');

// ターム一括付与Ajax処理
require_once get_stylesheet_directory() . '/lib/term-setter/term-setter-ajax.php';

// ショートコード [test_acf_mapping] を定義する関数
function test_acf_mapping_shortcode()
{
    ob_start();

    // CSVファイルのパス（使用しているテーマフォルダ直下を想定）
    $csv_path = get_stylesheet_directory() . '/lib/ゲーム内文言ーACF-対応表.csv';

    // モックデータ（ファイルがない場合用）
    $mock_csv = [
        ['種別' => 'とくせい', '文言' => '福{$val}で{$gimmick_name}が解放', 'ACFに入力するJSON' => '{"trait_type" : "gimmick","gimmick" : "$gimmick_name","condition_type_loop" : [{"condition_type" : "fuku_count","condition_value" : "$val"}]}'],
        ['種別' => 'とくせい', '文言' => '【{$val}】属性のATKを{$val}UP', 'ACFに入力するJSON' => '{"trait_type" : "status_up","target" : "$val","rate" : "$val"}']
    ];

    // CSVの読み込み処理
    $csv_data = [];
    if (file_exists($csv_path)) {
        $file = fopen($csv_path, 'r');
        $headers = fgetcsv($file);
        while (($row = fgetcsv($file)) !== FALSE) {
            if (count($headers) == count($row)) {
                $csv_data[] = array_combine($headers, $row);
            }
        }
        fclose($file);
    } else {
        $csv_data = $mock_csv;
        echo "<p style='color:red;'>※CSVファイルが見つかりません。テスト用のモックデータで実行します。</p>";
    }

    // フォーム送信時の処理
    $input_text = isset($_POST['test_text']) ? sanitize_text_field($_POST['test_text']) : '';
    $result_output = '';

    if ($input_text) {
        $match_found = false;
        foreach ($csv_data as $row) {
            $template = $row['文言'] ?? '';
            $json_template = $row['ACFに入力するJSON'] ?? '';

            // 1. {$xxx} のプレースホルダーを探し出す
            preg_match_all('/\{\$(.*?)\}/', $template, $ph_matches);
            $pattern = preg_quote($template, '/');

            $seen_vars = []; // すでに出現した変数名を記録する配列

            if (!empty($ph_matches[1])) {
                foreach ($ph_matches[1] as $idx => $var_name) {
                    // エスケープされたプレースホルダー（例: "\{\$val\}"）
                    $quoted_ph = preg_quote($ph_matches[0][$idx], '/');

                    // ▼ 修正ポイント: 正規表現置換ではなく、文字列検索(strpos)で場所を特定
                    $pos = strpos($pattern, $quoted_ph);
                    if ($pos !== false) {
                        if (!in_array($var_name, $seen_vars)) {
                            // 1回目の出現：名前付きキャプチャグループ (?P<name>.+?) を作成
                            $replacement = '(?P<' . $var_name . '>.+?)';
                            $seen_vars[] = $var_name;
                        } else {
                            // 2回目以降の出現：後方参照 (?P=name) を使用
                            $replacement = '(?P=' . $var_name . ')';
                        }
                        // ▼ substr_replaceで、見つけた場所をピンポイントで置換する
                        $pattern = substr_replace($pattern, $replacement, $pos, strlen($quoted_ph));
                    }
                }
            }
            $pattern = '/^' . $pattern . '$/u';

            // 正規表現によるマッチング
            if (preg_match($pattern, $input_text, $matches)) {
                $match_found = true;
                $json_str = $json_template;

                // 置換処理
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $json_str = str_replace('$' . $key, $value, $json_str);
                    }
                }

                // JSON文字列を連想配列に変換
                $acf_data = json_decode($json_str, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $result_output = "<p style='color:red;'>JSONパースエラー: " . json_last_error_msg() . "<br>生成された文字列: " . esc_html($json_str) . "</p>";
                } else {
                    // 特殊ロジック（大きくUPならスーパー化）の適用
                    if (isset($acf_data['gimmick_prefix'])) {
                        if ($acf_data['gimmick_prefix'] === '大きく') {
                            $acf_data['gimmick'] = 'スーパー' . $acf_data['gimmick'];
                        }
                        unset($acf_data['gimmick_prefix']);
                    }

                    $result_output = "<h3 style='color:green;'>マッチ成功！</h3>";
                    $result_output .= "<p><strong>適用されたテンプレート:</strong> " . esc_html($template) . "</p>";
                    $result_output .= "<h4>▼ 生成されたACF用配列（この配列をupdate_fieldに渡します）</h4>";
                    $result_output .= "<pre style='background:#f4f4f4; padding:10px; border:1px solid #ccc;'>" . esc_html(print_r($acf_data, true)) . "</pre>";
                }
                break;
            }
        }

        if (!$match_found) {
            $result_output = "<h3 style='color:orange;'>一致するテンプレートが見つかりませんでした。</h3>";
        }
    }

    // UIの描画
?>
    <div style="max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
        <h2>CSV対応表 テストツール</h2>
        <form method="post" action="">
            <label for="test_text"><strong>AI抽出テキスト（ゲーム内文言）:</strong></label><br>
            <input type="text" id="test_text" name="test_text" value="<?php echo esc_attr($input_text); ?>" style="width: 100%; padding: 8px; margin: 10px 0;" placeholder="例: 福30でシールドブレイカーが解放">
            <button type="submit" style="padding: 10px 20px; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer;">テスト実行</button>
        </form>
        <hr>
        <div>
            <?php echo $result_output; ?>
        </div>
    </div>
<?php

    return ob_get_clean();
}
add_shortcode('test_acf_mapping', 'test_acf_mapping_shortcode');

// 管理画面のダッシュボード上部（ admin_notices ）にランダム変更ボタンを設置
function add_random_color_theme_button()
{
    // 処理用のURL（現在のページURLにクエリパラメータを付与）
    $ajax_url = wp_nonce_url(add_query_arg('action', 'set_random_admin_color'), 'random_color_nonce');

    echo '<div class="notice notice-info is-dismissible" style="margin-top: 15px;">';
    echo '<p>🎨 <a href="' . esc_url($ajax_url) . '" class="button button-primary">管理者の管理画面のテーマ色をランダムに変える</a></p>';
    echo '</div>';
}
add_action('admin_notices', 'add_random_color_theme_button');

// ボタンが押されたときの処理
function handle_random_admin_color_change()
{
    if (isset($_GET['action']) && $_GET['action'] === 'set_random_admin_color') {
        // セキュリティチェック（Nonceの検証）
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'random_color_nonce')) {
            wp_die('セキュリティエラーが発生しました。');
        }

        // WordPress標準のテーマ色スラッグのリスト
        $schemes = ['fresh', 'light', 'blue', 'coffee', 'ectoplasm', 'midnight', 'ocean', 'sunrise'];

        // ランダムに1つ選択
        // 現在のユーザーIDを取得してメタデータを更新
        $user_id = get_current_user_id();
        $random_scheme = $schemes[array_rand($schemes)];
        update_user_meta($user_id, 'admin_color', $random_scheme);
        $random_scheme = $schemes[array_rand($schemes)];
        update_user_meta(1, 'admin_color', $random_scheme);

        // クエリパラメータを除去した元のURLに戻してリロード
        $redirect_url = remove_query_arg(['action', '_wpnonce']);
        wp_safe_redirect($redirect_url);
        exit;
    }
}
add_action('admin_init', 'handle_random_admin_color_change');

add_filter('manage_users_columns', function ($columns) {
    $columns['user_id'] = 'ID';
    return $columns;
});
