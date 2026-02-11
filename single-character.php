<?php //通常ページとAMPページの切り分け

/**
 * Cocoon WordPress Theme
 * @author: yhira
 * @link: https://wp-cocoon.com/
 * @license: http://www.gnu.org/licenses/gpl-2.0.html GPL v2 or later
 */
if (!defined('ABSPATH')) exit;

if (!is_amp()) {
    get_header();
} else {
    cocoon_template_part('tmp/amp-header');
}
?>

<?php //投稿ページ内容
cocoon_template_part('tmp/single-contents'); ?>

<div class="character-search-box">
    <?php get_search_form(); ?>
</div>

<?php
// =================================================================
//  関連キャラ（モードシフト・変身）のタブ切り替え表示
// =================================================================
$related_chars = get_field('same_character');

if ($related_chars):
    $current_id = get_the_ID(); // 今見ているページのID
?>
    <div class="character-tab-section">
        <ul class="char-tab-nav">
            <?php foreach ($related_chars as $char_post):
                $char_id = $char_post->ID;
                $char_title = get_the_title($char_id);
                $char_link = get_permalink($char_id);
                $is_current = ($char_id === $current_id);
            ?>
                <li class="char-tab-item <?php if ($is_current) echo 'active'; ?>">
                    <?php if ($is_current): ?>
                        <span class="tab-text"><?php echo esc_html($char_title); ?></span>
                    <?php else: ?>
                        <a href="<?php echo esc_url($char_link); ?>" class="tab-link">
                            <?php echo esc_html($char_title); ?>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php
// =================================================================
//  基本データの呼び出し
// =================================================================
$post_id = get_the_ID();
$raw_data = get_post_meta($post_id, '_spec_json', true);
$spec_data = null;
if (is_string($raw_data)) {
    $spec_data = json_decode($raw_data, true);
} elseif (is_array($raw_data)) {
    $spec_data = $raw_data;
}

// ★追加: データがない場合はその場で計算して取得する（フォールバック）
if (empty($spec_data) && function_exists('get_character_spec_data')) {
    $spec_data = get_character_spec_data($post_id);
}

// ★追加: 火力指数が未計算の場合、その場で計算して補完する
if (empty($spec_data['firepower_index']) && function_exists('_calculate_firepower_index') && !empty($spec_data)) {
    $spec_data['firepower_index'] = _calculate_firepower_index($spec_data);
}

// -----------------------------------------------------------
// ステータス取得 (JSON優先)
// -----------------------------------------------------------
// Lv99
$disp_hp_99  = $spec_data['_val_99_hp'] ?? get_post_meta($post_id, '99_hp', true);
$disp_atk_99 = $spec_data['_val_99_atk'] ?? get_post_meta($post_id, '99_atk', true);
// Lv120
$disp_hp_120  = $spec_data['_val_120_hp'] ?? get_post_meta($post_id, '120_hp', true);
$disp_atk_120 = $spec_data['_val_120_atk'] ?? get_post_meta($post_id, '120_atk', true);
// 才能開花 (JSONから取得)
$talent_hp  = $spec_data['talent_hp'] ?? 0;
$talent_atk = $spec_data['talent_atk'] ?? 0;

// 未入力チェック
if ($disp_hp_99 === '' || $disp_hp_99 === null) $disp_hp_99 = '未入力';
if ($disp_atk_99 === '' || $disp_atk_99 === null) $disp_atk_99 = '未入力';

// 偏差値計算
$score_hp  = (is_numeric($disp_hp_99) && function_exists('get_koto_deviation_score'))
    ? get_koto_deviation_score($disp_hp_99, 'total_99_hp') : '-';
$score_atk = (is_numeric($disp_atk_99) && function_exists('get_koto_deviation_score'))
    ? get_koto_deviation_score($disp_atk_99, 'total_99_atk') : '-';

// -----------------------------------------------------------
// タクソノミー・フィールド情報の取得 (JSON活用)
// -----------------------------------------------------------
$attr_slug = $spec_data['attribute'] ?? '';
$attr_term = $attr_slug ? get_term_by('slug', $attr_slug, 'attribute') : null;

$species_slug = $spec_data['species'] ?? '';
$species_term = $species_slug ? get_term_by('slug', $species_slug, 'species') : null;

$rarity_slug = $spec_data['rarity'] ?? '';
$rarity_term = $rarity_slug ? get_term_by('slug', $rarity_slug, 'rarity') : null;
$rarity_label = $rarity_term ? $rarity_term->name : 'なし';

// 所属 (JSONにはスラッグ配列が入っているため、オブジェクトを取得してメインを判定)
$group_slugs = $spec_data['groups'] ?? [];
$group_terms = [];
foreach ($group_slugs as $gs) {
    $t = get_term_by('slug', $gs, 'affiliation');
    if ($t) $group_terms[] = $t;
}
$affiliation_term = function_exists('get_primary_affiliation_obj') ? get_primary_affiliation_obj($group_terms) : ($group_terms[0] ?? null);

// 実装日
$release_date = $spec_data['release_date'] ?? get_field('実装月（わかれば実装日）');

// 進化前名前など (JSONに含まれていないものは get_field)
$pre_evo_name = $spec_data['pre_evo_name'] ?? get_field('pre_evo_name');
$another_img_name = $spec_data['another_image_name'] ?? get_field('another_image_name');
$cv = $spec_data['cv'] ?? get_field('voice_actor');
$acquisition = $spec_data['acquisition'] ?? get_field('get_place');

?>

<div class="character-visual">
    <?php
    // =========================================================
    // 1. データ取得
    // =========================================================

    // --- 進化前画像 ---
    $pre_img_id = get_field('pre_evo_image');
    $pre_img_html = $pre_img_id ? wp_get_attachment_image($pre_img_id, 'full') : '';

    // --- 進化後（通常）画像 ---
    $main_img_id = get_field('character_image');
    $main_img_url = $main_img_id ? wp_get_attachment_image_url($main_img_id, 'full') : '';

    // --- 進化後（アナザー/モードシフト）画像 ---
    $sub_img_id = get_field('another_character_image');
    $sub_img_url = $sub_img_id ? wp_get_attachment_image_url($sub_img_id, 'full') : '';

    // --- 切り替え用リスト作成 ---
    $variations = [];
    if ($main_img_url) {
        $variations[] = ['url' => $main_img_url];
    }
    if ($sub_img_url) {
        $variations[] = ['url' => $sub_img_url];
    }
    ?>

    <div class="visual-flex-container">

        <?php if ($pre_img_html): ?>
            <div class="img-box pre-evo-box">
                <?php echo $pre_img_html; ?>
                <span class="img-label">進化前</span>
            </div>
            <div class="evo-arrow">➡</div>
        <?php endif; ?>

        <div class="img-box main-evo-box">
            <?php if ($variations): ?>
                <img id="js-main-char-img"
                    src="<?php echo esc_url($variations[0]['url']); ?>"
                    alt="キャラクター画像"
                    style="width:100%; height:100%; object-fit:cover;">
            <?php else: ?>
                <div class="no-image">
                    <p>NO IMAGE</p>
                </div>
            <?php endif; ?>

            <span class="img-label">進化後</span>
        </div>
    </div>

    <?php if (count($variations) > 1): ?>
        <div class="char-variant-thumbs" style="display:flex; justify-content:center; gap:10px; margin-top:15px;">
            <?php foreach ($variations as $index => $item): ?>
                <div class="var-thumb <?php if ($index === 0) echo 'active'; ?>"
                    onclick="switchCharImageOnly(this, '<?php echo esc_url($item['url']); ?>')"
                    style="width:60px; height:60px; border-radius:10px; overflow:hidden; cursor:pointer; border:2px solid #ddd; transition:0.2s; position:relative;">

                    <img src="<?php echo esc_url($item['url']); ?>" style="width:100%; height:100%; object-fit:cover;">

                    <span style="position:absolute; bottom:0; left:0; width:100%; background:rgba(0,0,0,0.5); color:#fff; font-size:10px; text-align:center; display:block;">
                        <?php echo $index + 1; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>

        <script>
            // 画像だけを切り替える関数
            function switchCharImageOnly(el, url) {
                // 1. 画像切り替え
                const mainImg = document.getElementById('js-main-char-img');
                if (mainImg) {
                    mainImg.style.opacity = 0.5; // フェード演出
                    setTimeout(() => {
                        mainImg.src = url;
                        mainImg.style.opacity = 1;
                    }, 100);
                }

                // 2. サムネイルの枠線切り替え
                document.querySelectorAll('.var-thumb').forEach(t => {
                    t.style.borderColor = '#ddd';
                    t.style.opacity = '0.6';
                });
                el.style.borderColor = '#ffa545';
                el.style.opacity = '1';
            }

            // 初期状態設定
            document.addEventListener('DOMContentLoaded', function() {
                const thumbs = document.querySelectorAll('.var-thumb');
                if (thumbs.length > 0) {
                    thumbs[0].style.borderColor = '#ffa545';
                    thumbs[0].style.opacity = '1';
                }
            });
        </script>
    <?php endif; ?>
</div>

<div class="character-title-area">
    <h1 class="entry-title"><?php the_title(); ?></h1>
</div>

<div class="spec-table">
    <dl class="spec-row row-attribute">
        <dt>属性</dt>
        <dd>
            <?php
            $term = $attr_term;
            if ($term):
                $icon_html = get_term_icon_html($term, 'attr-icon-img');
                echo '<a href="' . get_term_link($term) . '">' . ($icon_html ?: $term->name) . '</a>';
            else: echo '未入力';
            endif;
            ?>
        </dd>
    </dl>

    <dl class="spec-row row-species">
        <dt>種族</dt>
        <dd>
            <?php
            $term = $species_term;
            if ($term):
                $icon_html = get_term_icon_html($term, 'species-icon-img');
                echo '<a href="' . get_term_link($term) . '">' . ($icon_html ?: $term->name) . '</a>';
            else: echo '未入力';
            endif;
            ?>
        </dd>
    </dl>

    <dl class="spec-row">
        <dt>レアリティ</dt>
        <dd><?php echo $rarity_label; ?></dd>
    </dl>

    <dl class="spec-row">
        <dt>所属</dt>
        <dd><?php if ($affiliation_term && !is_wp_error($affiliation_term)): echo '<a href="' . get_term_link($affiliation_term) . '">' . $affiliation_term->name . '</a>';
            else: echo 'なし';
            endif; ?></dd>
    </dl>

    <dl class="spec-row full-width">
        <dt>文字</dt>
        <dd class="moji-list">
            <?php
            // JSONの 'chars' 配列を活用
            $chars_data = $spec_data['chars'] ?? [];
            $links = [];

            if (!empty($chars_data)) {
                foreach ($chars_data as $c) {
                    $val = esc_html($c['val']);
                    $slug = $c['slug'] ?? '';
                    $attr_slug = $c['attr'] ?? 'none';
                    $place = $c['unlock'] ?? 'normal';

                    $suffix = '';
                    if ($place === 'super_copy') {
                        $suffix = '<span style="font-size:0.85em; color:#333; margin-left:2px;">(Sコピー)</span>';
                    } elseif ($place === 'super_change') {
                        $suffix = '<span style="font-size:0.85em; color:#333; margin-left:2px;">(Sチェンジ)</span>';
                    }

                    $term_link = '#';
                    if ($slug) {
                        $t = get_term_by('slug', $slug, 'available_moji');
                        if ($t && !is_wp_error($t)) $term_link = get_term_link($t);
                    }

                    $colored_char = '<span class="char-font attr-' . esc_attr($attr_slug) . '">' . $val . '</span>';
                    $links[] = '<a href="' . esc_url($term_link) . '">' . $colored_char . $suffix . '</a>';
                }
            }
            echo !empty($links) ? implode('・', $links) : '未入力';
            ?>
        </dd>
    </dl>

    <dl class="spec-row full-width">
        <dt>入手方法、実装イベントなど</dt>
        <dd>
            <?php
            $events = get_the_terms(get_the_ID(), 'event');
            if ($events && !is_wp_error($events)):
                $event_links = [];
                foreach ($events as $ev) {
                    $event_links[] = '<a href="' . get_term_link($ev) . '">' . esc_html($ev->name) . '</a>';
                }
                echo implode('・', $event_links);
            else:
                echo '未設定';
            endif;
            ?>
        </dd>
    </dl>

    <dl class="spec-row full-width">
        <dt>実装日</dt>
        <dd><?php echo $release_date ?: '-'; ?></dd>
    </dl>

    <dl class="spec-row full-width">
        <dt>進化前の名前</dt>
        <dd><?php echo $pre_evo_name ?: '-'; ?></dd>
    </dl>
    <?php if (!empty($another_img_name)): ?>
        <dl class="spec-row full-width">
            <dt>絵違いの名前</dt>
            <dd><?php echo $another_img_name ?: '-'; ?></dd>
        </dl>
    <?php endif; ?>

    <dl class="spec-row full-width">
        <dt>声優</dt>
        <dd>
            <?php
            echo $cv ? esc_html($cv) : '未入力';
            ?>
        </dd>
    </dl>

    <?php
    $get_type = get_field('get_place');
    if ($get_type === 'other'):
        $is_break = get_field('fuku_break');
    ?>
        <dl class="spec-row">
            <dt>言塊突破・福</dt>
            <dd><?php echo $is_break ? 'あり' : 'なし'; ?></dd>
        </dl>
    <?php endif; ?>
</div>
<table class="status-table">
    <thead>
        <tr>
            <th class="st-col-head"></th>
            <th class="st-col-head">HP</th>
            <th class="st-col-head">ATK</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <th class="st-row-label">Lv.99</th>
            <td class="st-val"><?php echo $disp_hp_99; ?></td>
            <td class="st-val"><?php echo $disp_atk_99; ?></td>
        </tr>
        <tr>
            <th class="st-row-label">Lv.120</th>
            <td class="st-val"><?php echo $disp_hp_120; ?></td>
            <td class="st-val"><?php echo $disp_atk_120; ?></td>
        </tr>
        <tr>
            <th class="st-row-label">才能開MAX</th>
            <td class="st-val"><?php echo $talent_hp > 0 ? '+' . number_format($talent_hp) : '-'; ?></td>
            <td class="st-val"><?php echo $talent_atk > 0 ? '+' . number_format($talent_atk) : '-'; ?></td>
        </tr>
        <?php if (false): ?>
            <tr class="row-deviation">
                <th class="st-row-label">偏差値<span class="small-note">(Lv99)</span></th>
                <td class="st-val st-score"><?php echo $score_hp; ?></td>
                <td class="st-val st-score"><?php echo $score_atk; ?></td>
            </tr>
        <?php endif; ?>
        <?php /* ▲▲▲ ここまで ▲▲▲ */ ?>
    </tbody>
</table>
</dd>

<div class="firepower-container">
    <div class="firepower-header">
        <span class="fp-label">火力指数</span>
        <?php if (!empty($spec_data['is_estimate'])): ?>
            <span class="fp-est-badge">予想倍率による計算</span>
        <?php endif; ?>
    </div>
    <div class="firepower-value-area">
        <?php
        $fp_index = $spec_data['firepower_index'] ?? 0;
        echo ($fp_index > 0) ? '<span class="fp-val">' . number_format($fp_index) . '</span>' : '<span class="fp-val-none">-</span>';
        ?>
    </div>
    <div class="firepower-note">※LV120・才能開花なし<br>計算が正確でない場合があります</div>
</div>

<?php
// =================================================================
//  EXスキル (グランドコトダマン)
// =================================================================
$ex_name = get_field('ex_skill_name');
$ex_label = get_field('ex_skill_label');
$ex_desc = get_field('ex_skill_discription');
$search_group = get_field('search_priority');
$priority_text = function_exists('get_koto_target_label') ? get_koto_target_label($search_group) : '';

$add_effect_type = get_field('additional_effect'); // buff, debuff
$effect_target   = get_field('effect_target');
$effect_val      = get_field('effect_value');
$effect_turn = get_field('turn_count');

$target_map = ['selected' => '選択したコトダマン', 'hand_ally' => '手札の味方', 'all_oppo' => '敵全体', 'single_oppo' => '敵単体'];
$target_text = isset($target_map[$effect_target]) ? $target_map[$effect_target] : '対象';
$param_text  = ($add_effect_type === 'buff') ? 'ATK' : 'DEF';
$suffix_text = ($add_effect_type === 'buff') ? 'バフ' : 'デバフ';
$turn_text = $effect_turn ?  "{$effect_turn}ターンの間、" : '';
$auto_summary = "{$target_text}に{$turn_text}{$param_text}{$effect_val}段階{$suffix_text}";

if ($ex_name || $ex_label): // 名前か種類のどちらかがあれば表示
?>
    <div class="skill-card card-ex">
        <div class="skill-badge-area">
            <span class="skill-badge badge-ex">EXスキル</span>
        </div>
        <div class="skill-text-area">
            <div class="ex-accordion-container">
                <div class="ex-acc-trigger">
                    <div class="ex-trigger-content">
                        <div class="ex-header-line">
                            <?php if (!empty($ex_label)): ?>
                                <div class="skill-proper-name" style="font-weight:bold; font-size:1.1em; margin-bottom:3px;">
                                    <?php echo esc_html($ex_label); ?>
                                </div>
                            <?php endif; ?>

                            <div style="font-size: 0.95em; color: #555;">
                                <span class="ex-skill-name" style="font-weight:bold;">&lt;<?php echo esc_html($ex_name); ?>&gt;</span>
                                <?php if ($ex_name === "サーチ"): ?>
                                    <span class="ex-priority-text">(<?php echo esc_html($priority_text); ?>優先)</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="ex-effect-line" style="margin-top:5px;">
                            <span class="label-tag tag-effect">効果</span>
                            <span class="ex-description"><?php echo $auto_summary; ?></span>
                        </div>
                    </div>
                    <div class="ex-acc-icon">▼</div>
                </div>
                <?php if ($ex_desc): ?>
                    <div class="ex-acc-content" style="display: none;">
                        <div class="ex-detail-text">
                            <?php echo nl2br(esc_html($ex_desc)); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
// =================================================================
//  チャージスキル (新規実装)
// =================================================================
$charge_name = get_field('charge_skill_name');
$need_charge = get_field('need_charge');
$charge_loop = get_field('charge_skill_loop');

if ($charge_name || $charge_loop):
?>
    <div class="skill-card card-charge">
        <div class="skill-badge-area">
            <span class="skill-badge badge-charge">チャージスキル</span>
        </div>
        <div class="skill-text-area">
            <div class="ex-header-line" style="margin-bottom: 0.5em; border-bottom: 1px dashed #eee; padding-bottom: 5px;">
                <span class="skill-proper-name" style="font-weight:bold;"><?php echo esc_html($charge_name); ?></span>
                <?php if ($need_charge): ?>
                    <span style="font-size: 0.9em; color: #666; margin-left: 10px;">(必要チャージ: <?php echo esc_html($need_charge); ?>)</span>
                <?php endif; ?>
            </div>

            <div class="skill-row">
                <span class="label-tag tag-effect">効果</span>
                <div class="skill-text-block">
                    <?php
                    if ($charge_loop && is_array($charge_loop)):
                        $c_count = 1;
                        foreach ($charge_loop as $item):
                            // --- 1. ターゲット名の生成 ---
                            $tgt_key = $item['effect_target'];
                            $tgt_label = '';

                            // 基本ターゲットマップ
                            $base_map = [
                                'single_oppo' => '敵単体',
                                'all_oppo'    => '敵全体',
                                'single_hand' => '手札の味方1体',
                                'board_ally'  => '味方', // 盤面
                                'hand_ally'   => '手札の味方'
                            ];

                            if (isset($base_map[$tgt_key])) {
                                $tgt_label = $base_map[$tgt_key];
                            }
                            // 条件付きターゲット (board_part:盤面, hand_part:手札)
                            elseif ($tgt_key === 'board_part' || $tgt_key === 'hand_part') {
                                $cond_g = $item['target_cond_group'];

                                // 既存の関数が使える場合は利用してラベル生成
                                if (function_exists('get_koto_target_label') && !empty($cond_g)) {
                                    $label_raw = get_koto_target_label($cond_g);
                                    // 関数は「～の味方」を付けることが多いので、文脈に合わせて調整
                                    $tgt_label = str_replace(['の味方', '味方'], '', $label_raw);
                                } else {
                                    $tgt_label = '指定の味方';
                                }

                                // 文脈（手札か盤面か）で修飾
                                if ($tgt_key === 'hand_part') {
                                    $tgt_label = "手札の{$tgt_label}";
                                }
                            }

                            // --- 2. 効果テキストの生成 ---
                            $type = $item['charge_type'];
                            $val  = $item['charge_skill_value']; // 数値
                            $turn = $item['effect_turn'];        // ターン数
                            $turn_txt = $turn ? "{$turn}ターンの間、" : "";
                            $effect_text = "";

                            // 状態異常名のマップ
                            $st_map = ['poison' => '毒', 'sleep' => '睡眠', 'curse' => '呪い', 'confusion' => '混乱', 'pollution' => '汚染', 'burn' => '炎上', 'remodel' => '改造', 'weakness' => '衰弱', 'mutation' => '変異', 'erasure' => '消去', 'all' => '全て'];

                            switch ($type) {
                                case 'healing':
                                    $effect_text = "HPを{$val}回復";
                                    break;
                                case 'atk_buff':
                                    $effect_text = "{$tgt_label}に{$turn_txt}ATK{$val}段階バフを付与";
                                    break;
                                case 'atk_debuff':
                                    $effect_text = "{$tgt_label}に{$turn_txt}ATK{$val}段階デバフを付与";
                                    break;
                                case 'def_buff':
                                    $effect_text = "{$tgt_label}に{$turn_txt}DEF{$val}段階バフを付与";
                                    break;
                                case 'def_debuff':
                                    $effect_text = "{$tgt_label}に{$turn_txt}DEF{$val}段階デバフを付与";
                                    break;
                                case 'resistance': // 状態異常回復
                                    $res_list = $item['target_resistance'];
                                    $res_names = [];
                                    if ($res_list) {
                                        foreach ((array)$res_list as $r) {
                                            $res_names[] = isset($st_map[$r]) ? $st_map[$r] : $r;
                                        }
                                    }
                                    $res_str = implode('・', $res_names);
                                    $effect_text = "{$tgt_label}の{$res_str}を回復";
                                    break;
                                case 'resistance_barrier': // 状態異常バリア
                                    $res_list = $item['target_resistance'];
                                    $res_names = [];
                                    if ($res_list) {
                                        foreach ((array)$res_list as $r) {
                                            $res_names[] = isset($st_map[$r]) ? $st_map[$r] : $r;
                                        }
                                    }
                                    $res_str = implode('・', $res_names);
                                    // 状態異常バリアはターン数が入力可能
                                    $effect_text = "{$tgt_label}に{$turn_txt}{$res_str}を防ぐバリアを展開";
                                    break;
                                case 'barrier': // 無敵バリア
                                    // ACF設定上、バリア選択時は「数値」「ターン数」が入力不可のため固定文言
                                    $effect_text = "敵から受けるダメージを1回ダメージを無効化するバリアを展開";
                                    break;
                            }

                            // --- 3. 出力 ---
                            if ($effect_text) {
                                echo "<div class='skill-effect-line'>";
                                echo "<span class='effect-num'>({$c_count}) </span>";
                                echo $effect_text;
                                echo "</div>";
                                $c_count++;
                            }

                        endforeach;
                    endif;
                    ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
// =================================================================
//  リーダーとくせい
// =================================================================
$ls_html = function_exists('get_koto_leader_skill_html') ? get_koto_leader_skill_html() : '';

if ($ls_html): ?>
    <div class="skill-card card-leader">
        <div class="skill-badge-area"><span class="skill-badge badge-leader">リーダーとくせい</span></div>
        <div class="skill-row">
            <span class="label-tag tag-effect">効果</span>
            <div class="skill-text-block">
                <div class="skill-text-area"><?php echo $ls_html; ?></div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
// =================================================================
//  わざ・すごわざ・コトワザ 表示エリア
// =================================================================
ob_start();
$waza_groups = get_field('waza_group_loop');
$waza_name = get_field('waza_name');

if ($waza_groups):
    echo '<div class="skill-card card-waza">';
    echo '<div class="skill-badge-area">';
    echo '<span class="skill-badge badge-waza">わざ</span>';
    if ($waza_name) echo '<span class="skill-proper-name">' . esc_html($waza_name) . '</span>';
    echo '</div>';
    echo '<div class="skill-text-area">';
    if (function_exists('get_koto_sugowaza_html')) echo get_koto_sugowaza_html(null, $waza_groups, 'waza');
    echo '</div></div>';
endif;
$waza_html = ob_get_clean();

ob_start();
$sugo_condition = get_field('sugowaza_condition');
$sugo_groups    = get_field('sugowaza_group_loop');
$sugo_name = get_field('sugowaza_name');

if ($sugo_condition || $sugo_groups):
    echo '<div class="skill-card card-sugo">';
    echo '<div class="skill-badge-area">';
    echo '<span class="skill-badge badge-sugo">すごわざ</span>';
    if ($sugo_name) echo '<span class="skill-proper-name">' . esc_html($sugo_name) . '</span>';
    echo '</div>';
    echo '<div class="skill-text-area">';
    if (function_exists('get_koto_sugowaza_html')) echo get_koto_sugowaza_html($sugo_condition, $sugo_groups, 'sugo');
    echo '</div></div>';
endif;
$sugo_html = ob_get_clean();

$has_waza = !empty($waza_html);
$has_sugo = !empty($sugo_html);

if ($has_waza || $has_sugo):
?>
    <?php
    // !! をつけることで、どんな値が来ても強制的に true か false に変換します
    $mgn_est = !!get_field('magnification_estimate_tf');
    $koto_mgn_est = !!get_field('koto_magnification_estimate_tf');

    // 条件分岐を三項演算子ではなく、if-elseでハッキリ分ける
    if ($mgn_est) {
        $est_note = '※このページの攻撃/回復倍率は予想です';
    } else {
        $est_note = '※コトワザの攻撃/回復倍率は一部予想です';
    }

    // 両方の変数が確実に boolean なので、判定が正確になります
    if ($mgn_est === true || $koto_mgn_est === true) : 
?>
    <div class='mgn-note-container'>
        <p class='mgn-note'><?php echo esc_html($est_note); ?></p>
    </div>
<?php endif; ?>
    <div class="tab-section-wrapper">
        <?php if ($has_waza && $has_sugo): ?>
            <ul class="tab-nav">
                <li class="tab-item" data-target="panel-waza">わざ</li>
                <li class="tab-item active" data-target="panel-sugo">すごわざ</li>
            </ul>
        <?php endif; ?>
        <div class="tab-content-container">
            <?php if ($has_sugo): ?>
                <div id="panel-sugo" class="tab-panel active"><?php echo $sugo_html; ?></div>
            <?php endif; ?>
            <?php if ($has_waza): ?>
                <div id="panel-waza" class="tab-panel <?php echo (!$has_sugo) ? 'active' : ''; ?>"><?php echo $waza_html; ?></div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php
// =================================================================
//  コトワザ表示エリア
// =================================================================
$koto_outside = get_field('kotowaza_loop_v2');

if ($koto_outside):
    $koto_htmls = [];
    foreach ($koto_outside as $index => $row) {
        $lv = $index;
        $cond_check = isset($row['kotowaza_condition']) ? $row['kotowaza_condition'] : null;
        $loop_check = isset($row['kotowaza_group_loop']) ? $row['kotowaza_group_loop'] : null;

        $html_body = '';
        if (function_exists('get_koto_sugowaza_html')) {
            $html_body = get_koto_sugowaza_html($cond_check, $loop_check, 'kotowaza');
        }

        if ($html_body) {
            $koto_htmls[$lv] = $html_body;
        }
    }
    $koto_levels = [0, 1, 2, 3, 4];
?>
    <div class="tab-section-wrapper kotowaza-section">
        <ul class="tab-nav">
            <?php foreach ($koto_levels as $lv): ?>
                <li class="tab-item <?php if ($lv === 0) echo 'active'; ?>" data-target="koto-panel-<?php echo $lv; ?>">
                    <?php echo $lv; ?>凸
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="tab-content-container">
            <?php foreach ($koto_levels as $lv):
                $final_html = isset($koto_htmls[$lv]) ? $koto_htmls[$lv] : '';
            ?>
                <div id="koto-panel-<?php echo $lv; ?>" class="tab-panel <?php if ($lv === 0) echo 'active'; ?>">
                    <div class="skill-card card-kotowaza">
                        <div class="skill-badge-area">
                            <span class="skill-badge badge-kotowaza">コトわざ</span>
                        </div>
                        <div class="skill-text-area">
                            <?php if ($final_html): echo $final_html;
                            else: ?>
                                <div class="no-data-msg">未入力</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php
// -----とくせい表示-------
$trait1_loop = get_field('first_trait_loop');
$trait1_lines = [];
$add_moji_1 = function_exists('get_koto_add_moji_html') ? get_koto_add_moji_html('first_trait') : '';
if ($add_moji_1) $trait1_lines[] = $add_moji_1;
if ($trait1_loop) {
    foreach ($trait1_loop as $row) {
        $text = function_exists('get_koto_trait_text_from_row') ? get_koto_trait_text_from_row($row) : '';
        if ($text) $trait1_lines[] = $text;
    }
}

$trait2_loop = get_field('second_trait_loop');
$trait2_lines = [];
$add_moji_2 = function_exists('get_koto_add_moji_html') ? get_koto_add_moji_html('second_trait') : '';
if ($add_moji_2) $trait2_lines[] = $add_moji_2;
if ($trait2_loop) {
    foreach ($trait2_loop as $row) {
        $text = function_exists('get_koto_trait_text_from_row') ? get_koto_trait_text_from_row($row) : '';
        if ($text) $trait2_lines[] = $text;
    }
}
?>

<div class="trait-section-wrapper">
    <?php if (!empty($trait1_lines)): ?>
        <div class="skill-card card-trait mb-trait">
            <div class="skill-badge-area">
                <span class="skill-badge badge-trait">とくせい1</span>
                <?php
                $t1_name = get_field('first_trait_name');
                if ($t1_name) echo '<span class="skill-proper-name">' . esc_html($t1_name) . '</span>';
                ?>
            </div>
            <div class="skill-text-area">
                <div class="skill-row"><span class="label-tag tag-effect">効果</span>
                    <div class="skill-text-block">
                        <?php $i = 1;
                        foreach ($trait1_lines as $line): ?>
                            <div class="skill-effect-line"><span class="effect-num">(<?php echo $i; ?>) </span><?php echo $line; ?></div>
                        <?php $i++;
                        endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($trait2_lines)): ?>
        <div class="skill-card card-trait mb-trait">
            <div class="skill-badge-area">
                <span class="skill-badge badge-trait">とくせい2</span>
                <?php
                $t2_name = get_field('second_trait_name');
                if ($t2_name) echo '<span class="skill-proper-name">' . esc_html($t2_name) . '</span>';
                ?>
            </div>
            <div class="skill-text-area">
                <div class="skill-row"><span class="label-tag tag-effect">効果</span>
                    <div class="skill-text-block">
                        <?php $i = 1;
                        foreach ($trait2_lines as $line): ?>
                            <div class="skill-effect-line"><span class="effect-num">(<?php echo $i; ?>) </span><?php echo $line; ?></div>
                        <?php $i++;
                        endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
// ---------------------
// ---------祝福--------
// ---------------------
$blessing_loop = get_field('blessing_trait_loop');
$has_blessing_data = ($blessing_loop && is_array($blessing_loop));

// 1. 文字追加情報の取得
$add_moji_blessing = function_exists('get_koto_add_moji_html') ? get_koto_add_moji_html('blessing') : '';

// 2. ★追加：すごわざ条件解放情報の取得
$add_sugo_blessing_list = function_exists('get_koto_blessing_sugo_list') ? get_koto_blessing_sugo_list() : [];

// 表示判定（すごわざ追加がある場合も表示するよう条件を追加）
if ($has_blessing_data || $add_moji_blessing || !empty($add_sugo_blessing_list)):
?>
    <div class="skill-card card-blessing" style="margin-top: 30px;">
        <div class="skill-badge-area">
            <span class="skill-badge badge-blessing">祝福とくせい</span>
        </div>
        <div class="skill-text-area">
            <div class="skill-row">
                <span class="label-tag tag-effect">効果</span>
                <div class="skill-text-block">
                    <?php
                    $i = 1;

                    // A. 文字追加の表示
                    if ($add_moji_blessing):
                    ?>
                        <div class="skill-effect-line" style="margin-bottom: 10px; border-bottom: 1px dashed #eee; padding-bottom: 5px;">
                            <span class="effect-num">(<?php echo $i; ?>) </span>
                            <?php echo $add_moji_blessing; ?>
                        </div>
                        <?php
                        $i++;
                    endif;

                    // B. ★追加：すごわざ条件追加の表示
                    if (!empty($add_sugo_blessing_list)):
                        foreach ($add_sugo_blessing_list as $line):
                        ?>
                            <div class="skill-effect-line" style="margin-bottom: 10px; border-bottom: 1px dashed #eee; padding-bottom: 5px;">
                                <span class="effect-num">(<?php echo $i; ?>) </span>
                                <?php echo $line; ?>
                            </div>
                            <?php
                            $i++;
                        endforeach;
                    endif;

                    // C. 通常の祝福とくせいループ（既存のまま）
                    if ($has_blessing_data):
                        foreach ($blessing_loop as $row):
                            $levels_data = [];
                            $pt_pattern = isset($row['pt_pattern']) ? $row['pt_pattern'] : 'default';
                            $points = [];

                            if ($pt_pattern === 'default') {
                                $points = [1, 2, 3, 4, 5, 7, 9, 12, 15, 20];
                            } elseif ($pt_pattern === 'single') {
                                $raw_pt = isset($row['need_point']) ? $row['need_point'] : '';
                                $points = [$raw_pt];
                            } elseif ($pt_pattern === 'csv') {
                                $raw_pt = isset($row['need_point']) ? $row['need_point'] : '';
                                $points = $raw_pt !== '' ? array_map('trim', explode(',', $raw_pt)) : [];
                            }

                            $values = [];
                            $lv_count = count($points);

                            if ($pt_pattern === 'single') {
                                $val = isset($row['blessing_value']) ? $row['blessing_value'] : '';
                                $values[] = $val;
                            } else {
                                $calc_type = isset($row['blessing_level_value']) ? $row['blessing_level_value'] : 'csv';
                                if ($calc_type === 'min_max') {
                                    $min = isset($row['min_value']) ? (float)$row['min_value'] : 0;
                                    $max = isset($row['max_value']) ? (float)$row['max_value'] : 0;
                                    $gaps = $lv_count - 1;

                                    if ($gaps > 0) {
                                        $diff = $max - $min;
                                        if ($diff % $gaps === 0) {
                                            $step = $diff / $gaps;
                                            for ($k = 0; $k < $lv_count; $k++) $values[] = $min + ($step * $k);
                                        } elseif ($gaps > 0) {
                                            $val = $min;
                                            $values[] = $val;
                                            $base_step = floor($diff / $gaps);
                                            $remainder = $diff % $gaps;
                                            for ($k = 0; $k < $gaps; $k++) {
                                                $step = $base_step + ($k < $remainder ? 1 : 0);
                                                $val += $step;
                                                $values[] = $val;
                                            }
                                        } else {
                                            $step_float = $diff / $gaps;
                                            for ($k = 0; $k < $lv_count; $k++) $values[] = round($min + ($step_float * $k));
                                        }
                                    } else {
                                        $values[] = $min;
                                    }
                                } else {
                                    $raw_val = isset($row['blessing_value']) ? $row['blessing_value'] : '';
                                    if ($raw_val === '200!') {
                                        $raw_val = '200,225,250,275,300,330,360,390,420,450';
                                    }
                                    $values = $raw_val !== '' ? array_map('trim', explode(',', $raw_val)) : [];
                                }
                            }

                            foreach ($points as $k => $pt) {
                                $val = isset($values[$k]) ? $values[$k] : '';
                                $temp_row = $row;
                                $temp_row['trait_rate'] = $val;

                                $generated_text = function_exists('get_koto_trait_text_from_row') ? get_koto_trait_text_from_row($temp_row) : '';
                                $lv_label = ($pt_pattern === 'single') ? '' : ($k + 1);

                                $levels_data[] = [
                                    'blessing_level' => $lv_label,
                                    'need_point' => $pt,
                                    'generated_text' => $generated_text
                                ];
                            }

                            if (empty($levels_data)) continue;

                            $first_lv = $levels_data[0];
                            $rest_lvs = array_slice($levels_data, 1);
                            $has_rest = !empty($rest_lvs);

                            $text_first = $first_lv['generated_text'];
                            $pt_first   = $first_lv['need_point'];
                            $lv_num_first = $first_lv['blessing_level'];

                            if ($text_first):
                            ?>
                                <div class="skill-effect-line" style="margin-bottom: 10px; border-bottom: 1px dashed #eee; padding-bottom: 5px;">
                                    <div style="display:flex; align-items:flex-start;">
                                        <span class="effect-num" style="margin-right: 5px; margin-top: 2px;">(<?php echo $i; ?>)</span>
                                        <div class="blessing-accordion-wrapper" style="flex:1;">
                                            <div class="blessing-acc-header <?php echo $has_rest ? 'is-toggle' : ''; ?>">
                                                <span class="blessing-level-item">
                                                    <?php if ($lv_num_first): ?>
                                                        <span style="color:#e91e63; font-weight:bold; font-size:0.9em;">Lv.<?php echo $lv_num_first; ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($pt_first): ?>
                                                        <span style="color:#666; font-size:0.85em;">(<?php echo $pt_first; ?>pt)</span>
                                                    <?php endif; ?>
                                                    : <?php echo $text_first; ?>
                                                </span>
                                                <?php if ($has_rest): ?><span class="acc-icon">▼</span><?php endif; ?>
                                            </div>
                                            <?php if ($has_rest): ?>
                                                <div class="blessing-acc-body" style="display: none;">
                                                    <?php foreach ($rest_lvs as $lv_row): ?>
                                                        <div class="blessing-level-item" style="margin-top: 8px; border-top: 1px dotted #f0f0f0; padding-top: 4px;">
                                                            <?php if ($lv_row['blessing_level']): ?>
                                                                <span style="color:#e91e63; font-weight:bold; font-size:0.9em;">Lv.<?php echo $lv_row['blessing_level']; ?></span>
                                                            <?php endif; ?>
                                                            <?php if ($lv_row['need_point']): ?>
                                                                <span style="color:#666; font-size:0.85em;">(<?php echo $lv_row['need_point']; ?>pt)</span>
                                                            <?php endif; ?>
                                                            : <?php echo $lv_row['generated_text']; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                    <?php
                                $i++;
                            endif;
                        endforeach;
                    endif;
                    ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php
// ==============================================
//  5. 倍率詳細表 (ページ最下部エリア)
// ==============================================
$waza_table = get_field('waza_maltiplier_table_group');
$waza_table_html = function_exists('get_koto_multiplier_table_html') ? get_koto_multiplier_table_html($waza_table, null, false) : '';
if ($waza_table_html): ?>
    <div id="rate-table-waza" class="detail-section-part">
        <h4 class="sub-section-title">わざ倍率詳細</h4>
        <?php echo $waza_table_html; ?>
    </div>
<?php endif; ?>

<?php
$sugo_table = get_field('sugowaza_maltiplier_table_group');
$sugo_table_html = function_exists('get_koto_multiplier_table_html') ? get_koto_multiplier_table_html($sugo_table, null, false) : '';
if ($sugo_table_html): ?>
    <div id="rate-table-sugo" class="detail-section-part">
        <h4 class="sub-section-title">すごわざ倍率詳細</h4>
        <?php echo $sugo_table_html; ?>
    </div>
<?php endif; ?>

<?php
$koto_table = get_field('kotowaza_maltiplier_table_group');
if ($koto_table && !empty($koto_table['use_maltiplier_table'])):
    $koto_levels = [0, 1, 2, 3, 4];
    $has_any_data = false;
    if (!empty($koto_table['maltiplier_table'])) $has_any_data = true;

    if ($has_any_data):
?>
        <div id="rate-table-kotowaza" class="detail-section-part" style="margin-top: 30px;">
            <h4 class="sub-section-title">コトわざ倍率詳細</h4>
            <div class="tab-section-wrapper" style="margin-bottom: 0;">
                <ul class="tab-nav">
                    <?php foreach ($koto_levels as $lv): ?>
                        <li class="tab-item <?php if ($lv === 0) echo 'active'; ?>" data-target="koto-table-panel-<?php echo $lv; ?>">
                            <?php echo $lv; ?>凸
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="tab-content-container">
                    <?php foreach ($koto_levels as $lv): ?>
                        <div id="koto-table-panel-<?php echo $lv; ?>" class="tab-panel <?php if ($lv === 0) echo 'active'; ?>">
                            <?php
                            $html = function_exists('get_koto_multiplier_table_html') ? get_koto_multiplier_table_html($koto_table, $lv, false) : '';
                            if ($html): echo $html;
                            else: ?>
                                <div style="padding: 15px; color: #999; font-size: 0.9em;">データなし</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
<?php
    endif;
endif;
?>

<?php
// 火力計算機の表示
if (function_exists('display_koto_damage_calculator')) {
    display_koto_damage_calculator();
}
?>

<?php
// ==============================================
//  4. 関連キャラクター表示エリア
// ==============================================
$current_id = get_the_ID();
$tax_query_args = ['relation' => 'OR'];
$has_terms = false;

$terms_event = get_the_terms($current_id, 'event');
if ($terms_event && !is_wp_error($terms_event)) {
    $event_ids = wp_list_pluck($terms_event, 'term_id');
    $tax_query_args[] = [
        'taxonomy' => 'event',
        'field'    => 'term_id',
        'terms'    => $event_ids,
    ];
    $has_terms = true;
}

$terms_group = get_the_terms($current_id, 'affiliation');
if ($terms_group && !is_wp_error($terms_group)) {
    $group_ids = wp_list_pluck($terms_group, 'term_id');
    $tax_query_args[] = [
        'taxonomy' => 'affiliation',
        'field'    => 'term_id',
        'terms'    => $group_ids,
    ];
    $has_terms = true;
}

if ($has_terms) {
    $related_args = [
        'post_type'      => 'character',
        'posts_per_page' => 14,
        'post__not_in'   => [$current_id],
        'orderby'        => 'rand',
        'tax_query'      => $tax_query_args,
    ];
    $related_query = new WP_Query($related_args);

    if ($related_query->have_posts()):
?>
        <div class="detail-section-part" style="margin-top: 40px;">
            <h4 class="sub-section-title">関連キャラクター</h4>
            <div class="common-char-grid">
                <?php while ($related_query->have_posts()): $related_query->the_post(); ?>
                    <a href="<?php the_permalink(); ?>" class="char-grid-card">
                        <div class="grid-icon-box">
                            <?php
                            $thumb_id = get_field('character_image');
                            if ($thumb_id) {
                                echo wp_get_attachment_image($thumb_id, 'medium', false, ['class' => 'grid-char-img']);
                            } else {
                                echo '<div class="grid-no-img">No Img</div>';
                            }
                            ?>
                        </div>
                        <div class="grid-char-name"><?php the_title(); ?></div>
                    </a>
                <?php endwhile; ?>
            </div>
        </div>
<?php
    endif;
    wp_reset_postdata();
}
?>

<?php
// ==============================================
//  5. 新着キャラクター表示エリア
// ==============================================
$new_args = [
    'post_type'      => 'character',
    'posts_per_page' => 10,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'post__not_in'   => [$current_id],
];
$new_query = new WP_Query($new_args);

if ($new_query->have_posts()):
?>
    <div class="detail-section-part" style="margin-top: 40px;">
        <h4 class="sub-section-title">🆕 新着キャラクター</h4>
        <div class="common-char-grid">
            <?php while ($new_query->have_posts()): $new_query->the_post(); ?>
                <a href="<?php the_permalink(); ?>" class="char-grid-card">
                    <div class="grid-icon-box">
                        <?php
                        $thumb_id = get_field('character_image');
                        if ($thumb_id) {
                            echo wp_get_attachment_image($thumb_id, 'medium', false, ['class' => 'grid-char-img']);
                        } else {
                            echo '<div class="grid-no-img">No Img</div>';
                        }
                        ?>
                    </div>
                    <div class="grid-char-name"><?php the_title(); ?></div>
                </a>
            <?php endwhile; ?>
        </div>
    </div>
<?php
endif;
wp_reset_postdata();
?>

<div id="comment-area" class="detail-section-part" style="margin-top: 50px;">
    <?php
    if (comments_open() || get_comments_number()) {
        comments_template();
    }
    ?>
</div>
</div>
<?php get_footer(); ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('.tab-item');
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const parentNav = this.closest('ul');
                if (parentNav) {
                    parentNav.querySelectorAll('.tab-item').forEach(t => t.classList.remove('active'));
                }
                this.classList.add('active');
                const targetId = this.getAttribute('data-target');
                const targetPanel = document.getElementById(targetId);
                if (targetPanel) {
                    const siblingPanels = targetPanel.parentElement.children;
                    Array.from(siblingPanels).forEach(p => {
                        if (p.classList.contains('trait-panel') || p.classList.contains('tab-panel')) {
                            p.classList.remove('active');
                        }
                    });
                    targetPanel.classList.add('active');
                }
            });
        });
        const blessingHeaders = document.querySelectorAll('.blessing-acc-header.is-toggle');
        blessingHeaders.forEach(header => {
            header.addEventListener('click', function() {
                this.classList.toggle('active');
                const body = this.nextElementSibling;
                if (body && body.classList.contains('blessing-acc-body')) {
                    if (body.style.display === 'none') {
                        body.style.display = 'block';
                    } else {
                        body.style.display = 'none';
                    }
                }
            });
        });
    });
    const exTriggers = document.querySelectorAll('.ex-acc-trigger');
    exTriggers.forEach(trigger => {
        trigger.addEventListener('click', function() {
            this.classList.toggle('active');
            const content = this.nextElementSibling;
            if (content && content.classList.contains('ex-acc-content')) {
                if (content.style.display === 'none') {
                    content.style.display = 'block';
                } else {
                    content.style.display = 'none';
                }
            }
        });
    });
</script>

<?php
// koto-calc.php の関数からキャラの詳細データを取得
$spec = get_character_spec_data(get_the_ID());
?>
<?php
// --- データの準備 ---

// 1. 検索結果に出したい文字のリストを作成
$moji_list = [];
if (! empty($spec['chars']) && is_array($spec['chars'])) {
    foreach ($spec['chars'] as $char) {
        $moji_list[] = $char['val'];
    }
}
$moji_str = implode('、', $moji_list);

// 2. 属性・種族の日本語変換マップ
$slug_map = [
    '火' => 'fire',
    '水' => 'water',
    '木' => 'wood',
    '光' => 'light',
    '闇' => 'dark',
    '冥' => 'void',
    '天' => 'heaven',
];

$slug_map_race = [
    '神' => 'god',
    '魔' => 'demon',
    '英' => 'hero',
    '龍' => 'dragon',
    '獣' => 'beast',
    '霊' => 'spirit',
    '物' => 'artifact',
    '妖' => 'yokai',
];

// 3. 日本語への変換（逆引き）
// $spec['attribute'] が 'dark' なら '闇' が返る
$attr_val = isset($spec['attribute']) ? $spec['attribute'] : '';
$attr_jp  = array_search($attr_val, $slug_map) ?: $attr_val;

$species_val = isset($spec['species']) ? $spec['species'] : '';
$species_jp  = array_search($species_val, $slug_map_race) ?: $species_val;

// 4. Googleに伝える説明文
$description_text = "属性：{$attr_jp} / 種族：{$species_jp} / 文字：{$moji_str}。詳細な情報やわざ倍率をチェックできます！";

// --- 構造化データの作成 (配列で作る) ---
$schema_data = [
    "@context"    => "https://schema.org",
    "@type"       => "Article", // キャラクター単体ページならArticleでOK
    "name"        => wp_strip_all_tags(get_the_title()), // ★ここでタグを除去
    "description" => $description_text,
    "image"       => get_the_post_thumbnail_url(),
    "headline"    => wp_strip_all_tags(get_the_title()), // Articleにはheadlineが推奨されることが多いです（nameと同じでOK）
];
?>

<script type="application/ld+json">
    <?php
    // 日本語をUnicodeエスケープせず、スラッシュもエスケープしない設定で出力
    echo json_encode($schema_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    ?>
</script>