/**
 * キャラ画像設定 専用JavaScript
 * 
 * - キャラクター選択時に画像検索欄へ name_ruby を自動入力して絞り込み
 * - キャラ名／画像名のリアルタイムインクリメンタル検索（ひらがな/カタカナ相互対応）
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
    const mediaCards = document.querySelectorAll('.media-card');
    const mediaSearchInput = document.getElementById('media-search-input');
    const mediaSearchClear = document.getElementById('media-search-clear');
    const mediaCountEl = document.getElementById('media-count');

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

        // ★★★ 画像検索欄に選択したキャラの name_ruby を入力して絞り込み実行 ★★★
        if (mediaSearchInput) {
            // ルビがあればルビ、なければタイトルをセット
            const searchKeyword = charRuby ? charRuby : charTitle;
            mediaSearchInput.value = searchKeyword;
            filterMedia();
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
     * キャラ検索フィルタリング
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
     * 画像検索フィルタリング
     */
    function filterMedia() {
        if (!mediaSearchInput) return;

        const query = normalizeSearchText(mediaSearchInput.value);
        let visibleCount = 0;

        document.querySelectorAll('.media-card').forEach(card => {
            const title = normalizeSearchText(card.getAttribute('data-title') || '');
            const filename = normalizeSearchText(card.getAttribute('data-filename') || '');

            if (!query || title.includes(query) || filename.includes(query)) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        if (mediaSearchClear) {
            mediaSearchClear.style.display = query ? 'block' : 'none';
        }
        if (mediaCountEl) {
            mediaCountEl.textContent = `(${visibleCount}件)`;
        }
    }

    if (mediaSearchInput) {
        mediaSearchInput.addEventListener('input', filterMedia);
    }
    if (mediaSearchClear) {
        mediaSearchClear.addEventListener('click', function() {
            mediaSearchInput.value = '';
            filterMedia();
            mediaSearchInput.focus();
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
                            mediaCountEl.textContent = `(${remaining}件)`;
                        }
                        if (remaining === 0) {
                            const grid = document.getElementById('media-grid');
                            if (grid) {
                                grid.innerHTML = '<li class="setter-empty-msg" style="grid-column: 1/-1;">未紐付けの画像はありません。</li>';
                            }
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

