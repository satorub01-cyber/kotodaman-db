<?php
/**
 * コトダマンDB MCP 関連キャラ機能（same_character / すごわざトークン / モードシフト・変身）統合テスト
 *
 * 実行方法:
 *   php lib/mcp/tests/test-mcp-relations.php
 */

if (php_sapi_name() !== 'cli') {
    exit("CLI only.\n");
}

$wp_load = '/var/www/html/wp-load.php';
if (!file_exists($wp_load)) {
    $wp_load = dirname(__DIR__, 6) . '/wp-load.php';
}
require_once $wp_load;
require_once ABSPATH . 'wp-admin/includes/user.php';

$pass_count = 0;
$fail_count = 0;

function koto_rel_assert($condition, $label)
{
    global $pass_count, $fail_count;
    if ($condition) {
        $pass_count++;
        echo "  [PASS] {$label}\n";
    } else {
        $fail_count++;
        echo "  [FAIL] {$label}\n";
    }
}

$cleanup_users = [];
$cleanup_posts = [];
$cleanup_clients = [];

register_shutdown_function(function () use (&$cleanup_users, &$cleanup_posts, &$cleanup_clients) {
    wp_set_current_user(0);
    unset($_SERVER['HTTP_AUTHORIZATION']);
    foreach ($cleanup_posts as $pid) {
        wp_delete_post((int) $pid, true);
    }
    foreach ($cleanup_clients as $cid) {
        koto_mcp_delete_static_oauth_client($cid);
        koto_mcp_storage_delete('koto_mcp_client_' . $cid);
        delete_transient('koto_mcp_client_' . $cid);
    }
    foreach ($cleanup_users as $uid) {
        koto_mcp_revoke_user_tokens((int) $uid);
        wp_delete_user((int) $uid);
    }
});

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$user_id = wp_insert_user([
    'user_login' => 'koto_rel_tester_' . $suffix,
    'user_pass'  => wp_generate_password(20),
    'user_email' => 'koto_rel_tester_' . $suffix . '@example.test',
    'role'       => 'subscriber',
]);
if (is_wp_error($user_id)) {
    exit("テストユーザー作成失敗\n");
}
$cleanup_users[] = $user_id;
update_user_meta($user_id, '_koto_mcp_approved', '1');

$client = koto_mcp_create_static_oauth_client(
    'Relations Test Client ' . $suffix,
    ['https://example.com/callback'],
    true,
    'validate preview create'
);
$cid = (string) ($client['client_id'] ?? '');
$cleanup_clients[] = $cid;

$tok_res = koto_mcp_issue_token($user_id, 'validate preview create', $cid, null, koto_mcp_server_url());
$token = ($tok_res instanceof WP_REST_Response) ? (string) ($tok_res->get_data()['access_token'] ?? '') : '';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

$mk_req = static function () use ($token) {
    $r = new WP_REST_Request('POST', '/kotodaman/v1/mcp');
    $r->set_header('Authorization', 'Bearer ' . $token);
    return $r;
};

echo "========================================================\n";
echo "MCP same_character / トークン / モードシフト・変身 統合テスト\n";
echo "========================================================\n\n";

// ------------------------------------------------------------
// シナリオ 1: A・B・C の作成と create_draft 時の "self" 解決
// ------------------------------------------------------------
echo "=== シナリオ 1: A・B・C 作成 & create_draft same_character \"self\" 解決 ===\n";

$base_fields = [
    'name_ruby' => 'てすとえー',
    'attribute' => '火',
    'species'   => '神',
    'rarity'    => 'なし',
];

$prev_a = koto_mcp_call_tool('preview_draft', [
    'character_name' => 'MCPテスト形態A_' . $suffix,
    'basic_fields'   => $base_fields,
], $mk_req());
koto_rel_assert(!is_wp_error($prev_a) && !empty($prev_a['preview_hash']), 'キャラAの preview_draft が成功する');

$create_a = koto_mcp_call_tool('create_draft', [
    'preview_hash'    => $prev_a['preview_hash'],
    'idempotency_key' => 'rel-a-' . $suffix,
], $mk_req());
$id_a = is_array($create_a) ? (int) ($create_a['post_id'] ?? 0) : 0;
if ($id_a > 0) $cleanup_posts[] = $id_a;
koto_rel_assert($id_a > 0, 'キャラAの下書きが作成される (ID: ' . $id_a . ')');

// キャラBを same_character: [A_id, "self"] で作成
$prev_b = koto_mcp_call_tool('preview_draft', [
    'character_name' => 'MCPテスト形態B_' . $suffix,
    'basic_fields'   => array_merge($base_fields, [
        'name_ruby'      => 'てすとびー',
        'same_character' => [$id_a, 'self'],
    ]),
], $mk_req());
koto_rel_assert(!is_wp_error($prev_b) && !empty($prev_b['preview_hash']), 'キャラBの same_character: [A, "self"] の preview_draft が成功する');
koto_rel_assert(!empty($prev_b['warnings']), 'キャラAの same_character 未設定リマインド警告が出る');

$create_b = koto_mcp_call_tool('create_draft', [
    'preview_hash'    => $prev_b['preview_hash'],
    'idempotency_key' => 'rel-b-' . $suffix,
], $mk_req());
$id_b = is_array($create_b) ? (int) ($create_b['post_id'] ?? 0) : 0;
if ($id_b > 0) $cleanup_posts[] = $id_b;
koto_rel_assert($id_b > 0, 'キャラBの下書きが作成される (ID: ' . $id_b . ')');

$draft_b_init = koto_mcp_call_tool('get_draft', ['post_id' => $id_b], $mk_req());
koto_rel_assert(($draft_b_init['basic_fields']['same_character'] ?? []) === [$id_a, $id_b], 'キャラB作成時に "self" が自身のIDへ置換され [A, B] 順で保存される');

$draft_a_untouched = koto_mcp_call_tool('get_draft', ['post_id' => $id_a], $mk_req());
koto_rel_assert(($draft_a_untouched['basic_fields']['same_character'] ?? null) === [], 'キャラB保存時にキャラAの same_character は自動書き換えされない');

// トークンCを作成
$prev_c = koto_mcp_call_tool('preview_draft', [
    'character_name' => 'MCPテストトークンC_' . $suffix,
    'basic_fields'   => array_merge($base_fields, [
        'name_ruby'      => 'てすとしー',
        'no_lv120_flag'  => true,
    ]),
], $mk_req());
$create_c = koto_mcp_call_tool('create_draft', [
    'preview_hash'    => $prev_c['preview_hash'],
    'idempotency_key' => 'rel-c-' . $suffix,
], $mk_req());
$id_c = is_array($create_c) ? (int) ($create_c['post_id'] ?? 0) : 0;
if ($id_c > 0) $cleanup_posts[] = $id_c;
koto_rel_assert($id_c > 0, 'トークンCの下書きが作成される (ID: ' . $id_c . ')');

// ------------------------------------------------------------
// シナリオ 2: A・B・C の相互紐付け（テキスト記法・構造化入力・祝福・EX祝福）
// ------------------------------------------------------------
echo "\n=== シナリオ 2: すごわざトークン・モードシフト・変身・same_character 更新 ===\n";

// Aを更新（テキスト記法でトークンC生成＋Bへモードシフト＋same_character [A, B, C]）
$prev_a2 = koto_mcp_call_tool('preview_draft', [
    'post_id'          => $id_a,
    'replace_existing' => true,
    'basic_fields'     => [
        'same_character' => [$id_a, $id_b, $id_c],
    ],
    'auto_input'       => [
        'auto_input_sugowaza' => sprintf('敵単体に超絶強力な火属性攻撃+トークンコトダマン「MCPテストトークンC_%s」[%d]を生成し、ドロー候補に加わる', $suffix, $id_c),
        'auto_input_trait2'   => sprintf('詳細画面で「MCPテスト形態B_%s」[%d]に切り替えてデッキに編成できる', $suffix, $id_b),
    ],
], $mk_req());
koto_rel_assert(!is_wp_error($prev_a2) && !empty($prev_a2['preview_hash']), 'キャラAのテキスト記法（トークン＋モードシフト＋same_character）の preview_draft が成功する');
$has_mismatch_warn = false;
foreach (($prev_a2['warnings'] ?? []) as $w) {
    if (strpos($w, '順序・構成の不一致') !== false) {
        $has_mismatch_warn = true;
    }
}
koto_rel_assert($has_mismatch_warn, 'キャラBの same_character ([A, B]) との不一致が警告出力される');

$upd_a2 = koto_mcp_call_tool('update_draft', [
    'post_id'         => $id_a,
    'preview_hash'    => $prev_a2['preview_hash'],
    'idempotency_key' => 'rel-a-upd1-' . $suffix,
], $mk_req());
koto_rel_assert(!is_wp_error($upd_a2) && (int) ($upd_a2['post_id'] ?? 0) === $id_a, 'キャラAの update_draft（テキスト記法）が成功する');

$draft_a2 = koto_mcp_call_tool('get_draft', ['post_id' => $id_a], $mk_req());
koto_rel_assert(($draft_a2['basic_fields']['same_character'] ?? []) === [$id_a, $id_b, $id_c], 'キャラAの same_character が [A, B, C] 順で保存される');
koto_rel_assert(($draft_a2['computed']['sugowaza_tokens'][0]['related_token'] ?? []) === [$id_c], 'キャラAのすごわざトークン生成に C の投稿ID が保存され get_draft で取得できる');
koto_rel_assert(
    ($draft_a2['computed']['trait_mode_shifts'][0]['slot'] ?? '') === 'trait2'
    && ($draft_a2['computed']['trait_mode_shifts'][0]['relation_ship'] ?? '') === 'mode_shift'
    && ($draft_a2['computed']['trait_mode_shifts'][0]['related_form'] ?? []) === [$id_b],
    'キャラAのとくせい2モードシフトに B の投稿ID が保存され get_draft で取得できる'
);

// Aを構造化入力で上書き（シフト2グループにトークン＋とくせい1に変身前）
$prev_a3 = koto_mcp_call_tool('preview_draft', [
    'post_id'          => $id_a,
    'replace_existing' => true,
    'auto_input'       => [
        'auto_input_sugowaza' => [
            'text'   => '自身の文字に応じて効果が変化する①「あ」の時、敵単体に爆絶強力な火属性攻撃②「い」の時、敵単体に爆絶強力な火属性攻撃',
            'tokens' => [
                ['related_token' => [$id_c], 'group_index' => 0],
                ['related_token' => [$id_c], 'group_index' => 1],
            ],
        ],
        'auto_input_trait1' => [
            'text'       => 'チェンジマスの効果を受けない',
            'mode_shift' => [
                'relation_ship' => 'before_transform',
                'related_form'  => [$id_b],
            ],
        ],
    ],
], $mk_req());
koto_rel_assert(!is_wp_error($prev_a3) && !empty($prev_a3['preview_hash']), 'キャラAの構造化入力（シフトトークン＋変身前）の preview_draft が成功する');
$upd_a3 = koto_mcp_call_tool('update_draft', [
    'post_id'         => $id_a,
    'preview_hash'    => $prev_a3['preview_hash'],
    'idempotency_key' => 'rel-a-upd2-' . $suffix,
], $mk_req());
koto_rel_assert(!is_wp_error($upd_a3), 'キャラAの update_draft（構造化入力）が成功する');

$draft_a3 = koto_mcp_call_tool('get_draft', ['post_id' => $id_a], $mk_req());
koto_rel_assert(
    count($draft_a3['computed']['sugowaza_tokens'] ?? []) === 2
    && ($draft_a3['computed']['sugowaza_tokens'][0]['group_index'] ?? -1) === 0
    && ($draft_a3['computed']['sugowaza_tokens'][1]['group_index'] ?? -1) === 1,
    '構造化入力でシフト各グループ(0, 1)へトークンCが保存される'
);
$found_t1_before = false;
foreach (($draft_a3['computed']['trait_mode_shifts'] ?? []) as $tms) {
    if (($tms['slot'] ?? '') === 'trait1' && ($tms['relation_ship'] ?? '') === 'before_transform' && ($tms['related_form'] ?? []) === [$id_b]) {
        $found_t1_before = true;
    }
}
koto_rel_assert($found_t1_before, '構造化入力でとくせい1に before_transform (related_form=[B]) が保存される');

// Bを更新（変身後＋祝福の絵違いモードシフト＋EX祝福のモードシフト＋same_character [A, B, C]）
$prev_b2 = koto_mcp_call_tool('preview_draft', [
    'post_id'          => $id_b,
    'replace_existing' => true,
    'basic_fields'     => [
        'same_character' => [$id_a, 'self', $id_c],
    ],
    'auto_input'       => [
        'auto_input_trait2'      => [
            'mode_shift' => [
                'relation_ship' => 'after_transform',
                'related_form'  => [$id_a],
            ],
        ],
        'auto_input_blessing'    => [
            'items' => [
                ['type' => 'token', 'token' => 'hp_up'],
                ['type' => 'mode_shift', 'point' => 20, 'relation_ship' => 'another_image', 'related_form' => ['self']],
            ],
        ],
        'auto_input_ex_blessing' => [
            'items' => [
                ['type' => 'mode_shift', 'point' => 20, 'relation_ship' => 'mode_shift', 'related_form' => [$id_a]],
            ],
        ],
    ],
], $mk_req());
koto_rel_assert(!is_wp_error($prev_b2) && !empty($prev_b2['preview_hash']), 'キャラB（変身後＋祝福絵違い＋EX祝福モードシフト＋same_character）の preview_draft が成功する');
$upd_b2 = koto_mcp_call_tool('update_draft', [
    'post_id'         => $id_b,
    'preview_hash'    => $prev_b2['preview_hash'],
    'idempotency_key' => 'rel-b-upd1-' . $suffix,
], $mk_req());
koto_rel_assert(!is_wp_error($upd_b2), 'キャラBの update_draft が成功する');

$draft_b2 = koto_mcp_call_tool('get_draft', ['post_id' => $id_b], $mk_req());
koto_rel_assert(($draft_b2['basic_fields']['same_character'] ?? []) === [$id_a, $id_b, $id_c], 'キャラBの same_character が [A, B, C] 順で保存される');
koto_rel_assert(
    ($draft_b2['computed']['trait_mode_shifts'][0]['relation_ship'] ?? '') === 'after_transform'
    && ($draft_b2['computed']['trait_mode_shifts'][0]['related_form'] ?? []) === [$id_a],
    'キャラBのとくせい2に after_transform (related_form=[A]) が保存される'
);
koto_rel_assert(
    ($draft_b2['blessing'][1]['type'] ?? '') === 'mode_shift'
    && ($draft_b2['blessing'][1]['relation_ship'] ?? '') === 'another_image'
    && ($draft_b2['blessing'][1]['related_form'] ?? []) === [$id_b],
    'キャラBの祝福に絵違いモードシフト (another_image, related_form=[B]) が保存され get_draft で抽出される'
);
koto_rel_assert(
    ($draft_b2['ex_blessing'][0]['type'] ?? '') === 'mode_shift'
    && ($draft_b2['ex_blessing'][0]['relation_ship'] ?? '') === 'mode_shift'
    && ($draft_b2['ex_blessing'][0]['related_form'] ?? []) === [$id_a],
    'キャラBのEX祝福にモードシフト (mode_shift, related_form=[A]) が保存され get_draft で抽出される'
);

// Cにも [A, B, C] を設定後、全員一致で警告ゼロを確認
$prev_c2 = koto_mcp_call_tool('preview_draft', [
    'post_id'      => $id_c,
    'basic_fields' => [
        'same_character' => [$id_a, $id_b, $id_c],
    ],
], $mk_req());
koto_rel_assert(!is_wp_error($prev_c2) && empty($prev_c2['warnings']), 'A・Bと同じ順番 [A, B, C] をCに指定すると順序不一致・未設定警告が出ない');
$upd_c2 = koto_mcp_call_tool('update_draft', [
    'post_id'         => $id_c,
    'preview_hash'    => $prev_c2['preview_hash'],
    'idempotency_key' => 'rel-c-upd1-' . $suffix,
], $mk_req());
koto_rel_assert(!is_wp_error($upd_c2), 'キャラCの update_draft が成功する');

// ------------------------------------------------------------
// シナリオ 3: バリデーション異常系・スキーマ確認
// ------------------------------------------------------------
echo "\n=== シナリオ 3: 異常系バリデーション & スキーマ確認 ===\n";

// 3-1. create_draft で "self" なしの same_character はエラー
$err_no_self_create = koto_mcp_call_tool('validate', [
    'character_name' => 'エラーテスト_' . $suffix,
    'basic_fields'   => array_merge($base_fields, [
        'same_character' => [$id_a, $id_b],
    ]),
], $mk_req());
koto_rel_assert(
    is_wp_error($err_no_self_create)
    && ($err_no_self_create->get_error_data()['invalid_fields'][0]['reason'] ?? '') === 'self_not_included',
    'create_draft 時に "self" を含まない same_character が self_not_included で拒否される'
);

// 3-2. update_draft で自身のIDを含まない same_character はエラー
$err_no_self_update = koto_mcp_call_tool('validate', [
    'post_id'      => $id_a,
    'basic_fields' => [
        'same_character' => [$id_b, $id_c],
    ],
], $mk_req());
koto_rel_assert(
    is_wp_error($err_no_self_update)
    && ($err_no_self_update->get_error_data()['invalid_fields'][0]['reason'] ?? '') === 'self_not_included',
    'update_draft 時に自身の post_id を含まない same_character が self_not_included で拒否される'
);

// 3-3. 存在しない投稿IDは unknown_character
$err_unknown = koto_mcp_call_tool('validate', [
    'post_id'      => $id_a,
    'basic_fields' => [
        'same_character' => [$id_a, 999999999],
    ],
], $mk_req());
koto_rel_assert(
    is_wp_error($err_unknown)
    && ($err_unknown->get_error_data()['invalid_fields'][0]['reason'] ?? '') === 'unknown_character',
    '存在しない投稿IDを含む same_character が unknown_character で拒否される'
);

// 3-4. 保護記事ID (3683) は protected_template
$err_protected = koto_mcp_call_tool('validate', [
    'post_id'      => $id_a,
    'basic_fields' => [
        'same_character' => [$id_a, 3683],
    ],
], $mk_req());
koto_rel_assert(
    is_wp_error($err_protected)
    && ($err_protected->get_error_data()['invalid_fields'][0]['reason'] ?? '') === 'protected_template',
    '保護記事ID (3683) を含む same_character が protected_template で拒否される'
);

// 3-5. とくせい1で another_image は拒否
$err_trait_another = koto_mcp_call_tool('validate', [
    'post_id'    => $id_a,
    'auto_input' => [
        'auto_input_trait1' => [
            'mode_shift' => [
                'relation_ship' => 'another_image',
                'related_form'  => [$id_a],
            ],
        ],
    ],
], $mk_req());
koto_rel_assert(
    is_wp_error($err_trait_another)
    && ($err_trait_another->get_error_data()['invalid_fields'][0]['reason'] ?? '') === 'invalid_choice',
    'とくせい1で another_image を指定すると invalid_choice で拒否される'
);

// 3-6. すごわざトークンに存在しない明示IDを指定すると拒否
$err_bad_token = koto_mcp_call_tool('validate', [
    'post_id'    => $id_a,
    'auto_input' => [
        'auto_input_sugowaza' => '敵単体に超絶強力な火属性攻撃+トークンコトダマン「ダミー」[999999999]を生成し、ドロー候補に加わる',
    ],
], $mk_req());
koto_rel_assert(
    is_wp_error($err_bad_token)
    && ($err_bad_token->get_error_data()['invalid_fields'][0]['reason'] ?? '') === 'unknown_character',
    'すごわざトークンに存在しない明示IDを指定すると unknown_character で拒否される'
);

// 3-7. キャラ名自動解決（一意一致）がテキスト記法で動作する
$name_resolve = koto_mcp_call_tool('validate', [
    'post_id'    => $id_a,
    'test_mode'  => true,
    'auto_input' => [
        'auto_input_sugowaza' => sprintf('敵単体に超絶強力な火属性攻撃+トークンコトダマン「MCPテストトークンC_%s」を生成し、ドロー候補に加わる', $suffix),
        'auto_input_trait2'   => sprintf('詳細画面で「MCPテスト形態B_%s」に切り替えてデッキに編成できる', $suffix),
    ],
], $mk_req());
koto_rel_assert(
    !is_wp_error($name_resolve)
    && ($name_resolve['parsed_acf']['auto_input_sugowaza'][0]['sugo_detail_loop'][1]['related_token'] ?? []) === [$id_c]
    && ($name_resolve['parsed_acf']['auto_input_trait2'][0]['related_form'] ?? []) === [$id_b],
    'IDタグなしのキャラ名テキスト記法でもDB一意一致時に自動で投稿ID解決される'
);

// 3-8. get_blessing_schema に mode_shift 項目が含まれる
$b_schema = koto_mcp_call_tool('get_blessing_schema', [], $mk_req());
$one_of = $b_schema['schema']['oneOf'][0]['properties']['items']['items']['oneOf'] ?? [];
$has_ms_schema = false;
foreach ($one_of as $branch) {
    if (in_array('mode_shift', (array) ($branch['properties']['type']['enum'] ?? []), true)) {
        $has_ms_schema = true;
    }
}
koto_rel_assert($has_ms_schema, 'get_blessing_schema の items.oneOf に mode_shift スキーマが含まれる');

// ------------------------------------------------------------
// シナリオ 4: relation_patches 2段階直接パッチパイプライン（非破壊検証＋絵違い警告＋異常系）
// ------------------------------------------------------------
echo "\n=== シナリオ 4: relation_patches 後追い直接更新パイプライン ===\n";

// 4-1. まず未作成の別形態・トークン名を含む通常テキストのまま新規下書きDを作成（空の骨格行が生成される）
$prev_d = koto_mcp_call_tool('preview_draft', [
    'character_name' => 'MCPテスト2段階D_' . $suffix,
    'basic_fields'   => array_merge($base_fields, ['name_ruby' => 'てすとでぃー']),
    'auto_input'     => [
        'auto_input_sugowaza' => '敵単体に超絶強力な火属性攻撃+トークンコトダマン「未作成トークンZ」を生成し、ドロー候補に加わる',
        'auto_input_trait2'   => '①チェンジマスの効果を受けない②詳細画面で「未作成形態E」に切り替えてデッキに編成できる',
        'auto_input_blessing' => [
            'items' => [
                ['type' => 'token', 'token' => 'hp_up'],
                ['type' => 'mode_shift', 'point' => 20, 'relation_ship' => 'mode_shift', 'related_form' => []],
            ],
        ],
    ],
], $mk_req());
koto_rel_assert(!is_wp_error($prev_d) && !empty($prev_d['preview_hash']), '未作成キャラ参照テキストのままでキャラDの preview_draft が成功する');
$create_d = koto_mcp_call_tool('create_draft', [
    'preview_hash'    => $prev_d['preview_hash'],
    'idempotency_key' => 'rel-d-create-' . $suffix,
], $mk_req());
$id_d = is_array($create_d) ? (int) ($create_d['post_id'] ?? 0) : 0;
if ($id_d > 0) $cleanup_posts[] = $id_d;
koto_rel_assert($id_d > 0, 'キャラDの下書きが作成される (ID: ' . $id_d . ')');

$draft_d_before = koto_mcp_call_tool('get_draft', ['post_id' => $id_d], $mk_req());
koto_rel_assert(
    ($draft_d_before['computed']['sugowaza_tokens'][0]['group_index'] ?? -1) === 0
    && ($draft_d_before['computed']['sugowaza_tokens'][0]['detail_index'] ?? -1) === 1
    && ($draft_d_before['computed']['sugowaza_tokens'][0]['related_token'] ?? null) === [],
    'get_draft で空のすごわざトークン骨格行の group_index=0, detail_index=1 が取得できる'
);
koto_rel_assert(
    ($draft_d_before['computed']['trait_mode_shifts'][0]['slot'] ?? '') === 'trait2'
    && ($draft_d_before['computed']['trait_mode_shifts'][0]['row_index'] ?? -1) === 1
    && ($draft_d_before['computed']['trait_mode_shifts'][0]['related_form'] ?? null) === [],
    'get_draft で空のとくせい2モードシフト骨格行の row_index=1 が取得できる'
);
koto_rel_assert(
    ($draft_d_before['blessing'][1]['row_index'] ?? -1) === 1
    && ($draft_d_before['blessing'][1]['type'] ?? '') === 'mode_shift',
    'get_draft で祝福 mode_shift 行の row_index=1 が取得できる'
);

// 4-2. auto_input を再送せず、relation_patches だけで既存骨格行へ直接IDを流し込む（＋絵違い明示指定）
$prev_d_patch = koto_mcp_call_tool('preview_draft', [
    'post_id'      => $id_d,
    'basic_fields' => [
        'same_character'     => [$id_d, $id_b, $id_c],
        'another_image_name' => '絵違いテストD',
    ],
    'relation_patches' => [
        'sugowaza_tokens' => [
            ['group_index' => 0, 'detail_index' => 1, 'related_token' => [$id_c]],
        ],
        'trait_mode_shifts' => [
            ['slot' => 'trait2', 'row_index' => 1, 'related_form' => [$id_b]],
        ],
        'blessing_mode_shifts' => [
            ['row_index' => 1, 'relation_ship' => 'another_image', 'related_form' => []],
        ],
    ],
], $mk_req());
koto_rel_assert(!is_wp_error($prev_d_patch) && !empty($prev_d_patch['preview_hash']), 'relation_patches による後追い直接パッチの preview_draft が成功する');
$upd_d_patch = koto_mcp_call_tool('update_draft', [
    'post_id'         => $id_d,
    'preview_hash'    => $prev_d_patch['preview_hash'],
    'idempotency_key' => 'rel-d-patch1-' . $suffix,
], $mk_req());
koto_rel_assert(!is_wp_error($upd_d_patch), 'relation_patches の update_draft が preview_hash キャッシュ復元で成功する');

// 既存の攻撃行・ギミック行・hp_up祝福行が一切壊れておらず、対象行のみが直接更新されていることをACF実値で検証
$sugo_after = get_field('sugowaza_group_loop', $id_d);
$t2_after = get_field('second_trait_loop', $id_d);
$draft_d_after = koto_mcp_call_tool('get_draft', ['post_id' => $id_d], $mk_req());
koto_rel_assert(
    count($sugo_after[0]['sugo_detail_loop'] ?? []) === 2
    && ($sugo_after[0]['sugo_detail_loop'][0]['waza_type'] ?? '') === 'attack'
    && ($draft_d_after['computed']['sugowaza_tokens'][0]['related_token'] ?? []) === [$id_c],
    'relation_patches 後も既存のすごわざ攻撃行[0]が非破壊で維持され、トークン行[1]のみにCが直接反映される'
);
koto_rel_assert(
    count($t2_after ?? []) === 2
    && ($t2_after[0]['trait_type'] ?? '') === 'gimmick'
    && ($draft_d_after['computed']['trait_mode_shifts'][0]['related_form'] ?? []) === [$id_b],
    'relation_patches 後も既存のとくせい2ギミック行[0]が非破壊で維持され、モードシフト行[1]のみにBが直接反映される'
);
koto_rel_assert(
    ($draft_d_after['blessing'][0]['token'] ?? '') === 'hp_up'
    && ($draft_d_after['blessing'][1]['relation_ship'] ?? '') === 'another_image'
    && ($draft_d_after['basic_fields']['another_image_name'] ?? '') === '絵違いテストD',
    'relation_patches 後も祝福 hp_up[0] が非破壊で維持され、絵違いモードシフト[1]と another_image_name が反映される'
);

// 4-3. detail_index / row_index 省略時の自動特定（単一行ならそのまま更新）
$prev_d_auto = koto_mcp_call_tool('preview_draft', [
    'post_id'          => $id_d,
    'relation_patches' => [
        'sugowaza_tokens'   => [['group_index' => 0, 'related_token' => [$id_b]]],
        'trait_mode_shifts' => [['slot' => 'trait2', 'related_form' => [$id_a]]],
    ],
], $mk_req());
$upd_d_auto = !is_wp_error($prev_d_auto) ? koto_mcp_call_tool('update_draft', [
    'post_id'         => $id_d,
    'preview_hash'    => $prev_d_auto['preview_hash'],
    'idempotency_key' => 'rel-d-patch2-' . $suffix,
], $mk_req()) : $prev_d_auto;
$draft_d_auto = koto_mcp_call_tool('get_draft', ['post_id' => $id_d], $mk_req());
koto_rel_assert(
    !is_wp_error($upd_d_auto)
    && ($draft_d_auto['computed']['sugowaza_tokens'][0]['related_token'] ?? []) === [$id_b]
    && ($draft_d_auto['computed']['trait_mode_shifts'][0]['related_form'] ?? []) === [$id_a],
    'detail_index / row_index 省略時でも単一該当行を自動特定して直接上書きできる'
);

// 4-4. 絵違いモードシフト指定時に another_image_name が未設定なら警告が出る
$warn_no_ano = koto_mcp_call_tool('validate', [
    'post_id'          => $id_a,
    'relation_patches' => [
        'blessing_mode_shifts' => [
            ['relation_ship' => 'another_image', 'related_form' => [], 'point' => 20],
        ],
    ],
], $mk_req());
$has_ano_warn = false;
if (!is_wp_error($warn_no_ano)) {
    foreach (($warn_no_ano['warnings'] ?? []) as $w) {
        if (strpos($w, 'another_image_name') !== false) {
            $has_ano_warn = true;
        }
    }
}
koto_rel_assert(!is_wp_error($warn_no_ano) && $has_ano_warn, '絵違い(another_image)指定時に another_image_name 未設定警告が出る');

// 4-5. 異常系: create_draft（post_idなし）での relation_patches 拒否 & 攻撃行への誤パッチ拒否
$err_rp_create = koto_mcp_call_tool('validate', [
    'character_name'   => 'RPエラー_' . $suffix,
    'basic_fields'     => $base_fields,
    'relation_patches' => ['sugowaza_tokens' => [['related_token' => [$id_c]]]],
], $mk_req());
koto_rel_assert(
    is_wp_error($err_rp_create)
    && ($err_rp_create->get_error_data()['invalid_fields'][0]['reason'] ?? '') === 'not_supported_on_create',
    'post_id 未指定での relation_patches が not_supported_on_create で拒否される'
);

$err_rp_mismatch = koto_mcp_call_tool('validate', [
    'post_id'          => $id_d,
    'relation_patches' => [
        'sugowaza_tokens' => [
            ['group_index' => 0, 'detail_index' => 0, 'related_token' => [$id_c]],
        ],
    ],
], $mk_req());
koto_rel_assert(
    is_wp_error($err_rp_mismatch)
    && ($err_rp_mismatch->get_error_data()['invalid_fields'][0]['reason'] ?? '') === 'target_row_type_mismatch',
    'waza_type=attack の行(detail_index=0)に対するトークンパッチが target_row_type_mismatch で安全に拒否される'
);

echo "\n========================================================\n";
echo sprintf("結果: %d passed / %d failed\n", $pass_count, $fail_count);
echo "========================================================\n";
exit($fail_count === 0 ? 0 : 1);
