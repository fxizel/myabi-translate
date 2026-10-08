const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const scripts = ['app.js', 'validation.js'].map(name => readFileSync(join(__dirname, '../../public/js', name), 'utf8'));

class Surface {
    constructor() { this.listeners = []; this.dataset = {}; this.attributes = {}; this.hidden = false; this.textContent = ''; }
    addEventListener(type, handler, capture = false) { this.listeners.push({type, handler, capture}); }
    dispatch(event, capture = false) {
        for (const listener of this.listeners) {
            if (event.stopped) break;
            if (listener.type === event.type && listener.capture === capture) listener.handler(event);
        }
    }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { delete this.attributes[name]; }
    replaceChildren(...children) { this.children = children; this.textContent = children.map(child => child.textContent).join(''); }
}

function fixture({count = 2, more = true, total = count + 2} = {}) {
    const document = new Surface();
    const window = new Surface();
    const form = new Surface();
    const rows = new Surface();
    const all = new Surface();
    const warning = new Surface();
    const selected = new Surface();
    const submit = new Surface();
    const loader = new Surface();
    const status = new Surface();
    const next = new Surface();
    const requests = [];
    const responses = [];
    const observer = {observations: 0, disconnects: 0, observe() { this.observations++; }, unobserve() {}, disconnect() { this.disconnects++; }};
    const makeRow = id => {
        const row = new Surface();
        row.id = id;
        row.checkbox = new Surface();
        row.checkbox.checked = false;
        row.checkbox.value = `version-${id}`;
        row.checkbox.matches = selector => selector.split(', ').includes('[data-select-item]');
        row.current = new Surface();
        row.current.textContent = `Value ${id}`;
        row.proposal = new Surface();
        row.proposal.dataset.diffCurrent = row.current.textContent;
        row.proposal.textContent = `Value ${id} revised`;
        row.proposal.closest = () => row;
        row.querySelector = selector => selector === '[data-current-diff]' ? row.current : null;
        return row;
    };
    const makeBody = () => {
        const body = new Surface();
        body.children = [];
        body.querySelectorAll = selector => selector === '[data-diff-current]' ? body.children.map(row => row.proposal) : [];
        Object.defineProperty(body, 'innerHTML', {set(html) {
            body.children = [...html.matchAll(/<tr data-fixture-id="(\d+)"><\/tr>/g)].map(match => makeRow(Number(match[1])));
        }});
        return body;
    };
    rows.children = Array.from({length: count}, (_, index) => makeRow(index + 1));
    rows.append = (...children) => rows.children.push(...children);
    const items = () => rows.children.map(row => row.checkbox);
    Object.defineProperty(form, 'elements', {get: () => [all, ...items()]});
    form.querySelector = selector => ({tbody: rows, '[data-select-all]': all, '[data-selection-limit]': warning})[selector] || null;
    form.querySelectorAll = selector => ({'[data-select-item]': items(), '[data-selected-count]': [selected], '[data-bulk-submit]': [submit]})[selector] || [];
    all.form = form;
    all.matches = selector => selector.split(', ').includes('[data-select-all]');
    all.checked = false;
    warning.hidden = true;
    next.href = '/validation?cursor=second';
    loader.dataset = {countLabel: ':count / :total', total: String(total), loadingLabel: 'Loading', errorLabel: 'Retry'};
    loader.querySelector = selector => ({'[data-load-status]': status, '[data-load-more]': more ? next : null})[selector] || null;
    document.body = {dataset: {}};
    document.getElementById = id => id === 'validation-form' ? form : null;
    document.querySelector = selector => selector === '[data-validation-loader]' ? loader : null;
    document.querySelectorAll = selector => selector === '[data-select-all]' ? [all] : [];
    document.createElement = tag => tag === 'tbody' ? makeBody() : new Surface();
    document.createTextNode = text => ({textContent: text});
    const context = vm.createContext({document, window, localStorage: {getItem: () => null}, setTimeout() {},
        fetch: async (url, options) => {
            requests.push({url, options});
            const result = responses.shift();
            if (result instanceof Error) throw result;
            return result;
        },
        IntersectionObserver: function(callback) { observer.callback = callback; return observer; },
    });
    window.IntersectionObserver = context.IntersectionObserver;
    scripts.forEach(source => vm.runInContext(source, context));
    const change = target => {
        const event = {type: 'change', target, stopImmediatePropagation() { this.stopped = true; }};
        form.dispatch(event, true);
        if (!event.stopped) target.dispatch(event);
        if (!event.stopped) form.dispatch(event);
    };
    return {
        rows, all, warning, selected, submit, next, status, requests, observer,
        respond(ids, nextUrl = null) {
            responses.push({ok: true, json: async () => ({html: ids.map(id => `<tr data-fixture-id="${id}"></tr>`).join(''), next_url: nextUrl})});
        },
        fail(response = new Error('Network unavailable')) { responses.push(response); },
        async load() {
            next.dispatch({type: 'click', button: 0, preventDefault() {}});
            await new Promise(resolve => setImmediate(resolve));
        },
        select(index, checked = true) { items()[index].checked = checked; change(items()[index]); },
        selectAll(checked = true) { all.checked = checked; change(all); },
    };
}

test('loading appends highlighted rows and keeps reviewed values, versions and selections intact', async () => {
    const page = fixture();
    const reviewed = page.rows.children[0];
    page.select(0);
    page.respond([3, 4]);
    await page.load();
    assert.equal(page.rows.children.length, 4);
    assert.equal(page.rows.children[0], reviewed);
    assert.equal(reviewed.proposal.textContent, 'Value 1 revised');
    assert.equal(reviewed.checkbox.value, 'version-1');
    assert.equal(reviewed.checkbox.checked, true);
    assert.equal(page.rows.children[2].proposal.children[1].textContent, ' revised');
    assert.equal(page.status.textContent, '4 / 4');
    assert.equal(page.selected.textContent, 1);
    assert.equal(page.next.hidden, true);
    assert.equal(page.requests[0].options.credentials, 'same-origin');
});

test('network failure preserves rows and a manual retry resumes the same cursor', async () => {
    const page = fixture();
    page.select(1);
    page.fail();
    await page.load();
    assert.equal(page.rows.children.length, 2);
    assert.equal(page.status.textContent, '2 / 4 · Retry');
    assert.equal(page.rows.attributes['aria-busy'], 'false');
    assert.equal(page.next.attributes['aria-disabled'], undefined);
    page.respond([3], '/validation?cursor=third');
    await page.load();
    assert.equal(page.requests[0].url, page.requests[1].url);
    assert.equal(page.rows.children[1].checkbox.checked, true);
    assert.equal(page.rows.children.length, 3);
    assert.equal(page.next.href, '/validation?cursor=third');
    assert.equal(page.observer.observations, 2);
});

test('HTTP and malformed responses leave the reviewed rows available for retry', async () => {
    for (const response of [{ok: false}, {ok: true, json: async () => ({html: null, next_url: null})}]) {
        const page = fixture();
        const reviewed = page.rows.children[0];
        page.fail(response);
        await page.load();
        assert.equal(page.rows.children[0], reviewed);
        assert.equal(page.rows.children.length, 2);
        assert.equal(page.status.textContent, '2 / 4 · Retry');
    }
});

test('exactly 500 items can be selected globally', () => {
    const page = fixture({count: 500, more: false});
    page.selectAll();
    assert.equal(page.selected.textContent, 500);
    assert.equal(page.submit.disabled, false);
    assert.equal(page.all.checked, true);
    assert.equal(page.warning.hidden, true);
});

test('the 501st item is refused while the existing 500 remain selected', () => {
    const page = fixture({count: 501, more: false});
    for (let index = 0; index < 500; index++) page.select(index);
    page.select(500);
    assert.equal(page.selected.textContent, 500);
    assert.equal(page.rows.children[500].checkbox.checked, false);
    assert.equal(page.warning.hidden, false);
    page.select(0, false);
    assert.equal(page.warning.hidden, true);
    page.select(500);
    assert.equal(page.rows.children[500].checkbox.checked, true);
    assert.equal(page.selected.textContent, 500);
});

test('global selection above 500 retains the exact previously reviewed selection', () => {
    const page = fixture({count: 502});
    page.select(3);
    page.select(501);
    page.selectAll();
    assert.deepEqual(page.rows.children.filter(row => row.checkbox.checked).map(row => row.id), [4, 502]);
    assert.equal(page.selected.textContent, 2);
    assert.equal(page.all.checked, false);
    assert.equal(page.all.indeterminate, true);
    assert.equal(page.warning.hidden, false);
    page.selectAll(false);
    assert.equal(page.selected.textContent, 0);
    assert.equal(page.submit.disabled, true);
});

test('newly loaded rows obey the same limit without changing an existing full selection', async () => {
    const page = fixture({count: 500, total: 501});
    page.selectAll();
    page.respond([501]);
    await page.load();
    assert.equal(page.all.checked, false);
    assert.equal(page.all.indeterminate, true);
    page.select(500);
    assert.equal(page.rows.children[500].checkbox.checked, false);
    assert.equal(page.selected.textContent, 500);
});

test('overlapping loading triggers fetch only once and do not duplicate rows', async () => {
    const page = fixture();
    page.respond([3, 4]);
    await Promise.all([page.load(), page.load()]);
    assert.equal(page.requests.length, 1);
    assert.equal(page.rows.children.length, 4);
});
