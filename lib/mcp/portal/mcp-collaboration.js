(function () {
    'use strict';

    function copyTextToClipboard(text, btn) {
        if (!text) return;
        var original = btn.textContent;
        var markCopied = function () {
            btn.textContent = 'コピーしました';
            setTimeout(function () {
                btn.textContent = original;
            }, 1800);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(markCopied).catch(function () {
                fallbackCopy(text, markCopied);
            });
        } else {
            fallbackCopy(text, markCopied);
        }
    }

    function fallbackCopy(text, cb) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.className = 'koto-collab-hp-field';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            cb();
        } catch (e) {
            // ignore
        }
        document.body.removeChild(ta);
    }

    function initPortal() {
        var portal = document.querySelector('.koto-collab-portal');
        if (!portal) return;

        var dataEl = document.getElementById('koto-ai-guide-data');
        var guides = {};
        if (dataEl) {
            try {
                guides = JSON.parse(dataEl.textContent || '{}');
            } catch (e) {
                guides = {};
            }
        }

        // 1クリックコピーボタン
        portal.addEventListener('click', function (ev) {
            var copyBtn = ev.target.closest('.koto-copy-btn');
            if (!copyBtn) return;
            var targetId = copyBtn.getAttribute('data-copy-target');
            if (targetId) {
                var el = document.getElementById(targetId);
                if (el) {
                    copyTextToClipboard(el.textContent || '', copyBtn);
                }
                return;
            }
            var rawText = copyBtn.getAttribute('data-copy-text');
            if (rawText) {
                copyTextToClipboard(rawText, copyBtn);
            }
        });

        var catButtons = portal.querySelectorAll('.koto-ai-cat-btn');
        var toolButtons = portal.querySelectorAll('.koto-ai-tool-btn');
        var titleEl = document.getElementById('koto-ai-guide-title');
        var badgeEl = document.getElementById('koto-ai-guide-badge');
        var summaryEl = document.getElementById('koto-ai-guide-summary');
        var stepsEl = document.getElementById('koto-ai-guide-steps');
        var snippetWrapEl = document.getElementById('koto-ai-guide-snippet-wrap');
        var snippetLabelEl = document.getElementById('koto-ai-guide-snippet-label');
        var snippetCodeEl = document.getElementById('koto-ai-guide-snippet-code');

        function renderTool(toolId) {
            var item = guides[toolId];
            if (!item) return;

            toolButtons.forEach(function (btn) {
                btn.classList.toggle('is-active', btn.getAttribute('data-tool') === toolId);
            });

            if (titleEl) titleEl.textContent = item.title || '';
            if (badgeEl) {
                badgeEl.textContent = item.badge || '';
                badgeEl.classList.toggle('koto-ai-badge-warn', !!item.unsupported);
            }
            if (summaryEl) summaryEl.textContent = item.summary || '';

            if (stepsEl) {
                stepsEl.innerHTML = '';
                (item.steps || []).forEach(function (step) {
                    var li = document.createElement('li');
                    li.textContent = step;
                    stepsEl.appendChild(li);
                });
            }

            if (snippetWrapEl && snippetCodeEl && snippetLabelEl) {
                if (item.snippet) {
                    snippetWrapEl.hidden = false;
                    snippetLabelEl.textContent = item.snippet_label || '設定例・コマンド';
                    snippetCodeEl.textContent = item.snippet;
                } else {
                    snippetWrapEl.hidden = true;
                    snippetCodeEl.textContent = '';
                }
            }
        }

        catButtons.forEach(function (catBtn) {
            catBtn.addEventListener('click', function () {
                var cat = catBtn.getAttribute('data-category') || 'all';
                catButtons.forEach(function (b) {
                    b.classList.toggle('is-active', b === catBtn);
                });

                var firstVisible = null;
                var activeStillVisible = false;
                toolButtons.forEach(function (tBtn) {
                    var tCat = tBtn.getAttribute('data-category') || '';
                    var show = (cat === 'all' || tCat === cat);
                    tBtn.classList.toggle('is-hidden', !show);
                    if (show && !firstVisible) {
                        firstVisible = tBtn.getAttribute('data-tool');
                    }
                    if (show && tBtn.classList.contains('is-active')) {
                        activeStillVisible = true;
                    }
                });

                if (!activeStillVisible && firstVisible) {
                    renderTool(firstVisible);
                }
            });
        });

        toolButtons.forEach(function (toolBtn) {
            toolBtn.addEventListener('click', function () {
                var toolId = toolBtn.getAttribute('data-tool');
                if (toolId) {
                    renderTool(toolId);
                }
            });
        });

        renderTool('chatgpt_mcp');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPortal);
    } else {
        initPortal();
    }
})();
