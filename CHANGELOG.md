# Changelog

Ez a fájl a Stock Manager verzióinak fontosabb változásait követi. A
formátum lazán a [Keep a Changelog](https://keepachangelog.com/) elvét
követi.

## 1.0 RC1 (2026-09-05)

A projekt innentől **release candidate** állapotban van — új funkció
tervezetten nem kerül bele az 1.0 véglegesig, a hátralévő munka
kizárólag hibakeresés és bugfix. A későbbre halasztott ötletek (NAV
valós idejű adatszolgáltatás, többdevizás támogatás, 2FA stb.) a
[ROADMAP.md](ROADMAP.md)-ban vannak, egy jövőbeli 1.1-es körnek.

### Hozzáadva (az utolsó beta óta)
- Tömeges kijelölés/műveletek (csoport módosítása, törlés) és
  GDPR-export/anonimizálás a Vásárlóknál.
- "Kapcsolat tesztelése" gomb a Beállítások → WooCommerce fülön.
- Alap PHPUnit teszt-készlet (lásd README "Automatizált tesztek").

## 1.0 beta 9 (2026-09-05)

### Hozzáadva
- **Beérkező eladások**: a WooCommerce webhookja mostantól piszkozatként
  veszi fel a fizetett webshop-rendeléseket, és csak emberi ellenőrzés +
  "leadás" után csökkenti a helyi készletet / hoz létre valódi
  eladás-rekordot. Tétel-szintű termékpárosítás, piros értesítés
  (oldalsáv + harang + felugró popup), egy kattintásos Számlázz.hu
  számlázás a rendelés adataiból, elutasítás gomb.
- **Bővíthető fizetési módok lista** (Beállítások → Számlázz.hu) — a
  kassza és a beérkező rendelések fizetésimód-választója innen olvas.
- **Verzió-lábléc** minden oldal jobb alsó sarkában (copyright + verzió).
- **Tömeges kijelölés + export** az Árucikkeknél és a Vásárlóknál:
  soronkénti checkbox, "mind kijelölése", Export CSV és Export XLS
  (SpreadsheetML formátum, külső könyvtár nélkül). Az árucikk-export
  tartalmazza a rövid/hosszú leírást, a márkát és a kép alt szövegét is.
- Alap linkstílus (accessibility): a szövegbe ágyazott linkek az app
  zöld accent-színét kapják a böngésző alapértelmezett kék/lila
  linkszíne helyett, ami rossz kontrasztú volt sötét sablonon.
- Alap PHPUnit teszt-készlet (`tests/`) a legkritikusabb üzleti
  logikára (készlet, kupon, jogosultság, IP-korlátozás, export).

### Javítva
- Tárolt XSS: a WooCommerce-ből érkező fizetési mód escape nélkül
  jelent meg az Eladások, Napi zárás és Beszerzések listákban.
- Reszponzív: a felső navigáció ~640–880px szélesség között rácsúszott
  a fejléc címére (a "Beérkező eladások" menüpont hozzáadása óta) —
  a töréspont 900px-re emelve.
- Reszponzív: az Eladások, Beszerzések és Beérkező eladások
  részletező modaljában a tételtáblázat a teljes modal-kártyát oldalra
  csúsztatta keskeny képernyőn, ahelyett hogy csak saját magát
  görgette volna.
- Versenyhelyzet-védelem: két majdnem egyidejű rendelés-leadás/elutasítás
  most atomikus DB-frissítéssel van kizárva.
- Néhány `Undefined array key` PHP-figyelmeztetés a termék- és
  kupon-mentésben (a PHPUnit-készlet fedte fel).

## 1.0 beta 8 (2026-09-03)

### Hozzáadva
- Animált bejelentkező képernyő (kártya-belépő animáció, izzó
  fókusz-gyűrű, rázkódás hibás jelszónál, sikeres-animáció).
- Árucikkek lista: "WS" gyorskapcsoló a webáruházban való
  feltüntetéshez, a szerkesztő modal megnyitása nélkül.
- Beállítások → Biztonság: "Saját IP hozzáadása" gomb az engedélyezett
  IP-lista mezőhöz.

### Javítva
- A Törlés/Visszaállítás gomb (és az új WS-kapcsoló) csak részleges
  adatot küldött mentéskor, ami csendben kinullázta volna a leírást,
  márkát, képet és a WooCommerce-szinkron kapcsolót.

## 1.0 beta 7 (2026-09-02)

### Hozzáadva
- A rövid/hosszú termékleírás TinyMCE-szerkesztője kézzel
  átméretezhető.
- Termékkép alapértelmezett mérete a Beállítások → WooCommerce alól
  módosítható (200–4000 px).

## 1.0 beta 6 és korábban (2026-09-02)

- Első feltöltés: Stock Manager POS/készletkezelő alkalmazás (kassza,
  beszerzés, árucikkek, napi zárás, Számlázz.hu integráció, WooCommerce
  kétirányú szinkron, törzsvásárlói pontok, kuponok/utalványok, több
  telephely, dolgozói jogszintek, automatikus mentés).
- IP/ország alapú hozzáférés-korlátozás (GeoBlocker).
- Jogosultsági rések, webhook-idempotencia és validációs hiányosságok
  javítása egy teljes kód-átvizsgálás után.
- Termékleírás, kép, márka mezők + WooCommerce-szinkron be/ki kapcsoló
  termékenként; TinyMCE vizuális szerkesztő a leírásokhoz.

---

A részletesebb, funkciónkénti leírásokért lásd a [README.md](README.md)-t.
