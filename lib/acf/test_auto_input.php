<?php
/**
 * ACF自動入力（auto-input）パーサー動作確認・回帰テストスクリプト
 *
 * ※注意: 自動入力関連（lib/acf/auto-input/ や lib/ゲーム内文言ーACF-対応表.csv）の
 *   変更がないタスクでは、本スクリプトを実行する必要はありません（auto_input修正時のみ必須）。
 *
 * 【使い方】
 * 1. 全カテゴリのデフォルトテスト文言を一括実行する場合:
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

// テスト詳細出力フラグ（初期値 false。--test-mode または --verbose 指定時に true）
$test_mode = in_array('--test-mode', $argv, true) || in_array('--verbose', $argv, true);
$cli_args = array_values(array_filter(array_slice($argv, 1), static function ($arg) {
    return !in_array($arg, ['--test-mode', '--verbose'], true);
}));
if ($test_mode && !defined('KOTO_AUTO_INPUT_TEST_MODE')) {
    define('KOTO_AUTO_INPUT_TEST_MODE', true);
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

// 既存キャラ投稿IDから祝福テンプレートを抽出するCLIオプション
// 例: php lib/acf/test_auto_input.php --extract-blessing 3685 pattern_2
if (isset($cli_args[0]) && $cli_args[0] === '--extract-blessing') {
    $extract_post_id = isset($cli_args[1]) ? (int) $cli_args[1] : 0;
    $extract_tpl_key = isset($cli_args[2]) ? trim((string) $cli_args[2]) : 'pattern_custom';
    if ($extract_post_id <= 0) {
        fwrite(STDERR, "Usage: php lib/acf/test_auto_input.php --extract-blessing <POST_ID> [TEMPLATE_KEY]\n");
        exit(1);
    }
    $extracted = koto_extract_blessing_template_from_post($extract_post_id, $extract_tpl_key);
    echo "■ 抽出元 Post ID: {$extracted['post_id']} ({$extracted['post_title']})\n";
    echo "■ CSV追記用1行:\n{$extracted['csv_line']}\n";
    if ($test_mode) {
        echo "■ 抽出元の生データ (pt_pattern=default):\n";
        echo json_encode($extracted['raw_rows'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
    exit(!empty($extracted['tokens']) ? 0 : 1);
}

// 引数指定時は単発テストを実行
if (isset($cli_args[0], $cli_args[1])) {
    $type = $cli_args[0];
    $phrase = $cli_args[1];
    $input_key = ($type === 'とくせい') ? 'auto_input_trait1' : (($type === 'EX祝福') ? 'auto_input_ex_blessing' : (($type === '祝福') ? 'auto_input_blessing' : ''));
    $parsed = koto_parse_text_by_type($phrase, $type, $grouped_csv, $input_key);
    echo "■ 種別: {$type}\n";
    echo "■ 文言: {$phrase}\n";
    $flags = JSON_UNESCAPED_UNICODE | ($test_mode ? JSON_PRETTY_PRINT : 0);
    echo json_encode($parsed, $flags) . "\n";
    exit(!empty($parsed) ? 0 : 1);
}

$tests = [
    'わざ' => [
        // 1. 「または」区切り複数条件（文字数＋コンボ）＋クリティカル率バフ＋単体攻撃
        '6文字以上、または15コンボ以上の場合、追加でこのターン自身のクリティカル発生率を上昇＋敵単体に超強力な水属性攻撃',
        // 2. 「または」区切り複数条件（HP条件＋テーマ条件）＋全体ATKバフ＋全体攻撃
        'HPが50%以下、または「いきもの」テーマのことばを作った場合、追加でこのターン攻撃する味方全体のATKを少し強化＋敵全体に超絶強力な火属性攻撃',
        // 3. 「または」区切り3条件（同時発動＋文字数＋コンボ）＋被ダメ増加デバフ＋連撃
        '同時に水属性のコトダマンがわざ・すごわざを発動させていた、または6文字以上、または12コンボ以上の場合、敵単体に被ダメージ増加状態を2ターン付与＋敵単体に超絶強力な水属性の8回攻撃',
        // 4. 通常効果＋句点「。」区切りの「または」複数条件付き追加効果
        '敵単体に超絶強力な木属性攻撃。5文字以上、または10コンボ以上の場合、追加でHPを大きく回復',
        // 5. 文字シフト（sugo_shift_type = moji）＋丸数字パート分割＋複数効果
        '自身の文字に応じて効果が変化する①「あ」の時、敵単体に爆絶強力な光属性の10回攻撃②「い」の時、手札の味方にATK強化を2ターン付与＋敵全体に超絶強力な光属性攻撃',
        // 6. 属性シフト（sugo_shift_type = attr）による属性ごとの行複製＆attack_attr自動上書き
        '自身の属性に応じて火属性の時は【火】、水属性の時は【水】に効果が変化する①敵単体に超絶強力な火属性攻撃②手札の火属性にATK強化を1ターン付与',
        // 7. ランダムシフト（sugo_shift_type = random）と random_count の自動付与
        '次の2つからランダムで1つ発動する①敵単体に爆絶強力な闇属性攻撃②敵全体に超絶強力な闇属性攻撃',
        // 8. 被弾回数シフト（sugo_shift_type = attacked）と sugo_shift_attacked の階層引き上げ
        'このコトダマンがクエスト中に敵から受けた攻撃の回数に応じて効果が変化する①攻撃を1回以上受けた場合、敵単体に強力な木属性攻撃②攻撃を3回以上受けた場合、敵単体に爆絶強力な木属性攻撃',
        // 9. 倍率テーブル（maltiplier_table: moji_converged）の自動生成＋全属性有利（omni_advantage）
        '敵全体に文字数に応じて威力が上昇し、敵の数が少ないほど威力が大きく上昇する全属性有利となる天属性攻撃',
        // 10. フィールド展開（battle_field）＋連携攻撃（coop_attack）
        '水属性威力20%・神種族威力20%UP効果のフィールドを3ターン展開＋このターンわざ・すごわざを発動させた「クロニクル」のコトダマンと同じ属性で敵単体に超絶強力な攻撃',
        // 11. パイプ分割わざ条件（同時発動：グループまたは種族）＋重圧（preassure）＋乱打（random_oppo）
        '同時に「クロニクル」または神種族のコトダマンがわざ・すごわざを発動させていた場合、敵単体に重圧1を2ターン付与＋9回、ランダムな敵に超絶強力な冥属性攻撃を与える',
    ],
    'とくせい' => [
        // 1. 単一条件（文字数）＋自身の威力UP
        '5文字以上の言葉を作成した時、自身の威力が50%UP',
        // 2. 複数条件（HP＋コンボ）＋ダメージ上限増加
        'HPが80%以上の時、10コンボ以上で自身の1回の攻撃で与えることができるダメージの上限を300000増加',
        // 3. スーパーギミック（文字変換追加パイプ結合）
        'チェンジマスの効果を受けない。さらにチェンジマスがある場合、文字変換に「あ・い」が追加される',
        // 4. ドロー時バフ
        'ドロー時に味方のATKを少し強化を1ターン付与',
        // 5. 丸数字による複数とくせい（実体化時コンボ＋＆被弾時ATK上昇）
        '①実体化時に、ことばの判定でコンボの数を+2する②このコトダマンが敵からの攻撃を受けた場合、ATKが150上昇する(上限4回)',
        // 6. 他者へのとくせい付与（whose_trait キー変換 ＆ 文字条件 char）
        'デッキ内の火属性の文字が「あ・い・う」の時、そのコトダマンの威力が50%UP',
        // 7. デッキ内キャラ1枚につきステータスUP（per_unit_tf ＆ deck_ally_field_group キー変換）
        'デッキの神種族1枚につきATKを40UP',
        // 8. パイプ分割による複数ステータス同時UP（所属グループのATK UP・状態異常耐性）
        'デッキ内の「クロニクル」のATK15%UP・呪い耐性50%',
        // 9. スーパーアタックコア（need_combos 4段階展開）＆ヒールコア（式計算 $val*1000）
        '①このコトダマンを含むことばで【10|15|20|25】コンボ達成した場合、このターン攻撃する味方の行動前、自身にATKを【少し強化|強化|大きく強化|超大きく強化】を与える②このコトダマンを含むことばで10コンボ達成した場合、敵ターン終了時、HPを少し強化',
        // 10. 新とくせい系（看破 see_through ＆ 共鳴 resonance）
        '①自身が実体化時に、敵全体に被ダメージ少し増加状態を1ターン付与し、このターン敵全体が受けるダメージの上限を100000増加②自身が実体化した文字と同じ文字のコトダマンが実体化している場合、ことば読み上げ完了後にこのターン攻撃する自身と、自身と同じ文字のコトダマンの威力が30%UP・1回の攻撃で与えることができるダメージの上限を200000増加',
        // 11. 単体単発攻撃補正＋上限解放複合（single_shot）＆すごわざ発動条件追加（sugowaza_condition）
        '①デッキ内の水属性の単体単発攻撃で敵に与えるダメージが30%UP・単体単発攻撃で与えることができるダメージの上限を200000増加②自身のすごわざ発動条件に「いきもののことば」を追加する',
    ],
    'リーダーとくせい' => [
        // 1. 編成条件＋コンボ条件＋固定倍率（パターンB）
        'デッキ内に火属性が6体以上いる場合、10コンボ以上で火属性のHP120%・ATK260%・睡眠耐性100%UP',
        // 2. 同時文字作成条件＋固定倍率
        '光・闇属性が同時に文字を作ったとき、デッキ内全員のHP100%・ATK240%UP',
        // 3. WAVE加算条件（leader_text の前方復元処理）
        'クエスト中、WAVEを進めるたび火属性のATKが40%を加算する(3WAVE目まで発動)',
        // 4. もじ含有条件＋クリティカル補正
        '「あ・い・う」を含むことばを作成した時、デッキ内全員のクリティカル発生率30%・クリティカル威力50%UP',
        // 5. キャラ数倍率（パターンA：per_unit_loop）
        'デッキ内全員のHPを「クロニクル」×20%・ATKを「クロニクル」×20%・汚染耐性を「クロニクル」×20%UP',
        // 6. 複数の編成条件（属性＋種族）の同一 ls_cond_type (chara_num) へのANDマージ処理
        'デッキ内に水属性が4体以上・神種族が4体以上いる場合、水属性のHP140%・ATK280%UP',
        // 7. 特殊 ls_type の仕分け（通常固定値 fixed ＋ 腐敗 corruption ＋ ダメージカット damage_cut）
        '①火属性のHP120%・ATK240%・混乱耐性100%UP②火属性がわざ・すごわざ・コトわざでダメージを与えたとき、腐敗20%を2ターン付与③火属性が受ける5000以下のダメージを無効化',
        // 8. その他の特殊 ls_type（オーバーヒール over_heal・追加ATK add_atk）
        '①木属性がHP上限を超えて回復で固定ダメージ②木属性のATKが追加で40%UP',
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
        // 6. 省略記法の文字指定系（s=からはじまる, e=でおわる, i=を含む）
        '①あかさs4m②んいe5m③しi12c',
        // 7. 頭文字＋語尾＋文字数の3条件AND結合
        '「あ」からはじまる「ん」でおわる5文字以上',
    ],
    '祝福' => [
        // 1. タイプ1（テンプレート注入：★6通常、複数キラー対象属性・種族のインデックス付き配列展開）
        '{"template":"spe2_atkup","rarity":"なし","attrs":["火"],"species":["神","魔"],"rows":[]}',
        // 2. タイプ1（★5キャラ：キラー値が -5 されることの確認）
        '{"template":"only_core","rarity":"5","attrs":["火"],"species":["神"],"rows":[]}',
        // 3. タイプ2（とくせい自動入力の流用＋祝福ポイント設定、trait_rate -> blessing_value 変換、文字追加・すごわざ条件追加）
        '{"template":"","attrs":[],"species":[],"rows":[{"point":"20","text":"5文字以上の言葉を作成した時、自身の威力が50%UP"},{"point":"40","text":"文字変換に「あ・い」を追加する"},{"point":"50","text":"自身のすごわざ発動条件に「いきもののことば」を追加する"}]}',
        // 4. タイプ1＋タイプ2の複合指定（テキスト記法での rarity=5 指定と順序確認）
        "@template:only_core(rarity=5,attrs=光,species=英)\n20pt: ドロー時に味方のATKを少し強化を1ターン付与",
        // 5. グループドローバフ（affiliation自動注入）
        '{"template":"{group_draw_buff}","affiliations":["呪術廻戦"],"rows":[]}',
    ],
];

$total_count = 0;
$passed_count = 0;
$failed_cases = [];

foreach ($tests as $type => $phrases) {
    if ($test_mode) {
        echo "========================================\n";
        echo "■ カテゴリ: {$type}\n";
        echo "========================================\n";
    }
    $cat_passed = 0;
    $input_key = ($type === 'とくせい') ? 'auto_input_trait1' : '';
    foreach ($phrases as $i => $phrase) {
        $num = $i + 1;
        $total_count++;
        $parsed = koto_parse_text_by_type($phrase, $type, $grouped_csv, $input_key);
        if (!empty($parsed)) {
            $passed_count++;
            $cat_passed++;
        } else {
            $failed_cases[] = "[{$type} #{$num}] {$phrase}";
        }
        if ($test_mode) {
            echo "[Test {$num}] {$phrase}\n";
            echo json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        }
    }
    if (!$test_mode) {
        echo sprintf("[PASS] %-18s : %d / %d passed\n", $type, $cat_passed, count($phrases));
    }
}

// 祝福インデックス不足時の警告テスト
$blessing_warn_tests = [
    [
        'label' => '祝福: only_core で属性1が不足',
        'input' => '{"template":"only_core","attrs":[],"species":["神"],"rows":[]}',
        'expected_substr' => '属性インデックス1（attr_killer1, attr_mitigation1）に対する属性が足りません。',
    ],
    [
        'label' => '祝福: spe2_atkup で種族2が不足',
        'input' => '{"template":"spe2_atkup","attrs":["火"],"species":["神"],"rows":[]}',
        'expected_substr' => '種族インデックス2（species_killer2）に対する種族が足りません。',
    ],
    [
        'label' => '祝福: comb_plus で属性2が不足',
        'input' => '{"template":"comb_plus","attrs":["火"],"species":["神"],"rows":[]}',
        'expected_substr' => '属性インデックス2（attr_killer2）に対する属性が足りません。',
    ],
    [
        'label' => 'EX祝福: spe2_atkup で種族1が不足',
        'input' => '{"template":"spe2_atkup","attrs":["火"],"species":[],"rows":[]}',
        'input_key' => 'auto_input_ex_blessing',
        'expected_substr' => 'EX祝福: 種族インデックス1（species_killer1）に対する種族が足りません。',
    ],
    [
        'label' => '祝福: グループドローバフで所属が不足',
        'input' => '{"template":"{group_draw_buff}","rows":[]}',
        'expected_substr' => 'グループドローバフの対象グループ（所属）が未設定です。',
    ],
];

$warn_passed = 0;
foreach ($blessing_warn_tests as $w_test) {
    $total_count++;
    $GLOBALS['koto_last_blessing_parse_meta'] = [];
    $input_key = $w_test['input_key'] ?? 'auto_input_blessing';
    koto_parse_blessing($w_test['input'], $grouped_csv, $input_key);
    $warnings = function_exists('koto_get_blessing_auto_input_warnings') ? koto_get_blessing_auto_input_warnings() : [];
    $all_warn_text = implode("\n", $warnings);

    if (strpos($all_warn_text, $w_test['expected_substr']) !== false) {
        $passed_count++;
        $warn_passed++;
    } else {
        $failed_cases[] = "[祝福警告テスト] {$w_test['label']} (期待: {$w_test['expected_substr']} / 実際: {$all_warn_text})";
    }
}
if (!$test_mode) {
    echo sprintf("[PASS] %-18s : %d / %d passed\n", '祝福警告テスト', $warn_passed, count($blessing_warn_tests));
}

// 祝福 items 新形式の順序パーステスト
$items_seq_test = [
    'items' => [
        ['type' => 'token', 'token' => 'hp_up'],
        ['type' => 'token', 'token' => 'attr_killer', 'target' => '火'],
        ['type' => 'free',  'point' => '20', 'text' => 'ドロー時に味方のATKを少し強化を1ターン付与'],
        ['type' => 'token', 'token' => 'species_killer', 'target' => '神'],
    ]
];
$total_count++;
$seq_rows = koto_parse_blessing($items_seq_test, $grouped_csv, 'auto_input_blessing');
if (count($seq_rows) === 4
    && ($seq_rows[0]['trait_type'] ?? '') === 'status_up'
    && ($seq_rows[1]['trait_type'] ?? '') === 'damage_correction'
    && ($seq_rows[2]['trait_type'] ?? '') === 'draw_eff'
    && ($seq_rows[3]['trait_type'] ?? '') === 'damage_correction'
) {
    $passed_count++;
    if (!$test_mode) {
        echo sprintf("[PASS] %-18s : %d / %d passed\n", '祝福並び順テスト', 1, 1);
    }
} else {
    $failed_cases[] = '[祝福並び順テスト] items配列の順序パースに失敗しました（件数: ' . count($seq_rows) . '）';
}

// レアリティ判定の型安全性テスト（WP_Term, 文字列, 配列, null）
$total_count++;
$term_star5 = (object) ['term_id' => 86, 'name' => '5', 'slug' => '5'];
$term_star6 = (object) ['term_id' => 87, 'name' => 'なし', 'slug' => 'none'];
$is_safe_star5 = koto_is_blessing_star5_rarity($term_star5, 0);
$is_safe_star6 = !koto_is_blessing_star5_rarity($term_star6, 0);
$is_safe_arr = koto_is_blessing_star5_rarity([$term_star6, $term_star5], 0);
$is_safe_null = !koto_is_blessing_star5_rarity(null, 0);
if ($is_safe_star5 && $is_safe_star6 && $is_safe_arr && $is_safe_null) {
    $passed_count++;
    if (!$test_mode) {
        echo sprintf("[PASS] %-18s : %d / %d passed\n", '祝福レアリティ安全性', 1, 1);
    }
} else {
    $failed_cases[] = '[祝福レアリティ安全性] WP_Termオブジェクト等の型安全性判定に失敗しました';
}

// 祝福ギミックトークン & スーパー化 & ptフォールバックテスト
$gimmick_test_input = [
    'items' => [
        ['type' => 'token', 'token' => 'gimmick_change'],
        ['type' => 'token', 'token' => 'gimmick_super_change'],
        ['type' => 'free',  'point' => '',   'text' => 'チェンジガード・スーパー化'],
        ['type' => 'free',  'point' => '',   'text' => 'ウォールブレイカー'],
        ['type' => 'free',  'point' => '30', 'text' => 'シールドブレイカー・スーパー化'],
    ]
];
$total_count++;
$gimmick_rows = koto_parse_blessing($gimmick_test_input, $grouped_csv, 'auto_input_blessing');
$g_pass = (
    count($gimmick_rows) === 5
    && ($gimmick_rows[0]['trait_type'] ?? '') === 'gimmick'
    && ($gimmick_rows[0]['gimmick'] ?? []) === [71]
    && ($gimmick_rows[0]['need_point'] ?? '') === '20'
    && ($gimmick_rows[1]['trait_type'] ?? '') === 'gimmick'
    && ($gimmick_rows[1]['gimmick'] ?? []) === [88]
    && ($gimmick_rows[1]['need_point'] ?? '') === '20'
    && ($gimmick_rows[2]['trait_type'] ?? '') === 'gimmick'
    && ($gimmick_rows[2]['gimmick'] ?? []) === [88]
    && ($gimmick_rows[2]['need_point'] ?? '') === '20' // point不明時に20自動セット
    && ($gimmick_rows[3]['trait_type'] ?? '') === 'gimmick'
    && ($gimmick_rows[3]['gimmick'] ?? []) === [15]
    && ($gimmick_rows[3]['need_point'] ?? '') === '20' // ウォールブレイカー & 20自動セット
    && ($gimmick_rows[4]['trait_type'] ?? '') === 'gimmick'
    && ($gimmick_rows[4]['gimmick'] ?? []) === [214]
    && ($gimmick_rows[4]['need_point'] ?? '') === '30' // 明示的30pt優先
);
if ($g_pass) {
    $passed_count++;
    if (!$test_mode) {
        echo sprintf("[PASS] %-18s : %d / %d passed\n", '祝福ギミックトークン', 1, 1);
    }
} else {
    $failed_cases[] = '[祝福ギミックトークン] ギミックトークン・スーパー化・ptフォールバックテストに失敗しました（取得件数: ' . count($gimmick_rows) . '）';
}

if (!$test_mode) {
    echo sprintf("Total: %d / %d passed (詳細なJSON出力は --test-mode を付与)\n", $passed_count, $total_count);
    if (!empty($failed_cases)) {
        echo "Failed cases:\n  " . implode("\n  ", $failed_cases) . "\n";
        exit(1);
    }
}
exit(!empty($failed_cases) ? 1 : 0);
