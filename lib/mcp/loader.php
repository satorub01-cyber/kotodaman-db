<?php
/**
 * コトダマンDB MCPモジュール 統一ローダー
 *
 * auth（認証基盤・OAuth 2.1）→ protocol（MCP・スキーマ・下書き・REST）→ portal（管理画面・協力者ページ）
 * の依存順に各サブディレクトリを読み込みます。
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. 認証・OAuth・コア基盤
require_once __DIR__ . '/auth/mcp-core.php';
require_once __DIR__ . '/auth/mcp-auth.php';
require_once __DIR__ . '/auth/mcp-oauth.php';

// 2. プロトコル・ツール・スキーマ・下書き・REST API
require_once __DIR__ . '/protocol/mcp-schema.php';
require_once __DIR__ . '/protocol/mcp-draft.php';
require_once __DIR__ . '/protocol/mcp-resources.php';
require_once __DIR__ . '/protocol/mcp-tools.php';
require_once __DIR__ . '/protocol/post-mcp.php';
require_once __DIR__ . '/protocol/server.php';

// 3. 管理画面承認UI ＆ 協力者登録ポータル
require_once __DIR__ . '/portal/mcp-admin.php';
require_once __DIR__ . '/portal/mcp-collaboration-page.php';
