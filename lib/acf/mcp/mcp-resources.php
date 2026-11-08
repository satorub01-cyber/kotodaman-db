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
        '2. ローカルCLIエージェントの場合：複数枚またはZIPが渡されたら `php lib/acf/mcp/prepare_screenshots.php <入力パス>` で生成した `contact_sheet.jpg` 1枚のみを読み取ってください。',
        '3. 画面に存在しない値や判別不能な値は絶対に推測で埋めず、空欄にするか人間に確認を求めてください。',
        '',
        '【最短・省トークン作成手順（4ステップ）】',
        'Step 1 (スキーマ確認): 不明なフィールド仕様がある場合のみ `get_field_schema` を呼ぶ（フィールド規則と `workflow_guide` が返却されます）。',
        'Step 2 (データ構築・ターム自動解決):',
        '  - `basic_fields` には基本データ（必須: `attribute`, `species`, `rarity`）を入れ、HPとATKの超化（`hp_chouka`, `atk_chouka`）およびLv.99基礎ステータス（`lv_99_hp`, `lv_99_atk`）も含めてください（画面の「+〇〇」表示または超化欄から読み取って設定）。これらは日本語名（例: `"attribute": "水"`, `"species": "神"`, `"rarity": "なし"`, `"affiliation": ["エヴァンゲリオン"]`, `"available_moji_loop": [{"available_moji": "い"}]`）で直接指定でき、サーバー側でタームIDに自動解決されるため、不要な `get_terms` の連発は避けてください（未知のグループ名等を検索する時のみ `get_terms(taxonomy: "affiliation", search: "キーワード")` を使用）。',
        '  - `name_ruby`: キャラ名のひらがな読みのみを指定し、`()` / `（）` 内の文字列は含めない（例: `シンジ(決戦)` → `"しんじ"`）。',
        '  - `rarity`: ★6通常キャラは `"なし"`、特殊枠は `"スペシャル"`, `"レジェンド"`, `"グランド"`。★5以下は `"5"` 等。',
        '  - `status_auto_tf`: Lv.120キャラの場合は `true`。',
        '  - `available_moji_loop`: 初期使用可能文字のみを入れる（とくせい1・2や祝福の追加文字は `auto_input` 側で自動生成されるため含めない）。【最重要注意】ア行（ぁ/ぃ/ぅ/ぇ/ぉ）やヤ行（ゃ/ゅ/ょ）の小文字（捨て仮名・拗音）は大文字（あ/い/う/え/お/や/ゆ/よ）と明確に区別して入力してください（コトダマンDBでは「ゆ」と「ゅ」、「あ」と「ぁ」等は別文字として扱われます）。',
        '  - `auto_input` には、わざ・すごわざ・とくせい・リーダー・祝福・コトワザのゲーム内説明文をそのまま渡す。',
        'Step 3 (`preview_draft` 一括実行): 抽出した `character_name`, `basic_fields`, `auto_input` を一度で `preview_draft` に渡し、`preview_hash` を取得する。',
        'Step 4 (`create_draft` / `update_draft` 最小引数実行): 直前の `preview_draft` 成功後、`create_draft` には `preview_hash` と `idempotency_key` の2項目のみ（`update_draft` は加えて `post_id`）を渡す。サーバー側の30分キャッシュから入力一式が自動復元されるため、`basic_fields` や `auto_input` を重複送信しないでください。',
        '',
        '【エラー時の対応】',
        '- `VALIDATION_FAILED` を受けた場合は、エラー箇所（`missing_fields` / `invalid_fields`）のみを最大3回まで読み直して修正し、3回で解決しない場合は処理を停止して人間に確認を求めてください。',
    ]);
}

function koto_mcp_workflow_guide_data()
{
    return [
        'summary' => 'スクリーンショットからキャラクター下書き（draft）を作成する標準手順とフィールド規則',
        'screenshot_handling' => [
            'external_chat_ai' => 'チャット画面に添付されたスクリーンショット画像（複数枚可）から直接文字・数値を読み取る。画面にない値は推測しない。',
            'local_cli_agent' => 'php lib/acf/mcp/prepare_screenshots.php <zip_or_dir> を実行し、生成された contact_sheet.jpg 1枚のみを read_file で読み取る。',
        ],
        'steps' => [
            '1_extract' => 'スクリーンショットから character_name, basic_fields（必須: attribute, species, rarity）, auto_input（スキル・とくせい文言）を抽出する。',
            '2_term_resolution' => 'attribute, species, rarity, affiliation, available_moji_loop は日本語名（例: "水", "神", "なし", [{"available_moji": "い"}]）で指定可能（サーバー側でタームIDに自動解決）。未知の所属グループ等を確認する場合のみ get_terms(taxonomy, search) を使う。',
            '3_preview' => 'preview_draft に全データを一括送信し、valid: true と preview_hash を確認する。',
            '4_create_or_update' => 'create_draft には preview_hash と idempotency_key のみ（update_draft は加えて post_id）を渡す。30分キャッシュから自動復元されるため basic_fields / auto_input の再送は不要。',
        ],
        'key_field_rules' => [
            'name_ruby' => 'ひらがな読みのみ。カッコ () / （） 内の文字列は含めない（例: シンジ(決戦) -> しんじ）。',
            'rarity' => '★6通常は "なし"、★6特殊は "スペシャル" / "レジェンド" / "グランド"、★5以下は数字（"5" 等）。',
            'status_auto_tf' => 'Lv.120のキャラクターは true に設定する。',
            'hp_chouka / atk_chouka' => 'HPとATKの超化（コエダマ）pt（数値）。スクリーンショットの+〇〇表示または超化欄から読み取って basic_fields に含める。',
            'available_moji_loop' => '初期使用可能文字のみを指定する（例: [{"available_moji": "い"}]）。ア行（ぁ/ぃ/ぅ/ぇ/ぉ）やヤ行（ゃ/ゅ/ょ）の小文字は大文字と明確に区別して入力すること。とくせい・祝福で追加される文字は auto_input から自動生成されるため含めない。',
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
