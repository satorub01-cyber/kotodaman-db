<?php
if (!defined('ABSPATH')) exit;

/**
 * コトダマンDB MCP (Model Context Protocol) サーバー本体
 *
 * 各責務のモジュールを読み込み、Streamable HTTP (JSON-RPC 2.0) エンドポイント
 * （/wp-json/kotodaman/v1/mcp）および OAuth エンドポイントを提供します。
 */

require_once __DIR__ . '/mcp-core.php';
require_once __DIR__ . '/mcp-auth.php';
require_once __DIR__ . '/mcp-oauth.php';
require_once __DIR__ . '/mcp-schema.php';
require_once __DIR__ . '/mcp-draft.php';
require_once __DIR__ . '/mcp-resources.php';
require_once __DIR__ . '/mcp-tools.php';
require_once __DIR__ . '/mcp-admin.php';

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

function koto_mcp_handle_http($request)
{
    if ($request instanceof WP_REST_Request) {
        $http_method = strtoupper($request->get_method());
        if ($http_method === 'OPTIONS') {
            return new WP_REST_Response(null, 204, ['Allow' => 'POST, OPTIONS']);
        }
        if ($http_method !== 'POST') {
            return new WP_REST_Response(null, 405, ['Allow' => 'POST, OPTIONS']);
        }
    }
    $raw_body = (string) $request->get_body();
    if (strlen($raw_body) > KOTO_MCP_MAX_BODY_BYTES) {
        return koto_mcp_jsonrpc_error_response(null, -32600, 'Request payload too large (max 256KB).', ['reason' => 'payload_too_large'], 413);
    }
    $body = json_decode($raw_body, true);
    if (!is_array($body)) return new WP_Error('invalid_json', 'JSON-RPC body is required.', ['status' => 400]);
    $method = $body['method'] ?? '';
    $id = $body['id'] ?? null;

    // Authorization ヘッダーが明示送信されている場合は、initialize 等でもトークン有効性を検証して期限切れ時に 401 + WWW-Authenticate を返す
    $auth_header = koto_mcp_get_authorization_header($request);
    if ($auth_header !== '' && in_array($method, ['initialize', 'tools/list', 'resources/list', 'prompts/list'], true)) {
        $early_auth = koto_mcp_require_scope('validate', $request);
        if (is_wp_error($early_auth)) {
            $detail = $early_auth->get_error_data();
            $status = is_array($detail) && isset($detail['status']) ? (int) $detail['status'] : 401;
            $headers = [];
            if ($status === 401) {
                $reason_code = is_array($detail) && !empty($detail['reason_code']) ? (string) $detail['reason_code'] : 'token_invalid';
                $headers['WWW-Authenticate'] = koto_mcp_www_authenticate_header(
                    is_array($detail) ? ($detail['oauth_error'] ?? 'invalid_token') : 'invalid_token',
                    $reason_code
                );
            }
            return koto_mcp_jsonrpc_error_response($id, -32000, $early_auth->get_error_message(), [
                'code' => $early_auth->get_error_code(),
                'reason' => is_array($detail) && !empty($detail['reason_code']) ? (string) $detail['reason_code'] : 'token_invalid',
                'detail' => $detail,
            ], $status, $headers);
        }
    }

    if ($method === 'initialize') {
        return rest_ensure_response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => KOTO_MCP_PROTOCOL_VERSION,
                'capabilities' => [
                    'tools' => ['listChanged' => false],
                    'resources' => ['listChanged' => false],
                    'prompts' => ['listChanged' => false],
                ],
                'serverInfo' => ['name' => 'kotodaman-db', 'version' => '1.1.0'],
                'instructions' => koto_mcp_instructions_text(),
            ],
        ]);
    }
    if ($method === 'ping') {
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()]);
    }
    if ($method === 'notifications/initialized' || strpos((string) $method, 'notifications/') === 0) {
        return new WP_REST_Response(null, 202);
    }
    if ($method === 'tools/list') {
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => koto_mcp_tools()]]);
    }
    if ($method === 'resources/list') {
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resources' => koto_mcp_resources_list()]]);
    }
    if ($method === 'resources/read') {
        $res_auth = koto_mcp_require_scope('validate', $request);
        if (is_wp_error($res_auth)) {
            $detail = $res_auth->get_error_data();
            $status = is_array($detail) && isset($detail['status']) ? (int) $detail['status'] : 401;
            $headers = [];
            if ($status === 401) {
                $reason_code = is_array($detail) && !empty($detail['reason_code']) ? (string) $detail['reason_code'] : 'token_invalid';
                $headers['WWW-Authenticate'] = koto_mcp_www_authenticate_header(
                    is_array($detail) ? ($detail['oauth_error'] ?? 'invalid_token') : 'invalid_token',
                    $reason_code
                );
            }
            return koto_mcp_jsonrpc_error_response($id, -32000, $res_auth->get_error_message(), [
                'code' => $res_auth->get_error_code(),
                'reason' => is_array($detail) && !empty($detail['reason_code']) ? (string) $detail['reason_code'] : 'token_invalid',
                'detail' => $detail,
            ], $status, $headers);
        }
        $uri = (string) ($body['params']['uri'] ?? '');
        $res = koto_mcp_read_resource($uri);
        if (is_wp_error($res)) {
            return koto_mcp_jsonrpc_error_response($id, -32602, $res->get_error_message());
        }
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $res]);
    }
    if ($method === 'prompts/list') {
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['prompts' => koto_mcp_prompts_list()]]);
    }
    if ($method === 'prompts/get') {
        $prompt_name = (string) ($body['params']['name'] ?? '');
        $prompt_args = is_array($body['params']['arguments'] ?? null) ? $body['params']['arguments'] : [];
        $prompt = koto_mcp_get_prompt($prompt_name, $prompt_args);
        if (is_wp_error($prompt)) {
            return koto_mcp_jsonrpc_error_response($id, -32602, $prompt->get_error_message());
        }
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $prompt]);
    }
    if ($method !== 'tools/call') {
        return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
    $name = $body['params']['name'] ?? '';
    $arguments = $body['params']['arguments'] ?? [];
    if (!is_string($name) || $name === '' || !in_array($name, koto_mcp_tool_names(), true)) {
        return koto_mcp_jsonrpc_error_response($id, -32602, 'Invalid tool name.');
    }
    $validation = koto_mcp_validate_tool_arguments($name, $arguments);
    if (is_wp_error($validation)) {
        return koto_mcp_jsonrpc_error_response($id, -32602, $validation->get_error_message(), [
            'code' => $validation->get_error_code(),
            'detail' => $validation->get_error_data(),
        ]);
    }
    $tool = koto_mcp_call_tool($name, $arguments, $request);
    if (is_wp_error($tool)) {
        $detail = $tool->get_error_data();
        $status = is_array($detail) && isset($detail['status']) ? (int) $detail['status'] : 200;
        $headers = [];
        if ($status === 401) {
            $reason_code = is_array($detail) && !empty($detail['reason_code']) ? (string) $detail['reason_code'] : 'token_invalid';
            $headers['WWW-Authenticate'] = koto_mcp_www_authenticate_header(
                is_array($detail) ? ($detail['oauth_error'] ?? 'invalid_token') : 'invalid_token',
                $reason_code
            );
        }
        return koto_mcp_jsonrpc_error_response($id, -32000, $tool->get_error_message(), [
            'code' => $tool->get_error_code(),
            'reason' => is_array($detail) && !empty($detail['reason_code']) ? (string) $detail['reason_code'] : null,
            'detail' => $detail,
        ], $status, $headers);
    }
    return rest_ensure_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => wp_json_encode($tool, JSON_UNESCAPED_UNICODE)]]]]);
}
