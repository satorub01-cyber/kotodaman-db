// =========================================================
// コトダマンDB フロントエンド検索エンジン
// =========================================================

const JSON_URL = window.KOTO_SEARCH_JSON_URL || '/wp-content/themes/cocoon-child-master/lib/character-search/all_characters_search.json';
const IS_LOCAL_HOST = ['localhost', '127.0.0.1'].includes(window.location.hostname);
const getSearchIcon = (filename) => IS_LOCAL_HOST ? '' : `/wp-content/uploads/2025/12/${filename}`;
const RENDER_CHUNK_SIZE = 60;
const INPUT_DEBOUNCE_MS = 200;
const CHAR_NAME_DISP_REGEX = /^(?:(?![^・]*[\(（])(?!(?:[\u30A0-\u30FF]+)・)(?:[^・]+)・(.+)|(.+))$/u;
const JA_COLLATOR = (typeof Intl !== 'undefined' && typeof Intl.Collator === 'function') ? new Intl.Collator('ja') : null;
const ALLOWED_SORT_KEYS = new Set([
    'id', 'name_ruby', 'attr', 'spe', 'rarity', 'hp99', 'atk99', 'hp120', 'atk120',
    'ls_hp', 'ls_atk', 'buff_board', 'buff_hand', 'debuff', 'date',
    'firepower_index', 'healingpower_index'
]);

let allCharacters = [];
let filteredCharacters = []; // ★追加：現在の絞り込み結果を保持する配列
// ★追加：ソート（並び替え）用の状態変数
let currentSortKey = 'name_ruby';    // デフォルトのソートキー（実装日）
let currentSortOrder = 'ASC';  // デフォルトの順序（降順）

// ★追加：URL更新用・検索入力用デバウンスタイマー＆段階描画状態
let urlUpdateDebounceTimer = null;
let filterDebounceTimer = null;
let pendingModalFilter = false;
let pendingModalForm = null;
let hasUserInteractedBeforeLoad = false;
let isCharactersLoaded = false;
let isRestoringFromUrl = false;
const composingInputs = (typeof WeakSet === 'function') ? new WeakSet() : new Set();
let currentRenderItems = [];
let renderedItemIndex = 0;
let renderedCharCount = 0;
let totalRenderCharCount = 0;
let loadMoreObserver = null;

// ★追加：初期ロード時は URL 更新をスキップするフラグ
let isInitialLoad = true;

// セキュリティ・外部マップ・文字列ユーティリティ
const getGroupMap = () => (typeof KOTO_GROUP_MAP !== 'undefined' && KOTO_GROUP_MAP) ? KOTO_GROUP_MAP : (window.KOTO_GROUP_MAP || {});
const getEventMap = () => (typeof KOTO_EVENT_MAP !== 'undefined' && KOTO_EVENT_MAP) ? KOTO_EVENT_MAP : (window.KOTO_EVENT_MAP || {});
const getQuestMap = () => (typeof KOTO_SUITABLE_QUEST_MAP !== 'undefined' && KOTO_SUITABLE_QUEST_MAP) ? KOTO_SUITABLE_QUEST_MAP : (window.KOTO_SUITABLE_QUEST_MAP || {});
const getRarityMap = () => (typeof KOTO_RARITY_MAP !== 'undefined' && KOTO_RARITY_MAP) ? KOTO_RARITY_MAP : (window.KOTO_RARITY_MAP || {});

const DEFAULT_RARITY_DETAIL_MAP = {
    special: 'スペシャル',
    legend: 'レジェンド',
    grand: 'グランド',
    dream: 'ドリーム',
    miracle: 'ミラクル'
};
const RARITY_DETAIL_SORT_RANK = {
    none: 0,
    special: 1,
    miracle: 2,
    dream: 3,
    legend: 4,
    grand: 5
};

function toFullWidthDigits(val) {
    return String(val).replace(/[0-9]/g, ch => String.fromCharCode(ch.charCodeAt(0) + 0xFEE0));
}

function formatRarityLabel(char) {
    if (!char) return '';
    const rarNum = Number(char.rar);
    const hasStar = Number.isFinite(rarNum) && rarNum > 0;
    const starText = hasStar ? `★${toFullWidthDigits(rarNum)}` : '';
    const rarDetail = String(char.rar_d || '').trim();
    if (rarDetail && rarDetail !== 'none') {
        const rarityMap = getRarityMap();
        const detailLabel = rarityMap[rarDetail] || DEFAULT_RARITY_DETAIL_MAP[rarDetail] || rarDetail;
        return starText ? `${detailLabel}（${starText}）` : detailLabel;
    }
    return starText;
}

function getRaritySortValue(char) {
    const rarNum = Number(char && char.rar) || 0;
    const rarDetail = String((char && char.rar_d) || '').trim();
    let detailRank = 0;
    if (rarDetail && rarDetail !== 'none') {
        detailRank = Object.prototype.hasOwnProperty.call(RARITY_DETAIL_SORT_RANK, rarDetail)
            ? RARITY_DETAIL_SORT_RANK[rarDetail]
            : 1;
    }
    return rarNum * 100 + detailRank;
}

function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function sanitizeThumbUrl(url) {
    if (!url || typeof url !== 'string') return '';
    const trimmed = url.trim();
    if (/^https?:\/\//i.test(trimmed) || /^\/(?!\/)/.test(trimmed)) {
        return trimmed;
    }
    return '';
}

function escapeCssAttrValue(value) {
    const str = String(value ?? '');
    if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
        return CSS.escape(str);
    }
    return str.replace(/["\\\]\[]/g, '\\$&');
}

// ヘルパー関数群
function formatNumber(num) {
    if (!num || num === 0 || num === "0") return '<span class="text-muted">-</span>';
    return escapeHtml(Number(num).toLocaleString());
}

const normalizeRawForSearch = (v) => {
    try {
        return String(v || '').normalize('NFKC').toLowerCase().trim();
    } catch (e) {
        return String(v || '').toLowerCase().trim();
    }
};

// 全角/半角や互換文字を検索用に正規化して小文字化するユーティリティ
// 通常の読み仮名比較では `&` / `＆` を無視するが、入力が `&` だけのときはリテラル検索として扱う
const normalizeForSearch = (v) => {
    const value = normalizeRawForSearch(v);
    if (value === '&' || value === '＆') return value;
    return value.replace(/[&＆]/g, '');
};

function compareJa(a, b) {
    if (JA_COLLATOR) {
        return JA_COLLATOR.compare(a, b);
    }
    return a.localeCompare(b, 'ja');
}

function getBuffDispHTML(buffArray) {
    if (!buffArray || buffArray.length < 6) return '<span class="text-muted">0</span>';
    const min = buffArray;
    const max = buffArray;
    if (max === 0) return '<span class="text-muted">0</span>';
    if (min === max) return `<span class="bd-val">${escapeHtml(max)}</span>`;
    return `<span class="bd-val">${escapeHtml(min)}➡${escapeHtml(max)}</span>`;
}

/// 属性・種族マップ（iconの行を追加）
const ATTR_MAP = {
    1: { slug: 'fire', name: '火', icon: getSearchIcon('icon-fire-150x150.png.webp') },
    2: { slug: 'water', name: '水', icon: getSearchIcon('icon-water-150x150.png.webp') },
    3: { slug: 'wood', name: '木', icon: getSearchIcon('icon-wood-150x150.png.webp') },
    4: { slug: 'light', name: '光', icon: getSearchIcon('icon-light-150x150.png.webp') },
    5: { slug: 'dark', name: '闇', icon: getSearchIcon('icon-dark-150x150.png.webp') },
    6: { slug: 'void', name: '冥', icon: getSearchIcon('icon-void-150x150.png.webp') },
    7: { slug: 'heaven', name: '天', icon: getSearchIcon('icon-heaven-150x150.png.webp') },
    8: { slug: 'rainbow', name: '虹', icon: getSearchIcon('icon-rainbow-150x150.png.webp') },
};
const SPECIES_MAP = {
    1: { slug: 'god', name: '神', icon: getSearchIcon('icon-god-150x150.png.webp') },
    2: { slug: 'demon', name: '魔', icon: getSearchIcon('icon-demon-150x150.png.webp') },
    3: { slug: 'hero', name: '英', icon: getSearchIcon('icon-hero-150x150.png.webp') },
    4: { slug: 'dragon', name: '龍', icon: getSearchIcon('icon-dragon-150x150.png.webp') },
    5: { slug: 'beast', name: '獣', icon: getSearchIcon('icon-beast-150x150.png.webp') },
    6: { slug: 'spirit', name: '霊', icon: getSearchIcon('icon-spirit-150x150.png.webp') },
    7: { slug: 'artifact', name: '物', icon: getSearchIcon('icon-artifact-150x150.png.webp') },
    8: { slug: 'yokai', name: '妖', icon: getSearchIcon('icon-yokai-150x150.png.webp') }
};
const AXIS_MAP = {
    'axis_i': 'い軸',
    'axis_u': 'う軸',
    'axis_youon': 'やゆよ軸',
    'char_small_yuyo': '小文字ゆよ',
    'char_connector': 'つなぎ文字',
}

const LEADER_STATUS_NAME_MAP = {
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
    all: '全状態異常'
};

function formatLeaderSummaryHtml(char) {
    const leaders = Array.isArray(char?.leader) ? char.leader : [];
    if (leaders.length === 0) return '<span class="text-muted">-</span>';

    const groupMap = getGroupMap();
    const lines = [];

    for (let i = 0; i < leaders.length; i++) {
        const ldr = leaders[i];
        if (!ldr || (ldr.ty !== 'fixed' && ldr.ty !== 'per_unit')) continue;

        const effs = Array.isArray(ldr.effs) ? ldr.effs : [];
        for (let j = 0; j < effs.length; j++) {
            const eff = effs[j];
            const vals = eff?.vals;
            if (!vals || typeof vals !== 'object') continue;

            // HP・ATK・状態異常のみを抽出（その他効果は無視）
            const statParts = [];
            if (Number(vals.hp) > 0) statParts.push(`HP${vals.hp}`);
            if (Number(vals.atk) > 0) statParts.push(`ATK${vals.atk}`);
            for (const [statusKey, statusLabel] of Object.entries(LEADER_STATUS_NAME_MAP)) {
                if (Number(vals[statusKey]) > 0) {
                    statParts.push(`${statusLabel}${vals[statusKey]}`);
                }
            }
            if (statParts.length === 0) continue;

            // 対象ラベルの構築（具体対象があればそれを優先、なければ all → 全）
            const tgts = Array.isArray(eff.tgts) ? eff.tgts : [];
            const concreteLabels = [];
            let hasAllTarget = false;

            for (let k = 0; k < tgts.length; k++) {
                const t = tgts[k];
                if (!t) continue;
                if (t.ty === 'all') {
                    hasAllTarget = true;
                    continue;
                }
                const slgs = Array.isArray(t.slgs) ? t.slgs : [];
                for (let m = 0; m < slgs.length; m++) {
                    const slug = slgs[m];
                    if (t.ty === 'attr' && ATTR_MAP[slug]) {
                        concreteLabels.push(ATTR_MAP[slug].name);
                    } else if (t.ty === 'species' && SPECIES_MAP[slug]) {
                        concreteLabels.push(SPECIES_MAP[slug].name);
                    } else if (t.ty === 'group' && slug) {
                        let decoded = String(slug);
                        try { decoded = decodeURIComponent(decoded); } catch (e) { }
                        concreteLabels.push(groupMap[slug] || groupMap[decoded] || decoded);
                    }
                }
            }

            const uniqueLabels = Array.from(new Set(concreteLabels));
            const targetText = uniqueLabels.length > 0
                ? uniqueLabels.join('・')
                : (hasAllTarget ? '全' : '');

            const targetHtml = targetText ? `<span class="ls-summary-target">${escapeHtml(targetText)}</span>` : '';
            const statsHtml = `<span class="ls-summary-vals">${escapeHtml(statParts.join(' '))}</span>`;
            lines.push(`<div class="ls-summary-line">${targetHtml}${statsHtml}</div>`);
        }
    }

    if (lines.length === 0) return '<span class="text-muted">-</span>';
    return lines.join('');
}

// 1キャラ分の行(<tr>)生成関数
function createCharacterRowHtml(char, options) {
    const bypassCache = Boolean(options && options.bypassCache);
    if (!bypassCache && char._rowHtml) {
        return char._rowHtml;
    }

    const rawId = String(char.id ?? '');
    const safeId = escapeHtml(rawId);
    const link = `/character/${encodeURIComponent(rawId)}`;
    //   ^(?:
    //   (?![^・]*[\(（])            # 最初の・より前に ( または （ があればこの枝は失敗
    //   (?!(?:[\u30A0-\u30FF]+)・)   # 全部カタカナならこの枝は失敗
    //   (?:[^・]+)・(.+)            # 最初の・の後ろを全部キャプチャ
    //   |
    //   (.+)                        # 先読みマッチがなければ全文
    //   )$
    const rawName = String(char.name || '');
    const match = rawName.match(CHAR_NAME_DISP_REGEX);
    const dispName = escapeHtml(match ? (match[1] || match[2]) : rawName); // 最初の・の後ろを優先、なければ全体
    const safeName = escapeHtml(rawName);
    const safeNameRuby = escapeHtml(char.name_ruby || '');

    const safeThumbUrl = sanitizeThumbUrl(char.thumb_url);
    const thumbHtml = safeThumbUrl
        ? `<img src="${escapeHtml(safeThumbUrl)}" class="chara-thumb" alt="${safeName}" loading="lazy" decoding="async" width="52" height="52">`
        : `<div class="no-img"></div>`;

    let charsHtml = '';
    if (char.chars && char.chars.length > 0) {
        charsHtml = char.chars.map(c => {
            const attrSlug = ATTR_MAP[c.attr] ? ATTR_MAP[c.attr].slug : 'none';
            let suffix = '';
            if (c.unlock === 'scopy') suffix = '<span class="char-suffix">(Sコ)</span>';
            if (c.unlock === 'schange') suffix = '<span class="char-suffix">(Sチ)</span>';
            if (c.unlock === 'sboth') suffix = '<span class="char-suffix">(Sコ・Sチ)</span>';

            const safeCharVal = escapeHtml(c.val);
            // ★ポイント：元の 'char-link-item' と新しい 'js-quick-filter' を両方つける！
            return `<a href="#" class="char-link-item js-quick-filter" data-name="search_char" data-value="${safeCharVal}"><span class="char-font attr-${attrSlug}">${safeCharVal}</span>${suffix}</a>`;
        }).join('');
    }

    const attrData = ATTR_MAP[char.attr] || { slug: 'none', name: '不明', icon: '' };
    // ▼▼▼ 属性を修正 ▼▼▼
    let attrHtml = attrData.icon
        ? `<a href="#" class="js-quick-filter" data-name="tx_attr[]" data-value="${attrData.slug}"><img src="${attrData.icon}" class="attr-icon-img" alt="${attrData.name}"></a>`
        : `<a href="#" class="js-quick-filter" data-name="tx_attr[]" data-value="${attrData.slug}"><span class="attr-text attr-${attrData.slug}">${attrData.name}</span></a>`;

    // サブ属性の処理
    if (char.sub_attrs && char.sub_attrs.length > 0) {
        char.sub_attrs.forEach(subId => {
            const subData = ATTR_MAP[subId];
            if (subData) {
                attrHtml += subData.icon
                    ? `<img src="${subData.icon}" class="attr-icon-img koto-icon-small" alt="${subData.name}">`
                    : `<span class="attr-text attr-${subData.slug} koto-icon-small">${subData.name}</span>`;
            }
        });
    }

    const speciesData = SPECIES_MAP[char.spe] || { slug: 'none', name: '不明', icon: '' };
    // ▼▼▼ 種族を修正 ▼▼▼
    let speciesHtml = speciesData.icon
        ? `<a href="#" class="js-quick-filter" data-name="tx_species[]" data-value="${speciesData.slug}"><img src="${speciesData.icon}" class="species-icon-img" alt="${speciesData.name}"></a>`
        : `<a href="#" class="js-quick-filter" data-name="tx_species[]" data-value="${speciesData.slug}"><span class="attr-text attr-${speciesData.slug}">${speciesData.name}</span></a>`;

    const rarityLabel = formatRarityLabel(char);
    const rarDetail = String(char.rar_d || '').trim();
    const rarNum = Number(char.rar);
    const rarityFilterValue = (rarDetail && rarDetail !== 'none')
        ? rarDetail
        : (Number.isFinite(rarNum) && rarNum > 0 ? String(rarNum) : '');
    const rarityHtml = rarityLabel
        ? (rarityFilterValue
            ? `<a href="#" class="js-quick-filter" data-name="tx_rarity[]" data-value="${escapeHtml(rarityFilterValue)}">${escapeHtml(rarityLabel)}</a>`
            : escapeHtml(rarityLabel))
        : '<span class="text-muted">-</span>';

    let gimmickHtml = '';
    const gimmickEnValues = Array.isArray(char.gimmick_en) ? char.gimmick_en : [];
    const gimmickJpValues = Array.isArray(char.gimmick_jp) ? char.gimmick_jp : [];
    if (gimmickJpValues.length > 0) {
        gimmickHtml = gimmickJpValues.map((name, index) => {
            const slug = gimmickEnValues[index] || '';
            return `<a href="#" class="js-quick-filter" data-name="tx_gimmick[]" data-value="${escapeHtml(slug)}"><span class="badge-gimmick">${escapeHtml(name)}</span></a>`;
        }).join('');
    }

    const valHp99 = formatNumber(char.hp99);
    const valAtk99 = formatNumber(char.atk99);
    const valHp120 = formatNumber(char.hp120);
    const valAtk120 = formatNumber(char.atk120);
    const lsHp = char.ls_hp && char.ls_hp != "0" ? `${escapeHtml(char.ls_hp)}%` : '<span class="text-muted">-</span>';
    const lsAtk = char.ls_atk && char.ls_atk != "0" ? `${escapeHtml(char.ls_atk)}%` : '<span class="text-muted">-</span>';
    const lsSummaryHtml = formatLeaderSummaryHtml(char);

    const buffBoard = getBuffDispHTML(char.bd_buff);
    const buffHand = getBuffDispHTML(char.hnd_buff);
    const debuff = getBuffDispHTML(char.debuf);

    const groupMap = getGroupMap();
    const eventMap = getEventMap();
    const questMap = getQuestMap();

    // ▼▼▼ グループ ▼▼▼
    let groupHtml = '<span class="text-muted">-</span>';
    const groupEnValues = Array.isArray(char.group_en) ? char.group_en : [];
    const groupJpValues = Array.isArray(char.group_jp) ? char.group_jp : [];
    if (groupEnValues.length > 0) {
        groupHtml = groupEnValues.map((slug, index) => {
            let decoded = slug;
            try { decoded = decodeURIComponent(slug); } catch (e) { }
            const label = groupJpValues[index] || groupMap[decoded] || decoded;
            return `<a href="#" class="js-quick-filter" data-name="tx_group[]" data-value="${escapeHtml(slug)}">${escapeHtml(label)}</a>`;
        }).join('<br>');
    }

    // ▼▼▼ イベント ▼▼▼
    let eventHtml = '<span class="text-muted">-</span>';
    if (char.events && char.events.length > 0) {
        eventHtml = char.events.map(slug => {
            let decoded = slug;
            try { decoded = decodeURIComponent(slug); } catch (e) { }
            const label = eventMap[slug] || eventMap[decoded] || decoded;
            return `<a href="#" class="js-quick-filter" data-name="tx_event[]" data-value="${escapeHtml(slug)}">${escapeHtml(label)}</a>`;
        }).join('<br>');
    }

    // ▼▼▼ その他入手方法 ▼▼▼
    let subEventHtml = '<span class="text-muted">-</span>';
    const charSubEvents = char['sub-event'] || char.sub_event || [];
    if (charSubEvents && charSubEvents.length > 0) {
        subEventHtml = charSubEvents.map(slug => {
            let decoded = slug;
            try { decoded = decodeURIComponent(slug); } catch (e) { }
            const label = eventMap[slug] || eventMap[decoded] || decoded;
            return `<a href="#" class="js-quick-filter" data-name="tx_event[]" data-value="${escapeHtml(slug)}">${escapeHtml(label)}</a>`;
        }).join('<br>');
    }

    // ▼▼▼ 強襲元 ▼▼▼
    let guerillaRootHtml = '<span class="text-muted">-</span>';
    const charGuerillaRoots = char.gerilla_root || char.guerilla_root || [];
    if (charGuerillaRoots && charGuerillaRoots.length > 0) {
        guerillaRootHtml = charGuerillaRoots.map(slug => {
            let decoded = slug;
            try { decoded = decodeURIComponent(slug); } catch (e) { }
            const label = eventMap[slug] || eventMap[decoded] || decoded;
            return `<a href="#" class="js-quick-filter" data-name="tx_event[]" data-value="${escapeHtml(slug)}">${escapeHtml(label)}</a>`;
        }).join('<br>');
    }
    let charAxisHtml = '';
    if (char.axis && Array.isArray(char.axis)) {
        // マップに存在するものは変換し、存在しないものはそのまま出力して結合する
        charAxisHtml = char.axis.map(axis => escapeHtml(AXIS_MAP[axis] || axis)).join('、');
    } else if (char.axis) {
        charAxisHtml = escapeHtml(AXIS_MAP[char.axis] || char.axis);
    }    // ▼▼▼ 適正クエスト▼▼▼
    let suitableQuestHtml = '<span class="text-muted">-</span>';
    if (char.quests && char.quests.length > 0) {
        suitableQuestHtml = char.quests.map(slug => {
            let decoded = slug;
            try { decoded = decodeURIComponent(slug); } catch (e) { }
            // <a>タグで囲む
            return `<a href="#" class="js-quick-filter" data-name="tx_quest[]" data-value="${escapeHtml(slug)}">${escapeHtml(questMap[decoded] || decoded)}</a>`;
        }).join('<br>');
    }

    const safeCv = escapeHtml(char.cv || '-');
    const safeAcq = escapeHtml(char.acq || '-');
    const safeDate = escapeHtml(char.date || '-');

    const html = `
    <tr data-id="${safeId}">
        <td class="td-icon col-icon"><a href="${link}" target="_blank" rel="noopener noreferrer">${thumbHtml}</a></td>
        <td class="td-id col-id">${safeId}</td>
        <td class="td-name col-name"><a href="${link}" class="chara-link" target="_blank" rel="noopener noreferrer">${dispName}</a><div class="name-ruby">${safeNameRuby}</div></td>

        <td class="td-moji col-moji"><div class="char-list">${charsHtml}</div></td>
        <td class="td-moji-axis col-moji-axis"><div class="char-list">${charAxisHtml}</div></td>
        <td class="td-attr col-attr"><div class="attr-box-row">${attrHtml}</div></td>
        <td class="td-species col-species">${speciesHtml}</td>
        <td class="td-rarity col-rarity">${rarityHtml}</td>
        <td class="td-group col-group">${groupHtml}</td>
        <td class="td-event col-event">${eventHtml}</td>
        <td class="td-sub-event col-sub-event">${subEventHtml}</td>
        <td class="td-guerilla-root col-guerilla-root">${guerillaRootHtml}</td>
        <td class="td-suitable-quest col-quest">${suitableQuestHtml}</td>
        <td class="td-stat hp-val col-hp99">${valHp99}</td>
        <td class="td-stat atk-val col-atk99">${valAtk99}</td>
        <td class="td-stat hp-val-120 col-hp120">${valHp120}</td>
        <td class="td-stat atk-val-120 col-atk120">${valAtk120}</td>

        <td class="td-stat ls-val col-ls-hp">${lsHp}</td>
        <td class="td-stat ls-val col-ls-atk">${lsAtk}</td>
        <td class="td-ls-summary col-ls-summary">${lsSummaryHtml}</td>

        <td class="td-buff buff-cell col-buff-board">${buffBoard}</td>
        <td class="td-buff buff-cell col-buff-hand">${buffHand}</td>
        <td class="td-debuff debuff-cell col-debuff">${debuff}</td>

        <td class="td-gimmick col-gimmick"><div class="gimmick-list">${gimmickHtml}</div></td>
        <td class="td-cv col-cv"><a href="#" class="js-quick-filter" data-name="tx_cv" data-value="${safeCv}">${safeCv}</a></td>
        <td class="td-acq col-acq">${safeAcq}</td>
        <td class="td-date col-date">${safeDate}</td>
        <td class="td-power col-power">${formatNumber(char.firepower_index)}</td>
        <td class="td-heal col-heal">${formatNumber(char.healingpower_index)}</td>
    </tr>
    `;

    if (!bypassCache) {
        char._rowHtml = html;
    }
    return html;
}

// =========================================================
// フィルタリング・ソート・描画処理
// =========================================================

// ヘルパー関数：現在操作対象の検索フォームを解決する（複数フォーム競合防止）
function resolveActiveSearchForm(preferredForm) {
    if (preferredForm && preferredForm.nodeType === 1) {
        return preferredForm;
    }
    if (typeof window !== 'undefined' && typeof window.getActiveSearchForm === 'function') {
        const activeForm = window.getActiveSearchForm();
        if (activeForm) return activeForm;
    }
    if (typeof document !== 'undefined') {
        return document.querySelector('.character-search-box .js-search-form')
            || document.getElementById('searchform')
            || document.querySelector('.js-search-form')
            || null;
    }
    return null;
}

// ヘルパー関数：チェックされた値を配列で取得（対象フォームにスコープ限定）
const getCheckedValues = (selector, root = document) => Array.from((root || document).querySelectorAll(selector)).map(cb => cb.value);
// ヘルパー関数：ラジオボタンの値を取得（トグルスイッチのフォールバック対応版）
const getRadioValue = (name, defaultVal, root = document) => {
    const checked = (root || document).querySelector(`input[name="${name}"]:checked`);
    return checked ? checked.value : defaultVal;
};

// URLエンコードを再帰的に完全デコードするユーティリティ（二重エンコード・大文字小文字対応）
const fullyDecode = (str) => {
    let prev = String(str || '');
    try {
        let decoded = decodeURIComponent(prev);
        while (decoded !== prev) {
            prev = decoded;
            decoded = decodeURIComponent(prev);
        }
    } catch (e) { }
    return prev;
};

const safeIncludes = (array, searchElement) => {
    if (!array || !Array.isArray(array)) return false;
    const decodedSearch = fullyDecode(searchElement);
    const normSearch = normalizeForSearch(decodedSearch);
    const rawSearch = String(searchElement || '').toLowerCase().trim();

    return array.some(item => {
        const decodedItem = fullyDecode(item);
        const normItem = normalizeForSearch(decodedItem);
        const rawItem = String(item || '').toLowerCase().trim();

        return normItem === normSearch || rawItem === rawSearch;
    });
};

const countMatchedValues = (searchValues, charValues) => {
    const safeSearchValues = Array.isArray(searchValues) ? searchValues : (searchValues ? [searchValues] : []);
    const safeCharValues = Array.isArray(charValues) ? charValues : (charValues ? [charValues] : []);

    const uniqueSearchValues = Array.from(new Set(safeSearchValues.filter(value => value !== null && value !== undefined && String(value).trim() !== '')));
    if (uniqueSearchValues.length === 0 || safeCharValues.length === 0) return 0;

    return uniqueSearchValues.reduce((count, searchValue) => {
        return count + (safeIncludes(safeCharValues, searchValue) ? 1 : 0);
    }, 0);
};

const setMatchCount = (char, key, count) => {
    if (!char._searchMatchCounts) {
        char._searchMatchCounts = {};
    }
    char._searchMatchCounts[key] = count;
};

const getMatchCount = (char, key) => {
    const storedCount = char._searchMatchCounts?.[key];
    if (typeof storedCount === 'number') return storedCount;

    if (key === 'char' && typeof char._charMatchCount === 'number') return char._charMatchCount;
    if (key === 'gimmick' && typeof char._gimmickMatchCount === 'number') return char._gimmickMatchCount;

    return 0;
};

const getActiveCharMatchKey = () => {
    if (window._shouldRankByCharMatch) return 'char';
    return null;
};

const getActiveGimmickMatchKey = () => {
    if (window._shouldRankByGimmickMatch) return 'gimmick';
    return null;
};

const normalizeTraitTagValue = (value) => {
    const normalizedValue = String(value || '').trim();
    if (!normalizedValue) return '';
    if (normalizedValue === 'give_trait') return normalizedValue;
    return normalizedValue.replace(/^trait_/, '');
};

const prepareSearchToken = (val) => {
    const decoded = fullyDecode(val);
    return {
        norm: normalizeForSearch(decoded),
        raw: String(val || '').toLowerCase().trim()
    };
};

const prepareSearchTokens = (values) => {
    const safeValues = Array.isArray(values) ? values : (values ? [values] : []);
    return safeValues.map(prepareSearchToken);
};

const buildNormalizedSet = (values) => {
    const normSet = new Set();
    const rawSet = new Set();
    const safeValues = Array.isArray(values) ? values : (values ? [values] : []);
    for (let i = 0; i < safeValues.length; i++) {
        const item = safeValues[i];
        const decodedItem = fullyDecode(item);
        normSet.add(normalizeForSearch(decodedItem));
        rawSet.add(String(item || '').toLowerCase().trim());
    }
    return { normSet, rawSet };
};

const matchTokenInSet = (setObj, token) => {
    if (!setObj || setObj.rawSet.size === 0) return false;
    return setObj.normSet.has(token.norm) || setObj.rawSet.has(token.raw);
};

const checkRelationWithSet = (tokens, setObj, relation) => {
    if (!tokens || tokens.length === 0) return true;
    if (!setObj || setObj.rawSet.size === 0) return false;
    if (relation === 'AND') {
        for (let i = 0; i < tokens.length; i++) {
            if (!matchTokenInSet(setObj, tokens[i])) return false;
        }
        return true;
    }
    for (let i = 0; i < tokens.length; i++) {
        if (matchTokenInSet(setObj, tokens[i])) return true;
    }
    return false;
};

const countMatchedTokensInSet = (uniqueTokens, setObj) => {
    if (!uniqueTokens || uniqueTokens.length === 0 || !setObj || setObj.rawSet.size === 0) return 0;
    let count = 0;
    for (let i = 0; i < uniqueTokens.length; i++) {
        if (matchTokenInSet(setObj, uniqueTokens[i])) count++;
    }
    return count;
};

// ロード時に1回だけ全キャラの検索用インデックスを構築する
function prepareSearchIndex(characters, maps) {
    if (!Array.isArray(characters)) return [];
    const eventMap = (maps && maps.eventMap) || getEventMap();

    for (let i = 0; i < characters.length; i++) {
        const char = characters[i];
        const charAllEvents = [
            ...(char.events || []),
            ...(char['sub-event'] || char.sub_event || []),
            ...(char.gerilla_root || char.guerilla_root || [])
        ];
        const eventNamesJp = new Array(charAllEvents.length);
        for (let j = 0; j < charAllEvents.length; j++) {
            const slug = charAllEvents[j];
            const decoded = fullyDecode(slug);
            eventNamesJp[j] = eventMap[slug] || eventMap[decoded] || decoded;
        }

        const baseTargets = [
            char.name,
            char.name_ruby,
            ...eventNamesJp,
            ...(Array.isArray(char.group_jp) ? char.group_jp : []),
            ...(Array.isArray(char.gimmick_jp) ? char.gimmick_jp : []),
            ...(Array.isArray(char.trait1_jp) ? char.trait1_jp : []),
            ...(Array.isArray(char.trait2_jp) ? char.trait2_jp : []),
            ...(Array.isArray(char.blessing_jp) ? char.blessing_jp : []),
            ...(Array.isArray(char.leader_status_resistance_jp) ? char.leader_status_resistance_jp : [])
        ];
        const traitResistTargets = Array.isArray(char.trait_status_resistance_jp) ? char.trait_status_resistance_jp : [];

        char._kwBase = baseTargets.map(normalizeForSearch).join('\n');
        char._kwTraitResist = traitResistTargets.map(normalizeForSearch).join('\n');
        char._hasAmpBase = baseTargets.some(v => {
            const raw = normalizeRawForSearch(v);
            return raw.includes('&') || raw.includes('＆');
        });
        char._hasAmpTrait = traitResistTargets.some(v => {
            const raw = normalizeRawForSearch(v);
            return raw.includes('&') || raw.includes('＆');
        });
        char._cvNorm = char.cv ? normalizeForSearch(char.cv) : '';
        char._charSet = buildNormalizedSet(Array.isArray(char.chars) ? char.chars.map(c => c && c.val) : []);

        const attrMainSlug = ATTR_MAP[char.attr] ? ATTR_MAP[char.attr].slug : '';
        const attrSubSlugs = Array.isArray(char.sub_attrs)
            ? char.sub_attrs.map(a => ATTR_MAP[a] ? ATTR_MAP[a].slug : '')
            : [];
        char._attrMainSet = buildNormalizedSet(attrMainSlug ? [attrMainSlug] : []);
        char._attrCombinedSet = buildNormalizedSet([attrMainSlug, ...attrSubSlugs]);

        const speciesSlug = SPECIES_MAP[char.spe] ? SPECIES_MAP[char.spe].slug : '';
        char._speciesSet = buildNormalizedSet(speciesSlug ? [speciesSlug] : []);
        char._rarSet = buildNormalizedSet(char.rar_t);
        char._groupSet = buildNormalizedSet(char.group_en);
        char._eventSet = buildNormalizedSet(charAllEvents);
        char._questSet = buildNormalizedSet(char.quests);
        char._gimmickSet = buildNormalizedSet(char.gimmick_en);
        char._acqSet = buildNormalizedSet(char.acq);
        char._axisSet = buildNormalizedSet(char.axis);
        char._trait1Set = buildNormalizedSet(char.trait1_en);
        char._trait2Set = buildNormalizedSet(char.trait2_en);
        char._blessingSet = buildNormalizedSet(char.blessing_en);
        char._priStr = char.pri ? String(char.pri) : '';
        char._rowHtml = null;
    }

    // name_ruby の日本語ロケール順位を事前計算（同値には同一ランクを付与して第2ソートと完全一致させる）
    const uniqueRubies = Array.from(new Set(characters.map(c => String(c.name_ruby ?? ''))));
    uniqueRubies.sort(compareJa);
    const rubyRankMap = new Map();
    let rank = 0;
    for (let i = 0; i < uniqueRubies.length; i++) {
        if (i > 0 && compareJa(uniqueRubies[i - 1], uniqueRubies[i]) !== 0) {
            rank++;
        }
        rubyRankMap.set(uniqueRubies[i], rank);
    }
    for (let i = 0; i < characters.length; i++) {
        characters[i]._rubyRank = rubyRankMap.get(String(characters[i].name_ruby ?? ''));
    }

    return characters;
}

// 汎用的なAND/ORトグルを含む判定関数
function checkRelationCondition(checkedValues, charValues, relation) {
    if (checkedValues.length === 0) return true;

    // データ未定義時のエラーを防止
    const safeCharValues = Array.isArray(charValues) ? charValues : (charValues ? [charValues] : []);

    if (relation === 'AND') {
        return checkedValues.every(val => safeIncludes(safeCharValues, val));
    } else {
        return checkedValues.some(val => safeIncludes(safeCharValues, val));
    }
}

// =========================================================
// ▼▼▼ リーダー検索第二段階：対象+補正+条件の照合ロジック ▼▼▼
// =========================================================

const LEADER_STATUS_KEYS = ['poison', 'sleep', 'curse', 'confusion', 'pollution', 'burn', 'remodel', 'weakness', 'mutation', 'erasure'];

/**
 * 単一のリーダー効果(eff)が指定の単一ターゲット(type, slug)を対象に含むか判定する
 */
function effCoversSingleTarget(eff, targetType, slug, options) {
    const tgts = Array.isArray(eff?.tgts) ? eff.tgts : [];
    if (tgts.length === 0) return false;

    const allowAllFallback = Boolean(options && options.allowAllFallback);
    const isAllQuery = Boolean(options && options.isAllQuery);

    if (allowAllFallback || isAllQuery) {
        if (tgts.some(t => t && t.ty === 'all')) {
            return true;
        }
    }

    return tgts.some(t => {
        if (!t || t.ty !== targetType || !Array.isArray(t.slgs) || t.slgs.length === 0) {
            return false;
        }
        if (targetType === 'group') {
            return safeIncludes(t.slgs, slug);
        }
        return t.slgs.includes(Number(slug)) || t.slgs.includes(slug);
    });
}

/**
 * キャラのリーダー効果群全体に対して、対象条件と効果述語を組み合わせて判定する
 */
function checkEffectWithTarget(leaders, targetCond, effectPredicate, options) {
    const allowAllFallback = Boolean(options && options.allowAllFallback);
    const ignoreTarget = Boolean(options && options.ignoreTarget);

    if (!targetCond || !targetCond.active || ignoreTarget) {
        return leaders.some(ldr => {
            const effs = Array.isArray(ldr?.effs) ? ldr.effs : [];
            if (effs.length === 0) {
                return effectPredicate(ldr, null);
            }
            return effs.some(eff => effectPredicate(ldr, eff));
        });
    }

    const slugs = Array.isArray(targetCond.slugs) ? targetCond.slugs : [];
    if (slugs.length === 0) return false;

    const matchSlug = (slug) => leaders.some(ldr =>
        (Array.isArray(ldr?.effs) ? ldr.effs : []).some(eff =>
            effectPredicate(ldr, eff) &&
            effCoversSingleTarget(eff, targetCond.type, slug, {
                allowAllFallback,
                isAllQuery: Boolean(targetCond.isAll)
            })
        )
    );

    if (targetCond.relation === 'AND') {
        return slugs.every(matchSlug);
    }
    return slugs.some(matchSlug);
}

/**
 * 状態異常耐性の効果値を取得する
 */
function getLeaderStatusResistanceValue(vals, statusKey) {
    if (!vals || typeof vals !== 'object') return 0;
    if (statusKey === 'all') {
        if (vals.all !== undefined && vals.all !== null && vals.all !== '') {
            return Number(vals.all) || 0;
        }
        const allValues = LEADER_STATUS_KEYS.map(k => Number(vals[k] || 0));
        if (allValues.every(v => v > 0)) {
            return Math.min(...allValues);
        }
        return 0;
    }
    const direct = Number(vals[statusKey] || 0);
    if (direct > 0) return direct;
    return Number(vals.all || 0);
}

/**
 * 追加条件の1項目が、キャラ側の条件要素(c)に一致するか判定する
 */
function matchSingleLeaderCondItem(c, ldr, condQuery) {
    if (!c || c.ty !== condQuery.type) return false;

    const tgts = Array.isArray(c.tgts) ? c.tgts : [];
    const hasTargetSlugs = Array.isArray(condQuery.targetSlugs) && condQuery.targetSlugs.length > 0;
    const hasNumVal = condQuery.numVal !== null && condQuery.numVal !== undefined && Number(condQuery.numVal) > 0;
    const textVal = String(condQuery.textVal || '').trim();

    if (hasTargetSlugs) {
        const matchingTgts = tgts.filter(ct => {
            if (!ct || ct.ty !== condQuery.targetType || !Array.isArray(ct.slgs) || ct.slgs.length === 0) {
                return false;
            }
            return condQuery.targetSlugs.some(s =>
                condQuery.targetType === 'group'
                    ? safeIncludes(ct.slgs, s)
                    : (ct.slgs.includes(Number(s)) || ct.slgs.includes(s))
            );
        });
        if (matchingTgts.length === 0) return false;
        if (hasNumVal && condQuery.type === 'chara_num') {
            return matchingTgts.some(ct => Number(ct.num) > 0 && Number(ct.num) <= Number(condQuery.numVal));
        }
        return true;
    }

    if (hasNumVal) {
        const maxAllowed = Number(condQuery.numVal);
        if (condQuery.type === 'chara_num') {
            return tgts.some(ct => Number(ct?.num) > 0 && Number(ct.num) <= maxAllowed);
        }
        if (condQuery.type === 'wave_count') {
            const valsMatch = Array.isArray(c.vals) && c.vals.some(v => Number(v) > 0 && Number(v) <= maxAllowed);
            const lmMatch = Number(ldr?.lm_wave) > 0 && Number(ldr.lm_wave) <= maxAllowed;
            return valsMatch || lmMatch;
        }
        return Array.isArray(c.vals) && c.vals.some(v => Number(v) > 0 && Number(v) <= maxAllowed);
    }

    if (textVal !== '') {
        const normText = normalizeForSearch(textVal);
        return Array.isArray(c.vals) && c.vals.some(v => normalizeForSearch(v).includes(normText));
    }

    return true;
}

/**
 * 新UIのリーダー検索クエリがキャラクターにマッチするか判定する
 * @param {Object} char - キャラクターデータ
 * @param {Object} query - collectLeaderSearchConditions() が返す新クエリオブジェクト
 * @returns {boolean}
 */
function matchLeaderCharacter(char, query) {
    const leaders = Array.isArray(char?.leader) ? char.leader : [];
    if (leaders.length === 0 || !query) return false;

    const targetCond = query.target || { active: false };
    const hasHp = query.hp !== null && query.hp !== undefined && Number(query.hp) > 0;
    const hasAtk = query.atk !== null && query.atk !== undefined && Number(query.atk) > 0;
    const statuses = Array.isArray(query.statuses) ? query.statuses : [];
    const others = Array.isArray(query.others) ? query.others : [];
    const conditions = Array.isArray(query.conditions) ? query.conditions : [];

    // 1. HP
    if (hasHp) {
        const minHp = Number(query.hp);
        const ok = checkEffectWithTarget(
            leaders,
            targetCond,
            (ldr, eff) => (ldr.ty === 'fixed' || ldr.ty === 'per_unit') && Number(eff?.vals?.hp || 0) >= minHp,
            { allowAllFallback: false, ignoreTarget: false }
        );
        if (!ok) return false;
    }

    // 2. ATK
    if (hasAtk) {
        const minAtk = Number(query.atk);
        const ok = checkEffectWithTarget(
            leaders,
            targetCond,
            (ldr, eff) => (ldr.ty === 'fixed' || ldr.ty === 'per_unit') && Number(eff?.vals?.atk || 0) >= minAtk,
            { allowAllFallback: false, ignoreTarget: false }
        );
        if (!ok) return false;
    }

    // 3. 状態異常耐性（指定対象または all を許容。0/未入力時は > 0 でヒット）
    for (let i = 0; i < statuses.length; i++) {
        const st = statuses[i];
        const minVal = Number(st.minVal || 0);
        const ok = checkEffectWithTarget(
            leaders,
            targetCond,
            (ldr, eff) => {
                if (ldr.ty !== 'fixed' && ldr.ty !== 'per_unit') return false;
                const val = getLeaderStatusResistanceValue(eff?.vals, st.status);
                return minVal > 0 ? val >= minVal : val > 0;
            },
            { allowAllFallback: true, ignoreTarget: false }
        );
        if (!ok) return false;
    }

    // 4. その他効果（exp_up は対象不問、それ以外は指定対象のみ）
    const buffMap = (typeof window !== 'undefined' && window.LEADER_CONSTANTS?.BUFF_TYPE_MAP) || {};
    for (let i = 0; i < others.length; i++) {
        const oth = others[i];
        const map = buffMap[oth.type];
        if (!map) return false;

        const minVal = Number(oth.minVal || 0);
        const ok = checkEffectWithTarget(
            leaders,
            targetCond,
            (ldr, eff) => {
                const vals = eff?.vals || {};
                if (map.matchBy === 'vals_key') {
                    if (ldr.ty !== 'fixed' && ldr.ty !== 'per_unit') return false;
                    const v = map.stat === 'crit_damage'
                        ? Number(vals.crit_damage ?? vals.critical_damage ?? 0)
                        : Number(vals[map.stat] || 0);
                    return minVal > 0 ? v >= minVal : v > 0;
                }
                if (map.matchBy === 'ty_or_vals') {
                    const v = Number(vals[map.stat] ?? ldr.oth_val ?? 0);
                    if (ldr.ty === map.ty) return minVal > 0 ? v >= minVal : true;
                    const valNum = Number(vals[map.stat] || 0);
                    if (valNum > 0) return minVal > 0 ? valNum >= minVal : true;
                    return false;
                }
                if (ldr.ty !== map.ty) return false;
                if (minVal > 0) {
                    const v = Number((map.stat ? vals[map.stat] : undefined) ?? ldr.oth_val ?? 0);
                    return v >= minVal;
                }
                return true;
            },
            { allowAllFallback: false, ignoreTarget: Boolean(map.ignoreTarget) }
        );
        if (!ok) return false;
    }

    // 5. 対象のみが選択されていて効果が1つも入力されていない場合
    const hasAnyEffectFilter = hasHp || hasAtk || statuses.length > 0 || others.length > 0;
    if (!hasAnyEffectFilter && targetCond.active) {
        const ok = checkEffectWithTarget(
            leaders,
            targetCond,
            (ldr, eff) => Boolean(eff),
            { allowAllFallback: false, ignoreTarget: false }
        );
        if (!ok) return false;
    }

    // 6. 追加条件
    for (let i = 0; i < conditions.length; i++) {
        const condQuery = conditions[i];
        const matchedCond = leaders.some(ldr => {
            const condGroups = Array.isArray(ldr?.cond) ? ldr.cond : [];
            return condGroups.some(group => {
                const condList = Array.isArray(group) ? group : [group];
                return condList.some(c => matchSingleLeaderCondItem(c, ldr, condQuery));
            });
        });
        if (!matchedCond) return false;
    }

    return true;
}

/**
 * リーダー効果が検索条件にマッチするか判定（単一効果オブジェクト向け互換関数）
 * @param {Object} ldrEff - char.leader[] の1要素
 * @param {Object} condition - リーダー検索条件オブジェクト
 * @returns {boolean}
 */
function matchLeaderEffect(ldrEff, condition) {
    // BUFF_TYPE_MAPから照合方法を取得
    const map = window.LEADER_CONSTANTS?.BUFF_TYPE_MAP?.[condition.raw?.type];

    // typeが指定されている場合は効果タイプの照合を行う
    if (condition.raw?.type && map) {
        // === ケース1: valsキーで照合（hp, atk, crit_rateなど）===
        if (map.matchBy === 'vals_key') {
            // ldrEff.ty が 'fixed' または 'per_unit' であることを確認
            if (ldrEff.ty !== 'fixed' && ldrEff.ty !== 'per_unit') {
                return false;
            }

            // ldrEff.effs[0].vals に該当キーがあり、値が0より大きい
            const vals = ldrEff.effs?.[0]?.vals;

            // 状態異常耐性の場合は、具体的な状態異常を検索
            let statValue = null;
            if (map.stat === null) {
                // status_resistance: 具体的な状態異常が指定されているか確認
                const specificStatus = condition.raw?.statusResistance;
                if (specificStatus && vals) {
                    // 具体的な状態異常が指定された場合、そのキーのみを検索
                    statValue = vals[specificStatus];
                } else {
                    // 指定がない場合は、全ての状態異常キーを探す
                    for (const key of LEADER_STATUS_KEYS) {
                        if (vals && vals[key]) {
                            statValue = vals[key];
                            break;
                        }
                    }
                }
            } else {
                statValue = vals?.[map.stat];
            }

            if (!vals || !statValue) {
                return false;
            }

            // 補正値の条件があれば照合（検索値以上の補正を持つキャラを表示）
            if (condition.buffValue > 0 && statValue < condition.buffValue) {
                return false;
            }
        }
        // === ケース2: tyで照合（exp_up, corruptionなど）===
        else {
            if (ldrEff.ty !== map.ty) {
                return false;
            }
        }
    }
    // typeが空の場合は効果タイプのチェックをスキップし、対象のみで判定

    // === 対象（tgts）の照合 ===
    const tgts = ldrEff.effs?.[0]?.tgts;

    // vals_keyタイプ（fixed/per_unit）はtgts必須、tyタイプはtgtsが空でもOK（対象全体に適用）
    if (map?.matchBy === 'vals_key') {
        if (!matchLeaderTarget(tgts, condition.target)) {
            return false;
        }
    } else if (map?.matchBy === 'ty') {
        // tyタイプはtgtsがあれば照合、なければスキップ（対象全体に適用）
        if (tgts && tgts.length > 0) {
            if (!matchLeaderTarget(tgts, condition.target)) {
                return false;
            }
        }
    } else {
        // マッピングがない場合は従来通り（typeが空の場合など）
        if (!matchLeaderTarget(tgts, condition.target)) {
            return false;
        }
    }

    return true;
}

/**
 * リーダー効果の対象（tgts）が検索条件にマッチするか判定
 * @param {Array} tgts - ldrEff.effs[0].tgts（対象配列）
 * @param {Object} searchTarget - 検索条件のtarget
 * @returns {boolean}
 */
function matchLeaderTarget(tgts, searchTarget) {

    if (!tgts || tgts.length === 0) {
        return false;
    }

    // 検索条件が"all"の場合、tgtsに"all"が含まれていればOK
    if (searchTarget.type === 'all') {
        const hasAll = tgts.some(t => t.ty === 'all');
        return hasAll;
    }

    // 検索条件と一致するtgtを探す
    const result = tgts.some(tgt => {

        // タイプが一致しない場合はスキップ
        if (tgt.ty !== searchTarget.type) {
            return false;
        }

        // slgs（スラッグ/ID配列）の照合
        if (!tgt.slgs || tgt.slgs.length === 0) {
            return false;
        }


        // searchTarget.slugs が tgt.slgs の一部と一致すればOK（OR条件）
        const match = searchTarget.slugs.some(slug => tgt.slgs.includes(slug));
        return match;
    });

    return result;
}

// トグルスイッチ（チェックボックス）のAND/OR判定を取得するヘルパー（対象フォームにスコープ限定）
function getRelationValue(name, defaultVal, root = document) {
    const scope = root || document;
    const checkbox = scope.querySelector(`input[type="checkbox"][name="${name}"]`);
    if (checkbox) return checkbox.checked ? 'AND' : 'OR';

    // トグルがない場合は従来のラジオボタンを探すフォールバック
    const radio = scope.querySelector(`input[type="radio"][name="${name}"]:checked`);
    return radio ? radio.value : defaultVal;
}

function collectSearchState(targetForm) {
    const form = resolveActiveSearchForm(targetForm);
    const scope = form || document;
    const sInput = scope.querySelector('input[name="s"]') || document.getElementById('s');
    const charInput = scope.querySelector('input[name="search_char"]');
    const cvInput = scope.querySelector('input[name="tx_cv"]');

    return {
        rawKeyword: sInput ? sInput.value : '',
        includeTraitStatusResistance: Boolean(scope.querySelector('input[name="include_trait_status_resistance"]:checked')),
        rawSearchChar: charInput ? charInput.value : '',
        rawSearchCv: cvInput ? cvInput.value : '',
        checkedAxises: getCheckedValues('input[name="tx_axis[]"]:checked', scope),
        checkedAttrs: getCheckedValues('input[name="tx_attr[]"]:checked', scope),
        checkedSpecies: getCheckedValues('input[name="tx_species[]"]:checked', scope),
        checkedRarities: getCheckedValues('input[name="tx_rarity[]"]:checked', scope),
        checkedGroups: getCheckedValues('input[name="tx_group[]"]:checked', scope),
        checkedEvents: getCheckedValues('input[name="tx_event[]"]:checked', scope),
        checkedQuests: getCheckedValues('input[name="tx_quest[]"]:checked', scope),
        checkedGimmicks: getCheckedValues('input[name="tx_gimmick[]"]:checked', scope),
        checkedPriorities: getCheckedValues('input[name="tx_priority[]"]:checked', scope),
        checkedAcqs: getCheckedValues('input[name="tx_acq[]"]:checked', scope),
        checkedSkillTags: getCheckedValues('input[name="tx_skill_tags[]"]:checked', scope),
        checkedSkillScopes: getCheckedValues('input[name="scope_skill[]"]:checked', scope),
        checkedTraitTags: getCheckedValues('input[name="tx_trait_tags[]"]:checked', scope),
        checkedTraitScopes: getCheckedValues('input[name="scope_trait[]"]:checked', scope),
        relAxis: getRelationValue('tx_axis_relation', 'OR', scope),
        relGroup: getRelationValue('tx_group_relation', 'OR', scope),
        relEvent: getRelationValue('tx_event_relation', 'OR', scope),
        relQuest: getRelationValue('tx_quest_relation', 'OR', scope),
        relGimmick: getRelationValue('tx_gimmick_relation', 'OR', scope),
        relAcq: getRelationValue('tx_acq_relation', 'OR', scope),
        relAttr: getRelationValue('tx_attr_relation', 'OR', scope),
        subAttrToggle: getRelationValue('tx_attr_sub', 'OR', scope),
        relSpecies: getRelationValue('tx_species_relation', 'OR', scope),
        relRarity: getRelationValue('tx_rarity_relation', 'OR', scope),
        relSkillTags: getRelationValue('tx_skill_tags_relation', 'OR', scope),
        relTraitTags: getRelationValue('tx_trait_tags_relation', 'OR', scope),
        relChar: getRelationValue('search_char_relation', 'AND', scope),
        leaderConditions: window.collectLeaderSearchConditions ? window.collectLeaderSearchConditions(form) : []
    };
}

function filterCharacterList(characters, state) {
    if (!Array.isArray(characters) || characters.length === 0) return [];
    if (characters[0]._kwBase === undefined) {
        prepareSearchIndex(characters);
    }

    const keyword = normalizeForSearch(state.rawKeyword);
    const keywordTerms = keyword ? keyword.split(/\s+/u).filter(Boolean) : [];
    const parsedKeywordTerms = keywordTerms.map(term => {
        const normTerm = normalizeForSearch(term);
        const rawTerm = normalizeRawForSearch(term);
        return {
            normTerm,
            isAmpOnly: rawTerm === '&' || rawTerm === '＆'
        };
    });
    const includeTraitStatusResistance = Boolean(state.includeTraitStatusResistance);

    const searchChar = normalizeForSearch(state.rawSearchChar);
    const targetChars = searchChar
        ? Array.from(new Set(searchChar)).filter(c => c.trim() !== '' && c !== ',' && c !== '、')
        : [];
    const uniqueTargetCharTokens = targetChars.length > 0
        ? prepareSearchTokens(Array.from(new Set(targetChars.filter(v => v !== null && v !== undefined && String(v).trim() !== ''))))
        : [];

    const searchCv = normalizeForSearch(state.rawSearchCv);

    const attrTokens = prepareSearchTokens(state.checkedAttrs);
    const speciesTokens = prepareSearchTokens(state.checkedSpecies);
    const rarityTokens = prepareSearchTokens(state.checkedRarities);
    const groupTokens = prepareSearchTokens(state.checkedGroups);
    const eventTokens = prepareSearchTokens(state.checkedEvents);
    const questTokens = prepareSearchTokens(state.checkedQuests);
    const gimmickTokens = prepareSearchTokens(state.checkedGimmicks);
    const uniqueGimmickTokens = gimmickTokens.length > 0
        ? prepareSearchTokens(Array.from(new Set(state.checkedGimmicks.filter(v => v !== null && v !== undefined && String(v).trim() !== ''))))
        : [];
    const acqTokens = prepareSearchTokens(state.checkedAcqs);
    const axisTokens = prepareSearchTokens(state.checkedAxises);
    const prioritySet = state.checkedPriorities && state.checkedPriorities.length > 0
        ? new Set(state.checkedPriorities)
        : null;

    const checkedSkillTags = state.checkedSkillTags || [];
    const checkedSkillScopes = state.checkedSkillScopes || [];
    const hasSkillTags = checkedSkillTags.length > 0;
    const hasSkillScopes = checkedSkillScopes.length > 0;
    const hasScopeWaza = checkedSkillScopes.includes('waza');
    const hasScopeSugo = checkedSkillScopes.includes('sugo');
    const hasScopeKoto = checkedSkillScopes.includes('kotowaza');
    const skillTagNeedles = hasSkillTags ? checkedSkillTags.map(tag => ` ${tag} `) : [];

    const checkedTraitTags = state.checkedTraitTags || [];
    const checkedTraitScopes = state.checkedTraitScopes || [];
    const hasTraitTags = checkedTraitTags.length > 0;
    const hasTraitScopes = checkedTraitScopes.length > 0;
    const hasScopeT1 = checkedTraitScopes.includes('t1');
    const hasScopeT2 = checkedTraitScopes.includes('t2');
    const hasScopeBlessing = checkedTraitScopes.includes('blessing');
    const traitTagTokens = hasTraitTags
        ? checkedTraitTags.map(tag => {
            const nt = normalizeTraitTagValue(tag);
            return nt ? prepareSearchToken(nt) : null;
        })
        : [];

    const leaderConditions = Array.isArray(state.leaderConditions) ? state.leaderConditions : [];
    const hasLeaderSearch = leaderConditions.length > 0;

    return characters.filter(char => {
        // --- 属性フィルター ---
        if (attrTokens.length > 0) {
            const targetAttrSet = state.subAttrToggle === 'OR' ? char._attrCombinedSet : char._attrMainSet;
            if (!checkRelationWithSet(attrTokens, targetAttrSet, state.relAttr)) return false;
        }

        // --- フリーワード検索 ---
        if (parsedKeywordTerms.length > 0) {
            for (let i = 0; i < parsedKeywordTerms.length; i++) {
                const t = parsedKeywordTerms[i];
                let matched = char._kwBase.includes(t.normTerm);
                if (!matched && includeTraitStatusResistance) {
                    matched = char._kwTraitResist.includes(t.normTerm);
                }
                if (!matched && t.isAmpOnly) {
                    matched = char._hasAmpBase || (includeTraitStatusResistance && char._hasAmpTrait);
                }
                if (!matched) return false;
            }
        }

        // --- CV ---
        if (searchCv) {
            if (!char._cvNorm || !char._cvNorm.includes(searchCv)) return false;
        }

        // --- 使用可能文字 ---
        if (uniqueTargetCharTokens.length > 0) {
            const matchedCount = countMatchedTokensInSet(uniqueTargetCharTokens, char._charSet);
            setMatchCount(char, 'char', matchedCount);
            char._charMatchCount = matchedCount; // 互換用: マッチ数を保存
            if (matchedCount === 0) return false; // 0文字は除外
        }

        // --- 各種タグ・タクソノミー ---
        if (speciesTokens.length > 0 && !checkRelationWithSet(speciesTokens, char._speciesSet, state.relSpecies)) return false;
        if (rarityTokens.length > 0 && !checkRelationWithSet(rarityTokens, char._rarSet, state.relRarity)) return false;
        if (groupTokens.length > 0 && !checkRelationWithSet(groupTokens, char._groupSet, state.relGroup)) return false;
        if (eventTokens.length > 0 && !checkRelationWithSet(eventTokens, char._eventSet, state.relEvent)) return false;
        if (questTokens.length > 0 && !checkRelationWithSet(questTokens, char._questSet, state.relQuest)) return false;
        if (gimmickTokens.length > 0) {
            if (!checkRelationWithSet(gimmickTokens, char._gimmickSet, state.relGimmick)) return false;
            const matchedGimmickCount = countMatchedTokensInSet(uniqueGimmickTokens, char._gimmickSet);
            setMatchCount(char, 'gimmick', matchedGimmickCount);
            char._gimmickMatchCount = matchedGimmickCount;
        }
        if (acqTokens.length > 0 && !checkRelationWithSet(acqTokens, char._acqSet, state.relAcq)) return false;
        if (axisTokens.length > 0 && !checkRelationWithSet(axisTokens, char._axisSet, state.relAxis)) return false;

        if (prioritySet) {
            if (!prioritySet.has(char._priStr)) return false;
        }

        // --- わざ・すごわざ・コトワザ ---
        if (hasSkillTags) {
            if (!hasSkillScopes) return false;
            let hasMatch = state.relSkillTags === 'AND';
            for (let i = 0; i < skillTagNeedles.length; i++) {
                const s = skillTagNeedles[i];
                const m = (hasScopeWaza && char.waza_t && char.waza_t.includes(s)) ||
                    (hasScopeSugo && char.sugo_t && char.sugo_t.includes(s)) ||
                    (hasScopeKoto && char.koto_t && char.koto_t.includes(s));
                if (state.relSkillTags === 'AND') {
                    if (!m) { hasMatch = false; break; }
                } else {
                    if (m) { hasMatch = true; break; }
                }
            }
            if (!hasMatch) return false;
        }

        // --- とくせい・祝福 ---
        if (hasTraitTags) {
            if (!hasTraitScopes) return false;
            let hasMatch = state.relTraitTags === 'AND';
            for (let i = 0; i < traitTagTokens.length; i++) {
                const token = traitTagTokens[i];
                const m = token ? (
                    (hasScopeT1 && matchTokenInSet(char._trait1Set, token)) ||
                    (hasScopeT2 && matchTokenInSet(char._trait2Set, token)) ||
                    (hasScopeBlessing && matchTokenInSet(char._blessingSet, token))
                ) : false;
                if (state.relTraitTags === 'AND') {
                    if (!m) { hasMatch = false; break; }
                } else {
                    if (m) { hasMatch = true; break; }
                }
            }
            if (!hasMatch) return false;
        }

        // ★追加：リーダー検索
        if (hasLeaderSearch) {
            if (!char.leader || char.leader.length === 0) return false;

            // 複数条件のいずれかに一致すればOK（新UIの単一クエリは matchLeaderCharacter で判定）
            const matchAnyCondition = leaderConditions.some(condition => {
                if (condition && condition.isNewLeaderQuery) {
                    return matchLeaderCharacter(char, condition);
                }
                // キャラの複数リーダー効果のいずれかに一致すればOK
                return char.leader.some(ldrEff => matchLeaderEffect(ldrEff, condition));
            });

            if (!matchAnyCondition) return false;
        }

        return true;
    });
}

// 検索ロジックの本体
function filterCharacters(options) {
    const opts = (options && typeof options === 'object') ? options : {};
    const activeForm = resolveActiveSearchForm(opts.form);
    if (activeForm && typeof window.setActiveSearchForm === 'function') {
        window.setActiveSearchForm(activeForm);
    }

    const state = collectSearchState(activeForm);
    const searchChar = normalizeForSearch(state.rawSearchChar);

    // ★追加：AND検索フラグをグローバルに設定
    window._isCharAndSearch = Boolean(searchChar && state.relChar === 'AND');
    window._isGimmickAndSearch = Boolean(state.checkedGimmicks.length > 0 && state.relGimmick === 'AND');
    window._hasGimmickSearch = state.checkedGimmicks.length > 0;
    window._hasCharSearch = Boolean(searchChar);
    window._shouldRankByCharMatch = Boolean(searchChar);
    window._shouldRankByGimmickMatch = Boolean(state.checkedGimmicks.length > 0);

    // ★変更：結果を filteredCharacters に代入する
    filteredCharacters = filterCharacterList(allCharacters, state);

    // ★変更：ソートを実行してから描画する
    renderTable(sortCharacters(filteredCharacters), activeForm);

    // ★改善：初期ロード時はスキップ、確定操作時は同期で即時URL更新（iOS Chrome対策）
    if (opts.updateUrl !== false) {
        if (opts.immediateUrl) {
            updateSearchUrlImmediate(activeForm);
        } else {
            updateSearchUrl(activeForm);
        }
    }
}

// ★追加：特殊なキー（バフ・デバフの配列など）から比較用の数値を安全に取り出すヘルパー
function getSortValue(char, key) {
    switch (key) {
        case 'rarity': return getRaritySortValue(char);
        case 'buff_board': return char.bd_buff && char.bd_buff.length > 5 ? Number(char.bd_buff) : 0;
        case 'buff_hand': return char.hnd_buff && char.hnd_buff.length > 5 ? Number(char.hnd_buff) : 0;
        case 'debuff': return char.debuf && char.debuf.length > 5 ? Number(char.debuf) : 0;
        case 'firepower_index': return Number(char.firepower_index) || 0;
        case 'healingpower_index': return Number(char.healingpower_index) || 0;
        default: return char[key]; // それ以外はそのまま返す
    }
}

// ★変更：配列を並び替える関数（第2ソート対応版）
function sortCharacters(chars, sortKey, sortOrder) {
    const activeSortKey = sortKey || currentSortKey;
    const activeSortOrder = sortOrder || currentSortOrder;

    return chars.sort((a, b) => {
        // ★第0ソート：文字検索の一致数（降順）
        const activeCharMatchKey = getActiveCharMatchKey();
        if (activeCharMatchKey) {
            const scoreA = getMatchCount(a, activeCharMatchKey);
            const scoreB = getMatchCount(b, activeCharMatchKey);
            if (scoreA !== scoreB) {
                return scoreB - scoreA;
            }
        }

        // ★第0ソート：ギミック検索の一致数（降順）
        if (window._shouldRankByGimmickMatch) {
            const scoreA = getMatchCount(a, 'gimmick');
            const scoreB = getMatchCount(b, 'gimmick');
            if (scoreA !== scoreB) {
                return scoreB - scoreA;
            }
        }

        // 1. 第1ソートキーでの比較
        let diff = 0;
        if (activeSortKey === 'name_ruby' && a._rubyRank !== undefined && b._rubyRank !== undefined) {
            diff = a._rubyRank - b._rubyRank;
            if (activeSortOrder === 'DESC') {
                diff = -diff;
            }
        } else {
            let valA = getSortValue(a, activeSortKey);
            let valB = getSortValue(b, activeSortKey);

            if (valA === undefined || valA === null) valA = '';
            if (valB === undefined || valB === null) valB = '';

            // 両方数値の場合は引き算で比較
            if (typeof valA === 'number' && typeof valB === 'number') {
                diff = activeSortOrder === 'ASC' ? valA - valB : valB - valA;
            } else {
                // それ以外（文字列）は日本語ロケールで比較
                diff = compareJa(String(valA), String(valB));
                if (activeSortOrder === 'DESC') {
                    diff = -diff;
                }
            }
        }

        // 2. ★第1ソートで全く同じ値（diff === 0）だった場合の第2ソート処理
        if (diff === 0) {
            if (activeSortKey === 'name_ruby') {
                // 【A】メインが名前順の場合 ➡ 第2ソートは「実装日（新しい順）」
                let dateA = String(a.date || '');
                let dateB = String(b.date || '');
                if (dateA < dateB) return 1;  // 降順
                if (dateA > dateB) return -1;
                return 0;
            } else {
                // 【B】HPやATKなど、それ以外の並び替え時 ➡ 第2ソートは「名前順（あいうえお順）」
                if (a._rubyRank !== undefined && b._rubyRank !== undefined) {
                    return a._rubyRank - b._rubyRank;
                }
                let nameA = String(a.name_ruby || '');
                let nameB = String(b.name_ruby || '');
                return compareJa(nameA, nameB); // 昇順
            }
        }

        // 差があれば第1ソートの結果を返す
        return diff;
    });
}

// ★追加：ヘッダーのUI（矢印の向きや太字）を更新する関数
function updateSortUI() {
    document.querySelectorAll('.js-sort-link').forEach(link => {
        const arrow = link.querySelector('.sort-arrow');
        if (link.dataset.sortKey === currentSortKey) {
            link.classList.add('is-active');
            arrow.classList.remove('faint');
            arrow.classList.add('active');
            arrow.textContent = currentSortOrder === 'ASC' ? '▲' : '▼';
        } else {
            link.classList.remove('is-active');
            arrow.classList.remove('active');
            arrow.classList.add('faint');
            arrow.textContent = '▼';
        }
    });
}

function buildRenderItems(chars, options) {
    if (!Array.isArray(chars) || chars.length === 0) return [];
    const activeCharMatchKey = (options && options.activeCharMatchKey !== undefined)
        ? options.activeCharMatchKey
        : getActiveCharMatchKey();
    const shouldRankByGimmick = (options && options.shouldRankByGimmick !== undefined)
        ? options.shouldRankByGimmick
        : Boolean(window._shouldRankByGimmickMatch);

    const groupKey = activeCharMatchKey ? activeCharMatchKey : (shouldRankByGimmick ? 'gimmick' : null);
    if (!groupKey) {
        return chars.map(char => ({ type: 'char', char }));
    }

    const groups = new Map();
    for (let i = 0; i < chars.length; i++) {
        const c = chars[i];
        const count = getMatchCount(c, groupKey) || 0;
        let bucket = groups.get(count);
        if (!bucket) {
            bucket = [];
            groups.set(count, bucket);
        }
        bucket.push(c);
    }

    const sortedCounts = Array.from(groups.keys()).sort((a, b) => b - a);
    const items = [];
    for (let i = 0; i < sortedCounts.length; i++) {
        const count = sortedCounts[i];
        const sectionChars = groups.get(count);
        const headerText = activeCharMatchKey
            ? `${count}文字含むキャラクター (${sectionChars.length}件)`
            : `${count}件のギミック一致キャラクター (${sectionChars.length}件)`;
        items.push({ type: 'section', text: headerText });
        for (let j = 0; j < sectionChars.length; j++) {
            items.push({ type: 'char', char: sectionChars[j] });
        }
    }
    return items;
}

function disconnectLoadMoreObserver() {
    if (loadMoreObserver) {
        loadMoreObserver.disconnect();
        loadMoreObserver = null;
    }
}

function updateLoadMoreControls() {
    const wrap = document.getElementById('koto-load-more-wrap');
    const loadMoreBtn = document.getElementById('koto-load-more-btn');
    const sentinel = document.getElementById('koto-load-more-sentinel');
    const remaining = totalRenderCharCount - renderedCharCount;

    if (!wrap) return;

    if (remaining <= 0 || renderedItemIndex >= currentRenderItems.length) {
        wrap.classList.add('is-hidden');
        disconnectLoadMoreObserver();
        return;
    }

    wrap.classList.remove('is-hidden');
    if (loadMoreBtn) {
        loadMoreBtn.textContent = `さらに表示（残り ${remaining} 件）`;
    }

    if (sentinel && typeof IntersectionObserver === 'function' && !loadMoreObserver) {
        loadMoreObserver = new IntersectionObserver(entries => {
            for (let i = 0; i < entries.length; i++) {
                if (entries[i].isIntersecting) {
                    appendNextRenderChunk(RENDER_CHUNK_SIZE);
                    break;
                }
            }
        }, { rootMargin: '300px 0px' });
        loadMoreObserver.observe(sentinel);
    }
}

function appendNextRenderChunk(charLimit) {
    const tbody = document.getElementById('chara-list-body');
    if (!tbody || renderedItemIndex >= currentRenderItems.length) {
        updateLoadMoreControls();
        return;
    }

    const limit = typeof charLimit === 'number' ? charLimit : RENDER_CHUNK_SIZE;
    const parts = [];
    let addedChars = 0;

    while (renderedItemIndex < currentRenderItems.length && addedChars < limit) {
        const item = currentRenderItems[renderedItemIndex];
        if (item.type === 'section') {
            parts.push(`<tr class="section-header"><td colspan="100%">${escapeHtml(item.text)}</td></tr>`);
        } else {
            parts.push(createCharacterRowHtml(item.char));
            addedChars++;
            renderedCharCount++;
        }
        renderedItemIndex++;
    }

    if (parts.length > 0) {
        const html = parts.join('');
        if (tbody.children.length === 0) {
            tbody.innerHTML = html;
        } else {
            tbody.insertAdjacentHTML('beforeend', html);
        }
    }

    updateLoadMoreControls();
}

function renderTable(chars, targetForm) {
    const tbody = document.getElementById('chara-list-body');
    const spinner = document.getElementById('loading-spinner');

    // ★追加：該当件数の表示を更新
    // ※HTML側の要素に合わせてセレクタ（.hit-count, #hit-count など）を調整してください
    const hitCountElements = document.querySelectorAll('#hit-count-num');
    hitCountElements.forEach(el => {
        el.textContent = chars.length;
    });

    disconnectLoadMoreObserver();

    if (!tbody) {
        if (spinner) spinner.style.display = 'none';
        return;
    }

    if (chars.length === 0) {
        currentRenderItems = [];
        renderedItemIndex = 0;
        renderedCharCount = 0;
        totalRenderCharCount = 0;
        tbody.innerHTML = '<tr><td colspan="100%" class="no-data">条件に一致するキャラクターが見つかりませんでした。</td></tr>';
        updateLoadMoreControls();
    } else {
        currentRenderItems = buildRenderItems(chars);
        renderedItemIndex = 0;
        renderedCharCount = 0;
        totalRenderCharCount = chars.length;
        tbody.innerHTML = '';
        appendNextRenderChunk(RENDER_CHUNK_SIZE);
    }

    if (spinner) spinner.style.display = 'none';

    try {
        if (typeof window.applyCurrentColumnSettings === 'function') {
            window.applyCurrentColumnSettings();
        }
        updateDynamicColumns(targetForm);
    } catch (err) {
        console.warn('列表示設定の更新中にエラーが発生しました:', err);
    }
}

function isSearchModalOpen() {
    return Boolean(document.body && document.body.classList && document.body.classList.contains('is-modal-active'));
}

function scheduleFilterCharacters(delayMs, targetForm) {
    if (isSearchModalOpen()) {
        pendingModalFilter = true;
        if (targetForm) pendingModalForm = targetForm;
        return;
    }
    const wait = typeof delayMs === 'number' ? delayMs : INPUT_DEBOUNCE_MS;
    if (filterDebounceTimer) {
        clearTimeout(filterDebounceTimer);
    }
    filterDebounceTimer = setTimeout(() => {
        filterDebounceTimer = null;
        filterCharacters({ form: targetForm, immediateUrl: true });
    }, wait);
}

function requestFilterCharacters(immediate, targetForm) {
    if (isSearchModalOpen()) {
        pendingModalFilter = true;
        if (targetForm) pendingModalForm = targetForm;
        return;
    }
    if (immediate) {
        if (filterDebounceTimer) {
            clearTimeout(filterDebounceTimer);
            filterDebounceTimer = null;
        }
        filterCharacters({ form: targetForm, immediateUrl: true });
    } else {
        scheduleFilterCharacters(undefined, targetForm);
    }
}

function flushPendingModalFilter(targetForm) {
    if (!pendingModalFilter) return false;
    const form = targetForm || pendingModalForm;
    pendingModalFilter = false;
    pendingModalForm = null;
    if (filterDebounceTimer) {
        clearTimeout(filterDebounceTimer);
        filterDebounceTimer = null;
    }
    filterCharacters({ form, immediateUrl: true });
    return true;
}

window.filterCharacters = filterCharacters;
window.scheduleFilterCharacters = scheduleFilterCharacters;
window.requestFilterCharacters = requestFilterCharacters;
window.flushPendingModalFilter = flushPendingModalFilter;

// =========================================================
// 5. 初期化とイベント登録
// =========================================================
document.addEventListener('DOMContentLoaded', async function () {
    // ★追加：URLからソート状態を復元（ホワイトリスト検証）
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('sort_key')) {
        const requestedSortKey = urlParams.get('sort_key');
        if (ALLOWED_SORT_KEYS.has(requestedSortKey)) {
            currentSortKey = requestedSortKey;
        }
        const requestedOrder = (urlParams.get('sort_order') || '').toUpperCase();
        currentSortOrder = requestedOrder === 'DESC' ? 'DESC' : 'ASC';
    }
    const spinner = document.getElementById('loading-spinner');
    if (spinner) spinner.style.display = 'block';
    /**
    * URLパラメータを解析し、HTMLフォームの各部品に値を反映させる
    * @param {boolean} triggerFilter - 復元後に filterCharacters を呼び出すか（デフォルト: false）
    * @param {HTMLFormElement|null} targetForm - 対象フォーム（省略時は全 .js-search-form に復元）
    */
    function restoreSingleFormFromUrl(searchFormEl, urlParams) {
        if (!searchFormEl) return;

        // 動的リーダー検索行の復元（チェックボックス復元前にDOMを生成しておく）
        const statusListEl = searchFormEl.querySelector('.js-leader-status-list');
        const statusTypes = urlParams.getAll('leader_status_type[]');
        const statusVals = urlParams.getAll('leader_status_val[]');
        if (statusListEl && statusTypes.length > 0) {
            while (statusListEl.querySelectorAll('.js-leader-status-row').length < statusTypes.length && typeof window.appendLeaderStatusRow === 'function') {
                window.appendLeaderStatusRow(statusListEl);
            }
            const statusRows = statusListEl.querySelectorAll('.js-leader-status-row');
            statusTypes.forEach((st, idx) => {
                const row = statusRows[idx];
                if (!row) return;
                const sel = row.querySelector('select[name="leader_status_type[]"]');
                const inp = row.querySelector('input[name="leader_status_val[]"]');
                if (sel) sel.value = st;
                if (inp && statusVals[idx] !== undefined) inp.value = statusVals[idx];
            });
        }

        const otherListEl = searchFormEl.querySelector('.js-leader-other-list');
        const otherTypes = urlParams.getAll('leader_other_type[]');
        const otherVals = urlParams.getAll('leader_other_val[]');
        if (otherListEl && otherTypes.length > 0 && typeof window.appendLeaderOtherRow === 'function') {
            otherListEl.querySelectorAll('.js-leader-other-row').forEach(row => row.remove());
            otherTypes.forEach((ot, idx) => {
                const row = window.appendLeaderOtherRow(otherListEl);
                if (!row) return;
                const sel = row.querySelector('select[name="leader_other_type[]"]');
                const inp = row.querySelector('input[name="leader_other_val[]"]');
                if (sel) sel.value = ot;
                if (inp && otherVals[idx] !== undefined) inp.value = otherVals[idx];
            });
        }

        const condListEl = searchFormEl.querySelector('.js-leader-cond-list');
        const condIndexSet = new Set();
        for (const [key] of urlParams.entries()) {
            const m = key.match(/^leader_cond_(\d+)_/);
            if (m) condIndexSet.add(Number(m[1]));
        }
        if (condListEl && condIndexSet.size > 0 && typeof window.appendLeaderCondItem === 'function') {
            condListEl.querySelectorAll('.js-leader-cond-item').forEach(item => item.remove());
            Array.from(condIndexSet).sort((a, b) => a - b).forEach(condIdx => {
                const condItem = window.appendLeaderCondItem(condListEl, condIdx);
                if (!condItem) return;
                const prefix = `leader_cond_${condIdx}_`;
                const typeSel = condItem.querySelector(`select[name="${prefix}type"]`);
                const targetSel = condItem.querySelector(`select[name="${prefix}target"]`);
                const numInp = condItem.querySelector(`input[name="${prefix}val"]`);
                const textInp = condItem.querySelector(`input[name="${prefix}text"]`);
                if (typeSel && urlParams.has(`${prefix}type`)) typeSel.value = urlParams.get(`${prefix}type`);
                if (targetSel && urlParams.has(`${prefix}target`)) targetSel.value = urlParams.get(`${prefix}target`);
                if (numInp && urlParams.has(`${prefix}val`)) numInp.value = urlParams.get(`${prefix}val`);
                if (textInp && urlParams.has(`${prefix}text`)) textInp.value = urlParams.get(`${prefix}text`);
            });
        }

        const allFormControls = Array.from(searchFormEl.querySelectorAll('input, select'));

        // 1. 通常のテキスト・数値入力やセレクトボックスの復元 (s, tx_cv, search_char, leader_* など)
        const textInputs = ['s', 'tx_cv', 'search_char', 'leader_target_type', 'leader_hp_val', 'leader_atk_val'];
        textInputs.forEach(name => {
            if (urlParams.has(name)) {
                const input = allFormControls.find(el =>
                    el.name === name && ((el.tagName === 'INPUT' && (el.type === 'text' || el.type === 'number')) || el.tagName === 'SELECT')
                );
                if (input) {
                    input.value = urlParams.get(name);
                }
            }
        });

        // 2. ラジオボタンやトグルスイッチ（単一値）の復元
        const radioNames = [
            'search_char_relation', 'tx_axis_relation', 'tx_attr_relation', 'tx_attr_sub',
            'tx_species_relation', 'tx_acq_relation', 'tx_group_relation', 'tx_event_relation',
            'tx_quest_relation', 'tx_gimmick_relation', 'tx_rarity_relation', 'tx_skill_tags_relation',
            'tx_trait_tags_relation', 'string_match_target', 'include_trait_status_resistance', 'leader_target_relation'
        ];
        radioNames.forEach(name => {
            if (urlParams.has(name)) {
                const value = urlParams.get(name);

                // render_ios_toggle は hidden input と checkbox を両方生成するため、hidden の value('OR') は壊さず disabled で制御
                const allInputs = allFormControls.filter(el => el.name === name);
                const hasRadio = allInputs.some(el => el.type === 'radio');

                if (hasRadio) {
                    allInputs.forEach(el => {
                        if (el.type === 'radio') {
                            el.checked = (el.value === value);
                        }
                    });
                } else if (allInputs.length > 1) {
                    // iOS トグルスイッチの場合（hidden=OR, checkbox=AND）
                    const isChecked = (value === 'AND');
                    allInputs.forEach(el => {
                        if (el.type === 'hidden') {
                            el.value = 'OR';
                            el.disabled = isChecked;
                        } else if (el.type === 'checkbox') {
                            el.checked = isChecked;
                            const wrapper = el.closest('.ios-toggle-wrapper');
                            if (wrapper) {
                                const labels = wrapper.querySelectorAll('.ios-toggle-label-text');
                                if (labels.length >= 2) {
                                    labels[0].classList.toggle('active', !isChecked);
                                    labels[1].classList.toggle('active', isChecked);
                                }
                            }
                            el.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    });
                } else if (allInputs.length === 1) {
                    const element = allInputs[0];
                    if (element.type === 'checkbox') {
                        element.checked = (value === 'AND' || value === 'true' || value === '1' || value === element.value);
                        element.dispatchEvent(new Event('change', { bubbles: true }));
                    } else if (element.type === 'radio') {
                        element.checked = true;
                    } else if (element.type === 'hidden') {
                        element.value = value;
                    }
                }
            }
        });

        // 3. 配列形式のチェックボックスの復元 (tx_group[], tx_attr[] など)
        for (const [key, value] of urlParams.entries()) {
            if (key.endsWith('[]')) {
                let checkbox = null;
                const allCheckboxes = allFormControls.filter(el => el.tagName === 'INPUT' && el.name === key);
                const normTargetVal = normalizeForSearch(fullyDecode(value));
                for (const cb of allCheckboxes) {
                    if (cb.value === value || normalizeForSearch(fullyDecode(cb.value)) === normTargetVal) {
                        checkbox = cb;
                        break;
                    }
                }
                if (checkbox) {
                    checkbox.checked = true;
                    if (checkbox.classList.contains('js-parent-checkbox') && typeof window.syncCheckboxHierarchy === 'function') {
                        window.syncCheckboxHierarchy(checkbox);
                    }

                    let currentElement = checkbox;
                    while (currentElement) {
                        const parentDetails = currentElement.closest('details');
                        if (parentDetails) {
                            parentDetails.open = true;
                            currentElement = parentDetails.parentElement;
                        } else {
                            break;
                        }
                    }
                }
            }
        }

        // scope_skill[] / scope_trait[] がURLに明示されている場合は、含まれていない項目を外す
        ['scope_skill[]', 'scope_trait[]'].forEach(scopeKey => {
            if (urlParams.has(scopeKey)) {
                const selectedScopes = new Set(urlParams.getAll(scopeKey));
                allFormControls
                    .filter(el => el.tagName === 'INPUT' && el.type === 'checkbox' && el.name === scopeKey)
                    .forEach(cb => {
                        cb.checked = selectedScopes.has(cb.value);
                    });
            }
        });

        if (typeof window.syncAllParentCheckboxes === 'function') {
            window.syncAllParentCheckboxes(searchFormEl);
        }
        if (typeof window.syncLeaderFormUI === 'function') {
            window.syncLeaderFormUI(searchFormEl);
        }
    }

    function restoreFormFromUrl(triggerFilter = false, targetForm = null) {
        const urlParams = new URLSearchParams(window.location.search);
        const forms = targetForm
            ? [targetForm]
            : Array.from(document.querySelectorAll('.js-search-form'));

        if (forms.length === 0) {
            const fallbackForm = document.getElementById('searchform');
            if (fallbackForm) forms.push(fallbackForm);
        }

        isRestoringFromUrl = true;
        try {
            forms.forEach(formEl => restoreSingleFormFromUrl(formEl, urlParams));
        } finally {
            isRestoringFromUrl = false;
        }

        if (triggerFilter) {
            filterCharacters({ form: resolveActiveSearchForm(targetForm), immediateUrl: false, updateUrl: false });
        }
    }

    // フォームイベントはJSON取得完了前に先行登録（ロード中のネイティブGET送信・複数フォーム競合を防止）
    const allSearchForms = Array.from(document.querySelectorAll('.js-search-form'));
    if (allSearchForms.length === 0) {
        const fallbackForm = document.getElementById('searchform');
        if (fallbackForm) allSearchForms.push(fallbackForm);
    }

    allSearchForms.forEach(searchForm => {
        const markUserInteraction = () => {
            if (isRestoringFromUrl) return;
            if (!isCharactersLoaded) {
                hasUserInteractedBeforeLoad = true;
            }
            if (typeof window.setActiveSearchForm === 'function') {
                window.setActiveSearchForm(searchForm);
            }
        };

        searchForm.addEventListener('submit', function (e) {
            e.preventDefault();
            markUserInteraction();
            if (filterDebounceTimer) {
                clearTimeout(filterDebounceTimer);
                filterDebounceTimer = null;
            }
            pendingModalFilter = false;
            pendingModalForm = null;
            if (isCharactersLoaded) {
                filterCharacters({ form: searchForm, immediateUrl: true });
            }
        });

        searchForm.addEventListener('change', function (e) {
            if (isRestoringFromUrl) return;
            if (e.target.type === 'checkbox' || e.target.type === 'radio') {
                markUserInteraction();
                if (isCharactersLoaded) {
                    requestFilterCharacters(true, searchForm);
                }
            }
        });

        // フリーワード・使用可能文字・CVのみにIME制御＋デバウンスを適用（ツリー絞り込み等は除外）
        searchForm.querySelectorAll('input[name="s"], input[name="search_char"], input[name="tx_cv"]').forEach(input => {
            let lastAppliedValue = input.value;

            const syncTextInputNow = () => {
                composingInputs.delete(input);
                if (isRestoringFromUrl) return;
                markUserInteraction();
                if (!isCharactersLoaded) return;
                if (input.value !== lastAppliedValue) {
                    lastAppliedValue = input.value;
                    requestFilterCharacters(true, searchForm);
                } else {
                    updateSearchUrlImmediate(searchForm);
                }
            };

            input.addEventListener('compositionstart', () => {
                composingInputs.add(input);
            });
            input.addEventListener('compositionend', () => {
                composingInputs.delete(input);
                markUserInteraction();
                if (!isCharactersLoaded) return;
                lastAppliedValue = input.value;
                scheduleFilterCharacters(undefined, searchForm);
            });
            input.addEventListener('input', (e) => {
                if (isRestoringFromUrl) return;
                markUserInteraction();
                if (composingInputs.has(input) || e.isComposing) return;
                if (!isCharactersLoaded) return;
                lastAppliedValue = input.value;
                scheduleFilterCharacters(undefined, searchForm);
            });
            input.addEventListener('change', syncTextInputNow);
            input.addEventListener('blur', syncTextInputNow);
        });
    });

    try {
        const res = await fetch(JSON_URL, { cache: 'no-cache' });
        if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
        allCharacters = await res.json();
        prepareSearchIndex(allCharacters);

        if (!hasUserInteractedBeforeLoad) {
            restoreFormFromUrl(false);
        }

        // 初期UIの更新と最初の描画（初期ロード中の途中URL上書きを防止）
        updateSortUI();
        const initialForm = resolveActiveSearchForm();
        filterCharacters({ form: initialForm, updateUrl: false });

        // 初期ロード完了を記録し、長いURLで開かれた場合はフォーム復元完了後に1回だけ自動で短縮URLへ正規化
        isCharactersLoaded = true;
        isInitialLoad = false;
        updateSearchUrlImmediate(initialForm);
    } catch (e) {
        console.error("キャラデータの読み込みに失敗しました:", e);
        if (spinner) spinner.style.display = 'none';
        const tbody = document.getElementById('chara-list-body');
        if (tbody) tbody.innerHTML = '<tr><td colspan="100%" class="error">データの読み込みに失敗しました。</td></tr>';
    }

    // iOS Chrome 等の bfcache 復元時にフォーム状態と描画・URLを同期
    window.addEventListener('pageshow', function (event) {
        if (event.persisted && isCharactersLoaded) {
            const activeForm = resolveActiveSearchForm();
            filterCharacters({ form: activeForm, immediateUrl: true });
        }
    });

    const loadMoreBtn = document.getElementById('koto-load-more-btn');
    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', () => appendNextRenderChunk(RENDER_CHUNK_SIZE));
    }

    const showAllBtn = document.getElementById('koto-show-all-btn');
    if (showAllBtn) {
        showAllBtn.addEventListener('click', () => appendNextRenderChunk(Infinity));
    }

    // ★追加：テーブルヘッダー（ソートリンク）のクリックイベント
    document.querySelectorAll('.js-sort-link').forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault(); // ページ最上部へのジャンプ（href="#"）を防ぐ

            const key = this.dataset.sortKey;
            if (!ALLOWED_SORT_KEYS.has(key)) return;

            // 同じ項目をクリックしたら昇順・降順を切り替え
            if (currentSortKey === key) {
                currentSortOrder = currentSortOrder === 'ASC' ? 'DESC' : 'ASC';
            } else {
                // 違う項目をクリックしたらキーを変更し、文字なら昇順、数値なら降順をデフォルトにする
                currentSortKey = key;
                currentSortOrder = (key === 'name_ruby') ? 'ASC' : 'DESC';
            }

            // 矢印の見た目を更新して、現在の絞り込みリストを並び替えて再描画
            updateSortUI();
            renderTable(sortCharacters(filteredCharacters), resolveActiveSearchForm());

            // ★追加：ソート変更時もURLを更新
            updateSearchUrlImmediate(resolveActiveSearchForm());
        });
    });
});

// =========================================================
// 6. 動的な列表示切り替えロジック（テーブル1要素へのCSSクラス切替）
// =========================================================
function updateDynamicColumns(targetForm) {
    if (typeof kotoColumnConfig === 'undefined') return;
    const searchForm = resolveActiveSearchForm(targetForm);
    const table = document.querySelector('.koto-chara-table');
    if (!searchForm || !table) return;

    const activeFilter = new Set();
    const formElements = searchForm.querySelectorAll('input, select');

    formElements.forEach(el => {
        if (el.type === 'hidden') return;
        if (el.type === 'checkbox' || el.type === 'radio') {
            if (el.checked) activeFilter.add(el.name);
        }
        else if (el.type === 'number') {
            if (el.value.trim() !== '' && Number(el.value) > 0) activeFilter.add(el.name);
        }
        else if (el.type === 'text' || el.tagName === 'SELECT') {
            if (el.value.trim() !== '') activeFilter.add(el.name);
        }
    });

    if (typeof window.collectLeaderSearchConditions === 'function' && window.collectLeaderSearchConditions(searchForm).length > 0) {
        activeFilter.add('leader_search_active');
    }

    Object.values(kotoColumnConfig).forEach(config => {
        if (config.related_filters && Array.isArray(config.related_filters) && config.related_filters.length > 0 && config.class) {
            const isFilterActive = config.related_filters.some(filterName => {
                const baseName = filterName.replace(/\[\]$/, '');
                return activeFilter.has(filterName) || activeFilter.has(baseName) || activeFilter.has(baseName + '[]');
            });

            table.classList.toggle('active-' + config.class, isFilterActive);
        }
    });
}

// =========================================================
// 7. 表の中のリンクをクリックした時のクイック絞り込み機能
// =========================================================
document.addEventListener('DOMContentLoaded', function () {

    const tbody = document.getElementById('chara-list-body');
    if (!tbody) return;

    tbody.addEventListener('click', function (e) {
        const link = e.target.closest('.js-quick-filter');
        if (!link) return;

        e.preventDefault();

        const filterName = link.dataset.name;
        const filterValue = link.dataset.value;

        // ① 対象の入力フォーム（input）を安全なプロパティ比較で探す
        const searchForm = resolveActiveSearchForm();
        const allInputs = searchForm
            ? Array.from(searchForm.querySelectorAll('input'))
            : Array.from(document.querySelectorAll('input'));
        const sameNameInputs = allInputs.filter(el => el.name === filterName);
        const inputElement = sameNameInputs[0];

        if (inputElement) {
            // 同じカテゴリの別選択肢をクリア（OR検索防止）
            if (inputElement.type === 'text') {
                // テキストボックスの場合は値をクリアして設定
                inputElement.value = filterValue;
            } else {
                // チェックボックスの場合：同じnameのチェックをすべて外してから対象をチェック
                sameNameInputs.forEach(cb => {
                    cb.checked = false;
                    cb.indeterminate = false;
                });

                let checkbox = null;
                const normFilterVal = normalizeForSearch(fullyDecode(filterValue));
                for (const cb of sameNameInputs) {
                    if (cb.value === filterValue || normalizeForSearch(fullyDecode(cb.value)) === normFilterVal) {
                        checkbox = cb;
                        break;
                    }
                }
                if (checkbox) {
                    checkbox.checked = true;
                    if (typeof window.syncCheckboxHierarchy === 'function') {
                        window.syncCheckboxHierarchy(checkbox, searchForm || document);
                    }

                    // アコーディオンを開く
                    let parentDetails = checkbox.closest('details');
                    while (parentDetails) {
                        parentDetails.open = true;
                        parentDetails = parentDetails.parentElement.closest('details');
                    }
                } else if (typeof window.syncAllParentCheckboxes === 'function') {
                    window.syncAllParentCheckboxes(searchForm || document);
                }
            }

            // ③ 検索を実行して表を再描画＆即座にURL更新
            if (filterDebounceTimer) {
                clearTimeout(filterDebounceTimer);
                filterDebounceTimer = null;
            }
            if (typeof window.filterCharacters === 'function') {
                window.filterCharacters({ form: searchForm, immediateUrl: true });
            }

            // ④ スクロールして検索窓付近を表示
            const searchWrapper = document.querySelector('.search-wrapper');
            if (searchWrapper) {
                searchWrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    });
});

/**
 * デフォルト値定義
 */
const DEFAULT_PARAMS = {
    'search_char_relation': 'AND',
    'tx_axis_relation': 'OR',
    'tx_attr_relation': 'OR',
    'tx_attr_sub': 'OR',
    'tx_species_relation': 'OR',
    'tx_acq_relation': 'OR',
    'tx_group_relation': 'OR',
    'tx_event_relation': 'OR',
    'tx_gimmick_relation': 'OR',
    'tx_rarity_relation': 'OR',
    'tx_skill_tags_relation': 'OR',
    'tx_trait_tags_relation': 'OR',
    'string_match_target': 'OR',
    'include_trait_status_resistance': '',  // ★追加：デフォルト値は空（チェックなし）
    'tx_quest_relation': 'OR',
    'leader_target_relation': 'OR'
};

// scope_skillとscope_traitのデフォルト値（全チェック状態）
const DEFAULT_SCOPE_SKILL = ['waza', 'sugo', 'kotowaza'];
const DEFAULT_SCOPE_TRAIT = ['t1', 't2', 'blessing'];

/**
 * 値がデフォルトと同じかチェック
 * @param {string} key - パラメータ名
 * @param {string} value - 値
 * @param {FormData} formData - フォームデータ（配列値チェック用）
 * @returns {boolean} - デフォルトと同じならtrue
 */
function isDefaultValue(key, value, formData) {
    // 空値はデフォルトとして扱う（URLに含めない）
    const strVal = (value === null || value === undefined) ? '' : String(value);
    if (!strVal || strVal.trim() === '') return true;

    // 単一値のデフォルトチェック
    if (Object.prototype.hasOwnProperty.call(DEFAULT_PARAMS, key)) {
        return strVal === DEFAULT_PARAMS[key];
    }

    // scope_skill[] のデフォルトチェック（全チェック = デフォルト、非破壊比較）
    if (key === 'scope_skill[]') {
        const currentValues = formData.getAll('scope_skill[]').map(String).sort();
        const defaultValues = [...DEFAULT_SCOPE_SKILL].sort();
        return arraysEqual(currentValues, defaultValues);
    }

    // scope_trait[] のデフォルトチェック（全チェック = デフォルト、非破壊比較）
    if (key === 'scope_trait[]') {
        const currentValues = formData.getAll('scope_trait[]').map(String).sort();
        const defaultValues = [...DEFAULT_SCOPE_TRAIT].sort();
        return arraysEqual(currentValues, defaultValues);
    }

    return false;
}

/**
 * 配列が等しいかチェック
 */
function arraysEqual(a, b) {
    if (a.length !== b.length) return false;
    for (let i = 0; i < a.length; i++) {
        if (a[i] !== b[i]) return false;
    }
    return true;
}

/**
 * 検索フォームからURLパラメータを構築する
 * @param {HTMLFormElement|null} targetForm - 対象フォーム
 * @returns {URLSearchParams} 構築されたパラメータ
 */
function buildSearchParams(targetForm) {
    const form = resolveActiveSearchForm(targetForm);
    if (!form) return new URLSearchParams();

    if (typeof window !== 'undefined' && typeof window.buildKotoSearchParams === 'function') {
        return window.buildKotoSearchParams(form, {
            sortKey: typeof currentSortKey !== 'undefined' ? currentSortKey : 'name_ruby',
            sortOrder: typeof currentSortOrder !== 'undefined' ? currentSortOrder : 'ASC'
        });
    }

    const formData = new FormData(form);
    const params = new URLSearchParams();

    // 必須パラメータ（常に含める）
    params.append('post_type', 'character');
    const searchTerm = String(formData.get('s') || '');
    if (searchTerm.trim() !== '') {
        params.append('s', searchTerm);
    }

    // iOSトグルスイッチはDOM上のcheckbox状態を正とする（hidden disabled競合を回避）
    const toggleValueMap = new Map();
    if (typeof form.querySelectorAll === 'function') {
        form.querySelectorAll('input.ios-toggle-checkbox[name]').forEach(cb => {
            if (cb.name) {
                toggleValueMap.set(cb.name, cb.checked ? 'AND' : 'OR');
            }
        });
    }

    // その他のパラメータ（デフォルトと異なる値のみ追加）
    const processedKeys = new Set();
    const hasLeaderTargetSelection =
        formData.getAll('leader_target_attr[]').length > 0 ||
        formData.getAll('leader_target_species[]').length > 0 ||
        formData.getAll('leader_target_group[]').length > 0;

    const inactiveCondPrefixes = [];
    if (typeof form.querySelectorAll === 'function') {
        form.querySelectorAll('.js-leader-cond-item').forEach(item => {
            const condType = item.querySelector('.js-leader-cond-type-select')?.value || '';
            const idx = item.getAttribute('data-cond-index');
            if (!condType && idx !== null) {
                inactiveCondPrefixes.push(`leader_cond_${idx}_`);
            }
        });
    }

    for (const [key, rawValue] of formData.entries()) {
        // 必須パラメータ・トグルは別途処理
        if (key === 's' || key === 'post_type') continue;
        if (toggleValueMap.has(key)) continue;

        const value = (rawValue === null || rawValue === undefined) ? '' : String(rawValue);
        if (key === 'leader_target_type' && value !== 'all' && !hasLeaderTargetSelection) continue;
        if (key === 'leader_status_type[]' || key === 'leader_status_val[]' || key === 'leader_other_type[]' || key === 'leader_other_val[]') continue;
        if (inactiveCondPrefixes.some(prefix => key.startsWith(prefix))) continue;

        // 空値はスキップ
        if (value.trim() === '') continue;

        // 配列パラメータの処理（scope_skill[], scope_trait[]）
        if (key.endsWith('[]')) {
            if (processedKeys.has(key)) continue;
            processedKeys.add(key);

            if (isDefaultValue(key, value, formData)) {
                continue;
            }

            const allValues = formData.getAll(key);
            allValues.forEach(v => {
                const sv = (v === null || v === undefined) ? '' : String(v);
                if (sv.trim() !== '') params.append(key, sv);
            });
        } else {
            if (isDefaultValue(key, value, formData)) {
                continue;
            }
            params.append(key, value);
        }
    }

    toggleValueMap.forEach((toggleVal, toggleName) => {
        if (!isDefaultValue(toggleName, toggleVal, formData)) {
            params.append(toggleName, toggleVal);
        }
    });

    if (typeof form.querySelectorAll === 'function') {
        form.querySelectorAll('.js-leader-status-row').forEach(row => {
            const type = row.querySelector('select[name="leader_status_type[]"]')?.value || '';
            const val = row.querySelector('input[name="leader_status_val[]"]')?.value?.trim() || '';
            if (type !== '') {
                params.append('leader_status_type[]', type);
                params.append('leader_status_val[]', val);
            }
        });

        form.querySelectorAll('.js-leader-other-row').forEach(row => {
            const type = row.querySelector('select[name="leader_other_type[]"]')?.value || '';
            const val = row.querySelector('input[name="leader_other_val[]"]')?.value?.trim() || '';
            if (type !== '') {
                params.append('leader_other_type[]', type);
                params.append('leader_other_val[]', val);
            }
        });
    }

    // ソート状態を追加
    if (typeof currentSortKey !== 'undefined' && currentSortKey !== 'name_ruby') {
        params.append('sort_key', currentSortKey);
        params.append('sort_order', currentSortOrder);
    }

    return params;
}

/**
 * URLを即座に更新（同期実行、iOS Chrome / WKWebView 対策済み）
 * @param {HTMLFormElement|null} targetForm - 対象フォーム
 */
function updateSearchUrlImmediate(targetForm) {
    // 初期ロード復元途中でのURL上書きを防止
    if (isInitialLoad) {
        return;
    }

    // 検索結果ページでなければ何もしない
    if (!isSearchResultPage()) return;

    if (urlUpdateDebounceTimer) {
        clearTimeout(urlUpdateDebounceTimer);
        urlUpdateDebounceTimer = null;
    }

    const params = buildSearchParams(targetForm);
    const queryString = params.toString();
    const nextRelative = window.location.pathname + (queryString ? '?' + queryString : '');

    let nextUrl = nextRelative;
    if (typeof URL === 'function' && window.location && window.location.origin) {
        try {
            nextUrl = new URL(nextRelative, window.location.origin).href;
        } catch (err) {
            nextUrl = nextRelative;
        }
    }

    // 現在URLと同一であれば不要なreplaceStateをスキップ（iOS WebKitのレート制限対策）
    const currentHref = window.location && window.location.href ? window.location.href : (window.location.pathname + (window.location.search || ''));
    if (currentHref === nextUrl) {
        return;
    }

    try {
        window.history.replaceState({ kotoSearch: true }, '', nextUrl);
    } catch (err) {
        console.warn('URLの更新に失敗しました:', err);
    }
}

/**
 * URLをデバウンス付きで更新（入力中の連続更新を防止）
 * @param {HTMLFormElement|null} targetForm - 対象フォーム
 */
function updateSearchUrl(targetForm) {
    if (isInitialLoad) {
        return;
    }

    // 既存のタイマーをクリア
    if (urlUpdateDebounceTimer) {
        clearTimeout(urlUpdateDebounceTimer);
    }

    // 100ms後にURL更新（連続入力時の負荷軽減）
    urlUpdateDebounceTimer = setTimeout(() => {
        urlUpdateDebounceTimer = null;
        updateSearchUrlImmediate(targetForm);
    }, 100);
}

const SEARCH_PATH = '/'; // 検索結果ページのパス（ルート）

/**
 * 検索結果ページかどうか判定
 * @returns {boolean}
 */
function isSearchResultPage() {
    const params = new URLSearchParams(window.location.search);
    return (window.location.pathname === SEARCH_PATH && params.get('post_type') === 'character') ||
        document.getElementById('chara-list-body') !== null;
}

/**
 * 統合検索実行関数（GA4送信専用 + 他ページからの遷移）
 * @param {boolean} shouldSendGA4 - trueの場合のみGA4イベントを送信
 * @param {HTMLFormElement|null} targetForm - 対象フォーム
 */
function executeSearch(shouldSendGA4 = false, targetForm = null) {
    const form = resolveActiveSearchForm(targetForm);
    if (!form) {
        return;
    }

    if (typeof window.setActiveSearchForm === 'function') {
        window.setActiveSearchForm(form);
    }

    const formData = new FormData(form);

    // 検索結果ページかどうか判定
    const isSearchPage = isSearchResultPage();

    if (isSearchPage) {
        // モーダル内で保留された検索変更があれば閉じたタイミングで同期反映する
        const flushed = flushPendingModalFilter(form);
        if (!flushed && shouldSendGA4) {
            filterCharacters({ form, immediateUrl: true });
        } else {
            // モーダル内の変更がURLへ確実に同期されるよう念のためガード付き即時更新
            updateSearchUrlImmediate(form);
        }
        if (shouldSendGA4) {
            sendGA4SearchEvent(formData);
        }
    } else {
        // 他ページ：検索結果ページへ遷移（短縮クエリ付き）
        const params = buildSearchParams(form);
        const queryString = params.toString();
        const targetUrl = SEARCH_PATH + (queryString ? '?' + queryString : '');
        window.location.href = targetUrl;
    }
}

/**
 * GA4検索イベント送信（searchChar・moji_search対応版）
 */
function sendGA4SearchEvent(formData) {
    if (typeof gtag !== 'function') return;
    if (window.location.hostname !== 'www.kotodaman-db.com') return;

    const gaData = {
        event_category: 'search',
        event_label: 'advanced_search',
        search_term: formData.get('s') || '',
        search_char: formData.get('search_char') || '', // ★追加：使用可能文字検索
        custom_parameters: {}
    };

    // フィルタ値を収集
    const filters = {
        attr: [],
        species: [],
        group: [],
        gimmick: [],
        event: [],
        rarity: [],
        axis: []
    };

    for (const [key, value] of formData.entries()) {
        if (key === 'tx_attr[]') filters.attr.push(value);
        else if (key === 'tx_species[]') filters.species.push(value);
        else if (key === 'tx_group[]') filters.group.push(value);
        else if (key === 'tx_gimmick[]') filters.gimmick.push(value);
        else if (key === 'tx_event[]') filters.event.push(value);
        else if (key === 'tx_rarity[]') filters.rarity.push(value);
        else if (key === 'tx_axis[]') filters.axis.push(value);
    }

    // フィルタ情報を追加
    Object.keys(filters).forEach(key => {
        if (filters[key].length > 0) {
            gaData.custom_parameters[`filter_${key}`] = filters[key].join(',');
        }
    });

    // 検索条件がある場合のみ送信
    const hasSearch = gaData.search_term ||
        gaData.search_char ||
        Object.keys(gaData.custom_parameters).length > 0;

    if (hasSearch) {
        gtag('event', 'character_search', gaData);
    }

    // ★追加：moji_searchイベント - 各文字を個別に送信
    const searchChar = formData.get('search_char') || '';
    if (searchChar) {
        // 文字を個別に分解し、重複を除去
        const uniqueChars = [...new Set(searchChar.split(''))];

        uniqueChars.forEach(char => {
            // 空白文字は除外
            if (char.trim()) {
                gtag('event', 'moji_search', {
                    event_category: 'search',
                    event_label: 'character',
                    search_char: char
                });
            }
        });
    }
}

// グローバル公開
window.executeSearch = executeSearch;
window.isSearchResultPage = isSearchResultPage;
window.KotoSearchCore = {
    normalizeForSearch,
    normalizeRawForSearch,
    fullyDecode,
    escapeHtml,
    sanitizeThumbUrl,
    escapeCssAttrValue,
    prepareSearchIndex,
    filterCharacterList,
    sortCharacters,
    buildRenderItems,
    createCharacterRowHtml,
    formatRarityLabel,
    formatLeaderSummaryHtml,
    matchLeaderCharacter,
    matchLeaderEffect,
    matchLeaderTarget,
    ALLOWED_SORT_KEYS,
    DEFAULT_PARAMS,
    isDefaultValue,
    buildSearchParams,
    resolveActiveSearchForm,
    updateSearchUrlImmediate
};
