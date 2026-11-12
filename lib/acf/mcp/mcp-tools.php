<?php
if (!defined('ABSPATH')) exit;

/**
 * コトダマンDB MCP ツール定義・実行ディスパッチモジュール
 *
 * 9つのMCPツール（get_field_schema, get_terms, search_character, get_draft,
 * validate, preview_draft, create_draft, update_draft, trash_draft）の
 * スキーマ定義、引数バリデーション、実行ディスパッチを提供します。
 */

function koto_mcp_tool_names()
{
    return array_map(static function ($tool) {
        return $tool['name'] ?? '';
    }, koto_mcp_tools());
}

function koto_mcp_tools()
{
    return [
        ['name' => 'get_field_schema', 'description' => 'Return ACF field schema, required fields, and manual guidance. Set test_mode=true (debug only) for full unfiltered schema.', 'inputSchema' => ['type' => 'object', 'properties' => ['test_mode' => ['type' => 'boolean', 'description' => 'Debug only: return all unfiltered ACF fields.']]]],
        ['name' => 'get_blessing_schema', 'description' => 'Return blessing token dictionary, template patterns, and validation schema for auto_input_blessing and auto_input_ex_blessing.', 'inputSchema' => ['type' => 'object', 'properties' => ['include_gimmicks' => ['type' => 'boolean', 'description' => 'Whether to include gimmick tokens (default: true).']]]],
        ['name' => 'get_terms', 'description' => 'Return term dictionaries with IDs. taxonomy accepts a single name, comma-separated names, or "all". Optional search filters by name/slug.', 'inputSchema' => ['type' => 'object', 'properties' => ['taxonomy' => ['type' => 'string'], 'search' => ['type' => 'string'], 'test_mode' => ['type' => 'boolean']], 'required' => ['taxonomy']]],
        ['name' => 'search_character', 'description' => 'Search existing character posts to verify duplication before creating a draft.', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => 'Character name keyword to search.']], 'required' => ['name']]],
        ['name' => 'get_draft', 'description' => 'Return current basic_fields, blessing, ex_blessing, title, status, and MCP review metadata of an existing character draft.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer', 'description' => 'Post ID of the character draft.']], 'required' => ['post_id']]],
        ['name' => 'validate', 'description' => 'Validate draft payload without saving or caching (use preview_draft when preparing to create/update). Set test_mode=true (debug only) to include full parsed_acf.', 'inputSchema' => koto_mcp_draft_schema(false)],
        ['name' => 'preview_draft', 'description' => 'Parse, validate, and cache draft payload for 30 minutes. Returns preview_hash needed for create_draft/update_draft. Set test_mode=true (debug only) for full parsed_acf.', 'inputSchema' => koto_mcp_draft_schema(false)],
        ['name' => 'create_draft', 'description' => 'Create a character draft using preview_hash and idempotency_key. WARNING: test_mode is for debugging only and does NOT prevent creation (not a dry run; use preview_draft/validate for dry run).', 'inputSchema' => koto_mcp_draft_schema(true)],
        ['name' => 'update_draft', 'description' => 'Update an existing character draft using preview_hash, idempotency_key, and post_id. WARNING: test_mode is for debugging only and does NOT prevent update (not a dry run).', 'inputSchema' => koto_mcp_draft_schema(true, true)],
        ['name' => 'trash_draft', 'description' => 'Move an existing draft to trash.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer', 'description' => 'Post ID of the draft to trash.']], 'required' => ['post_id']]],
    ];
}

function koto_mcp_draft_schema($hash_required = false, $update = false)
{
    $schema = [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Target post ID (required for update_draft).'],
            'character_name' => ['type' => 'string', 'description' => 'Character name (becomes post title).'],
            'basic_fields' => ['type' => 'object', 'description' => 'Top-level ACF fields. Creation requires name_ruby, attribute, species, rarity.'],
            'auto_input' => ['type' => 'object', 'description' => 'Raw in-game texts for skills, traits, leader, kotowaza, and blessings.'],
            'screenshot_urls' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uri'], 'description' => 'List of screenshot URLs to attach and set featured image.'],
            'evidence' => ['type' => 'object', 'description' => 'Optional OCR extraction evidence and reasoning metadata (stored in _koto_mcp_audit, max 16KB). Pass during preview_draft or create_draft.'],
            'idempotency_key' => ['type' => 'string', 'description' => 'Unique idempotency key for draft creation or update.'],
            'preview_hash' => ['type' => 'string', 'description' => '30-minute cache hash returned from preview_draft.'],
            'replace_existing' => ['type' => 'boolean', 'description' => 'For update_draft: true (default) replaces existing skill/blessing rows; false appends new rows.'],
            'human_approved' => ['type' => 'boolean', 'description' => 'Flag indicating that ambiguous values (e.g. get_place) have been confirmed and approved by a human.'],
            'test_mode' => ['type' => 'boolean', 'description' => 'Debug only: returns verbose parsed_acf structure. CAUTION: Does NOT prevent saving in create_draft/update_draft (not a dry run; use preview_draft/validate for dry run).'],
        ],
        'required' => $update ? ['post_id', 'idempotency_key', 'preview_hash'] : ($hash_required ? ['idempotency_key', 'preview_hash'] : []),
    ];
    return $schema;
}

function koto_mcp_validate_tool_arguments($name, $arguments)
{
    if (!is_array($arguments)) {
        return new WP_Error('INVALID_PARAMS', 'Tool arguments must be an object.', ['field' => 'arguments', 'reason' => 'invalid_type', 'status' => 400]);
    }

    $validators = [
        'get_terms' => ['taxonomy' => 'string'],
        'search_character' => ['name' => 'string'],
    ];
    if (isset($validators[$name])) {
        foreach ($validators[$name] as $key => $expected_type) {
            if (!array_key_exists($key, $arguments)) {
                return new WP_Error('INVALID_PARAMS', sprintf('%s is required.', $key), ['field' => $key, 'reason' => 'required_empty', 'status' => 400]);
            }
            if ($expected_type === 'string' && !is_string($arguments[$key])) {
                return new WP_Error('INVALID_PARAMS', sprintf('%s must be a string.', $key), ['field' => $key, 'reason' => 'invalid_type', 'status' => 400]);
            }
        }
    }

    if (array_key_exists('test_mode', $arguments) && !is_bool($arguments['test_mode'])) {
        return new WP_Error('INVALID_PARAMS', 'test_mode must be a boolean.', ['field' => 'test_mode', 'reason' => 'invalid_type', 'status' => 400]);
    }
    if ($name === 'get_blessing_schema' && array_key_exists('include_gimmicks', $arguments) && !is_bool($arguments['include_gimmicks'])) {
        return new WP_Error('INVALID_PARAMS', 'include_gimmicks must be a boolean.', ['field' => 'include_gimmicks', 'reason' => 'invalid_type', 'status' => 400]);
    }
    if ($name === 'get_terms' && array_key_exists('search', $arguments) && !is_string($arguments['search'])) {
        return new WP_Error('INVALID_PARAMS', 'search must be a string.', ['field' => 'search', 'reason' => 'invalid_type', 'status' => 400]);
    }
    if ($name === 'get_terms' && isset($arguments['search']) && mb_strlen((string) $arguments['search'], 'UTF-8') > 200) {
        return new WP_Error('INVALID_PARAMS', 'search exceeds maximum length.', ['field' => 'search', 'reason' => 'above_max', 'status' => 400]);
    }
    if ($name === 'search_character' && isset($arguments['name']) && mb_strlen((string) $arguments['name'], 'UTF-8') > KOTO_MCP_MAX_NAME_CHARS) {
        return new WP_Error('INVALID_PARAMS', 'name exceeds maximum length.', ['field' => 'name', 'reason' => 'above_max', 'status' => 400]);
    }
    if ($name === 'get_draft') {
        if (!array_key_exists('post_id', $arguments) || (!is_int($arguments['post_id']) && !(is_string($arguments['post_id']) && ctype_digit($arguments['post_id'])))) {
            return new WP_Error('INVALID_PARAMS', 'post_id must be an integer.', ['field' => 'post_id', 'reason' => 'invalid_type', 'status' => 400]);
        }
    }

    if (in_array($name, ['validate', 'preview_draft', 'create_draft', 'update_draft'], true)) {
        if (array_key_exists('post_id', $arguments) && $arguments['post_id'] !== null && (!is_int($arguments['post_id']) && !(is_string($arguments['post_id']) && ctype_digit($arguments['post_id'])))) {
            return new WP_Error('INVALID_PARAMS', 'post_id must be an integer.', ['field' => 'post_id', 'reason' => 'invalid_type', 'status' => 400]);
        }
        if (array_key_exists('character_name', $arguments)) {
            if (!is_string($arguments['character_name'])) {
                return new WP_Error('INVALID_PARAMS', 'character_name must be a string.', ['field' => 'character_name', 'reason' => 'invalid_type', 'status' => 400]);
            }
            if (mb_strlen($arguments['character_name'], 'UTF-8') > KOTO_MCP_MAX_NAME_CHARS) {
                return new WP_Error('INVALID_PARAMS', 'character_name exceeds maximum length (200 chars).', ['field' => 'character_name', 'reason' => 'above_max', 'status' => 400]);
            }
        }
        if (in_array($name, ['validate', 'preview_draft'], true) && empty($arguments['post_id'])) {
            if (!array_key_exists('character_name', $arguments) || trim((string) $arguments['character_name']) === '') {
                return new WP_Error('INVALID_PARAMS', 'character_name is required.', ['field' => 'character_name', 'reason' => 'required_empty', 'status' => 400]);
            }
        }
        if (array_key_exists('basic_fields', $arguments)) {
            if (!koto_mcp_is_object_like_array($arguments['basic_fields'])) {
                return new WP_Error('INVALID_PARAMS', 'basic_fields must be an object.', ['field' => 'basic_fields', 'reason' => 'invalid_type', 'status' => 400]);
            }
            if (count($arguments['basic_fields']) > 60) {
                return new WP_Error('INVALID_PARAMS', 'Too many basic_fields entries.', ['field' => 'basic_fields', 'reason' => 'above_max', 'status' => 400]);
            }
            foreach ($arguments['basic_fields'] as $bf_key => $bf_val) {
                if (is_string($bf_val) && mb_strlen($bf_val, 'UTF-8') > KOTO_MCP_MAX_BASIC_FIELD_CHARS) {
                    return new WP_Error('INVALID_PARAMS', sprintf('basic_fields.%s exceeds maximum length.', $bf_key), ['field' => 'basic_fields.' . $bf_key, 'reason' => 'above_max', 'status' => 400]);
                }
                if (is_array($bf_val) && count($bf_val) > 50) {
                    return new WP_Error('INVALID_PARAMS', sprintf('basic_fields.%s has too many items.', $bf_key), ['field' => 'basic_fields.' . $bf_key, 'reason' => 'above_max', 'status' => 400]);
                }
            }
        }
        if (array_key_exists('auto_input', $arguments)) {
            if (!koto_mcp_is_object_like_array($arguments['auto_input'])) {
                return new WP_Error('INVALID_PARAMS', 'auto_input must be an object.', ['field' => 'auto_input', 'reason' => 'invalid_type', 'status' => 400]);
            }
            if (count($arguments['auto_input']) > 20) {
                return new WP_Error('INVALID_PARAMS', 'Too many auto_input entries.', ['field' => 'auto_input', 'reason' => 'above_max', 'status' => 400]);
            }
            foreach ($arguments['auto_input'] as $input_key => $input_val) {
                if (!is_string($input_key)) {
                    return new WP_Error('INVALID_PARAMS', 'auto_input keys must be strings.', ['field' => 'auto_input', 'reason' => 'invalid_type', 'status' => 400]);
                }
                if ($input_key === 'auto_input_blessing' || $input_key === 'auto_input_ex_blessing') {
                    if ($input_val !== null && !is_string($input_val) && !is_array($input_val)) {
                        return new WP_Error('INVALID_PARAMS', sprintf('auto_input.%s must be a string or object.', $input_key), ['field' => 'auto_input.' . $input_key, 'reason' => 'invalid_type', 'status' => 400]);
                    }
                    if (is_array($input_val)) {
                        $encoded_blessing = wp_json_encode($input_val, JSON_UNESCAPED_UNICODE);
                        if (is_string($encoded_blessing) && mb_strlen($encoded_blessing, 'UTF-8') > KOTO_MCP_MAX_AUTO_INPUT_CHARS) {
                            return new WP_Error('INVALID_PARAMS', sprintf('auto_input.%s exceeds maximum length (4000 chars).', $input_key), ['field' => 'auto_input.' . $input_key, 'reason' => 'above_max', 'status' => 400]);
                        }
                    }
                } elseif ($input_val !== null && !is_string($input_val)) {
                    return new WP_Error('INVALID_PARAMS', 'auto_input values must be strings.', ['field' => 'auto_input.' . $input_key, 'reason' => 'invalid_type', 'status' => 400]);
                }
                if (is_string($input_val) && mb_strlen($input_val, 'UTF-8') > KOTO_MCP_MAX_AUTO_INPUT_CHARS) {
                    return new WP_Error('INVALID_PARAMS', sprintf('auto_input.%s exceeds maximum length (4000 chars).', $input_key), ['field' => 'auto_input.' . $input_key, 'reason' => 'above_max', 'status' => 400]);
                }
            }
        }
        if (array_key_exists('screenshot_urls', $arguments)) {
            if (!is_array($arguments['screenshot_urls'])) {
                return new WP_Error('INVALID_PARAMS', 'screenshot_urls must be an array.', ['field' => 'screenshot_urls', 'reason' => 'invalid_type', 'status' => 400]);
            }
            if (count($arguments['screenshot_urls']) > 20) {
                return new WP_Error('INVALID_PARAMS', 'screenshot_urls exceeds maximum count (20).', ['field' => 'screenshot_urls', 'reason' => 'above_max', 'status' => 400]);
            }
            foreach ($arguments['screenshot_urls'] as $index => $url) {
                if (!is_string($url)) {
                    return new WP_Error('INVALID_PARAMS', 'screenshot_urls items must be strings.', ['field' => 'screenshot_urls.' . $index, 'reason' => 'invalid_type', 'status' => 400]);
                }
                if (strlen($url) > 2048) {
                    return new WP_Error('INVALID_PARAMS', 'screenshot_urls item exceeds maximum length.', ['field' => 'screenshot_urls.' . $index, 'reason' => 'above_max', 'status' => 400]);
                }
            }
        }
        if (array_key_exists('evidence', $arguments)) {
            if (!koto_mcp_is_object_like_array($arguments['evidence'])) {
                return new WP_Error('INVALID_PARAMS', 'evidence must be an object.', ['field' => 'evidence', 'reason' => 'invalid_type', 'status' => 400]);
            }
            $encoded_ev = wp_json_encode($arguments['evidence'], JSON_UNESCAPED_UNICODE);
            if (is_string($encoded_ev) && strlen($encoded_ev) > KOTO_MCP_MAX_EVIDENCE_BYTES) {
                return new WP_Error('INVALID_PARAMS', 'evidence exceeds maximum size (16KB).', ['field' => 'evidence', 'reason' => 'above_max', 'status' => 400]);
            }
        }
        if (array_key_exists('human_approved', $arguments) && !is_bool($arguments['human_approved'])) {
            return new WP_Error('INVALID_PARAMS', 'human_approved must be a boolean.', ['field' => 'human_approved', 'reason' => 'invalid_type', 'status' => 400]);
        }
        if (array_key_exists('replace_existing', $arguments) && !is_bool($arguments['replace_existing'])) {
            return new WP_Error('INVALID_PARAMS', 'replace_existing must be a boolean.', ['field' => 'replace_existing', 'reason' => 'invalid_type', 'status' => 400]);
        }
    }

    if (in_array($name, ['create_draft', 'update_draft'], true)) {
        if (!array_key_exists('idempotency_key', $arguments) || !is_string($arguments['idempotency_key']) || trim($arguments['idempotency_key']) === '' || strlen(trim($arguments['idempotency_key'])) > 255) {
            return new WP_Error('INVALID_PARAMS', 'idempotency_key is required.', ['field' => 'idempotency_key', 'reason' => 'required_empty', 'status' => 400]);
        }
        if (!array_key_exists('preview_hash', $arguments) || !is_string($arguments['preview_hash']) || trim($arguments['preview_hash']) === '') {
            return new WP_Error('INVALID_PARAMS', 'preview_hash is required.', ['field' => 'preview_hash', 'reason' => 'required_empty', 'status' => 400]);
        }
    }
    if ($name === 'update_draft') {
        if (!array_key_exists('post_id', $arguments) || (!is_int($arguments['post_id']) && !(is_string($arguments['post_id']) && ctype_digit($arguments['post_id'])))) {
            return new WP_Error('INVALID_PARAMS', 'post_id must be an integer.', ['field' => 'post_id', 'reason' => 'invalid_type', 'status' => 400]);
        }
    }
    if ($name === 'trash_draft') {
        if (!array_key_exists('post_id', $arguments) || (!is_int($arguments['post_id']) && !(is_string($arguments['post_id']) && ctype_digit($arguments['post_id'])))) {
            return new WP_Error('INVALID_PARAMS', 'post_id must be an integer.', ['field' => 'post_id', 'reason' => 'invalid_type', 'status' => 400]);
        }
    }
    return true;
}

function koto_mcp_call_tool($name, $args, $request = null)
{
    if (!in_array($name, koto_mcp_tool_names(), true)) {
        return new WP_Error('INVALID_TOOL', 'Unknown tool name.', ['field' => 'name', 'reason' => 'invalid_value', 'status' => 400]);
    }
    $scope = in_array($name, ['get_field_schema', 'get_blessing_schema', 'get_terms', 'search_character', 'get_draft', 'validate'], true) ? 'validate' : 'preview';
    if (in_array($name, ['create_draft', 'update_draft', 'trash_draft'], true)) $scope = 'create';
    $auth = koto_mcp_require_scope($scope, $request);
    if (is_wp_error($auth)) return $auth;

    $arg_validation = koto_mcp_validate_tool_arguments($name, $args);
    if (is_wp_error($arg_validation)) {
        return $arg_validation;
    }
    $rate_key = 'koto_mcp_rate_' . $auth;
    $now = time();
    $rate_state = get_transient($rate_key);
    if (is_array($rate_state) && isset($rate_state['count'], $rate_state['reset_at']) && (int) $rate_state['reset_at'] > $now) {
        $rate_count = (int) $rate_state['count'];
        $reset_at = (int) $rate_state['reset_at'];
    } elseif (is_numeric($rate_state) && (int) $rate_state > 0) {
        $rate_count = (int) $rate_state;
        $reset_at = $now + MINUTE_IN_SECONDS;
    } else {
        $rate_count = 0;
        $reset_at = $now + MINUTE_IN_SECONDS;
    }
    if ($rate_count >= 60) {
        return new WP_Error('RATE_LIMITED', 'Too many MCP requests. Please retry later.', ['field' => '', 'reason' => 'invalid_value', 'status' => 429]);
    }
    set_transient($rate_key, ['count' => $rate_count + 1, 'reset_at' => $reset_at], max(1, $reset_at - $now));

    $test_mode = koto_mcp_is_test_mode($args);
    $prev_test_mode = $GLOBALS['koto_auto_input_test_mode'] ?? false;
    $GLOBALS['koto_auto_input_test_mode'] = $test_mode;

    // 【MCP権限の厳格なスコープ分離】
    // スコープが 'create' かつ承認済みユーザーの場合のみ、ツール実行中限定でケイパビリティを一時付与。
    // delete_post（削除）は付与せず、MCP外の経路や validate/preview では一切効かない。
    $cap_filter = null;
    if ($scope === 'create' && koto_mcp_is_user_approved($auth)) {
        $cap_filter = static function ($allcaps, $caps, $cap_args, $user) use ($auth) {
            if ((int) ($user->ID ?? 0) !== $auth) {
                return $allcaps;
            }
            $allcaps['edit_posts'] = true;
            $allcaps['upload_files'] = true;
            // 自身の作成した下書きに対する編集・閲覧のみ許可（delete_post は絶対に含めない）
            if (isset($cap_args[0]) && in_array($cap_args[0], ['edit_post', 'read_post'], true)) {
                $post_id = isset($cap_args[2]) ? (int) $cap_args[2] : 0;
                if ($post_id > 0) {
                    $post = get_post($post_id);
                    if ($post && (int) $post->post_author === $auth && $post->post_status === 'draft') {
                        $allcaps[$caps[0]] = true;
                    }
                }
            }
            return $allcaps;
        };
        add_filter('user_has_cap', $cap_filter, 999, 4);
    }

    try {
        if ($name === 'get_field_schema') {
            $resp = [
                'fields' => koto_mcp_field_schema(!$test_mode),
                'required_for_create' => ['name_ruby', 'attribute', 'species', 'rarity'],
                'auto_input_keys' => [
                    'auto_input_waza_name',
                    'auto_input_waza',
                    'auto_input_sugowaza_name',
                    'auto_input_sugowaza_condition',
                    'auto_input_sugowaza',
                    'auto_input_trait1',
                    'auto_input_trait2',
                    'auto_input_leader',
                    'auto_input_blessing',
                    'auto_input_ex_blessing',
                    'auto_input_kotowaza',
                ],
                'workflow_guide' => koto_mcp_workflow_guide_data(),
                'blessing_schema_hint' => '祝福・EX祝福の入力スキーマ、トークン一覧、テンプレート構成は get_blessing_schema ツール（またはリソース koto://blessing-schema）で取得できます。',
            ];
            if ($test_mode) {
                $resp['test_mode'] = true;
            }
            return $resp;
        }
        if ($name === 'get_blessing_schema') {
            $include_gimmicks = !isset($args['include_gimmicks']) || (bool) $args['include_gimmicks'];
            return function_exists('koto_mcp_blessing_schema') ? koto_mcp_blessing_schema($include_gimmicks) : ['error' => 'blessing_schema_unavailable'];
        }
        if ($name === 'get_terms') {
            $raw_tax = trim((string) ($args['taxonomy'] ?? ''));
            $search = isset($args['search']) ? trim((string) $args['search']) : '';
            $tax_list = ($raw_tax === 'all')
                ? ['attribute', 'species', 'rarity', 'affiliation', 'event', 'gimmick', 'available_moji', 'suitable_quest']
                : array_values(array_filter(array_map('sanitize_key', explode(',', $raw_tax))));
            if (empty($tax_list)) {
                return new WP_Error('VALIDATION_FAILED', 'Unknown taxonomy.', ['field' => 'taxonomy', 'reason' => 'invalid_value']);
            }
            foreach ($tax_list as $tax_item) {
                if (!taxonomy_exists($tax_item)) {
                    return new WP_Error('VALIDATION_FAILED', 'Unknown taxonomy.', ['field' => 'taxonomy', 'reason' => 'invalid_value']);
                }
            }
            $fetch_terms = static function ($tax_item) use ($search) {
                $query_args = ['taxonomy' => $tax_item, 'hide_empty' => false];
                if ($search !== '') {
                    $query_args['search'] = $search;
                }
                $terms = get_terms($query_args);
                if (is_wp_error($terms) || !is_array($terms)) {
                    return [];
                }
                return array_values(array_map(static function ($term) {
                    return ['id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug];
                }, $terms));
            };
            if (count($tax_list) === 1 && $raw_tax !== 'all') {
                return ['taxonomy' => $tax_list[0], 'terms' => $fetch_terms($tax_list[0])];
            }
            $grouped = [];
            foreach ($tax_list as $tax_item) {
                $grouped[$tax_item] = $fetch_terms($tax_item);
            }
            return ['taxonomies' => $grouped];
        }
        if ($name === 'search_character') {
            $query = new WP_Query(['post_type' => 'character', 'post_status' => ['publish', 'draft', 'pending', 'private'], 's' => sanitize_text_field($args['name'] ?? ''), 'posts_per_page' => 20, 'no_found_rows' => true]);
            $matches = [];
            foreach ($query->posts as $post) {
                if (koto_mcp_is_protected_template_post($post)) continue;
                if ($post->post_status === 'private') {
                    if (!user_can($auth, 'read_post', $post->ID)) continue;
                } elseif ($post->post_status !== 'publish') {
                    if (!user_can($auth, 'edit_post', $post->ID)) continue;
                }
                $matches[] = ['id' => $post->ID, 'title' => $post->post_title, 'status' => $post->post_status];
            }
            return ['matches' => $matches];
        }
        if ($name === 'get_draft') {
            $post_id = (int) ($args['post_id'] ?? 0);
            if (koto_mcp_is_protected_template_post($post_id)) {
                return new WP_Error('VALIDATION_FAILED', 'Template posts cannot be accessed via MCP.', ['field' => 'post_id', 'reason' => 'protected_template', 'status' => 403]);
            }
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'character') {
                return new WP_Error('NOT_FOUND', 'Character post not found.', ['field' => 'post_id', 'reason' => 'not_found', 'status' => 404]);
            }
            if (koto_mcp_is_protected_template_post($post)) {
                return new WP_Error('VALIDATION_FAILED', 'Template posts cannot be accessed via MCP.', ['field' => 'post_id', 'reason' => 'protected_template', 'status' => 403]);
            }
            if (!user_can($auth, 'edit_post', $post->ID)) {
                return new WP_Error('FORBIDDEN', 'The draft cannot be read by this user.', ['field' => 'post_id', 'reason' => 'forbidden', 'status' => 403]);
            }
            $draft_data = koto_mcp_extract_existing_draft_data($post->ID);
            if (!$draft_data) {
                return new WP_Error('NOT_FOUND', 'Failed to extract draft data.', ['field' => 'post_id', 'reason' => 'not_found', 'status' => 404]);
            }
            return $draft_data;
        }
        if ($name === 'trash_draft') {
            $post_id = (int) ($args['post_id'] ?? 0);
            if (koto_mcp_is_protected_template_post($post_id)) {
                return new WP_Error('VALIDATION_FAILED', 'Template posts cannot be trashed via MCP.', ['field' => 'post_id', 'reason' => 'protected_template', 'status' => 403]);
            }
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'character' || $post->post_status !== 'draft') return new WP_Error('VALIDATION_FAILED', 'Only character drafts can be trashed.', ['field' => 'post_id', 'reason' => 'invalid_value']);
            if (koto_mcp_is_protected_template_post($post)) {
                return new WP_Error('VALIDATION_FAILED', 'Template posts cannot be trashed via MCP.', ['field' => 'post_id', 'reason' => 'protected_template', 'status' => 403]);
            }
            if (!user_can($auth, 'delete_post', $post->ID)) return new WP_Error('VALIDATION_FAILED', 'The draft cannot be deleted by this user.', ['field' => 'post_id', 'reason' => 'invalid_value']);
            $trashed = wp_trash_post($post->ID);
            if (!$trashed) return new WP_Error('TRASH_FAILED', 'Failed to trash the draft.', ['field' => 'post_id', 'reason' => 'invalid_value']);
            return ['post_id' => $post->ID, 'status' => 'trash'];
        }

        // create_draft / update_draft で preview_hash のみが渡された場合、直前の preview_draft キャッシュから入力を復元
        if (in_array($name, ['create_draft', 'update_draft'], true) && !empty($args['preview_hash'])) {
            $has_explicit_payload = array_key_exists('basic_fields', $args) || array_key_exists('auto_input', $args) || array_key_exists('character_name', $args);
            if (!$has_explicit_payload) {
                $cached_preview = get_transient(koto_mcp_preview_cache_key($auth, (string) $args['preview_hash']));
                if (is_array($cached_preview)) {
                    foreach (['character_name', 'basic_fields', 'auto_input', 'screenshot_urls', 'evidence', 'replace_existing'] as $ckey) {
                        if (!array_key_exists($ckey, $args) && array_key_exists($ckey, $cached_preview) && $cached_preview[$ckey] !== null) {
                            $args[$ckey] = $cached_preview[$ckey];
                        }
                    }
                }
            }
        }
        if ($name === 'create_draft' && (!isset($args['character_name']) || trim((string) $args['character_name']) === '')) {
            return new WP_Error('VALIDATION_FAILED', 'character_name is required (or preview_hash cache expired).', ['field' => 'character_name', 'reason' => 'required_empty']);
        }

        if (!empty($args['post_id']) && koto_mcp_is_protected_template_post((int) $args['post_id'])) {
            return new WP_Error('VALIDATION_FAILED', 'Template posts cannot be accessed or modified via MCP.', ['field' => 'post_id', 'reason' => 'protected_template', 'status' => 403]);
        }

        $validation = koto_mcp_validate_draft($args);
        if (!empty($validation['errors']['missing_fields']) || !empty($validation['errors']['invalid_fields'])) {
            return new WP_Error('VALIDATION_FAILED', 'Draft validation failed.', $validation['errors']);
        }
        $hash = koto_mcp_content_hash($args, $validation['parsed_acf']);
        $parsed_counts = [];
        foreach ($validation['parsed_acf'] as $k => $rows) {
            $parsed_counts[$k] = is_array($rows) ? count($rows) : 1;
        }
        if ($name === 'validate' || $name === 'preview_draft') {
            set_transient(
                koto_mcp_preview_cache_key($auth, $hash),
                [
                    'character_name'   => $args['character_name'] ?? '',
                    'basic_fields'     => $args['basic_fields'] ?? [],
                    'auto_input'       => $args['auto_input'] ?? [],
                    'screenshot_urls'  => $args['screenshot_urls'] ?? null,
                    'evidence'         => $args['evidence'] ?? null,
                    'replace_existing' => $args['replace_existing'] ?? null,
                ],
                30 * MINUTE_IN_SECONDS
            );
            $resp = [
                'valid'         => true,
                'content_hash'  => $hash,
                'preview_hash'  => $hash,
                'parsed_counts' => $parsed_counts,
                'warnings'      => $validation['warnings'] ?? [],
            ];
            if ($test_mode) {
                $resp['test_mode'] = true;
                $resp['parsed_acf'] = $validation['parsed_acf'];
            }
            return $resp;
        }
        if (empty($args['preview_hash']) || !hash_equals($hash, (string) $args['preview_hash'])) {
            return new WP_Error('PREVIEW_HASH_MISMATCH', 'preview_hash does not match the current content.', ['field' => 'preview_hash', 'reason' => 'invalid_value']);
        }
        $key = trim((string) ($args['idempotency_key'] ?? ''));
        if ($key === '') return new WP_Error('VALIDATION_FAILED', 'idempotency_key is required.', ['field' => 'idempotency_key', 'reason' => 'required_empty']);
        $fingerprint = $name . '|' . (int) ($args['post_id'] ?? 0) . '|' . $hash;
        $reservation = koto_mcp_reserve_idempotency_key($auth, $key, $fingerprint);
        if (is_wp_error($reservation)) return $reservation;
        if (!empty($reservation['result'])) return $reservation['result'];
        $idempotency_option = $reservation['option'];
        if ($name === 'create_draft') {
            $post_id = koto_create_character_post_from_acf(sanitize_text_field($args['character_name']), []);
            if (!is_wp_error($post_id) && $post_id) {
                if (!isset($args['basic_fields']) || !is_array($args['basic_fields'])) {
                    $args['basic_fields'] = [];
                }
                $args['basic_fields']['magnification_estimate_tf'] = true;
                koto_mcp_update_basic_fields($post_id, $args['basic_fields']);
                update_field('field_698994f4550a2', 1, $post_id);
                koto_update_character_post_with_acf($post_id, $validation['parsed_acf']);
            }
        } else {
            $post_id = (int) $args['post_id'];
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'character' || $post->post_status !== 'draft' || !user_can($auth, 'edit_post', $post_id)) {
                koto_mcp_release_idempotency_key($idempotency_option);
                return new WP_Error('VALIDATION_FAILED', 'Only editable character drafts can be updated.', ['field' => 'post_id', 'reason' => 'invalid_value']);
            }
            if (isset($args['character_name']) && is_string($args['character_name']) && trim($args['character_name']) !== '') {
                wp_update_post(['ID' => $post_id, 'post_title' => sanitize_text_field($args['character_name'])]);
            }
            koto_mcp_update_basic_fields($post_id, $args['basic_fields'] ?? []);
            $replace_existing = array_key_exists('replace_existing', $args) ? (bool) $args['replace_existing'] : true;
            koto_update_character_post_with_acf($post_id, $validation['parsed_acf'], $replace_existing);
        }
        if (is_wp_error($post_id) || !$post_id) {
            koto_mcp_release_idempotency_key($idempotency_option);
            return new WP_Error('CREATE_FAILED', 'Draft creation failed.', ['field' => 'post', 'reason' => 'invalid_value']);
        }
        if ($name === 'create_draft') {
            if (!empty($args['screenshot_urls'])) {
                $urls = array_values(array_filter(array_map('esc_url_raw', (array) $args['screenshot_urls'])));
                update_post_meta($post_id, '_koto_mcp_screenshot_urls', $urls);
                koto_mcp_set_featured_image_from_url($post_id, $urls[0] ?? '');
            } elseif (defined('KOTO_MCP_DEFAULT_IMAGE_URL')) {
                koto_mcp_set_featured_image_from_url($post_id, KOTO_MCP_DEFAULT_IMAGE_URL);
            }
        } elseif (array_key_exists('screenshot_urls', $args)) {
            $urls = array_values(array_filter(array_map('esc_url_raw', (array) $args['screenshot_urls'])));
            update_post_meta($post_id, '_koto_mcp_screenshot_urls', $urls);
            if (!empty($urls)) {
                koto_mcp_set_featured_image_from_url($post_id, $urls[0]);
            }
        }
        update_post_meta($post_id, '_koto_mcp_generated', 1);
        update_post_meta($post_id, '_koto_mcp_needs_review', 1);
        update_post_meta($post_id, '_koto_mcp_audit', ['user_id' => $auth, 'content_hash' => $hash, 'evidence' => $args['evidence'] ?? [], 'warnings' => $validation['warnings'] ?? [], 'created_at' => current_time('mysql')]);
        do_action('acf/save_post', $post_id);
        $result = [
            'post_id'         => $post_id,
            'status'          => 'draft',
            'edit_url'        => admin_url('admin.php?page=koto-acf-editor&edit_post_id=' . $post_id),
            'content_hash'    => $hash,
            'screenshot_urls' => get_post_meta($post_id, '_koto_mcp_screenshot_urls', true),
            'warnings'        => $validation['warnings'] ?? [],
        ];
        if ($test_mode) {
            $result['test_mode'] = true;
            $result['parsed_acf'] = $validation['parsed_acf'];
        }
        koto_mcp_complete_idempotency_key($idempotency_option, $result, $fingerprint);
        koto_mcp_maybe_cleanup_expired_options();
        return $result;
    } finally {
        if ($cap_filter !== null) {
            remove_filter('user_has_cap', $cap_filter, 999);
        }
        $GLOBALS['koto_auto_input_test_mode'] = $prev_test_mode;
    }
}
