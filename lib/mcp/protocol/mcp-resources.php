<?php
if (!defined('ABSPATH')) exit;

/**
 * コトダマンDB MCP リソース・プロンプト・ガイド配信モジュール
 *
 * MCP Resources (kotodaman://guide/...), MCP Prompts、
 * 外部AI向けワークフローガイドおよび運用指示テキストの配信を提供します。
 */

function koto_mcp_instructions_text()
{
    return implode("\n", [
        'コトダマンDB キャラクター下書き作成MCPサーバーです。スクリーンショットからキャラクター情報を読み取り、WordPressの下書き（draft）を作成・更新します（自動公開は行いません）。',
        '',
        '【スクリーンショットの読み取り・運用ルール】',
        '1. 外部チャットAI（Claude / Muse / ChatGPT等）の場合：ユーザーがチャットに添付したスクリーンショット画像（複数枚可）から直接情報を読み取ってください。',
        '2. ローカルCLIエージェントの場合：複数枚またはZIPが渡されたら `php lib/mcp/tools/prepare_screenshots.php <入力パス>` で生成した `contact_sheet.jpg` 1枚のみを読み取ってください。',
        '3. 画面に存在しない値や判別不能な値は絶対に推測で埋めず、空欄にするか人間に確認を求めてください。',
        '',
        '【最短・省トークン作成手順】',
        'Step 0 (重複確認): 原則として下書き作成前に `search_character(name: "キャラ名")` で既存キャラ（同名・別バージョン）の有無を確認する。',
        'Step 1 (スキーマ確認): 不明なフィールド仕様がある場合のみ `get_field_schema` を呼ぶ（フィールド規則と `workflow_guide` が返却されます）。',
        'Step 2 (データ構築・ターム自動解決):',
        '  - `basic_fields` には基本データ（必須: `name_ruby`, `attribute`, `species`, `rarity`）を入れ、HPとATKの超化（`hp_chouka`, `atk_chouka`）およびLv.99基礎ステータス（`lv_99_hp`, `lv_99_atk`）も含めてください（画面の「+〇〇」表示または超化欄から読み取って設定）。これらは日本語名（例: `"attribute": "水"`, `"species": "神"`, `"rarity": "なし"`, `"affiliation": ["エヴァンゲリオン"]`, `"available_moji_loop": [{"available_moji": "い"}]`）で直接指定でき、サーバー側でタームIDに自動解決されるため、不要な `get_terms` の連発は避けてください（未知のグループ名等を検索する時のみ `get_terms(taxonomy: "affiliation", search: "キーワード")` を使用）。',
        '  - `name_ruby`【必須】: キャラ名のひらがな読みのみを指定し、`()` / `（）` 内の文字列は含めない（例: `シンジ(決戦)` → `"しんじ"`）。',
        '  - `rarity`【必須】: ★6通常キャラは `"なし"`、特殊枠は `"スペシャル"`, `"レジェンド"`, `"グランド"`。★5以下は `"5"` 等。',
        '  - ★5以下キャラクターのステータス運用ルール: コトダマンの★5以下は最大レベルがLv.90等のため、画面表示の最大レベル時（Lv.90等）の基礎HP・基礎ATKを `lv_99_hp` / `lv_99_atk` に入力してください。また昇華が存在しないため必ず `no_lv120_flag: true` を指定してください（`status_auto_tf: false`、Lv.120ステータスは省略）。',
        '  - `status_auto_tf`: ★6昇華キャラでLv.120ステータスを自動計算する場合は `true`。',
        '  - `event` / `sub_event` / `guerilla_root`: 【ユーザー指定により付与】推測や画像単体で勝手に付与せず、原則としてユーザーからの指定があった場合のみ付与してください（指定がない場合は省略または空配列 `[]`）。付与時は日本語名（例: `["エヴァンゲリオンコラボ第3弾"]`）で指定可能。既存確認は `get_terms(taxonomy: "event", search: "キーワード")` を使用。',
        '  - `available_moji_loop`: 初期使用可能文字のみを入れる（とくせい1・2や祝福の追加文字は `auto_input` 側で自動生成されるため含めない）。【最重要注意】ア行（ぁ/ぃ/ぅ/ぇ/ぉ）やヤ行（ゃ/ゅ/ょ）の小文字（捨て仮名・拗音）は大文字と、濁音・半濁音（「か」と「が」、「は」と「ば」と「ぱ」など）も清音と明確に区別して入力してください（コトダマンDBではそれぞれ独立した別文字タームとして扱われます）。',
        '  - `auto_input` には、わざ・すごわざ・とくせい・リーダー・祝福（`auto_input_blessing` / `auto_input_ex_blessing`）・コトワザのゲーム内説明文を渡す。',
        '  - 【祝福・EX祝福の自動入力ルール（重要: 上限最大8項目）】:',
        '    - `auto_input_blessing`（通常祝福）および `auto_input_ex_blessing`（EX祝福）は、以下の2つの形式で指定できます（最大8項目まで）：',
        '      1. 【新形式（推奨）】トークン・自由入力混在の並び順配列形式（`items`）：',
        '         `{"items": [{"type": "token", "token": "hp_up"}, {"type": "token", "token": "attr_killer", "target": "火"}, {"type": "token", "token": "species_killer", "target": "神"}, {"type": "free", "point": "20", "text": "ドロー時に味方のATKを少し強化"}]}`',
        '         ※利用可能トークン一覧、テンプレート構成、入力スキーマの詳細は `get_blessing_schema` ツール（またはリソース `kotodaman://schema/blessing`）で取得できます。',
        '      2. 【旧形式】テンプレート＋個別行形式：',
        '         `{"template": "pattern_1", "attrs": ["火", "水"], "species": ["神", "魔"], "rows": [{"point": "20", "text": "ドロー時に味方のATKを少し強化"}]}`',
        '    - 【最重要】`free`（旧形式の `rows`）は非レベル制専用です。HP/ATK基礎値UP、各種キラー、属性軽減などのレベル制とくせいは、必ずテンプレートまたはトークンで指定してください（`free` で point 省略時は非レベル制 single/20pt になるため、レベル制を free で入れてはいけません）。',
        '    - `attrs` と `species` はキャラ自身の属性・種族ではなく、**キラー対象の属性・種族（配列形式、2つ以上可）** を指定します。',
        '    - `get_place` に基づくタイプ判定：',
        '      1. 通常のガチャキャラ（`get_place: "gacha"`）：原則としてタイプ1（`template` + `attrs` + `species`）または新形式 `items` を使用します。タイプ1とタイプ2（`rows`：非レベル制の個別祝福とくせい）の両方を持つキャラでユーザーから事前指定がない場合は、**まずタイプ1のみを入力して下書きを作成し、タイプ2の入力有無をユーザーへ確認**してください。',
        '      2. 降臨キャラ等（`get_place: "other"`）：タイプ2（`rows` または新形式 `items`）を使用します。',
        '    - `update_draft` 時の更新・追記の使い分け：既存行を上書き・入れ直す場合は `replace_existing: true`（省略時デフォルト）、既存の祝福行を残して末尾に追記する場合は必ず **`"replace_existing": false`** を指定してください。',
        '    - スクリーンショットの祝福アイコンの並び順と `template` 名の対応関係は `kotodaman://guide/character-draft`（`documents/mcp/character-draft-guide.md`）の対応表を参照してください。',
        '  - 【監査メタデータ・人間確認フラグ】:',
        '    - `evidence`: OCRテキスト抽出箇所の根拠や確信度等を構造化したオブジェクト（最大16KB）。`preview_draft` 時に渡すと `_koto_mcp_audit` に記録されます。',
        '    - `human_approved`: 入手方法など疑問点について人間（ユーザー）の確認・承認を得たことを示すフラグ。確認完了後に `true` を渡してください。',
        'Step 3 (`preview_draft` 一括実行): 抽出した `character_name`, `basic_fields`, `auto_input` を一度で `preview_draft` に渡し、`preview_hash` を取得する（※`validate` はキャッシュせず検証のみを行うツールです。作成フローでは必ず `preview_draft` を使用してください）。',
        'Step 4 (`create_draft` / `update_draft` 最小引数実行): 直前の `preview_draft` 成功後、`create_draft` には `preview_hash` と `idempotency_key` の2項目のみ（`update_draft` は加えて `post_id`）を渡す。サーバー側の30分キャッシュから入力一式が自動復元されるため、`basic_fields` や `auto_input` を重複送信しないでください。',
        '',
        '【test_mode の重要な注意（非ドライラン・デバッグ専用）】',
        '- `test_mode: true` は開発・デバッグ専用のフラグです（通常運用時は常に false または省略）。',
        '- 【警告】`test_mode: true` はドライラン（保存抑止）ではありません！`create_draft` や `update_draft` に指定しても実際に投稿が作成・更新されます。保存を伴わない事前検証には必ず `preview_draft` または `validate` を使用してください。',
        '',
        '【エラー時の対応】',
        '- `VALIDATION_FAILED` を受けた場合は、エラー箇所（`missing_fields` / `invalid_fields`）のみを最大3回まで読み直して修正し、3回で解決しない場合は処理を停止して人間に確認を求めてください。',
    ]);
}

function koto_mcp_workflow_guide_data()
{
    return [
        'summary' => 'スクリーンショットからキャラクター下書き（draft）を作成する標準手順とフィールド規則',
        'test_mode_warning' => 'test_modeはデバッグ専用フラグです。create_draft/update_draftに付けても保存は抑止されません（ドライランではありません）。保存を伴わない検証はpreview_draftまたはvalidateを使用してください。',
        'screenshot_handling' => [
            'external_chat_ai' => 'チャット画面に添付されたスクリーンショット画像（複数枚可）から直接文字・数値を読み取る。画面にない値は推測しない。',
            'local_cli_agent' => 'php lib/mcp/tools/prepare_screenshots.php <zip_or_dir> を実行し、生成された contact_sheet.jpg 1枚のみを read_file で読み取る。',
        ],
        'steps' => [
            '0_search_character' => '下書き作成前に search_character(name) で既存キャラクターの有無（重複）を事前確認する。',
            '1_extract' => 'スクリーンショットから character_name, basic_fields（必須: name_ruby, attribute, species, rarity）, auto_input（スキル・とくせい文言）を抽出する。',
            '2_term_resolution' => 'attribute, species, rarity, affiliation, available_moji_loop は日本語名（例: "水", "神", "なし", [{"available_moji": "い"}]）で指定可能（サーバー側でタームIDに自動解決）。未知の所属グループ等を確認する場合のみ get_terms(taxonomy, search) を使う。event/sub_event/guerilla_rootはユーザー指定により付与する。',
            '3_preview' => 'preview_draft に全データを一括送信し、valid: true と preview_hash を確認する（validate はキャッシュを行わない検証専用）。',
            '4_create_or_update' => 'create_draft には preview_hash と idempotency_key のみ（update_draft は加えて post_id）を渡す。30分キャッシュから自動復元されるため basic_fields / auto_input の再送は不要。',
        ],
        'key_field_rules' => [
            'name_ruby' => '【必須】ひらがな読みのみ。カッコ () / （） 内の文字列は含めない（例: シンジ(決戦) -> しんじ）。',
            'attribute / species / rarity' => '【必須】属性・種族・レアリティ。★6通常は "なし"、特殊枠は "スペシャル" / "レジェンド" / "グランド"、★5以下は数字（"5" 等）。',
            'lv_99_hp / lv_99_atk' => 'Lv.99時点のHP/ATK基礎値。★5以下のキャラクターは画面表示の最大レベル時（Lv.90等）の基礎値をここに入力する（運用ルール）。',
            'status_auto_tf / no_lv120_flag' => '★6昇華キャラでLv.120ステータス自動計算は status_auto_tf: true。★5以下やトークンなど昇華のないキャラは必ず no_lv120_flag: true（status_auto_tf: false）。',
            'hp_chouka / atk_chouka' => 'HPとATKの超化（コエダマ）pt（数値）。スクリーンショットの+〇〇表示または超化欄から読み取って basic_fields に含める。',
            'available_moji_loop' => '初期使用可能文字のみを指定する（例: [{"available_moji": "い"}]）。ア行・ヤ行の小文字は大文字と、濁音・半濁音（か≠が、は≠ば≠ぱ）も清音と明確に区別して入力すること。とくせい・祝福の追加文字は含めない。',
            'event / sub_event / guerilla_root' => '【ユーザー指定により付与】推測で付与せずユーザー指定時のみ付与する（指定なし時は省略または空配列 []）。',
            'auto_input_blessing / auto_input_ex_blessing' => '【上限最大8項目】新形式 {"items": [...]} または旧形式 {"template": "...", "attrs": [...], "species": [...], "rows": [...]}。重要: freeは非レベル制専用（レベル制とくせいに使用不可）。update_draft時の入れ直しは replace_existing: true（デフォルト）、追記は replace_existing: false。',
            'evidence / human_approved' => 'evidence: OCR抽出根拠オブジェクト（16KB上限、preview_draftで渡す）。human_approved: 入手方法など人間確認承認済みフラグ。',
        ],
        'error_recovery' => 'VALIDATION_FAILED 時は該当項目のみ最大3回まで再確認し、解決しない場合は停止して人間に確認を求める。自動公開は行わない。',
    ];
}

function koto_mcp_resources_list()
{
    return [
        [
            'uri' => 'kotodaman://guide/character-draft',
            'name' => 'コトダマンDB キャラクター下書き作成・運用ガイド',
            'description' => 'スクリーンショットの読み取り方法、basic_fields / auto_input の分類、最短4ステップ手順、禁止事項の完全ガイド（Markdown）',
            'mimeType' => 'text/markdown',
        ],
        [
            'uri' => 'kotodaman://guide/field-manual',
            'name' => 'ACFフィールド別読み取りマニュアル',
            'description' => 'スクリーンショット上のどの箇所を根拠に各フィールドを読み取るかの定義一覧（JSON）',
            'mimeType' => 'application/json',
        ],
        [
            'uri' => 'kotodaman://schema/blessing',
            'name' => '祝福・EX祝福スキーマ＆トークン辞書',
            'description' => 'auto_input_blessing / auto_input_ex_blessing の入力スキーマ、利用可能トークン一覧、テンプレート構成一覧（JSON）',
            'mimeType' => 'application/json',
        ],
    ];
}

function koto_mcp_read_resource($uri)
{
    if ($uri === 'kotodaman://guide/character-draft') {
        $guide_path = get_stylesheet_directory() . '/documents/mcp/character-draft-guide.md';
        $text = file_exists($guide_path) ? (string) file_get_contents($guide_path) : koto_mcp_instructions_text();
        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'text/markdown',
                    'text' => $text,
                ],
            ],
        ];
    }
    if ($uri === 'kotodaman://guide/field-manual') {
        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'application/json',
                    'text' => wp_json_encode(koto_mcp_field_manuals(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }
    if ($uri === 'kotodaman://schema/blessing' || $uri === 'koto://blessing-schema') {
        $data = function_exists('koto_mcp_blessing_schema') ? koto_mcp_blessing_schema(true) : [];
        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'application/json',
                    'text' => wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }
    return new WP_Error('RESOURCE_NOT_FOUND', 'Requested resource was not found.', ['status' => 404]);
}

function koto_mcp_prompts_list()
{
    return [
        [
            'name' => 'character_draft_from_screenshots',
            'description' => '添付されたスクリーンショットからコトダマンDBのキャラクター下書きを作成する標準プロンプト',
            'arguments' => [
                [
                    'name' => 'character_name_hint',
                    'description' => 'キャラクター名のヒント（省略可・画像から読み取る場合は不要）',
                    'required' => false,
                ],
            ],
        ],
    ];
}

function koto_mcp_get_prompt($name, $arguments = [])
{
    if ($name !== 'character_draft_from_screenshots') {
        return new WP_Error('PROMPT_NOT_FOUND', 'Requested prompt was not found.', ['status' => 404]);
    }
    $hint = is_array($arguments) && !empty($arguments['character_name_hint'])
        ? "\n対象キャラクター名ヒント: " . sanitize_text_field((string) $arguments['character_name_hint'])
        : '';
    return [
        'description' => 'スクリーンショットからキャラクター下書きを作成する手順指示',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    'type' => 'text',
                    'text' => koto_mcp_instructions_text() . $hint . "\n\n添付されたスクリーンショットから情報を読み取り、`preview_draft` で検証した上で `create_draft` を実行し、作成された下書きの `edit_url` を提示してください。",
                ],
            ],
        ],
    ];
}
