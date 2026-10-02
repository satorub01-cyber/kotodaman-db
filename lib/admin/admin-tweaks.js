/**
 * 管理画面UI調整 & ACFエディタ補助スクリプト
 *
 * - ACFリピーターフィールドへの「この行を複製」ボタン動的追加
 * - キーボードショートカット:
 *   - Ctrl + Shift + Alt + D: アクティブな行の削除
 *   - Ctrl + Shift + Alt + T: アクティブな行の先頭入力項目へスクロール・フォーカス
 */

(function($) {
    'use strict';

    // 1. ACFリピーター「この行を複製」ボタンの追加
    $(document).ready(function() {
        var duplicateBtnHtml = '<a href="#" class="my-acf-duplicate-bottom" data-event="duplicate-row">この行を複製</a>';

        function appendDuplicateButtons() {
            $('.acf-repeater .acf-row').each(function() {
                var $row = $(this);
                var $fields = $row.children('.acf-fields');

                if ($fields.length > 0 && $fields.find('> .my-acf-duplicate-bottom').length === 0) {
                    $fields.append(duplicateBtnHtml);
                }
            });
        }

        setTimeout(appendDuplicateButtons, 500);
        if (window.acf) {
            acf.addAction('append', function($el) {
                setTimeout(appendDuplicateButtons, 100);
            });
        }
    });

    // 2. ACFショートカットキー操作
    document.addEventListener('keydown', function(e) {
        var activeEl = document.activeElement;
        if (!activeEl) return;

        var isInputTarget = ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON', 'A', 'DIV'].includes(activeEl.tagName);
        var isInsideRow = activeEl.closest('.acf-row') !== null;

        if (!isInputTarget && !isInsideRow) return;

        // Ctrl + Shift + Alt + D: 行削除
        if (e.ctrlKey && e.shiftKey && e.altKey && e.code === 'KeyD') {
            e.preventDefault();
            var row = activeEl ? activeEl.closest('.acf-row') : null;
            if (row) {
                var deleteBtn = row.querySelector('.acf-row-handle .acf-icon.-minus');
                if (deleteBtn) {
                    deleteBtn.click();
                    setTimeout(function() {
                        var confirmBtn = row.querySelector('.acf-row-handle .acf-icon.-minus.-confirm');
                        if (!confirmBtn && deleteBtn.classList.contains('-confirm')) {
                            confirmBtn = deleteBtn;
                        }
                        if (confirmBtn) confirmBtn.click();
                    }, 100);
                }
            }
        }

        // Ctrl + Shift + Alt + T: 行の先頭要素へ移動
        if (e.ctrlKey && e.shiftKey && e.altKey && e.code === 'KeyT') {
            e.preventDefault();
            var current = activeEl;
            var topRow = null;

            while (current && current.parentElement) {
                var rowElem = current.closest('.acf-row');
                if (rowElem) {
                    topRow = rowElem;
                    current = rowElem.parentElement;
                } else {
                    break;
                }
            }

            if (topRow) {
                var inputs = topRow.querySelectorAll('.acf-accordion-title, button:not([disabled]), div[tabindex], input:not([type="hidden"]):not([disabled]):not([readonly]), select:not([disabled]):not([readonly]), textarea:not([disabled]):not([readonly])');
                var targetInput = null;

                for (var i = 0; i < inputs.length; i++) {
                    if (inputs[i].offsetWidth > 0 || inputs[i].offsetHeight > 0) {
                        targetInput = inputs[i];
                        break;
                    }
                }

                if (targetInput) {
                    if (!['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON'].includes(targetInput.tagName) && !targetInput.hasAttribute('tabindex')) {
                        targetInput.setAttribute('tabindex', '-1');
                    }
                    targetInput.focus();
                    targetInput.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }
            }
        }
    });
})(jQuery);
