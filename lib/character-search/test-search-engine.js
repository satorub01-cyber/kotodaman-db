#!/usr/bin/env node
/**
 * コトダマンDB フロントエンド検索エンジン（search-engine.js）回帰・性能・セキュリティテスト
 * 実行: node lib/character-search/test-search-engine.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');
const { performance } = require('perf_hooks');

const DIR = __dirname;
const ENGINE_PATH = path.join(DIR, 'search-engine.js');
const JSON_PATH = path.join(DIR, 'all_characters_search.json');

if (!fs.existsSync(JSON_PATH)) {
    console.error(`[ERROR] 検索JSONが見つかりません: ${JSON_PATH}`);
    process.exit(1);
}

const rawCharacters = JSON.parse(fs.readFileSync(JSON_PATH, 'utf8'));
console.log(`Loaded ${rawCharacters.length} characters from all_characters_search.json`);

// 実データに含まれるイベント・グループ・クエストからテスト用日本語マップを構築
const testEventMap = {
    '%e8%a8%80%e9%9c%8a%e7%a5%ad': '言霊祭',
    '%e7%a5%9e%e8%a8%97%e3%81%ae%e3%82%b3%e3%83%88%e3%81%ae%e9%88%b4%e4%ba%a4%e6%8f%9b%e6%89%80': '神託のコトの鈴交換所',
    'test-encoded%20event': 'テストイベント＆限定'
};
const testGroupMap = {};
const testQuestMap = {};

// searchform.js と同じ LEADER_CONSTANTS
const LEADER_CONSTANTS = {
    ATTR_SLUG_TO_ID: {
        fire: 1, water: 2, wood: 3, light: 4,
        dark: 5, void: 6, heaven: 7, rainbow: 8
    },
    SPECIES_SLUG_TO_ID: {
        god: 1, demon: 2, hero: 3, dragon: 4,
        beast: 5, spirit: 6, artifact: 7, yokai: 8
    },
    BUFF_TYPE_MAP: {
        hp: { ty: 'fixed', stat: 'hp', matchBy: 'vals_key' },
        atk: { ty: 'fixed', stat: 'atk', matchBy: 'vals_key' },
        status_resistance: { ty: 'fixed', stat: null, matchBy: 'vals_key' },
        crit_rate: { ty: 'fixed', stat: 'crit_rate', matchBy: 'vals_key' },
        crit_damage: { ty: 'fixed', stat: 'critical_damage', matchBy: 'vals_key' },
        damage_reduction: { ty: 'fixed', stat: 'mitigation', matchBy: 'vals_key' },
        exp_up: { ty: 'exp_up', stat: 'exp', matchBy: 'ty' },
        over_healing: { ty: 'over_healing', stat: 'over_heal', matchBy: 'ty' },
        converged: { ty: 'converged', stat: 'converged', matchBy: 'ty' },
        corruption: { ty: 'corruption', stat: 'corruption_rate', matchBy: 'ty' },
        over_attack: { ty: 'over_attack', stat: 'over_atk', matchBy: 'ty' },
        random_crit: { ty: 'random_crit', stat: 'rand_crit', matchBy: 'ty' }
    }
};

// 軽量サンドボックスで search-engine.js を読み込む
const sandbox = {
    console,
    Intl,
    Set,
    Map,
    URLSearchParams,
    encodeURIComponent,
    decodeURIComponent,
    setTimeout,
    clearTimeout,
    KOTO_EVENT_MAP: testEventMap,
    KOTO_GROUP_MAP: testGroupMap,
    KOTO_SUITABLE_QUEST_MAP: testQuestMap,
    document: {
        addEventListener() {},
        getElementById() { return null; },
        querySelector() { return null; },
        querySelectorAll() { return []; },
        body: { classList: { contains() { return false; } } }
    },
    window: {
        location: { hostname: 'localhost', pathname: '/', search: '' },
        history: { replaceState() {} },
        addEventListener() {},
        KOTO_EVENT_MAP: testEventMap,
        KOTO_GROUP_MAP: testGroupMap,
        KOTO_SUITABLE_QUEST_MAP: testQuestMap,
        LEADER_CONSTANTS
    }
};
sandbox.window.window = sandbox.window;
sandbox.self = sandbox.window;

vm.runInNewContext(fs.readFileSync(ENGINE_PATH, 'utf8'), sandbox, { filename: 'search-engine.js' });
const core = sandbox.window.KotoSearchCore;
assert.ok(core, 'window.KotoSearchCore should be exported');

// =========================================================
// リファレンス実装（最適化前の従来ロジック）
// =========================================================
const ATTR_MAP_REF = {
    1: 'fire', 2: 'water', 3: 'wood', 4: 'light',
    5: 'dark', 6: 'void', 7: 'heaven', 8: 'rainbow'
};
const SPECIES_MAP_REF = {
    1: 'god', 2: 'demon', 3: 'hero', 4: 'dragon',
    5: 'beast', 6: 'spirit', 7: 'artifact', 8: 'yokai'
};

function refFullyDecode(str) {
    let prev = String(str || '');
    try {
        let decoded = decodeURIComponent(prev);
        while (decoded !== prev) {
            prev = decoded;
            decoded = decodeURIComponent(prev);
        }
    } catch (e) {}
    return prev;
}

function refNormalizeForSearch(v) {
    try {
        const value = String(v || '').normalize('NFKC').toLowerCase().trim();
        if (value === '&' || value === '＆') return value;
        return value.replace(/[&＆]/g, '');
    } catch (e) {
        const value = String(v || '').toLowerCase().trim();
        if (value === '&' || value === '＆') return value;
        return value.replace(/[&＆]/g, '');
    }
}

function refSafeIncludes(array, searchElement) {
    if (!array || !Array.isArray(array)) return false;
    const decodedSearch = refFullyDecode(searchElement);
    const normSearch = refNormalizeForSearch(decodedSearch);
    const rawSearch = String(searchElement || '').toLowerCase().trim();

    return array.some(item => {
        const decodedItem = refFullyDecode(item);
        const normItem = refNormalizeForSearch(decodedItem);
        const rawItem = String(item || '').toLowerCase().trim();
        return normItem === normSearch || rawItem === rawSearch;
    });
}

function refCountMatchedValues(searchValues, charValues) {
    const safeSearchValues = Array.isArray(searchValues) ? searchValues : (searchValues ? [searchValues] : []);
    const safeCharValues = Array.isArray(charValues) ? charValues : (charValues ? [charValues] : []);
    const uniqueSearchValues = Array.from(new Set(safeSearchValues.filter(v => v !== null && v !== undefined && String(v).trim() !== '')));
    if (uniqueSearchValues.length === 0 || safeCharValues.length === 0) return 0;
    return uniqueSearchValues.reduce((count, sv) => count + (refSafeIncludes(safeCharValues, sv) ? 1 : 0), 0);
}

function refCheckRelationCondition(checkedValues, charValues, relation) {
    if (checkedValues.length === 0) return true;
    const safeCharValues = Array.isArray(charValues) ? charValues : (charValues ? [charValues] : []);
    if (relation === 'AND') {
        return checkedValues.every(val => refSafeIncludes(safeCharValues, val));
    }
    return checkedValues.some(val => refSafeIncludes(safeCharValues, val));
}

function refNormalizeTraitTagValue(value) {
    const normalizedValue = String(value || '').trim();
    if (!normalizedValue) return '';
    if (normalizedValue === 'give_trait') return normalizedValue;
    return normalizedValue.replace(/^trait_/, '');
}

function refFilterAndSort(characters, state, sortKey = 'name_ruby', sortOrder = 'ASC') {
    const keyword = refNormalizeForSearch(state.rawKeyword);
    const keywordTerms = keyword ? keyword.split(/\s+/u).filter(Boolean) : [];
    const searchChar = refNormalizeForSearch(state.rawSearchChar);
    const searchCv = refNormalizeForSearch(state.rawSearchCv);
    const hasCharSearch = Boolean(searchChar);
    const hasGimmickSearch = state.checkedGimmicks.length > 0;

    const charCounts = new Map();
    const gimmickCounts = new Map();

    const hits = characters.filter(char => {
        if (state.checkedAttrs.length > 0) {
            const mainSlug = ATTR_MAP_REF[char.attr] || '';
            const subSlugs = (char.sub_attrs || []).map(a => ATTR_MAP_REF[a] || '');
            if (state.subAttrToggle === 'OR') {
                if (!refCheckRelationCondition(state.checkedAttrs, [mainSlug, ...subSlugs], state.relAttr)) return false;
            } else {
                if (!refCheckRelationCondition(state.checkedAttrs, [mainSlug], state.relAttr)) return false;
            }
        }

        const charAllEvents = [
            ...(char.events || []),
            ...(char['sub-event'] || char.sub_event || []),
            ...(char.gerilla_root || char.guerilla_root || [])
        ];

        if (keywordTerms.length > 0) {
            const eventNamesJp = charAllEvents.map(slug => {
                const decoded = refFullyDecode(slug);
                return testEventMap[slug] || testEventMap[decoded] || decoded;
            });
            const keywordTargets = [
                char.name,
                char.name_ruby,
                ...eventNamesJp,
                ...(Array.isArray(char.group_jp) ? char.group_jp : []),
                ...(Array.isArray(char.gimmick_jp) ? char.gimmick_jp : []),
                ...(Array.isArray(char.trait1_jp) ? char.trait1_jp : []),
                ...(Array.isArray(char.trait2_jp) ? char.trait2_jp : []),
                ...(Array.isArray(char.blessing_jp) ? char.blessing_jp : []),
                ...(Array.isArray(char.leader_status_resistance_jp) ? char.leader_status_resistance_jp : []),
                ...(state.includeTraitStatusResistance && Array.isArray(char.trait_status_resistance_jp) ? char.trait_status_resistance_jp : [])
            ];

            const hasKeywordMatch = keywordTerms.every(term => {
                const normTerm = refNormalizeForSearch(term);
                const rawTerm = String(term || '').normalize('NFKC').toLowerCase().trim();
                return keywordTargets.some(value => {
                    const normValue = refNormalizeForSearch(value);
                    if (normValue.includes(normTerm)) return true;
                    if ((rawTerm === '&' || rawTerm === '＆') && String(value || '').normalize('NFKC').toLowerCase().trim().includes(rawTerm)) {
                        return true;
                    }
                    return false;
                });
            });
            if (!hasKeywordMatch) return false;
        }

        if (searchCv) {
            if (!char.cv || !refNormalizeForSearch(char.cv).includes(searchCv)) return false;
        }

        if (searchChar) {
            const targetChars = Array.from(new Set(searchChar)).filter(c => c.trim() !== '' && c !== ',' && c !== '、');
            if (targetChars.length > 0) {
                const charVals = char.chars ? char.chars.map(c => c.val) : [];
                const matchedCount = refCountMatchedValues(targetChars, charVals);
                charCounts.set(char.id, matchedCount);
                if (matchedCount === 0) return false;
            }
        }

        if (!refCheckRelationCondition(state.checkedSpecies, SPECIES_MAP_REF[char.spe] || '', state.relSpecies)) return false;
        if (!refCheckRelationCondition(state.checkedRarities, char.rar_t, state.relRarity)) return false;
        if (!refCheckRelationCondition(state.checkedGroups, char.group_en, state.relGroup)) return false;
        if (!refCheckRelationCondition(state.checkedEvents, charAllEvents, state.relEvent)) return false;
        if (!refCheckRelationCondition(state.checkedQuests, char.quests, state.relQuest)) return false;
        if (!refCheckRelationCondition(state.checkedGimmicks, char.gimmick_en, state.relGimmick)) return false;
        if (state.checkedGimmicks.length > 0) {
            gimmickCounts.set(char.id, refCountMatchedValues(state.checkedGimmicks, char.gimmick_en));
        }
        if (!refCheckRelationCondition(state.checkedAcqs, char.acq, state.relAcq)) return false;
        if (!refCheckRelationCondition(state.checkedAxises, char.axis, state.relAxis)) return false;

        if (state.checkedPriorities.length > 0) {
            const priStr = char.pri ? String(char.pri) : '';
            if (!state.checkedPriorities.includes(priStr)) return false;
        }

        if (state.checkedSkillTags.length > 0) {
            if (state.checkedSkillScopes.length === 0) return false;
            const checkSkill = tag => {
                const s = ` ${tag} `;
                return (state.checkedSkillScopes.includes('waza') && char.waza_t && char.waza_t.includes(s)) ||
                    (state.checkedSkillScopes.includes('sugo') && char.sugo_t && char.sugo_t.includes(s)) ||
                    (state.checkedSkillScopes.includes('kotowaza') && char.koto_t && char.koto_t.includes(s));
            };
            const ok = state.relSkillTags === 'AND' ? state.checkedSkillTags.every(checkSkill) : state.checkedSkillTags.some(checkSkill);
            if (!ok) return false;
        }

        if (state.checkedTraitTags.length > 0) {
            if (state.checkedTraitScopes.length === 0) return false;
            const checkTrait = tag => {
                const nt = refNormalizeTraitTagValue(tag);
                if (!nt) return false;
                return (state.checkedTraitScopes.includes('t1') && refSafeIncludes(char.trait1_en, nt)) ||
                    (state.checkedTraitScopes.includes('t2') && refSafeIncludes(char.trait2_en, nt)) ||
                    (state.checkedTraitScopes.includes('blessing') && refSafeIncludes(char.blessing_en, nt));
            };
            const ok = state.relTraitTags === 'AND' ? state.checkedTraitTags.every(checkTrait) : state.checkedTraitTags.some(checkTrait);
            if (!ok) return false;
        }

        if (state.leaderConditions.length > 0) {
            if (!char.leader || char.leader.length === 0) return false;
            const ok = state.leaderConditions.some(cond => char.leader.some(ldr => core.matchLeaderEffect(ldr, cond)));
            if (!ok) return false;
        }

        return true;
    });

    const getRefSortValue = (c, k) => {
        switch (k) {
            case 'buff_board': return c.bd_buff && c.bd_buff.length > 5 ? Number(c.bd_buff) : 0;
            case 'buff_hand': return c.hnd_buff && c.hnd_buff.length > 5 ? Number(c.hnd_buff) : 0;
            case 'debuff': return c.debuf && c.debuf.length > 5 ? Number(c.debuf) : 0;
            case 'firepower_index': return Number(c.firepower_index) || 0;
            case 'healingpower_index': return Number(c.healingpower_index) || 0;
            default: return c[k];
        }
    };

    hits.sort((a, b) => {
        if (hasCharSearch) {
            const sA = charCounts.get(a.id) || 0;
            const sB = charCounts.get(b.id) || 0;
            if (sA !== sB) return sB - sA;
        }
        if (hasGimmickSearch) {
            const sA = gimmickCounts.get(a.id) || 0;
            const sB = gimmickCounts.get(b.id) || 0;
            if (sA !== sB) return sB - sA;
        }
        let valA = getRefSortValue(a, sortKey);
        let valB = getRefSortValue(b, sortKey);
        if (valA === undefined || valA === null) valA = '';
        if (valB === undefined || valB === null) valB = '';
        let diff = 0;
        if (typeof valA === 'number' && typeof valB === 'number') {
            diff = sortOrder === 'ASC' ? valA - valB : valB - valA;
        } else {
            diff = String(valA).localeCompare(String(valB), 'ja');
            if (sortOrder === 'DESC') diff = -diff;
        }
        if (diff === 0) {
            if (sortKey === 'name_ruby') {
                const dA = String(a.date || '');
                const dB = String(b.date || '');
                if (dA < dB) return 1;
                if (dA > dB) return -1;
                return 0;
            }
            return String(a.name_ruby || '').localeCompare(String(b.name_ruby || ''), 'ja');
        }
        return diff;
    });

    return { hits, charCounts, gimmickCounts };
}

function makeState(overrides = {}) {
    return {
        rawKeyword: '',
        includeTraitStatusResistance: false,
        rawSearchChar: '',
        rawSearchCv: '',
        checkedAxises: [],
        checkedAttrs: [],
        checkedSpecies: [],
        checkedRarities: [],
        checkedGroups: [],
        checkedEvents: [],
        checkedQuests: [],
        checkedGimmicks: [],
        checkedPriorities: [],
        checkedAcqs: [],
        checkedSkillTags: [],
        checkedSkillScopes: ['waza', 'sugo', 'kotowaza'],
        checkedTraitTags: [],
        checkedTraitScopes: ['t1', 't2', 'blessing'],
        relAxis: 'OR',
        relGroup: 'OR',
        relEvent: 'OR',
        relQuest: 'OR',
        relGimmick: 'OR',
        relAcq: 'OR',
        relAttr: 'OR',
        subAttrToggle: 'OR',
        relSpecies: 'OR',
        relRarity: 'OR',
        relSkillTags: 'OR',
        relTraitTags: 'OR',
        relChar: 'AND',
        leaderConditions: [],
        ...overrides
    };
}

// テスト用合成キャラを1件追加（&検索・二重エンコードイベント・個別状態異常耐性の検証用）
const testDataset = rawCharacters.map(c => JSON.parse(JSON.stringify(c)));
testDataset.push({
    id: '999901',
    thumb_url: 'https://www.kotodaman-db.com/wp-content/uploads/2025/12/sample.png',
    name: 'テスト・アルファ＆ベータ',
    name_ruby: 'てすとあるふぁあんどべーた',
    chars: [{ val: 'て', attr: 1, unlock: 'def' }, { val: '＆', attr: 2, unlock: 'scopy' }],
    attr: 1,
    sub_attrs: [2],
    spe: 1,
    group_en: ['test-group'],
    group_jp: ['テストＧ＆Ｐ'],
    events: ['test-encoded%2520event'],
    'sub-event': [],
    gerilla_root: [],
    quests: ['test-quest'],
    rar_t: ['6'],
    date: '2026-01-01',
    cv: 'テスト声優',
    acq: 'ガチャ',
    hp99: 1000, atk99: 500, hp120: 2000, atk120: 800,
    pri: 2,
    bd_buff: [0, 0, 0, 0, 0, 0],
    hnd_buff: [0, 0, 0, 0, 0, 0],
    debuf: [0, 0, 0, 0, 0, 0],
    firepower_index: 99999,
    healingpower_index: 0,
    gimmick_en: ['wall', 'shield'],
    gimmick_jp: ['ウォールブレイカー', 'シールドブレイカー'],
    trait_status_resistance_en: ['poison'],
    trait_status_resistance_jp: ['毒耐性'],
    leader_status_resistance_en: [],
    leader_status_resistance_jp: [],
    leader: [],
    ls_hp: 100,
    ls_atk: 200,
    axis: ['axis_i'],
    waza_t: ' type_attack_single ',
    sugo_t: ' type_atk_buff ',
    koto_t: '',
    trait1_en: ['damage_correction_oneself', 'give_trait'],
    trait1_jp: ['自身の威力UP'],
    trait2_en: [],
    trait2_jp: [],
    blessing_en: ['status_up_atk'],
    blessing_jp: ['ATKUP']
});

// =========================================================
// 1. プリインデックス実行
// =========================================================
const refDataset = testDataset.map(c => JSON.parse(JSON.stringify(c)));
const tPre0 = performance.now();
core.prepareSearchIndex(testDataset, { eventMap: testEventMap });
const preIndexMs = performance.now() - tPre0;

// =========================================================
// 2. 等価性テストケース
// =========================================================
const cases = [
    { name: '空条件（全件 + name_ruby ASC）', state: makeState(), sortKey: 'name_ruby', sortOrder: 'ASC' },
    { name: '全件 name_ruby DESC', state: makeState(), sortKey: 'name_ruby', sortOrder: 'DESC' },
    { name: '全件 hp120 DESC（第2ソート name_ruby ASC）', state: makeState(), sortKey: 'hp120', sortOrder: 'DESC' },
    { name: '全件 firepower_index DESC', state: makeState(), sortKey: 'firepower_index', sortOrder: 'DESC' },
    { name: '全件 date DESC', state: makeState(), sortKey: 'date', sortOrder: 'DESC' },
    { name: 'フリーワード単一「あ」', state: makeState({ rawKeyword: 'あ' }) },
    { name: 'フリーワード「しん」', state: makeState({ rawKeyword: 'しん' }) },
    { name: 'フリーワード複数AND「言霊祭 攻撃」', state: makeState({ rawKeyword: '言霊祭 攻撃' }) },
    { name: 'フリーワード「ウォール」', state: makeState({ rawKeyword: 'ウォール' }) },
    { name: 'フリーワード全角半角正規化「gp」', state: makeState({ rawKeyword: 'ＧＰ' }) },
    { name: 'フリーワード「&」単体検索', state: makeState({ rawKeyword: '&' }) },
    { name: 'フリーワード「＆」単体検索', state: makeState({ rawKeyword: '＆' }) },
    { name: 'フリーワード 個別特性耐性OFF「毒耐性」', state: makeState({ rawKeyword: '毒耐性', includeTraitStatusResistance: false }) },
    { name: 'フリーワード 個別特性耐性ON「毒耐性」', state: makeState({ rawKeyword: '毒耐性', includeTraitStatusResistance: true }) },
    { name: '使用可能文字「い」', state: makeState({ rawSearchChar: 'い' }) },
    { name: '使用可能文字「あいう」', state: makeState({ rawSearchChar: 'あいう' }) },
    { name: '使用可能文字「あいうえお」', state: makeState({ rawSearchChar: 'あいうえお' }) },
    { name: '使用可能文字 区切り文字混在「あ,い、あ う」', state: makeState({ rawSearchChar: 'あ,い、あ う' }) },
    { name: '使用可能文字 該当なし「ゐ」', state: makeState({ rawSearchChar: 'ゐ' }) },
    { name: '属性 火+水 OR（副属性含む）', state: makeState({ checkedAttrs: ['fire', 'water'], relAttr: 'OR', subAttrToggle: 'OR' }) },
    { name: '属性 水 OR（副属性含まない）', state: makeState({ checkedAttrs: ['water'], relAttr: 'OR', subAttrToggle: 'AND' }) },
    { name: '属性 火+水 AND（副属性含む）', state: makeState({ checkedAttrs: ['fire', 'water'], relAttr: 'AND', subAttrToggle: 'OR' }) },
    { name: '種族 神/魔 OR', state: makeState({ checkedSpecies: ['god', 'demon'], relSpecies: 'OR' }) },
    { name: 'イベント 言霊祭', state: makeState({ checkedEvents: ['%e8%a8%80%e9%9c%8a%e7%a5%ad'] }) },
    { name: 'イベント 二重エンコードテスト', state: makeState({ checkedEvents: ['test-encoded event'] }) },
    { name: 'ギミック wall+shield OR（一致数ランキング）', state: makeState({ checkedGimmicks: ['wall', 'shield'], relGimmick: 'OR' }) },
    { name: 'ギミック wall+shield AND', state: makeState({ checkedGimmicks: ['wall', 'shield'], relGimmick: 'AND' }) },
    { name: '文字軸 axis_i', state: makeState({ checkedAxises: ['axis_i'] }) },
    { name: '入手方法 ガチャ', state: makeState({ checkedAcqs: ['ガチャ'] }) },
    { name: '行動順 2', state: makeState({ checkedPriorities: ['2'] }) },
    { name: 'CV部分一致「すみれ」', state: makeState({ rawSearchCv: 'すみれ' }) },
    { name: 'わざタグ（waza/sugoのみ）AND', state: makeState({ checkedSkillTags: ['type_attack_single', 'type_atk_buff'], checkedSkillScopes: ['waza', 'sugo'], relSkillTags: 'AND' }) },
    { name: 'わざタグ スコープ空', state: makeState({ checkedSkillTags: ['type_attack_single'], checkedSkillScopes: [] }) },
    { name: 'とくせいタグ trait_プレフィックス＆give_trait', state: makeState({ checkedTraitTags: ['trait_damage_correction_oneself', 'give_trait'], relTraitTags: 'AND' }) },
    { name: 'とくせいタグ 祝福スコープのみ', state: makeState({ checkedTraitTags: ['trait_status_up_atk'], checkedTraitScopes: ['blessing'] }) },
    {
        name: 'リーダー検索 HP UP >= 100 (attr: fire)',
        state: makeState({
            leaderConditions: [{
                buffType: 'fixed', buffStat: 'hp', buffValue: 100, perUnit: false,
                target: { type: 'attr', slugs: [1] },
                raw: { type: 'hp', statusResistance: '' }
            }]
        })
    },
    {
        name: 'リーダー検索 状態異常耐性(全体) all',
        state: makeState({
            leaderConditions: [{
                buffType: 'fixed', buffStat: null, buffValue: 0, perUnit: false,
                target: { type: 'all', slugs: [] },
                raw: { type: 'status_resistance', statusResistance: '' }
            }]
        })
    }
];

let passed = 0;
for (const tc of cases) {
    const sortKey = tc.sortKey || 'name_ruby';
    const sortOrder = tc.sortOrder || 'ASC';

    const refRes = refFilterAndSort(refDataset, tc.state, sortKey, sortOrder);

    sandbox.window._shouldRankByCharMatch = Boolean(core.normalizeForSearch(tc.state.rawSearchChar));
    sandbox.window._shouldRankByGimmickMatch = tc.state.checkedGimmicks.length > 0;
    const newHits = core.filterCharacterList(testDataset, tc.state);
    core.sortCharacters(newHits, sortKey, sortOrder);

    const refIds = refRes.hits.map(c => String(c.id));
    const newIds = newHits.map(c => String(c.id));

    assert.deepStrictEqual(newIds, refIds, `[MISMATCH] ${tc.name}: IDs or sort order differ`);

    if (tc.state.rawSearchChar && Array.from(new Set(core.normalizeForSearch(tc.state.rawSearchChar))).some(c => c.trim() !== '' && c !== ',' && c !== '、')) {
        for (const c of newHits) {
            assert.strictEqual(c._charMatchCount, refRes.charCounts.get(c.id), `[MISMATCH] ${tc.name}: _charMatchCount for id=${c.id}`);
        }
    }
    if (tc.state.checkedGimmicks.length > 0) {
        for (const c of newHits) {
            assert.strictEqual(c._gimmickMatchCount, refRes.gimmickCounts.get(c.id), `[MISMATCH] ${tc.name}: _gimmickMatchCount for id=${c.id}`);
        }
    }

    // buildRenderItems 整合性確認
    const renderItems = core.buildRenderItems(newHits, {
        activeCharMatchKey: sandbox.window._shouldRankByCharMatch ? 'char' : null,
        shouldRankByGimmick: sandbox.window._shouldRankByGimmickMatch
    });
    const renderedCharItems = renderItems.filter(it => it.type === 'char');
    assert.strictEqual(renderedCharItems.length, newHits.length, `[MISMATCH] ${tc.name}: buildRenderItems char count`);

    passed++;
}

// =========================================================
// 3. セキュリティ & HTMLキャッシュテスト
// =========================================================
const xssChar = {
    id: '888"><script>alert(1)</script>',
    thumb_url: 'javascript:alert(1)',
    name: '<img src=x onerror=alert(1)>・悪意キャラ',
    name_ruby: 'あくい"><script>alert(2)</script>',
    chars: [{ val: '<b onmouseover=alert(3)>あ</b>', attr: 1, unlock: 'def' }],
    attr: 1,
    sub_attrs: [],
    spe: 1,
    group_en: ['g"><svg onload=alert(4)>'],
    group_jp: ['<b>グループ</b>'],
    events: ['ev" onclick="alert(5)'],
    quests: ['q<script>'],
    gimmick_en: ['w"'],
    gimmick_jp: ['<script>gimmick</script>'],
    cv: '"><img src=x onerror=alert(6)>',
    acq: '<u>ガチャ</u>',
    date: '2026"><script>',
    hp99: 100, atk99: 100, hp120: 200, atk120: 200,
    ls_hp: '50"<script>', ls_atk: '50',
    bd_buff: [0, 0, 0, 0, 0, 0], hnd_buff: [0, 0, 0, 0, 0, 0], debuf: [0, 0, 0, 0, 0, 0],
    firepower_index: 100, healingpower_index: 0,
    axis: ['<script>axis</script>']
};
const xssHtml = core.createCharacterRowHtml(xssChar, { bypassCache: true });
assert.ok(!xssHtml.includes('<script'), 'XSS: raw <script> tag must be escaped');
assert.ok(!xssHtml.includes('<img'), 'XSS: raw <img> tag must be escaped');
assert.ok(!xssHtml.includes('<svg'), 'XSS: raw <svg> tag must be escaped');
assert.ok(!xssHtml.includes('<b>'), 'XSS: raw <b> tag must be escaped');
assert.ok(!xssHtml.includes('<u>'), 'XSS: raw <u> tag must be escaped');
assert.ok(!xssHtml.includes('javascript:alert'), 'XSS: javascript: URL must be blocked');
assert.ok(!xssHtml.includes('" onclick='), 'XSS: raw onclick attribute must be escaped');
assert.ok(!xssHtml.includes('" onerror='), 'XSS: raw onerror attribute must be escaped');
assert.ok(!xssHtml.includes('" onmouseover='), 'XSS: raw onmouseover attribute must be escaped');
assert.ok(xssHtml.includes('&lt;script&gt;'), 'XSS: escaped &lt;script&gt; text should be preserved safely');

assert.strictEqual(core.sanitizeThumbUrl('https://www.kotodaman-db.com/a.png'), 'https://www.kotodaman-db.com/a.png');
assert.strictEqual(core.sanitizeThumbUrl('/wp-content/uploads/a.png'), '/wp-content/uploads/a.png');
assert.strictEqual(core.sanitizeThumbUrl('//evil.example/a.png'), '');
assert.strictEqual(core.sanitizeThumbUrl('javascript:alert(1)'), '');
assert.strictEqual(core.sanitizeThumbUrl('data:text/html,<script>alert(1)</script>'), '');

assert.strictEqual(core.ALLOWED_SORT_KEYS.has('hp120'), true);
assert.strictEqual(core.ALLOWED_SORT_KEYS.has('__proto__'), false);
assert.strictEqual(core.ALLOWED_SORT_KEYS.has('constructor'), false);

// キャッシュ・lazy画像チェック
const sampleChar = testDataset[0];
sampleChar._rowHtml = null;
const firstHtml = core.createCharacterRowHtml(sampleChar);
const secondHtml = core.createCharacterRowHtml(sampleChar);
assert.strictEqual(firstHtml, secondHtml, 'Row HTML cache should return identical string');
if (sampleChar.thumb_url) {
    assert.ok(firstHtml.includes('loading="lazy"'), 'Thumb img should include loading="lazy"');
    assert.ok(firstHtml.includes('decoding="async"'), 'Thumb img should include decoding="async"');
}

// =========================================================
// 4. パフォーマンスベンチマーク
// =========================================================
function measure(label, fn, runs = 10) {
    fn();
    const t0 = performance.now();
    let res;
    for (let i = 0; i < runs; i++) res = fn();
    const avg = (performance.now() - t0) / runs;
    console.log(`  ${label.padEnd(42)}: ${avg.toFixed(3)} ms (result: ${res})`);
}

console.log(`\n[PASS] All ${passed} parity cases + security + cache assertions passed!`);
console.log('\n--- Benchmark (Node.js / ' + testDataset.length + ' chars) ---');
console.log(`  ${'Pre-index build (once on load)'.padEnd(42)}: ${preIndexMs.toFixed(3)} ms`);
measure('Freeword "あ"', () => core.filterCharacterList(testDataset, makeState({ rawKeyword: 'あ' })).length);
measure('Freeword "言霊祭 攻撃"', () => core.filterCharacterList(testDataset, makeState({ rawKeyword: '言霊祭 攻撃' })).length);
measure('SearchChar "あいうえお"', () => core.filterCharacterList(testDataset, makeState({ rawSearchChar: 'あいうえお' })).length);
measure('Sort all by name_ruby ASC', () => {
    const copy = testDataset.slice();
    core.sortCharacters(copy, 'name_ruby', 'ASC');
    return copy.length;
});
measure('Sort all by hp120 DESC', () => {
    const copy = testDataset.slice();
    core.sortCharacters(copy, 'hp120', 'DESC');
    return copy.length;
});
measure('Initial 60 row HTML generation', () => {
    testDataset.slice(0, 60).forEach(c => { c._rowHtml = null; });
    return testDataset.slice(0, 60).map(c => core.createCharacterRowHtml(c)).join('').length;
}, 5);
