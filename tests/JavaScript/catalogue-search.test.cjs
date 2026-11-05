const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const source = readFileSync(join(__dirname, '../../public/js/catalogue-search.js'), 'utf8');
function fixture() {
    const control = properties => ({listeners:{}, addEventListener(type, fn) { this.listeners[type] = fn; }, ...properties});
    const option = control({checked:false});
    const mode = control({value:'prefix'});
    const query = control({value:''});
    const sort = control({disabled:false});
    const help = {dataset:{usual:'usual',approximate:'approximate',short:'short',quoted:'quoted'}};
    const recent = {dataset:{usualLabel:'recent',relevanceLabel:'relevance'}};
    const form = {elements:{approximate:option,search_mode:mode,q:query,sort}, querySelector:selector=>selector==='[data-search-help]' ? help : recent};
    vm.runInNewContext(source, {document:{querySelectorAll:()=>[form]}});
    return {option,mode,query,sort,help};
}
test('mode changes are exclusive and returning to text does not reactivate approximation', () => {
    const f=fixture();
    assert.equal(f.option.checked,false);
    f.option.checked=true; f.option.listeners.change();
    assert.equal(f.mode.value,'text'); assert.equal(f.sort.disabled,true);
    f.mode.value='regex'; f.mode.listeners.change();
    assert.equal(f.option.checked,false);
    f.mode.value='text'; f.mode.listeners.change();
    assert.equal(f.option.checked,false); assert.equal(f.sort.disabled,false);
});
test('Unicode character counts ignore spaces and quotes impose exact search help', () => {
    const f=fixture(); f.option.checked=true; f.option.listeners.change();
    f.query.value='é 日'; f.query.listeners.input(); assert.equal(f.help.textContent,'short');
    f.query.value='é 日本'; f.query.listeners.input(); assert.equal(f.help.textContent,'approximate');
    f.query.value='"dosier"'; f.query.listeners.input(); assert.equal(f.help.textContent,'quoted');
    assert.equal(f.option.checked,true);
});
