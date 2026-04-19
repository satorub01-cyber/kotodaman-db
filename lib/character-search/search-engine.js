// =========================================================
// コトダマンDB フロントエンド検索エンジン
// =========================================================

const JSON_URL = ((window.KOTO_SEARCH_JSON_URL || '/wp-content/themes/cocoon-child-master/lib/character-search/all_characters_search.json') + '?v=' + new Date().getTime());
const IS_LOCAL_HOST = ['localhost', '127.0.0.1'].includes(window.location.hostname);
const getSearchIcon = (filename) => IS_LOCAL_HOST ? '' : `/wp-content/uploads/2025/12/${filename}`;
let allCharacters = [];
let filteredCharacters = []; // ★追加：現在の絞り込み結果を保持する配列
// TODO詳細検索モーダルが閉じたら検索ボタンを押す。検索ボタンを押すと、検索結果ページ以外にいるときは遷移処理をし、検索結果ページにいるときはURLを更新してGA4にキーイベントを送信する
// ★追加：ソート（並び替え）用の状態変数
let currentSortKey = 'name_ruby';    // デフォルトのソートキー（実装日）
let currentSortOrder = 'ASC';  // デフォルトの順序（降順）

// ヘルパー関数群
function formatNumber(num) {
    if (!num || num === 0 || num === "0") return '<span class="text-muted">-</span>';
    return Number(num).toLocaleString();
}

function getBuffDispHTML(buffArray) {
    if (!buffArray || buffArray.length < 6) return '<span class="text-muted">0</span>';
    const min = buffArray;
    const max = buffArray;
    if (max === 0) return '<span class="text-muted">0</span>';
    if (min === max) return `<span class="bd-val">${max}</span>`;
    return `<span class="bd-val">${min}➡${max}</span>`;
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
    // TODO虹属性アイコンでき次第追記
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

// 1キャラ分の行(<tr>)生成関数
function createCharacterRowHtml(char) {
    const link = `/character/${char.id}`;
//   ^(?:
//   (?![^・]*[\(（])            # 最初の・より前に ( または （ があればこの枝は失敗
//   (?!(?:[\u30A0-\u30FF]+)・)   # 全部カタカナならこの枝は失敗
//   (?:[^・]+)・(.+)            # 最初の・の後ろを全部キャプチャ
//   |
//   (.+)                        # 先読みマッチがなければ全文
//   )$
    const re = /^(?:(?![^・]*[\(（])(?!(?:[\u30A0-\u30FF]+)・)(?:[^・]+)・(.+)|(.+))$/u;
    const match = char.name.match(re);
    const dispName = match ? (match[1] || match[2]) : char.name; // 最初の・の後ろを優先、なければ全体

    const thumbHtml = char.thumb_url
        ? `<img src="${char.thumb_url}" class="chara-thumb" alt="${char.name}">`
        : `<div class="no-img"></div>`;

    let charsHtml = '';
    if (char.chars && char.chars.length > 0) {
        charsHtml = char.chars.map(c => {
            const attrSlug = ATTR_MAP[c.attr] ? ATTR_MAP[c.attr].slug : 'none';
            let suffix = '';
            if (c.unlock === 'scopy') suffix = '<span class="char-suffix">(Sコ)</span>';
            if (c.unlock === 'schange') suffix = '<span class="char-suffix">(Sチ)</span>';
            if (c.unlock === 'sboth') suffix = '<span class="char-suffix">(Sコ・Sチ)</span>';

            // ★ポイント：元の 'char-link-item' と新しい 'js-quick-filter' を両方つける！
            return `<a href="#" class="char-link-item js-quick-filter" data-name="search_char" data-value="${c.val}"><span class="char-font attr-${attrSlug}">${c.val}</span>${suffix}</a>`;
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

    const speciesData = SPECIES_MAP[char.spe] || { slug: 'none', name: '不明' };
    // ▼▼▼ 種族を修正 ▼▼▼
    let speciesHtml = speciesData.icon
        ? `<a href="#" class="js-quick-filter" data-name="tx_species[]" data-value="${speciesData.slug}"><img src="${speciesData.icon}" class="species-icon-img" alt="${speciesData.name}"></a>`
        : `<a href="#" class="js-quick-filter" data-name="tx_species[]" data-value="${speciesData.slug}"><span class="attr-text attr-${speciesData.slug}">${speciesData.name}</span></a>`;

    let gimmickHtml = '';
    const gimmickEnValues = Array.isArray(char.gimmick_en) ? char.gimmick_en : [];
    const gimmickJpValues = Array.isArray(char.gimmick_jp) ? char.gimmick_jp : [];
    if (gimmickJpValues.length > 0) {
        gimmickHtml = gimmickJpValues.map((name, index) => {
            const slug = gimmickEnValues[index] || '';
            return `<a href="#" class="js-quick-filter" data-name="tx_gimmick[]" data-value="${slug}"><span class="badge-gimmick">${name}</span></a>`;
        }).join('');
    }

    const valHp99 = formatNumber(char.hp99);
    const valAtk99 = formatNumber(char.atk99);
    const valHp120 = formatNumber(char.hp120);
    const valAtk120 = formatNumber(char.atk120);
    const lsHp = char.ls_hp && char.ls_hp != "0" ? `${char.ls_hp}%` : '<span class="text-muted">-</span>';
    const lsAtk = char.ls_atk && char.ls_atk != "0" ? `${char.ls_atk}%` : '<span class="text-muted">-</span>';

    const buffBoard = getBuffDispHTML(char.bd_buff);
    const buffHand = getBuffDispHTML(char.hnd_buff);
    const debuff = getBuffDispHTML(char.debuf);

    // ▼▼▼ グループを修正 ▼▼▼
    let groupHtml = '<span class="text-muted">-</span>';
    const groupEnValues = Array.isArray(char.group_en) ? char.group_en : [];
    const groupJpValues = Array.isArray(char.group_jp) ? char.group_jp : [];
    if (groupEnValues.length > 0) {
        groupHtml = groupEnValues.map((slug, index) => {
            let decoded = slug;
            try { decoded = decodeURIComponent(slug); } catch (e) { }
            const label = groupJpValues[index] || KOTO_GROUP_MAP[decoded] || decoded;
            return `<a href="#" class="js-quick-filter" data-name="tx_group[]" data-value="${slug}">${label}</a>`;
        }).join('<br>');
    }

    // ▼▼▼ イベントを修正 ▼▼▼
    let eventHtml = '<span class="text-muted">-</span>';
    if (char.events && char.events.length > 0) {
        eventHtml = char.events.map(slug => {
            let decoded = slug;
            try { decoded = decodeURIComponent(slug); } catch (e) { }
            // <a>タグで囲む
            return `<a href="#" class="js-quick-filter" data-name="tx_event[]" data-value="${slug}">${KOTO_EVENT_MAP[decoded] || decoded}</a>`;
        }).join('<br>');
    }

    return `
    <tr data-id="${char.id}">
        <td class="td-icon col-icon"><a href="${link}" target="_blank" rel="noopener noreferrer">${thumbHtml}</a></td>
        <td class="td-id col-id">${char.id}</td>
        <td class="td-name col-name"><a href="${link}" class="chara-link" target="_blank" rel="noopener noreferrer">${dispName}</a><div class="name-ruby" style="display:none;">${char.name_ruby}</div></td>

        <td class="td-moji col-moji"><div class="char-list">${charsHtml}</div></td>
        <td class="td-attr col-attr"><div class="attr-box-row">${attrHtml}</div></td>
        <td class="td-species col-species">${speciesHtml}</td>
        <td class="td-group col-group" style="font-size: 11px;">${groupHtml}</td>
        <td class="td-event col-event" style="font-size: 11px;">${eventHtml}</td>

        <td class="td-stat hp-val col-hp99">${valHp99}</td>
        <td class="td-stat atk-val col-atk99">${valAtk99}</td>
        <td class="td-stat hp-val-120 col-hp120">${valHp120}</td>
        <td class="td-stat atk-val-120 col-atk120">${valAtk120}</td>

        <td class="td-stat ls-val col-ls-hp">${lsHp}</td>
        <td class="td-stat ls-val col-ls-atk">${lsAtk}</td>

        <td class="td-buff buff-cell col-buff-board">${buffBoard}</td>
        <td class="td-buff buff-cell col-buff-hand">${buffHand}</td>
        <td class="td-debuff debuff-cell col-debuff">${debuff}</td>

        <td class="td-gimmick col-gimmick"><div class="gimmick-list">${gimmickHtml}</div></td>
        <td class="td-cv col-cv"><a href="#" class="js-quick-filter" data-name="tx_cv" data-value="${char.cv || '-'}">${char.cv || '-'}</a></td>
        <td class="td-acq col-acq">${char.acq || '-'}</td>
        <td class="td-date col-date">${char.date || '-'}</td>
        <td class="td-power col-power">${formatNumber(char.power)}</td>
    </tr>
    `;
}

// =========================================================
// フィルタリング・ソート・描画処理
// =========================================================

// ヘルパー関数：チェックされた値を配列で取得
const getCheckedValues = (selector) => Array.from(document.querySelectorAll(selector)).map(cb => cb.value);
// ヘルパー関数：ラジオボタンの値を取得（トグルスイッチのフォールバック対応版）
const getRadioValue = (name, defaultVal) => {
    const checked = document.querySelector(`input[name="${name}"]:checked`);
    return checked ? checked.value : defaultVal;
};

const safeIncludes = (array, searchElement) => {
    if (!array || !Array.isArray(array)) return false;
    let decodedSearch = searchElement;
    try { decodedSearch = decodeURIComponent(searchElement); } catch (e) { }

    return array.some(item => {
        let decodedItem = item;
        try { decodedItem = decodeURIComponent(item); } catch (e) { }
        return decodedItem === decodedSearch;
    });
};

const normalizeTraitTagValue = (value) => {
    const normalizedValue = String(value || '').trim();
    if (!normalizedValue) return '';
    if (normalizedValue === 'give_trait') return normalizedValue;
    return normalizedValue.replace(/^trait_/, '');
};

// 汎用的なAND/ORトグルを含む判定関数
function checkRelationCondition(checkedValues, charValues, relation) {
    if (checkedValues.length === 0) return true;

    // データ未定義時のエラーを防止
    const safeCharValues = Array.isArray(charValues) ? charValues : (charValues ? [charValues] : []);
    const safeCheckedValues = Array.isArray(checkedValues) ? checkedValues : [checkedValues];

    if (relation === 'AND') {
        return checkedValues.every(val => safeIncludes(safeCharValues, val));
    } else {
        return checkedValues.some(val => safeIncludes(safeCharValues, val));
    }
}

// =========================================================
// ▼▼▼ リーダー検索第二段階：対象+補正の簡易検索 ▼▼▼
// =========================================================

/**
 * リーダー効果が検索条件にマッチするか判定
 * @param {Object} ldrEff - char.leader[] の1要素
 * @param {Object} condition - collectLeaderSearchConditions() の結果
 * @returns {boolean}
 */
function matchLeaderEffect(ldrEff, condition) {
    // BUFF_TYPE_MAPから照合方法を取得
    const map = window.LEADER_CONSTANTS?.BUFF_TYPE_MAP?.[condition.raw.type];

    // typeが指定されている場合は効果タイプの照合を行う
    if (condition.raw.type && map) {
        // === ケース1: valsキーで照合（hp, atk, crit_rateなど）===
        if (map.matchBy === 'vals_key') {
            console.log('[Type Check] vals_key type - ldrEff.ty:', ldrEff.ty, 'required: fixed/per_unit');
            // ldrEff.ty が 'fixed' または 'per_unit' であることを確認
            if (ldrEff.ty !== 'fixed' && ldrEff.ty !== 'per_unit') {
                console.log('[Type Check] FAILED - ty mismatch');
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
                    const statusKeys = ['poison', 'sleep', 'curse', 'confusion', 'pollution', 'burn', 'remodel', 'weakness', 'mutation', 'erasure'];
                    for (const key of statusKeys) {
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
                console.log('[Type Check] FAILED - no vals or statValue, looking for:', map.stat);
                return false;
            }
            console.log('[Type Check] PASSED - vals_key match');

            // 補正値の条件があれば照合（検索値以上の補正を持つキャラを表示）
            if (condition.buffValue > 0 && statValue < condition.buffValue) {
                return false;
            }
        }
        // === ケース2: tyで照合（exp_up, corruptionなど）===
        else {
            console.log('[Type Check] ty match type - ldrEff.ty:', ldrEff.ty, 'required:', map.ty);
            if (ldrEff.ty !== map.ty) {
                console.log('[Type Check] FAILED - ty mismatch');
                return false;
            }
            console.log('[Type Check] PASSED - ty match');
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
    // console.log('[Leader Debug] matchLeaderTarget - tgts:', tgts, 'searchTarget:', searchTarget);

    if (!tgts || tgts.length === 0) {
        // console.log('[Leader Debug] tgts is empty');
        return false;
    }

    // 検索条件が"all"の場合、tgtsに"all"が含まれていればOK
    if (searchTarget.type === 'all') {
        const hasAll = tgts.some(t => t.ty === 'all');
        // console.log('[Leader Debug] searchTarget.type is all, result:', hasAll);
        return hasAll;
    }

    // 検索条件と一致するtgtを探す
    const result = tgts.some(tgt => {
        // console.log('[Leader Debug] checking tgt:', tgt, 'against searchTarget.type:', searchTarget.type);

        // タイプが一致しない場合はスキップ
        if (tgt.ty !== searchTarget.type) {
            // console.log('[Leader Debug] tgt.ty mismatch:', tgt.ty, '!==', searchTarget.type);
            return false;
        }

        // slgs（スラッグ/ID配列）の照合
        if (!tgt.slgs || tgt.slgs.length === 0) {
            // console.log('[Leader Debug] tgt.slgs is empty');
            return false;
        }

        // console.log('[Leader Debug] checking slugs - searchTarget.slugs:', searchTarget.slugs, 'tgt.slgs:', tgt.slgs);

        // searchTarget.slugs が tgt.slgs の一部と一致すればOK（OR条件）
        const match = searchTarget.slugs.some(slug => tgt.slgs.includes(slug));
        // console.log('[Leader Debug] slug match result:', match);
        return match;
    });

    // console.log('[Leader Debug] matchLeaderTarget final result:', result);
    return result;
}

// 検索ロジックの本体
function filterCharacters() {
    // トグルスイッチ（チェックボックス）のAND/OR判定を取得するヘルパー
    const getRelationValue = (name, defaultVal) => {
        const checkbox = document.querySelector(`input[type="checkbox"][name="${name}"]`);
        if (checkbox) return checkbox.checked ? 'AND' : 'OR';

        // トグルがない場合は従来のラジオボタンを探すフォールバック
        const radio = document.querySelector(`input[type="radio"][name="${name}"]:checked`);
        return radio ? radio.value : defaultVal;
    };
    const keyword = document.getElementById('s') ? document.getElementById('s').value.toLowerCase().trim() : '';
    const keywordTerms = keyword ? keyword.split(/\s+/u).filter(Boolean) : [];
    const includeTraitStatusResistance = Boolean(document.querySelector('input[name="include_trait_status_resistance"]:checked'));
    const searchChar = document.querySelector('input[name="search_char"]') ? document.querySelector('input[name="search_char"]').value.trim() : '';
    const searchCv = document.querySelector('input[name="tx_cv"]') ? document.querySelector('input[name="tx_cv"]').value.trim() : '';
    const checkedAxises = getCheckedValues('input[name="tx_axis[]"]:checked');
    const checkedAttrs = getCheckedValues('input[name="tx_attr[]"]:checked');
    const checkedSpecies = getCheckedValues('input[name="tx_species[]"]:checked');
    const checkedRarities = getCheckedValues('input[name="tx_rarity[]"]:checked');
    const checkedGroups = getCheckedValues('input[name="tx_group[]"]:checked');
    const checkedEvents = getCheckedValues('input[name="tx_event[]"]:checked');
    const checkedGimmicks = getCheckedValues('input[name="tx_gimmick[]"]:checked');
    const checkedPriorities = getCheckedValues('input[name="tx_priority[]"]:checked');
    const checkedAcqs = getCheckedValues('input[name="tx_acq[]"]:checked');
    const checkedSkillTags = getCheckedValues('input[name="tx_skill_tags[]"]:checked');
    const checkedSkillScopes = getCheckedValues('input[name="scope_skill[]"]:checked');
    const checkedTraitTags = getCheckedValues('input[name="tx_trait_tags[]"]:checked');
    const checkedTraitScopes = getCheckedValues('input[name="scope_trait[]"]:checked');

    // AND / OR の判定 (getRelationValueに変更)
    const relAxis = getRelationValue('tx_axis_relation', 'OR');
    const relGroup = getRelationValue('tx_group_relation', 'OR');
    const relEvent = getRelationValue('tx_event_relation', 'OR');
    const relGimmick = getRelationValue('tx_gimmick_relation', 'AND');
    const relAcq = getRelationValue('tx_acq_relation', 'OR');
    const relAttr = getRelationValue('tx_attr_relation', 'OR');
    const subAttrToggle = getRelationValue('tx_attr_sub', 'OR'); // 副属性の「含む/含まない」トグル
    const relSpecies = getRelationValue('tx_species_relation', 'OR');
    const relRarity = getRelationValue('tx_rarity_relation', 'OR');
    const relSkillTags = getRelationValue('tx_skill_tags_relation', 'OR');
    const relTraitTags = getRelationValue('tx_trait_tags_relation', 'OR');
    const relChar = getRelationValue('search_char_relation', 'OR'); // 使用可能文字のAND/ORトグル

    // ★追加：AND検索フラグをグローバルに設定
    window._isCharAndSearch = (searchChar && relChar === 'AND');
    console.log('[DEBUG] _isCharAndSearch:', window._isCharAndSearch, 'searchChar:', searchChar, 'relChar:', relChar);

    // ★追加：リーダー検索条件を収集
    const leaderConditions = window.collectLeaderSearchConditions ? window.collectLeaderSearchConditions() : [];
    const hasLeaderSearch = leaderConditions.length > 0;

    // ★変更：結果を filteredCharacters に代入する
    filteredCharacters = allCharacters.filter(char => {
        // --- 属性フィルター ---
        if (checkedAttrs.length > 0) {
            let charMainAttrSlug = ATTR_MAP[char.attr] ? ATTR_MAP[char.attr].slug : '';
            let charSubAttrSlugs = (char.sub_attrs || []).map(a => ATTR_MAP[a] ? ATTR_MAP[a].slug : '');

            if (subAttrToggle === 'OR') { // 「含む」: メイン属性またはサブ属性のいずれかが一致すればOK
                let combinedCharAttrs = [charMainAttrSlug, ...charSubAttrSlugs];
                if (!checkRelationCondition(checkedAttrs, combinedCharAttrs, relAttr)) return false;
            } else { // 「含まない」: メイン属性が一致し、かつサブ属性には一致するものが含まれないこと
                if (!checkRelationCondition(checkedAttrs, [charMainAttrSlug], relAttr)) return false; // メイン属性のチェック
            }
        }
        if (keywordTerms.length > 0) {
            const keywordTargets = [
                char.name,
                char.name_ruby,
                ...(Array.isArray(char.group_jp) ? char.group_jp : []),
                ...(Array.isArray(char.gimmick_jp) ? char.gimmick_jp : []),
                ...(Array.isArray(char.leader_status_resistance_jp) ? char.leader_status_resistance_jp : []),
                ...(includeTraitStatusResistance && Array.isArray(char.trait_status_resistance_jp) ? char.trait_status_resistance_jp : []),
            ];

            const hasKeywordMatch = keywordTerms.every(term =>
                keywordTargets.some(value => String(value || '').toLowerCase().includes(term))
            );

            if (!hasKeywordMatch) return false;
        }
        if (searchCv) {
            if (!char.cv || !char.cv.includes(searchCv)) return false;
        }
        if (searchChar) {
            const targetChars = Array.from(new Set(searchChar)).filter(c => c.trim() !== '' && c !== ',' && c !== '、');
            if (targetChars.length > 0) {
                const charVals = char.chars ? char.chars.map(c => c.val) : [];
                // マッチした文字数をカウント
                const matchedCount = targetChars.filter(tc => charVals.includes(tc)).length;
                char._charMatchCount = matchedCount; // マッチ数を保存
                if (matchedCount === 0) return false; // 0文字は除外
            }
        }
        if (!checkRelationCondition(checkedSpecies, SPECIES_MAP[char.spe] ? SPECIES_MAP[char.spe].slug : '', relSpecies)) return false;
        if (!checkRelationCondition(checkedRarities, char.rar_t, relRarity)) return false;
        if (!checkRelationCondition(checkedGroups, char.group_en, relGroup)) return false;
        if (!checkRelationCondition(checkedEvents, char.events, relEvent)) return false;
        if (!checkRelationCondition(checkedGimmicks, char.gimmick_en, relGimmick)) return false;
        if (!checkRelationCondition(checkedAcqs, char.acq, relAcq)) return false; // 入手方法のフィルターを追加
        if (!checkRelationCondition(checkedAxises, char.axis, relAxis)) return false;

        if (checkedPriorities.length > 0) {
            // 数値(int)を文字列(String)に変換して比較する
            const charPriorityStr = char.pri ? String(char.pri) : '';
            // チェックされた配列の中に、キャラの行動順が含まれていなければ弾く
            if (!checkedPriorities.includes(charPriorityStr)) return false;
        }

        const relSkillTags = getRelationValue('tx_skill_tags_relation', 'OR');
        const relTraitTags = getRelationValue('tx_trait_tags_relation', 'OR');

        if (checkedSkillTags.length > 0) {
            if (checkedSkillScopes.length === 0) return false;

            // 指定されたタグが選択されたわざの範囲に含まれているか判定
            const checkSkillMatch = tag => {
                const s = ` ${tag} `;
                return (checkedSkillScopes.includes('waza') && char.waza_t && char.waza_t.includes(s)) ||
                    (checkedSkillScopes.includes('sugo') && char.sugo_t && char.sugo_t.includes(s)) ||
                    (checkedSkillScopes.includes('kotowaza') && char.koto_t && char.koto_t.includes(s));
            };

            // 検索条件(AND/OR)に応じて配列の評価メソッドを切り替え
            const hasMatch = relSkillTags === 'AND'
                ? checkedSkillTags.every(checkSkillMatch)
                : checkedSkillTags.some(checkSkillMatch);

            if (!hasMatch) return false;
        }

        if (checkedTraitTags.length > 0) {
            if (checkedTraitScopes.length === 0) return false;

            // 指定されたタグが選択された特性の範囲に含まれているか判定
            const checkTraitMatch = tag => {
                const normalizedTag = normalizeTraitTagValue(tag);
                if (!normalizedTag) return false;

                return (checkedTraitScopes.includes('t1') && safeIncludes(char.trait1_en, normalizedTag)) ||
                    (checkedTraitScopes.includes('t2') && safeIncludes(char.trait2_en, normalizedTag)) ||
                    (checkedTraitScopes.includes('blessing') && safeIncludes(char.blessing_en, normalizedTag));
            };

            // 検索条件(AND/OR)に応じて配列の評価メソッドを切り替え
            const hasMatch = relTraitTags === 'AND'
                ? checkedTraitTags.every(checkTraitMatch)
                : checkedTraitTags.some(checkTraitMatch);

            if (!hasMatch) return false;
        }

        // ★追加：リーダー検索
        if (hasLeaderSearch) {
            if (!char.leader || char.leader.length === 0) return false;

            // 複数条件のいずれかに一致すればOK（OR条件）
            const matchAnyCondition = leaderConditions.some(condition => {
                // キャラの複数リーダー効果のいずれかに一致すればOK
                return char.leader.some(ldrEff => matchLeaderEffect(ldrEff, condition));
            });

            if (!matchAnyCondition) return false;
        }

        return true;
    });

    // ★変更：ソートを実行してから描画する
    renderTable(sortCharacters(filteredCharacters));
}

// ★追加：特殊なキー（バフ・デバフの配列など）から比較用の数値を安全に取り出すヘルパー
function getSortValue(char, key) {
    switch (key) {
        case 'buff_board': return char.bd_buff && char.bd_buff.length > 5 ? Number(char.bd_buff) : 0;
        case 'buff_hand': return char.hnd_buff && char.hnd_buff.length > 5 ? Number(char.hnd_buff) : 0;
        case 'debuff': return char.debuf && char.debuf.length > 5 ? Number(char.debuf) : 0;
        default: return char[key]; // それ以外はそのまま返す
    }
}

// ★変更：配列を並び替える関数（第2ソート対応版）
function sortCharacters(chars) {
    return chars.sort((a, b) => {
        // ★第0ソート：マッチ文字数（降順）- AND検索時のみ
        if (window._isCharAndSearch && a._charMatchCount !== b._charMatchCount) {
            return b._charMatchCount - a._charMatchCount;
        }
        
        // 1. 第1ソートキーでの比較
        let valA = getSortValue(a, currentSortKey);
        let valB = getSortValue(b, currentSortKey);

        if (valA === undefined || valA === null) valA = '';
        if (valB === undefined || valB === null) valB = '';

        let diff = 0;

        // 両方数値の場合は引き算で比較
        if (typeof valA === 'number' && typeof valB === 'number') {
            diff = currentSortOrder === 'ASC' ? valA - valB : valB - valA;
        } else {
            // それ以外（文字列）は日本語ロケールで比較
            valA = String(valA);
            valB = String(valB);
            diff = valA.localeCompare(valB, 'ja');
            if (currentSortOrder === 'DESC') {
                diff = -diff;
            }
        }

        // 2. ★第1ソートで全く同じ値（diff === 0）だった場合の第2ソート処理
        if (diff === 0) {
            if (currentSortKey === 'name_ruby') {
                // 【A】メインが名前順の場合 ➡ 第2ソートは「実装日（新しい順）」
                let dateA = String(a.date || '');
                let dateB = String(b.date || '');
                if (dateA < dateB) return 1;  // 降順
                if (dateA > dateB) return -1;
                return 0;
            } else {
                // 【B】HPやATKなど、それ以外の並び替え時 ➡ 第2ソートは「名前順（あいうえお順）」
                let nameA = String(a.name_ruby || '');
                let nameB = String(b.name_ruby || '');
                return nameA.localeCompare(nameB, 'ja'); // 昇順
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

function renderTable(chars) {
    const tbody = document.getElementById('chara-list-body');
    const spinner = document.getElementById('loading-spinner');
    
    // ★追加：該当件数の表示を更新
    // ※HTML側の要素に合わせてセレクタ（.hit-count, #hit-count など）を調整してください
    const hitCountElements = document.querySelectorAll('#hit-count-num');
    hitCountElements.forEach(el => {
        el.textContent = chars.length;
    });

    console.log('[DEBUG renderTable] chars.length:', chars.length, '_isCharAndSearch:', window._isCharAndSearch);
    if (chars.length > 0) {
        console.log('[DEBUG renderTable] first char _charMatchCount:', chars[0]._charMatchCount);
    }

    if (chars.length === 0) {
        tbody.innerHTML = '<tr><td colspan="100%" class="no-data" style="text-align:center; padding:30px;">条件に一致するキャラクターが見つかりませんでした。</td></tr>';
    } else if (window._isCharAndSearch) {
        // ★AND検索時：セクション区切り行を挿入して表示
        const uniqueCounts = [...new Set(chars.map(c => c._charMatchCount || 0))].sort((a, b) => b - a);
        console.log('[DEBUG renderTable] uniqueCounts:', uniqueCounts);
        
        let html = '';
        uniqueCounts.forEach(count => {
            const sectionChars = chars.filter(c => c._charMatchCount === count);
            // セクションヘッダー行（tr）として追加
            html += `<tr class="section-header" style="background-color: #e3f2fd;">
                <td colspan="100%" style="padding: 10px; font-weight: bold; color: #1976d2; border-top: 2px solid #1976d2;">
                    ${count}文字含むキャラクター (${sectionChars.length}件)
                </td>
            </tr>`;
            // セクション内のキャラクター
            html += sectionChars.map(createCharacterRowHtml).join('');
        });
        
        tbody.innerHTML = html;
    } else {
        // OR検索時は従来通り
        tbody.innerHTML = chars.map(createCharacterRowHtml).join('');
    }

    if (spinner) spinner.style.display = 'none';

    if (typeof window.applyCurrentColumnSettings === 'function') {
        window.applyCurrentColumnSettings();
    }
}

window.filterCharacters = filterCharacters;

// =========================================================
// 5. 初期化とイベント登録
// =========================================================
document.addEventListener('DOMContentLoaded', async function () {
    const spinner = document.getElementById('loading-spinner');
    if (spinner) spinner.style.display = 'block';

    try {
        const res = await fetch(JSON_URL);
        if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
        allCharacters = await res.json();

        // 初期UIの更新と最初の描画
        updateSortUI();
        filterCharacters();
    } catch (e) {
        console.error("キャラデータの読み込みに失敗しました:", e);
        const tbody = document.getElementById('chara-list-body');
        if (tbody) tbody.innerHTML = '<tr><td colspan="100%" class="error" style="color:red; text-align:center;">データの読み込みに失敗しました。</td></tr>';
    }

    const searchForm = document.getElementById('searchform');
    if (searchForm) {
        searchForm.addEventListener('submit', function (e) {
            e.preventDefault();
            filterCharacters();
        });

        searchForm.addEventListener('change', function (e) {
            if (e.target.type === 'checkbox' || e.target.type === 'radio') filterCharacters();
        });

        searchForm.querySelectorAll('input[type="text"]').forEach(input => {
            input.addEventListener('input', () => filterCharacters());
        });
    }

    // ★追加：テーブルヘッダー（ソートリンク）のクリックイベント
    document.querySelectorAll('.js-sort-link').forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault(); // ページ最上部へのジャンプ（href="#"）を防ぐ

            const key = this.dataset.sortKey;

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
            renderTable(sortCharacters(filteredCharacters));
        });
    });
});

// =========================================================
// 6. 動的な列表示切り替えロジック
// =========================================================
function updateDynamicColumns() {
    if (typeof kotoColumnConfig === 'undefined') return;

    const activeFilter = new Set();
    const formElements = document.getElementById('searchform').querySelectorAll('input, select');

    formElements.forEach(el => {
        if (el.type === 'hidden') return;
        if (el.type === 'checkbox' || el.type === 'radio') {
            if (el.checked) activeFilter.add(el.name);
        }
        else if (el.type === 'text' || el.tagName === 'SELECT') {
            if (el.value.trim() !== '') activeFilter.add(el.name);
        }
    });

    // ★最強のデバッグツール：現在JSが認識している検索項目をコンソールに表示
    console.log("現在アクティブな検索項目:", Array.from(activeFilter));

    Object.values(kotoColumnConfig).forEach(config => {
        if (config.related_filters && Array.isArray(config.related_filters) && config.related_filters.length > 0) {
            const isFilterActive = config.related_filters.some(filterName => activeFilter.has(filterName));

            // ★最強のデバッグツール：声優列がどう判定されたかコンソールに表示
            if (config.class === 'col-cv') {
                console.log("声優列(CV)の表示判定:", isFilterActive, "/ 設定された条件:", config.related_filters);
            }

            const colClass = config.class;
            const headerClass = config.header_class ? config.header_class.split(' ')[0] : '';

            const selectors = [];
            if (colClass) selectors.push(`.${colClass}`);
            if (headerClass) selectors.push(`.${headerClass}`);

            if (selectors.length === 0) return;
            const elements = document.querySelectorAll(selectors.join(', '));

            elements.forEach(el => {
                if (isFilterActive) {
                    el.classList.remove('col-hidden'); // ★追加：手動設定ボタンによる非表示を強制的に剥がす！
                    el.style.display = 'table-cell';
                } else {
                    el.style.display = '';
                }
            });
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

        // ① 現在の検索条件をクリア
        const resetBtn = document.getElementById('reset-search-btn');
        if (resetBtn) resetBtn.click();

        // ② 対象の入力フォーム（input）を探す
        const inputElement = document.querySelector(`input[name="${filterName}"]`);

        if (inputElement) {
            // ★追加：テキストボックス（文字検索やCV検索など）の場合
            if (inputElement.type === 'text') {
                inputElement.value = filterValue;
            }
            // 従来のチェックボックス（属性やイベントなど）の場合
            else {
                const checkbox = document.querySelector(`input[name="${filterName}"][value="${filterValue}"]`);
                if (checkbox) {
                    checkbox.checked = true;

                    // アコーディオンを開く
                    let parentDetails = checkbox.closest('details');
                    while (parentDetails) {
                        parentDetails.open = true;
                        parentDetails = parentDetails.parentElement.closest('details');
                    }
                }
            }

            // ③ 検索を実行して表を再描画
            if (typeof window.filterCharacters === 'function') {
                window.filterCharacters();
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
 * 統合検索実行関数
 * @param {boolean} shouldSendGA4 - trueの場合のみGA4イベントを送信
 */
function executeSearch(shouldSendGA4 = false) {
    const form = document.getElementById('searchform');
    if (!form) return;

    const formData = new FormData(form);
    const params = new URLSearchParams();

    // 必須パラメータ
    params.append('post_type', 'character');
    params.append('s', formData.get('s') || '');

    // その他のパラメータ
    for (const [key, value] of formData.entries()) {
        if (key !== 's' && key !== 'post_type' && value.trim() !== '') {
            params.append(key, value);
        }
    }

    const queryString = params.toString();
    const searchPath = '/'; // 検索結果ページのパスに調整してください

    // 検索結果ページかどうか判定
    const isSearchPage = window.location.pathname === searchPath ||
                         document.getElementById('chara-list-body') !== null;

    if (isSearchPage) {
        // 検索結果ページ：URL更新 + 検索実行
        const newUrl = window.location.pathname + (queryString ? '?' + queryString : '');
        window.history.replaceState(null, '', newUrl);

        if (typeof filterCharacters === 'function') {
            filterCharacters();
        }

        // 変更があればGA4送信
        if (shouldSendGA4) {
            sendGA4SearchEvent(formData);
        }
    } else {
        // 他ページ：検索結果ページへ遷移（クエリ付き）
        const targetUrl = searchPath + (queryString ? '?' + queryString : '');
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
