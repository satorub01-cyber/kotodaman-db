<?php
/**
 * 協力者ポータル＆ユーザー登録モジュール
 *
 * - 固定ページテンプレート（collaboration-page.php）およびショートコード [koto_collaborate_portal] の共通描画
 * - メール認証付きセルフユーザー登録（認証完了と同時に checker ロールとして作成）
 * - AI-assistant 下書きチェック・DBエディタ・倍率調査への導線および実績・広告非表示ステータス表示
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
        'status'     => 'pending_verification',
        'email'      => $email,
        'username'   => $username,
        'expires_at' => $expires_at,
        'verify_url' => $verify_url,
        'token'      => $token,
    ];
}

/**
 * メール認証トークンを検証し、WordPressユーザー（checker）を作成する
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

    if (username_exists($username) || email_exists($email)) {
        return new WP_Error('koto_reg_already_registered', 'このユーザー名またはメールアドレスは既に登録済みです。');
    }

    if (function_exists('koto_register_checker_role')) {
        koto_register_checker_role();
    }

    // checker ロールとしてユーザー作成（承認不要）
    $user_id = wp_insert_user([
        'user_login'   => $username,
        'user_pass'    => wp_generate_password(32, true, true),
        'user_email'   => $email,
        'display_name' => $display_name,
        'role'         => 'checker',
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

    return [
        'status'   => 'verified',
        'user_id'  => (int) $user_id,
        'username' => $username,
        'email'    => $email,
        'role'     => 'checker',
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
 */
add_action('template_redirect', function (): void {
    // 1. メール認証GET (?koto_reg_verify=...)
    if (isset($_GET['koto_reg_verify'])) {
        $token = sanitize_text_field(wp_unslash((string) $_GET['koto_reg_verify']));
        $result = koto_mcp_collab_verify_registration($token);
        if (is_wp_error($result)) {
            $nk = koto_mcp_collab_store_notice('error', $result->get_error_message());
        } else {
            $msg = 'メールアドレスの認証が完了し、チェッカーアカウント（' . $result['username'] . '）を作成しました。ログイン後すぐにAI下書きのチェックや倍率調査にご協力いただけます。';
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
        $page_title = 'コトダマンDB 編集協力ポータル';
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
    $checked_count = ($current_user && function_exists('koto_user_get_checked_count'))
        ? koto_user_get_checked_count((int) $current_user->ID)
        : 0;
    $ads_hidden = ($current_user && function_exists('koto_user_can_hide_ads'))
        ? koto_user_can_hide_ads((int) $current_user->ID)
        : false;
    $is_checker_role = ($current_user && function_exists('koto_user_is_checker'))
        ? koto_user_is_checker($current_user)
        : false;

    $portal_url       = koto_mcp_collab_current_url();
    $db_editor_url    = admin_url('admin.php?page=koto-acf-editor');
    $draft_list_url   = admin_url('edit.php?post_type=character&post_status=draft');
    $blank_charas_url = home_url('/mgn-blank-charas/');
    $calc_url         = home_url('/magnification-calc/');

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
            <p class="koto-collab-hero-lead">AI-assistant が作成したキャラクター下書きのチェック・公開作業、攻撃・回復倍率の調査協力、およびチェッカー（<code>checker</code>）アカウントの新規登録手順をまとめています。</p>
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
                        <<?php echo $card_tag; ?> class="koto-collab-card-title">AI下書きのチェック＆公開</<?php echo $card_tag; ?>>
                        <p class="koto-collab-card-text">AI-assistant が作成したキャラクター下書きを DBエディタで確認し、問題なければチェック完了・公開する作業です。1件以上のチェックで広告が非表示になります。</p>
                    </div>
                    <div class="koto-collab-card-actions">
                        <?php if ($is_logged_in): ?>
                            <a class="koto-collab-btn" href="<?php echo esc_url($db_editor_url); ?>">DBエディタを開く</a>
                            <a class="koto-collab-btn koto-collab-btn-secondary" href="<?php echo esc_url($draft_list_url); ?>">下書きキャラ一覧を見る</a>
                        <?php else: ?>
                            <a class="koto-collab-btn" href="#koto-collab-register">チェッカー登録して始める</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="koto-collab-card">
                    <div class="koto-collab-card-body">
                        <<?php echo $card_tag; ?> class="koto-collab-card-title">倍率調査・Discordから協力</<?php echo $card_tag; ?>>
                        <p class="koto-collab-card-text">攻撃・回復倍率が未調査のキャラクターの調査や、計算ツールを使った倍率の算出・スクリーンショット提供からご参加いただけます。</p>
                    </div>
                    <div class="koto-collab-card-actions">
                        <a class="koto-collab-btn koto-collab-btn-secondary" href="<?php echo esc_url($blank_charas_url); ?>">倍率未調査キャラを見る</a>
                        <a class="koto-collab-btn" href="<?php echo esc_url($calc_url); ?>">倍率計算機を使う</a>
                        <a class="koto-collab-btn koto-collab-btn-discord" href="https://discord.gg/cmjGCXe6u5" target="_blank" rel="noopener noreferrer">Discordで参加する</a>
                    </div>
                </div>
                <div class="koto-collab-card">
                    <div class="koto-collab-card-body">
                        <<?php echo $card_tag; ?> class="koto-collab-card-title">作業の流れを確認する</<?php echo $card_tag; ?>>
                        <p class="koto-collab-card-text">DBエディタでの確認ポイントや、「確認完了にする」フラグの操作方法、倍率調査の進め方をステップごとに解説しています。</p>
                    </div>
                    <div class="koto-collab-card-actions">
                        <a class="koto-collab-btn koto-collab-btn-secondary" href="#koto-collab-check-guide">チェック・調査手順を見る</a>
                    </div>
                </div>
            </div>
        </section>

        <section class="koto-collab-section" id="koto-collab-register">
            <<?php echo $section_tag; ?> class="koto-collab-section-title"><span class="koto-collab-step-num">2</span><span>チェッカーアカウント登録（承認不要・メール認証）</span></<?php echo $section_tag; ?>>
            <?php if ($is_logged_in && $current_user): ?>
                <div class="koto-collab-logged-in-box">
                    <p>現在 <strong><?php echo esc_html($current_user->display_name); ?></strong>（<code><?php echo esc_html($current_user->user_login); ?></code>）としてログイン中です。</p>
                    <div class="koto-collab-status-row">
                        <span class="koto-collab-status-badge">チェック実績: <?php echo esc_html((string) $checked_count); ?>件</span>
                        <?php if ($ads_hidden): ?>
                            <span class="koto-collab-status-badge is-ad-free">広告表示: 非表示（特典適用中）</span>
                        <?php elseif ($is_checker_role): ?>
                            <span class="koto-collab-status-badge is-ads-shown">広告表示: 表示中（あと1件チェック完了で非表示）</span>
                        <?php else: ?>
                            <span class="koto-collab-status-badge is-ads-shown">広告表示: 通常表示</span>
                        <?php endif; ?>
                    </div>
                    <div class="koto-collab-quick-actions">
                        <a class="koto-collab-btn" href="<?php echo esc_url($db_editor_url); ?>">DBエディタを開く</a>
                        <a class="koto-collab-btn koto-collab-btn-secondary" href="<?php echo esc_url($draft_list_url); ?>">下書きキャラ一覧</a>
                        <a class="koto-collab-btn koto-collab-btn-secondary" href="<?php echo esc_url($blank_charas_url); ?>">倍率未調査キャラ</a>
                        <a class="koto-collab-btn" href="<?php echo esc_url($calc_url); ?>">簡易ダメージ・倍率計算機</a>
                    </div>
                </div>
            <?php else: ?>
                <p class="koto-collab-desc">フォーム送信後に届く確認メール内のリンクをクリックすると、承認待ちなしで自動的にチェッカー（<code>checker</code>）アカウントが作成されます。AI-assistant が作成した下書きの編集・公開や、ご自身のキャラクター投稿を行えます。</p>
                <form method="post" class="koto-collab-form" action="<?php echo esc_url($portal_url); ?>">
                    <?php wp_nonce_field('koto_collab_register', 'koto_collab_reg_nonce'); ?>
                    <input type="hidden" name="koto_reg_return_url" value="<?php echo esc_attr($portal_url); ?>">
                    <div class="koto-collab-form-grid">
                        <div class="koto-collab-form-row">
                            <label for="koto_reg_username">ユーザー名（必須・半角英数字3〜60文字）</label>
                            <input type="text" id="koto_reg_username" name="koto_reg_username" required minlength="3" maxlength="60" pattern="[A-Za-z0-9_.\-]+" autocomplete="username" placeholder="例: kotodaman_checker01">
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
                    <p class="koto-collab-hint">※ 登録後、AI-assistant の下書きまたは自分の投稿のみ編集・公開できます（他ユーザーが作成した記事の編集やサイト設定変更は行えません）。1件以上のチェック実績（要確認フラグの解除）がある <code>checker</code> にはサイト内広告が表示されなくなります。</p>
                    <div class="koto-collab-form-actions">
                        <button type="submit" name="koto_collab_reg_submit" value="1" class="koto-collab-btn koto-collab-btn-submit">確認メールを送信して登録する</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="koto-collab-section" id="koto-collab-check-guide">
            <<?php echo $section_tag; ?> class="koto-collab-section-title"><span class="koto-collab-step-num">3</span><span>AI下書きチェック・倍率調査の手順</span></<?php echo $section_tag; ?>>
            <div class="koto-collab-guide-grid">
                <div class="koto-collab-guide-card">
                    <<?php echo $guide_tag; ?> class="koto-collab-guide-card-title">AI-assistant 下書きのチェック手順</<?php echo $guide_tag; ?>>
                    <ol class="koto-ai-steps">
                        <li>ログイン後、管理画面の「<a href="<?php echo esc_url($draft_list_url); ?>">キャラクター下書き一覧</a>」または「<a href="<?php echo esc_url($db_editor_url); ?>">DBエディタ</a>」を開きます。</li>
                        <li>AI-assistant が作成した下書きを選択し、ゲーム内の性能（ステータス・わざ・すごわざ・とくせい・祝福など）と入力内容に誤りがないか確認・修正します。</li>
                        <li>内容の確認が終わったら、DBエディタ上部の「確認完了にする」ボタン（または「公開 / 更新」ボタン）を押して要確認フラグを下げます。</li>
                        <li>要確認フラグを1記事でも下げるとチェック実績が記録され、<code>checker</code> ユーザーはサイト内の広告が非表示になります（実績は1記事につき1回までカウントされます）。</li>
                    </ol>
                </div>
                <div class="koto-collab-guide-card">
                    <<?php echo $guide_tag; ?> class="koto-collab-guide-card-title">攻撃・回復倍率の調査手順</<?php echo $guide_tag; ?>>
                    <ol class="koto-ai-steps">
                        <li>「<a href="<?php echo esc_url($blank_charas_url); ?>">倍率未調査キャラ一覧</a>」から倍率が未入力のキャラクターを探します。</li>
                        <li>実戦でのダメージ数値やスクショをもとに「<a href="<?php echo esc_url($calc_url); ?>">簡易ダメージ・倍率計算機</a>」で倍率を逆算・検算します。</li>
                        <li>算出した倍率や検証結果のスクリーンショットは、<a href="https://discord.gg/cmjGCXe6u5" target="_blank" rel="noopener noreferrer">Discordサーバー</a>での報告または DBエディタでの直接入力にお役立てください。</li>
                    </ol>
                </div>
            </div>
        </section>
    </div>
    <?php
    return koto_mcp_collab_strip_intertag_whitespace((string) ob_get_clean());
}

/**
 * ショートコード [koto_collaborate_portal] レンダリング（後方互換維持）
 */
add_shortcode('koto_collaborate_portal', function (): string {
    return koto_mcp_render_collaboration_portal([
        'is_template' => false,
    ]);
});
