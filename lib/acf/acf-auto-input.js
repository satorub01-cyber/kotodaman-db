document.addEventListener('DOMContentLoaded', function () {
    // 手動でXdebugを常に強制する場合はここを true に変更できます
    const MANUAL_FORCE_XDEBUG = false;

    // 現在のページURLまたはCookieにXdebugが指定されているか判定
    const urlParams = new URLSearchParams(window.location.search);
    const hasUrlXdebug = urlParams.has('XDEBUG_SESSION_START') || urlParams.has('XDEBUG_SESSION') || urlParams.has('XDEBUG_TRIGGER');
    const hasCookieXdebug = typeof document !== 'undefined' && document.cookie.includes('XDEBUG_SESSION=');

    // ローカル環境判定（AppDebugConfig、またはホスト名から判定）
    const isLocal = Boolean(
        MANUAL_FORCE_XDEBUG ||
        hasUrlXdebug ||
        (window.AppDebugConfig && window.AppDebugConfig.isLocal) ||
        ['localhost', '127.0.0.1'].includes(window.location.hostname) ||
        window.location.hostname.endsWith('.local')
    );

    // Xdebug トグルボタンの生成（ローカル環境のみ）
    if (isLocal) {
        let isXdebugActive = MANUAL_FORCE_XDEBUG || hasUrlXdebug || localStorage.getItem('app_xdebug_active') === 'true';

        const btnToggle = document.createElement('button');
        btnToggle.type = 'button';
        btnToggle.className = 'xdebug-toggle-btn' + (isXdebugActive ? ' is-active' : '');
        btnToggle.textContent = isXdebugActive ? 'Xdebug: ON' : 'Xdebug: OFF';

        btnToggle.addEventListener('click', function () {
            isXdebugActive = !isXdebugActive;
            localStorage.setItem('app_xdebug_active', isXdebugActive);
            btnToggle.textContent = isXdebugActive ? 'Xdebug: ON' : 'Xdebug: OFF';
            btnToggle.classList.toggle('is-active', isXdebugActive);
        });

        const container = document.createElement('div');
        container.className = 'xdebug-toggle-container';
        container.appendChild(btnToggle);
        document.body.appendChild(container);
    }

    // Ajaxリクエスト用 URL取得関数
    function getAjaxUrl() {
        const base = (window.AppDebugConfig && window.AppDebugConfig.ajaxUrl)
            ? window.AppDebugConfig.ajaxUrl
            : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');

        const isXdebugActive = MANUAL_FORCE_XDEBUG ||
            hasUrlXdebug ||
            hasCookieXdebug ||
            localStorage.getItem('app_xdebug_active') === 'true';

        if (isLocal && isXdebugActive) {
            const separator = base.indexOf('?') === -1 ? '?' : '&';
            // Xdebug 3 (XDEBUG_SESSION, XDEBUG_TRIGGER) および Xdebug 2 (XDEBUG_SESSION_START) に対応
            return base + separator + 'XDEBUG_SESSION=VSCODE&XDEBUG_SESSION_START=VSCODE&XDEBUG_TRIGGER=1';
        }
        return base;
    }

    var form = document.getElementById('multi-copy-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            var items = [];
            document.querySelectorAll('.multi-copy-check:checked').forEach(function (chk) {
                var fieldKey = chk.getAttribute('data-field-key');
                var rowIndex = chk.getAttribute('data-row-index');

                var targetKey = fieldKey;
                var container = chk.closest('.acf-single-copy-box');
                if (container) {
                    var select = container.querySelector('select[name="target_field_key"]');
                    if (select) {
                        targetKey = select.value;
                    }
                }

                items.push({
                    field_key: fieldKey,
                    row_index: rowIndex,
                    target_field_key: targetKey
                });
            });
            document.getElementById('copy_items_json').value = JSON.stringify(items);
        });
    }

    // 自動入力用スクリプト
    const btnAutoInputFill = document.getElementById('btn_auto_input_fill');
    const btnAutoInputMake = document.getElementById('btn_auto_input_make');

    function getAutoInputData() {
        // 汎用テキスト用: 改行以外の空白を取り除く
        const getSanitizedText = (id) => {
            const val = document.getElementById(id)?.value || '';
            return val.replace(/[^\S\r\n]+/g, '');
        };

        // キャラクター名用: 前後の空白のみ除去して内部スペースは保持
        const getName = (id) => {
            const val = document.getElementById(id)?.value || '';
            return val.trim();
        };

        return {
            character_name: getName('auto_input_character_name'),
            // auto-input-追加必須: HTML側の入力欄を texts に追加
            texts: {
                auto_input_waza_name: getSanitizedText('auto_input_waza_name'),
                auto_input_waza: getSanitizedText('auto_input_waza'),
                auto_input_sugowaza_name: getSanitizedText('auto_input_sugowaza_name'),
                auto_input_sugowaza: getSanitizedText('auto_input_sugowaza'),
                auto_input_sugowaza_condition: getSanitizedText('auto_input_sugowaza_condition'),
                auto_input_leader: getSanitizedText('auto_input_leader'),
                auto_input_trait1: getSanitizedText('auto_input_trait1'),
                auto_input_trait2: getSanitizedText('auto_input_trait2'),
                auto_input_blessing: getSanitizedText('auto_input_blessing'),
                // 0〜4凸の入力値を配列化して取得
                auto_input_kotowaza: [0, 1, 2, 3, 4].map(i => ({
                    condition: getSanitizedText(`auto_input_kotowaza_cond_${i}`),
                    effect: getSanitizedText(`auto_input_kotowaza_effect_${i}`)
                }))
            }
        };
    }

    if (btnAutoInputFill) {
        btnAutoInputFill.addEventListener('click', function () {
            if (window.kotoIsFormSubmitting) {
                alert('現在フォームの保存処理中です。画面の切り替え・保存が完了してから実行してください。');
                return;
            }

            const data = getAutoInputData();
            const editPostId = document.getElementById('real_edit_post_id')?.value;

            if (!editPostId) {
                alert('反映先の記事（左側）が選択されていません。');
                return;
            }

            if (!confirm('現在の記事（左側）に自動入力の内容を反映してよろしいですか？\n※保存済みのデータに追記されます。画面がリロードされます。')) {
                return;
            }

            window.kotoSkipBeforeUnloadSave = true;
            btnAutoInputFill.disabled = true;
            btnAutoInputFill.textContent = '反映中...';

            jQuery.ajax({
                // 手動でXdebugを開始する場合は下記コメントを外して直接指定も可能:
                // url: ajaxurl + (ajaxurl.indexOf('?') === -1 ? '?' : '&') + 'XDEBUG_SESSION_START=VSCODE',
                url: getAjaxUrl(),
                type: 'POST',
                data: {
                    action: 'koto_update_post_from_auto_input',
                    post_id: editPostId,
                    texts: data.texts,
                },
                success: function (response) {
                    if (response.success) {
                        window.kotoSkipBeforeUnloadSave = true;
                        alert(response.data.message);
                        location.reload();
                    } else {
                        window.kotoSkipBeforeUnloadSave = false;
                        alert('エラー: ' + (response.data?.message || '通信に失敗しました'));
                        btnAutoInputFill.disabled = false;
                        btnAutoInputFill.textContent = 'これらの内容を自動入力';
                    }
                },
                error: function () {
                    window.kotoSkipBeforeUnloadSave = false;
                    alert('サーバーエラーが発生しました。');
                    btnAutoInputFill.disabled = false;
                    btnAutoInputFill.textContent = 'これらの内容を自動入力';
                }
            });
        });
    }

    if (btnAutoInputMake) {
        btnAutoInputMake.addEventListener('click', function () {
            if (window.kotoIsFormSubmitting) {
                alert('現在フォームの保存処理中です。画面の切り替え・保存が完了してから実行してください。');
                return;
            }

            const data = getAutoInputData();

            if (!confirm('入力された内容で新しい記事を作成してよろしいですか？')) {
                return;
            }

            window.kotoSkipBeforeUnloadSave = true;
            btnAutoInputMake.disabled = true;
            btnAutoInputMake.textContent = '作成中...';

            jQuery.ajax({
                // 手動でXdebugを開始する場合は下記コメントを外して直接指定も可能:
                // url: ajaxurl + (ajaxurl.indexOf('?') === -1 ? '?' : '&') + 'XDEBUG_SESSION_START=VSCODE',
                url: getAjaxUrl(),
                type: 'POST',
                data: {
                    action: 'koto_create_post_from_auto_input',
                    character_name: data.character_name,
                    texts: data.texts
                },
                success: function (response) {
                    if (response.success) {
                        window.kotoSkipBeforeUnloadSave = true;
                        alert(response.data.message);
                        window.location.href = response.data.edit_url;
                    } else {
                        window.kotoSkipBeforeUnloadSave = false;
                        alert('エラー: ' + (response.data?.message || '通信に失敗しました'));
                        btnAutoInputMake.disabled = false;
                        btnAutoInputMake.textContent = 'これらの内容を自動入力して記事を作成';
                    }
                },
                error: function () {
                    window.kotoSkipBeforeUnloadSave = false;
                    alert('サーバーエラーが発生しました。');
                    btnAutoInputMake.disabled = false;
                    btnAutoInputMake.textContent = 'これらの内容を自動入力して記事を作成';
                }
            });
        });
    }
});