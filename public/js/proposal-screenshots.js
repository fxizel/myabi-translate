(() => {
    'use strict';
    const maxBytes = 5242880;
    document.querySelectorAll('[data-screenshot-editor]').forEach(widget => {
        const form = widget.closest('form');
        const input = widget.querySelector('[data-screenshot-file]');
        const action = widget.querySelector('[data-screenshot-action]');
        const preview = widget.querySelector('[data-screenshot-preview]');
        const existing = widget.querySelector('[data-screenshot-existing]');
        const error = widget.querySelector('[data-screenshot-error]');
        const result = widget.querySelector('[data-proposal-result]');
        const editor = form.querySelector('[data-editor]');
        let previewUrl = null;
        let selectedFile = null;
        let submitting = false;
        let saved = false;
        const release = () => {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = null;
            preview.removeAttribute('src');
            preview.hidden = true;
        };
        const refresh = () => {
            widget.dataset.dirty = action.value !== 'keep' ? '1' : '0';
            if (existing) existing.hidden = action.value !== 'keep';
            editor.dispatchEvent(new Event('input', {bubbles: true}));
        };
        const choose = file => {
            if (!file) return;
            if (!['image/png', 'image/jpeg'].includes(file.type) || file.size < 1 || file.size > maxBytes) {
                error.textContent = file.size > maxBytes ? widget.dataset.errorSize : widget.dataset.errorInvalid;
                if (selectedFile) {
                    const transfer = new DataTransfer();
                    transfer.items.add(selectedFile);
                    input.files = transfer.files;
                } else input.value = '';
                return;
            }
            release();
            selectedFile = file;
            previewUrl = URL.createObjectURL(file);
            preview.src = previewUrl;
            preview.hidden = false;
            action.value = 'replace';
            error.textContent = '';
            refresh();
        };
        input.addEventListener('change', () => {
            if (input.files.length) choose(input.files[0]);
            else { selectedFile = null; release(); action.value = 'keep'; refresh(); }
        });
        action.addEventListener('change', () => {
            if (action.value !== 'replace') { selectedFile = null; input.value = ''; release(); }
            error.textContent = '';
            refresh();
        });
        widget.querySelector('[data-screenshot-paste]').addEventListener('paste', event => {
            const files = [...(event.clipboardData?.items || [])].filter(item => item.kind === 'file').map(item => item.getAsFile()).filter(Boolean);
            if (!files.length) return;
            event.preventDefault();
            if (files.length !== 1) { error.textContent = widget.dataset.errorMultiple; return; }
            const transfer = new DataTransfer();
            transfer.items.add(files[0]);
            input.files = transfer.files;
            choose(files[0]);
        });
        // Capture phase prevents a second submit from running any other form handler.
        form.addEventListener('submit', event => {
            if (submitting || saved) { event.preventDefault(); event.stopImmediatePropagation(); }
        }, true);
        form.addEventListener('submit', async event => {
            if (event.defaultPrevented) return;
            event.preventDefault();
            error.textContent = '';
            const payload = new FormData(form);
            // Use the same contract for an empty file input in enhanced and native forms.
            if (!input.files.length) payload.delete('screenshot');
            const disabled = [...form.elements].map(control => [control, control.disabled]);
            disabled.forEach(([control]) => control.disabled = true);
            submitting = true;
            form.setAttribute('aria-busy', 'true');
            try {
                const response = await fetch(form.action, {method: 'POST', body: payload, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
                let data;
                try { data = await response.json(); } catch { throw new Error('Invalid response'); }
                if (!response.ok) {
                    const messages = Object.values(data.errors || {}).flat().filter(value => typeof value === 'string');
                    error.textContent = messages.join(' ') || widget.dataset.errorNetwork;
                    error.focus?.();
                    return;
                }
                if (!Number.isInteger(data.id) || !Number.isInteger(data.version) || typeof data.message !== 'string') throw new Error('Invalid response');
                saved = true;
                editor.defaultValue = editor.value;
                editor.dataset.restored = '0';
                widget.dataset.dirty = '0';
                form.querySelector('[data-draft-state]')?.setAttribute('hidden', '');
                input.value = '';
                action.value = 'keep';
                // If another form prevents navigation, this completed form must not send twice.
                result.replaceChildren();
                const reload = document.createElement('a');
                reload.href = window.location.href;
                reload.textContent = widget.dataset.saved;
                result.append(reload);
                window.location.reload();
            } catch {
                error.textContent = widget.dataset.errorNetwork;
            } finally {
                submitting = false;
                disabled.forEach(([control, wasDisabled]) => control.disabled = saved || wasDisabled);
                form.removeAttribute('aria-busy');
                if (!saved && error.textContent) error.focus();
            }
        });
        window.addEventListener('pagehide', release);
        window.addEventListener('pageshow', event => {
            if (event.persisted && input.files.length && !saved) choose(input.files[0]);
        });
    });

    const dialog = document.querySelector('[data-screenshot-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const large = dialog.querySelector('[data-screenshot-large]');
    const unavailable = dialog.querySelector('[data-screenshot-unavailable]');
    let trigger = null;
    document.addEventListener('click', event => {
        const link = event.target.closest?.('[data-screenshot-open]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        event.preventDefault();
        trigger = link;
        unavailable.hidden = true;
        large.hidden = false;
        large.src = link.href;
        large.alt = link.getAttribute('aria-label');
        dialog.querySelector('[data-screenshot-original]').href = link.href;
        dialog.showModal();
    });
    large.addEventListener('error', () => { unavailable.hidden = false; large.hidden = true; });
    dialog.querySelector('[data-screenshot-close]').addEventListener('click', () => dialog.close());
    const outsideImage = target => target !== large && !target.closest?.('a, button');
    let pointerStartedOutside = false;
    dialog.addEventListener('pointerdown', event => { pointerStartedOutside = outsideImage(event.target); });
    dialog.addEventListener('click', event => {
        // A drag starting on the image must not dismiss it when released outside.
        if (pointerStartedOutside && outsideImage(event.target)) dialog.close();
        pointerStartedOutside = false;
    });
    dialog.addEventListener('close', () => { large.removeAttribute('src'); trigger?.focus(); });
})();
