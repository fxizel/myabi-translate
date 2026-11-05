const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const source = ['app.js', 'proposal-screenshots.js'].map(file => readFileSync(join(__dirname, '../../public/js', file), 'utf8')).join('\n');

class Node {
    constructor() { this.listeners = []; this.dataset = {}; this.hidden = false; this.disabled = false; this.attributes = {}; this.value = ''; }
    addEventListener(type, fn, capture) { this.listeners.push({type, fn, capture: capture === true}); }
    async dispatch(event) {
        const pending = [];
        for (const listener of this.listeners.filter(item => item.type === event.type).sort((a, b) => Number(b.capture) - Number(a.capture))) {
            if (event.stopped) break;
            pending.push(listener.fn(event));
        }
        await Promise.all(pending);
    }
    dispatchEvent(event) { this.listeners.filter(item => item.type === event.type).forEach(item => item.fn(event)); return !event.defaultPrevented; }
    setAttribute(key, value) { this.attributes[key] = value; }
    removeAttribute(key) { delete this.attributes[key]; }
    insertAdjacentElement() {}
    replaceChildren() {}
    append() {}
    focus() { this.focused = true; }
    matches() { return false; }
    hasAttribute() { return false; }
}

function fixture() {
    const document = new Node(), window = new Node(), form = new Node(), widget = new Node();
    const editor = new Node(), input = new Node(), action = new Node(), preview = new Node(), paste = new Node(), error = new Node(), result = new Node(), existing = new Node();
    const draft = new Node();
    editor.value = editor.defaultValue = 'Synthetic text';
    editor.dataset = {source: '', restored: '0'};
    editor.form = form;
    input.files = [];
    action.value = 'keep';
    widget.dataset = {errorInvalid: 'Invalid', errorSize: 'Large', errorMultiple: 'Multiple', errorNetwork: 'Network', saved: 'Saved'};
    const nodes = {'[data-editor]': editor, '[data-screenshot-editor]': widget, '[data-draft-state]': draft,
        '[data-screenshot-file]': input, '[data-screenshot-action]': action, '[data-screenshot-preview]': preview,
        '[data-screenshot-existing]': existing, '[data-screenshot-error]': error, '[data-proposal-result]': result, '[data-screenshot-paste]': paste};
    form.querySelector = widget.querySelector = key => nodes[key] || null;
    form.querySelectorAll = () => [];
    form.elements = [editor, input, action];
    form.action = '/terms/1/proposals';
    widget.closest = () => form;
    document.querySelectorAll = key => key === '[data-editor]' ? [editor] : key === '[data-screenshot-editor]' ? [widget] : [];
    document.querySelector = () => null;
    document.createElement = () => new Node();
    document.body = {dataset: {}};
    let reloaded = false, calls = 0, reply = async () => ({ok: false, json: async () => ({errors: {screenshot: ['Rejected']}})});
    const revoked = [];
    window.location = {href: 'http://example.test/terms/1', reload: () => { reloaded = true; }};
    class Transfer { constructor() { this.files = []; this.items = {add: file => this.files.push(file)}; } }
    class Payload { delete() {} }
    vm.runInNewContext(source, {document, window, Event, DataTransfer: Transfer, FormData: Payload,
        URL: {createObjectURL: () => 'blob:synthetic', revokeObjectURL: url => revoked.push(url)},
        fetch: async () => { calls++; return reply(); }, localStorage: {getItem: () => null}, setTimeout: () => {}});
    const event = type => ({type, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, stopImmediatePropagation() { this.stopped = true; }});
    return {
        editor, input, action, preview, error, existing, widget, revoked,
        get calls() { return calls; }, get reloaded() { return reloaded; },
        setReply(value) { reply = value; },
        async choose(file) { input.files = [file]; await input.dispatch(event('change')); },
        async actionTo(value) { action.value = value; await action.dispatch(event('change')); },
        async paste(files) { const e = event('paste'); e.clipboardData = {items: files.map(file => ({kind: 'file', getAsFile: () => file}))}; await paste.dispatch(e); },
        async submit() { const e = event('submit'); e.target = form; await form.dispatch(e); await document.dispatch(e); return e; },
        async warns() { const e = event('beforeunload'); await window.dispatch(e); return e.defaultPrevented; },
    };
}

const png = {type: 'image/png', size: 500};

test('an image-only change is a protected draft and removal releases its preview', async () => {
    const page = fixture();
    assert.equal(await page.warns(), false);
    await page.choose(png);
    assert.equal(page.action.value, 'replace');
    assert.equal(page.preview.src, 'blob:synthetic');
    assert.equal(await page.warns(), true);
    await page.actionTo('remove');
    assert.equal(page.preview.hidden, true);
    assert.deepEqual(page.revoked, ['blob:synthetic']);
    assert.equal(await page.warns(), true);
    await page.actionTo('keep');
    assert.equal(await page.warns(), false);
});

test('paste accepts exactly one image and does not silently choose between several', async () => {
    const page = fixture();
    await page.paste([png]);
    assert.equal(page.input.files[0], png);
    assert.equal(page.action.value, 'replace');
    await page.paste([png, png]);
    assert.equal(page.error.textContent, 'Multiple');
    assert.equal(page.input.files[0], png);
});

test('invalid type and excessive size never send an image', async () => {
    const page = fixture();
    await page.choose({type: 'image/svg+xml', size: 100});
    assert.equal(page.error.textContent, 'Invalid');
    assert.equal(page.action.value, 'keep');
    await page.choose({type: 'image/png', size: 5242881});
    assert.equal(page.error.textContent, 'Large');
});

test('a rejected replacement retains the previous valid selection and preview', async () => {
    const page = fixture();
    await page.choose(png);
    await page.choose({type: 'image/png', size: 5242881});
    assert.equal(page.input.files[0], png);
    assert.equal(page.action.value, 'replace');
    assert.equal(page.preview.hidden, false);
    assert.deepEqual(page.revoked, []);
});

test('server rejection and network failures retain text, image and dirty state', async () => {
    const page = fixture();
    await page.choose(png);
    page.editor.value = 'Retained draft';
    await page.submit();
    assert.equal(page.error.textContent, 'Rejected');
    assert.equal(page.input.files[0], png);
    assert.equal(page.editor.value, 'Retained draft');
    assert.equal(await page.warns(), true);
    assert.equal(page.editor.disabled, false);
    page.setReply(async () => { throw Error('Offline'); });
    await page.submit();
    assert.equal(page.error.textContent, 'Network');
    assert.equal(page.preview.hidden, false);
    assert.equal(await page.warns(), true);
});

test('only confirmed success clears the draft and prevents a duplicate submit', async () => {
    const page = fixture();
    await page.choose(png);
    page.setReply(async () => ({ok: true, json: async () => ({id: 1, version: 2, message: 'Saved'})}));
    await page.submit();
    assert.equal(page.reloaded, true);
    assert.equal(await page.warns(), false);
    await page.submit();
    assert.equal(page.calls, 1);
});

test('an overlapping submit cannot issue a second request', async () => {
    const page = fixture();
    let finish;
    page.setReply(() => new Promise(resolve => { finish = resolve; }));
    const first = page.submit();
    await Promise.resolve();
    await page.submit();
    assert.equal(page.calls, 1);
    finish({ok: false, json: async () => ({errors: {value: ['Conflict']}})});
    await first;
    assert.equal(page.error.textContent, 'Conflict');
});

function dialogFixture() {
    const document = new Node(), window = new Node(), dialog = new Node(), large = new Node();
    const unavailable = new Node(), close = new Node(), original = new Node(), trigger = new Node();
    const toolbar = new Node();
    trigger.href = '/proposals/1/screenshot?version=1';
    trigger.getAttribute = () => 'Open synthetic screenshot';
    trigger.closest = selector => selector === '[data-screenshot-open]' ? trigger : null;
    for (const node of [dialog, large, toolbar]) node.closest = () => null;
    close.closest = original.closest = () => close;
    dialog.querySelector = key => ({'[data-screenshot-large]': large, '[data-screenshot-unavailable]': unavailable,
        '[data-screenshot-close]': close, '[data-screenshot-original]': original})[key];
    dialog.open = false;
    dialog.closeCalls = 0;
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; dialog.closeCalls++; dialog.dispatchEvent({type: 'close'}); };
    large.removeAttribute = key => { if (key === 'src') delete large.src; };
    document.querySelectorAll = () => [];
    document.querySelector = key => key === '[data-screenshot-dialog]' ? dialog : null;
    document.body = {dataset: {}};
    vm.runInNewContext(source, {document, window});
    const event = (type, target) => ({type, target, button: 0, preventDefault() {}});
    return {
        dialog, large, trigger, close, original, toolbar,
        async open() { trigger.focused = false; await document.dispatch(event('click', trigger)); },
        async click(target) { await dialog.dispatch(event('pointerdown', target)); await dialog.dispatch(event('click', target)); },
        async cross() { await close.dispatch(event('click', close)); await dialog.dispatch(event('click', close)); },
        async dragOut() { await dialog.dispatch(event('pointerdown', large)); await dialog.dispatch(event('click', dialog)); },
    };
}

test('the close control dismisses the screenshot and returns focus to its thumbnail', async () => {
    const page = dialogFixture();
    await page.open();
    assert.equal(page.dialog.open, true);
    await page.cross();
    assert.equal(page.dialog.open, false);
    assert.equal(page.dialog.closeCalls, 1);
    assert.equal(page.trigger.focused, true);
    assert.equal('src' in page.large, false);
});

test('clicks outside the image dismiss the dialog, including its backdrop and toolbar space', async () => {
    const page = dialogFixture();
    for (const target of [page.dialog, page.toolbar]) {
        await page.open();
        await page.click(target);
        assert.equal(page.dialog.open, false);
        assert.equal(page.trigger.focused, true);
    }
});

test('image clicks, original-image links and drags starting on the image keep the dialog open', async () => {
    const page = dialogFixture();
    await page.open();
    await page.click(page.large);
    assert.equal(page.dialog.open, true);
    await page.click(page.original);
    assert.equal(page.dialog.open, true);
    await page.dragOut();
    assert.equal(page.dialog.open, true);
});
