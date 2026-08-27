document.addEventListener('DOMContentLoaded', function  () {
    var form = document.getElementById('multi-copy-form');
    if (form) {
        form.addEventListener('submit', function  (e) {
            var items = [];
            document.querySelectorAll('.multi-copy-check:checked').forEach(function  (chk) {
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
            texts: {
                auto_input_waza_name: getSanitizedText('auto_input_waza_name'),
                auto_input_waza: getSanitizedText('auto_input_waza'),
                auto_input_sugowaza_name: getSanitizedText('auto_input_sugowaza_name'),
                auto_input_sugowaza: getSanitizedText('auto_input_sugowaza'),
                auto_input_sugowaza_condition: getSanitizedText('auto_input_sugowaza_condition'),
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
        btnAutoInputFill.addEventListener('click', function  () {
            const data = getAutoInputData();
            const editPostId = document.getElementById('real_edit_post_id')?.value;

            if (!editPostId) {
                alert('反映先の記事（左側）が選択されていません。');
                return;
            }

            if (!confirm('現在の記事（左側）に自動入力の内容を反映してよろしいですか？\n※保存済みのデータに追記されます。画面がリロードされます。')) {
                return;
            }

            btnAutoInputFill.disabled = true;
            btnAutoInputFill.textContent = '反映中...';

            jQuery.ajax({
                // Xebugを開始する場合は下記をURLに追加
                // url: ajaxurl + (ajaxurl.indexOf('?') === -1 ? '?' : '&') + 'XDEBUG_SESSION_START=VSCODE',
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'koto_update_post_from_auto_input',
                    post_id: editPostId,
                    texts: data.texts,
                },
                success: function  (response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert('エラー: ' + (response.data?.message || '通信に失敗しました'));
                        btnAutoInputFill.disabled = false;
                        btnAutoInputFill.textContent = 'これらの内容を自動入力';
                    }
                },
                error: function  () {
                    alert('サーバーエラーが発生しました。');
                    btnAutoInputFill.disabled = false;
                    btnAutoInputFill.textContent = 'これらの内容を自動入力';
                }
            });
        });
    }

    if (btnAutoInputMake) {
        btnAutoInputMake.addEventListener('click', function  () {
            const data = getAutoInputData();

            if (!confirm('入力された内容で新しい記事を作成してよろしいですか？')) {
                return;
            }

            btnAutoInputMake.disabled = true;
            btnAutoInputMake.textContent = '作成中...';

            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'koto_create_post_from_auto_input',
                    character_name: data.character_name,
                    texts: data.texts
                },
                success: function  (response) {
                    if (response.success) {
                        alert(response.data.message);
                        window.location.href = response.data.edit_url;
                    } else {
                        alert('エラー: ' + (response.data?.message || '通信に失敗しました'));
                        btnAutoInputMake.disabled = false;
                        btnAutoInputMake.textContent = 'これらの内容を自動入力して記事を作成';
                    }
                },
                error: function  () {
                    alert('サーバーエラーが発生しました。');
                    btnAutoInputMake.disabled = false;
                    btnAutoInputMake.textContent = 'これらの内容を自動入力して記事を作成';
                }
            });
        });
    }
});