// =========================================================
// コトダマンDB フロントエンド検索エンジン
// =========================================================

const JSON_URL = '/wp-content/themes/cocoon-child-master/lib/character-search/all_characters_search.json?v=' + new Date().getTime();
let allCharacters = [];
let filteredCharacters = []; // ★追加：現在の絞り込み結果を保持する配列

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
    1: { slug: 'fire', name: '火', icon: '/wp-content/uploads/2025/12/icon-fire-150x150.png.webp' },
    2: { slug: 'water', name: '水', icon: '/wp-content/uploads/2025/12/icon-water-150x150.png.webp' },
    3: { slug: 'wood', name: '木', icon: '/wp-content/uploads/2025/12/icon-wood-150x150.png.webp' },
    4: { slug: 'light', name: '光', icon: '/wp-content/uploads/2025/12/icon-light-150x150.png.webp' },
    5: { slug: 'dark', name: '闇', icon: '/wp-content/uploads/2025/12/icon-dark-150x150.png.webp' },
    6: { slug: 'void', name: '冥', icon: '/wp-content/uploads/2025/12/icon-void-150x150.png.webp' },
    7: { slug: 'heaven', name: '天', icon: '/wp-content/uploads/2025/12/icon-heaven-150x150.png.webp' }
};
const SPECIES_MAP = {
    1: { slug: 'god', name: '神', icon: '/wp-content/uploads/2025/12/icon-god-150x150.png.webp' },
    2: { slug: 'demon', name: '魔', icon: '/wp-content/uploads/2025/12/icon-demon-150x150.png.webp' },
    3: { slug: 'hero', name: '英', icon: '/wp-content/uploads/2025/12/icon-hero-150x150.png.webp' },
    4: { slug: 'dragon', name: '龍', icon: '/wp-content/uploads/2025/12/icon-dragon-150x150.png.webp' },
    5: { slug: 'beast', name: '獣', icon: '/wp-content/uploads/2025/12/icon-beast-150x150.png.webp' },
    6: { slug: 'spirit', name: '霊', icon: '/wp-content/uploads/2025/12/icon-spirit-150x150.png.webp' },
    7: { slug: 'artifact', name: '物', icon: '/wp-content/uploads/2025/12/icon-artifact-150x150.png.webp' },
    8: { slug: 'yokai', name: '妖', icon: '/wp-content/uploads/2025/12/icon-yokai-150x150.png.webp' }
};

// 1キャラ分の行(<tr>)生成関数
function createCharacterRowHtml(char) {
    const link = `/character/${char.id}`;
    // ▼▼▼ ここを修正 ▼▼▼
    const nameParts = char.name.split('・');
    // 「・」が複数あった場合でも、最初の「・」以降を全て繋ぎ直して表示する
    const dispName = nameParts.length > 1 ? nameParts.slice(1).join('・') : char.name;
    // ▲▲▲ 修正ここまで ▲▲▲

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
    if (char.gimmicks && char.gimmicks.length > 0) {
        gimmickHtml = char.gimmicks.map(g => `<a href="#" class="js-quick-filter" data-name="tx_gimmick[]" data-value="${g}"><span class="badge-gimmick">${g}</span></a>`).join('');
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
    if (char.grp && char.grp.length > 0) {
        groupHtml = char.grp.map(slug => {
            let decoded = slug;
            try { decoded = decodeURIComponent(slug); } catch (e) { }
            // <a>タグで囲む
            return `<a href="#" class="js-quick-filter" data-name="tx_group[]" data-value="${slug}">${KOTO_GROUP_MAP[decoded] || decoded}</a>`;
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
        if (keyword) {
            if (!char.name.toLowerCase().includes(keyword) && !char.name_ruby.toLowerCase().includes(keyword)) return false;
        }
        if (searchCv) {
            if (!char.cv || !char.cv.includes(searchCv)) return false;
        }
        if (searchChar) {
            const targetChars = Array.from(searchChar).filter(c => c.trim() !== '' && c !== ',' && c !== '、');
            if (targetChars.length > 0) {
                const charVals = char.chars ? char.chars.map(c => c.val) : [];
                if (!targetChars.some(tc => charVals.includes(tc))) return false;
            }
        }
        if (!checkRelationCondition(checkedSpecies, SPECIES_MAP[char.spe] ? SPECIES_MAP[char.spe].slug : '', relSpecies)) return false;
        if (!checkRelationCondition(checkedRarities, char.rar_t, relRarity)) return false;
        if (!checkRelationCondition(checkedGroups, char.grp, relGroup)) return false;
        if (!checkRelationCondition(checkedEvents, char.events, relEvent)) return false;
        if (!checkRelationCondition(checkedGimmicks, char.gim_t, relGimmick)) return false;
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
                const s = ` ${tag} `;
                return (checkedTraitScopes.includes('t1') && char.t1_t && char.t1_t.includes(s)) ||
                    (checkedTraitScopes.includes('t2') && char.t2_t && char.t2_t.includes(s)) ||
                    (checkedTraitScopes.includes('blessing') && char.bles_t && char.bles_t.includes(s));
            };

            // 検索条件(AND/OR)に応じて配列の評価メソッドを切り替え
            const hasMatch = relTraitTags === 'AND'
                ? checkedTraitTags.every(checkTraitMatch)
                : checkedTraitTags.some(checkTraitMatch);

            if (!hasMatch) return false;
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

    if (chars.length === 0) {
        tbody.innerHTML = '<tr><td colspan="100%" class="no-data" style="text-align:center; padding:30px;">条件に一致するキャラクターが見つかりませんでした。</td></tr>';
    } else {
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

// 描画処理
function renderTable(chars) {
    const tbody = document.getElementById('chara-list-body');
    const spinner = document.getElementById('loading-spinner');

    if (chars.length === 0) {
        tbody.innerHTML = '<tr><td colspan="100%" class="no-data" style="text-align:center; padding:30px;">条件に一致するキャラクターが見つかりませんでした。</td></tr>';
    } else {
        tbody.innerHTML = chars.map(createCharacterRowHtml).join('');
    }

    if (spinner) spinner.style.display = 'none';

    if (typeof window.applyCurrentColumnSettings === 'function') {
        window.applyCurrentColumnSettings();
    }
    const hitCountSpan = document.getElementById('hit-count-num');
    if (hitCountSpan) {
        // formatNumber を使えば 1,000件 のようにカンマ区切りになって綺麗です
        hitCountSpan.innerHTML = formatNumber(chars.length);
    }

    updateDynamicColumns();
}

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

function updateSearchUrlAndAnalytics() {
    const form = document.getElementById('searchform');
    if (!form) return;

    const urlParams = new URLSearchParams();
    const formData = new FormData(form);

    // ★必須パラメータを最初に追加
    // これにより、常に ?post_type=character&s=... の形式が維持されます
    urlParams.append('post_type', 'character');

    // キーワード検索 's' の値を取得（空の場合は空文字をセット）
    const searchVal = formData.get('s') || '';
    urlParams.append('s', searchVal);

    const gaData = {
        'event_category': 'search',
        'event_label': 'custom_search_bar',
        'search_term': searchVal,
        'filter_attr': [],
        'filter_species': [],
        'filter_group': [],
        'filter_gimmick': [],
        'filter_event': []
    };

    for (const [key, value] of formData.entries()) {
        // 's' と 'post_type' は既に追加済みなのでスキップ
        if (key === 's' || key === 'post_type') continue;

        if (value.trim() !== '') {
            urlParams.append(key, value);

            if (key === 'tx_attr[]') gaData.filter_attr.push(value);
            else if (key === 'tx_species[]') gaData.filter_species.push(value);
            else if (key === 'tx_group[]') gaData.filter_group.push(value);
            else if (key === 'tx_gimmick[]') gaData.filter_gimmick.push(value);
            else if (key === 'tx_event[]') gaData.filter_event.push(value);
        }
    }

    const queryString = urlParams.toString();
    const newUrl = window.location.pathname + (queryString ? '?' + queryString : '');
    window.history.replaceState(null, '', newUrl);

    // ③ GA4へイベントを送信 (本番環境のみ)
    if (window.location.hostname === 'www.kotodaman-db.com' && typeof gtag === 'function') {

        // 配列になっているものをカンマ区切りの文字列に変換
        const finalGaParams = {
            'event_category': gaData.event_category,
            'event_label': gaData.event_label,
            'search_term': gaData.search_term,
            'filter_attr': gaData.filter_attr.join(','),
            'filter_species': gaData.filter_species.join(','),
            'filter_group': gaData.filter_group.join(','),
            'filter_gimmick': gaData.filter_gimmick.join(','),
            'filter_event': gaData.filter_event.join(',')
        };

        // 何か1つでも検索条件が指定されていれば送信
        if (finalGaParams.search_term || finalGaParams.filter_attr || finalGaParams.filter_species ||
            finalGaParams.filter_group || finalGaParams.filter_gimmick || finalGaParams.filter_event) {

            gtag('event', 'character_search', finalGaParams);
            // console.log("GA4送信テスト:", finalGaParams); // テスト時はこのコメントを外すと確認できます
        }
    }
}