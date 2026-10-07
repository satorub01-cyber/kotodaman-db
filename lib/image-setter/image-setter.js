/**
 * キャラ画像設定 専用JavaScript
 * 
 * - メモリ保護・高速化のためサーバーサイドAjax検索 & ページネーション対応
 * - キャラクター選択時に画像検索欄へ name_ruby を自動入力して即座にサーバー検索
 * - 画像ホバーボタンによる非同期Ajax画像割り当て
 */
(function() {
    'use strict';

    // サーバー設定
    const CONFIG = window.IMAGE_SETTER_CONFIG || {
        ajaxUrl: '',
        nonce: ''
    };

    // 状態管理
    let selectedCharId = null;
    let selectedCharTitle = '';
    let isSubmitting = false;
    let toastTimer = null;
    let mediaSearchTimer = null;
    let currentMediaPage = 1;
    let hasMoreMedia = false;
    let isLoadingMedia = false;

    // DOM要素取得
    const toastBar = document.getElementById('setter-toast-bar');
    const toastMsg = document.getElementById('setter-toast-message');
    const currentNameEl = document.getElementById('current-char-name');
    const currentPill = document.getElementById('current-char-pill');

    const charaListEl = document.getElementById('chara-list');
    const charaItems = document.querySelectorAll('.chara-item');
    const charaSearchInput = document.getElementById('chara-search-input');
    const charaSearchClear = document.getElementById('chara-search-clear');
    const charaCountEl = document.getElementById('chara-count');

    const mediaGridEl = document.getElementById('media-grid');
    const mediaSearchInput = document.getElementById('media-search-input');
    const mediaSearchClear = document.getElementById('media-search-clear');
    const mediaCountEl = document.getElementById('media-count');
    const mediaLoadMoreWrap = document.getElementById('media-load-more-wrap');
    const btnMediaLoadMore = document.getElementById('btn-media-load-more');

    /**
     * トースト通知の表示（最上部バー）
     */
    function showToast(message, isError = false) {
        if (!toastBar || !toastMsg) return;

        if (toastTimer) clearTimeout(toastTimer);

        toastMsg.textContent = message;
        toastBar.style.display = 'block';

        if (isError) {
            toastBar.style.backgroundColor = '#fee2e2';
            toastBar.style.borderColor = '#fca5a5';
            toastBar.style.color = '#991b1b';
        } else {
            toastBar.style.backgroundColor = '#9df0ff';
            toastBar.style.borderColor = '#55cde2';
            toastBar.style.color = '#0b4975';
        }

        // スムーズに視界へスクロール
        toastBar.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        // 6秒後に自動非表示
        toastTimer = setTimeout(function() {
            toastBar.style.display = 'none';
        }, 6000);
    }

    /**
     * カタカナをひらがなに正規化するヘルパー関数
     */
    function normalizeSearchText(str) {
        if (!str) return '';
        return str
            .toLowerCase()
            .replace(/[\u30a1-\u30f6]/g, function(match) {
                return String.fromCharCode(match.charCodeAt(0) - 0x60);
            })
            .trim();
    }

    /**
     * キャラクター選択処理
     * ★ 要件: キャラを選択すると、画像検索欄に選択したキャラの name_ruby が入力される
     */
    function selectCharacter(item) {
        if (!item) return;

        const charId = item.getAttribute('data-id');
        const charTitle = item.getAttribute('data-title') || '';
        const charRuby = item.getAttribute('data-ruby') || '';

        // 選択スタイル更新
        charaItems.forEach(el => {
            el.classList.remove('is-selected');
            el.setAttribute('aria-pressed', 'false');
        });

        item.classList.add('is-selected');
        item.setAttribute('aria-pressed', 'true');

        selectedCharId = charId;
        selectedCharTitle = charTitle;

        if (currentNameEl) {
            currentNameEl.textContent = charTitle;
        }
        if (currentPill) {
            currentPill.style.backgroundColor = '#e0f2fe';
            currentPill.style.borderColor = '#135e96';
        }

        // ★★★ 画像検索欄に選択したキャラの name_ruby を入力してサーバー検索実行 ★★★
        if (mediaSearchInput) {
            const searchKeyword = charRuby ? charRuby : charTitle;
            mediaSearchInput.value = searchKeyword;
            triggerMediaSearch(searchKeyword, true);
        }
    }

    // キャラクター行のクリック・キーボード操作
    charaItems.forEach(item => {
        item.addEventListener('click', function() {
            selectCharacter(this);
        });
        item.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                selectCharacter(this);
            }
        });
    });

    /**
     * キャラ検索フィルタリング（クライアントサイド）
     */
    function filterCharacters() {
        if (!charaSearchInput) return;

        const query = normalizeSearchText(charaSearchInput.value);
        let visibleCount = 0;

        charaItems.forEach(item => {
            const title = normalizeSearchText(item.getAttribute('data-title') || '');
            const ruby = normalizeSearchText(item.getAttribute('data-ruby') || '');

            if (!query || title.includes(query) || ruby.includes(query)) {
                item.style.display = '';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        if (charaSearchClear) {
            charaSearchClear.style.display = query ? 'block' : 'none';
        }
        if (charaCountEl) {
            charaCountEl.textContent = `(${visibleCount}件)`;
        }
    }

    if (charaSearchInput) {
        charaSearchInput.addEventListener('input', filterCharacters);
    }
    if (charaSearchClear) {
        charaSearchClear.addEventListener('click', function() {
            charaSearchInput.value = '';
            filterCharacters();
            charaSearchInput.focus();
        });
    }

    /**
     * 単一の画像カードHTMLを生成
     */
    function createMediaCardHtml(img) {
        const titleSafe = escapeHtml(img.title);
        const filenameSafe = escapeHtml(img.filename);
        const thumbUrlSafe = escapeHtml(img.thumb_url);

        return `
            <li class="media-card"
                data-id="${img.id}"
                data-title="${titleSafe}"
                data-filename="${filenameSafe}"
                id="media-card-${img.id}">
                <div class="media-thumb-box">
                    <img src="${thumbUrlSafe}" alt="${titleSafe}" loading="lazy">
                    <div class="media-actions-overlay">
                        <button type="button" class="btn-image-action" data-type="pre_evo" data-id="${img.id}">進化前</button>
                        <button type="button" class="btn-image-action" data-type="character" data-id="${img.id}">進化後</button>
                        <button type="button" class="btn-image-action" data-type="another" data-id="${img.id}">絵違い</button>
                    </div>
                </div>
                <div class="media-title-box" title="${titleSafe} (${filenameSafe})">
                    ${titleSafe}
                </div>
            </li>
        `;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * サーバーサイド画像検索・ページネーション実行
     */
    function fetchMedia(searchKeyword = '', page = 1, append = false) {
        if (isLoadingMedia) return;
        isLoadingMedia = true;

        if (btnMediaLoadMore) {
            btnMediaLoadMore.disabled = true;
            btnMediaLoadMore.textContent = '読み込み中...';
        }

        const formData = new FormData();
        formData.append('action', 'image_setter_search_media');
        formData.append('nonce', CONFIG.nonce);
        formData.append('search', searchKeyword);
        formData.append('paged', page);

        fetch(CONFIG.ajaxUrl, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(res => {
            isLoadingMedia = false;

            if (res.success && res.data) {
                const data = res.data;
                currentMediaPage = data.page;
                hasMoreMedia = data.has_more;

                if (!append) {
                    // 全体差し替え
                    if (data.items.length === 0) {
                        mediaGridEl.innerHTML = '<li class="setter-empty-msg" style="grid-column: 1/-1;">該当する未紐付け画像はありません。</li>';
                    } else {
                        mediaGridEl.innerHTML = data.items.map(createMediaCardHtml).join('');
                    }
                } else {
                    // 追加
                    if (data.items.length > 0) {
                        const html = data.items.map(createMediaCardHtml).join('');
                        mediaGridEl.insertAdjacentHTML('beforeend', html);
                    }
                }

                // カウント更新
                const currentCount = mediaGridEl.querySelectorAll('.media-card').length;
                if (mediaCountEl) {
                    mediaCountEl.textContent = `(${currentCount}件${hasMoreMedia ? '+' : ''})`;
                }

                // 「さらに読み込む」ボタン表示制御
                if (mediaLoadMoreWrap) {
                    mediaLoadMoreWrap.style.display = hasMoreMedia ? '' : 'none';
                }
                if (btnMediaLoadMore) {
                    btnMediaLoadMore.disabled = false;
                    btnMediaLoadMore.textContent = 'さらに画像を読み込む';
                }

            } else {
                if (btnMediaLoadMore) {
                    btnMediaLoadMore.disabled = false;
                    btnMediaLoadMore.textContent = 'さらに画像を読み込む';
                }
                showToast(res.data && res.data.message ? res.data.message : '画像取得に失敗しました。', true);
            }
        })
        .catch(err => {
            isLoadingMedia = false;
            if (btnMediaLoadMore) {
                btnMediaLoadMore.disabled = false;
                btnMediaLoadMore.textContent = 'さらに画像を読み込む';
            }
            showToast('画像検索通信エラー: ' + err.message, true);
        });
    }

    /**
     * 画像検索のトリガー（debounce付き）
     */
    function triggerMediaSearch(keyword, immediate = false) {
        if (mediaSearchClear) {
            mediaSearchClear.style.display = keyword ? 'block' : 'none';
        }

        if (mediaSearchTimer) clearTimeout(mediaSearchTimer);

        if (immediate) {
            currentMediaPage = 1;
            fetchMedia(keyword, 1, false);
        } else {
            mediaSearchTimer = setTimeout(() => {
                currentMediaPage = 1;
                fetchMedia(keyword, 1, false);
            }, 300);
        }
    }

    if (mediaSearchInput) {
        mediaSearchInput.addEventListener('input', function() {
            triggerMediaSearch(this.value.trim(), false);
        });
    }
    if (mediaSearchClear) {
        mediaSearchClear.addEventListener('click', function() {
            mediaSearchInput.value = '';
            triggerMediaSearch('', true);
            mediaSearchInput.focus();
        });
    }

    // 「さらに画像を読み込む」ボタンイベント
    if (btnMediaLoadMore) {
        btnMediaLoadMore.addEventListener('click', function() {
            const keyword = mediaSearchInput ? mediaSearchInput.value.trim() : '';
            fetchMedia(keyword, currentMediaPage + 1, true);
        });
    }

    /**
     * 画像設定リクエスト (Ajax送信)
     */
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-image-action');
        if (!btn) return;

        e.preventDefault();
        e.stopPropagation();

        if (isSubmitting) return;

        // キャラが未選択の場合
        if (!selectedCharId) {
            showToast('上のキャラ一覧から設定対象のキャラクターを選択してください。', true);
            const panelChara = document.getElementById('panel-chara');
            if (panelChara) {
                panelChara.scrollIntoView({ behavior: 'smooth' });
                panelChara.style.borderColor = '#dc2626';
                setTimeout(() => { panelChara.style.borderColor = '#333333'; }, 1500);
            }
            return;
        }

        const imageId = btn.getAttribute('data-id');
        const imageType = btn.getAttribute('data-type');
        const card = document.getElementById(`media-card-${imageId}`);

        if (card) {
            card.classList.add('is-uploading');
        }

        isSubmitting = true;
        const originalText = btn.textContent;
        btn.textContent = '設定中...';

        const formData = new FormData();
        formData.append('action', 'image_setter_save_image');
        formData.append('nonce', CONFIG.nonce);
        formData.append('character_id', selectedCharId);
        formData.append('image_id', imageId);
        formData.append('image_type', imageType);

        fetch(CONFIG.ajaxUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(res => {
            isSubmitting = false;
            btn.textContent = originalText;

            if (res.success) {
                // 成功メッセージ表示（添付画像: 「キャラ１の進化前/後に設定しました」）
                showToast(res.data.message);

                // 設定済み画像を一覧からフェードアウトして削除
                if (card) {
                    card.classList.add('fade-out');
                    setTimeout(() => {
                        card.remove();
                        // 残件数更新
                        const remaining = document.querySelectorAll('.media-card:not(.fade-out)').length;
                        if (mediaCountEl) {
                            mediaCountEl.textContent = `(${remaining}件${hasMoreMedia ? '+' : ''})`;
                        }
                        if (remaining === 0 && !hasMoreMedia) {
                            mediaGridEl.innerHTML = '<li class="setter-empty-msg" style="grid-column: 1/-1;">未紐付けの画像はありません。</li>';
                        }
                    }, 400);
                }

                // 対象キャラのバッジ更新
                if (res.data.status) {
                    const st = res.data.status;
                    const preBadge = document.getElementById(`badge-pre-${selectedCharId}`);
                    const mainBadge = document.getElementById(`badge-main-${selectedCharId}`);
                    const anoBadge = document.getElementById(`badge-ano-${selectedCharId}`);

                    if (preBadge) {
                        preBadge.textContent = '進化前:' + (st.has_pre ? '済' : '未');
                        preBadge.classList.toggle('is-set', !!st.has_pre);
                    }
                    if (mainBadge) {
                        mainBadge.textContent = '進化後:' + (st.has_main ? '済' : '未');
                        mainBadge.classList.toggle('is-set', !!st.has_main);
                    }
                    if (anoBadge) {
                        anoBadge.textContent = '絵違い:' + (st.has_another ? '済' : '未');
                        anoBadge.classList.toggle('is-set', !!st.has_another);
                    }
                }

            } else {
                if (card) card.classList.remove('is-uploading');
                showToast(res.data && res.data.message ? res.data.message : '設定に失敗しました。', true);
            }
        })
        .catch(err => {
            isSubmitting = false;
            btn.textContent = originalText;
            if (card) card.classList.remove('is-uploading');
            showToast('通信エラーが発生しました: ' + err.message, true);
        });
    });

})();
