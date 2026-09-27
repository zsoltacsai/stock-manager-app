// PERF-09 frontend regresszió — a lapozott Termékek oldal (webroot/termekek.js)
// egy minimális, hamis DOM-on, node:vm alatt: csak az aktuális oldal sorai
// kerülnek a DOM-ba, a „mind kijelölése” / tömeges művelet a TELJES szűrt
// halmazon dolgozik, a lapozás/szűrés/rendezés a szervertől kéri az adatot,
// és egy elavult (később érkező) válasz nem írja felül a frissebbet.
// Futtatás: node tests/js/catalog-pagination.cjs — a kimenet egyetlen JSON-sor.
// A PHPUnit-oldali belépő: tests/CatalogPaginationFrontendTest.php.
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
    const classes = new Set();
    let html = '';
    return {
        id, value: '', textContent: '', disabled: false, checked: false, indeterminate: false,
        dataset: {}, listeners: {}, children: [],
        get innerHTML() { return html; },
        set innerHTML(v) { html = v; this.children = []; }, // a valódi DOM-hoz hasonlóan törli a gyerekeket
        classList: {
            add: (c) => classes.add(c), remove: (...c) => c.forEach((x) => classes.delete(x)),
            contains: (c) => classes.has(c),
            toggle: (c, force) => { const on = force === undefined ? !classes.has(c) : !!force; on ? classes.add(c) : classes.delete(c); return on; },
        },
        addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); },
        appendChild(child) { this.children.push(child); return child; },
        querySelectorAll() { return []; },
        querySelector() { return makeEl('_'); },
        closest() { return null; },
    };
}

function deferred() {
    let resolve, reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

const json = (status, body) => ({ ok: status >= 200 && status < 300, status, json: async () => body });

function loadTermekek() {
    const els = {};
    const calls = [];
    const ths = ['name', 'price'].map((col) => { const th = makeEl('th-' + col); th.dataset.sort = col; return th; });
    const sandbox = {
        console, setTimeout, clearTimeout, Promise, JSON, Intl, URLSearchParams, Date, Math, Number, Array, Object, String, Set, Map,
        localStorage: { getItem() { return null; }, setItem() {}, removeItem() {} },
        confirm: () => true,
        alert: () => {},
        escapeHtml: (s) => String(s ?? ''),
        document: {
            getElementById: (id) => (els[id] ||= makeEl(id)),
            querySelectorAll: (sel) => (sel.includes('th[data-sort]') ? ths : []),
            querySelector: () => makeEl('_'),
            createElement: (tag) => makeEl(tag),
            addEventListener() {},
        },
        fetch(url, options) {
            const d = deferred();
            calls.push({ url, body: options && options.body ? JSON.parse(options.body) : null, d });
            return d.promise;
        },
    };
    sandbox.window = sandbox;
    sandbox.smSettingsPromise = Promise.resolve({ low_stock_default_threshold: 5 });
    sandbox.fetchJson = async (url, options) => {
        const res = await sandbox.fetch(url, options);
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'hiba');
        return data;
    };
    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(path.join(webroot, 'termekek.js'), 'utf8'), sandbox, { filename: 'termekek.js' });
    return { els, calls, ths, run: (code) => vm.runInContext(code, sandbox) };
}

const flush = () => new Promise((r) => setTimeout(r, 0));
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const fire = (el, type) => Promise.all((el.listeners[type] || []).map((fn) => fn({ currentTarget: el, target: el, stopPropagation() {}, preventDefault() {} })));

function pageResponse(offset, total = 250) {
    const ids = Array.from({ length: total }, (_, i) => i + 1);
    const rows = ids.slice(offset, offset + 100).map((id) => ({ id, name: 'Termék ' + id, stock_qty: 3, unit: 'db', purchase_price_net: 1, net_price: 2, price: 3, show_webshop: 1, is_deleted: 0 }));
    return { products: rows, ids, filtered_count: total, total_count: 300, groups: ['Italok', 'Édesség'], offset, limit: 100 };
}

async function main() {
    const page = loadTermekek();
    const { els, calls } = page;
    const pageCalls = () => calls.filter((c) => c.url.startsWith('/api/products-page.php'));
    await flush();

    check('első betöltés: egyetlen products-page kérés, a teljes katalógus nélkül', pageCalls().length === 1 && !calls.some((c) => c.url.startsWith('/api/products.php')), calls.map((c) => c.url));
    const q0 = new URLSearchParams(pageCalls()[0].url.split('?')[1]);
    check('első betöltés: 0. eltolás, 100-as oldal, név szerint növekvő', q0.get('offset') === '0' && q0.get('limit') === '100' && q0.get('sort') === 'name' && q0.get('dir') === 'asc', q0.toString());

    pageCalls()[0].d.resolve(json(200, pageResponse(0)));
    await flush(); await flush();
    check('csak az aktuális oldal 100 sora kerül a DOM-ba (nem 250)', els['products-body'].children.length === 100, els['products-body'].children.length);
    check('számláló: szűrt / összes', els['products-count'].textContent === '250 / 300 árucikk', els['products-count'].textContent);
    check('lapozó látszik, oldal-információ', !els['products-pager'].classList.contains('hidden') && els['products-page-info'].textContent === '1–100 / 250', els['products-page-info'].textContent);
    check('előző gomb letiltva az első oldalon', els['products-prev-btn'].disabled === true);

    // „Mind kijelölése” a TELJES szűrt halmazra, tömeges művelet mind a 250 azonosítóval.
    els['select-all-products'].checked = true;
    await fire(els['select-all-products'], 'change');
    check('mind kijelölése: 250 kijelölve (minden oldal)', els['products-selected-count'].textContent === '250 kijelölve', els['products-selected-count'].textContent);
    await fire(els['bulk-delete-btn'], 'click');
    await flush();
    const bulk = calls.find((c) => c.url === '/api/products-bulk.php');
    check('tömeges törlés a teljes szűrt halmazra', bulk && bulk.body.ids.length === 250 && bulk.body.action === 'delete', bulk && bulk.body);
    bulk.d.resolve(json(200, { ok: true }));
    await flush(); await flush();
    const afterBulk = pageCalls()[pageCalls().length - 1];
    afterBulk.d.resolve(json(200, pageResponse(0)));
    await flush(); await flush();

    // Következő oldal.
    const before = pageCalls().length;
    await fire(els['products-next-btn'], 'click');
    const next = pageCalls()[before];
    check('következő oldal: offset=100', next && new URLSearchParams(next.url.split('?')[1]).get('offset') === '100', next && next.url);
    next.d.resolve(json(200, pageResponse(100)));
    await flush(); await flush();
    check('második oldal sorai', els['products-body'].children.length === 100 && els['products-page-info'].textContent === '101–200 / 250', els['products-page-info'].textContent);

    // Szűrés: gépelés közben csak egy kérés (késleltetve), az 0. oldalról.
    const beforeFilter = pageCalls().length;
    els['f-name'].value = 'a';
    await fire(els['f-name'], 'input');
    els['f-name'].value = 'alm';
    await fire(els['f-name'], 'input');
    els['f-name'].value = 'alma';
    await fire(els['f-name'], 'input');
    await flush();
    check('gépelés közben még nincs kérés', pageCalls().length === beforeFilter, pageCalls().length - beforeFilter);
    await wait(260);
    check('a késleltetés után egyetlen kérés', pageCalls().length === beforeFilter + 1, pageCalls().length - beforeFilter);
    const fq = new URLSearchParams(pageCalls()[beforeFilter].url.split('?')[1]);
    check('szűrő-kérés: name=alma, offset=0', fq.get('name') === 'alma' && fq.get('offset') === '0', fq.toString());

    // Rendezés + elavult válasz: a korábbi (szűrő) válasz később érkezik, nem írhatja felül.
    await fire(page.ths[1], 'click');
    const sortCall = pageCalls()[pageCalls().length - 1];
    check('rendezés: sort=price, dir=asc, offset=0', /sort=price/.test(sortCall.url) && /dir=asc/.test(sortCall.url) && /offset=0/.test(sortCall.url), sortCall.url);
    sortCall.d.resolve(json(200, pageResponse(0, 120)));
    await flush(); await flush();
    pageCalls()[beforeFilter].d.resolve(json(200, pageResponse(0, 7)));
    await flush(); await flush();
    check('elavult válasz nem írja felül a frissebbet', els['products-count'].textContent === '120 / 300 árucikk', els['products-count'].textContent);

    // Szerkesztés az aktuális oldal teljes rekordjával.
    let opened = null;
    page.run('globalThis.ProductModal = { open(p) { globalThis.__opened = p; } }');
    page.run('openEdit(5)');
    opened = page.run('globalThis.__opened');
    check('szerkesztés: az oldal teljes rekordja nyílik meg', opened && opened.id === 5 && opened.name === 'Termék 5', opened);
}

main().then(() => {
    process.stdout.write(JSON.stringify({ results }) + '\n');
    process.exit(failed ? 1 : 0);
}).catch((e) => {
    process.stdout.write(JSON.stringify({ results, error: String(e && e.stack || e) }) + '\n');
    process.exit(1);
});
