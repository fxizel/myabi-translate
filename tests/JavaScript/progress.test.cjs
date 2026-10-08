const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = readFileSync(join(__dirname, '../../public/js/app.js'), 'utf8');

class Surface {
    addEventListener() {}
}

function fixture({progress = true, draft = false} = {}) {
    const document = new Surface();
    const window = new Surface();
    const timers = [];
    let reloads = 0;
    const selection = {tagName: 'INPUT', type: 'checkbox', checked: false, defaultChecked: false};
    const reason = {tagName: 'INPUT', type: 'text', value: '', defaultValue: ''};
    // HTML selects the first option implicitly when none has a selected attribute.
    const filter = {
        tagName: 'SELECT', multiple: false, selectedIndex: 0,
        options: [
            {selected: true, defaultSelected: false, disabled: false},
            {selected: false, defaultSelected: false, disabled: false},
        ],
    };
    const fields = [selection, reason, filter];
    const editors = [];
    if (draft) {
        const form = new Surface();
        form.querySelector = () => null;
        const editor = new Surface();
        Object.assign(editor, {
            form, tagName: 'TEXTAREA', value: 'Recovered draft', defaultValue: 'Recovered draft',
            dataset: {restored: '1'}, insertAdjacentElement() {},
        });
        fields.push(editor);
        editors.push(editor);
    }
    document.body = {dataset: {}};
    document.hidden = false;
    document.querySelector = selector => selector === '[data-refresh-progress]' && progress ? {} : null;
    document.querySelectorAll = selector => ({
        '[data-editor]': editors,
        'form input:not([type="hidden"]), form textarea, form select': fields,
    })[selector] || [];
    document.createElement = () => ({dataset: {}});
    window.location = {reload() { reloads++; }};
    vm.runInNewContext(source, {
        document, window, localStorage: {getItem: () => null},
        setTimeout(callback) { timers.push(callback); },
    });

    return {
        selection, reason, filter,
        tick() { timers.splice(0).forEach(callback => callback()); },
        get reloads() { return reloads; },
    };
}

test('active progress refreshes an unchanged page with an implicit first select option', () => {
    const page = fixture();
    page.tick();
    assert.equal(page.reloads, 1);
});

test('a page without active progress does not refresh', () => {
    const page = fixture({progress: false});
    page.tick();
    assert.equal(page.reloads, 0);
});

test('progress refresh preserves a newly checked proposal selection', () => {
    const page = fixture();
    page.selection.checked = true;
    page.tick();
    assert.equal(page.reloads, 0);
});

test('progress refresh preserves a rejection reason being entered', () => {
    const page = fixture();
    page.reason.value = 'Review the wording';
    page.tick();
    assert.equal(page.reloads, 0);
});

test('progress refresh preserves a changed filter selection', () => {
    const page = fixture();
    page.filter.options[0].selected = false;
    page.filter.options[1].selected = true;
    page.filter.selectedIndex = 1;
    page.tick();
    assert.equal(page.reloads, 0);
});

test('progress refresh preserves a restored draft even without a new input event', () => {
    const page = fixture({draft: true});
    page.tick();
    assert.equal(page.reloads, 0);
});
