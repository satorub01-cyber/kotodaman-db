import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const repoRoot = path.resolve(__dirname, '..');
const specDir = path.join(repoRoot, 'testdata', 'spec-json');
const outputPath = path.join(repoRoot, 'lib', 'character-search', 'all_characters_search.json');

const ATTR_NUM = {
  fire: 1,
  water: 2,
  wood: 3,
  light: 4,
  dark: 5,
  void: 6,
  heaven: 7,
  rainbow: 8,
};

const SPECIES_NUM = {
  god: 1,
  demon: 2,
  hero: 3,
  dragon: 4,
  beast: 5,
  spirit: 6,
  artifact: 7,
  yokai: 8,
};

const UNLOCK_MAP = {
  default: 'def',
  first_trait: '1',
  second_trait: '2',
  blessing: 'bl',
  super_change: 'schange',
  super_copy: 'scopy',
  super_both: 'sboth',
};

const CONNECTOR = new Set(['い', 'う', 'ん']);
const SMALL_YUYO = new Set(['ゅ', 'ょ']);
const AXIS_I = new Set(['あ', 'か', 'さ', 'た', 'な', 'は', 'ま', 'や', 'ら', 'わ', 'が', 'ざ', 'だ', 'ば', 'ぱ', 'え', 'け', 'せ', 'て', 'ね', 'へ', 'め', 'れ', 'げ', 'ぜ', 'で', 'べ', 'ぺ', 'す', 'ず']);
const AXIS_U = new Set(['く', 'す', 'つ', 'ふ', 'ゆ', 'ぐ', 'ず', 'づ', 'ぶ', 'ぷ', 'お', 'こ', 'そ', 'と', 'の', 'ほ', 'も', 'よ', 'ろ', 'ご', 'ぞ', 'ど', 'ぼ', 'ぽ']);
const AXIS_YOUON = new Set(['き', 'し', 'ち', 'に', 'ひ', 'み', 'り', 'ぎ', 'じ', 'ぢ', 'び', 'ぴ', 'う', 'ゃ']);

const GIMMICK_LABELS = {
  shield: 'シールドブレイカー',
  needle: 'トゲガード',
  change: 'チェンジガード',
  weakening: '弱体ガード',
  wall: 'ウォールブレイカー',
  shock: 'ビリビリガード',
  healing: 'ヒールブレイカー',
  copy: 'コピーガード',
  freezing: 'フリーズブレイカー',
  landmine: '地雷ガード',
  smash: 'スマッシュブレイカー',
  balloon: 'バルーンガード',
  healing_core: 'ヒールコア',
  attack_core: 'アタックコア',
  attack_buff_core: 'アタックバフコア',
  super_attack_core: 'スーパーアタックコア',
};

const STATUS_LABELS = {
  poison: '毒',
  sleep: '睡眠',
  curse: '呪い',
  confusion: '混乱',
  pollution: '汚染',
  burn: '炎上',
  remodel: '改造',
  weakness: '衰弱',
  mutation: '変異',
  erasure: '消去',
  all: '全て',
};

const TRAIT_LABELS = {
  give_trait: '特性付与',
  damage_correction: '火力補正',
  damage_correction_oneself: '自身の威力up',
  damage_correction_killer: 'キラー',
  damage_correction_break_limit: '自身の上限解放',
  damage_correction_single_shot: '単体単発補正',
  damage_correction_week_killer: '弱点キラー',
  status_up: 'ステータス・クリティカル補正',
  status_up_atk: 'ATKUP',
  status_up_hp: 'HPUP',
  status_up_critical_rate: 'クリティカル率',
  status_up_critical_damage: 'クリティカルダメージ',
  status_up_resistance: '状態異常耐性',
  status_up_healing_effect: '回復効果UP',
  status_up_mitigation: 'ダメージ軽減',
  status_up_dodge: '心眼回避',
  draw_eff: 'ドロー時効果',
  draw_eff_atk_buff: '攻撃バフ',
  draw_eff_def_buff: '防御バフ',
  draw_eff_healing: '回復',
  draw_eff_status_healing: '状態異常回復',
  on_play_eff: '実体時効果',
  on_play_eff_atk_buff: '攻撃バフ',
  on_play_eff_def_buff: '防御バフ',
  new_traits: '新とくせい',
  new_traits_support: '応援',
  new_traits_see_through: '看破',
  new_traits_assistance: '援護',
  new_traits_resonance_atk: '共鳴',
  new_traits_resonance_crit: 'クリティカル共鳴',
  new_traits_poke: '牽制',
  after_attack: '反撃・腐敗など',
  after_attack_counter: 'わざ反撃',
  after_attack_sugo_counter: 'すごわざ反撃',
  after_attack_corruption: '腐敗',
  after_attack_reflection: 'ダメージ反射',
  mode_shift: 'モードシフト・変身',
  mode_shift_mode_shift: 'モードシフト',
  mode_shift_transform: '変身',
  other: 'その他',
  other_combo_plus: 'コンボ＋',
  other_penetration: 'バリア貫通',
  other_over_healing: 'オーバーヒール',
  other_exp_up: '経験値UP',
  other_pressure_break: '重圧の上限解放',
  other_other: 'その他の固有とくせい',
};

function uniqueBilingualPairs(pairs) {
  const seen = new Set();
  const en = [];
  const jp = [];

  for (const pair of pairs) {
    const enValue = String(pair.en || '').trim();
    const jpValue = String(pair.jp || '').trim();
    if (!enValue || !jpValue) continue;

    const key = `${enValue}\t${jpValue}`;
    if (seen.has(key)) continue;

    seen.add(key);
    en.push(enValue);
    jp.push(jpValue);
  }

  return { en, jp };
}

function gimmickSlugToLabel(slug) {
  if (GIMMICK_LABELS[slug]) {
    return GIMMICK_LABELS[slug];
  }

  if (slug.startsWith('super_')) {
    const baseSlug = slug.slice('super_'.length);
    const baseLabel = GIMMICK_LABELS[baseSlug] || baseSlug;
    return `スーパー${baseLabel}`;
  }

  return slug;
}

function collectStatusResistancePairs(statusSlugs) {
  return uniqueBilingualPairs(
    statusSlugs.map((slug) => ({
      en: String(slug || '').trim(),
      jp: STATUS_LABELS[String(slug || '').trim()] || '',
    })),
  );
}

function extractTraitContents(section) {
  if (!section || typeof section !== 'object') {
    return [];
  }

  if (Array.isArray(section.contents)) {
    return section.contents;
  }

  return Array.isArray(section) ? section : [];
}

function getTraitWhoseType(trait) {
  const whose = trait?.whose ?? 'self';
  let whoseType = '';

  if (whose && typeof whose === 'object' && !Array.isArray(whose)) {
    whoseType = String(whose.type || '').trim();
  } else {
    whoseType = String(whose || '').trim();
  }

  return whoseType || 'self';
}

function normalizeTraitSearchSlugs(trait) {
  const type = String(trait?.type || '').trim();
  if (!type) return [];

  const canonicalType = type === 'other_traits' ? 'other' : type;
  const slugs = [canonicalType];

  if (canonicalType === 'mode_shift') {
    const relation = String(trait?.shift_relation || trait?.relation_ship || '').trim();
    if (relation === 'mode_shift') {
      slugs.push('mode_shift_mode_shift');
    } else if (relation === 'before_transform' || relation === 'after_transform') {
      slugs.push('mode_shift_transform');
    }
  } else {
    let subType = String(trait?.sub_type || '').trim();
    if (subType === 'healling') {
      subType = 'healing';
    }

    if (canonicalType === 'new_traits' && (subType === 'resonance' || subType === 'resonance_crit')) {
      const hasCritResonance = Boolean(
        trait?.crit_rate
        || trait?.crit_damage
        || trait?.resonance_crit_rate
        || trait?.resonance_crit_damage
        || subType === 'resonance_crit',
      );
      subType = hasCritResonance ? 'resonance_crit' : 'resonance_atk';
    }

    if (subType) {
      slugs.push(`${canonicalType}_${subType}`);
    }
  }

  if (getTraitWhoseType(trait) !== 'self') {
    slugs.push('give_trait');
  }

  return [...new Set(slugs.filter(Boolean))];
}

function collectTraitPairs(traits) {
  const pairs = [];

  for (const trait of traits) {
    for (const slug of normalizeTraitSearchSlugs(trait)) {
      if (!TRAIT_LABELS[slug]) continue;
      pairs.push({ en: slug, jp: TRAIT_LABELS[slug] });
    }
  }

  return uniqueBilingualPairs(pairs);
}

function collectAxisTags(chars) {
  const tags = [];

  for (const char of chars) {
    const value = char?.val || '';
    if (CONNECTOR.has(value)) tags.push('char_connector');
    if (SMALL_YUYO.has(value)) tags.push('char_small_yuyo');
    if (AXIS_I.has(value)) tags.push('axis_i');
    if (AXIS_U.has(value)) tags.push('axis_u');
    if (AXIS_YOUON.has(value)) tags.push('axis_youon');
  }

  return [...new Set(tags)];
}

function flattenTargetGroup(item) {
  const type = item?.type || '';
  const objects = Array.isArray(item?.obj) ? item.obj : [];

  if (type === 'self' || type === 'all') {
    return { ty: type, slgs: '' };
  }

  const slgs = objects
    .map((object) => {
      const slug = object?.slug || '';
      const name = object?.name || '';

      if (type === 'other') return name;
      if (type === 'attr') return slug ? (ATTR_NUM[slug] || 1) : 1;
      if (type === 'species') return slug ? (SPECIES_NUM[slug] || 1) : 1;
      return slug;
    })
    .filter((value) => value !== '');

  return { ty: type, slgs };
}

function flattenLeader(item) {
  return {
    ty: item?.type || '',
    cond: (item?.conditions || []).map((condition) => ({
      ty: condition?.type || '',
      vals: condition?.val || [],
      tgts: (condition?.cond_targets || []).map((target) => ({
        ...flattenTargetGroup(target),
        ttl: target?.total_tf || false,
        num: target?.need_num || 0,
      })),
    })),
    lm_wave: item?.limit_wave || 0,
    per_unit: item?.per_unit || false,
    effs: (item?.main_eff || []).map((effect) => {
      const mergedValues = {};
      for (const valueRaw of effect?.value_raws || []) {
        const key = valueRaw?.status === 'resistance' ? (valueRaw?.resist || '') : (valueRaw?.status || '');
        if (key) {
          mergedValues[key] = valueRaw?.value || 0;
        }
      }

      return {
        tgts: (effect?.targets || []).map(flattenTargetGroup),
        vals: mergedValues,
      };
    }),
    oth_val: item?.exp ?? item?.buff_count ?? 0,
    convs: item?.converge_rate || {},
    trn: item?.turn_count || 0,
  };
}

function buildCharacter(spec) {
  const chars = (spec.chars || []).map((item) => ({
    val: item?.val || '',
    attr: ATTR_NUM[String(item?.attr || '').trim()] || 0,
    unlock: UNLOCK_MAP[String(item?.unlock || 'default').trim()] || 'def',
  }));

  const groups = uniqueBilingualPairs(
    (spec.groups || []).map((item) => ({
      en: item?.slug || '',
      jp: item?.name || '',
    })),
  );

  const gimmickPairs = [];
  const trait1Contents = extractTraitContents(spec.trait1);
  const trait2Contents = extractTraitContents(spec.trait2);
  const blessingContents = extractTraitContents(spec.blessing);
  const traitContents = [
    ...trait1Contents,
    ...trait2Contents,
    ...blessingContents,
  ];

  for (const trait of traitContents) {
    if ((trait?.type || '') !== 'gimmick' || !trait?.sub_type) {
      continue;
    }

    gimmickPairs.push({
      en: trait.sub_type,
      jp: gimmickSlugToLabel(trait.sub_type),
    });
  }

  const gimmicks = uniqueBilingualPairs(gimmickPairs);
  const trait1Pairs = collectTraitPairs(trait1Contents);
  const trait2Pairs = collectTraitPairs(trait2Contents);
  const blessingPairs = collectTraitPairs(blessingContents);
  const traitStatusResistances = collectStatusResistancePairs(
    traitContents
      .filter((trait) => (trait?.type || '') === 'status_up' && (trait?.sub_type || '') === 'resistance')
      .map((trait) => trait?.resist_status || ''),
  );
  const leaderStatusResistances = collectStatusResistancePairs(
    (spec.leader || []).flatMap((leader) =>
      (leader?.main_eff || []).flatMap((effect) =>
        (effect?.value_raws || [])
          .filter((valueRaw) => (valueRaw?.status || '') === 'resistance')
          .map((valueRaw) => valueRaw?.resist || ''),
      ),
    ),
  );
  const rarity = spec.rarity || 0;
  const rarityDetail = spec.rarity_detail || 'none';
  const rarityTags = [];
  if (rarity) rarityTags.push(String(rarity));
  if (rarityDetail) rarityTags.push(rarityDetail);

  return {
    id: spec.id || 0,
    thumb_url: '',
    name: spec.name || '',
    pre_name: spec.pre_evo_name || '',
    ano_name: spec.another_image_name || '',
    name_ruby: spec.name_ruby || '',
    chars,
    attr: ATTR_NUM[spec.attribute] || 0,
    sub_attrs: (spec.sub_attributes || []).map((item) => ATTR_NUM[item] || 0).filter(Boolean),
    spe: SPECIES_NUM[spec.species] || 0,
    group_en: groups.en,
    group_jp: groups.jp,
    events: [],
    rar: rarity,
    rar_d: rarityDetail,
    rar_t: [...new Set(rarityTags)],
    date: spec.release_date || '',
    cv: spec.cv || '',
    acq: spec.acquisition || '',
    hp99: spec._val_99_hp || 0,
    atk99: spec._val_99_atk || 0,
    hp120: spec._val_120_hp || 0,
    atk120: spec._val_120_atk || 0,
    hptal: spec.talent_hp || 0,
    atktal: spec.talent_atk || 0,
    pri: spec.priority || 0,
    hnd_buff: spec.buff_counts_hand || [0, 0, 0, 0, 0, 0],
    bd_buff: spec.buff_counts_board || [0, 0, 0, 0, 0, 0],
    debuf: spec.debuff_counts || [0, 0, 0, 0, 0, 0],
    gimmick_en: gimmicks.en,
    gimmick_jp: gimmicks.jp,
    trait_status_resistance_en: traitStatusResistances.en,
    trait_status_resistance_jp: traitStatusResistances.jp,
    leader_status_resistance_en: leaderStatusResistances.en,
    leader_status_resistance_jp: leaderStatusResistances.jp,
    leader: (spec.leader || []).map(flattenLeader),
    ls_hp: spec.max_ls_hp || 0,
    ls_atk: spec.max_ls_atk || 0,
    axis: collectAxisTags(chars),
    waza_t: '',
    sugo_t: '',
    koto_t: '',
    trait1_en: trait1Pairs.en,
    trait1_jp: trait1Pairs.jp,
    trait2_en: trait2Pairs.en,
    trait2_jp: trait2Pairs.jp,
    blessing_en: blessingPairs.en,
    blessing_jp: blessingPairs.jp,
  };
}

async function main() {
  const fileNames = (await fs.readdir(specDir))
    .filter((fileName) => fileName.endsWith('.json'))
    .sort();

  const output = [];
  for (const fileName of fileNames) {
    const filePath = path.join(specDir, fileName);
    const raw = await fs.readFile(filePath, 'utf8');
    const spec = JSON.parse(raw);
    output.push(buildCharacter(spec));
  }

  await fs.writeFile(outputPath, JSON.stringify(output, null, 0), 'utf8');
  process.stdout.write(`Generated ${output.length} characters to ${outputPath}\n`);
}

main().catch((error) => {
  process.stderr.write(`${error.stack || error.message}\n`);
  process.exitCode = 1;
});
