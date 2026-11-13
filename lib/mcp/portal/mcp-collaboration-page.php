<?php
/**
 * 協力者ポータル＆ユーザー登録モジュール
 *
 * - 固定ページテンプレート（collaboration-page.php）およびショートコード [koto_collaborate_portal] の共通描画
 * - メール認証付きセルフユーザー登録（登録時は権限最小の subscriber として作成）
 * - 登録時の「MCP利用も申請する」チェック（デフォルトON）で _koto_mcp_requested=1 を付与し管理者へ通知
 * - 主要AIツール別の接続ガイド切替UI（ChatGPT / Claude / Gemini / Muse / Copilot / Cursor / Dify 等）
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 協力者登録フォーム・認証トークンの有効期限（秒）
 */
if (!defined('KOTO_MCP_REG_TOKEN_TTL')) {
    define('KOTO_MCP_REG_TOKEN_TTL', DAY_IN_SECONDS);
}

/**
 * ポータル用のスタイル・スクリプトをエンキューする（<head>内事前読み込み・冪等）
 */
function koto_mcp_collab_enqueue_assets(): void {
    $theme_uri = get_stylesheet_directory_uri();
    $mcp_dir   = __DIR__;
    $css_ver   = file_exists($mcp_dir . '/mcp-collaboration.css') ? (string) filemtime($mcp_dir . '/mcp-collaboration.css') : '1.0.0';
    $js_ver    = file_exists($mcp_dir . '/mcp-collaboration.js') ? (string) filemtime($mcp_dir . '/mcp-collaboration.js') : '1.0.0';

    wp_enqueue_style(
        'koto-mcp-collaboration',
        $theme_uri . '/lib/mcp/portal/mcp-collaboration.css',
        [],
        $css_ver
    );
    wp_enqueue_script(
        'koto-mcp-collaboration',
        $theme_uri . '/lib/mcp/portal/mcp-collaboration.js',
        [],
        $js_ver,
        true
    );
}

// 【一時非公開メモ】MCP協力者募集ページ（/collaborate/）一時非公開化に伴いアセット読み込みフックをコメントアウト（処理自体は残す）。
// 再公開時は下のコメントアウトを解除すること。
/*
add_action('wp_enqueue_scripts', function (): void {
    if (is_page_template('collaboration-page.php')) {
        koto_mcp_collab_enqueue_assets();
        return;
    }
    if (is_singular()) {
        $post_id = get_queried_object_id();
        if ($post_id > 0) {
            $content = (string) get_post_field('post_content', $post_id);
            if ($content !== '' && has_shortcode($content, 'koto_collaborate_portal')) {
                koto_mcp_collab_enqueue_assets();
            }
        }
    }
}, 20);
*/

/**
 * ユーザー登録用システムプロンプト（AIクライアント向けコピー用）
 */
function koto_mcp_collab_default_system_prompt(): string {
    return "あなたは「コトダマンDB」のキャラクターデータ登録アシスタントです。\n"
        . "必ず以下のルールに従って作業してください：\n"
        . "1. 作業開始時にまず `get_field_schema` と `get_blessing_schema` と `get_terms` を確認し、有効なフィールド名・選択肢・スラッグを把握する。\n"
        . "2. 既存キャラとの重複を防ぐため、`search_character` で同名キャラが存在しないか先に確認する。\n"
        . "3. 画像やユーザーから提供された情報だけを根拠にし、推測で数値・属性・ギミック等を埋めない。\n"
        . "4. 下書き登録前に `validate` で入力エラーを確認し、`preview_draft` で人間が確認できるサマリーを提示する。\n"
        . "5. 問題がなければ `create_draft` または `update_draft` で下書き（draft）として保存する。公開操作は人間がWordPress管理画面で行う。";
}

/**
 * クライアントIPアドレス取得（レート制限用）
 */
function koto_mcp_collab_client_ip(): string {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
    return preg_replace('/[^0-9a-fA-F:.,]/', '', $ip) ?: '127.0.0.1';
}

/**
 * 現在のポータルURL（固定ページ表示中ならそのページのパーマリンク）を取得する
 */
function koto_mcp_collab_current_url(): string {
    if (function_exists('is_page') && is_page()) {
        $qid = get_queried_object_id();
        if ($qid > 0) {
            $permalink = get_permalink($qid);
            if (is_string($permalink) && $permalink !== '') {
                return remove_query_arg(['koto_reg_notice', 'koto_reg_verify'], $permalink);
            }
        }
    }
    return koto_mcp_collaborate_page_url();
}

/**
 * 同一サイト内の安全なリダイレクト先URLを決定する
 */
function koto_mcp_collab_resolve_return_url(string $candidate = ''): string {
    $fallback = koto_mcp_collab_current_url();
    $candidate = trim($candidate);
    if ($candidate === '') {
        return $fallback;
    }
    $validated = wp_validate_redirect($candidate, $fallback);
    return remove_query_arg(['koto_reg_notice', 'koto_reg_verify'], $validated);
}

/**
 * メール認証トークンをストレージへ保存し認証メールを送信する
 *
 * @param array $input [
 *   'username'         => string,
 *   'email'            => string,
 *   'display_name'     => string,
 *   'password'         => string,
 *   'password_confirm' => string,
 *   'request_mcp'      => bool|int|string,
 *   'website_url'      => string, // ハニーポット（空であること）
 *   'client_ip'        => string, // 任意（レート制限識別）
 *   'skip_rate_limit'  => bool,   // CLIテスト等での明示スキップ用
 *   'skip_mail'        => bool,   // CLIテスト用（trueでwp_mail送信を抑止しtokenを返す）
 * ]
 * @return array|WP_Error
 */
function koto_mcp_collab_submit_registration(array $input) {
    // 1. ハニーポットチェック
    $honeypot = trim((string) ($input['website_url'] ?? ''));
    if ($honeypot !== '') {
        return new WP_Error('koto_reg_spam', '登録リクエストを受け付けられませんでした。');
    }

    // 2. レート制限（1IPあたり10分間に5回まで）
    $skip_rl = !empty($input['skip_rate_limit']);
    if (!$skip_rl) {
        $ip = trim((string) ($input['client_ip'] ?? koto_mcp_collab_client_ip()));
        $rl_key = 'koto_mcp_reg_rl_' . md5($ip);
        $count = (int) get_transient($rl_key);
        if ($count >= 5) {
            return new WP_Error('koto_reg_rate_limited', '短時間に連続して登録申請が行われました。10分ほど待ってから再度お試しください。');
        }
        set_transient($rl_key, $count + 1, 10 * MINUTE_IN_SECONDS);
    }

    // 3. 入力サニタイズ＆バリデーション
    $username_raw     = trim((string) ($input['username'] ?? ''));
    $username         = sanitize_user($username_raw, true);
    $email            = sanitize_email(trim((string) ($input['email'] ?? '')));
    $display_name     = sanitize_text_field(trim((string) ($input['display_name'] ?? '')));
    $password         = (string) ($input['password'] ?? '');
    $password_confirm = (string) ($input['password_confirm'] ?? '');
    $request_mcp      = !isset($input['request_mcp']) || !empty($input['request_mcp']);

    if ($username === '' || strlen($username) < 3 || strlen($username) > 60 || $username !== $username_raw || !validate_username($username)) {
        return new WP_Error('koto_reg_invalid_username', 'ユーザー名は半角英数字・アンダースコア等で3〜60文字以内で入力してください。');
    }
    if (!is_email($email)) {
        return new WP_Error('koto_reg_invalid_email', '有効なメールアドレスを入力してください。');
    }
    if (strlen($password) < 8) {
        return new WP_Error('koto_reg_weak_password', 'パスワードは8文字以上で入力してください。');
    }
    if ($password_confirm !== '' && !hash_equals($password, $password_confirm)) {
        return new WP_Error('koto_reg_password_mismatch', '確認用パスワードが一致しません。');
    }

    if (username_exists($username)) {
        return new WP_Error('koto_reg_username_exists', 'このユーザー名は既に使用されています。');
    }
    if (email_exists($email)) {
        return new WP_Error('koto_reg_email_exists', 'このメールアドレスは既に登録されています。');
    }

    if ($display_name === '') {
        $display_name = $username;
    }

    // 4. 認証トークン生成・ハッシュ化パスワード保存（ユーザー未作成状態で保留）
    $token = bin2hex(random_bytes(24));
    $token_hash = hash('sha256', $token);
    $now = time();
    $expires_at = $now + KOTO_MCP_REG_TOKEN_TTL;

    $pending_payload = [
        'username'     => $username,
        'email'        => $email,
        'display_name' => $display_name,
        'pass_hash'    => wp_hash_password($password),
        'request_mcp'  => $request_mcp ? 1 : 0,
        'created_at'   => $now,
        'expires_at'   => $expires_at,
    ];

    $storage_key = 'koto_mcp_pending_reg_' . $token_hash;
    if (function_exists('koto_mcp_storage_put')) {
        koto_mcp_storage_put($storage_key, $pending_payload, 'no');
    } else {
        update_option($storage_key, $pending_payload, false);
    }

    $base_portal_url = !empty($input['return_url'])
        ? koto_mcp_collab_resolve_return_url((string) $input['return_url'])
        : koto_mcp_collab_current_url();
    $verify_url = add_query_arg(
        ['koto_reg_verify' => $token],
        $base_portal_url
    );

    if (empty($input['skip_mail'])) {
        $site_name = get_bloginfo('name');
        $subject = sprintf('[%s] メールアドレス認証（協力者アカウント登録）', $site_name);
        $message = sprintf(
            "%s 様\n\n"
            . "「%s」への協力者登録リクエストを受け付けました。\n"
            . "以下のリンクを24時間以内にクリックしてメールアドレスの認証を完了してください。\n\n"
            . "%s\n\n"
            . "※このリンクを開くまでアカウントは作成されません。\n"
            . "※心当たりがない場合は、このメールを破棄してください。\n",
            $display_name,
            $site_name,
            $verify_url
        );
        wp_mail($email, $subject, $message);
    }

    return [
        'status'      => 'pending_verification',
        'email'       => $email,
        'username'    => $username,
        'request_mcp' => $request_mcp,
        'expires_at'  => $expires_at,
        'verify_url'  => $verify_url,
        'token'       => $token,
    ];
}

/**
 * メール認証トークンを検証し、WordPressユーザー（subscriber）を作成する
 *
 * @param string $token
 * @return array|WP_Error
 */
function koto_mcp_collab_verify_registration(string $token) {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{32,128}$/i', $token)) {
        return new WP_Error('koto_reg_invalid_token', '認証トークンの形式が不正です。');
    }

    $token_hash = hash('sha256', $token);
    $storage_key = 'koto_mcp_pending_reg_' . $token_hash;

    // 使い捨て: 読み出しと同時に削除を試み、二重作成を防ぐ
    $pending = function_exists('koto_mcp_storage_get')
        ? koto_mcp_storage_get($storage_key, null)
        : get_option($storage_key, null);

    if (function_exists('koto_mcp_storage_delete')) {
        koto_mcp_storage_delete($storage_key);
    } else {
        delete_option($storage_key);
    }

    if (!is_array($pending) || empty($pending['username']) || empty($pending['email']) || empty($pending['pass_hash'])) {
        return new WP_Error('koto_reg_token_not_found', '認証リンクが無効か、既に使用済みです。');
    }

    $expires_at = (int) ($pending['expires_at'] ?? 0);
    if ($expires_at > 0 && time() > $expires_at) {
        return new WP_Error('koto_reg_token_expired', '認証リンクの有効期限（24時間）が切れています。再度ご登録ください。');
    }

    $username     = (string) $pending['username'];
    $email        = (string) $pending['email'];
    $display_name = (string) ($pending['display_name'] ?? $username);
    $pass_hash    = (string) $pending['pass_hash'];
    $request_mcp  = !empty($pending['request_mcp']);

    if (username_exists($username) || email_exists($email)) {
        return new WP_Error('koto_reg_already_registered', 'このユーザー名またはメールアドレスは既に登録済みです。');
    }

    // 最小権限 subscriber としてユーザー作成
    $user_id = wp_insert_user([
        'user_login'   => $username,
        'user_pass'    => wp_generate_password(32, true, true),
        'user_email'   => $email,
        'display_name' => $display_name,
        'role'         => 'subscriber',
    ]);

    if (is_wp_error($user_id)) {
        return $user_id;
    }

    // 保存していたハッシュ化パスワードをそのまま設定（平文パスワード非保存）
    global $wpdb;
    $wpdb->update(
        $wpdb->users,
        ['user_pass' => $pass_hash],
        ['ID' => (int) $user_id],
        ['%s'],
        ['%d']
    );
    clean_user_cache((int) $user_id);

    update_user_meta((int) $user_id, '_koto_email_verified', 1);
    update_user_meta((int) $user_id, '_koto_email_verified_at', current_time('mysql'));

    if ($request_mcp) {
        if (function_exists('koto_mcp_record_request_meta')) {
            koto_mcp_record_request_meta((int) $user_id);
        } else {
            update_user_meta((int) $user_id, '_koto_mcp_requested', '1');
            update_user_meta((int) $user_id, '_koto_mcp_requested_at', current_time('mysql'));
        }

        // 管理者通知メール
        $admin_email = get_option('admin_email');
        if ($admin_email) {
            $approval_url = admin_url('users.php?page=koto-mcp-approvals');
            wp_mail(
                $admin_email,
                sprintf('[%s] 新規ユーザー登録＋MCP利用申請がありました', get_bloginfo('name')),
                sprintf(
                    "新しい協力者がメール認証を完了し、MCP利用を申請しました。\n\n"
                    . "ユーザー名: %s\n表示名: %s\nメール: %s\n"
                    . "承認管理画面: %s\n",
                    $username,
                    $display_name,
                    $email,
                    $approval_url
                )
            );
        }
    }

    return [
        'status'      => 'verified',
        'user_id'     => (int) $user_id,
        'username'    => $username,
        'email'       => $email,
        'role'        => 'subscriber',
        'request_mcp' => $request_mcp,
    ];
}

/**
 * フラッシュ通知用の短いトークンをtransient保存
 */
function koto_mcp_collab_store_notice(string $type, string $message): string {
    $key = bin2hex(random_bytes(8));
    set_transient('koto_mcp_collab_notice_' . $key, [
        'type'    => $type === 'success' ? 'success' : 'error',
        'message' => $message,
    ], 5 * MINUTE_IN_SECONDS);
    return $key;
}

/**
 * template_redirect でフォーム送信とメール認証GETを受け付ける
 *
 * 【一時非公開メモ】MCP協力者募集の一時非公開化に伴い、公開ページからの登録・認証受付フックをコメントアウト中。
 * 関数本体（koto_mcp_collab_submit_registration / koto_mcp_collab_verify_registration）は残している。
 * 再公開時は下のコメントアウトを解除すること。
 */
/*
add_action('template_redirect', function (): void {
    // 1. メール認証GET (?koto_reg_verify=...)
    if (isset($_GET['koto_reg_verify'])) {
        $token = sanitize_text_field(wp_unslash((string) $_GET['koto_reg_verify']));
        $result = koto_mcp_collab_verify_registration($token);
        if (is_wp_error($result)) {
            $nk = koto_mcp_collab_store_notice('error', $result->get_error_message());
        } else {
            $msg = 'メールアドレスの認証が完了し、アカウントを作成しました（' . $result['username'] . '）。';
            if (!empty($result['request_mcp'])) {
                $msg .= ' MCP利用申請も同時に送信済みです。管理者の承認後、承認通知メールが届きます。';
            }
            $nk = koto_mcp_collab_store_notice('success', $msg);
        }
        $redirect = add_query_arg('koto_reg_notice', $nk, koto_mcp_collab_current_url());
        wp_safe_redirect($redirect);
        exit;
    }

    // 2. 新規登録POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['koto_collab_reg_submit'])) {
        $return_url = koto_mcp_collab_resolve_return_url(
            isset($_POST['koto_reg_return_url']) ? wp_unslash((string) $_POST['koto_reg_return_url']) : ''
        );
        $nonce = isset($_POST['koto_collab_reg_nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['koto_collab_reg_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'koto_collab_register')) {
            $nk = koto_mcp_collab_store_notice('error', 'セキュリティチェックに失敗しました。ページを再読み込みしてもう一度お試しください。');
            wp_safe_redirect(add_query_arg('koto_reg_notice', $nk, $return_url));
            exit;
        }

        $result = koto_mcp_collab_submit_registration([
            'username'         => wp_unslash((string) ($_POST['koto_reg_username'] ?? '')),
            'email'            => wp_unslash((string) ($_POST['koto_reg_email'] ?? '')),
            'display_name'     => wp_unslash((string) ($_POST['koto_reg_display_name'] ?? '')),
            'password'         => (string) ($_POST['koto_reg_password'] ?? ''),
            'password_confirm' => (string) ($_POST['koto_reg_password_confirm'] ?? ''),
            'request_mcp'      => isset($_POST['koto_reg_request_mcp']),
            'website_url'      => wp_unslash((string) ($_POST['website_url'] ?? '')),
            'return_url'       => $return_url,
        ]);

        if (is_wp_error($result)) {
            $nk = koto_mcp_collab_store_notice('error', $result->get_error_message());
        } else {
            $nk = koto_mcp_collab_store_notice(
                'success',
                sprintf(
                    '確認メールを %s 宛に送信しました。24時間以内にメール内のリンクを開いて登録を完了してください。',
                    $result['email']
                )
            );
        }

        wp_safe_redirect(add_query_arg('koto_reg_notice', $nk, $return_url));
        exit;
    }
});
*/

/**
 * AIクライアント別の接続ガイド定義（JSに渡すJSONデータ）
 */
function koto_mcp_collab_ai_guides_data(): array {
    $mcp_url     = koto_mcp_server_url();
    $rest_base   = koto_mcp_base_url();
    $openapi_url = $rest_base . '/mcp/openapi.json';

    $json_http_config = wp_json_encode([
        'mcpServers' => [
            'kotodaman-db' => [
                'url' => $mcp_url,
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $json_type_http_config = wp_json_encode([
        'mcpServers' => [
            'kotodaman-db' => [
                'type' => 'http',
                'url'  => $mcp_url,
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return [
        'chatgpt_mcp' => [
            'title'         => 'ChatGPT（MCPコネクタ / Apps・Developer Mode）',
            'badge'         => '対応済み・OAuth 2.1 / CIMD',
            'unsupported'   => false,
            'summary'       => 'ChatGPTの設定画面からMCPサーバーURLを登録するだけで、ブラウザ上のOAuthログイン画面が開き、全10ツールをそのまま利用できます。',
            'steps'         => [
                'ChatGPT の設定 →「コネクタ（Connectors / Apps）」→「開発者モード（Developer Mode）」またはカスタムコネクタ作成を開く。',
                'MCP Server URL に「' . $mcp_url . '」を入力する。',
                '認証方式は「OAuth」を選択する（CIMD / DCR 自動対応）。',
                '表示されるコトダマンDBログイン画面でサインインし、スコープ（validate preview / create）を許可する。',
            ],
            'snippet_label' => 'ChatGPT コネクタ登録URL',
            'snippet'       => $mcp_url,
        ],
        'chatgpt_actions' => [
            'title'         => 'ChatGPT（Custom GPT Actions / REST + OpenAPI）',
            'badge'         => '対応済み・Static OAuth / OpenAPI 3.1',
            'unsupported'   => false,
            'summary'       => 'Custom GPTsのActions機能からOpenAPI 3.1.0スキーマを取り込み、REST経由で下書き作成・検索・検証を行います。管理者発行のStatic Client ID / Secretが必要です。',
            'steps'         => [
                '管理者に依頼してStatic OAuthクライアント（client_id / client_secret）を発行してもらい、GPT編集画面に表示されるCallback URLを登録してもらう。',
                'GPT Builder の「Configure」→「Actions」→「Import from URL」へ下記 OpenAPI URL を入力する。',
                'Authentication で「OAuth」を選び、Client ID / Client Secret、Authorization URL（' . $rest_base . '/mcp/oauth/authorize）、Token URL（' . $rest_base . '/mcp/oauth/token）を設定する。',
                'Scope に「validate preview create」を指定して保存する。',
            ],
            'snippet_label' => 'OpenAPI 3.1.0 スキーマ URL',
            'snippet'       => $openapi_url,
        ],
        'claude' => [
            'title'         => 'Claude（Web / Desktop / iOS・Android）',
            'badge'         => '対応済み・OAuth 2.1 / DCR・CIMD',
            'unsupported'   => false,
            'summary'       => 'Claudeの「カスタムコネクタ」からMCPサーバーURLを追加するだけで、OAuth 2.1認証を経てすぐに利用できます。',
            'steps'         => [
                'Claude の「設定（Settings）」→「コネクタ（Connectors / Integrations）」→「カスタムコネクタを追加」を開く。',
                '名前に「コトダマンDB」、リモートMCPサーバーURLに「' . $mcp_url . '」を入力して追加する。',
                'チャット画面でコネクタを有効にし、OAuth画面でコトダマンDBにログイン・許可する。',
            ],
            'snippet_label' => 'Claude リモートMCP URL',
            'snippet'       => $mcp_url,
        ],
        'claude_code' => [
            'title'         => 'Claude Code（CLI）',
            'badge'         => '対応済み・Streamable HTTP + OAuth',
            'unsupported'   => false,
            'summary'       => 'コマンド1行でリモートHTTP MCPサーバーを登録し、`/mcp` コマンドからブラウザOAuth認証を行えます。',
            'steps'         => [
                'ターミナルで下記の `claude mcp add` コマンドを実行する。',
                'Claude Code 起動後、`/mcp` を実行して `kotodaman-db` の「Authenticate」を選択する。',
                'ブラウザでコトダマンDBへログイン・承認するとトークンが保存される。',
            ],
            'snippet_label' => 'Claude Code 追加コマンド',
            'snippet'       => 'claude mcp add --transport http kotodaman-db ' . $mcp_url,
        ],
        'gemini_web' => [
            'title'         => 'Gemini（Web・Developer Mode / Extensions）',
            'badge'         => '一部対応・環境により接続可',
            'unsupported'   => false,
            'summary'       => 'コンシューマ向けGemini Web標準画面はサードパーティMCPを直接登録できない場合がありますが、Developer ModeまたはGoogle AI Studio / Workspace Studio連携ではMCP URLまたはOpenAPIを利用できます。',
            'steps'         => [
                '利用中のGemini環境がカスタムMCPに対応している場合、MCP URL（' . $mcp_url . '）を設定する。',
                'OpenAPIツール連携に対応している場合は OpenAPI URL（' . $openapi_url . '）と OAuth / Bearer トークンを指定する。',
                '一般的なGemini Webのみお使いの場合は、ターミナルで使える「Gemini CLI」の利用をおすすめします。',
            ],
            'snippet_label' => 'MCP / OpenAPI URL',
            'snippet'       => "MCP URL: {$mcp_url}\nOpenAPI URL: {$openapi_url}",
        ],
        'gemini_cli' => [
            'title'         => 'Gemini CLI（Google公式ターミナルエージェント）',
            'badge'         => '対応済み・HTTP MCP + OAuth',
            'unsupported'   => false,
            'summary'       => 'Google公式の Gemini CLI は Streamable HTTP MCP と OAuth 2.1 にネイティブ対応しています。',
            'steps'         => [
                '`~/.gemini/settings.json`（またはプロジェクトの `.gemini/settings.json`）に下記 `mcpServers` 設定を追加する。',
                '`gemini` を起動し、`/mcp auth kotodaman-db` を実行するか初回ツール呼び出し時の認証プロンプトに従いブラウザでログインする。',
            ],
            'snippet_label' => 'Gemini CLI settings.json 抜粋',
            'snippet'       => wp_json_encode([
                'mcpServers' => [
                    'kotodaman-db' => [
                        'httpUrl' => $mcp_url,
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ],
        'gemini_api_enterprise' => [
            'title'         => 'Gemini API / Gemini Enterprise（Vertex AI Agent）',
            'badge'         => '対応済み・HTTP MCP / Static OAuth',
            'unsupported'   => false,
            'summary'       => 'Gemini Enterprise や Vertex AI / Gemini API からはリモートMCPまたはOpenAPI Toolとして接続できます。',
            'steps'         => [
                'MCP対応エージェント機能では「' . $mcp_url . '」をリモートMCPエンドポイントとして指定する。',
                'OpenAPI連携やサーバー間接続で固定クライアントが必要な場合は、管理者にStatic OAuthクライアント発行を依頼し、OpenAPI URL（' . $openapi_url . '）を取り込む。',
            ],
            'snippet_label' => '接続エンドポイント一覧',
            'snippet'       => "MCP URL: {$mcp_url}\nOpenAPI URL: {$openapi_url}\nToken URL: {$rest_base}/mcp/oauth/token",
        ],
        'muse' => [
            'title'         => 'Muse（リモートMCP対応クライアント）',
            'badge'         => '対応済み・Streamable HTTP + OAuth / Bearer',
            'unsupported'   => false,
            'summary'       => 'Muse の MCPサーバー設定画面から HTTP エンドポイントを指定して接続できます。',
            'steps'         => [
                'Muse の MCP 設定で「HTTP / Streamable HTTP」タイプの新規サーバーを追加する。',
                'URL に「' . $mcp_url . '」を設定する。',
                'OAuth画面が開く場合はそのままログイン。固定トークンが必要な場合は、WordPressプロフィール画面でアプリケーションパスワードを発行し Authorization: Basic ヘッダーで指定する。',
            ],
            'snippet_label' => 'Muse 設定用JSON例',
            'snippet'       => $json_type_http_config,
        ],
        'grok_perplexity_lechat' => [
            'title'         => 'Grok / Perplexity / Mistral Le Chat',
            'badge'         => '対応済み・Custom MCP Connectors',
            'unsupported'   => false,
            'summary'       => 'Grok、Perplexity（Desktop / Enterprise）、Mistral Le Chat のカスタムMCPコネクタから HTTP + OAuth 2.1 で接続できます。',
            'steps'         => [
                '各サービスの Connectors / Integrations 設定からカスタムMCPサーバーを追加する。',
                'エンドポイントURLに「' . $mcp_url . '」を入力する。',
                '表示されたOAuth同意画面でコトダマンDBアカウント（mcp_user権限）にログインしアクセスを許可する。',
            ],
            'snippet_label' => 'カスタムMCP URL',
            'snippet'       => $mcp_url,
        ],
        'copilot_studio' => [
            'title'         => 'Microsoft Copilot Studio（Agent Builder）',
            'badge'         => '対応済み・Static OAuth + MCP / OpenAPI',
            'unsupported'   => false,
            'summary'       => 'Copilot Studio ではカスタムコネクタ経由でMCPサーバーまたはOpenAPI REST APIをエージェントに組み込めます。',
            'steps'         => [
                '管理者にStatic OAuthクライアント（client_id / client_secret）発行を依頼する（Power PlatformのリダイレクトURL登録も依頼）。',
                'Copilot Studio の「ツール（Tools）」→「新しいツール」→「Model Context Protocol」または「REST API（OpenAPI）」を選択する。',
                'MCP URL「' . $mcp_url . '」または OpenAPI URL「' . $openapi_url . '」と OAuth 2.0 情報を入力する。',
            ],
            'snippet_label' => 'Copilot Studio 設定値',
            'snippet'       => "MCP URL: {$mcp_url}\nOpenAPI URL: {$openapi_url}\nAuthorize URL: {$rest_base}/mcp/oauth/authorize\nToken URL: {$rest_base}/mcp/oauth/token",
        ],
        'github_copilot' => [
            'title'         => 'GitHub Copilot（VS Code / Coding Agent）',
            'badge'         => '対応済み・mcp.json + OAuth 2.1',
            'unsupported'   => false,
            'summary'       => 'VS Code の `.vscode/mcp.json`（またはユーザー設定）に登録するだけで、OAuth 2.1 ブラウザログインにより利用できます。',
            'steps'         => [
                'VS Code で `.vscode/mcp.json`（またはユーザー `mcp.json`）を開き、下記設定を貼り付ける。',
                'エディタ上の「Start」をクリックし、VS Codeの案内に従ってブラウザでコトダマンDBにログイン・認可する。',
                'Copilot Chat を「Agent」モードに切り替えて `kotodaman-db` のツールが有効になっていることを確認する。',
            ],
            'snippet_label' => '.vscode/mcp.json 設定例',
            'snippet'       => wp_json_encode([
                'servers' => [
                    'kotodaman-db' => [
                        'type' => 'http',
                        'url'  => $mcp_url,
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ],
        'antigravity' => [
            'title'         => 'Antigravity（IDE / Agent）',
            'badge'         => '対応済み・mcp_config.json',
            'unsupported'   => false,
            'summary'       => 'Antigravity の `mcp_config.json` に `serverUrl` と認証ヘッダー（またはOAuth）を設定して接続できます。',
            'steps'         => [
                '`~/.gemini/antigravity/mcp_config.json` を開く。',
                '下記 `mcpServers` ブロックを追加する（WordPressプロフィールで発行したアプリケーションパスワードを Base64 化した Basic 認証、または OAuth Bearer トークンを指定）。',
            ],
            'snippet_label' => 'mcp_config.json 設定例',
            'snippet'       => wp_json_encode([
                'mcpServers' => [
                    'kotodaman-db' => [
                        'serverUrl' => $mcp_url,
                        'headers'   => [
                            'Authorization' => 'Basic <base64(username:app_password)>',
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ],
        'cursor' => [
            'title'         => 'Cursor',
            'badge'         => '対応済み・mcp.json + OAuth 2.1',
            'unsupported'   => false,
            'summary'       => 'Cursor の `.cursor/mcp.json` にURLを記述すると、OAuth 2.1 フローにより自動でトークンを取得できます。',
            'steps'         => [
                'Cursor Settings →「Tools & MCP」を開くか、`~/.cursor/mcp.json` に下記を追記する。',
                '「Needs authentication / Connect」を押してブラウザでコトダマンDBへログイン・承認する。',
            ],
            'snippet_label' => '~/.cursor/mcp.json 設定例',
            'snippet'       => $json_http_config,
        ],
        'windsurf' => [
            'title'         => 'Windsurf（Cascade）',
            'badge'         => '対応済み・mcp_config.json',
            'unsupported'   => false,
            'summary'       => 'Windsurf の MCP Marketplace / `mcp_config.json` で Streamable HTTP URL を設定して利用できます。',
            'steps'         => [
                '`~/.codeium/windsurf/mcp_config.json` を開く。',
                '下記の通り `serverUrl` にコトダマンDBのMCP URLを追加し、リフレッシュする。',
            ],
            'snippet_label' => 'mcp_config.json 設定例',
            'snippet'       => wp_json_encode([
                'mcpServers' => [
                    'kotodaman-db' => [
                        'serverUrl' => $mcp_url,
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ],
        'cline_roo' => [
            'title'         => 'Cline / Roo Code',
            'badge'         => '対応済み・streamableHttp',
            'unsupported'   => false,
            'summary'       => 'VS Code 拡張の Cline / Roo Code では `streamableHttp` タイプのMCP設定で接続できます。',
            'steps'         => [
                'Cline / Roo Code の MCP Servers 設定アイコンから `cline_mcp_settings.json`（または `mcp_settings.json`）を開く。',
                '下記設定を追記し、認証プロンプトまたはBasic/Bearerヘッダーを設定する。',
            ],
            'snippet_label' => 'streamableHttp 設定例',
            'snippet'       => wp_json_encode([
                'mcpServers' => [
                    'kotodaman-db' => [
                        'type' => 'streamableHttp',
                        'url'  => $mcp_url,
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ],
        'codex_opencode' => [
            'title'         => 'OpenAI Codex CLI / OpenCode',
            'badge'         => '対応済み・config.toml / opencode.json',
            'unsupported'   => false,
            'summary'       => 'Codex CLI（TOML）と OpenCode（JSON）の両方がリモートHTTP MCP + OAuth 2.1に対応しています。',
            'steps'         => [
                'Codex CLI の場合は `~/.codex/config.toml` に下記を追記し、`codex mcp login kotodaman-db` を実行する。',
                'OpenCode の場合は `opencode.json` に `type: "remote"` としてURLを追記し、`opencode mcp auth kotodaman-db` を実行する。',
            ],
            'snippet_label' => 'Codex CLI (~/.codex/config.toml) 例',
            'snippet'       => "[mcp_servers.kotodaman_db]\nurl = \"{$mcp_url}\"\n",
        ],
        'jetbrains' => [
            'title'         => 'JetBrains IDE / AI Assistant',
            'badge'         => '対応済み・Streamable HTTP + OAuth',
            'unsupported'   => false,
            'summary'       => 'JetBrains IDE（IntelliJ / PhpStorm 等）の Settings → Tools → AI Assistant → Model Context Protocol から HTTP MCP を追加できます。',
            'steps'         => [
                'JetBrains Settings →「Tools」→「AI Assistant」→「Model Context Protocol (MCP)」を開く。',
                '「+」ボタンから下記JSONまたは URL（' . $mcp_url . '）を登録して「OK」を押す。',
                '認可リクエストが出たらブラウザでコトダマンDBにログインする。',
            ],
            'snippet_label' => 'JetBrains MCP JSON 設定例',
            'snippet'       => $json_type_http_config,
        ],
        'dify' => [
            'title'         => 'Dify（ワークフロー / エージェントMCPツール）',
            'badge'         => '対応済み・Static OAuth / HTTP MCP',
            'unsupported'   => false,
            'summary'       => 'Dify のツールプラグインまたはワークフローの MCP 連携から接続し、下書き作成ワークフローを自動化できます。',
            'steps'         => [
                'Dify の「ツール」→「MCP」→「MCPサーバーを追加（HTTP）」を開く。',
                'Server URL に「' . $mcp_url . '」を入力する。',
                'Dify側のバージョンに応じてOAuth認証またはStatic Client / アプリケーションパスワード（Basic認証ヘッダー）を指定する。',
            ],
            'snippet_label' => 'Dify MCP Server URL',
            'snippet'       => $mcp_url,
        ],
        'mcp_inspector' => [
            'title'         => 'MCP Inspector（開発者・動作確認用）',
            'badge'         => '対応済み・OAuth / Bearer テスト',
            'unsupported'   => false,
            'summary'       => '公式 MCP Inspector を使って、ツール一覧やスキーマ、OAuth認証フローをブラウザ上でテストできます。',
            'steps'         => [
                'ローカル環境等で `npx @modelcontextprotocol/inspector` を起動する。',
                'Transport Type に「Streamable HTTP」、URL に「' . $mcp_url . '」を入力する。',
                'Connect 後に「Quick OAuth Flow」を実行して動作確認する。',
            ],
            'snippet_label' => 'MCP Inspector 起動コマンド',
            'snippet'       => 'npx @modelcontextprotocol/inspector',
        ],
        'copilot_web_poe' => [
            'title'         => '個人向け Web版 Microsoft Copilot / Poe',
            'badge'         => '未対応（ツール側の仕様制約）',
            'unsupported'   => true,
            'summary'       => '個人向けコンシューマWeb版 Microsoft Copilot と Poe は、外部カスタムMCPや外部OAuth接続の受け口を公式提供していないため直接接続できません。',
            'steps'         => [
                'Microsoft 環境で接続したい場合は、開発者向けの「VS Code GitHub Copilot」または業務向け「Copilot Studio」をご利用ください。',
                '無料またはブラウザ単体でキャラクター下書き登録を行いたい場合は、「ChatGPT」「Claude」「Gemini CLI」のご利用をおすすめします。',
            ],
            'snippet_label' => '推奨の代替AIツール',
            'snippet'       => 'ChatGPT（コネクタ） / Claude（カスタムコネクタ） / VS Code GitHub Copilot / Gemini CLI',
        ],
    ];
}

/**
 * ショートコード経路等で wpautop がタグ間に <p> や <br> を挿入しないよう整形する
 * （<pre> と <script> の中身は改変しない）
 */
function koto_mcp_collab_strip_intertag_whitespace(string $html): string {
    $preserved = [];
    $masked = preg_replace_callback(
        '/<(pre|script)\b[^>]*>.*?<\/\1>/is',
        function ($m) use (&$preserved) {
            $key = '___KOTO_PRESERVE_' . count($preserved) . '___';
            $preserved[$key] = $m[0];
            return $key;
        },
        $html
    );
    if (!is_string($masked)) {
        return $html;
    }
    $compact = preg_replace('/>\s+</', '><', trim($masked));
    if (!is_string($compact)) {
        $compact = $masked;
    }
    return strtr($compact, $preserved);
}

/**
 * 協力者ポータルHTMLを生成して返す（ページテンプレート / ショートコード共通）
 *
 * @param array $args [
 *   'is_template' => bool,
 *   'page_title'  => string,
 * ]
 * @return string
 */
function koto_mcp_render_collaboration_portal(array $args = []): string {
    koto_mcp_collab_enqueue_assets();

    $is_template = !empty($args['is_template']);
    $page_title  = trim((string) ($args['page_title'] ?? ''));
    if ($page_title === '') {
        $page_title = 'コトダマンDB 編集協力・AI連携ポータル';
    }

    $notice = null;
    if (isset($_GET['koto_reg_notice'])) {
        $nk = sanitize_key((string) $_GET['koto_reg_notice']);
        $saved = get_transient('koto_mcp_collab_notice_' . $nk);
        if (is_array($saved) && !empty($saved['message'])) {
            $notice = $saved;
            delete_transient('koto_mcp_collab_notice_' . $nk);
        }
    }

    $is_logged_in = is_user_logged_in();
    $current_user = $is_logged_in ? wp_get_current_user() : null;
    $has_mcp_role = $current_user && (
        in_array('administrator', (array) $current_user->roles, true)
        || in_array('editor', (array) $current_user->roles, true)
        || in_array('mcp_user', (array) $current_user->roles, true)
    );
    $mcp_requested = $current_user ? (bool) get_user_meta($current_user->ID, '_koto_mcp_requested', true) : false;

    $portal_url    = koto_mcp_collab_current_url();
    $mcp_url       = koto_mcp_server_url();
    $rest_base     = koto_mcp_base_url();
    $openapi_url   = $rest_base . '/mcp/openapi.json';
    $system_prompt = koto_mcp_collab_default_system_prompt();
    $ai_guides     = koto_mcp_collab_ai_guides_data();

    $tool_buttons = [
        ['id' => 'chatgpt_mcp',            'cat' => 'chat', 'label' => 'ChatGPT（MCP）'],
        ['id' => 'chatgpt_actions',        'cat' => 'api',  'label' => 'ChatGPT（Actions）'],
        ['id' => 'claude',                 'cat' => 'chat', 'label' => 'Claude'],
        ['id' => 'claude_code',            'cat' => 'ide',  'label' => 'Claude Code'],
        ['id' => 'gemini_web',             'cat' => 'chat', 'label' => 'Gemini（Web）'],
        ['id' => 'gemini_cli',             'cat' => 'ide',  'label' => 'Gemini CLI'],
        ['id' => 'gemini_api_enterprise',  'cat' => 'api',  'label' => 'Gemini API / Enterprise'],
        ['id' => 'muse',                   'cat' => 'chat', 'label' => 'Muse'],
        ['id' => 'grok_perplexity_lechat', 'cat' => 'chat', 'label' => 'Grok / Perplexity / Le Chat'],
        ['id' => 'copilot_studio',         'cat' => 'api',  'label' => 'Copilot Studio'],
        ['id' => 'github_copilot',         'cat' => 'ide',  'label' => 'GitHub Copilot'],
        ['id' => 'antigravity',            'cat' => 'ide',  'label' => 'Antigravity'],
        ['id' => 'cursor',                 'cat' => 'ide',  'label' => 'Cursor'],
        ['id' => 'windsurf',               'cat' => 'ide',  'label' => 'Windsurf'],
        ['id' => 'cline_roo',              'cat' => 'ide',  'label' => 'Cline / Roo Code'],
        ['id' => 'codex_opencode',         'cat' => 'ide',  'label' => 'Codex CLI / OpenCode'],
        ['id' => 'jetbrains',              'cat' => 'ide',  'label' => 'JetBrains IDE'],
        ['id' => 'dify',                   'cat' => 'api',  'label' => 'Dify'],
        ['id' => 'mcp_inspector',          'cat' => 'api',  'label' => 'MCP Inspector'],
        ['id' => 'copilot_web_poe',        'cat' => 'chat', 'label' => 'MS Copilot(Web) / Poe（非対応）'],
    ];

    $hero_tag    = $is_template ? 'h1' : 'h2';
    $section_tag = $is_template ? 'h2' : 'h3';
    $card_tag    = $is_template ? 'h3' : 'h4';
    $guide_tag   = $is_template ? 'h3' : 'h4';
    $wrap_class  = $is_template ? 'koto-collab-portal koto-collab-portal-template' : 'koto-collab-portal koto-collab-portal-shortcode';

    ob_start();
    ?>
    <div class="<?php echo esc_attr($wrap_class); ?>">
        <div class="koto-collab-hero">
            <<?php echo $hero_tag; ?> class="koto-collab-hero-title"><?php echo esc_html($page_title); ?></<?php echo $hero_tag; ?>>
            <p class="koto-collab-hero-lead">キャラクター図鑑の編集協力方法のご案内、アカウント新規登録、および ChatGPT・Claude・Gemini・Muse・Copilot などの外部AIから下書き登録するための接続ガイドをまとめています。</p>
        </div>

        <?php if ($notice): ?>
            <div class="koto-collab-notice <?php echo $notice['type'] === 'success' ? 'koto-collab-notice-success' : 'koto-collab-notice-error'; ?>">
                <?php echo esc_html($notice['message']); ?>
            </div>
        <?php endif; ?>

        <section class="koto-collab-section" id="koto-collab-overview">
            <<?php echo $section_tag; ?> class="koto-collab-section-title"><span class="koto-collab-step-num">1</span><span>協力の方法を選ぶ</span></<?php echo $section_tag; ?>>
            <div class="koto-collab-cards">
                <div class="koto-collab-card">
                    <div class="koto-collab-card-body">
                        <<?php echo $card_tag; ?> class="koto-collab-card-title">不足キャラ一覧・Discordから協力</<?php echo $card_tag; ?>>
                        <p class="koto-collab-card-text">スクショや性能データの提供、不足キャラクターの情報提供から参加できます。まずはDiscordまたは未調査リストをご確認ください。</p>
                    </div>
                    <div class="koto-collab-card-actions">
                        <a class="koto-collab-btn koto-collab-btn-discord" href="https://discord.gg/cmjGCXe6u5" target="_blank" rel="noopener noreferrer">Discordで参加する</a>
                        <a class="koto-collab-btn koto-collab-btn-secondary" href="<?php echo esc_url(home_url('/mgn-blank-charas/')); ?>">情報不足キャラを見る</a>
                    </div>
                </div>
                <div class="koto-collab-card">
                    <div class="koto-collab-card-body">
                        <<?php echo $card_tag; ?> class="koto-collab-card-title">ユーザー登録・MCP利用申請</<?php echo $card_tag; ?>>
                        <p class="koto-collab-card-text">下のフォームからサイト上でアカウント登録ができます。管理者がMCP利用を承認すると、AIから直接下書き登録が可能になります。</p>
                    </div>
                    <div class="koto-collab-card-actions">
                        <a class="koto-collab-btn" href="#koto-collab-register">登録フォームへ進む</a>
                    </div>
                </div>
                <div class="koto-collab-card">
                    <div class="koto-collab-card-body">
                        <<?php echo $card_tag; ?> class="koto-collab-card-title">お使いのAIツールと連携する</<?php echo $card_tag; ?>>
                        <p class="koto-collab-card-text">ChatGPT / Claude / Gemini / Muse / GitHub Copilot / Cursor / Dify など主要AI別の接続手順と設定JSONを確認できます。</p>
                    </div>
                    <div class="koto-collab-card-actions">
                        <a class="koto-collab-btn koto-collab-btn-secondary" href="#koto-collab-ai-guide">AI接続ガイドを見る</a>
                    </div>
                </div>
            </div>
        </section>

        <section class="koto-collab-section" id="koto-collab-register">
            <<?php echo $section_tag; ?> class="koto-collab-section-title"><span class="koto-collab-step-num">2</span><span>協力者アカウント登録（メール認証付き）</span></<?php echo $section_tag; ?>>
            <?php if ($is_logged_in && $current_user): ?>
                <div class="koto-collab-logged-in-box">
                    <p>現在 <strong><?php echo esc_html($current_user->display_name); ?></strong>（<code><?php echo esc_html($current_user->user_login); ?></code>）としてログイン中です。</p>
                    <?php if ($has_mcp_role): ?>
                        <p>MCP利用権限：<strong>承認済み</strong>（各AIツールからOAuthログインして下書き作成が可能です）。</p>
                    <?php elseif ($mcp_requested): ?>
                        <p>MCP利用権限：<strong>承認待ち</strong>（管理者が承認すると通知メールが届きます）。</p>
                    <?php else: ?>
                        <p>MCPでの下書き登録を希望する場合は、WordPressプロフィール画面またはOAuthログイン時の申請ボタンから申請できます。</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="koto-collab-desc">新規登録時はセキュリティのため最小権限（購読者 / <code>subscriber</code>）として作成され、確認メール内のリンクをクリックした時点でアカウントが有効になります。「MCP利用も同時に申請する」にチェックを入れておくと、管理者の承認後にAIからの下書き登録が使えるようになります。</p>
                <form method="post" class="koto-collab-form" action="<?php echo esc_url($portal_url); ?>">
                    <?php wp_nonce_field('koto_collab_register', 'koto_collab_reg_nonce'); ?>
                    <input type="hidden" name="koto_reg_return_url" value="<?php echo esc_attr($portal_url); ?>">
                    <div class="koto-collab-form-grid">
                        <div class="koto-collab-form-row">
                            <label for="koto_reg_username">ユーザー名（必須・半角英数字3〜60文字）</label>
                            <input type="text" id="koto_reg_username" name="koto_reg_username" required minlength="3" maxlength="60" pattern="[A-Za-z0-9_.\-]+" autocomplete="username" placeholder="例: kotodaman_helper01">
                        </div>
                        <div class="koto-collab-form-row">
                            <label for="koto_reg_email">メールアドレス（必須・認証メール送信先）</label>
                            <input type="email" id="koto_reg_email" name="koto_reg_email" required autocomplete="email" placeholder="example@example.com">
                        </div>
                        <div class="koto-collab-form-row">
                            <label for="koto_reg_password">パスワード（必須・8文字以上）</label>
                            <input type="password" id="koto_reg_password" name="koto_reg_password" required minlength="8" autocomplete="new-password">
                        </div>
                        <div class="koto-collab-form-row">
                            <label for="koto_reg_password_confirm">パスワード確認（必須）</label>
                            <input type="password" id="koto_reg_password_confirm" name="koto_reg_password_confirm" required minlength="8" autocomplete="new-password">
                        </div>
                        <div class="koto-collab-form-row">
                            <label for="koto_reg_display_name">表示名（任意・未入力時はユーザー名）</label>
                            <input type="text" id="koto_reg_display_name" name="koto_reg_display_name" maxlength="60" placeholder="例: 編集協力者A">
                        </div>
                    </div>
                    <div class="koto-collab-hp-field" aria-hidden="true">
                        <label for="koto_reg_hp_url">Website</label>
                        <input type="text" id="koto_reg_hp_url" name="website_url" value="" tabindex="-1" autocomplete="off">
                    </div>
                    <label class="koto-collab-checkbox">
                        <input type="checkbox" name="koto_reg_request_mcp" value="1" checked>
                        <span>MCP利用（AIツールからのキャラクター下書き作成連携）も同時に申請する</span>
                    </label>
                    <p class="koto-collab-hint">※ 登録時点では公開記事の編集やサイト設定変更は行えません。広告の非表示特典は「管理者・編集者」または「公開記事を1件以上投稿したユーザー」にのみ適用されます。</p>
                    <div class="koto-collab-form-actions">
                        <button type="submit" name="koto_collab_reg_submit" value="1" class="koto-collab-btn koto-collab-btn-submit">確認メールを送信して登録する</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="koto-collab-section" id="koto-collab-ai-guide">
            <<?php echo $section_tag; ?> class="koto-collab-section-title"><span class="koto-collab-step-num">3</span><span>AIツール別 接続ガイド＆エンドポイント</span></<?php echo $section_tag; ?>>
            <div class="koto-endpoint-grid">
                <div class="koto-copy-block">
                    <div class="koto-copy-header">
                        <span>コトダマンDB MCP サーバーURL（共通）</span>
                        <button type="button" class="koto-copy-btn" data-copy-target="koto-mcp-endpoint-url">コピー</button>
                    </div>
                    <pre id="koto-mcp-endpoint-url" class="koto-code-pre"><?php echo esc_html($mcp_url); ?></pre>
                </div>
                <div class="koto-copy-block">
                    <div class="koto-copy-header">
                        <span>OpenAPI 3.1.0 スキーマ URL（REST連携用）</span>
                        <button type="button" class="koto-copy-btn" data-copy-target="koto-mcp-openapi-url">コピー</button>
                    </div>
                    <pre id="koto-mcp-openapi-url" class="koto-code-pre"><?php echo esc_html($openapi_url); ?></pre>
                </div>
            </div>

            <p class="koto-collab-sublead">お使いのAIツールを選択すると、必要な手順とコピー用設定が表示されます。</p>

            <div class="koto-ai-cat-bar" role="tablist" aria-label="AIツールカテゴリ">
                <button type="button" class="koto-ai-cat-btn is-active" data-category="all">すべて</button>
                <button type="button" class="koto-ai-cat-btn" data-category="chat">Web / チャットAI</button>
                <button type="button" class="koto-ai-cat-btn" data-category="ide">IDE / CLIエージェント</button>
                <button type="button" class="koto-ai-cat-btn" data-category="api">API / ノーコード / 検証</button>
            </div>

            <div class="koto-ai-tool-grid">
                <?php foreach ($tool_buttons as $tb): ?>
                    <button type="button"
                            class="koto-ai-tool-btn<?php echo $tb['id'] === 'chatgpt_mcp' ? ' is-active' : ''; ?>"
                            data-category="<?php echo esc_attr($tb['cat']); ?>"
                            data-tool="<?php echo esc_attr($tb['id']); ?>"><?php echo esc_html($tb['label']); ?></button>
                <?php endforeach; ?>
            </div>

            <div class="koto-ai-guide-panel">
                <div class="koto-ai-guide-header">
                    <<?php echo $guide_tag; ?> id="koto-ai-guide-title" class="koto-ai-guide-title"></<?php echo $guide_tag; ?>>
                    <span id="koto-ai-guide-badge" class="koto-ai-badge"></span>
                </div>
                <p id="koto-ai-guide-summary" class="koto-ai-guide-summary"></p>
                <ol id="koto-ai-guide-steps" class="koto-ai-steps"></ol>
                <div id="koto-ai-guide-snippet-wrap" class="koto-copy-block" hidden>
                    <div class="koto-copy-header">
                        <span id="koto-ai-guide-snippet-label"></span>
                        <button type="button" class="koto-copy-btn" data-copy-target="koto-ai-guide-snippet-code">コピー</button>
                    </div>
                    <pre id="koto-ai-guide-snippet-code" class="koto-code-pre"></pre>
                </div>
            </div>

            <div class="koto-copy-block koto-copy-block-spaced">
                <div class="koto-copy-header">
                    <span>AIへの共通システムプロンプト例（コピペ推奨）</span>
                    <button type="button" class="koto-copy-btn" data-copy-target="koto-mcp-system-prompt">コピー</button>
                </div>
                <pre id="koto-mcp-system-prompt" class="koto-code-pre"><?php echo esc_html($system_prompt); ?></pre>
            </div>

            <script type="application/json" id="koto-ai-guide-data"><?php echo wp_json_encode($ai_guides, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
        </section>
    </div>
    <?php
    return koto_mcp_collab_strip_intertag_whitespace((string) ob_get_clean());
}

/**
 * ショートコード [koto_collaborate_portal] レンダリング（後方互換維持）
 */
add_shortcode('koto_collaborate_portal', function (): string {
    // 【一時非公開メモ】MCP協力者募集の一時非公開化に伴いショートコード描画をコメントアウト（処理自体は残す）。
    // 再公開時は下のコメントアウトを解除し、return ''; を削除すること。
    // return koto_mcp_render_collaboration_portal([
    //     'is_template' => false,
    // ]);
    return '';
});
