(function () {
    'use strict';

    if (typeof KotoOgpAdmin === 'undefined') {
        return;
    }

    const MAX_SELECTED = 6;
    const state = {
        selected: [], // [{ id, name, thumb }]
        candidates: [],
        blobUrl: null
    };

    const sourceSel = document.getElementById('koto-ogp-source');
    const searchInput = document.getElementById('koto-ogp-search-input');
    const searchBtn = document.getElementById('koto-ogp-search-btn');
    const candidatesEl = document.getElementById('koto-ogp-candidates');
    const selectedListEl = document.getElementById('koto-ogp-selected-list');
    const selectedCountEl = document.getElementById('koto-ogp-selected-count');
    const clearSelectedBtn = document.getElementById('koto-ogp-clear-selected');
    const presetSel = document.getElementById('koto-ogp-preset');
    const bgSel = document.getElementById('koto-ogp-bg-style');
    const logoCb = document.getElementById('koto-ogp-show-logo');
    const generateBtn = document.getElementById('koto-ogp-generate-btn');
    const downloadLink = document.getElementById('koto-ogp-download-link');
    const clearCacheBtn = document.getElementById('koto-ogp-clear-cache-btn');
    const statusEl = document.getElementById('koto-ogp-status');
    const previewImg = document.getElementById('koto-ogp-preview-img');
    const previewPlaceholder = document.getElementById('koto-ogp-preview-placeholder');

    function setStatus(msg) {
        if (statusEl) {
            statusEl.textContent = msg || '';
        }
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function sanitizeImageUrl(url) {
        const s = String(url || '').trim();
        if (/^(https?:\/\/|\/)/i.test(s)) {
            return s;
        }
        return '';
    }

    function isSelected(id) {
        return state.selected.some(item => item.id === id);
    }

    function renderCandidates() {
        if (!candidatesEl) return;
        if (!state.candidates.length) {
            candidatesEl.innerHTML = '<p class="koto-ogp-muted">該当キャラクターが見つかりませんでした。</p>';
            return;
        }
        candidatesEl.innerHTML = state.candidates.map(item => {
            const safeId = Number(item.id) || 0;
            const activeClass = isSelected(safeId) ? ' is-selected' : '';
            const safeThumb = sanitizeImageUrl(item.thumb);
            const thumbHtml = safeThumb
                ? `<img src="${escapeHtml(safeThumb)}" class="koto-ogp-candidate-thumb" alt="" loading="lazy">`
                : '<div class="koto-ogp-candidate-thumb"></div>';
            return `
                <button type="button" class="koto-ogp-candidate-card${activeClass}" data-id="${safeId}">
                    ${thumbHtml}
                    <span class="koto-ogp-candidate-name">${escapeHtml(item.name)}</span>
                    <span class="koto-ogp-candidate-badge">${escapeHtml(item.rarity || '')}</span>
                </button>
            `;
        }).join('');
    }

    function renderSelected() {
        if (!selectedListEl || !selectedCountEl) return;
        selectedCountEl.textContent = String(state.selected.length);
        generateBtn.disabled = state.selected.length === 0;

        if (!state.selected.length) {
            selectedListEl.innerHTML = '<li class="koto-ogp-empty-note">左の一覧からキャラクターをクリックして追加してください。</li>';
            downloadLink.classList.add('is-disabled');
            downloadLink.setAttribute('href', '#');
            return;
        }

        selectedListEl.innerHTML = state.selected.map((item, idx) => {
            const safeThumb = sanitizeImageUrl(item.thumb);
            const thumbHtml = safeThumb
                ? `<img src="${escapeHtml(safeThumb)}" class="koto-ogp-selected-thumb" alt="">`
                : '<div class="koto-ogp-selected-thumb"></div>';
            return `
                <li class="koto-ogp-selected-item" data-index="${idx}">
                    ${thumbHtml}
                    <span class="koto-ogp-selected-name">${idx + 1}. ${escapeHtml(item.name)}</span>
                    <span class="koto-ogp-selected-btns">
                        <button type="button" class="button button-small js-move-up" ${idx === 0 ? 'disabled' : ''}>↑</button>
                        <button type="button" class="button button-small js-move-down" ${idx === state.selected.length - 1 ? 'disabled' : ''}>↓</button>
                        <button type="button" class="button button-small js-remove">削除</button>
                    </span>
                </li>
            `;
        }).join('');
    }

    async function fetchCandidates() {
        setStatus('');
        candidatesEl.innerHTML = '<p class="koto-ogp-muted">検索中...</p>';
        const body = new URLSearchParams();
        body.append('action', 'koto_ogp_search_characters');
        body.append('nonce', KotoOgpAdmin.adminNonce);
        body.append('keyword', searchInput ? searchInput.value.trim() : '');
        body.append('source', sourceSel ? sourceSel.value : 'db');

        try {
            const res = await fetch(KotoOgpAdmin.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            });
            const json = await res.json();
            if (!json || !json.success) {
                candidatesEl.innerHTML = '<p class="koto-ogp-muted">取得に失敗しました。</p>';
                return;
            }
            state.candidates = Array.isArray(json.data.items) ? json.data.items : [];
            renderCandidates();
        } catch (err) {
            candidatesEl.innerHTML = '<p class="koto-ogp-muted">通信エラーが発生しました。</p>';
        }
    }

    function revokeBlobUrl() {
        if (state.blobUrl) {
            URL.revokeObjectURL(state.blobUrl);
            state.blobUrl = null;
        }
    }

    function buildRenderParams() {
        const [w, h] = (presetSel.value || '1200x630').split('x');
        const params = new URLSearchParams();
        params.append('action', 'koto_ogp_render_custom');
        params.append('nonce', KotoOgpAdmin.adminNonce);
        params.append('ids', state.selected.map(item => item.id).join(','));
        params.append('w', w);
        params.append('h', h);
        params.append('bg', bgSel.value || 'blur');
        params.append('logo', logoCb.checked ? '1' : '0');
        params.append('source', sourceSel ? sourceSel.value : 'db');
        return { params, filename: `kotodaman-thumb-${w}x${h}.png` };
    }

    async function updatePreview() {
        if (!state.selected.length) return;
        setStatus('画像を生成しています...');
        generateBtn.disabled = true;
        downloadLink.classList.add('is-disabled');
        downloadLink.setAttribute('href', '#');

        const { params, filename } = buildRenderParams();
        try {
            const res = await fetch(KotoOgpAdmin.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            });
            const contentType = res.headers.get('Content-Type') || '';
            if (!res.ok || !contentType.startsWith('image/')) {
                const errText = await res.text().catch(() => '');
                setStatus(errText ? `画像の生成に失敗しました（${res.status}: ${errText.slice(0, 80)}）。` : `画像の生成に失敗しました（HTTP ${res.status}）。`);
                return;
            }
            const blob = await res.blob();
            if (!blob || blob.size === 0) {
                setStatus('画像データが空でした。');
                return;
            }

            revokeBlobUrl();
            const blobUrl = URL.createObjectURL(blob);
            state.blobUrl = blobUrl;

            previewImg.onload = function () {
                previewImg.classList.remove('is-hidden');
                previewPlaceholder.classList.add('is-hidden');
                downloadLink.classList.remove('is-disabled');
                downloadLink.setAttribute('href', blobUrl);
                downloadLink.setAttribute('download', filename);
                setStatus('プレビューを生成しました。');
            };
            previewImg.onerror = function () {
                setStatus('プレビュー画像の描画に失敗しました。');
            };
            previewImg.src = blobUrl;
        } catch (err) {
            setStatus('通信エラーが発生しました。');
        } finally {
            generateBtn.disabled = state.selected.length === 0;
        }
    }

    candidatesEl.addEventListener('click', function (e) {
        const card = e.target.closest('.koto-ogp-candidate-card');
        if (!card) return;
        const id = Number(card.dataset.id);
        const item = state.candidates.find(c => c.id === id);
        if (!item) return;

        if (isSelected(id)) {
            state.selected = state.selected.filter(c => c.id !== id);
        } else {
            if (state.selected.length >= MAX_SELECTED) {
                setStatus(`最大 ${MAX_SELECTED} 体まで選択できます。`);
                return;
            }
            state.selected.push({ id: item.id, name: item.name, thumb: item.thumb });
        }
        renderCandidates();
        renderSelected();
    });

    selectedListEl.addEventListener('click', function (e) {
        const li = e.target.closest('.koto-ogp-selected-item');
        if (!li) return;
        const idx = Number(li.dataset.index);
        if (e.target.classList.contains('js-remove')) {
            state.selected.splice(idx, 1);
        } else if (e.target.classList.contains('js-move-up') && idx > 0) {
            const tmp = state.selected[idx - 1];
            state.selected[idx - 1] = state.selected[idx];
            state.selected[idx] = tmp;
        } else if (e.target.classList.contains('js-move-down') && idx < state.selected.length - 1) {
            const tmp = state.selected[idx + 1];
            state.selected[idx + 1] = state.selected[idx];
            state.selected[idx] = tmp;
        }
        renderCandidates();
        renderSelected();
    });

    clearSelectedBtn.addEventListener('click', function () {
        state.selected = [];
        renderCandidates();
        renderSelected();
    });

    sourceSel.addEventListener('change', function () {
        state.selected = [];
        renderSelected();
        fetchCandidates();
    });

    searchBtn.addEventListener('click', fetchCandidates);
    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            fetchCandidates();
        }
    });

    generateBtn.addEventListener('click', updatePreview);

    downloadLink.addEventListener('click', function (e) {
        if (downloadLink.classList.contains('is-disabled') || !state.blobUrl) {
            e.preventDefault();
        }
    });

    window.addEventListener('beforeunload', revokeBlobUrl);

    clearCacheBtn.addEventListener('click', async function () {
        setStatus('キャッシュを削除しています...');
        const body = new URLSearchParams();
        body.append('action', 'koto_ogp_clear_cache');
        body.append('nonce', KotoOgpAdmin.adminNonce);
        try {
            const res = await fetch(KotoOgpAdmin.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            });
            const json = await res.json();
            if (json && json.success) {
                setStatus(`OGPキャッシュ（${json.data.deleted} 件）を削除しました。`);
            } else {
                setStatus('キャッシュ削除に失敗しました。');
            }
        } catch (err) {
            setStatus('通信エラーが発生しました。');
        }
    });

    fetchCandidates();
})();
