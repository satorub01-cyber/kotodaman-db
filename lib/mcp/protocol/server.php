<?php
if (!defined('ABSPATH')) exit;

/**
 * コトダマンDB MCP (Model Context Protocol) サーバー本体
 *
 * 各責務のモジュールを読み込み、Streamable HTTP (JSON-RPC 2.0) エンドポイント
 * （/wp-json/kotodaman/v1/mcp）および OAuth エンドポイントを提供します。
 */

$koto_mcp_root = dirname(__DIR__);
require_once $koto_mcp_root . '/auth/mcp-core.php';
require_once $koto_mcp_root . '/auth/mcp-auth.php';
require_once $koto_mcp_root . '/auth/mcp-oauth.php';
require_once __DIR__ . '/mcp-schema.php';
require_once __DIR__ . '/mcp-draft.php';
require_once __DIR__ . '/mcp-resources.php';
require_once __DIR__ . '/mcp-tools.php';
require_once $koto_mcp_root . '/portal/mcp-admin.php';
require_once $koto_mcp_root . '/portal/mcp-collaboration-page.php';

add_action('rest_api_init', 'koto_mcp_register_server_routes');

function koto_mcp_register_server_routes()
{
    register_rest_route('kotodaman/v1', '/mcp', [
        'methods' => WP_REST_Server::ALLMETHODS,
        'callback' => 'koto_mcp_handle_http',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('kotodaman/v1', '/mcp/oauth/register', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'koto_mcp_oauth_register_client',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('kotodaman/v1', '/mcp/oauth/authorize', [
        'methods' => WP_REST_Server::ALLMETHODS,
        'callback' => 'koto_mcp_oauth_authorize_rest',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('kotodaman/v1', '/mcp/oauth/token', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'koto_mcp_oauth_token',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('kotodaman/v1', '/mcp/oauth/revoke', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'koto_mcp_oauth_revoke',
        'permission_callback' => '__return_true',
    ]);
}

/**
 * リクエストの Origin ヘッダーを検証する（DNS Rebinding・不正オリジン防御）。
 *
 * @param WP_REST_Request|null $request
 * @return bool
 */
function koto_mcp_validate_origin($request = null)
{
    $origin = '';
    if ($request instanceof WP_REST_Request) {
        $origin = trim((string) $request->get_header('origin'));
    }
    if ($origin === '' && !empty($_SERVER['HTTP_ORIGIN'])) {
        $origin = trim((string) $_SERVER['HTTP_ORIGIN']);
    }
    if ($origin === '') {
        return (bool) apply_filters('koto_mcp_is_allowed_origin', true, '', $request);
    }
    if (strtolower($origin) === 'null') {
        return (bool) apply_filters('koto_mcp_is_allowed_origin', false, $origin, $request);
    }

    $parsed = wp_parse_url($origin);
    if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
        return (bool) apply_filters('koto_mcp_is_allowed_origin', false, $origin, $request);
    }

    $scheme = strtolower((string) $parsed['scheme']);
    $origin_host = strtolower(trim((string) $parsed['host'], '[]'));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return (bool) apply_filters('koto_mcp_is_allowed_origin', false, $origin, $request);
    }

    $loopbacks = ['localhost', '127.0.0.1', '::1'];
    $site_host = strtolower(trim((string) wp_parse_url(home_url('/'), PHP_URL_HOST), '[]'));
    $is_origin_loopback = in_array($origin_host, $loopbacks, true);
    $is_same_site_host = ($site_host !== '' && $origin_host === $site_host);

    if (filter_var($origin_host, FILTER_VALIDATE_IP) && !$is_origin_loopback && !$is_same_site_host) {
        if (function_exists('koto_mcp_oauth_is_private_or_reserved_ip') && koto_mcp_oauth_is_private_or_reserved_ip($origin_host)) {
            return (bool) apply_filters('koto_mcp_is_allowed_origin', false, $origin, $request);
        }
    }

    if ($scheme === 'http' && !$is_origin_loopback && !$is_same_site_host) {
        return (bool) apply_filters('koto_mcp_is_allowed_origin', false, $origin, $request);
    }

    $http_host = isset($_SERVER['HTTP_HOST']) ? strtolower(trim((string) preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']), '[]')) : '';
    if ($http_host !== '' && in_array($http_host, $loopbacks, true) && !$is_origin_loopback && !$is_same_site_host) {
        return (bool) apply_filters('koto_mcp_is_allowed_origin', false, $origin, $request);
    }

    return (bool) apply_filters('koto_mcp_is_allowed_origin', true, $origin, $request);
}

/**
 * CORS および MCP プロトコル関連のレスポンスヘッダーを付与する。
 *
 * @param WP_REST_Response $response
 * @param WP_REST_Request|null $request
 * @param string $protocol_version
 * @return WP_REST_Response
 */
function koto_mcp_apply_cors_headers($response, $request = null, $protocol_version = '')
{
    if (!$response instanceof WP_REST_Response) {
        return $response;
    }
    $origin = '';
    if ($request instanceof WP_REST_Request) {
        $origin = trim((string) $request->get_header('origin'));
    }
    if ($origin === '' && !empty($_SERVER['HTTP_ORIGIN'])) {
        $origin = trim((string) $_SERVER['HTTP_ORIGIN']);
    }

    if ($origin !== '') {
        $response->header('Access-Control-Allow-Origin', $origin);
        $response->header('Vary', 'Origin');
        $response->header('Access-Control-Allow-Credentials', 'true');
    } else {
        $response->header('Access-Control-Allow-Origin', '*');
    }
    $response->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
    $response->header('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, MCP-Protocol-Version, Mcp-Session-Id');
    $response->header('Access-Control-Expose-Headers', 'WWW-Authenticate, MCP-Protocol-Version, Mcp-Session-Id');
    $response->header('Access-Control-Max-Age', '86400');
    if ($protocol_version !== '') {
        $response->header('MCP-Protocol-Version', $protocol_version);
    }
    return $response;
}

/**
 * 認証／権限エラーに対する JSON-RPC エラーレスポンスを組み立てる。
 *
 * @param mixed $id
 * @param WP_Error $err
 * @return WP_REST_Response
 */
function koto_mcp_build_auth_error_response($id, WP_Error $err)
{
    $detail = $err->get_error_data();
    $status = is_array($detail) && isset($detail['status']) ? (int) $detail['status'] : 401;
    $headers = [];
    if ($status === 401) {
        $headers['WWW-Authenticate'] = koto_mcp_www_authenticate_header(
            is_array($detail) ? ($detail['oauth_error'] ?? 'invalid_token') : 'invalid_token',
            $err->get_error_message()
        );
    } elseif ($status === 403 && is_array($detail) && ($detail['oauth_error'] ?? '') === 'insufficient_scope') {
        $headers['WWW-Authenticate'] = koto_mcp_www_authenticate_header(
            'insufficient_scope',
            $err->get_error_message(),
            (string) ($detail['required_scope'] ?? '')
        );
    }
    return koto_mcp_jsonrpc_error_response($id, -32000, $err->get_error_message(), [
        'code' => $err->get_error_code(),
        'reason' => is_array($detail) && !empty($detail['reason_code']) ? (string) $detail['reason_code'] : ($err->get_error_code() === 'mcp_forbidden' ? 'insufficient_scope' : 'token_invalid'),
        'detail' => $detail,
    ], $status, $headers);
}

/**
 * 単一の JSON-RPC 2.0 メッセージを処理する。
 *
 * @param array<string, mixed> $body
 * @param WP_REST_Request $request
 * @param string $negotiated_version
 * @return array{type: string, data?: mixed, response?: WP_REST_Response}
 */
function koto_mcp_handle_single_jsonrpc(array $body, $request, &$negotiated_version)
{
    $method = $body['method'] ?? '';
    $id = array_key_exists('id', $body) ? $body['id'] : null;
    $is_notification = !array_key_exists('id', $body);

    if (!is_string($method) || $method === '') {
        return ['type' => 'json', 'data' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32600, 'message' => 'Invalid Request: method is required.']]];
    }

    if ($method === 'initialize') {
        $req_ver = isset($body['params']['protocolVersion']) && is_string($body['params']['protocolVersion'])
            ? $body['params']['protocolVersion']
            : '';
        $negotiated_version = koto_mcp_negotiate_protocol_version($req_ver);
        return [
            'type' => 'json',
            'data' => [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'protocolVersion' => $negotiated_version,
                    'capabilities' => [
                        'tools' => ['listChanged' => false],
                        'resources' => ['listChanged' => false],
                        'prompts' => ['listChanged' => false],
                    ],
                    'serverInfo' => ['name' => 'kotodaman-db', 'version' => '1.2.0'],
                    'instructions' => koto_mcp_instructions_text(),
                ],
            ],
        ];
    }
    if ($method === 'ping') {
        return ['type' => 'json', 'data' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()]];
    }
    if ($method === 'notifications/initialized' || strpos($method, 'notifications/') === 0) {
        return ['type' => 'accepted'];
    }
    if ($method === 'tools/list') {
        return ['type' => 'json', 'data' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => koto_mcp_tools()]]];
    }
    if ($method === 'resources/list') {
        return ['type' => 'json', 'data' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resources' => koto_mcp_resources_list()]]];
    }
    if ($method === 'resources/read') {
        $uri = (string) ($body['params']['uri'] ?? '');
        $res = koto_mcp_read_resource($uri);
        if (is_wp_error($res)) {
            return ['type' => 'response', 'response' => koto_mcp_jsonrpc_error_response($id, -32602, $res->get_error_message())];
        }
        return ['type' => 'json', 'data' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $res]];
    }
    if ($method === 'prompts/list') {
        return ['type' => 'json', 'data' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['prompts' => koto_mcp_prompts_list()]]];
    }
    if ($method === 'prompts/get') {
        $prompt_name = (string) ($body['params']['name'] ?? '');
        $prompt_args = is_array($body['params']['arguments'] ?? null) ? $body['params']['arguments'] : [];
        $prompt = koto_mcp_get_prompt($prompt_name, $prompt_args);
        if (is_wp_error($prompt)) {
            return ['type' => 'response', 'response' => koto_mcp_jsonrpc_error_response($id, -32602, $prompt->get_error_message())];
        }
        return ['type' => 'json', 'data' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $prompt]];
    }
    if ($method !== 'tools/call') {
        if ($is_notification) {
            return ['type' => 'accepted'];
        }
        return ['type' => 'json', 'data' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]];
    }

    $name = $body['params']['name'] ?? '';
    $arguments = $body['params']['arguments'] ?? [];
    if (!is_string($name) || $name === '' || !in_array($name, koto_mcp_tool_names(), true)) {
        return ['type' => 'response', 'response' => koto_mcp_jsonrpc_error_response($id, -32602, 'Invalid tool name.')];
    }
    $validation = koto_mcp_validate_tool_arguments($name, $arguments);
    if (is_wp_error($validation)) {
        return ['type' => 'response', 'response' => koto_mcp_jsonrpc_error_response($id, -32602, $validation->get_error_message(), [
            'code' => $validation->get_error_code(),
            'detail' => $validation->get_error_data(),
        ])];
    }

    $tool = koto_mcp_call_tool($name, $arguments, $request);
    if (is_wp_error($tool)) {
        $code = $tool->get_error_code();
        $detail = $tool->get_error_data();
        $status = is_array($detail) && isset($detail['status']) ? (int) $detail['status'] : 200;

        // 認証・スコープ不足・レート制限エラーは HTTP / JSON-RPC エラーとして返す
        if (in_array($status, [401, 429], true) || in_array($code, ['mcp_unauthorized', 'mcp_insecure_transport', 'mcp_forbidden', 'mcp_pending_approval', 'RATE_LIMITED', 'mcp_rate_limited'], true)) {
            return ['type' => 'response', 'response' => koto_mcp_build_auth_error_response($id, $tool)];
        }
        if ($code === 'INVALID_PARAMS' || $code === 'INVALID_TOOL') {
            return ['type' => 'response', 'response' => koto_mcp_jsonrpc_error_response($id, -32602, $tool->get_error_message(), [
                'code' => $code,
                'detail' => $detail,
            ])];
        }

        // 業務エラー（VALIDATION_FAILED, PREVIEW_HASH_MISMATCH, NOT_FOUND 等）は MCP 仕様通り isError: true で返す
        return [
            'type' => 'json',
            'data' => [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'isError' => true,
                    'content' => [[
                        'type' => 'text',
                        'text' => wp_json_encode([
                            'error' => $code,
                            'message' => $tool->get_error_message(),
                            'detail' => $detail,
                        ], JSON_UNESCAPED_UNICODE),
                    ]],
                ],
            ],
        ];
    }

    return [
        'type' => 'json',
        'data' => [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [[
                    'type' => 'text',
                    'text' => wp_json_encode($tool, JSON_UNESCAPED_UNICODE),
                ]],
            ],
        ],
    ];
}

function koto_mcp_handle_http($request)
{
    if (!koto_mcp_validate_origin($request)) {
        return koto_mcp_apply_cors_headers(
            koto_mcp_jsonrpc_error_response(null, -32000, 'Forbidden origin.', ['code' => 'forbidden_origin'], 403),
            $request
        );
    }

    $header_protocol_version = '';
    if ($request instanceof WP_REST_Request) {
        $header_protocol_version = trim((string) $request->get_header('mcp_protocol_version'));
        $http_method = strtoupper($request->get_method());
        if ($http_method === 'OPTIONS') {
            return koto_mcp_apply_cors_headers(
                new WP_REST_Response(null, 204, ['Allow' => 'POST, GET, OPTIONS']),
                $request,
                $header_protocol_version !== '' && in_array($header_protocol_version, KOTO_MCP_SUPPORTED_PROTOCOL_VERSIONS, true) ? $header_protocol_version : KOTO_MCP_PROTOCOL_VERSION
            );
        }
        if ($http_method === 'GET') {
            $get_auth = koto_mcp_require_scope('validate', $request);
            if (is_wp_error($get_auth)) {
                return koto_mcp_apply_cors_headers(koto_mcp_build_auth_error_response(null, $get_auth), $request);
            }
            return koto_mcp_apply_cors_headers(
                new WP_REST_Response(null, 405, ['Allow' => 'POST, GET, OPTIONS']),
                $request,
                $header_protocol_version !== '' ? $header_protocol_version : KOTO_MCP_PROTOCOL_VERSION
            );
        }
        if ($http_method !== 'POST') {
            return koto_mcp_apply_cors_headers(
                new WP_REST_Response(null, 405, ['Allow' => 'POST, GET, OPTIONS']),
                $request
            );
        }
    }

    if ($header_protocol_version !== '' && !in_array($header_protocol_version, KOTO_MCP_SUPPORTED_PROTOCOL_VERSIONS, true)) {
        return koto_mcp_apply_cors_headers(
            koto_mcp_jsonrpc_error_response(null, -32600, 'Unsupported MCP-Protocol-Version header.', ['supported' => KOTO_MCP_SUPPORTED_PROTOCOL_VERSIONS], 400),
            $request
        );
    }

    $raw_body = (string) $request->get_body();
    if (strlen($raw_body) > KOTO_MCP_MAX_BODY_BYTES) {
        return koto_mcp_apply_cors_headers(
            koto_mcp_jsonrpc_error_response(null, -32600, 'Request payload too large (max 256KB).', ['reason' => 'payload_too_large'], 413),
            $request
        );
    }
    $body = json_decode($raw_body, true);
    if (!is_array($body)) {
        return koto_mcp_apply_cors_headers(
            koto_mcp_jsonrpc_error_response(null, -32700, 'Parse error: JSON-RPC body is required.', null, 400),
            $request
        );
    }

    $is_batch = array_keys($body) === range(0, count($body) - 1);
    if ($is_batch && empty($body)) {
        return koto_mcp_apply_cors_headers(
            koto_mcp_jsonrpc_error_response(null, -32600, 'Invalid Request: empty JSON-RPC batch.', null, 400),
            $request
        );
    }
    if ($is_batch && count($body) > 20) {
        return koto_mcp_apply_cors_headers(
            koto_mcp_jsonrpc_error_response(null, -32600, 'Batch exceeds maximum size (20 requests).', null, 400),
            $request
        );
    }

    $first_id = !$is_batch ? ($body['id'] ?? null) : null;

    // MCP Streamable HTTP 仕様準拠: 全ての JSON-RPC メソッド（initialize, tools/list, tools/call 等）で認証を必須化。
    // トークン未指定または期限切れ・無効時は即座に 401 Unauthorized + WWW-Authenticate チャレンジを返信し、
    // クライアント側（Claude / Cursor 等）の OAuth 自動リフレッシュフローを確実に起動させる。
    $early_auth = koto_mcp_require_scope('validate', $request);
    if (is_wp_error($early_auth)) {
        return koto_mcp_apply_cors_headers(koto_mcp_build_auth_error_response($first_id, $early_auth), $request);
    }

    $negotiated_version = $header_protocol_version !== '' ? $header_protocol_version : KOTO_MCP_PROTOCOL_VERSION;

    if (!$is_batch) {
        $handled = koto_mcp_handle_single_jsonrpc($body, $request, $negotiated_version);
        if ($handled['type'] === 'accepted') {
            return koto_mcp_apply_cors_headers(new WP_REST_Response(null, 202), $request, $negotiated_version);
        }
        if ($handled['type'] === 'response') {
            return koto_mcp_apply_cors_headers($handled['response'], $request, $negotiated_version);
        }
        return koto_mcp_apply_cors_headers(rest_ensure_response($handled['data']), $request, $negotiated_version);
    }

    $batch_results = [];
    foreach ($body as $item) {
        if (!is_array($item)) {
            $batch_results[] = ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid Request.']];
            continue;
        }
        $handled = koto_mcp_handle_single_jsonrpc($item, $request, $negotiated_version);
        if ($handled['type'] === 'accepted') {
            continue;
        }
        if ($handled['type'] === 'response' && $handled['response'] instanceof WP_REST_Response) {
            if ($handled['response']->get_status() >= 400) {
                return koto_mcp_apply_cors_headers($handled['response'], $request, $negotiated_version);
            }
            $batch_results[] = $handled['response']->get_data();
            continue;
        }
        $batch_results[] = $handled['data'];
    }

    if (empty($batch_results)) {
        return koto_mcp_apply_cors_headers(new WP_REST_Response(null, 202), $request, $negotiated_version);
    }
    return koto_mcp_apply_cors_headers(rest_ensure_response($batch_results), $request, $negotiated_version);
}
