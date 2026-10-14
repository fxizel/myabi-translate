(() => {
    'use strict';
    document.querySelectorAll('[data-sortable-table]').forEach(table => {
        const body = table.tBodies[0];
        const buttons = [...table.querySelectorAll('[data-sort-column]')];
        const collator = new Intl.Collator(document.documentElement.lang || 'fr', {numeric: true, sensitivity: 'base'});
        const originalOrder = new WeakMap();
        let sequence = 0;
        let active = null;
        let direction = 1;
        const value = (row, column) => {
            const cell = row.cells[column];
            const editor = cell?.querySelector('[data-editor]');
            return editor ? editor.value : (cell?.dataset.sortValue ?? cell?.textContent ?? '').trim();
        };
        const observer = new MutationObserver(() => sort());
        const observe = () => observer.observe(body, {childList: true});
        const sort = () => {
            if (!active) return;
            const column = Number(active.dataset.sortColumn);
            const rows = [...body.rows].filter(row => row.cells.length === table.tHead.rows[0].cells.length);
            rows.forEach(row => {
                if (!originalOrder.has(row)) originalOrder.set(row, sequence++);
            });
            rows.sort((a, b) => direction * collator.compare(value(a, column), value(b, column))
                || originalOrder.get(a) - originalOrder.get(b));
            // Move existing nodes: draft values, selections and listeners survive.
            observer.disconnect();
            const fragment = document.createDocumentFragment();
            rows.forEach(row => fragment.append(row));
            body.append(fragment);
            observe();
        };
        buttons.forEach(button => {
            button.disabled = false;
            button.addEventListener('click', () => {
                direction = active === button ? -direction : 1;
                active = button;
                buttons.forEach(other => {
                    const selected = other === active;
                    other.closest('th').setAttribute('aria-sort', selected ? (direction === 1 ? 'ascending' : 'descending') : 'none');
                    other.querySelector('[data-sort-indicator]').textContent = selected ? (direction === 1 ? '↑' : '↓') : '↕';
                    other.title = selected && direction === 1 ? table.dataset.sortDesc : table.dataset.sortAsc;
                });
                sort();
            });
        });
        observe();
    });
})();
