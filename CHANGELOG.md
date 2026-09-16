# Changelog

Ez a fájl a FountainTrade verzióinak fontosabb változásait követi. A
formátum lazán a [Keep a Changelog](https://keepachangelog.com/) elvét
követi.

## [1.2.0] — 2026-09-16 (Dashboard és üzleti riportok)

**Feature release — a meglévő sales/purchases/inventory/invoice/
WooCommerce adatokból ad üzleti áttekintést, nincs új külső integráció.
Lásd README "FountainTrade 1.2.0 — Dashboard és üzleti riportok" szakasza
a részletekért.**

### Added

- **Dashboard** (`dashboard.php`) — az alkalmazás új alapértelmezett
  kezdőoldala: bejelentkezés után és a csupasz gyökér-URL-en
  (`http://.../`) is ez nyílik meg (a Kassza saját, explicit `index.php`
  URL-je változatlan, a Kassza tartalma nem módosult). Gyors napi
  áttekintő, nem egy újabb riport-oldal:
  - dátum + magyar névnap fejléc (`src/HungarianNameDays.php` — lokális,
    verziózott naptáradat, nincs külső API-hívás; ha egy napra nincs
    hitelesen megállapítható névnap a forrásban — pl. január 23-24.,
    február 29. —, egyszerűen nem jelenik meg "Névnap:" sor, nincs
    kitalált név);
  - rendszerállapot-jelző (🟢/🟠/🔴), kizárólag már meglévő, mért
    jelekből (sync-hiba, WooCommerce/NAV-számla sikertelenségek);
  - mai KPI-k tegnaphoz viszonyított %-os változással (csak ha van
    érvényes tegnapi bázisadat);
  - "Figyelmet igényel" blokk (elfogyott/alacsony készlet, előrejelzés
    alapján hamarosan kifogyó termékek, függő webshop-rendelések,
    sikertelen számlák/WooCommerce-szinkron) — mindegyik tétel a
    megfelelő meglévő oldalra mutat;
  - napi állapotok (napi zárás, webshop, számlázás), 7 napos
    bevétel-trend, mai top termékek, mai fizetésimód-megoszlás.
  - A sidebar-ban nincs külön Dashboard-menüpont — a logó vezet rá.
- Forgalmi riport (`sales-report.php`) — napi bontás, fizetésimód szerinti
  bontás, Top termékek (csoport/minimum darabszám szűréssel,
  visszáruval nettósítva).
- Készlet riport (`inventory-report.php`) — készletérték, legnagyobb
  értékű termékek, alacsony/kifogyott készlet lista CSV exporttal.
- Készletmozgás-riport (`stock-movements.php`) — a meglévő eladás/
  beszerzés/visszáru/leltár/telephely-mozgás adatokból, szűrhető,
  lapozható, CSV exporttal; új "Készletmozgások" fül a termék-
  részletezőben.
- Egyszerű készlet-előrejelzés (átlagos napi fogyás alapján), a
  Beszerzési javaslat oldalon és a Készlet riportban — négy explicit
  állapot (`out_of_stock`/`insufficient_data`/`zero_consumption`/`ok`),
  sose hamis pontosságú becslés.
- WooCommerce szinkron-monitor (`woocommerce-sync.php`) — queue-állapot
  összesítő, admin-jogszintű kézi újrapróbálkozás a meglévő
  `wc_push_queue`-ra.
- Új, kizárólag olvasó riport-API végpontok (lásd README) + 4 CSV export
  (a meglévő formula-injekció elleni `csv_safe()` védelemmel).

### Changed

- `purchase-suggestions.php` válasza additívan bővült egy `forecast`
  mezővel soronként — a meglévő javasolt-mennyiség logika és válasz-alak
  változatlan.
- Bejelentkezés utáni (és a "már bejelentkezve" auto-redirect)
  alapértelmezett cél `index.php`-ről `dashboard.php`-ra változott — a
  `?redirect=...` deep-link célok (közvetlen URL-ről érkezéskor)
  változatlanul elsőbbséget élveznek.
- PWA `manifest.json` `start_url` mezője `dashboard.php`-ra változott.

### Database

- `Database::SCHEMA_VERSION` 24 → 25 — kizárólag 3 új index
  (`idx_returns_created_at`, `idx_return_items_product_id`,
  `idx_stock_take_items_product_id`), nincs új tábla/oszlop.

## [1.1.1] — 2026-09-14 (stabilitási / megbízhatósági kiadás)

**Maintenance release — nincs új üzleti funkció, kizárólag stabilitási/
adatbiztonsági javítások. Lásd README "FountainTrade 1.1.1 — stabilitási
és megbízhatósági javítások" szakasza a teljes indoklásért.**

### Added
- `purchases.idempotency_key`/`idempotency_fingerprint` + UNIQUE index —
  beszerzés-idempotencia, a sale-nél már bevált minta (kliens-generált
  UUID, előzetes ellenőrzés + DB-szintű UNIQUE-ütközés-visszajátszás).
- `wc_push_queue` tábla + `WcPushQueueWorker` — aszinkron WooCommerce
  készlet-push, a NAV queue mintáját követve (claim-with-lock, 9-lépéses
  retry/backoff, dead_letter). Új cron-végpont: `api/wc-queue-run.php`.
- `WooCommerceRequestException` — timeout/DNS/5xx (retryable) vs. 4xx
  (végleges) hiba-osztályozás; külön connect- és teljes-kérés-timeout.
- `src/PriceValidator.php` — központi ár-validáció (negatív tiltva, nulla
  megengedett), alkalmazva kézi termékszerkesztésen, CSV/JutaSoft
  importon és WooCommerce-behúzáson.
- `webroot/api.js` (`fetchJson()`) — egységes fetch()-becsomagolás, minden
  oldalon felvéve; 18 lista-betöltő oldal hibakezelése javítva (HTTP
  hibaválasz többé nem jelenik meg csendben üres listaként).
- Globális backend hibakezelő (`set_exception_handler`/
  `register_shutdown_function`) `_bootstrap.php`-ban — minden el nem
  kapott hiba egységes, secret nélküli JSON 500-ra fordul,
  `display_errors` explicit kikapcsolva.
- `tests/fixtures/jutasoft_export.csv` + `tests/JutasoftImportFixtureTest.php`
  — valódi, reprezentatív JutaSoft export-struktúra regressziós tesztje.
- `Database::closeForExternalFileReplacement()`/`reconnect()` — biztonságos
  kapcsolat-csere a self-update rollback fájl-visszaállítása körül.

### Changed
- `api/sale.php`, `api/purchase-save.php`, `api/stock-take-complete.php` —
  a WooCommerce-push MOST beütemezés (gyors, helyi INSERT, ugyanabban a
  tranzakcióban, mint a készletváltozás), NEM szinkron, blokkoló hálózati
  hívás — egy lassú/elérhetetlen WooCommerce-szerver többé nem
  lassítja a kasszát/beszerzést/leltárt.
- `api/import-commit.php` — egy hibás árú (negatív) sor mostantól
  KIHAGYÁSRA kerül (a válasz `rejected` tömbje jelzi), NEM dobja el az
  egész importot; `api/import-preview.php` egy külön `invalid_price`
  számlálóval előzetesen jelzi ugyanezt.
- `api/import-preview.php` — opportunista seprés a `data/imports/`
  könyvtárban maradt, 4 óránál régebbi árva ideiglenes fájlokra.
- `sale.php` — az `InvoiceService::processInvoice()` hívása explicit
  `try/catch`-be került, hogy egy váratlan hiba a MÁR sikeresen rögzített
  eladás válaszát ne nyelje el egy generikus szerverhiba mögé.

### Fixed
- Self-update rollback: a `BackupManager::restoreFromFile()` nyers
  fájlmásolása a MÉG NYITVA lévő DB-kapcsolat alatt valódi SQLite-fájl-
  sérülést okozhatott ("database disk image is malformed") — a
  `wc_push_queue` tábla bevezetése (nagyobb séma) tette
  determinisztikusan reprodukálhatóvá, de a hiba maga a nyers
  fájlmásolás architektúrájában volt jelen 1.1.0 óta.

## [1.1.0] — 2026-09-14 (helyesbítő/sztornó számla)

**A MODIFY/STORNO adatmodell ÉS a tényleges NAV/Számlázz.hu kérés-
összeállítás/beküldés is bekötve — valódi NAV sandbox lánccal
(CREATE→MODIFY→STORNO, mindhárom DONE) igazolva. Lásd README
"Számla-műveletek adatmodell" és "MODIFY/STORNO — tényleges NAV/
Számlázz.hu beküldés" szakaszai.**

### Added
- `invoices.invoice_type` (normal/modification/storno), `original_invoice_id`
  (explicit FK, sose string-parszolás), `operation_key` (egységes,
  determinisztikus/kísérlet-kulcsolt duplikálás-védelem), `modification_index`.
- `invoice_sequences` — provider-kulcsolt, atomikus, `invoices.id`-től
  független NAV számlaszám-sorozat (`Database::allocateInvoiceNumber()`).
- `invoice_modification_sequences` — eredeti-számlánkénti, MODIFY+STORNO
  közös, atomikus `modificationIndex`-számláló
  (`Database::allocateModificationIndex()`).
- `Database::createInvoiceOperation()` — a módosító/sztornó `invoices`-sorok
  létrehozásának validált belépési pontja.
- `src/InvoiceNumbering.php` — a számlaszám-formátum egyetlen, központi helye.
- `InvoiceProviderInterface::supportsOperation()`/`requestModification()`/
  `requestStorno()` — mindkét provider (`NavInvoiceProvider`,
  `SzamlazzInvoiceProvider`) implementálja.
- `NavClient::manageInvoiceOperation()` — CREATE/MODIFY/STORNO envelope;
  `NavInvoiceXmlBuilder` `invoiceReference`/`lineModificationReference`
  támogatás (valódi NAV sandbox hívással igazolt kötelező mezők, lásd
  README).
- `SzamlazzClient::modifyInvoice()`/`stornoInvoice()` — helyesbítő számla
  (meglévő `xmlszamla` endpoint, `helyesbitoszamla`/`helyesbitettSzamlaszam`)
  és valódi sztornó (KÜLÖN `xmlszamlast` séma/`action-szamla_agent_st`
  endpoint, NEM a korábbi, tudatosan nem-valódi `createCreditNote()`).
- `InvoiceService::requestModification()`/`requestStorno()`/
  `retrySzamlazzOperation()` — a MODIFY/STORNO egyetlen, központilag
  validáló belépési pontja.
- `webroot/api/invoice-modify.php`, `invoice-storno.php`,
  `szamlazz-operation-retry.php` — új API-végpontok (admin+CSRF).
- Kimenő számlák UI: "Módosító számla"/"Sztornó számla" gombok
  (backend-vezérelt láthatóság), tételszerkesztő modal, kapcsolódó
  számla-lánc navigáció (`kimeno-szamlak.js`/`.php`).
- 32 új teszt a schema/numbering rétegből (3 valódi, 16-folyamatos
  konkurrencia-teszt), PLUSZ ~55 új teszt a tényleges beküldési rétegből
  (NAV XML/signature/queue-branching, Számlázz.hu XML/field-order/POST-
  mezőnév, InvoiceService központi validáció, HTTP-végpont auth/CSRF,
  2 új valódi 16-folyamatos konkurrencia-teszt MODIFY/STORNO dupla-
  kattintásra).

### Changed
- A korábbi `UNIQUE(sale_id, provider)` megszűnt (MODIFY/STORNO
  strukturális előfeltétele volt) — helyette `UNIQUE(operation_key)` adja
  ugyanazt (és annál finomabb) a duplikálás-védelmet, teljes visszafelé
  kompatibilitással a normál CREATE-folyamatra.
- A NAV számlaszám többé NEM `invoices.id`-ból képződik — lásd fent.
  A meglévő (1.0.x-ben kiállított) számlaszámok VÁLTOZATLANOK.
- `Database::upsertInvoiceMirror()` mostantól a Számlázz.hu CREATE
  buyer/items payloadját is eltárolja (`payload_json`, ugyanaz az alak,
  mint a NAV oldalon) — enélkül egy Számlázz.hu-s eredeti számla
  STORNO-kontextusa nem lenne rekonstruálható (a `sales` tábla csak
  `buyer_name`-et tárol, strukturált vevő-adatot nem).

## [1.0.2] — 2026-09-14

### Fixed
- Az önfrissítő rendszer GitHub SSRF-fehérlistája kiegészítve a
  `release-assets.githubusercontent.com` hoszttal — ez a GitHub
  release-asset letöltések tényleges, valódi átirányítási célja (egy
  élő 1.0.0 → 1.0.1 frissítési teszt közben derült ki; a korábbi
  fehérlista a hibát biztonságosan, elutasítással kezelte, nem
  bukott el nyitva).

## [1.0.1] — 2026-09-14

### Added
- Önfrissítő rendszer — GitHub Release-alapú, ellenőrzött (manifest +
  checksum + kereszt-ellenőrzött commit-SHA), biztonságos (SSRF-védett
  letöltés, "Zip Slip"-védett kicsomagolás) frissítés, automatikus
  biztonsági mentéssel, karbantartási móddal, hiba esetén teljes (kód +
  adatbázis) visszaállítással. Lásd README "Önfrissítés" szakasza.
- Beállítások → Frissítések: jelenlegi/elérhető verzió, ellenőrzés/
  telepítés indítása, automatikus ellenőrzés/telepítés be- és
  kikapcsolása, frissítési előzmények.
- `tools/update-install-cli.php` — cron-indítható, felügyelet nélküli
  automatikus telepítéshez (opcionális, alapból kikapcsolva).

## [1.0.0] — 2026-09-10

FountainTrade 1.0 az első production kiadás, az 1.0 RC stabilizációs és
biztonsági hardening ciklus lezárása után.

### Added
- NAV Online Számla kimenő integráció — közvetlen NAV-beküldés, tartós
  és race-safe queue, retry/backoff, timeout-biztonság, bizonytalan
  tranzakció helyreállítása.
- NAV Online Számla bejövő szinkronizáció (Beérkezett számlák).
- Kimenő számlák — egységes, szolgáltató-független (NAV + Számlázz.hu)
  nézet.
- Hálózati (ESC/POS) nyomtatás, Epson TM-T20III CP852 magyar
  ékezet-támogatással.
- QR-kód nyomtatása a nyugtán.
- Automatikus hálózati nyomtatás minden kasszaeladás után.
- SMTP e-mail-küldés beállítása (Beállítások → Email).
- Korábbi (legacy) számlák visszamenőleges rögzítésére szolgáló
  backfill eszköz.

### Security
- CSRF-védelem kikényszerítve.
- SSRF-védelem (kimenő kapcsolatok célcím-ellenőrzése).
- Számla-idempotencia.
- Queue-konkurrencia védelem.
- Mentés titkosítása nyugalmi állapotban.
- Visszaállítás-biztonság.
- Jogosultság-hardening.
- Számla-helyreállítási védelmek.

### Fixed
Az 1.0 RC stabilizációs kör legfontosabb, production-blocker javításai:
- MySQL friss-telepítési séma lemaradása.
- Mentés-visszaállítás fájlnév-ütközése.
- NAV bizonytalan-egyeztetés végtelen újra-feldolgozási hurka.
- NAV végleges státusz-ellenőrzési hiba kezelése.
- Számlázz.hu transport-bizonytalanság.
- Termék létrehozás/szerkesztés dupla-beküldés elleni védelem.
- Dolgozó-létrehozás jogosultsági rése.

### Tested
- Teljes PHPUnit reguressziós szvit, 3 egymást követő futtatás:
  330 teszt / 946 assertion / 0 failure / 0 error / 2 skip.

## 1.0 RC5 (2026-09-09)

### Hozzáadva
- **Beérkezett számlák** (NAV Online Számla bejövő szinkron) — más
  adózók által kiállított, a NAV-nál regisztrált számlák helyi
  szinkronizálása, listázása, részletnézete (kizárólag olvasás, nincs
  NAV-beküldés). Külön `incoming_invoices`/`incoming_invoice_items`/
  `incoming_invoice_sync` adatmodell (indoklás: README), 35 napos NAV
  ablak-darabolás, race-safe deduplikáció, módosítás/sztornó-kapcsolat,
  lazy tétel-lekérdezés, manuális + automatikus (cron) sync.
- **Nyomtató kódlap-választás** (Beállítások → Nyomtató) — a magyar
  ékezetes karakterek helyesen jelennek meg hálózati ESC/POS
  nyomtatón (korábban ASCII-re transzliterálódtak, torzítva); valódi
  Epson TM-T20III hardveren igazolva.
- **QR-kód nyomtatása** a nyugtára (a digitális nyugta linkjével),
  Epson hivatalos ESC/POS specifikációja alapján, valódi hardveren
  igazolva.
- **Automatikus hálózati nyomtatás** minden kasszaeladás után
  (opcionális) — a nyomtatási hiba sose vonja vissza az eladást.
- **SMTP e-mail küldés** (Beállítások → Email) a korábbi, csak helyi
  levelezés-továbbítóval működő `mail()` mellett/helyett.
- Beállítások oldal szélesebb elrendezése, új "Email" fül.

### Javítva
- **Migráció-megbízhatóság**: egy élesben reprodukált hiba, ahol a
  séma-verzió előrébb léphetett, mint ahogy a hozzá tartozó táblák
  ténylegesen létrejöttek volna (SQLite-on mostantól teljes
  tranzakciós atomicitás, minden motoron idempotens, biztonságosan
  újrafuttatható migráció).
- Kimenő számlák szűrő-rács UI-hibák (elcsúszott gombsor, levágott
  legördülő szöveg).

## 1.0 RC4 (2026-09-07)

Külső production/security audit alapján, a megerősített találatok javítva
(a teljes jelentés és az egyenkénti indoklás a git történetben).

### Javítva
- **CSRF-védelem ténylegesen kikényszerítve.** A token-infrastruktúra már
  megvolt (Auth::csrfToken()/verifyCsrf(), a kliens le is kérte), csak
  sose lett ellenőrizve/elküldve — mostantól minden állapotváltoztató
  (POST) kérés megköveteli az X-CSRF-Token fejlécet, központilag,
  automatikusan hozzáfűzve minden oldalon.
- Vezetői jogszinthez kötve: Beállítások mentése, biztonsági beállítások,
  mentés indítása/listázása, WooCommerce-szinkron indítása, WooCommerce
  kapcsolat-teszt.
- WooCommerce kapcsolat-teszt SSRF-védelem: belső/loopback/metadata címek
  (127.0.0.1, 192.168.x.x, 169.254.169.254 stb.) elutasítva.
- Beérkező webshop-rendelés számlázása mostantól elutasítja, ha az
  eladáshoz már tartozik számla (dupla számlázás dupla kattintásnál).
- WooCommerce-behúzás (pull-szinkron) többé nem írja felül egy már ismert
  helyi termék készletét — a helyi adatbázis marad a készlet egyetlen
  hiteles forrása, mint mindenhol máshol az appban.
- Telepítő (install.php) mostantól egyszer-generált tokent kér, amíg a
  telepítés nincs lezárva; MySQL adatbázisnév-mező validálva SQL-befecs-
  kendezés ellen.
- Cron-parancsok dokumentációja javítva (hiányzó ?token= paraméter).
- Kijelentkezés-végpontok (logout, dolgozó-kijelentkezés) csak POST-ot
  fogadnak el.
- Visszáru/telephelyi mozgatás mostantól a szerver-oldali, PIN-nel
  ellenőrzött dolgozói session-t használja, nem a kliens által beküldött
  staff_id-t.
- Import feltöltés méretkorlátja (25 MB).
- NAV cégadat-lekérdezés hibaüzenete nem ad ki technikai részleteket egy
  sima pénztárosnak.
- Tevékenységnapló (audit-log.js) néhány mezője nem volt escape-elve.
- Settings::save() zárolt olvasás-módosítás-írás (verseny-védelem két
  majdnem egyidejű mentés között).

### Szándékosan nem változott
Alapértelmezett jelszó nélküli működés (dokumentált, szándékos, egyetlen
boltos/helyi telepítésre); teljes eladás-idempotencia (a dupla-számla
eset javítva, a dupla-eladás kockázata alacsony és egy heurisztikus
javítás rosszabb lenne — legitim, gyors egymás utáni azonos eladásokat
blokkolna); CSP/HSTS (törésveszélyes, külön tesztelés nélkül nem
vezetjük be); mentés-titkosítás (ez már funkció, nem patch).

2 új PHPUnit teszt (41/41 zöld).

## 1.0 RC3 (2026-09-07)

Harmadik átvizsgálási kör: napi zárás/riportok, hűségpontok/kuponok/
ajándékutalványok, biztonsági mentés/GDPR, többtelephelyes készlet, majd
séma-migráció régi adatbázison, beszerzés, termékkatalógus/WooCommerce-
szinkron, leltár, kassza/számlázás UX, és az Árucikkek/Vásárlók listák.

### Javítva
- Napi zárás/forgalmi trend mostantól levonja a visszárukat.
- Kassza: kupon-/pont-/utalvány-beváltás foglalása az eladás rögzítése
  ELŐTT, ugyanabban a tranzakcióban történik — versenyhelyzetben a teljes
  eladás visszagördül, nem csak a könyvelés marad inkonzisztens.
- Rendszerszintű jogosultság-megkerülés javítva: minden "csak vezetőnek"
  végpont a szerver-oldali, PIN-nel ellenőrzött session-t olvassa, nem egy
  kliens által beküldött staff_id-t.
- GDPR-export/törlés vezetői jogszinthez kötve; a törlés a korábbi
  eladásokon is eltünteti a vásárló nevét.
- Adatbázis-visszaállítás: stale -wal/-shm tisztítás, séma-ellenőrzés, és a
  data/settings.json (API-kulcsok) is bekerül a mentésbe.
- **Leltárzárás mostantól relatív eltérésként (nem abszolút felülírásként)
  korrigálja a készletet** — korábban egy leltár alatt lezajlott valódi
  eladást csendben eltüntetett volna a zárás. Emellett: dupla lezárás és
  átfedő (egyidejű) leltár is elutasítva, korrekció alkalmazása vezetői
  jogszinthez kötve.
- Séma-migráció: valódi hibák (nem csak "ez már létezik") eddig csendben
  elnyelődtek, a séma-verzió mégis feljebb íródott — mostantól csak a
  jóindulatú eseteket nyeli el. Régi telepítésen a nettó/beszerzési ár is
  helyesen kitöltődik migráláskor (korábban csendben 0 lett).
- Beszerzés: kedvezmény (discount_percent) mostantól ténylegesen
  érvényesül az összegben; WooCommerce-készletpush versenyhelyzete javítva.
- WooCommerce-termékszinkron (behúzás) többé nem törli csendben a helyi
  vonalkódot/leírást/márkát, ha a webshop oldalán az üres; márka helyi
  törlése a WooCommerce oldalán is törlődik.
- CSV mellett az XLS-exportok (Árucikkek, Vásárlók) is védettek
  képlet-injekció (CWE-1236) ellen; a Vásárlók-export mostantól az
  ország/megjegyzés mezőket is tartalmazza.
- Árucikkek lista 500 termékes korlátja miatt egy annál nagyobb katalógus
  csendben csonkult (hibás számláló, hamis üres keresési találat) — a
  korlát feloldva.
- Kassza: csak kézi tételeket tartalmazó kosárnál egy sikertelen eladás
  után a "Eladás rögzítése" gomb véglegesen letiltva ragadt.

11 új PHPUnit teszt a fenti javításokra (40/40 zöld).

## 1.0 RC2 (2026-09-07)

Teljes kódátvizsgálás második köre: WooCommerce-szinkron, biztonság,
import/export, nyomtatás, és egy élő funkcionális teszt.

### Javítva
- Eladás/leltár utáni WooCommerce-készletszinkron versenyhelyzetek.
- API-titkok (WooCommerce, Számlázz.hu, NAV, Dropbox, Google) maszkolva
  a Beállítások válaszában.
- Bejelentkezési/PIN rate-limit versenyhelyzet, adatbázis-visszaállítás
  jogosultság-megkerülés, ajándékutalvány/kupon versenyhelyzetek.
- 8+ helyen hiányzó `escapeHtml()` — tárolt XSS.
- Teljes visszárunál a hűségpontok/kupon/utalvány visszapörgetése.
- CSV-export képlet-injekció (CWE-1236) minden export-végpontnál.
- Tömeges termékimport vezetői jogszinthez kötve, naplózva.
- `.xlsx` zip-bomb DoS elleni méretkorlát.
- `preferred_supplier_id` csendben kinullázódott minden termékmentésnél
  (nem csak importnál).
- ESC/POS nyomtató-parancs-befecskendezés (termék-/kosártétel-névből).
- Nyugta QR-kódja többé nem küldi a titkos megtekintési tokent egy
  külső szolgáltatásnak — helyben, becsomagolt könyvtárral generálódik.
- Nyomtató-teszt végpont vezetői jogszinthez kötve (belső hálózat
  feltérképezésének megakadályozása).
- Napi zárás ÁFA/Nettó bontása mostantól figyelembe veszi a
  rendelés-szintű kedvezményeket (korábban túlbecsülte az ÁFA-t).
- Kassza checkout gomb felirata igazodik a számla-jelölőnégyzethez.

Alap PHPUnit-készlet 28 tesztre bővítve.

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
