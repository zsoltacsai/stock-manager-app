// N-2 / N-3 frontend regresszió — a készletmozgatás (telephelyek.js) és a
// részleges visszáru (eladasok.js) beküldő gombja egy minimális, hamis
// DOM-on, node:vm alatt futtatva. Nincs böngésző: a tényleges kattintás-
// kezelőket hívja meg, a fetch()-et a teszt vezérli (késleltetett válasz,
// elveszett válasz, siker). Futtatás: node tests/js/frontend-idempotency.cjs
// — a kimenet egyetlen JSON-sor, hiba esetén nem nulla kilépési kód.
// A PHPUnit-oldali belépő: tests/FrontendIdempotencyKeyTest.php.
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

function makeEl(id) {
    return {
        id, value: '', textContent: '', innerHTML: '', className: '', disabled: false, checked: false,
        dataset: {}, listeners: {},
        classList: { add() {}, remove() {}, contains() { return false; } },
        addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); },
        querySelectorAll() { return []; },
        querySelector() { return makeEl('_'); },
    };
}

function deferred() {
    let resolve, reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

function jsonResponse(status, body) {
    return { ok: status >= 200 && status < 300, status, json: async () => body };
}

/** Egy friss "oldal": saját DOM, saját fetch-vezérlő, a megadott szkriptek betöltve. */
function loadPage(scripts, { immediate = () => null, qsa = {} } = {}) {
    const els = {};
    const calls = [];
    const sandbox = {
        console, setTimeout, clearTimeout, Promise, JSON, Intl, URLSearchParams, Date, Math, Number, Array, Object, String,
        crypto: globalThis.crypto,
        localStorage: { getItem() { return null; }, setItem() {}, removeItem() {} },
        confirm: () => true,
        alert: () => {},
        escapeHtml: (s) => String(s ?? ''),
        document: {
            getElementById: (id) => (els[id] ||= makeEl(id)),
            querySelectorAll: (sel) => qsa[sel] || [],
            querySelector: () => makeEl('_'),
            addEventListener() {},
            documentElement: makeEl('html'),
        },
        fetch(url, options) {
            const quick = immediate(url);
            if (quick) return Promise.resolve(quick);
            const d = deferred();
            calls.push({ url, body: options && options.body ? JSON.parse(options.body) : null, d });
            return d.promise;
        },
    };
    sandbox.window = sandbox;
    sandbox.fetchJson = async (url, options) => {
        const res = await sandbox.fetch(url, options);
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'hiba');
        return data;
    };
    vm.createContext(sandbox);
    for (const s of scripts) {
        vm.runInContext(fs.readFileSync(path.join(webroot, s), 'utf8'), sandbox, { filename: s });
    }
    return { els, calls, sandbox, run: (code) => vm.runInContext(code, sandbox) };
}

const flush = () => new Promise((r) => setTimeout(r, 0));
const click = (el) => Promise.all((el.listeners.click || []).map((fn) => fn({ currentTarget: el, target: el, preventDefault() {} })));

async function transferScenarios() {
    const immediate = (url) => (url.includes('stock-transfer.php') ? null : jsonResponse(200, { locations: [], stock: [], transfers: [] }));
    const page = loadPage(['telephelyek.js'], { immediate });
    const { els, calls } = page;
    page.run("selectedProduct = { id: 5, name: 'N2 termék' };");
    const btn = els['transfer-submit-btn'];
    const setForm = (qty) => { els['transfer-from'].value = ''; els['transfer-to'].value = '2'; els['transfer-qty'].value = String(qty); };
    const transfers = () => calls.filter((c) => c.url.includes('stock-transfer.php'));

    // 1) Dupla kattintás, függő válasz mellett: egyetlen kérés.
    setForm(3);
    const first = click(btn);
    const second = click(btn);
    await flush();
    check('transfer: dupla kattintás egyetlen kérést küld', transfers().length === 1, transfers().length);
    check('transfer: a gomb a kérés alatt letiltva', btn.disabled === true, btn.disabled);
    const key1 = transfers()[0].body.idempotency_key;
    check('transfer: a kérés idempotency_key-t visz', typeof key1 === 'string' && key1.length >= 16, key1);

    // 2) Elveszett válasz (hálózati hiba) → újraküldés UGYANAZZAL a kulccsal.
    transfers()[0].d.reject(new TypeError('Failed to fetch'));
    await Promise.all([first, second]);
    check('transfer: hiba után a gomb újra aktív', btn.disabled === false, btn.disabled);
}

async function transferRetryScenarios() {
    const immediate = (url) => (url.includes('stock-transfer.php') ? null : jsonResponse(200, { locations: [], stock: [], transfers: [] }));
    const page = loadPage(['telephelyek.js'], { immediate });
    const { els, calls } = page;
    page.run("selectedProduct = { id: 5, name: 'N2 termék' };");
    const btn = els['transfer-submit-btn'];
    const setForm = (qty) => { els['transfer-from'].value = ''; els['transfer-to'].value = '2'; els['transfer-qty'].value = String(qty); };
    const transfers = () => calls.filter((c) => c.url.includes('stock-transfer.php'));

    setForm(3);
    let pending = click(btn);
    await flush();
    const key1 = transfers()[0].body.idempotency_key;
    transfers()[0].d.reject(new TypeError('Failed to fetch')); // a válasz elveszett
    await pending;

    pending = click(btn); // újraküldés
    await flush();
    check('transfer: elveszett válasz utáni újraküldés ugyanazt a kulcsot küldi', transfers()[1].body.idempotency_key === key1, [key1, transfers()[1].body.idempotency_key]);

    transfers()[1].d.resolve(jsonResponse(409, { error: 'nincs elég készlet' })); // üzleti hiba
    await pending;
    pending = click(btn);
    await flush();
    check('transfer: üzleti hiba utáni ismétlés is ugyanaz a művelet (ugyanaz a kulcs)', transfers()[2].body.idempotency_key === key1);
    transfers()[2].d.resolve(jsonResponse(200, { ok: true, transfer_id: 77, replayed: true }));
    await pending;
    await flush();

    setForm(3);
    pending = click(btn);
    await flush();
    check('transfer: siker után a következő mozgatás ÚJ kulcsot kap', transfers()[3].body.idempotency_key !== key1);
    const key2 = transfers()[3].body.idempotency_key;
    transfers()[3].d.reject(new TypeError('Failed to fetch'));
    await pending;

    setForm(4); // a felhasználó módosította a mennyiséget → ez már másik művelet
    pending = click(btn);
    await flush();
    check('transfer: megváltozott mennyiség új kulcsot kap', transfers()[4].body.idempotency_key !== key2);
    transfers()[4].d.resolve(jsonResponse(200, { ok: true, transfer_id: 78 }));
    await pending;
    check('transfer: összesen 5 hálózati kérés, dupla kattintásból egy sem', transfers().length === 5, transfers().length);
}

async function returnScenarios() {
    const qtyInput = { dataset: { saleItemId: '11' }, value: '1' };
    const immediate = (url) => {
        if (url.includes('sale-detail.php')) {
            return jsonResponse(200, { sale: { id: 9, created_at: '2026-09-25 10:00:00', payment_method: 'Készpénz', total: 3000, items: [{ id: 11, name: 'N3', qty: 3, unit_price: 1000 }] } });
        }
        if (url.includes('sale-returns.php')) return jsonResponse(200, { returned_quantities: {}, returns: [] });
        if (url.includes('return-create.php')) return null;
        return jsonResponse(200, { sales: [] });
    };
    const page = loadPage(['eladasok.js'], { immediate, qsa: { '.return-qty-input': [qtyInput] } });
    const { els, calls } = page;
    await page.run('openDetail(9)');
    const btn = els['return-submit-btn'];
    const returnsCalls = () => calls.filter((c) => c.url.includes('return-create.php'));

    const a = click(btn);
    const b = click(btn);
    await flush();
    check('return: dupla kattintás egyetlen kérést küld', returnsCalls().length === 1, returnsCalls().length);
    const key1 = returnsCalls()[0].body.idempotency_key;
    check('return: a kérés idempotency_key-t visz', typeof key1 === 'string' && key1.length >= 16, key1);

    returnsCalls()[0].d.reject(new TypeError('Failed to fetch'));
    await Promise.all([a, b]);
    check('return: hiba után a gomb újra aktív', btn.disabled === false);
    let pending = click(btn);
    await flush();
    check('return: elveszett válasz utáni újraküldés ugyanazt a kulcsot küldi', returnsCalls()[1].body.idempotency_key === key1);
    returnsCalls()[1].d.reject(new TypeError('Failed to fetch'));
    await pending;

    qtyInput.value = '2'; // más mennyiség → más visszáru-kísérlet
    pending = click(btn);
    await flush();
    check('return: megváltozott tételek új kulcsot kapnak', returnsCalls()[2].body.idempotency_key !== key1);
    returnsCalls()[2].d.resolve(jsonResponse(200, { return_id: 5, total_refund: 2000 }));
    await pending;
}

(async () => {
    try {
        await transferScenarios();
        await transferRetryScenarios();
        await returnScenarios();
    } catch (e) {
        failed = true;
        results.push({ name: 'harness', ok: false, detail: String(e && e.stack || e) });
    }
    process.stdout.write(JSON.stringify({ ok: !failed, results }) + '\n');
    process.exit(failed ? 1 : 0);
})();
