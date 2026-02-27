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
        return wp_get_attachment_image($icon_id, 'full', false, ['class' => $class_name, 'alt' => $term->name]);
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
require_once get_stylesheet_directory() . '/lib/koto-search.php';
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

/**
 * ACFのリピーター(moji_bind_loop)内の文字を、
 * 実際のWordPressのタクソノミー(moji)として自動保存する処理
 */
function my_update_moji_terms($post_id)
{

    // 1. オートセーブやリビジョンの時は何もしない
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    // 2. リピーターフィールド名とタクソノミースラッグを設定
    $repeater_key = 'available_moji_loop'; // リピーター名
    $sub_field_key = 'available_moji';     // その中の文字フィールド名
    $taxonomy_slug = 'available_moji';          // 文字タクソノミーのスラッグ (例: kotodaman_moji など)

    // ※タクソノミーのスラッグが 'moji' じゃない場合は書き換えてください！
    // 管理画面のURL ...?taxonomy=ここ を確認

    // 3. リピーターの値を取得
    $rows = get_field($repeater_key, $post_id);
    $term_ids = [];

    if ($rows) {
        foreach ($rows as $row) {
            // 文字オブジェクトを取得 (複数選択対応)
            $terms = $row[$sub_field_key];

            if ($terms) {
                // 複数選択の場合は配列、単一の場合はオブジェクトが来るので統一して処理
                if (is_array($terms)) {
                    foreach ($terms as $t) {
                        if (isset($t->term_id)) $term_ids[] = (int) $t->term_id;
                    }
                } elseif (isset($terms->term_id)) {
                    $term_ids[] = (int) $terms->term_id;
                }
            }
        }
    }

    // 4. 重複を削除して整数化
    $term_ids = array_unique(array_map('intval', $term_ids));

    // 5. 投稿にタームをセットする (上書き保存)
    // これで「検索」や「アーカイブページ」にヒットするようになります！
    wp_set_object_terms($post_id, $term_ids, $taxonomy_slug);
}

// ACFの保存処理が終わった後に実行させるフック
add_action('acf/save_post', 'my_update_moji_terms', 20);

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

require_once get_stylesheet_directory() . '/lib/chara-list-functions.php';
/**
 * 1. event と affiliation の権限設定を強制的に上書き（特注の鍵穴にする）
 */
function override_event_affiliation_caps($args, $taxonomy)
{
    $target_taxonomies = ['event', 'affiliation'];

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
    $roles_to_modify = ['administrator', 'editor', 'author', 'contributor'];

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
            if ($role_slug === 'administrator') {
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

    // 4. 全キャラクター取得
    $args = [
        'post_type'      => 'character',
        'posts_per_page' => -1,
        'post_status'    => 'publish', // 公開済みのみ
        'fields'         => 'ids',     // IDだけ取得
    ];
    $query = new WP_Query($args);

    echo '<div style="background:#fff; padding:20px; border:2px solid #00a0d2; margin:20px; z-index:9999; position:relative;">';
    echo "<h3>デバッグ情報</h3>";
    echo "<ul>";
    echo "<li>計算ファイルパス: " . $calc_file . " (" . (file_exists($calc_file) ? '発見' : '見つかりません') . ")</li>";
    echo "<li>対象キャラクター数: " . $query->found_posts . " 体</li>";
    echo "<li>保存関数 (on_save_character_specs): " . (function_exists('on_save_character_specs') ? '有効' : '無効(見つかりません)') . "</li>";
    echo "</ul>";

    $count = 0;
    if ($query->have_posts()) {
        foreach ($query->posts as $post_id) {

            // 関数が存在する場合のみ実行
            if (function_exists('on_save_character_specs')) {
                on_save_character_specs($post_id);
                $count++;
            }
        }
    }

    // 完了メッセージ
    echo "<h3>更新結果</h3>";
    echo "<p><strong>{$count}</strong> 体のデータを更新しました。</p>";

    if ($count === 0 && $query->found_posts > 0) {
        echo "<p style='color:red;'>※キャラクターはいるのに更新数が0です。保存関数が読み込めていません。<br>koto-calc.php がテーマフォルダ直下にあるか確認してください。</p>";
    }

    echo '<a href="' . remove_query_arg('run_update_index') . '" style="display:inline-block; margin-top:10px; padding:10px 20px; background:#00a0d2; color:#fff; text-decoration:none;">元の画面に戻る</a>';
    echo '</div>';
    exit;
}
// デバッグ用ショートコード: [debug_koto_json id=123]
add_shortcode('debug_koto_json', function ($atts) {
    $atts = shortcode_atts(['id' => get_the_ID()], $atts);
    $json = get_post_meta($atts['id'], '_spec_json', true);

    if (!$json) return 'データがありません。保存し直すか一括更新を実行してください。';

    $data = json_decode($json, true);

    // 見やすく出力
    return '<pre style="background:#eee; padding:10px; font-size:12px; height:400px; overflow:auto;">'
        . print_r($data, true)
        . '</pre>';
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

function koto_replace_icons($buffer)
{

    // ==========================================
    // 0. 除外（マスク）処理
    // ==========================================

    // ★手順A: ここに「誤爆させたくない単語」を追加してください
    $ignore_words = [
        '植物・',  // 「物」種族の誤爆防止
        '花火・',  // 「火」属性の誤爆防止
        '生き物・',  // 「物」種族の誤爆防止
        // 必要に応じて追加
    ];

    // 除外したいクラス名やタグの正規表現リスト
    $ignore_patterns = [
        // 1. .article h1 (h1タグ全体を保護)
        '/<h1\b[^>]*>.*?<\/h1>/us',

        // 2. 指定されたクラス名を含むタグ
        '/<([a-z0-9]+)\b[^>]*class=["\'][^"\']*(?:prev-post-title|next-post-title|entry-title|grid-char-name|tab-text|tab-link)[^"\']*["\'][^>]*>.*?<\/\1>/us',

        // 3. titleタグ
        '/<title>.*?<\/title>/us',

        // 4. 属性値の中身 (alt="火・水" など)
        '/=["\'][^"\']*["\']/s',
    ];

    // ★手順B: 単語リストを正規表現に変換してパターンに追加
    if (! empty($ignore_words)) {
        // (植物・|花火・|生物・) という形に変換
        $ignore_regex_parts = array_map(function ($word) {
            return preg_quote($word, '/');
        }, $ignore_words);

        // リストに追加
        $ignore_patterns[] = '/' . implode('|', $ignore_regex_parts) . '/u';
    }

    // マスクした内容を保存しておく金庫
    $saved_masks = [];

    // 除外対象を一時的にプレースホルダー (##MASK_0## 等) に置き換える
    foreach ($ignore_patterns as $pattern) {
        $buffer = preg_replace_callback($pattern, function ($matches) use (&$saved_masks) {
            $placeholder = '##MASK_' . count($saved_masks) . '##';
            $saved_masks[$placeholder] = $matches[0];
            return $placeholder;
        }, $buffer);
    }


    // ==========================================
    // 1. 属性の置換処理
    // ==========================================
    $slug_map = [
        '火' => 'fire',
        '水' => 'water',
        '木' => 'wood',
        '光' => 'light',
        '闇' => 'dark',
        '冥' => 'void',
        '天' => 'heaven',
    ];

    $keys_regex = implode('|', array_keys($slug_map));
    $pattern_attr = '/(' . $keys_regex . ')(属性|・)/u';

    $buffer = preg_replace_callback($pattern_attr, function ($matches) use ($slug_map) {
        $element_name = $matches[1];
        $suffix       = $matches[2];

        if (! isset($slug_map[$element_name])) return $matches[0];

        $slug = $slug_map[$element_name];
        $img_tag = '<img src="https://www.kotodaman-db.com/wp-content/uploads/2025/12/icon-' . $slug . '.png" alt="' . $element_name . '属性" class="attr-icon-img">';

        if ($suffix === '・') {
            return $img_tag . '・';
        } else {
            return $img_tag;
        }
    }, $buffer);


    // ==========================================
    // 2. 種族の置換処理
    // ==========================================
    $slug_map_race = [
        '神' => 'god',
        '魔' => 'demon',
        '英' => 'hero',
        '龍' => 'dragon',
        '獣' => 'beast',
        '霊' => 'spirit',
        '物' => 'artifact',
        '妖' => 'yokai',
    ];

    $race_keys_regex = implode('|', array_keys($slug_map_race));
    $pattern_race = '/(' . $race_keys_regex . ')(種族|・)/u';

    $buffer = preg_replace_callback($pattern_race, function ($matches) use ($slug_map_race) {
        $name   = $matches[1];
        $suffix = $matches[2];

        if (! isset($slug_map_race[$name])) return $matches[0];

        $slug = $slug_map_race[$name];
        $img_tag = '<img src="https://www.kotodaman-db.com/wp-content/uploads/2025/12/icon-' . $slug . '.png" alt="' . $name . '種族" class="species-icon-img">';

        if ($suffix === '・') {
            return $img_tag . '・';
        } else {
            return $img_tag;
        }
    }, $buffer);


    // ==========================================
    // 3. マスク解除
    // ==========================================
    if (! empty($saved_masks)) {
        $buffer = str_replace(array_keys($saved_masks), array_values($saved_masks), $buffer);
    }

    return $buffer;
}

// ★ここが抜けていたためエラーになっていました！
function global_replace_buffer_end()
{
    if (is_admin()) return;

    if (ob_get_length()) {
        ob_end_flush();
    }
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
            $new_template = locate_template(['search-character.php']);
            if ($new_template) return $new_template;
        }
    }
    return $template;
}

// =================================================================
// キャラクターアーカイブ (/character/) を検索結果へリダイレクト
// =================================================================
add_action('template_redirect', function () {
    // キャラクターのアーカイブページ、かつ検索ページでない場合
    if (is_post_type_archive('character') && !is_search()) {

        // 検索クエリ（全件表示）付きのURLを生成
        // ?s=&post_type=character
        $search_url = home_url('/?s=&post_type=character');

        // リダイレクト実行 (301: 恒久的な移動)
        wp_safe_redirect($search_url, 301);
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
            // 感知範囲を「行の中の項目（入力欄）」に限定する
            // 感知範囲を「入力欄、ボタン、または行(.acf-row)の中の要素」に拡張する
            const activeEl = document.activeElement;
            if (!activeEl) return;

            const isInputTarget = ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON', 'A'].includes(activeEl.tagName);
            const isInsideRow = activeEl.closest('.acf-row') !== null;

            // 入力欄でもなく、行の中にもいない場合は弾く
            if (!isInputTarget && !isInsideRow) return;

            // Ctrl + Shift + Alt + D という「まず被らない組み合わせ」をトリガーにする
            if (e.ctrlKey && e.shiftKey && e.altKey && e.code === 'KeyD') {
                e.preventDefault();
                const row = activeEl ? activeEl.closest('.acf-row') : null;
                if (row) {
                    // ★修正: 行のハンドル(.acf-row-handle)内にある削除ボタンを厳密に取得（誤爆防止）
                    const deleteBtn = row.querySelector('.acf-row-handle .acf-icon.-minus');
                    if (deleteBtn) {
                        deleteBtn.click();
                        // 0.1秒待って確認ボタンが出てきたらクリック
                        setTimeout(() => {
                            let confirmBtn = row.querySelector('.acf-row-handle .acf-icon.-minus.-confirm');
                            // ボタン自体が変化している場合もあるためチェック
                            if (!confirmBtn && deleteBtn.classList.contains('-confirm')) {
                                confirmBtn = deleteBtn;
                            }
                            if (confirmBtn) confirmBtn.click();
                        }, 100);
                    }
                }
            }
            // 【追加】先頭へ移動 (Ctrl+Shift+Alt+T)
            if (e.ctrlKey && e.shiftKey && e.altKey && e.code === 'KeyT') {
                e.preventDefault();
                let current = activeEl;
                let topRow = null;

                // 親を遡って一番外側の .acf-row を探す
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
                    // 最初に見つかる入力要素（hidden以外）にフォーカス
                    const firstInput = topRow.querySelector('input:not([type="hidden"]), select, textarea');
                    if (firstInput) {
                        firstInput.focus();
                        firstInput.scrollIntoView({
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
require_once get_stylesheet_directory() . '/lib/koto-json-reformer.php';
?>