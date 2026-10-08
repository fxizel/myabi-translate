(() => {
    'use strict';
    const loader = document.querySelector('[data-validation-loader]');
    const form = document.getElementById('validation-form');
    if(!loader || !form) return;

    const rows = form.querySelector('tbody');
    const all = form.querySelector('[data-select-all]');
    const selectionWarning = form.querySelector('[data-selection-limit]');
    const status = loader.querySelector('[data-load-status]');
    const more = loader.querySelector('[data-load-more]');
    const items = () => [...form.querySelectorAll('[data-select-item]')];
    const formatCount = count => String(count).replace(/\B(?=(\d{3})+(?!\d))/g, '’');
    const countLabel = () => loader.dataset.countLabel
        .replace(':count', formatCount(items().length))
        .replace(':total', formatCount(loader.dataset.total));
    const refreshSelection = () => {
        const checkboxes = items();
        const count = checkboxes.filter(checkbox => checkbox.checked).length;
        form.querySelectorAll('[data-selected-count]').forEach(element => element.textContent = count);
        form.querySelectorAll('[data-bulk-submit]').forEach(button => button.disabled = count === 0);
        all.indeterminate = count > 0 && count < checkboxes.length;
        all.checked = checkboxes.length > 0 && count === checkboxes.length;
    };

    // app.js handles the original rows; delegation also covers rows added later.
    form.addEventListener('change', event => {
        if(!event.target.matches('[data-select-item], [data-select-all]')) return;
        if(event.target.matches('[data-select-item]') && items().filter(checkbox => checkbox.checked).length > 500) {
            event.target.checked = false;
            selectionWarning.hidden = false;
        } else {
            selectionWarning.hidden = true;
        }
        refreshSelection();
    });
    form.addEventListener('change', event => {
        if(event.target === all && all.checked && items().length > 500) {
            // Keep the existing selection rather than silently choosing a subset.
            event.stopImmediatePropagation();
            selectionWarning.hidden = false;
            refreshSelection();
        }
    }, true);

    const highlightChanges = container => {
        container.querySelectorAll('[data-diff-current]').forEach(element => {
            const before = element.dataset.diffCurrent || '';
            const after = element.textContent;
            if(!before || before === after) return;
            let prefix = 0; let suffix = 0;
            while(prefix < before.length && prefix < after.length && before[prefix] === after[prefix]) prefix++;
            while(suffix < before.length - prefix && suffix < after.length - prefix && before[before.length - 1 - suffix] === after[after.length - 1 - suffix]) suffix++;
            const inserted = document.createElement('ins');
            inserted.textContent = after.slice(prefix, after.length - suffix);
            element.replaceChildren(document.createTextNode(after.slice(0, prefix)), inserted, document.createTextNode(suffix ? after.slice(-suffix) : ''));
            const current = element.closest('tr')?.querySelector('[data-current-diff]');
            if(current) {
                const removed = document.createElement('del');
                removed.textContent = before.slice(prefix, before.length - suffix);
                current.replaceChildren(document.createTextNode(before.slice(0, prefix)), removed, document.createTextNode(suffix ? before.slice(-suffix) : ''));
            }
        });
    };

    if(!more) return;
    let loading = false;
    let observer = null;
    const loadMore = async () => {
        if(loading || more.hidden) return;
        loading = true;
        more.setAttribute('aria-disabled', 'true');
        rows.setAttribute('aria-busy', 'true');
        status.textContent = `${countLabel()} · ${loader.dataset.loadingLabel}`;
        try {
            const response = await fetch(more.href, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            if(!response.ok) throw new Error('Loading failed');
            const data = await response.json();
            if(typeof data.html !== 'string' || (data.next_url !== null && typeof data.next_url !== 'string')) throw new Error('Invalid response');
            const incoming = document.createElement('tbody');
            incoming.innerHTML = data.html;
            highlightChanges(incoming);
            rows.append(...incoming.children);
            refreshSelection();
            status.textContent = countLabel();
            if(data.next_url) {
                more.href = data.next_url;
                // Recheck visibility if the next batch does not fill the viewport.
                observer?.unobserve(loader);
                observer?.observe(loader);
            } else {
                more.hidden = true;
                observer?.disconnect();
            }
        } catch {
            status.textContent = `${countLabel()} · ${loader.dataset.errorLabel}`;
            observer?.disconnect();
        } finally {
            loading = false;
            more.removeAttribute('aria-disabled');
            rows.setAttribute('aria-busy', 'false');
        }
    };
    more.addEventListener('click', event => {
        if(event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        event.preventDefault();
        loadMore();
    });
    if('IntersectionObserver' in window) {
        observer = new IntersectionObserver(entries => {
            if(entries.some(entry => entry.isIntersecting)) loadMore();
        }, {rootMargin: '400px'});
        observer.observe(loader);
    }
})();
