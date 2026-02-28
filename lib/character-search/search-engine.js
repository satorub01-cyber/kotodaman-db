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

// 属性・種族マップ
const ATTR_MAP = { 1: { slug: 'fire', name: '火' }, 2: { slug: 'water', name: '水' }, 3: { slug: 'wood', name: '木' }, 4: { slug: 'light', name: '光' }, 5: { slug: 'dark', name: '闇' }, 6: { slug: 'void', name: '冥' }, 7: { slug: 'heaven', name: '天' } };
const SPECIES_MAP = { 1: { slug: 'god', name: '神' }, 2: { slug: 'demon', name: '魔' }, 3: { slug: 'hero', name: '英' }, 4: { slug: 'dragon', name: '龍' }, 5: { slug: 'beast', name: '獣' }, 6: { slug: 'spirit', name: '霊' }, 7: { slug: 'artifact', name: '物' }, 8: { slug: 'yokai', name: '妖' } };

// 1キャラ分の行(<tr>)生成関数
function createCharacterRowHtml(char) {
    const link = `/character/?p=${char.id}`;
    const nameParts = char.name.split('・');
    const dispName = nameParts.length > 1 ? nameParts : char.name;

    const thumbHtml = char.thumb_url
        ? `<img src="${char.thumb_url}" class="chara-thumb" alt="${char.name}">`
        : `<div class="no-img"></div>`;

    let charsHtml = '';
    if (char.chars && char.chars.length > 0) {
        charsHtml = char.chars.map(c => {
            const attrSlug = ATTR_MAP[c.attr] ? ATTR_MAP[c.attr].slug : 'none';
            let suffix = '';
            if (c.unlock === 'super_copy') suffix = '<span class="char-suffix">(Sコ)</span>';
            if (c.unlock === 'super_change') suffix = '<span class="char-suffix">(Sチ)</span>';
            return `<a href="#" class="char-link-item"><span class="char-font attr-${attrSlug}">${c.val}</span>${suffix}</a>`;
        }).join('');
    }

    const attrData = ATTR_MAP[char.attr] || { slug: 'none', name: '不明' };
    let attrHtml = `<span class="attr-text attr-${attrData.slug}">${attrData.name}</span>`;
    if (char.sub_attrs && char.sub_attrs.length > 0) {
        char.sub_attrs.forEach(subId => {
            const subData = ATTR_MAP[subId];
            if (subData) attrHtml += `<span class="attr-text attr-${subData.slug} koto-icon-small">${subData.name}</span>`;
        });
    }

    const speciesData = SPECIES_MAP[char.spe] || { slug: 'none', name: '不明' };
    const speciesHtml = `<span class="species-text species-${speciesData.slug}">${speciesData.name}</span>`;

    let gimmickHtml = '';
    if (char.gimmicks && char.gimmicks.length > 0) {
        gimmickHtml = char.gimmicks.map(g => `<span class="badge-gimmick">${g}</span>`).join('');
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

    return `
    <tr data-id="${char.id}">
        <td class="td-icon col-icon"><a href="${link}">${thumbHtml}</a></td>
        <td class="td-id col-id">${char.id}</td>
        <td class="td-name col-name"><a href="${link}" class="chara-link">${dispName}</a><div class="name-ruby" style="display:none;">${char.name_ruby}</div></td>

        <td class="td-moji col-moji"><div class="char-list">${charsHtml}</div></td>
        <td class="td-attr col-attr"><div class="attr-box-row">${attrHtml}</div></td>
        <td class="td-species col-species">${speciesHtml}</td>

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
        <td class="td-cv col-cv">${char.cv || '-'}</td>
        <td class="td-acq col-acq">${char.acq || '-'}</td>
        <td class="td-date col-date">${char.date || '-'}</td>
        <td class="td-power col-power">${formatNumber(char.power)}</td>
    </tr>
    `;
}

// =========================================================
// フィルタリング・ソート・描画処理
// =========================================================

const getCheckedValues = (selector) => Array.from(document.querySelectorAll(selector)).map(cb => cb.value);
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

function filterCharacters() {
    const keyword = document.getElementById('s') ? document.getElementById('s').value.toLowerCase().trim() : '';
    const searchChar = document.querySelector('input[name="search_char"]') ? document.querySelector('input[name="search_char"]').value.trim() : '';
    const searchCv = document.querySelector('input[name="tx_cv"]') ? document.querySelector('input[name="tx_cv"]').value.trim() : '';

    const checkedAttrs = getCheckedValues('input[name="tx_attr[]"]:checked');
    const checkedSpecies = getCheckedValues('input[name="tx_species[]"]:checked');
    const checkedRarities = getCheckedValues('input[name="tx_rarity[]"]:checked');
    const checkedGroups = getCheckedValues('input[name="tx_group[]"]:checked');
    const checkedEvents = getCheckedValues('input[name="tx_event[]"]:checked');
    const checkedGimmicks = getCheckedValues('input[name="tx_gimmick[]"]:checked');

    const relGroup = getRadioValue('tx_group_relation', 'OR');
    const relEvent = getRadioValue('tx_event_relation', 'OR');
    const relGimmick = getRadioValue('tx_gimmick_relation', 'AND');

    const checkedSkillTags = getCheckedValues('input[name="tx_skill_tags[]"]:checked');
    const checkedSkillScopes = getCheckedValues('input[name="scope_skill[]"]:checked');
    const checkedTraitTags = getCheckedValues('input[name="tx_trait_tags[]"]:checked');
    const checkedTraitScopes = getCheckedValues('input[name="scope_trait[]"]:checked');

    // ★変更：結果を filteredCharacters に代入する
    filteredCharacters = allCharacters.filter(char => {
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
        if (checkedAttrs.length > 0 && !checkedAttrs.includes(ATTR_MAP[char.attr] ? ATTR_MAP[char.attr].slug : '')) return false;
        if (checkedSpecies.length > 0 && !checkedSpecies.includes(SPECIES_MAP[char.spe] ? SPECIES_MAP[char.spe].slug : '')) return false;
        if (checkedRarities.length > 0 && !checkedRarities.some(r => safeIncludes(char.rar_t, r))) return false;

        if (checkedGroups.length > 0) {
            if (relGroup === 'AND' ? !checkedGroups.every(g => safeIncludes(char.grp, g)) : !checkedGroups.some(g => safeIncludes(char.grp, g))) return false;
        }
        if (checkedEvents.length > 0) {
            if (relEvent === 'AND' ? !checkedEvents.every(e => safeIncludes(char.events, e)) : !checkedEvents.some(e => safeIncludes(char.events, e))) return false;
        }
        if (checkedGimmicks.length > 0) {
            if (relGimmick === 'AND' ? !checkedGimmicks.every(g => safeIncludes(char.gim_t, g)) : !checkedGimmicks.some(g => safeIncludes(char.gim_t, g))) return false;
        }

        if (checkedSkillTags.length > 0) {
            if (checkedSkillScopes.length === 0) return false;
            const hasMatch = checkedSkillTags.some(tag => {
                const s = ` ${tag} `;
                return (checkedSkillScopes.includes('waza') && char.waza_t && char.waza_t.includes(s)) ||
                    (checkedSkillScopes.includes('sugo') && char.sugo_t && char.sugo_t.includes(s)) ||
                    (checkedSkillScopes.includes('kotowaza') && char.koto_t && char.koto_t.includes(s));
            });
            if (!hasMatch) return false;
        }

        if (checkedTraitTags.length > 0) {
            if (checkedTraitScopes.length === 0) return false;
            const hasMatch = checkedTraitTags.some(tag => {
                const s = ` ${tag} `;
                return (checkedTraitScopes.includes('t1') && char.t1_t && char.t1_t.includes(s)) ||
                    (checkedTraitScopes.includes('t2') && char.t2_t && char.t2_t.includes(s)) ||
                    (checkedTraitScopes.includes('blessing') && char.bles_t && char.bles_t.includes(s));
            });
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
            // それ以外（文字列）は辞書順で比較
            valA = String(valA);
            valB = String(valB);
            if (valA < valB) diff = currentSortOrder === 'ASC' ? -1 : 1;
            else if (valA > valB) diff = currentSortOrder === 'ASC' ? 1 : -1;
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
                if (nameA < nameB) return -1; // 昇順
                if (nameA > nameB) return 1;
                return 0;
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
}