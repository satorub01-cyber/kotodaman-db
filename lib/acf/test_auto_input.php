<?php
/**
 * ACF自動入力（auto-input）パーサー動作確認・回帰テストスクリプト
 *
 * 【使い方】
 * 1. 全カテゴリのデフォルトテスト文言（各5件）を一括実行する場合:
 *    php wp-content/themes/cocoon-child-master/lib/acf/test_auto_input.php
 *    （テーマディレクトリ直下から実行する場合: php lib/acf/test_auto_input.php）
 *
 * 2. 任意の単一文言をコマンドライン引数でテストする場合:
 *    php lib/acf/test_auto_input.php "<種別>" "<文言>"
 *    ※種別: わざ / とくせい / リーダーとくせい / すごわざ発動条件
 *    例: php lib/acf/test_auto_input.php "わざ" "6文字以上、または15コンボ以上の場合、追加でこのターン自身のクリティカル発生率を上昇＋敵単体に超強力な水属性攻撃"
 *
 * 3. テストケースを追加・変更したい場合:
 *    下記の $tests 配列に文言を追加して実行してください。
 */

// ブラウザからの直接アクセスを禁止（CLI実行専用）
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$theme_dir = dirname(__DIR__, 2);
$wp_load_path = dirname($theme_dir, 3) . '/wp-load.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname($theme_dir, 3) . '/');
}
if (file_exists($wp_load_path)) {
    require_once $wp_load_path;
}

require_once $theme_dir . '/lib/koto-variables.php';
require_once __DIR__ . '/auto-input/acf-auto-input-parsers.php';

$csv_path = $theme_dir . '/lib/ゲーム内文言ーACF-対応表.csv';
$csv_data = koto_load_csv_dictionary($csv_path);
$grouped_csv = koto_group_csv_by_type($csv_data);

// 引数指定時は単発テストを実行
if (isset($argv[1], $argv[2])) {
    $type = $argv[1];
    $phrase = $argv[2];
    $input_key = ($type === 'とくせい') ? 'auto_input_trait1' : '';
    $parsed = koto_parse_text_by_type($phrase, $type, $grouped_csv, $input_key);
    echo "■ 種別: {$type}\n";
    echo "■ 文言: {$phrase}\n";
    echo json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

$tests = [
    'わざ' => [
        // 1. 「または」区切り複数条件（文字数＋コンボ）＋クリティカル率バフ＋単体攻撃
        '6文字以上、または15コンボ以上の場合、追加でこのターン自身のクリティカル発生率を上昇＋敵単体に超強力な水属性攻撃',
        // 2. 「または」区切り複数条件（HP条件＋テーマ条件）＋バフ＋全体攻撃
        'HPが50%以下、または「いきもの」テーマのことばを作った場合、追加でこのターン攻撃する味方全体のATKを少し強化＋敵全体に超絶強力な火属性攻撃',
        // 3. 「または」区切り3条件（同時発動＋文字数＋コンボ）＋デバフ＋連撃
        '同時に水属性のコトダマンがわざ・すごわざを発動させていた、または6文字以上、または12コンボ以上の場合、敵単体に被ダメージ増加状態を2ターン付与＋敵単体に超絶強力な水属性の8回攻撃',
        // 4. 通常効果＋句点区切りの「または」複数条件付き追加効果
        '敵単体に超絶強力な木属性攻撃。5文字以上、または10コンボ以上の場合、追加でHPを大きく回復',
        // 5. 文字シフト＋複数効果
        '自身の文字に応じて効果が変化する①「あ」の時、敵単体に爆絶強力な光属性の10回攻撃②「い」の時、手札の味方にATK強化を2ターン付与＋敵全体に超絶強力な光属性攻撃',
    ],
    'とくせい' => [
        // 1. 条件付き威力UP
        '5文字以上の言葉を作成した時、自身の威力が50%UP',
        // 2. 複数条件（HP＋コンボ）＋ダメージ上限増加
        'HPが80%以上の時、10コンボ以上で自身の1回の攻撃で与えることができるダメージの上限を300000増加',
        // 3. スーパーギミック（文字変換追加パイプ結合）
        'チェンジマスの効果を受けない。さらにチェンジマスがある場合、文字変換に「あ・い」が追加される',
        // 4. ドロー時バフ
        'ドロー時に味方のATKを少し強化を1ターン付与',
        // 5. 実体化時コンボ＋＆被弾時ATK上昇
        '①実体化時に、ことばの判定でコンボの数を+2する②このコトダマンが敵からの攻撃を受けた場合、ATKが150上昇する(上限4回)',
    ],
    'リーダーとくせい' => [
        // 1. 編成条件＋コンボ条件＋固定倍率（パターンB）
        'デッキ内に火属性が6体以上いる場合、10コンボ以上で火属性のHP120%・ATK260%・睡眠耐性100%UP',
        // 2. 同時文字作成条件＋固定倍率
        '光・闇属性が同時に文字を作ったとき、デッキ内全員のHP100%・ATK240%UP',
        // 3. WAVE加算条件
        'クエスト中、WAVEを進めるたび火属性のATKが40%を加算する(3WAVE目まで発動)',
        // 4. もじ含有条件＋クリティカル補正
        '「あ・い・う」を含むことばを作成した時、デッキ内全員のクリティカル発生率30%・クリティカル威力50%UP',
        // 5. キャラ数倍率（パターンA）
        'デッキ内全員のHPを「クロニクル」×20%・ATKを「クロニクル」×20%・汚染耐性を「クロニクル」×20%UP',
    ],
    'すごわざ発動条件' => [
        // 1. 文字数条件＋コンボ条件（OR条件）
        '①4文字以上②10コンボ以上',
        // 2. テーマ条件＋頭文字指定＆文字数（AND条件）
        '①いきもののことば②「あかさ」からはじまる4文字以上',
        // 3. 語尾指定＋文字数（AND条件）
        '「んい」でおわる5文字以上',
        // 4. 文字含有＋コンボ条件（AND条件）
        '「し」を含む12コンボ以上',
        // 5. 省略記法（m=文字, c=コンボ, t=のことば）
        '①4m②10c③いきものt',
    ],
];

foreach ($tests as $type => $phrases) {
    echo "========================================\n";
    echo "■ カテゴリ: {$type}\n";
    echo "========================================\n";
    $input_key = ($type === 'とくせい') ? 'auto_input_trait1' : '';
    foreach ($phrases as $i => $phrase) {
        $num = $i + 1;
        $parsed = koto_parse_text_by_type($phrase, $type, $grouped_csv, $input_key);
        echo "[Test {$num}] {$phrase}\n";
        echo json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    }
}

