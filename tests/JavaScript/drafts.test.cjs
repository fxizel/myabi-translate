const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = readFileSync(join(__dirname, '../../public/js/app.js'), 'utf8');

// A small event fixture for this script's form handlers; no database or browser dependencies.
class Surface {
    constructor() { this.listeners = new Map(); }
    addEventListener(type, handler) {
        if (!this.listeners.has(type)) this.listeners.set(type, []);
        this.listeners.get(type).push(handler);
    }
    dispatch(event) {
        for (const handler of this.listeners.get(event.type) || []) handler(event);
    }
}

function fixture(rows, {bulk = false, confirmedForm = false} = {}) {
    const document = new Surface();
    const window = new Surface();
    const forms = [];
    const editors = [];
    const confirmations = [];
    let confirmResult = true;
    const makeNode = () => ({dataset: {}, hidden: false, textContent: '', matches: () => false, remove() { this.removed = true; }});
    const makeForm = (confirm = false) => {
        const form = new Surface();
        form.dataset = confirm ? {confirm: 'Send :count?'} : {};
        form.elements = [];
        form.nodes = {'[data-character-count]': makeNode(), '[data-draft-state]': makeNode(), '[data-editor-error]': makeNode()};
        form.querySelector = selector => selector === '[data-editor]' ? editors.find(editor => editor.form === form) : form.nodes[selector] || null;
        form.querySelectorAll = selector => selector === '[data-copied-value]' ? form.elements.filter(el => 'copiedValue' in el.dataset && !el.removed) : [];
        form.hasAttribute = name => name === 'data-bulk-proposals' && form.isBulk;
        form.append = input => { form.elements.push(input); form.elements[input.name] = input; };
        forms.push(form);
        return form;
    };
    const bulkForm = bulk ? makeForm(true) : null;
    if (bulkForm) bulkForm.isBulk = true;
    for (const [index, row] of rows.entries()) {
        const form = makeForm(confirmedForm);
        const editor = new Surface();
        editor.form = form;
        editor.name = 'value';
        editor.value = editor.defaultValue = row.initial || '';
        editor.dataset = {source: row.reference || '', restored: row.restored ? '1' : '0', emptyError: 'Empty', tokenError: 'Tokens'};
        editor.insertAdjacentElement = () => {};
        editor.focus = () => { editor.focused = true; };
        editor.matches = () => false;
        form.elements.push(editor);
        form.elements.value = editor;
        for (const key of ['attribute', 'language', 'lock_version']) form.elements[key] = {value: key};
        editors.push(editor);
        if (bulkForm) {
            const checkbox = {
                checked: !!row.selected,
                dataset: {termId: String(index + 1)},
                matches: selector => selector === '[data-select-item]' || (selector === '[data-select-item]:checked' && checkbox.checked),
                closest: () => ({querySelector: () => form}),
            };
            bulkForm.elements.push(checkbox);
        }
    }
    document.body = {dataset: {unsentLabel: 'Unsent', longWarning: 'Long', confirm: 'Continue?'}};
    document.querySelectorAll = selector => ({
        '[data-editor]': editors,
        'form[data-confirm]': forms.filter(form => 'confirm' in form.dataset),
    })[selector] || [];
    document.querySelector = () => null;
    document.createElement = makeNode;
    window.confirm = message => { confirmations.push(message); return confirmResult; };
    vm.runInNewContext(source, {document, window, setTimeout: () => {}, localStorage: {getItem: () => null}});

    const event = (type, target) => {
        const result = new Event(type, {cancelable: true});
        Object.defineProperty(result, 'target', {value: target});
        if (type === 'beforeunload') Object.defineProperty(result, 'returnValue', {value: '', writable: true});
        return result;
    };
    return {
        editors, forms, bulkForm, confirmations,
        setConfirmation(value) { confirmResult = value; },
        change(index, value) {
            editors[index].value = value;
            editors[index].dispatch(event('input', editors[index]));
        },
        submit(form = editors[0].form, afterDocument) {
            const submission = event('submit', form);
            form.dispatch(submission);
            document.dispatch(submission);
            if (afterDocument) afterDocument(submission);
            return submission;
        },
        warns() {
            const unload = event('beforeunload', window);
            window.dispatch(unload);
            return unload.defaultPrevented;
        },
    };
}

test('unchanged values and edits reverted to the initial value are clean', () => {
    const page = fixture([{initial: 'Recorded value'}]);
    assert.equal(page.warns(), false);
    page.change(0, 'Changed');
    assert.equal(page.warns(), true);
    page.change(0, 'Recorded value');
    assert.equal(page.warns(), false);
});

test('a restored unsent value warns even without a new input event', () => {
    const page = fixture([{initial: 'Recovered draft', restored: true}]);
    assert.equal(page.warns(), true);
});

test('a restored correction is visibly dirty until submitted, including an empty rejected edit', () => {
    const correction = fixture([{initial: 'Recovered correction', restored: true}, {initial: 'Recorded proposal'}]);
    assert.equal(correction.editors[0].form.nodes['[data-draft-state]'].hidden, false);
    assert.equal(correction.warns(), true);
    assert.equal(correction.submit().defaultPrevented, false);
    assert.equal(correction.warns(), false);
    const afterSuccess = fixture([{initial: 'Recovered correction'}]);
    assert.equal(afterSuccess.warns(), false);

    const emptyCorrection = fixture([{initial: '', restored: true}]);
    assert.equal(emptyCorrection.warns(), true);
    assert.equal(emptyCorrection.submit().defaultPrevented, true);
    assert.equal(emptyCorrection.warns(), true);
});

test('sending one row warns about the other row and preserves both values', () => {
    const page = fixture([{}, {}]);
    page.change(0, 'First draft');
    page.change(1, 'Second draft');
    assert.equal(page.submit().defaultPrevented, false);
    assert.equal(page.warns(), true);
    assert.deepEqual(page.editors.map(editor => editor.value), ['First draft', 'Second draft']);
});

test('sending the only dirty row does not warn about its submitted value', () => {
    const page = fixture([{}, {initial: 'Unchanged'}]);
    page.change(0, 'Submitted');
    page.submit();
    assert.equal(page.warns(), false);
});

test('an invalid proposal retains the draft warning', () => {
    const page = fixture([{reference: 'Value {name}'}]);
    page.change(0, 'Missing token');
    assert.equal(page.submit().defaultPrevented, true);
    assert.equal(page.warns(), true);
});

test('cancelling a form confirmation retains all drafts', () => {
    const page = fixture([{}], {confirmedForm: true});
    page.change(0, 'Unsent');
    page.setConfirmation(false);
    assert.equal(page.submit().defaultPrevented, true);
    assert.equal(page.warns(), true);
});

test('a later listener cancelling submission retains the draft warning', () => {
    const page = fixture([{}]);
    page.change(0, 'Unsent');
    page.submit(undefined, event => event.preventDefault());
    assert.equal(page.warns(), true);
});

test('a cancelled navigation restores warnings for every draft', () => {
    const page = fixture([{}, {}]);
    page.change(0, 'First');
    page.change(1, 'Second');
    page.submit();
    assert.equal(page.warns(), true);
    assert.equal(page.warns(), true);
    // Simulate staying on the page, then reverting the other row and navigating again.
    page.change(1, '');
    assert.equal(page.warns(), true);
});

test('cancelling the navigation after a bulk submission still protects the selected drafts', () => {
    const page = fixture([{selected: true}, {}], {bulk: true});
    page.change(0, 'Selected');
    page.change(1, 'Not selected');
    page.submit(page.bulkForm);
    assert.equal(page.warns(), true);
    page.change(1, '');
    assert.equal(page.warns(), true);
    assert.equal(page.editors[0].value, 'Selected');
});

test('bulk submission copies selected values but warns for unselected drafts', () => {
    const page = fixture([{selected: true}, {}], {bulk: true});
    page.change(0, 'Selected');
    page.change(1, 'Not selected');
    page.submit(page.bulkForm);
    assert.equal(page.bulkForm.elements['rows[1][value]'].value, 'Selected');
    assert.equal(page.bulkForm.elements['rows[2][value]'], undefined);
    assert.equal(page.warns(), true);
});

test('a confirmed bulk submission of all dirty rows needs no draft warning', () => {
    const page = fixture([{selected: true}, {selected: true}], {bulk: true});
    page.change(0, 'First');
    page.change(1, 'Second');
    page.submit(page.bulkForm);
    assert.equal(page.warns(), false);
});

test('cancelling bulk confirmation retains every draft and copies no payload', () => {
    const page = fixture([{selected: true}, {}], {bulk: true});
    page.change(0, 'Selected');
    page.change(1, 'Not selected');
    page.setConfirmation(false);
    assert.equal(page.submit(page.bulkForm).defaultPrevented, true);
    assert.equal(page.bulkForm.elements['rows[1][value]'], undefined);
    assert.equal(page.warns(), true);
});

test('editing a value after submission does not exempt the new value', () => {
    const page = fixture([{}]);
    page.change(0, 'Submitted');
    page.submit();
    page.change(0, 'New draft');
    assert.equal(page.warns(), true);
});
