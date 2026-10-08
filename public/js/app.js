(() => {
    'use strict';
    const densityToggles = [...document.querySelectorAll('[data-density-toggle]')];
    let compact = true;
    try { compact = localStorage.getItem('myabi-density') !== 'comfortable'; } catch {}
    const applyDensity = () => {
        document.body.dataset.density = compact ? 'compact' : 'comfortable';
        densityToggles.forEach(button => button.setAttribute('aria-pressed', String(compact)));
    };
    applyDensity();
    densityToggles.forEach(button => button.addEventListener('click', () => {
        compact = !compact;
        applyDensity();
        try { localStorage.setItem('myabi-density', compact ? 'compact' : 'comfortable'); } catch {}
    }));
    const editors = [...document.querySelectorAll('[data-editor]')];
    const isDraft = editor => editor.value !== editor.defaultValue || editor.dataset.restored === '1';
    let submission = null;
    document.querySelectorAll('[data-auto-submit]').forEach(el => el.addEventListener('change', () => el.form.requestSubmit()));
    editors.forEach(editor => {
        const form = editor.form;
        const counter = form.querySelector('[data-character-count]');
        let state = form.querySelector('[data-draft-state]');
        if(!state) { state = document.createElement('span'); state.className = 'edit-meta draft-state'; state.dataset.draftState = ''; state.hidden = true; state.textContent = document.body.dataset.unsentLabel || ''; editor.insertAdjacentElement('afterend',state); }
        const error = form.querySelector('[data-editor-error]');
        const source = editor.dataset.source || '';
        const restored = editor.dataset.restored === '1';
        if(counter) counter.textContent = String([...editor.value].length);
        if(state && restored) state.hidden = false;
        const lengthWarning = document.createElement('p');
        lengthWarning.className = 'field-hint'; lengthWarning.hidden = true;
        lengthWarning.textContent = document.body.dataset.longWarning;
        editor.insertAdjacentElement('afterend', lengthWarning);
        const tokens = text => text.match(/\{[^{}\r\n]*\}|\[[\p{L}_][\p{L}\p{N}_. -]*\]|(?<!%)%(?!%)(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]/gu) || [];
        const counts = values => values.reduce((map, value) => (map[value] = (map[value] || 0) + 1, map), {});
        editor.addEventListener('input', () => {
            submission = null;
            if(counter) counter.textContent = String([...editor.value].length);
            if(state) state.hidden = !isDraft(editor);
            if(error) error.textContent = '';
            lengthWarning.hidden = !source.length || editor.value.length <= source.length * 2;
        });
        form.addEventListener('submit', event => {
            const required = counts(tokens(source));
            const present = counts(tokens(editor.value));
            const missing = [...new Set([...Object.keys(required),...Object.keys(present)])].filter(key => (present[key] || 0) !== (required[key] || 0));
            if(!editor.value.trim() || missing.length) {
                event.preventDefault();
                if(error) error.textContent = !editor.value.trim() ? editor.dataset.emptyError : `${editor.dataset.tokenError} ${missing.join(', ')}`;
                editor.focus();
            }
        });
        editor.addEventListener('keydown', event => {
            if((event.ctrlKey || event.metaKey) && event.key === 'Enter') { event.preventDefault(); form.requestSubmit(); }
        });
    });
    window.addEventListener('beforeunload', event => {
        const sent = submission && !submission.event.defaultPrevented ? submission.values : new Map();
        const dirty = editors.some(editor => isDraft(editor) && sent.get(editor) !== editor.value);
        // If navigation is cancelled, every draft must be protected on the next attempt.
        submission = null;
        if(dirty) { event.preventDefault(); event.returnValue = ''; }
    });
    document.querySelectorAll('[data-select-all]').forEach(all => {
        const form = all.form || document.getElementById(all.dataset.form);
        const items = () => [...form.elements].filter(el => el.matches('[data-select-item]'));
        const selected = () => items().filter(el => el.checked);
        const refresh = () => {
            const allItems = items();
            const count = selected().length;
            form.querySelectorAll('[data-selected-count]').forEach(el => el.textContent = count);
            form.querySelectorAll('[data-bulk-submit]').forEach(el => el.disabled = count === 0);
            all.indeterminate = count > 0 && count < allItems.length;
            all.checked = allItems.length > 0 && count === allItems.length;
        };
        all.addEventListener('change', () => { items().forEach(el => el.checked = all.checked); refresh(); });
        items().forEach(el => el.addEventListener('change', refresh));
        refresh();
    });
    document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
        const selected = [...form.elements].filter(el => el.matches('[data-select-item]:checked'));
        const count = selected.length;
        const message = (event.submitter?.dataset.confirm || form.dataset.confirm || document.body.dataset.confirm).replace(':count', String(count));
        if(!window.confirm(message)) { event.preventDefault(); return; }
        if(form.hasAttribute('data-bulk-proposals')) {
            form.querySelectorAll('[data-copied-value]').forEach(el => el.remove());
            for(const checkbox of selected) {
                const sourceForm = checkbox.closest('tr').querySelector('form');
                for(const key of ['attribute','language','lock_version','value']) {
                    const input = document.createElement('input');
                    input.type = 'hidden'; input.name = `rows[${checkbox.dataset.termId}][${key}]`;
                    input.value = sourceForm.elements[key].value; input.dataset.copiedValue = ''; form.append(input);
                }
            }
        }
    }));
    document.addEventListener('submit', event => {
        submission = null;
        if(event.defaultPrevented) return;
        const form = event.target;
        const values = new Map(editors.filter(editor => editor.form === form).map(editor => [editor, editor.value]));
        if(form.hasAttribute('data-bulk-proposals')) {
            for(const checkbox of [...form.elements].filter(el => el.matches('[data-select-item]:checked'))) {
                const editor = checkbox.closest('tr').querySelector('form')?.querySelector('[data-editor]');
                const copied = form.elements[`rows[${checkbox.dataset.termId}][value]`];
                if(editor && copied) values.set(editor, copied.value);
            }
        }
        // Read defaultPrevented again before unloading: a later listener may cancel submission.
        submission = {event, values};
    });
    document.querySelectorAll('[data-rejection-reason]').forEach(select => select.addEventListener('change', () => {
        const input = select.form.querySelector('[name="reason"]');
        if(input) input.required = select.value === 'reject';
    }));
    document.querySelectorAll('[data-column-toggle]').forEach(input => {
        const key = `argeabi-column-${input.dataset.columnToggle}`;
        const apply = () => document.querySelectorAll(`[data-column="${input.dataset.columnToggle}"]`).forEach(el => el.hidden = !input.checked);
        try { if(localStorage.getItem(key) !== null) input.checked = localStorage.getItem(key) === '1'; } catch {}
        apply();
        input.addEventListener('change', () => { apply(); try { localStorage.setItem(key, input.checked ? '1' : '0'); } catch {} });
    });
    const progress = document.querySelector('[data-refresh-progress]');
    const hasFormChanges = () => [...document.querySelectorAll('form input:not([type="hidden"]), form textarea, form select')].some(field => {
        if(field.tagName === 'SELECT') {
            const options = [...field.options];
            const implicitFirst = !field.multiple && !options.some(option => option.defaultSelected);
            return options.some((option, index) => option.selected !== (option.defaultSelected || (implicitFirst && index === 0)));
        }
        if(field.type === 'checkbox' || field.type === 'radio') return field.checked !== field.defaultChecked;
        return field.value !== field.defaultValue;
    });
    if(progress && !document.hidden) setTimeout(() => {
        if(!editors.some(isDraft) && !hasFormChanges()) window.location.reload();
    }, 10000);
    document.querySelectorAll('[data-diff-current]').forEach(element => {
        const before = element.dataset.diffCurrent || '';
        const after = element.textContent;
        if(!before || before === after) return;
        let prefix = 0; let suffix = 0;
        while(prefix < before.length && prefix < after.length && before[prefix] === after[prefix]) prefix++;
        while(suffix < before.length-prefix && suffix < after.length-prefix && before[before.length-1-suffix] === after[after.length-1-suffix]) suffix++;
        const inserted = document.createElement('ins'); inserted.textContent = after.slice(prefix, after.length-suffix);
        element.replaceChildren(document.createTextNode(after.slice(0,prefix)), inserted, document.createTextNode(suffix ? after.slice(-suffix) : ''));
        const current = element.closest('tr')?.querySelector('[data-current-diff]');
        if(current) {
            const removed = document.createElement('del'); removed.textContent = before.slice(prefix,before.length-suffix);
            current.replaceChildren(document.createTextNode(before.slice(0,prefix)), removed, document.createTextNode(suffix ? before.slice(-suffix) : ''));
        }
    });
})();
