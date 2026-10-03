// Phase 9 accessibility remediation (KBD-01, CRITICAL) — valódi viselkedési
// teszt a topbar.js attachSearchListboxKeyboard()-jára, node:vm alatt.
// A PHPUnit-oldali forrás-regex tesztek (AccessibilityRemediationPhase9Test)
// csak azt tudják igazolni, hogy a kulcsszavak ("ArrowDown" stb.) jelen
// vannak a fájlban — egy mutáció, ami a feltételt "false && ..."-ra cseréli,
// a stringet érintetlenül hagyja, a tesztet mégis át kellene, hogy buktassa.
// Ez a harness a TÉNYLEGES függvényt futtatja le egy minimális hamis DOM-on,
// és valódi billentyű-eseményeket szimulál. Futtatás:
// node tests/js/search-listbox-keyboard.cjs — a kimenet egyetlen JSON-sor,
// hiba esetén nem nulla kilépési kód. A PHPUnit-oldali belépő:
// tests/SearchListboxKeyboardTest.php.
'use strict';

const vm = require('vm');
const fs = require('fs');
const path = require('path');

const webroot = path.join(__dirname, '..', '..', 'webroot');
const results = [];
let failed = false;

function check(name, cond, detail) {
    results.push({ name, ok: !!cond, detail: cond ? undefined : detail });
    if (!cond) failed = true;
}

function extractFunctionSource() {
    const src = fs.readFileSync(path.join(webroot, 'topbar.js'), 'utf8');
    const m = src.match(/window\.attachSearchListboxKeyboard = function[\s\S]*?\n};/);
    if (!m) {
        throw new Error('attachSearchListboxKeyboard nem található a topbar.js-ben.');
    }
    return m[0];
}

function makeOption(id) {
    const attrs = {};
    let clicked = false;
    return {
        id,
        get: () => attrs,
        getAttribute: (k) => (k in attrs ? attrs[k] : null),
        setAttribute: (k, v) => { attrs[k] = String(v); },
        removeAttribute: (k) => { delete attrs[k]; },
        classList: {
            _set: new Set(),
            toggle(cls, on) { on ? this._set.add(cls) : this._set.delete(cls); },
            contains(cls) { return this._set.has(cls); },
        },
        click() { clicked = true; },
        wasClicked: () => clicked,
    };
}

function makeInput() {
    const attrs = {};
    const listeners = {};
    return {
        attrsRef: attrs,
        getAttribute: (k) => (k in attrs ? attrs[k] : null),
        setAttribute: (k, v) => { attrs[k] = String(v); },
        removeAttribute: (k) => { delete attrs[k]; },
        addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
        fire(type, evt) { (listeners[type] || []).forEach((fn) => fn(evt)); },
    };
}

function makeEvent(key) {
    let prevented = false;
    let stopped = false;
    return { key, preventDefault: () => { prevented = true; }, stopPropagation: () => { stopped = true; }, wasPrevented: () => prevented, wasStopped: () => stopped };
}

function run() {
    const fnSrc = extractFunctionSource();
    const sandbox = { console };
    vm.createContext(sandbox);
    vm.runInContext(`var window = {}; ${fnSrc}`, sandbox);
    const attach = sandbox.window.attachSearchListboxKeyboard;
    check('a függvény exportálva van', typeof attach === 'function', typeof attach);

    const opts = [makeOption('opt-0'), makeOption('opt-1'), makeOption('opt-2')];
    const resultsEl = {
        id: 'r',
        setAttribute: () => {},
        querySelectorAll: (sel) => (sel === '[role="option"]' ? opts : []),
        innerHTML: '',
    };
    const input = makeInput();
    let escapeCalled = false;
    const listbox = attach(input, resultsEl, { onEscape: () => { escapeCalled = true; } });

    // --- ArrowDown mozgatja a kiemelést, aria-activedescendant frissül ---
    input.fire('keydown', makeEvent('ArrowDown'));
    check('ArrowDown: az első opció lesz aktív', opts[0].classList.contains('active'), 'opt-0 nem aktív ArrowDown után');
    check('ArrowDown: aria-activedescendant az első opcióra mutat', input.getAttribute('aria-activedescendant') === 'opt-0', input.getAttribute('aria-activedescendant'));

    input.fire('keydown', makeEvent('ArrowDown'));
    check('ArrowDown (2.): a második opció lesz aktív', opts[1].classList.contains('active') && !opts[0].classList.contains('active'), 'opt-1 nem lett aktív');

    // --- ArrowUp visszafelé mozgat ---
    input.fire('keydown', makeEvent('ArrowUp'));
    check('ArrowUp: visszatér az első opcióra', opts[0].classList.contains('active'), 'ArrowUp nem állította vissza opt-0-t');

    // --- Enter a kiemelt opciót "kattintja" (nem egy másikat) ---
    input.fire('keydown', makeEvent('Enter'));
    check('Enter: a kiemelt (opt-0) opció kapja a clicket', opts[0].wasClicked() && !opts[1].wasClicked() && !opts[2].wasClicked(), 'Enter rossz (vagy semmilyen) opciót kattintott');

    // --- Escape meghívja az onEscape callbacket, nem buborékol tovább ---
    const escEvt = makeEvent('Escape');
    input.fire('keydown', escEvt);
    check('Escape: meghívja az onEscape callbacket', escapeCalled === true, 'onEscape nem futott le');
    check('Escape: stopPropagation, hogy ne zárjon be egy mögöttes modalt is', escEvt.wasStopped(), 'Escape nem hívott stopPropagation-t');

    // --- Üres találati lista esetén Enter/Arrow nem dob hibát, nem csinál semmit ---
    opts.length = 0;
    const input2 = makeInput();
    const listbox2 = attach(input2, { id: 'r2', setAttribute: () => {}, querySelectorAll: () => [] }, {});
    let threw = false;
    try {
        input2.fire('keydown', makeEvent('ArrowDown'));
        input2.fire('keydown', makeEvent('Enter'));
    } catch (e) { threw = true; }
    check('Üres lista: ArrowDown/Enter nem dob hibát', !threw, 'kivételt dobott üres listánál');

    console.log(JSON.stringify({ ok: !failed, results }));
    process.exit(failed ? 1 : 0);
}

run();
