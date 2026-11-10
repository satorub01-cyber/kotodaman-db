<?php
if (!defined('ABSPATH')) exit;

/**
 * コトダマンDB 祝福・EX祝福 自動入力モジュール
 *
 * 1. 祝福トークンレジストリ（★6 / ★5 の数値切り替えおよびACF構造定義）
 * 2. テンプレート展開処理（attr_killer1, species_killer1 等のインデックス必須チェック）
 * 3. 既存キャラ投稿IDからのテンプレート逆抽出ヘルパー（インデックス付き出力）
 * 4. タイプ2（とくせい自動入力流用＋祝福ポイント設定）変換処理
 * 5. 祝福入力データの正規化と統合パース
 */

/**
 * 祝福トークンレジストリを取得する。
 *
 * //要チェック: 新しいトークンの追加や、★6・★5ごとの数値（min_value / max_value / blessing_value）の変更はここで行えます。
 *
 * @return array<string, array<string, mixed>> トークン識別子をキーとした定義配列。
 */
function koto_get_blessing_token_registry()
{
    $base_tokens = [
        // HPアップ（レベル制CSV）
        'hp_up' => [
            'label'                => 'HP基礎値(中)UP',
            'trait_type'           => 'status_up',
            'status_up'            => 'hp',
            'rate_type'            => 'fixed',
            'pt_pattern'           => 'default',
            'blessing_level_value' => 'csv',
            'target_type'          => 'self',
            'rarity_values'        => [
                'star6' => ['blessing_value' => '200!'],
                'star5' => ['blessing_value' => '100,125,150,175,200,230,260,290,320,350'],
            ],
        ],

        // ATKアップ（レベル制CSV）
        'atk_up' => [
            'label'                => 'ATK基礎値(極大)UP',
            'trait_type'           => 'status_up',
            'status_up'            => 'atk',
            'rate_type'            => 'fixed',
            'pt_pattern'           => 'default',
            'blessing_level_value' => 'csv',
            'target_type'          => 'self',
            'rarity_values'        => [
                'star6' => ['blessing_value' => '50!'],
                'star5' => ['blessing_value' => '25,30,35,40,45,50,55,60,65,70'],
            ],
        ],

        // 属性キラー（min_max制。インデックス付き attr_killer1, attr_killer2 で指定）
        // //要チェック: キラーは★5だと値が -5（★6: 10~20% -> ★5: 5~15%）
        'attr_killer' => [
            'label'                => '属性キラー(+)',
            'trait_type'           => 'damage_correction',
            'damage_correction'    => 'killer',
            'rate_type'            => 'percentage',
            'pt_pattern'           => 'default',
            'blessing_level_value' => 'min_max',
            'target_type'          => 'attr',
            'rarity_values'        => [
                'star6' => ['min_value' => '10', 'max_value' => '20'],
                'star5' => ['min_value' => '5',  'max_value' => '15'],
            ],
        ],

        // 種族キラー（min_max制。インデックス付き species_killer1, species_killer2 で指定）
        // //要チェック: キラーは★5だと値が -5（★6: 15~25% -> ★5: 10~20%）
        'species_killer' => [
            'label'                => '種族キラー(+)',
            'trait_type'           => 'damage_correction',
            'damage_correction'    => 'killer',
            'rate_type'            => 'percentage',
            'pt_pattern'           => 'default',
            'blessing_level_value' => 'min_max',
            'target_type'          => 'species',
            'rarity_values'        => [
                'star6' => ['min_value' => '15', 'max_value' => '25'],
                'star5' => ['min_value' => '10', 'max_value' => '20'],
            ],
        ],

        // 属性軽減（min_max制。インデックス付き attr_mitigation1, attr_mitigation2 で指定）
        'attr_mitigation' => [
            'label'                => '属性軽減(+)',
            'trait_type'           => 'status_up',
            'status_up'            => 'mitigation',
            'rate_type'            => 'percentage',
            'pt_pattern'           => 'default',
            'blessing_level_value' => 'min_max',
            'target_type'          => 'attr',
            'rarity_values'        => [
                'star6' => ['min_value' => '10', 'max_value' => '20'],
                'star5' => ['min_value' => '5',  'max_value' => '15'],
            ],
        ],

        // 回避率UP（min_max制）
        'dodge' => [
            'label'                => '心眼回避',
            'trait_type'           => 'status_up',
            'status_up'            => 'dodge',
            'rate_type'            => 'percentage',
            'pt_pattern'           => 'default',
            'blessing_level_value' => 'min_max',
            'target_type'          => 'self',
            'rarity_values'        => [
                'star6' => ['min_value' => '1', 'max_value' => '10'],
                'star5' => ['min_value' => '4', 'max_value' => '12'],
            ],
        ],

        // 30%属性キラー
        'attr_30_killer' => [
            'label'                => '属性キラー30%',
            'trait_type'           => 'damage_correction',
            'damage_correction'    => 'killer',
            'rate_type'            => 'percentage',
            'pt_pattern'           => 'single',
            'target_type'          => 'attr',
            'rarity_values'        => [
                'star6' => ['blessing_value' => '30'],
                'star5' => ['blessing_value' => '25'],
            ],
        ],
        
        // 35%種族キラー
        'speci_35_killer' => [
            'label'                => '種族キラー35%',
            'trait_type'           => 'damage_correction',
            'damage_correction'    => 'killer',
            'rate_type'            => 'percentage',
            'pt_pattern'           => 'single',
            'target_type'          => 'species',
            'rarity_values'        => [
                'star6' => ['blessing_value' => '35'],
                'star5' => ['blessing_value' => '25'],
            ],
        ],

        // グループドローバフ
        'group_draw_buff' => [
            'label'                => 'グループドローバフ',
            'trait_type'           => 'draw_eff',
            'draw_eff'             => 'atk_buff',
            'draw_triat_turn'      => '2',
            'rate_type'            => 'fixed',
            'pt_pattern'           => 'single',
            'need_point'           => '20',
            'target_type'          => 'affiliation',
            'rarity_values'        => [
                'star6' => ['blessing_value' => '1', 'need_point' => '20'],
                'star5' => ['blessing_value' => '1', 'need_point' => '20'],
            ],
        ],

        // atk_up_per_unit
        'atk_up_per_unit' => [
            'label'                => '属性×ATKUP',
            'trait_type'           => 'status_up',
            'status_up'            => 'atk',
            'rate_type'            => 'fixed',
            'pt_pattern'           => 'per_unit',
            'blessing_level_value' => 'csv',
            'target_type'          => 'self',
            'rarity_values'        => [
                'star6' => ['blessing_value' => '1,2,5,10'],
                'star5' => ['blessing_value' => '1,2,5,10'],
            ],
        ],

        // コンボ＋
        'comb_plus' => [
            'label'                => 'コンボ＋',
            'trait_type'           => 'other_traits',
            'other_traits'         => 'comb_plus',
            'rate_type'            => 'fixed',
            'pt_pattern'           => 'single',
            'need_point'           => '20',
            'rarity_values'        => [
                'star6' => ['blessing_value' => '1', 'need_point' => '20'],
                'star5' => ['blessing_value' => '1', 'need_point' => '20'],
            ],
        ],
    ];

    $gimmick_tokens = koto_get_blessing_gimmick_token_definitions();
    return array_merge($base_tokens, $gimmick_tokens);
}

/**
 * ギミック（通常・スーパー化）祝福トークン定義一覧を取得する。
 *
 * //要チェック: 新規ギミック追加時はここへターム情報（slug, term_id, ラベル）を追記してください。
 *
 * @return array<string, array<string, mixed>> ギミックトークン連想配列。
 */
function koto_get_blessing_gimmick_token_definitions()
{
    return [
        'gimmick_change' => [
            'label'         => 'チェンジガード',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'change',
            'gimmick_id'    => 71,
            'is_super'      => false,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_super_change' => [
            'label'         => 'チェンジガード・スーパー化',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'super_change',
            'gimmick_id'    => 88,
            'is_super'      => true,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_copy' => [
            'label'         => 'コピーガード',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'copy',
            'gimmick_id'    => 80,
            'is_super'      => false,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_super_copy' => [
            'label'         => 'コピーガード・スーパー化',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'super_copy',
            'gimmick_id'    => 81,
            'is_super'      => true,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_needle' => [
            'label'         => 'トゲガード',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'needle',
            'gimmick_id'    => 41,
            'is_super'      => false,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_super_needle' => [
            'label'         => 'トゲガード・スーパー化',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'super_needle',
            'gimmick_id'    => 62,
            'is_super'      => true,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_balloon' => [
            'label'         => 'バルーンガード',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'balloon',
            'gimmick_id'    => 43,
            'is_super'      => false,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_super_balloon' => [
            'label'         => 'バルーンガード・スーパー化',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'super_balloon',
            'gimmick_id'    => 314,
            'is_super'      => true,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_shock' => [
            'label'         => 'ビリビリガード',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'shock',
            'gimmick_id'    => 114,
            'is_super'      => false,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_super_shock' => [
            'label'         => 'ビリビリガード・スーパー化',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'super_shock',
            'gimmick_id'    => 92,
            'is_super'      => true,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_landmine' => [
            'label'         => '地雷ガード',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'landmine',
            'gimmick_id'    => 124,
            'is_super'      => false,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_super_landmine' => [
            'label'         => '地雷ガード・スーパー化',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'super_landmine',
            'gimmick_id'    => 74,
            'is_super'      => true,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_weakening' => [
            'label'         => '弱体ガード',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'weakening',
            'gimmick_id'    => 99,
            'is_super'      => false,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_super_weakening' => [
            'label'         => '弱体ガード・スーパー化',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'super_weakening',
            'gimmick_id'    => 82,
            'is_super'      => true,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_laser' => [
            'label'         => 'レーザーガード',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'laser',
            'gimmick_id'    => 305,
            'is_super'      => false,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_wall' => [
            'label'         => 'ウォール',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'wall',
            'gimmick_id'    => 15,
            'is_super'      => false,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_super_wall' => [
            'label'         => 'ウォールブレイカー・スーパー化',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'super_wall',
            'gimmick_id'    => 199,
            'is_super'      => true,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_shield' => [
            'label'         => 'シールド',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'shield',
            'gimmick_id'    => 14,
            'is_super'      => false,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_super_shield' => [
            'label'         => 'シールドブレイカー・スーパー化',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'super_shield',
            'gimmick_id'    => 214,
            'is_super'      => true,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_healing' => [
            'label'         => 'ヒールブレイカー',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'healing',
            'gimmick_id'    => 118,
            'is_super'      => false,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_super_healing' => [
            'label'         => 'ヒールブレイカー・スーパー化',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'super_healing',
            'gimmick_id'    => 50,
            'is_super'      => true,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_smash' => [
            'label'         => 'スマッシュブレイカー',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'smash',
            'gimmick_id'    => 57,
            'is_super'      => false,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_super_smash' => [
            'label'         => 'スマッシュブレイカー・スーパー化',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'super_smash',
            'gimmick_id'    => 289,
            'is_super'      => true,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_freezing' => [
            'label'         => 'フリーズブレイカー',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'freezing',
            'gimmick_id'    => 264,
            'is_super'      => false,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
        'gimmick_super_freezing' => [
            'label'         => 'フリーズブレイカー・スーパー化',
            'trait_type'    => 'gimmick',
            'gimmick_slug'  => 'super_freezing',
            'gimmick_id'    => 171,
            'is_super'      => true,
            'is_gimmick'    => true,
            'pt_pattern'    => 'single',
            'need_point'    => '20',
            'target_type'   => 'self',
            'rate_type'     => 'fixed',
            'rarity_values' => [
                'star6' => ['need_point' => '20'],
                'star5' => ['need_point' => '20'],
            ],
        ],
    ];
}

/**
 * 祝福トークン識別子（スラッグ、エイリアス、日本語名）を正規トークンキーへ解決する。
 *
 * @param string $token 入力されたトークン文字列。
 * @return string|null 解決されたトークンキー（見つからない場合は null）。
 */
function koto_resolve_blessing_token_key($token)
{
    $token = trim((string) $token);
    if ($token === '') return null;

    $registry = koto_get_blessing_token_registry();
    if (isset($registry[$token])) {
        return $token;
    }

    // 小文字化でのチェック
    $lower = strtolower($token);
    if (isset($registry[$lower])) {
        return $lower;
    }

    // gimmick_ プレフィックスのフォールバック
    if (strpos($lower, 'gimmick_') !== 0 && isset($registry['gimmick_' . $lower])) {
        return 'gimmick_' . $lower;
    }

    // 日本語名・別名マッピング
    $clean_label = preg_replace('/[（(]|\s+|[)）]/u', '', $token);
    foreach ($registry as $k => $def) {
        $reg_label = $def['label'] ?? '';
        if ($reg_label !== '' && ($clean_label === $reg_label || $token === $reg_label)) {
            return $k;
        }
    }

    // ギミック特有の表記揺れ解決
    $gimmick_aliases = [
        'スーパーチェンジ'       => 'gimmick_super_change',
        'スーパーコピー'         => 'gimmick_super_copy',
        'スーパートゲ'           => 'gimmick_super_needle',
        'スーパーバルーン'       => 'gimmick_super_balloon',
        'スーパービリビリ'       => 'gimmick_super_shock',
        'スーパー地雷'           => 'gimmick_super_landmine',
        'スーパー弱体'           => 'gimmick_super_weakening',
        'スーパーウォール'       => 'gimmick_super_wall',
        'スーパーシールド'       => 'gimmick_super_shield',
        'スーパーヒール'         => 'gimmick_super_healing',
        'スーパースマッシュ'     => 'gimmick_super_smash',
        'スーパーフリーズ'       => 'gimmick_super_freezing',
        'ウォール・スーパー化'   => 'gimmick_super_wall',
        'シールド・スーパー化'   => 'gimmick_super_shield',
        'ウォールブレイカー'     => 'gimmick_wall',
        'シールドブレイカー'     => 'gimmick_shield',
    ];

    if (isset($gimmick_aliases[$clean_label])) {
        return $gimmick_aliases[$clean_label];
    }

    // 「◯◯・スーパー化」から「スーパー◯◯」への変換判定
    if (preg_match('/^(.+?)[・_]スーパー化$/u', $clean_label, $m)) {
        $base = $m[1];
        $super_form1 = 'スーパー' . $base;
        $super_form2 = 'スーパー' . $base . 'ブレイカー';
        $super_form3 = 'スーパー' . $base . 'ガード';
        foreach ([$super_form1, $super_form2, $super_form3] as $cand) {
            foreach ($registry as $k => $def) {
                if (($def['label'] ?? '') === $cand) return $k;
            }
            if (isset($gimmick_aliases[$cand])) return $gimmick_aliases[$cand];
        }
    }

    return null;
}

/**
 * レアリティが ★5 かどうかを判定する。
 *
 * @param mixed $rarity レアリティ値（文字列・数値・配列など）。
 * @param int $post_id 投稿ID（指定があればDBのタームもフォールバック確認）。
 * @return bool ★5の場合は true、それ以外（未指定、★6、スペシャル等）は false。
 */
function koto_is_blessing_star5_rarity($rarity = null, $post_id = 0)
{
    if (is_wp_error($rarity)) {
        $rarity = null;
    }

    if (is_object($rarity)) {
        if (isset($rarity->slug) || isset($rarity->name) || isset($rarity->term_id)) {
            $slug = (string) ($rarity->slug ?? '');
            $name = (string) ($rarity->name ?? '');
            $tid  = (int) ($rarity->term_id ?? 0);
            if ($slug === '5' || $name === '5' || $tid === 86) {
                return true;
            }
            $rarity = $slug ?: $name;
        } elseif (method_exists($rarity, '__toString')) {
            $rarity = (string) $rarity;
        } else {
            $rarity = null;
        }
    }

    if (is_array($rarity)) {
        foreach ($rarity as $r_item) {
            if (koto_is_blessing_star5_rarity($r_item, 0)) {
                return true;
            }
        }
        return false;
    }

    if ($rarity !== null && $rarity !== '') {
        $str = trim((string) $rarity);
        if ($str === '5' || $str === '星5' || $str === '★5' || $str === '86') {
            return true;
        }
        if (preg_match('/^[^\d]*5[^\d]*$/u', $str) && strpos($str, '6') === false) {
            return true;
        }
    }

    if ($post_id > 0 && function_exists('wp_get_object_terms')) {
        $terms = wp_get_object_terms($post_id, 'rarity');
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $t) {
                if (is_object($t) && ($t->slug === '5' || $t->name === '5' || (int) $t->term_id === 86)) {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * 文字列または配列で渡された属性・種族リストを配列へ正規化する。
 *
 * @param mixed $val 配列またはカンマ・中黒区切り文字列。
 * @return array<int, string> 正規化された文字列配列。
 */
function koto_normalize_blessing_param_array($val)
{
    if (is_array($val)) {
        $out = [];
        foreach ($val as $item) {
            if (is_object($item) && $item instanceof WP_Term) {
                $out[] = trim($item->name ?: $item->slug);
            } elseif (is_scalar($item)) {
                $trimmed = trim((string) $item);
                $out[] = $trimmed;
            }
        }
        // 末尾の連続する空文字を削除（中間のインデックス位置は保持する）
        while (!empty($out) && end($out) === '') {
            array_pop($out);
        }
        return $out;
    }
    if (is_scalar($val)) {
        $str = trim((string) $val);
        if ($str === '') {
            return [];
        }
        $parts = preg_split('/[・,、\/／\s]+/u', $str, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? array_values(array_filter(array_map('trim', $parts), 'strlen')) : [];
    }
    return [];
}

/**
 * 属性・種族の名称またはIDからタームIDを解決する。
 *
 * @param string|int|object $val 属性または種族の名称/ID/タームオブジェクト。
 * @param string $taxonomy 'attribute' または 'species'。
 * @return int 解決されたタームID（見つからない場合は 0）。
 */
function koto_resolve_blessing_term_id($val, $taxonomy)
{
    if (is_object($val) && $val instanceof WP_Term) {
        return (int) $val->term_id;
    }
    if (!is_scalar($val)) {
        return 0;
    }
    $val_str = trim((string) $val);
    if ($val_str === '') return 0;

    $clean = preg_replace('/(属性|種族)$/u', '', $val_str);
    if (ctype_digit($clean)) {
        return (int) $clean;
    }

    if (function_exists('get_term_by')) {
        $term = get_term_by('name', $clean, $taxonomy) ?: get_term_by('slug', $clean, $taxonomy);
        if ($term && !is_wp_error($term)) {
            return (int) $term->term_id;
        }
    }

    return 0;
}

/**
 * キャラクターの所属（affiliation）のタームID配列を取得・解決する。
 *
 * @param mixed $context 実行コンテキスト（'post_id', 'affiliations', 'affiliation' 等）または所属指定値。
 * @param int|string|null $target_id 直接指定されたターゲットタームID/名称。
 * @return array<int, int> 解決されたタームID配列。
 */
function koto_resolve_blessing_affiliations($context = [], $target_id = null)
{
    $candidates = [];

    if (!empty($target_id)) {
        $candidates[] = $target_id;
    }

    $post_id = 0;
    if (is_array($context)) {
        if (!empty($context['affiliations'])) {
            $candidates = array_merge($candidates, (array) $context['affiliations']);
        }
        if (!empty($context['affiliation'])) {
            $candidates = array_merge($candidates, (array) $context['affiliation']);
        }
        if (!empty($context['group'])) {
            $candidates = array_merge($candidates, (array) $context['group']);
        }
        if (!empty($context['post_id'])) {
            $post_id = (int) $context['post_id'];
        }
    } elseif (is_numeric($context) && (int) $context > 0) {
        $post_id = (int) $context;
    } elseif (is_string($context) && trim($context) !== '') {
        $candidates[] = trim($context);
    }

    $resolved_ids = [];
    foreach ($candidates as $c) {
        if (is_object($c) && isset($c->term_id)) {
            $resolved_ids[] = (int) $c->term_id;
        } elseif (is_numeric($c) && (int) $c > 0) {
            $resolved_ids[] = (int) $c;
        } elseif (is_string($c) && trim($c) !== '') {
            $name = trim($c);
            if (function_exists('get_term_by')) {
                $term = get_term_by('name', $name, 'affiliation') ?: get_term_by('slug', $name, 'affiliation');
                if ($term && !is_wp_error($term)) {
                    $resolved_ids[] = (int) $term->term_id;
                }
            }
        }
    }

    // まだ解決できておらず post_id がある場合、投稿から affiliation を取得
    if (empty($resolved_ids) && $post_id > 0) {
        if (function_exists('wp_get_object_terms')) {
            $terms = wp_get_object_terms($post_id, 'affiliation');
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $t) {
                    $resolved_ids[] = (int) $t->term_id;
                }
            }
        }
        if (empty($resolved_ids) && function_exists('get_field')) {
            $acf_aff = get_field('affiliation', $post_id, false);
            if (!empty($acf_aff)) {
                foreach ((array) $acf_aff as $aid) {
                    if ((int) $aid > 0) {
                        $resolved_ids[] = (int) $aid;
                    }
                }
            }
        }
    }

    return array_values(array_unique(array_filter($resolved_ids)));
}

/**
 * 1つのトークン定義とパラメータからACF行データを構築する。
 *
 * @param string $base_token 基本トークン名（例: 'attr_killer', 'hp_up'）。
 * @param int|null $target_id ターゲットタームID（属性IDまたは種族ID）。
 * @param bool $is_star5 ★5かどうか。
 * @param mixed $context 実行コンテキスト（所属情報や post_id など）。
 * @return array<string, mixed>|null ACF行データ（未登録トークンの場合は null）。
 */
function koto_build_blessing_token_row($base_token, $target_id = null, $is_star5 = false, $context = [])
{
    $registry = koto_get_blessing_token_registry();
    $token_key = $base_token;
    if (!isset($registry[$token_key])) {
        $resolved = koto_resolve_blessing_token_key($base_token);
        if ($resolved && isset($registry[$resolved])) {
            $token_key = $resolved;
        } else {
            return null;
        }
    }

    $def = $registry[$token_key];
    $rarity_key = $is_star5 ? 'star5' : 'star6';
    $values = $def['rarity_values'][$rarity_key] ?? ($def['rarity_values']['star6'] ?? []);

    $self_target = [
        'target_type'    => 'self',
        'target_attr'    => [],
        'target_species' => [],
        'target_group'   => [],
        'target_other'   => '',
    ];

    $target_type = $def['target_type'] ?? 'self';
    $target_group = $self_target;
    if ($target_type === 'attr') {
        $target_group = [
            'target_type'    => 'attr',
            'target_attr'    => $target_id ? [(int) $target_id] : [],
            'target_species' => [],
            'target_group'   => [],
            'target_other'   => '',
        ];
    } elseif ($target_type === 'species') {
        $target_group = [
            'target_type'    => 'species',
            'target_attr'    => [],
            'target_species' => $target_id ? [(int) $target_id] : [],
            'target_group'   => [],
            'target_other'   => '',
        ];
    } elseif ($target_type === 'affiliation' || $target_type === 'group') {
        $aff_ids = koto_resolve_blessing_affiliations($context, $target_id);
        $target_group = [
            'target_type'    => 'group',
            'target_attr'    => [],
            'target_species' => [],
            'target_group'   => $aff_ids,
            'target_other'   => '',
        ];
    }

    $row = [
        'whose_trait'          => $self_target,
        'target_field_group'   => $target_group,
        'trait_type'           => $def['trait_type'],
        'pt_pattern'           => $def['pt_pattern'],
        'blessing_level_value' => $def['blessing_level_value'] ?? '',
        'rate_type'            => $def['rate_type'],
    ];

    if (!empty($def['need_point'])) {
        $row['need_point'] = $def['need_point'];
    }
    if (!empty($def['status_up'])) {
        $row['status_up'] = $def['status_up'];
    }
    if (!empty($def['damage_correction'])) {
        $row['damage_correction'] = $def['damage_correction'];
    }
    if (!empty($def['draw_eff'])) {
        $row['draw_eff'] = $def['draw_eff'];
    }
    if (!empty($def['draw_triat_turn'])) {
        $row['draw_triat_turn'] = $def['draw_triat_turn'];
    }

    foreach ($values as $k => $v) {
        $row[$k] = $v;
    }

    // ギミック対策行のタームID設定
    if (($def['trait_type'] ?? '') === 'gimmick') {
        $gimmick_id = $def['gimmick_id'] ?? 0;
        if (!$gimmick_id && !empty($def['gimmick_slug']) && function_exists('get_term_by')) {
            $t = get_term_by('slug', $def['gimmick_slug'], 'gimmick');
            if ($t && !is_wp_error($t)) {
                $gimmick_id = (int) $t->term_id;
            }
        }
        $row['gimmick'] = $gimmick_id ? [$gimmick_id] : [];
    }

    // 明示的なポイント指定がある場合は上書き
    if (!empty($context['need_point'])) {
        $row['need_point'] = (string) $context['need_point'];
    } elseif (!empty($context['point'])) {
        $row['need_point'] = (string) $context['point'];
    }

    // pt_pattern : single の場合、need_point が不明・未指定なら 20 をセット
    if (($row['pt_pattern'] ?? '') === 'single') {
        if (!isset($row['need_point']) || trim((string) $row['need_point']) === '') {
            $row['need_point'] = '20';
        }
    }

    return $row;
}

/**
 * CSVの「祝福テンプレート」定義と属性・種族配列、レアリティを受け取り、ACFの行配列へ展開する。
 *
 * 属性・種族を必要とするトークン（attr_killer, species_killer, attr_mitigation）は
 * 必ずインデックス（例: attr_killer1, species_killer2）が付与されている必要があります。
 * インデックスに対応する属性・種族が不足している場合は $warnings に警告が追加されます。
 *
 * @param string $template_def CSVに記載されたテンプレート定義文字列（例: "{hp_up,attr_killer1,species_killer1}"）。
 * @param array<int, string|int> $attrs キラー対象の属性配列（1始まりのインデックスに対応）。
 * @param array<int, string|int> $species キラー対象の種族配列（1始まりのインデックスに対応）。
 * @param mixed $rarity レアリティ値（★5の場合はキラー値が -5 される）。
 * @param array<int, string> &$warnings 属性・種族不足などの警告を格納する参照配列。
 * @return array<int, array<string, mixed>> ACFリピーター行配列。
 */
function koto_expand_blessing_template_to_acf($template_def, array $attrs = [], array $species = [], $rarity = null, array &$warnings = [], array $context = [])
{
    $template_def = trim((string) $template_def);
    if ($template_def === '') {
        return [];
    }

    $is_star5 = koto_is_blessing_star5_rarity($rarity);

    // 属性・種族をタームID配列へ解決（0始まりキーを維持し、インデックス1は index 0 に対応）
    $attr_ids = [];
    foreach ($attrs as $i => $a) {
        $tid = koto_resolve_blessing_term_id($a, 'attribute');
        $attr_ids[$i] = $tid > 0 ? $tid : 0;
    }

    $species_ids = [];
    foreach ($species as $i => $s) {
        $tid = koto_resolve_blessing_term_id($s, 'species');
        $species_ids[$i] = $tid > 0 ? $tid : 0;
    }

    // JSON形式が直接書かれていた場合のフォールバック
    if (strpos($template_def, '{"') === 0 || strpos($template_def, '[{') === 0) {
        $decoded = json_decode($template_def, true);
        if (is_array($decoded)) {
            return isset($decoded[0]) ? $decoded : [$decoded];
        }
    }

    $trimmed = trim($template_def, "{} \t\n\r\0\x0B");
    if ($trimmed === '') {
        return [];
    }
    $tokens = array_values(array_filter(array_map('trim', explode(',', $trimmed)), 'strlen'));
    if (empty($tokens)) {
        return [];
    }

    $missing_attrs = [];
    $missing_species = [];

    $rows = [];
    foreach ($tokens as $token) {
        // 1. 属性キラー（インデックス必須: attr_killer1, attr_killer2 等）
        if (preg_match('/^attr_killer_?(\d+)$/i', $token, $m)) {
            $idx_num = (int) $m[1];
            $idx = max(0, $idx_num - 1);
            $target_id = !empty($attr_ids[$idx]) ? $attr_ids[$idx] : null;
            if (!$target_id) {
                $missing_attrs[$idx_num][] = $token;
            }
            $row = koto_build_blessing_token_row('attr_killer', $target_id, $is_star5, $context);
            if ($row) $rows[] = $row;
            continue;
        }

        // 2. 種族キラー（インデックス必須: species_killer1, species_killer2 等）
        if (preg_match('/^species_killer_?(\d+)$/i', $token, $m)) {
            $idx_num = (int) $m[1];
            $idx = max(0, $idx_num - 1);
            $target_id = !empty($species_ids[$idx]) ? $species_ids[$idx] : null;
            if (!$target_id) {
                $missing_species[$idx_num][] = $token;
            }
            $row = koto_build_blessing_token_row('species_killer', $target_id, $is_star5, $context);
            if ($row) $rows[] = $row;
            continue;
        }

        // 3. 属性軽減（インデックス必須: attr_mitigation1, attr_mitigation2 等）
        if (preg_match('/^(?:attr_)?mitigation_?(\d+)$/i', $token, $m)) {
            $idx_num = (int) $m[1];
            $idx = max(0, $idx_num - 1);
            $target_id = !empty($attr_ids[$idx]) ? $attr_ids[$idx] : null;
            if (!$target_id) {
                $missing_attrs[$idx_num][] = $token;
            }
            $row = koto_build_blessing_token_row('attr_mitigation', $target_id, $is_star5, $context);
            if ($row) $rows[] = $row;
            continue;
        }

        // 4. インデックスなしキラー・軽減のフォールバック警告
        if (preg_match('/^attr_killer$/i', $token)) {
            $warnings[] = '属性キラー（attr_killer）にインデックス（例: attr_killer1）が指定されていません。';
            $row = koto_build_blessing_token_row('attr_killer', null, $is_star5, $context);
            if ($row) $rows[] = $row;
            continue;
        }
        if (preg_match('/^species_killer$/i', $token)) {
            $warnings[] = '種族キラー（species_killer）にインデックス（例: species_killer1）が指定されていません。';
            $row = koto_build_blessing_token_row('species_killer', null, $is_star5, $context);
            if ($row) $rows[] = $row;
            continue;
        }
        if (preg_match('/^(?:attr_)?mitigation$/i', $token)) {
            $warnings[] = '属性軽減（attr_mitigation）にインデックス（例: attr_mitigation1）が指定されていません。';
            $row = koto_build_blessing_token_row('attr_mitigation', null, $is_star5, $context);
            if ($row) $rows[] = $row;
            continue;
        }

        // 5. グループドローバフ
        if ($token === 'group_draw_buff') {
            $aff_ids = koto_resolve_blessing_affiliations($context);
            if (empty($aff_ids)) {
                $warnings[] = 'グループドローバフの対象グループ（所属）が未設定です。';
            }
            $row = koto_build_blessing_token_row('group_draw_buff', null, $is_star5, $context);
            if ($row) $rows[] = $row;
            continue;
        }

        // 6. その他の固定トークン（hp_up, atk_up, dodge 等）
        $row = koto_build_blessing_token_row($token, null, $is_star5, $context);
        if ($row) {
            $rows[] = $row;
        }
    }

    // //要チェック: 属性・種族不足時の警告メッセージ文面やフォーマットの調整はここで行えます。
    if (!empty($missing_attrs)) {
        ksort($missing_attrs);
        foreach ($missing_attrs as $idx_num => $toks) {
            $tok_str = implode(', ', array_unique($toks));
            $warnings[] = sprintf('属性インデックス%d（%s）に対する属性が足りません。', $idx_num, $tok_str);
        }
    }
    if (!empty($missing_species)) {
        ksort($missing_species);
        foreach ($missing_species as $idx_num => $toks) {
            $tok_str = implode(', ', array_unique($toks));
            $warnings[] = sprintf('種族インデックス%d（%s）に対する種族が足りません。', $idx_num, $tok_str);
        }
    }

    return $rows;
}

/**
 * 既存キャラクター投稿の blessing_trait_loop からテンプレート定義（インデックス付きトークン形式）を逆抽出する。
 *
 * @param int $post_id 抽出元のキャラクター投稿ID。
 * @param string $template_key CSVに登録するテンプレート名。
 * @return array<string, mixed> 抽出結果の連想配列。
 */
function koto_extract_blessing_template_from_post($post_id, $template_key = 'pattern_custom')
{
    $post_id = (int) $post_id;
    if ($post_id <= 0 || !function_exists('get_field')) {
        return ['csv_line' => '', 'shorthand' => '', 'tokens' => [], 'raw_rows' => []];
    }
    $rows = get_field('blessing_trait_loop', $post_id);
    if (!is_array($rows)) {
        return ['csv_line' => '', 'shorthand' => '', 'tokens' => [], 'raw_rows' => []];
    }

    $tokens = [];
    $default_rows = [];
    $attr_killer_count = 0;
    $species_killer_count = 0;
    $attr_mitigation_count = 0;

    foreach ($rows as $r) {
        $tt = $r['trait_type'] ?? '';
        $su = $r['status_up'] ?? '';
        $dc = $r['damage_correction'] ?? '';
        $de = $r['draw_eff'] ?? '';
        $target_type = $r['target_field_group']['target_type'] ?? '';

        // グループドローバフは pt_pattern が single
        if ($tt === 'draw_eff' && $de === 'atk_buff' && ($target_type === 'group' || $target_type === 'affiliation')) {
            $default_rows[] = $r;
            $tokens[] = 'group_draw_buff';
            continue;
        }

        // ギミック対策
        if ($tt === 'gimmick') {
            $default_rows[] = $r;
            $gimmick_val = $r['gimmick'] ?? null;
            $g_slug = '';
            if (is_object($gimmick_val) && isset($gimmick_val->slug)) {
                $g_slug = $gimmick_val->slug;
            } elseif (is_array($gimmick_val) && !empty($gimmick_val)) {
                $first = reset($gimmick_val);
                if (is_object($first) && isset($first->slug)) {
                    $g_slug = $first->slug;
                } elseif (is_numeric($first)) {
                    $t = function_exists('get_term') ? get_term((int)$first, 'gimmick') : null;
                    if ($t && !is_wp_error($t)) $g_slug = $t->slug;
                }
            } elseif (is_numeric($gimmick_val)) {
                $t = function_exists('get_term') ? get_term((int)$gimmick_val, 'gimmick') : null;
                if ($t && !is_wp_error($t)) $g_slug = $t->slug;
            }
            if ($g_slug !== '') {
                $tokens[] = 'gimmick_' . $g_slug;
            } else {
                $tokens[] = 'gimmick';
            }
            continue;
        }

        if (($r['pt_pattern'] ?? '') !== 'default') {
            continue;
        }
        $default_rows[] = $r;

        if ($tt === 'status_up' && $su === 'hp') {
            $tokens[] = 'hp_up';
        } elseif ($tt === 'status_up' && $su === 'atk') {
            $tokens[] = 'atk_up';
        } elseif ($tt === 'damage_correction' && $dc === 'killer' && $target_type === 'attr') {
            $attr_killer_count++;
            $tokens[] = 'attr_killer' . $attr_killer_count;
        } elseif ($tt === 'damage_correction' && $dc === 'killer' && $target_type === 'species') {
            $species_killer_count++;
            $tokens[] = 'species_killer' . $species_killer_count;
        } elseif ($tt === 'status_up' && $su === 'mitigation') {
            $attr_mitigation_count++;
            $tokens[] = 'attr_mitigation' . $attr_mitigation_count;
        } elseif ($tt === 'status_up' && $su === 'dodge') {
            $tokens[] = 'dodge';
        } else {
            $tokens[] = 'unknown_' . ($tt ?: 'trait');
        }
    }

    $shorthand = '{' . implode(',', $tokens) . '}';
    $csv_line = sprintf('祝福テンプレート,%s,"%s"', $template_key, $shorthand);

    return [
        'post_id'      => $post_id,
        'post_title'   => function_exists('get_the_title') ? get_the_title($post_id) : '',
        'template_key' => $template_key,
        'shorthand'    => $shorthand,
        'tokens'       => $tokens,
        'csv_line'     => $csv_line,
        'raw_rows'     => $default_rows,
    ];
}

/**
 * とくせいパーサー（koto_parse_trait）が生成した1行を祝福リピーター用の構造へ変換する。
 *
 * @param array<string, mixed> $row koto_parse_trait の出力行。
 * @param string $point 必要祝福ポイント。
 * @param bool $is_ex EX祝福かどうか。
 * @param array<string, array<int, array<string, mixed>>> $grouped_csv 種別グループ済みCSV。
 * @return array<int, array<string, mixed>> 変換後の行配列。
 */
function koto_convert_trait_row_to_blessing_rows(array $row, $point, $is_ex, array $grouped_csv)
{
    $point_str = trim((string) $point);

    // 1. 文字変換追加（available_moji_loop 行き）
    if (isset($row['available_moji'])) {
        $row['unlock_place'] = 'blessing';
        if ($point_str !== '') {
            $row['unlock_need_point'] = $point_str;
        }
        return [$row];
    }

    // 2. すごわざ発動条件追加（sugowaza_condition 行き）
    if (($row['trait_type'] ?? '') === 'sugowaza_condition' && !empty($row['sugowaza_condition'])) {
        $cond_raw = (string) $row['sugowaza_condition'];
        $cond_preprocessed = koto_preprocess_text($cond_raw, 'すごわざ発動条件');
        $cond_rows = koto_parse_sugowaza_condition($cond_preprocessed, $grouped_csv, 'auto_input_sugowaza_condition');
        if (!empty($cond_rows)) {
            $out = [];
            foreach ($cond_rows as $c_row) {
                $c_row['get_palce'] = $is_ex ? 'ex_blessing' : 'blessing';
                if ($point_str !== '') {
                    $c_row['need_blessing_point'] = $point_str;
                }
                $c_row['_target_repeater'] = 'sugowaza_condition';
                $out[] = $c_row;
            }
            return $out;
        }
    }

    // 3. 通常の祝福とくせい行（blessing_trait_loop / blessing_ex_trait_loop 行き）
    $row['pt_pattern'] = 'single';
    if ($point_str !== '') {
        $row['need_point'] = $point_str;
    } else {
        // pt_pattern : single の場合、need_pointが不明な場合20をセット
        $row['need_point'] = '20';
    }
    if (array_key_exists('trait_rate', $row)) {
        if (!isset($row['blessing_value']) || $row['blessing_value'] === '') {
            $row['blessing_value'] = (string) $row['trait_rate'];
        }
        unset($row['trait_rate']);
    }

    return [$row];
}

/**
 * 祝福・EX祝福の入力データを正規化する。
 *
 * @param mixed $input 入力データ。
 * @return array{template: string, attrs: array<int, string>, species: array<int, string>, rarity: mixed, rows: array<int, array{point: string, text: string}>}
 */
function koto_normalize_blessing_input($input)
{
    $normalized = [
        'template'     => '',
        'attrs'        => [],
        'species'      => [],
        'affiliations' => [],
        'rarity'       => null,
        'rows'         => [],
        'items'        => [],
    ];

    if (is_string($input)) {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return $normalized;
        }
        if ((strpos($trimmed, '{') === 0 || strpos($trimmed, '[') === 0)) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $input = $decoded;
            }
        }
    }

    if (is_array($input)) {
        if (array_key_exists('items', $input) || array_key_exists('template', $input) || array_key_exists('rows', $input) || array_key_exists('attrs', $input) || array_key_exists('attr', $input) || array_key_exists('species', $input) || array_key_exists('affiliations', $input) || array_key_exists('affiliation', $input) || array_key_exists('group', $input) || array_key_exists('rarity', $input)) {
            $normalized['template'] = trim((string) ($input['template'] ?? ''));
            $raw_attrs = $input['attrs'] ?? ($input['attr'] ?? []);
            $raw_species = $input['species'] ?? [];
            $raw_affiliations = $input['affiliations'] ?? ($input['affiliation'] ?? ($input['group'] ?? []));
            $normalized['attrs'] = koto_normalize_blessing_param_array($raw_attrs);
            $normalized['species'] = koto_normalize_blessing_param_array($raw_species);
            $normalized['affiliations'] = koto_normalize_blessing_param_array($raw_affiliations);
            $normalized['rarity'] = $input['rarity'] ?? null;

            if (!empty($input['items']) && is_array($input['items'])) {
                foreach ($input['items'] as $it) {
                    if (is_array($it)) {
                        $itype = trim((string) ($it['type'] ?? ''));
                        if ($itype === 'token') {
                            $t_name = trim((string) ($it['token'] ?? ''));
                            if ($t_name !== '') {
                                $normalized['items'][] = [
                                    'type'   => 'token',
                                    'token'  => $t_name,
                                    'target' => trim((string) ($it['target'] ?? '')),
                                ];
                            }
                        } elseif ($itype === 'free') {
                            $f_pt = trim((string) ($it['point'] ?? ($it['pt'] ?? ($it['need_point'] ?? ''))));
                            $f_text = trim((string) ($it['text'] ?? ''));
                            if ($f_text !== '') {
                                $normalized['items'][] = [
                                    'type'  => 'free',
                                    'point' => $f_pt,
                                    'text'  => $f_text,
                                ];
                            }
                        }
                    }
                }
            }

            if (!empty($input['rows']) && is_array($input['rows'])) {
                foreach ($input['rows'] as $r) {
                    if (is_array($r)) {
                        $pt = trim((string) ($r['point'] ?? ($r['need_point'] ?? ($r['pt'] ?? ''))));
                        $txt = trim((string) ($r['text'] ?? ''));
                        if ($txt !== '') {
                            $normalized['rows'][] = ['point' => $pt, 'text' => $txt];
                        }
                    } elseif (is_string($r) && trim($r) !== '') {
                        $sub = koto_normalize_blessing_input($r);
                        $normalized['rows'] = array_merge($normalized['rows'], $sub['rows']);
                    }
                }
            }
            return $normalized;
        }

        foreach ($input as $r) {
            if (is_array($r)) {
                $pt = trim((string) ($r['point'] ?? ($r['need_point'] ?? ($r['pt'] ?? ''))));
                $txt = trim((string) ($r['text'] ?? ''));
                if ($txt !== '') {
                    $normalized['rows'][] = ['point' => $pt, 'text' => $txt];
                }
            }
        }
        return $normalized;
    }

    if (is_string($input)) {
        $lines = preg_split('/[\r\n｜|]+/u', $input, -1, PREG_SPLIT_NO_EMPTY);
        foreach ((array) $lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if (preg_match('/^@template\s*[:：]\s*([a-zA-Z0-9_\-{}]+)(?:\((.*?)\))?$/u', $line, $m)) {
                $normalized['template'] = trim($m[1]);
                if (!empty($m[2])) {
                    $param_pairs = preg_split('/[,、;；]+/u', $m[2], -1, PREG_SPLIT_NO_EMPTY);
                    foreach ((array) $param_pairs as $pair) {
                        if (preg_match('/^(attrs?|species|affiliations?|groups?|rarity)\s*=\s*(.+)$/u', trim($pair), $pm)) {
                            $pkey = strtolower($pm[1]);
                            $raw_val = trim($pm[2]);
                            if ($pkey === 'attr' || $pkey === 'attrs') {
                                $normalized['attrs'] = array_merge($normalized['attrs'], koto_normalize_blessing_param_array($raw_val));
                            } elseif ($pkey === 'species') {
                                $normalized['species'] = array_merge($normalized['species'], koto_normalize_blessing_param_array($raw_val));
                            } elseif ($pkey === 'affiliation' || $pkey === 'affiliations' || $pkey === 'group' || $pkey === 'groups') {
                                $normalized['affiliations'] = array_merge($normalized['affiliations'], koto_normalize_blessing_param_array($raw_val));
                            } elseif ($pkey === 'rarity') {
                                $normalized['rarity'] = $raw_val;
                            }
                        }
                    }
                }
                continue;
            }

            if (preg_match('/^(?:\[|【)?\s*(\d+)\s*(?:pt|PT|ポイント)?\s*(?:\]|】)?\s*[:：]?\s*(.+)$/u', $line, $m) && (strpos($line, 'pt') !== false || strpos($line, 'PT') !== false || strpos($line, 'ポイント') !== false || preg_match('/^(?:\[|【)?\s*\d+\s*(?:\]|】|:|：)/u', $line))) {
                $normalized['rows'][] = [
                    'point' => trim($m[1]),
                    'text'  => trim($m[2]),
                ];
            } else {
                $normalized['rows'][] = [
                    'point' => '',
                    'text'  => $line,
                ];
            }
        }
    }

    return $normalized;
}

/**
 * 「祝福」「EX祝福」入力データを解析し、タイプ1（テンプレート）→タイプ2（とくせい流用）の順でACF行配列を返す。
 *
 * @param mixed $input 構造化配列、JSON文字列、またはテキスト記法。
 * @param array<string, array<int, array<string, mixed>>> $grouped_csv 種別グループ済みCSV。
 * @param string $input_key 'auto_input_blessing' または 'auto_input_ex_blessing'。
 * @param array<string, mixed> $context 実行コンテキスト（'rarity', 'post_id' 等）。
 * @return array<int, array<string, mixed>> 解析されたACF行配列。
 */
function koto_parse_blessing($input, $grouped_csv, $input_key = 'auto_input_blessing', array $context = [])
{
    $is_ex = ($input_key === 'auto_input_ex_blessing');
    $normalized = koto_normalize_blessing_input($input);

    $rarity = $normalized['rarity'] !== null ? $normalized['rarity'] : ($context['rarity'] ?? null);
    $post_id = isset($context['post_id']) ? (int) $context['post_id'] : 0;
    if ($rarity === null && $post_id > 0) {
        $rarity = wp_get_object_terms($post_id, 'rarity');
    }

    if (!isset($GLOBALS['koto_last_blessing_parse_meta']) || !is_array($GLOBALS['koto_last_blessing_parse_meta'])) {
        $GLOBALS['koto_last_blessing_parse_meta'] = [];
    }
    $meta = [
        'unknown_template' => null,
        'unparsed_rows'    => [],
        'type1_count'      => 0,
        'type2_count'      => 0,
        'warnings'         => [],
    ];

    $type1_rows = [];
    $type2_rows = [];

    // ★新方式: items 配列（トークン・自由入力混在の並び順）による処理
    if (!empty($normalized['items'])) {
        $ordered_rows = [];
        $is_star5 = koto_is_blessing_star5_rarity($rarity, $post_id);
        $reg = koto_get_blessing_token_registry();

        foreach ($normalized['items'] as $it) {
            if (($it['type'] ?? '') === 'token') {
                $base_token = $it['token'] ?? '';
                $def = $reg[$base_token] ?? null;
                $target_id = null;
                if ($def) {
                    $ttype = $def['target_type'] ?? 'self';
                    $raw_target = $it['target'] ?? '';
                    if ($ttype === 'attr') {
                        $target_id = koto_resolve_blessing_term_id($raw_target, 'attribute');
                        if (!$target_id && $raw_target !== '') {
                            $meta['warnings'][] = sprintf('トークン「%s」の対象属性「%s」を解決できませんでした。', $def['label'] ?? $base_token, $raw_target);
                        }
                    } elseif ($ttype === 'species') {
                        $target_id = koto_resolve_blessing_term_id($raw_target, 'species');
                        if (!$target_id && $raw_target !== '') {
                            $meta['warnings'][] = sprintf('トークン「%s」の対象種族「%s」を解決できませんでした。', $def['label'] ?? $base_token, $raw_target);
                        }
                    } elseif ($ttype === 'affiliation' || $ttype === 'group') {
                        $target_id = $raw_target !== '' ? $raw_target : null;
                    }
                }
                $token_context = array_merge($context, [
                    'post_id'      => $post_id,
                    'affiliations' => !empty($normalized['affiliations']) ? $normalized['affiliations'] : ($context['affiliations'] ?? ($context['affiliation'] ?? [])),
                ]);
                $row = koto_build_blessing_token_row($base_token, $target_id, $is_star5, $token_context);
                if ($row) {
                    $ordered_rows[] = $row;
                } else {
                    $meta['warnings'][] = sprintf('未登録の祝福トークンです: %s', $base_token);
                }
            } elseif (($it['type'] ?? '') === 'free') {
                $point = $it['point'] ?? '';
                $raw_text = trim((string) ($it['text'] ?? ''));
                if ($raw_text === '') {
                    continue;
                }

                // ギミック単体文言（例:「チェンジガード」「チェンジガード・スーパー化」「ウォールブレイカー」など）の直接解決
                $gimmick_tok = koto_resolve_blessing_token_key($raw_text);
                if ($gimmick_tok && strpos($gimmick_tok, 'gimmick_') === 0) {
                    $row_ctx = array_merge($context, [
                        'post_id' => $post_id,
                        'point'   => $point !== '' ? $point : '20',
                    ]);
                    $g_row = koto_build_blessing_token_row($gimmick_tok, null, $is_star5, $row_ctx);
                    if ($g_row) {
                        $ordered_rows[] = $g_row;
                        continue;
                    }
                }

                $preprocessed = koto_preprocess_text($raw_text, 'とくせい');
                $parsed_traits = koto_parse_trait($preprocessed, $grouped_csv, $input_key);
                if (empty($parsed_traits)) {
                    $meta['unparsed_rows'][] = $raw_text;
                    continue;
                }

                foreach ($parsed_traits as $t_row) {
                    $converted = koto_convert_trait_row_to_blessing_rows($t_row, $point, $is_ex, $grouped_csv);
                    foreach ($converted as $c_row) {
                        $ordered_rows[] = $c_row;
                    }
                }
            }
        }

        $meta['type1_count'] = count($ordered_rows);
        $GLOBALS['koto_last_blessing_parse_meta'][$input_key] = $meta;
        return $ordered_rows;
    }

    // 1. タイプ1: テンプレート注入
    if ($normalized['template'] !== '') {
        $tpl_name = $normalized['template'];
        $tpl_csv_rows = $grouped_csv['祝福テンプレート'] ?? [];
        $matched_tpl_def = null;

        if (strpos($tpl_name, '{') === 0) {
            $matched_tpl_def = $tpl_name;
        } else {
            foreach ($tpl_csv_rows as $csv_row) {
                if (trim((string) ($csv_row['文言'] ?? '')) === $tpl_name) {
                    $matched_tpl_def = (string) ($csv_row['ACFに入力するJSON'] ?? '');
                    break;
                }
            }
        }

        if ($matched_tpl_def !== null) {
            $tpl_warnings = [];
            $tpl_context = array_merge($context, [
                'post_id'      => $post_id,
                'affiliations' => !empty($normalized['affiliations']) ? $normalized['affiliations'] : ($context['affiliations'] ?? ($context['affiliation'] ?? [])),
            ]);
            $type1_rows = koto_expand_blessing_template_to_acf(
                $matched_tpl_def,
                $normalized['attrs'],
                $normalized['species'],
                $rarity,
                $tpl_warnings,
                $tpl_context
            );
            $meta['type1_count'] = count($type1_rows);
            if (!empty($tpl_warnings)) {
                $meta['warnings'] = array_merge($meta['warnings'], $tpl_warnings);
            }
        } else {
            $meta['unknown_template'] = $tpl_name;
            $meta['warnings'][] = sprintf('未登録の祝福テンプレートです: %s', $tpl_name);
        }
    }

    // 2. タイプ2: とくせい自動入力の流用＋祝福ポイント設定
    foreach ($normalized['rows'] as $row_item) {
        $point = $row_item['point'] ?? '';
        $raw_text = trim((string) ($row_item['text'] ?? ''));
        if ($raw_text === '') {
            continue;
        }

        // ギミック単体文言（例:「チェンジガード」「チェンジガード・スーパー化」「ウォールブレイカー」など）の直接解決
        $gimmick_tok = koto_resolve_blessing_token_key($raw_text);
        if ($gimmick_tok && strpos($gimmick_tok, 'gimmick_') === 0) {
            $row_ctx = array_merge($context, [
                'post_id' => $post_id,
                'point'   => $point !== '' ? $point : '20',
            ]);
            $is_star5 = koto_is_blessing_star5_rarity($rarity, $post_id);
            $g_row = koto_build_blessing_token_row($gimmick_tok, null, $is_star5, $row_ctx);
            if ($g_row) {
                $type2_rows[] = $g_row;
                continue;
            }
        }

        $preprocessed = koto_preprocess_text($raw_text, 'とくせい');
        $parsed_traits = koto_parse_trait($preprocessed, $grouped_csv, $input_key);
        if (empty($parsed_traits)) {
            $meta['unparsed_rows'][] = $raw_text;
            continue;
        }

        foreach ($parsed_traits as $t_row) {
            $converted = koto_convert_trait_row_to_blessing_rows($t_row, $point, $is_ex, $grouped_csv);
            foreach ($converted as $c_row) {
                $type2_rows[] = $c_row;
            }
        }
    }
    $meta['type2_count'] = count($type2_rows);
    $GLOBALS['koto_last_blessing_parse_meta'][$input_key] = $meta;

    // タイプ1の後ろにタイプ2を配置して返却
    return array_merge($type1_rows, $type2_rows);
}

/**
 * 直近の祝福・EX祝福パースで発生した警告（属性・種族不足等）を取得する。
 *
 * @return array<int, string> 警告メッセージ一覧。
 */
function koto_get_blessing_auto_input_warnings()
{
    $warnings = [];
    if (!empty($GLOBALS['koto_last_blessing_parse_meta']) && is_array($GLOBALS['koto_last_blessing_parse_meta'])) {
        foreach ($GLOBALS['koto_last_blessing_parse_meta'] as $key => $meta) {
            $prefix_label = ($key === 'auto_input_ex_blessing') ? 'EX祝福' : '祝福';
            if (!empty($meta['warnings']) && is_array($meta['warnings'])) {
                foreach ($meta['warnings'] as $w) {
                    $warnings[] = sprintf('%s: %s', $prefix_label, $w);
                }
            }
        }
    }
    return $warnings;
}
