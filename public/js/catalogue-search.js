(() => {
    'use strict';
    document.querySelectorAll('[data-catalogue-search]').forEach(form => {
        const option = form.elements.approximate;
        const mode = form.elements.search_mode;
        const query = form.elements.q;
        const sort = form.elements.sort;
        const help = form.querySelector('[data-search-help]');
        const recent = form.querySelector('[data-sort-recent]');
        const update = () => {
            const text = query.value.trim();
            const quoted = text.length >= 2 && text.startsWith('"') && text.endsWith('"');
            const short = Array.from(text.replace(/\s/gu, '')).length < 3;
            help.textContent = quoted ? help.dataset.quoted : option.checked ? (text && short ? help.dataset.short : help.dataset.approximate) : help.dataset.usual;
            sort.disabled = option.checked;
            recent.textContent = option.checked ? recent.dataset.relevanceLabel : recent.dataset.usualLabel;
            if (option.checked) sort.value = 'updated';
        };
        option.addEventListener('change', () => { if (option.checked) mode.value = 'text'; update(); });
        mode.addEventListener('change', () => { if (mode.value !== 'text') option.checked = false; update(); });
        query.addEventListener('input', update);
        update();
    });
})();
