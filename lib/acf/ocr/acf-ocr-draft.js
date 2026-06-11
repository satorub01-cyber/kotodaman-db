(function () {
    const config = window.KOTO_OCR_DRAFT_CONFIG;
    if (!config) return;

    const panel = document.querySelector('[data-koto-ocr-panel]');
    if (!panel) return;

    const toggle = panel.querySelector('[data-koto-ocr-toggle]');
    const body = panel.querySelector('[data-koto-ocr-body]');
    const input = panel.querySelector('[data-koto-ocr-input]');
    const dropzone = panel.querySelector('[data-koto-ocr-dropzone]');
    const preview = panel.querySelector('[data-koto-ocr-preview]');
    const submit = panel.querySelector('[data-koto-ocr-submit]');
    const spinner = panel.querySelector('[data-koto-ocr-spinner]');
    const status = panel.querySelector('[data-koto-ocr-status]');
    const result = panel.querySelector('[data-koto-ocr-result]');
    let selectedFiles = [];
    let objectUrls = [];
    let hasCreatedDraft = false;
    let timer = null;
    let startedAt = 0;

    toggle.addEventListener('click', () => setOpen(body.hidden));
    input.addEventListener('change', () => setFiles(Array.from(input.files || [])));

    ['dragenter', 'dragover'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.remove('is-dragover');
        });
    });
    dropzone.addEventListener('drop', (event) => setFiles(Array.from(event.dataTransfer.files || [])));
    submit.addEventListener('click', runOcr);

    function setOpen(open) {
        body.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function setFiles(files) {
        revokeObjectUrls();
        selectedFiles = files.filter((file) => config.allowedMimeTypes.includes(file.type));
        if (files.length !== selectedFiles.length) {
            renderError('PNG/JPEG/WebP以外のファイルは除外しました。');
        } else {
            result.innerHTML = '';
        }
        renderPreview();
    }

    function renderPreview() {
        preview.innerHTML = '';
        selectedFiles.forEach((file) => {
            const url = URL.createObjectURL(file);
            objectUrls.push(url);
            const item = document.createElement('a');
            item.className = 'koto-ocr-thumb';
            item.href = url;
            item.target = '_blank';
            item.rel = 'noopener';
            item.innerHTML = `<img alt="" src="${url}"><span>${escapeHtml(file.name)} (${formatBytes(file.size)})</span>`;
            preview.appendChild(item);
        });
    }

    async function runOcr() {
        if (!config.hasApiKey) return renderError('OpenRouter APIキーが未設定です。');
        if (!selectedFiles.length) return renderError('画像を選択してください。');
        if (selectedFiles.length > config.maxImages) return renderError(`画像は一度に${config.maxImages}枚までです。`);
        if (hasCreatedDraft && !window.confirm('新しい下書きが追加で作成されます。続行しますか？')) return;

        try {
            setBusy(true);
            const prepared = [];
            for (const file of selectedFiles) {
                prepared.push(await resizeIfNeeded(file));
            }
            const formData = new FormData();
            formData.append('action', config.action);
            formData.append('nonce', config.nonce);
            prepared.forEach((file) => formData.append('images[]', file, file.name));
            const response = await fetch(config.ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
            const text = await response.text();
            let json;
            try {
                json = JSON.parse(text);
            } catch (parseError) {
                throw new Error(`サーバーがJSON以外の応答を返しました。HTTP ${response.status}: ${text.slice(0, 120)}`);
            }
            if (!json.success) throw new Error(json.data && json.data.message ? json.data.message : 'OCR実行に失敗しました。');
            hasCreatedDraft = true;
            setOpen(true);
            renderSuccess(json.data);
        } catch (error) {
            renderError(error.message || String(error));
        } finally {
            setBusy(false);
        }
    }

    async function resizeIfNeeded(file) {
        if (file.size <= config.maxImageBytes) return file;
        const bitmap = await createImageBitmap(file);
        const steps = [1800, 1440, 1200, 960, 720];
        const qualities = [0.86, 0.76, 0.66, 0.56, 0.46];
        for (const maxSide of steps) {
            const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(bitmap.width * scale));
            canvas.height = Math.max(1, Math.round(bitmap.height * scale));
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            for (const quality of qualities) {
                const webp = await canvasToBlob(canvas, 'image/webp', quality);
                if (webp && webp.size <= config.maxImageBytes) return new File([webp], replaceExt(file.name, 'webp'), { type: 'image/webp' });
                // iOS Safariはcanvas.toBlob('image/webp')がnullになるためJPEGへfallbackする。
                const jpeg = await canvasToBlob(canvas, 'image/jpeg', quality);
                if (jpeg && jpeg.size <= config.maxImageBytes) return new File([jpeg], replaceExt(file.name, 'jpg'), { type: 'image/jpeg' });
            }
        }
        throw new Error(`${file.name} を上限以下に縮小できませんでした。`);
    }

    function canvasToBlob(canvas, type, quality) {
        return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
    }

    function renderSuccess(data) {
        const warnings = (data.warnings || []).map((warning) => `<li><strong>${escapeHtml(warning.field || '')}</strong>: ${escapeHtml(warning.message || '')}</li>`).join('');
        const rawText = (data.rawText || []).map((item) => `<details><summary>${escapeHtml(item.source_image || '')} OCR raw text</summary><pre>${escapeHtml(item.text || '')}</pre></details>`).join('');
        result.innerHTML = `<div class="notice notice-success inline"><p><strong>下書きを作成しました:</strong> ${escapeHtml(data.title || '')} (#${data.postId})</p><p><a class="button" target="_blank" rel="noopener" href="${escapeAttr(data.links.dbEditor || '#')}">DBエディタで開く</a> <a class="button" target="_blank" rel="noopener" href="${escapeAttr(data.links.editPost || '#')}">投稿編集で開く</a></p></div>${warnings ? `<div class="notice notice-warning inline"><ul>${warnings}</ul></div>` : ''}${rawText}${data.debug ? '<p><button type="button" class="button" data-koto-ocr-copy-debug>debug JSONをコピー</button></p>' : ''}`;
        const copy = result.querySelector('[data-koto-ocr-copy-debug]');
        if (copy) copy.addEventListener('click', () => navigator.clipboard.writeText(JSON.stringify(data.debug, null, 2)));
    }

    function renderError(message) {
        result.innerHTML = `<div class="notice notice-error inline"><p>${escapeHtml(message)}</p></div>`;
    }

    function setBusy(busy) {
        submit.disabled = busy || !config.hasApiKey;
        spinner.classList.toggle('is-active', busy);
        if (busy) {
            startedAt = Date.now();
            status.textContent = `0/${config.timeoutSeconds}秒`;
            timer = window.setInterval(() => {
                const elapsed = Math.floor((Date.now() - startedAt) / 1000);
                status.textContent = `${elapsed}/${config.timeoutSeconds}秒`;
            }, 1000);
        } else {
            window.clearInterval(timer);
            timer = null;
            status.textContent = '';
        }
    }

    function revokeObjectUrls() {
        objectUrls.forEach((url) => URL.revokeObjectURL(url));
        objectUrls = [];
    }

    function replaceExt(name, ext) {
        return name.replace(/\.[^.]+$/, '') + '.' + ext;
    }

    function formatBytes(bytes) {
        return `${Math.round(bytes / 1024)}KB`;
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[char]));
    }

    function escapeAttr(value) {
        return escapeHtml(value || '#');
    }
})();
