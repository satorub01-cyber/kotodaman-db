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
    if ($type === 'ミラクルリーダーとくせい' || $type === 'ミラクルリーダー') {
        $type = 'リーダーとくせい';
    }
    $phrase = $cli_args[1];
    $input_key = ($type === 'とくせい')
        ? 'auto_input_trait1'
        : (($type === 'EX祝福')
            ? 'auto_input_ex_blessing'
            : (($type === '祝福')
                ? 'auto_input_blessing'
                : (($type === 'リーダーとくせい') ? 'auto_input_leader' : '')));
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
        // 12. 「〇属性〇回攻撃」（「の」なし連撃パターン）
        '敵単体に超絶強力な水属性8回攻撃',
        // 13. 全体連撃 per_hit 倍率割算（{$at_prefix_blast_per_hit} + {$val}回攻撃）
        '敵全体に超強力な火属性の4回攻撃',
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
        // 12. 共鳴クリティカル（後方一致によるcritical_damage誤吸防止）＆グループ対象クリティカル率UP
        '①自身が実体化した文字と同じ文字のコトダマンが実体化している場合、ことば読み上げ完了後にこのターン攻撃する自身と、自身と同じ文字のコトダマンのクリティカル発生率が100%UP・クリティカル時の威力が20%UP※濁音、半濁音、小文字も同じ文字とする②デッキ内の「彩々のひと」のクリティカル発生率が20%UP',
        // 13. ギミック複合とくせい（ブロックブレイカー＋マスガード）
        'ウォールブロックへのダメージをUPし、コピーマスの効果を受けない',
        // 14. 援護（assistance：1ターンにつき1回まで発動する）
        '「援護(重なる彩り)」を所持しているコトダマンが、ドロー候補または準備状態にあり、実体化した「彩々のひと」のコトダマンがすごわざを発動させたとき、ドロー候補または準備状態の「援護(重なる彩り)」を所持しているコトダマンのわざが1ターンにつき1回まで発動する',
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
        // 9. 中黒区切りの複数効果（対象引き継ぎ＋文字含有条件＋ATK上昇）
        '神種族と英種族のHP100%UP·「え」 を含む言葉を作成したときATKが200% UP',
        // 10. ミラクルリーダーとくせい（miracle_leader_loop: 属性付与＋上限突破）
        '①木・天属性HP150%・ATK150%UP・変異耐性100%②木・天属性に「自身ダメージ上限突破(+500000)」を付与する',
        // 11. ミラクルリーダーとくせい（汎用とくせい委譲＋全体／グループ付与）
        '①味方全体に「5文字以上の言葉を作成した時、自身の威力が50%UP」を付与する②「彩々のひと」に「自身ダメージ上限突破(+500000)」を付与する',
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
        // 8. 連続引用「」「」による複数文字列（「こう」「ごう」等）の非分割保持
        '「こう」「ごう」「そう」「とう」「どう」「ほう」を含む6文字以上',
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
    'コトわざ' => [
        // 1. 0凸〜1凸 配列形式（OR発動条件＋複数効果）
        [
            ['condition' => '①4文字以上②10コンボ以上', 'effect' => '敵単体に超絶強力な木属性攻撃'],
            ['condition' => '「し」を含む12コンボ以上', 'effect' => '6文字以上、または15コンボ以上の場合、追加でこのターン自身のクリティカル発生率を上昇＋敵単体に超強力な水属性攻撃'],
        ],
        // 2. JSON文字列ラッパー（{"levels": [...]}）形式
        '{"levels":[{"condition":"①いきもののことば②「あかさ」からはじまる4文字以上","effect":"HPを大きく回復"}]}',
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
    $input_key = ($type === 'とくせい') ? 'auto_input_trait1' : (($type === 'リーダーとくせい') ? 'auto_input_leader' : '');
    foreach ($phrases as $i => $phrase) {
        $num = $i + 1;
        $total_count++;
        $parsed = koto_parse_text_by_type($phrase, $type, $grouped_csv, $input_key);
        $phrase_label = is_array($phrase) ? json_encode($phrase, JSON_UNESCAPED_UNICODE) : (string) $phrase;
        if (!empty($parsed)) {
            $passed_count++;
            $cat_passed++;
        } else {
            $failed_cases[] = "[{$type} #{$num}] {$phrase_label}";
        }
        if ($test_mode) {
            echo "[Test {$num}] {$phrase_label}\n";
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

// グループドローバフ items 複数グループ指定＆未指定警告テスト
$group_draw_multi_input = [
    'items' => [
        ['type' => 'token', 'token' => 'group_draw_buff', 'target' => ['101', '202']],
    ],
];
$total_count++;
$GLOBALS['koto_last_blessing_parse_meta'] = [];
$group_multi_rows = koto_parse_blessing($group_draw_multi_input, $grouped_csv, 'auto_input_blessing', ['affiliations' => ['999']]);
$group_multi_ok = (
    count($group_multi_rows) === 1
    && ($group_multi_rows[0]['target_field_group']['target_type'] ?? '') === 'group'
    && ($group_multi_rows[0]['target_field_group']['target_group'] ?? []) === [101, 202]
);

$group_draw_empty_input = [
    'items' => [
        ['type' => 'token', 'token' => 'group_draw_buff', 'target' => ''],
    ],
];
$total_count++;
$GLOBALS['koto_last_blessing_parse_meta'] = [];
koto_parse_blessing($group_draw_empty_input, $grouped_csv, 'auto_input_blessing');
$group_empty_warnings = function_exists('koto_get_blessing_auto_input_warnings') ? koto_get_blessing_auto_input_warnings() : [];
$group_empty_ok = (strpos(implode("\n", $group_empty_warnings), 'グループドローバフの対象グループ（所属）が未設定です。') !== false);

$group_test_passed = ($group_multi_ok ? 1 : 0) + ($group_empty_ok ? 1 : 0);
$passed_count += $group_test_passed;
if (!$group_multi_ok) {
    $failed_cases[] = '[グループドローバフ複数指定] target=["101","202"] の解決に失敗しました';
}
if (!$group_empty_ok) {
    $failed_cases[] = '[グループドローバフ未指定警告] items形式で所属未設定の警告が出力されませんでした';
}
if (!$test_mode && $group_test_passed === 2) {
    echo sprintf("[PASS] %-18s : %d / %d passed\n", 'グループドローバフ', 2, 2);
}

// コトわざ未解析行の空振り抑止 & 互換形式正規化テスト
$koto_guard_total = 2;
$koto_guard_passed = 0;

$total_count++;
$koto_unparsed_res = koto_parse_kotowaza([
    ['condition' => '完全に未知の条件テキストXYZ', 'effect' => '完全に未知の効果テキストXYZ'],
], $grouped_csv, 'auto_input_kotowaza');
if ($koto_unparsed_res === []) {
    $passed_count++;
    $koto_guard_passed++;
} else {
    $failed_cases[] = '[コトわざガード] 未解析テキストのみで空のダミー行が返却されました';
}

$total_count++;
$koto_map_norm = koto_normalize_kotowaza_input([
    '0凸' => ['condition' => '①4文字以上②10コンボ以上', 'effect' => '敵単体に超絶強力な木属性攻撃'],
    '1凸' => ['condition' => '「し」を含む12コンボ以上', 'effect' => 'HPを大きく回復'],
]);
if (is_array($koto_map_norm) && count($koto_map_norm) === 2 && ($koto_map_norm[0]['condition'] ?? '') === '①4文字以上②10コンボ以上') {
    $passed_count++;
    $koto_guard_passed++;
} else {
    $failed_cases[] = '[コトわざガード] インデックスマップ形式（0凸/1凸）の正規化に失敗しました';
}
if (!$test_mode && $koto_guard_passed === $koto_guard_total) {
    echo sprintf("[PASS] %-18s : %d / %d passed\n", 'コトわざガード', $koto_guard_passed, $koto_guard_total);
}

// 今回追加分の構造検証アサーション（共鳴クリティカル・グループクリティカル・ミラクルリーダー・コトわざ連続引用）
// 回帰構造検証（共鳴クリティカル誤吸防止・グループ対象・ミラクルリーダー・連続引用コトわざ・援護）
$regression_total = 5;
$regression_passed = 0;

$total_count++;
$res_crit_trait = koto_parse_text_by_type(
    '①自身が実体化した文字と同じ文字のコトダマンが実体化している場合、ことば読み上げ完了後にこのターン攻撃する自身と、自身と同じ文字のコトダマンのクリティカル発生率が100%UP・クリティカル時の威力が20%UP※濁音、半濁音、小文字も同じ文字とする',
    'とくせい',
    $grouped_csv,
    'auto_input_trait2'
);
$ok_res_crit = (
    count($res_crit_trait) === 1
    && ($res_crit_trait[0]['trait_type'] ?? '') === 'new_traits'
    && ($res_crit_trait[0]['new_traits'] ?? '') === 'resonance'
    && (int) ($res_crit_trait[0]['resonance_crit_rate'] ?? 0) === 100
    && (int) ($res_crit_trait[0]['resonance_crit_damage'] ?? 0) === 20
);
if ($ok_res_crit) {
    $passed_count++;
    $regression_passed++;
} else {
    $failed_cases[] = '[回帰検証] 共鳴クリティカルが new_traits(resonance) として正しく解析されませんでした';
}

$total_count++;
$grp_crit_trait = koto_parse_text_by_type(
    'デッキ内の「彩々のひと」のクリティカル発生率が20%UP',
    'とくせい',
    $grouped_csv,
    'auto_input_trait2'
);
$ok_grp_crit = (
    count($grp_crit_trait) === 1
    && ($grp_crit_trait[0]['trait_type'] ?? '') === 'status_up'
    && ($grp_crit_trait[0]['status_up'] ?? '') === 'critical_rate'
    && ($grp_crit_trait[0]['target_field_group']['target_type'] ?? '') === 'group'
);
if ($ok_grp_crit) {
    $passed_count++;
    $regression_passed++;
} else {
    $failed_cases[] = '[回帰検証] グループ対象クリティカル発生率UPの target_field_group が解析されませんでした';
}

$total_count++;
$miracle_leader_res = koto_parse_text_by_type(
    '①木・天属性HP150%・ATK150%UP・変異耐性100%②木・天属性に「自身ダメージ上限突破(+500000)」を付与する',
    'リーダーとくせい',
    $grouped_csv,
    'auto_input_leader'
);
$ok_miracle = (
    count($miracle_leader_res) === 2
    && ($miracle_leader_res[1]['_target_repeater'] ?? '') === 'miracle_leader_loop'
    && ($miracle_leader_res[1]['trait_type'] ?? '') === 'damage_correction'
    && ($miracle_leader_res[1]['damage_correction'] ?? '') === 'break_limit'
    && (int) ($miracle_leader_res[1]['limit_break_rate'] ?? 0) === 500000
    && ($miracle_leader_res[1]['whose_trait']['target_type'] ?? '') === 'attr'
);
if ($ok_miracle) {
    $passed_count++;
    $regression_passed++;
} else {
    $failed_cases[] = '[回帰検証] ミラクルリーダーとくせい（miracle_leader_loop）の解析に失敗しました';
}

$total_count++;
$koto_quoted_res = koto_parse_kotowaza([
    ['condition' => '「こう」「ごう」「そう」「とう」「どう」「ほう」を含む6文字以上', 'effect' => '敵単体に超強力な天属性攻撃'],
], $grouped_csv, 'auto_input_kotowaza');
$koto_cond_val = $koto_quoted_res[0]['kotowaza_condition'][0]['sugo_cond_loop'][0]['sugo_cond_val'] ?? '';
$ok_koto_quoted = ($koto_cond_val === 'こう,ごう,そう,とう,どう,ほう');
if ($ok_koto_quoted) {
    $passed_count++;
    $regression_passed++;
} else {
    $failed_cases[] = '[回帰検証] コトわざ連続引用条件が分割されてしまいました（実際: ' . $koto_cond_val . '）';
}

$total_count++;
$assistance_trait = koto_parse_text_by_type(
    '「援護(重なる彩り)」を所持しているコトダマンが、ドロー候補または準備状態にあり、実体化した「彩々のひと」のコトダマンがすごわざを発動させたとき、ドロー候補または準備状態の「援護(重なる彩り)」を所持しているコトダマンのわざが1ターンにつき1回まで発動する',
    'とくせい',
    $grouped_csv,
    'auto_input_trait2'
);
$ok_assistance = (
    count($assistance_trait) === 1
    && ($assistance_trait[0]['trait_type'] ?? '') === 'new_traits'
    && ($assistance_trait[0]['new_traits'] ?? '') === 'assistance'
    && ($assistance_trait[0]['target_field_group']['target_type'] ?? '') === 'group'
    && !empty($assistance_trait[0]['target_field_group']['target_group'])
);
if ($ok_assistance) {
    $passed_count++;
    $regression_passed++;
} else {
    $failed_cases[] = '[回帰検証] 援護（assistance）の解析または target_field_group.target_type が正しくありません';
}

if (!$test_mode && $regression_passed === $regression_total) {
    echo sprintf("[PASS] %-18s : %d / %d passed\n", '回帰構造検証', $regression_passed, $regression_total);
}

// ミラクルリーダーとくせい専用検証（通常リーダー混在・汎用とくせい委譲・全体/グループ/対象なし）
$miracle_total = 3;
$miracle_passed = 0;

$total_count++;
$m_case1 = koto_parse_text_by_type(
    '①木・天属性HP130%・ATK170%UP・睡眠耐性100%②木・天属性に「自身ダメージ上限突破(+500000)」を付与する',
    'リーダーとくせい',
    $grouped_csv,
    'auto_input_leader'
);
$ok_m1 = (
    count($m_case1) === 2
    && isset($m_case1[0]['field_692e408c77f14'])
    && ($m_case1[1]['_target_repeater'] ?? '') === 'miracle_leader_loop'
    && ($m_case1[1]['trait_type'] ?? '') === 'damage_correction'
    && (int) ($m_case1[1]['limit_break_rate'] ?? 0) === 500000
    && ($m_case1[1]['whose_trait']['target_type'] ?? '') === 'attr'
);
if ($ok_m1) {
    $passed_count++;
    $miracle_passed++;
} else {
    $failed_cases[] = '[ミラクルリーダーとくせい #1] 属性付与＋上限突破のパースに失敗しました';
}

$total_count++;
$m_case2 = koto_parse_text_by_type(
    '①味方全体に「5文字以上の言葉を作成した時、自身の威力が50%UP」を付与する②「彩々のひと」に「自身ダメージ上限突破(+500000)」を付与する',
    'リーダーとくせい',
    $grouped_csv,
    'auto_input_leader'
);
$ok_m2 = (
    count($m_case2) === 2
    && ($m_case2[0]['_target_repeater'] ?? '') === 'miracle_leader_loop'
    && ($m_case2[0]['trait_type'] ?? '') === 'damage_correction'
    && ($m_case2[0]['damage_correction'] ?? '') === 'oneself'
    && (int) ($m_case2[0]['trait_rate'] ?? 0) === 50
    && ($m_case2[0]['condition_type_loop'][0]['condition_type'] ?? '') === 'moji_count'
    && ($m_case2[0]['whose_trait']['target_type'] ?? '') === 'all'
    && ($m_case2[1]['_target_repeater'] ?? '') === 'miracle_leader_loop'
    && ($m_case2[1]['whose_trait']['target_type'] ?? '') === 'group'
);
if ($ok_m2) {
    $passed_count++;
    $miracle_passed++;
} else {
    $failed_cases[] = '[ミラクルリーダーとくせい #2] 汎用とくせい委譲・全体/グループ付与のパースに失敗しました';
}

$total_count++;
$m_case3 = koto_parse_text_by_type(
    '「自身ダメージ上限突破(+300000)」を付与する',
    'リーダーとくせい',
    $grouped_csv,
    'auto_input_leader'
);
$ok_m3 = (
    count($m_case3) === 1
    && ($m_case3[0]['_target_repeater'] ?? '') === 'miracle_leader_loop'
    && ($m_case3[0]['trait_type'] ?? '') === 'damage_correction'
    && (int) ($m_case3[0]['limit_break_rate'] ?? 0) === 300000
);
if ($ok_m3) {
    $passed_count++;
    $miracle_passed++;
} else {
    $failed_cases[] = '[ミラクルリーダーとくせい #3] 対象なし付与のパースに失敗しました';
}

if (!$test_mode && $miracle_passed === $miracle_total) {
    echo sprintf("[PASS] %-18s : %d / %d passed\n", 'ミラクルリーダーとくせい', $miracle_passed, $miracle_total);
}

// ギミック複合とくせい検証（多様な連結・並列・既存保護ケース）
$compound_gimmick_total = 10;
$compound_gimmick_passed = 0;

// 1. 受け入れ例: ウォールブロックへのダメージをUPし、コピーマスの効果を受けない
$total_count++;
$cg_clauses_1 = koto_split_compound_gimmick_clauses('ウォールブロックへのダメージをUPし、コピーマスの効果を受けない');
$cg_res_1 = koto_parse_text_by_type(
    'ウォールブロックへのダメージをUPし、コピーマスの効果を受けない',
    'とくせい',
    $grouped_csv,
    'auto_input_trait1'
);
$ok_cg_1 = (
    $cg_clauses_1 === ['ウォールブロックへのダメージをUP', 'コピーマスの効果を受けない']
    && count($cg_res_1) === 2
    && ($cg_res_1[0]['trait_type'] ?? '') === 'gimmick'
    && ($cg_res_1[1]['trait_type'] ?? '') === 'gimmick'
    && !empty($cg_res_1[0]['gimmick'])
    && !empty($cg_res_1[1]['gimmick'])
);
if ($ok_cg_1) {
    $passed_count++;
    $compound_gimmick_passed++;
} else {
    $failed_cases[] = '[ギミック複合 #1] ウォール＋コピーの2行分解に失敗しました';
}

// 2. スーパーブレイカー＋マスガード（接頭辞「を大きくUP」保持）
$total_count++;
$cg_clauses_2 = koto_split_compound_gimmick_clauses('ウォールブロックへのダメージを大きくUPし、コピーマスの効果を受けない');
$cg_res_2 = koto_parse_text_by_type(
    'ウォールブロックへのダメージを大きくUPし、コピーマスの効果を受けない',
    'とくせい',
    $grouped_csv,
    'auto_input_trait1'
);
$ok_cg_2 = (
    $cg_clauses_2 === ['ウォールブロックへのダメージを大きくUP', 'コピーマスの効果を受けない']
    && count($cg_res_2) === 2
    && ($cg_res_2[0]['trait_type'] ?? '') === 'gimmick'
    && ($cg_res_2[1]['trait_type'] ?? '') === 'gimmick'
);
if ($ok_cg_2) {
    $passed_count++;
    $compound_gimmick_passed++;
} else {
    $failed_cases[] = '[ギミック複合 #2] スーパーウォール＋コピーの分解に失敗しました';
}

// 3. マスガード先頭（「ず、」）＋ブロック軽減
$total_count++;
$cg_clauses_3 = koto_split_compound_gimmick_clauses('チェンジマスの効果を受けず、トゲブロックから受けるダメージを軽減');
$cg_res_3 = koto_parse_text_by_type(
    'チェンジマスの効果を受けず、トゲブロックから受けるダメージを軽減',
    'とくせい',
    $grouped_csv,
    'auto_input_trait1'
);
$ok_cg_3 = (
    $cg_clauses_3 === ['チェンジマスの効果を受けない', 'トゲブロックから受けるダメージを軽減']
    && count($cg_res_3) === 2
    && ($cg_res_3[0]['trait_type'] ?? '') === 'gimmick'
    && ($cg_res_3[1]['trait_type'] ?? '') === 'gimmick'
);
if ($ok_cg_3) {
    $passed_count++;
    $compound_gimmick_passed++;
} else {
    $failed_cases[] = '[ギミック複合 #3] チェンジ(受けず)＋トゲの分解に失敗しました';
}

// 4. レーザーガード（防ぎ＋遮断）＋バルーンマス
$total_count++;
$cg_clauses_4 = koto_split_compound_gimmick_clauses('レーザーブロックからのダメージを防ぎ、レーザーを遮断し、バルーンマスの効果を受けない');
$cg_res_4 = koto_parse_text_by_type(
    'レーザーブロックからのダメージを防ぎ、レーザーを遮断し、バルーンマスの効果を受けない',
    'とくせい',
    $grouped_csv,
    'auto_input_trait1'
);
$ok_cg_4 = (
    $cg_clauses_4 === ['レーザーブロックからのダメージを防ぎ、レーザーを遮断する', 'バルーンマスの効果を受けない']
    && count($cg_res_4) === 2
    && ($cg_res_4[0]['trait_type'] ?? '') === 'gimmick'
    && ($cg_res_4[1]['trait_type'] ?? '') === 'gimmick'
);
if ($ok_cg_4) {
    $passed_count++;
    $compound_gimmick_passed++;
} else {
    $failed_cases[] = '[ギミック複合 #4] レーザー(遮断)＋バルーンの分解に失敗しました';
}

// 5. ブレイカー＋レーザーガード（軽減＋受け止め）
$total_count++;
$cg_clauses_5 = koto_split_compound_gimmick_clauses('ウォールブロックへのダメージをUPし、レーザーブロックから受けるダメージを軽減し、レーザーを受け止める');
$cg_res_5 = koto_parse_text_by_type(
    'ウォールブロックへのダメージをUPし、レーザーブロックから受けるダメージを軽減し、レーザーを受け止める',
    'とくせい',
    $grouped_csv,
    'auto_input_trait1'
);
$ok_cg_5 = (
    $cg_clauses_5 === ['ウォールブロックへのダメージをUP', 'レーザーブロックから受けるダメージを軽減し、レーザーを受け止める']
    && count($cg_res_5) === 2
    && ($cg_res_5[0]['trait_type'] ?? '') === 'gimmick'
    && ($cg_res_5[1]['trait_type'] ?? '') === 'gimmick'
);
if ($ok_cg_5) {
    $passed_count++;
    $compound_gimmick_passed++;
} else {
    $failed_cases[] = '[ギミック複合 #5] ウォール＋レーザー(受け止め)の分解に失敗しました';
}

// 6. スーパートゲ＋マスガード
$total_count++;
$cg_clauses_6 = koto_split_compound_gimmick_clauses('トゲブロックから受けるダメージを軽減し、軽減した時に自身のATKを上昇し、コピーマスの効果を受けない');
$cg_res_6 = koto_parse_text_by_type(
    'トゲブロックから受けるダメージを軽減し、軽減した時に自身のATKを上昇し、コピーマスの効果を受けない',
    'とくせい',
    $grouped_csv,
    'auto_input_trait1'
);
$ok_cg_6 = (
    $cg_clauses_6 === ['トゲブロックから受けるダメージを軽減し、軽減した時に自身のATKを上昇する', 'コピーマスの効果を受けない']
    && count($cg_res_6) === 2
    && (int) ($cg_res_6[0]['gimmick'] ?? 0) === 132
    && ($cg_res_6[1]['trait_type'] ?? '') === 'gimmick'
);
if ($ok_cg_6) {
    $passed_count++;
    $compound_gimmick_passed++;
} else {
    $failed_cases[] = '[ギミック複合 #6] スーパートゲ＋コピーの分解に失敗しました';
}

// 7. 並列（共有述語）形式: ウォール・シールドブロック / チェンジ・コピーマス
$total_count++;
$cg_clauses_7a = koto_split_compound_gimmick_clauses('ウォール・シールドブロックへのダメージをUP');
$cg_clauses_7b = koto_split_compound_gimmick_clauses('チェンジ・コピーマスの効果を受けない');
$cg_res_7a = koto_parse_text_by_type('ウォール・シールドブロックへのダメージをUP', 'とくせい', $grouped_csv, 'auto_input_trait1');
$cg_res_7b = koto_parse_text_by_type('チェンジ・コピーマスの効果を受けない', 'とくせい', $grouped_csv, 'auto_input_trait1');
$ok_cg_7 = (
    $cg_clauses_7a === ['ウォールブロックへのダメージをUP', 'シールドブロックへのダメージをUP']
    && $cg_clauses_7b === ['チェンジマスの効果を受けない', 'コピーマスの効果を受けない']
    && count($cg_res_7a) === 2
    && count($cg_res_7b) === 2
);
if ($ok_cg_7) {
    $passed_count++;
    $compound_gimmick_passed++;
} else {
    $failed_cases[] = '[ギミック複合 #7] 並列（共有述語）ギミックの展開に失敗しました';
}

// 8. 条件付き複合ギミック（両行へ条件が引き継がれること）
$total_count++;
$cg_res_8 = koto_parse_text_by_type(
    'HPが80%以上の時、ウォールブロックへのダメージをUPし、コピーマスの効果を受けない',
    'とくせい',
    $grouped_csv,
    'auto_input_trait1'
);
$ok_cg_8 = (
    count($cg_res_8) === 2
    && ($cg_res_8[0]['condition_type_loop'][0]['condition_type'] ?? '') === 'hpcond'
    && ($cg_res_8[1]['condition_type_loop'][0]['condition_type'] ?? '') === 'hpcond'
);
if ($ok_cg_8) {
    $passed_count++;
    $compound_gimmick_passed++;
} else {
    $failed_cases[] = '[ギミック複合 #8] 条件付き複合ギミックの条件引き継ぎに失敗しました';
}

// 9. 既存保護: 単一ギミック（スーパートゲ・レーザー）が誤分割されないこと
$total_count++;
$cg_single_1 = koto_split_compound_gimmick_clauses('トゲブロックから受けるダメージを軽減し、軽減した時に自身のATKを上昇する');
$cg_single_2 = koto_split_compound_gimmick_clauses('レーザーブロックから受けるダメージを軽減し、レーザーを受け止める');
$cg_res_single_1 = koto_parse_text_by_type('トゲブロックから受けるダメージを軽減し、軽減した時に自身のATKを上昇する', 'とくせい', $grouped_csv, 'auto_input_trait1');
$cg_res_single_2 = koto_parse_text_by_type('レーザーブロックから受けるダメージを軽減し、レーザーを受け止める', 'とくせい', $grouped_csv, 'auto_input_trait1');
$ok_cg_9 = (
    $cg_single_1 === []
    && $cg_single_2 === []
    && count($cg_res_single_1) === 1
    && (int) ($cg_res_single_1[0]['gimmick'] ?? 0) === 132
    && count($cg_res_single_2) === 1
    && ($cg_res_single_2[0]['trait_type'] ?? '') === 'gimmick'
);
if ($ok_cg_9) {
    $passed_count++;
    $compound_gimmick_passed++;
} else {
    $failed_cases[] = '[ギミック複合 #9] 単一ギミック保護に回帰が発生しました';
}

// 10. 既存保護: もともと複数行に解析されるスーパーチェンジ（文字追加複合）の結果が変わらないこと
$total_count++;
$cg_super_change = koto_split_compound_gimmick_clauses('チェンジマスの効果を受けない。さらにチェンジマスがある場合、文字変換に「あ・い」が追加される');
$cg_res_sc = koto_parse_text_by_type(
    'チェンジマスの効果を受けない。さらにチェンジマスがある場合、文字変換に「あ・い」が追加される',
    'とくせい',
    $grouped_csv,
    'auto_input_trait1'
);
$ok_cg_10 = (
    $cg_super_change === []
    && count($cg_res_sc) === 2
    && ($cg_res_sc[0]['trait_type'] ?? '') === 'gimmick'
    && isset($cg_res_sc[1]['available_moji'])
    && ($cg_res_sc[1]['unlock_place'] ?? '') === 'super_change'
);
if ($ok_cg_10) {
    $passed_count++;
    $compound_gimmick_passed++;
} else {
    $failed_cases[] = '[ギミック複合 #10] スーパーチェンジ文字追加複合に回帰が発生しました';
}

if (!$test_mode && $compound_gimmick_passed === $compound_gimmick_total) {
    echo sprintf("[PASS] %-18s : %d / %d passed\n", 'ギミック複合とくせい', $compound_gimmick_passed, $compound_gimmick_total);
}

// =========================================================================
// すごわざトークン生成・とくせい/祝福/EX祝福モードシフト・変身テスト
// =========================================================================
$rel_passed = 0;
$rel_total = 6;

// 1. すごわざトークン生成（テキスト記法：攻撃＋トークン生成、未存在キャラ名時は骨格生成＋警告）
$total_count++;
$GLOBALS['koto_last_auto_input_warnings'] = [];
$GLOBALS['koto_last_auto_input_errors'] = [];
$tok_res_1 = koto_parse_text_by_type(
    '敵単体に超絶強力な光属性攻撃+トークンコトダマン「未存在テストトークン」を生成し、ドロー候補に加わる',
    'わざ',
    $grouped_csv,
    'auto_input_sugowaza'
);
$ok_rel_1 = (
    count($tok_res_1) === 1
    && count($tok_res_1[0]['sugo_detail_loop'] ?? []) === 2
    && ($tok_res_1[0]['sugo_detail_loop'][0]['waza_type'] ?? '') === 'attack'
    && ($tok_res_1[0]['sugo_detail_loop'][1]['waza_type'] ?? '') === 'token'
    && !empty($GLOBALS['koto_last_auto_input_warnings']['auto_input_sugowaza'])
);
if ($ok_rel_1) {
    $passed_count++;
    $rel_passed++;
} else {
    $failed_cases[] = '[トークン/モードシフト #1] すごわざトークン生成（テキスト記法）の解析に失敗しました';
}

// 2. すごわざトークン生成（構造化入力：シフト2グループへそれぞれトークン付与）
$total_count++;
$tok_res_2 = koto_parse_text_by_type(
    [
        'text'   => '自身の文字に応じて効果が変化する①「あ」の時、敵単体に爆絶強力な光属性攻撃②「い」の時、敵単体に爆絶強力な光属性攻撃',
        'tokens' => [
            ['related_token' => ['未存在トークンA'], 'group_index' => 0],
            ['related_token' => ['未存在トークンB'], 'group_index' => 1],
        ],
    ],
    'わざ',
    $grouped_csv,
    'auto_input_sugowaza'
);
$ok_rel_2 = (
    count($tok_res_2) >= 2
    && ($tok_res_2[0]['sugo_detail_loop'][1]['waza_type'] ?? '') === 'token'
    && ($tok_res_2[1]['sugo_detail_loop'][1]['waza_type'] ?? '') === 'token'
);
if ($ok_rel_2) {
    $passed_count++;
    $rel_passed++;
} else {
    $failed_cases[] = '[トークン/モードシフト #2] すごわざトークン生成（構造化シフト入力）の解析に失敗しました';
}

// 3. とくせい：モードシフト（1形態・2形態） / 変身前（テキスト記法）
$total_count++;
$ms_res_1 = koto_parse_text_by_type('詳細画面で「テスト形態B」に切り替えてデッキに編成できる', 'とくせい', $grouped_csv, 'auto_input_trait2');
$ms_res_2 = koto_parse_text_by_type('詳細画面で「テスト形態B」と「テスト形態C」に切り替えてデッキに編成できる', 'とくせい', $grouped_csv, 'auto_input_trait2');
$ms_res_3 = koto_parse_text_by_type('自身がすごわざを発動時、「テスト変身後」に変身する', 'とくせい', $grouped_csv, 'auto_input_trait2');
$ok_rel_3 = (
    ($ms_res_1[0]['trait_type'] ?? '') === 'mode_shift' && ($ms_res_1[0]['relation_ship'] ?? '') === 'mode_shift'
    && ($ms_res_2[0]['trait_type'] ?? '') === 'mode_shift' && ($ms_res_2[0]['relation_ship'] ?? '') === 'mode_shift'
    && ($ms_res_3[0]['trait_type'] ?? '') === 'mode_shift' && ($ms_res_3[0]['relation_ship'] ?? '') === 'before_transform'
);
if ($ok_rel_3) {
    $passed_count++;
    $rel_passed++;
} else {
    $failed_cases[] = '[トークン/モードシフト #3] とくせいモードシフト・変身前（テキスト記法）の解析に失敗しました';
}

// 4. とくせい：構造化入力（テキスト＋変身前＋条件）
$total_count++;
$ms_res_4 = koto_parse_text_by_type(
    [
        'text'       => 'チェンジマスの効果を受けない',
        'mode_shift' => [
            'relation_ship' => 'before_transform',
            'related_form'  => ['self'],
            'condition'     => '5文字以上の言葉を作成した時、',
        ],
    ],
    'とくせい',
    $grouped_csv,
    'auto_input_trait1'
);
$ok_rel_4 = (
    count($ms_res_4) === 2
    && ($ms_res_4[0]['trait_type'] ?? '') === 'gimmick'
    && ($ms_res_4[1]['trait_type'] ?? '') === 'mode_shift'
    && ($ms_res_4[1]['relation_ship'] ?? '') === 'before_transform'
    && ($ms_res_4[1]['related_form'] ?? []) === ['self']
    && ($ms_res_4[1]['condition_type_loop'][0]['condition_type'] ?? '') === 'moji_count'
);
if ($ok_rel_4) {
    $passed_count++;
    $rel_passed++;
} else {
    $failed_cases[] = '[トークン/モードシフト #4] とくせい構造化モードシフト・変身の解析に失敗しました';
}

// 5. 祝福：items 配列での type=mode_shift（絵違いモードシフト another_image）
$total_count++;
$bl_ms_res = koto_parse_text_by_type(
    [
        'items' => [
            ['type' => 'token', 'token' => 'hp_up'],
            ['type' => 'mode_shift', 'point' => 20, 'relation_ship' => 'another_image', 'related_form' => ['self']],
        ],
    ],
    '祝福',
    $grouped_csv,
    'auto_input_blessing'
);
$ok_rel_5 = (
    count($bl_ms_res) === 2
    && ($bl_ms_res[1]['trait_type'] ?? '') === 'mode_shift'
    && ($bl_ms_res[1]['relation_ship'] ?? '') === 'another_image'
    && ($bl_ms_res[1]['related_form'] ?? []) === ['self']
    && ($bl_ms_res[1]['pt_pattern'] ?? '') === 'single'
    && (string) ($bl_ms_res[1]['need_point'] ?? '') === '20'
);
if ($ok_rel_5) {
    $passed_count++;
    $rel_passed++;
} else {
    $failed_cases[] = '[トークン/モードシフト #5] 祝福 items の mode_shift（another_image）解析に失敗しました';
}

// 6. EX祝福：構造化 items による絵違いモードシフト（ユーザー明示指定想定）解析
$total_count++;
$ex_ms_res = koto_parse_text_by_type(
    [
        'items' => [
            ['type' => 'mode_shift', 'point' => 30, 'relation_ship' => 'another_image', 'related_form' => []],
        ],
    ],
    'EX祝福',
    $grouped_csv,
    'auto_input_ex_blessing'
);
$ok_rel_6 = (
    count($ex_ms_res) === 1
    && ($ex_ms_res[0]['trait_type'] ?? '') === 'mode_shift'
    && ($ex_ms_res[0]['relation_ship'] ?? '') === 'another_image'
    && (string) ($ex_ms_res[0]['need_point'] ?? '') === '30'
);
if ($ok_rel_6) {
    $passed_count++;
    $rel_passed++;
} else {
    $failed_cases[] = '[トークン/モードシフト #6] EX祝福 items の絵違いモードシフト解析に失敗しました';
}

if (!$test_mode && $rel_passed === $rel_total) {
    echo sprintf("[PASS] %-18s : %d / %d passed\n", 'トークン・モードシフト', $rel_passed, $rel_total);
}

// わざ：at_prefix_*_per_hit の1ヒットあたり倍率計算テスト
$per_hit_cases = [
    [
        'label'    => '全体超強力4回攻撃 (3.75 / 4 = 0.9375)',
        'phrase'   => '敵全体に超強力な火属性の4回攻撃',
        'expected' => 0.9375,
        'hits'     => 4,
    ],
    [
        'label'    => '全体爆絶強力全属性有利3回攻撃 (7.5 / 3 = 2.5)',
        'phrase'   => '敵全体に爆絶強力な全属性有利となる水属性3回攻撃',
        'expected' => 2.5,
        'hits'     => 3,
    ],
    [
        'label'    => '全体超絶強力7回攻撃 (5.1 / 7 = 0.7286)',
        'phrase'   => '敵全体に超絶強力な木属性の7回攻撃',
        'expected' => 0.7286,
        'hits'     => 7,
    ],
];
$per_hit_passed = 0;
foreach ($per_hit_cases as $ph_case) {
    $total_count++;
    $ph_res = koto_parse_text_by_type($ph_case['phrase'], 'わざ', $grouped_csv, '');
    $detail = $ph_res[0]['sugo_detail_loop'][0] ?? [];
    $actual_val = isset($detail['waza_value']) ? (float) $detail['waza_value'] : null;
    $actual_hits = isset($detail['hit_count']) ? (int) $detail['hit_count'] : null;
    if ($actual_val !== null && abs($actual_val - $ph_case['expected']) < 0.0001 && $actual_hits === $ph_case['hits']) {
        $passed_count++;
        $per_hit_passed++;
    } else {
        $failed_cases[] = sprintf(
            '[わざ per_hit] %s (期待: waza_value=%s, hit_count=%d / 実際: waza_value=%s, hit_count=%s)',
            $ph_case['label'],
            (string) $ph_case['expected'],
            $ph_case['hits'],
            var_export($actual_val, true),
            var_export($actual_hits, true)
        );
    }
}
if (!$test_mode && $per_hit_passed === count($per_hit_cases)) {
    echo sprintf("[PASS] %-18s : %d / %d passed\n", 'わざ per_hit倍率', $per_hit_passed, count($per_hit_cases));
}

// リーダーとくせい：ダメージ軽減（mitigation_prefix の途中停止防止 & の/を/数値%表記対応）テスト
$leader_mitigation_cases = [
    [
        'label'    => 'デッキ内全員の被ダメージ軽減 (25)',
        'phrase'   => 'デッキ内全員の被ダメージ軽減',
        'expected' => 25,
        'index'    => 0,
    ],
    [
        'label'    => 'デッキ内全員の被ダメージを大きく軽減 (50)',
        'phrase'   => 'デッキ内全員の被ダメージを大きく軽減',
        'expected' => 50,
        'index'    => 0,
    ],
    [
        'label'    => '火属性の被ダメージ軽減 (25)',
        'phrase'   => '火属性の被ダメージ軽減',
        'expected' => 25,
        'index'    => 0,
    ],
    [
        'label'    => '火属性のHP100%・ATK200%・被ダメージ軽減 (25)',
        'phrase'   => '火属性のHP100%・ATK200%・被ダメージ軽減',
        'expected' => 25,
        'index'    => 2,
    ],
    [
        'label'    => 'デッキ内全員が受けるダメージを30%軽減 (30)',
        'phrase'   => 'デッキ内全員が受けるダメージを30%軽減',
        'expected' => 30,
        'index'    => 0,
    ],
];
$leader_mitigation_passed = 0;
foreach ($leader_mitigation_cases as $lm_case) {
    $total_count++;
    $lm_res = koto_parse_text_by_type($lm_case['phrase'], 'リーダーとくせい', $grouped_csv, 'auto_input_leader');
    $status_row = $lm_res[0]['field_692e3f0bd345b'][$lm_case['index']] ?? [];
    $actual_status = $status_row['field_692e3f3dd345c'] ?? null;
    $actual_rate = $status_row['field_692e3fa6d345d'] ?? null;
    if ($actual_status === 'mitigation' && $actual_rate === $lm_case['expected']) {
        $passed_count++;
        $leader_mitigation_passed++;
    } else {
        $failed_cases[] = sprintf(
            '[リーダー軽減] %s (期待: status=mitigation, rate=%d / 実際: status=%s, rate=%s)',
            $lm_case['label'],
            $lm_case['expected'],
            var_export($actual_status, true),
            var_export($actual_rate, true)
        );
    }
}
if (!$test_mode && $leader_mitigation_passed === count($leader_mitigation_cases)) {
    echo sprintf("[PASS] %-18s : %d / %d passed\n", 'リーダー軽減', $leader_mitigation_passed, count($leader_mitigation_cases));
}

if (!$test_mode) {
    echo sprintf("Total: %d / %d passed (詳細なJSON出力は --test-mode を付与)\n", $passed_count, $total_count);
    if (!empty($failed_cases)) {
        echo "Failed cases:\n  " . implode("\n  ", $failed_cases) . "\n";
        exit(1);
    }
}
exit(!empty($failed_cases) ? 1 : 0);
