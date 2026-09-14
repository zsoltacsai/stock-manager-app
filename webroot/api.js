// Egységes fetch()-becsomagolás — 1.1.1, lásd README "Frontend hibakezelés"
// szakasza. Az audit szerint több lista-betöltő oldal NEM ellenőrizte a
// `fetch()` válasz `ok` állapotát, mielőtt a törzsét adatként feldolgozta
// volna — egy nem-2xx JSON hibaválasz (pl. `{"error": "..."}`)  emiatt
// csendben "üres listaként" jelent meg, a valódi hibaüzenet sose jutott el
// a felhasználóhoz. Minden lista-betöltő függvénynek EZT kell hívnia
// nyers `fetch()` helyett, és a dobott Error.message-et kell megjelenítenie
// (lásd zaras.js/kimeno-szamlak.js már meglévő, jó mintáját, amit ez a
// segédfüggvény egyetlen helyre központosít).
async function fetchJson(url, options) {
    const res = await fetch(url, options);
    let data = null;
    try {
        data = await res.json();
    } catch (e) {
        // A válasz törzse nem érvényes JSON (pl. egy proxy hibaoldala, vagy
        // egy hálózati köztes eszköz beavatkozása) — ez akkor is HIBA, ha a
        // HTTP-státusz történetesen 2xx volt, sose "üres adat".
        throw new Error(res.ok ? 'A szerver válasza nem érvényes JSON.' : `Szerverhiba (HTTP ${res.status}).`);
    }
    if (!res.ok) {
        throw new Error((data && data.error) || `Szerverhiba (HTTP ${res.status}).`);
    }
    return data;
}
