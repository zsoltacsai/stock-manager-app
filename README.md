# FountainTrade — Point of Sale & Inventory

**Verzió: 1.3.1** (production release — stabilizáció)

FountainTrade 1.0 volt az első production kiadás, az 1.0 RC
stabilizációs és biztonsági hardening ciklus lezárása után. Azóta több
feature release (1.1.0, 1.1.1, 1.2.0, 1.3.0 — lásd `CHANGELOG.md`)
bővítette az alkalmazást; az 1.3.1 egy tiszta stabilizációs kör (audit +
hibajavítás, új funkció nélkül) — lásd `CHANGELOG.md` "[1.3.1]"
szakaszát.

Egy önállóan üzemeltethető PHP alkalmazás egy kisbolt/webshop teljes napi
üzemeltetéséhez: USB vonalkódolvasós kassza, beszerzés és leltár, több
telephely és dolgozói jogszint, törzsvásárlói/hűségpont-rendszer, kuponok
és ajándékutalványok, Számlázz.hu számlázás, automatikus mentés, és
kétirányú WooCommerce-szinkron — beleértve a webáruházból beérkező
rendelések ellenőrzött, piszkozat-alapú feldolgozását is (lásd lent:
Beérkező eladások).

## Követelmények

- PHP 8.1+ (fejlesztve és tesztelve **PHP 8.3** ellen — ugyanaz a verzió,
  amit a telepítési útmutató (`install.txt`) is használ), `curl`,
  `xmlwriter`, `fileinfo` kiterjesztésekkel, plusz `pdo_sqlite`
  (alapértelmezett) vagy `pdo_mysql` (ha MySQL-re váltasz — lásd lentebb)
- A `data/`, `invoices/` és `webroot/assets/` mappáknak írhatónak kell
  lenniük a PHP folyamat számára (itt van az SQLite fájl / helyi
  mentések / számla-PDF-ek / feltöltött logó)
- Egy Számlázz.hu fiók, bekapcsolt **Számla Agent**-tel (Beállítások →
  Számla Agent → kulcs generálása) a `szamlaagentkulcs` megszerzéséhez
- WooCommerce REST API kulcsok (WooCommerce → Settings → Advanced →
  REST API → Add key, jogosultság: **Read/Write**)

Alapból nincs Composer, nincs adatbázis-szerver — minden PHP beépített
webszerverén és egyetlen SQLite fájlon fut. Lásd lentebb az "Adatbázis:
SQLite vs MySQL" szakaszt, ha ez már kevés lenne.

## 1.0 Production Checklist

Az alkalmazás 1.0 production release, de az alábbi, telepítéstől függő
ellenőrzések továbbra is a telepítő/üzemeltető felelőssége — ezek nem
alkalmazási hibák, hanem az adott környezetre jellemző, egyszeri
beállítási lépések:

- **HTTPS** — valódi tanúsítvány a nyilvános/távoli telepítésnél (lásd `telepites-tavoli-szerver.txt`)
- **PHP verzió/kiterjesztések** — lásd "Követelmények" fent
- **Adatbázis** — SQLite (alapértelmezett) vagy MySQL; **MySQL esetén éles validáció szükséges** (ez a projekt fejlesztői környezetében nem tesztelhető élő MySQL szerver nélkül)
- **Migrációk** — friss telepítésnél automatikus (`schema.sql`/`schema.mysql.sql`), meglévő telepítés frissítésénél a `Database` osztály automatikusan lefuttatja
- **Biztonsági mentés** — `data/.backup-encryption-key` és `data/backups/` írhatók legyenek, cron beállítva
- **Cron** — lásd "Automatikus feladatok" lentebb (szinkron, mentés, NAV kimenő/bejövő)
- **NAV konfiguráció** — technikai felhasználó, tesztmód kikapcsolása élesítéskor, **működő kimenő TLS/CA-tár szükséges a célszerveren**
- **SMTP** — Beállítások → Email, **javasolt egy valódi teszt-email küldése élesítés előtt**
- **Nyomtató** — Beállítások → Nyomtató, IP/port, kódlap
- **Biztonság** — alkalmazás-jelszó bekapcsolása, ha nem csak localhost-ról érhető el
- **Smoke test** — egy teljes eladás rögzítése (nyugta + ha releváns, számla) éles beüzemelés után

## Beüzemelés

1. Másold az egész mappát valahova a gépedre.
2. Szerkeszd a `config/config.php` fájlt:
   - `woocommerce.store_url`, `consumer_key`, `consumer_secret`
   - `woocommerce.barcode_source` — `'sku'`, ha a WooCommerce SKU mezőt
     használod vonalkódként, vagy `'meta'` (+ `barcode_meta_key`), ha
     egy plugin egyéni mezőben tárolja a vonalkódot
   - `szamlazz.agent_key`
   - `szamlazz.default_buyer` — betérő/készpénzes eladásokhoz, ahol nem
     rögzítesz valódi vevő-számlázási adatot
3. Indítsd el a szervert a projekt gyökeréből:

   ```bash
   php -S localhost:8000 -t webroot
   ```

4. Nyisd meg a http://localhost:8000 címet — üres kasszát fogsz látni,
   amíg nem szinkronizálsz.
5. Kattints a **"Sync WooCommerce-ből"** gombra, hogy behúzd a
   termékeket (név, ár, készlet, vonalkód/SKU) a helyi adatbázisba.
6. Csatlakoztasd az USB vonalkódolvasót (billentyűzetként viselkedik —
   nem kell driver), kattints a vonalkód mezőbe, majd szkennelj.

## Beszerzés (bejövő készlet / beszerzések)

Nyisd meg a `beszerzes.html` oldalt (a felső sávból linkelve) a
beszállítói szállítmányok rögzítéséhez:

- Szkennelj be egy vonalkódot egy meglévő termék listához adásához,
  vagy használd a **"+ Új termék hozzáadása"** gombot egy vadonatúj
  termék helyben történő létrehozásához (név, mértékegység, csoport,
  cikkszám, áfakulcs, eladási ár, vonalkód, súly/térfogat, árlista/
  webshop láthatóság — ugyanazok a mezők, mint az "Árucikk módosítása"
  ablakban).
- Minden sor mutatja az adott termék utolsó ismert beszerzési
  (bekerülési) árát, előre kitöltve és szerkeszthetően — frissítsd, ha
  változik a beszállító ára; ez mentésre kerül mint új "utolsó ismert
  bekerülési ár" a következő alkalomra.
- Opcionálisan rögzíthetők beszállítói adatok, fizetési mód és
  fizetettségi állapot.
- Egy beszerzés mentése hozzáadja a beérkezett mennyiséget a
  készlethez, és a Kasszához hasonlóan pusholja az új készletszintet a
  WooCommerce-be.

## Árucikkek lista (termékek listája)

Nyisd meg a `termekek.html` oldalt (a felső sávból linkelve) minden
termék teljes, szerkeszthető listájához — ez a helyi megfelelője az
"Árucikk lista" képernyőnek:

- Szűrés név, cikkszám, vonalkód, csoport, csak nulla készlet, vagy a
  törölt cikkek megjelenítése szerint.
- Kattints bármelyik sorra (vagy a "Módosítás" gombra) a termék teljes
  törzsadatának szerkesztéséhez, ugyanabban az ablakban, amit a
  Beszerzés oldalról is elérsz.
- A **"+ Új árucikk"** gomb vadonatúj terméket hoz létre anélkül, hogy
  előbb beszerzés vagy vonalkód-szkennelés kellene.
- A "Törlés" egy terméket "soft"-töröl (beállítja az `is_deleted`
  mezőt, alapból elrejti a Kasszáról és a keresésből), a sort magát nem
  távolítja el — az eladási/beszerzési előzmények érintetlenek
  maradnak. A "Visszaállítás" ezt vonja vissza.

## Termék importálás (átállás másik programról)

Ha eddig más programban (pl. Axel Pro) vezetted a készletet, a
`beallitasok.html` (Beállítások) **Importálás** füle egy menetben
átemeli a teljes termékkatalógust:

1. Válaszd ki a forrásprogramot a legördülő listából.
2. Töltsd fel az onnan exportált **CSV**, **XLS** vagy **XLSX** fájlt —
   a szerver mindhárom formátumot saját maga, külső program nélkül
   olvassa be (lásd install.txt). Csak akkor van szükség kézi CSV-vé
   mentésre, ha a feltöltött fájl sérült vagy nem szabványos formátumú,
   és a beépített olvasó emiatt hibát ad.
3. **Előnézet**: megmutatja, hány sorból lesz új termék, illetve hány
   frissít meglévőt vonalkód alapján, és jelzi a gyanús eseteket
   (hiányzó név, duplikált vonalkód a fájlban, egy várt oszlop, ami nem
   található).
4. **Importálás indítása**: ténylegesen létrehozza/frissíti a
   termékeket — név, cikkszám, csoport, mértékegység, nettó beszerzési
   ár, nettó és bruttó eladási ár (az áfakulcsot az arányukból
   következteti ki), vonalkód — és **közvetlenül felülírja a
   készletet** az importált értékkel (ez egy teljes átállást modellez,
   nem egy beszerzést/szállítást).

Az egyeztetés vonalkód alapján történik: az azonos vonalkódú meglévő
termék frissül; minden más újként kerül beszúrásra. A vonalkód nélküli
sorok mindig újként kerülnek beszúrásra, hiszen nincs mihez biztonságosan
egyeztetni őket.

**Bővítés másik programhoz (pl. Jutasoft):** az oszlop-hozzárendelés a
`src/ImportProfiles.php` fájlban van, programonként egy tömb. Egy új
program támogatása csak egy új bejegyzés felvétele (melyik oszlopnév
melyik mezőnek felel meg) — az oldal, a feltöltés-kezelés és az
egyeztetési logika már eleve generikus, nem kell hozzájuk nyúlni.

## A szinkronizálás működése (kétirányú)

- **Behúzás (kézi gombbal, vagy időzítve cronból)**: beolvassa az összes
  terméket a WooCommerce-ből, és frissíti a helyi terméklistát — név,
  ár, és készletmennyiség. `webroot/api/sync-pull.php`.
- **Kiküldés (automatikus, minden kassza-eladás után)**: amint egy
  eladás helyben rögzítésre kerül, minden eladott termék új
  készletmennyisége kiküldésre kerül a WooCommerce-be a REST API-n
  keresztül. Lásd az `updateStock()` hívásokat a
  `webroot/api/sale.php`-ban.
- **Webhook (opcionális, valós idejű behúzáshoz)**: a
  `webroot/api/webhook.php` fogadni tud egy WooCommerce "Rendelés
  frissítve" webhookot, és azonnal csökkenti a helyi készletet, amikor
  a weboldalon történik eladás, ahelyett hogy a következő kézi
  behúzásra várna. Ez csak akkor működik, ha a WooCommerce el tudja
  érni ezt a gépet a hálózaton keresztül (azonos LAN, vagy egy alagút,
  mint az ngrok) — egy teljesen offline localhost-beállításnál inkább
  a kézi behúzás gombra hagyatkozz.

Mivel mindkét oldal függetlenül változtathatja a készletet, ez egy
last-write-wins (utolsó írás nyer) szinkron, nem tranzakciós: ha
ugyanaz a tétel elkel a weboldalon és a kasszánál is ugyanazon a
szinkron-időközön belül, futtass utána egy behúzást az egyeztetéshez.
Egy kis boltnak ez általában megfelelő; jelezd, ha szigorúbb garanciák
kellenek, és hozzáadható optimista zárolás / ütközés-riasztás.

## Számlázás

A számlázást a Beállítások → Számlázás fülön kiválasztott szolgáltató
végzi (`invoice_provider`: `szamlazz` — alapértelmezett — vagy `nav`),
`src/InvoiceService.php`-n keresztül. `sale.php` (és a webshop-rendelés-
végpontok) sose ismerik a konkrét szolgáltató (Számlázz.hu vagy NAV)
API-részleteit — csak az `InvoiceService::processInvoice()`-ot hívják,
ami mindig egy egységes `{success, invoice_number, pdf_path, error,
pending}` alakú eredményt ad.

### Számlázz.hu (alapértelmezett)

Minden kassza-eladás meghívja a Számlázz.hu Számla Agent XML API-ját
(`src/SzamlazzClient.php`), hogy valódi számlát állítson ki, és letölti
a PDF-et az `invoices/` mappába. Ez **szinkron**: a válasz még az
eladási kérésen belül megérkezik. Ha a számla létrehozása MEGERŐSÍTETTEN
sikertelen (pl. hibás agent kulcs — a Számlázz.hu ténylegesen válaszolt
egy elutasítással), az eladás helyben ekkor is rögzítésre kerül
`invoice_failed` státusszal, hogy ne vesszen el a tranzakció — a számla
utólag azonnal, automatikusan újrapróbálható. Ha viszont a hívásra
EGYÁLTALÁN nem érkezett válasz (hálózati hiba/timeout — nem tudható, a
Számlázz.hu ténylegesen létrehozta-e a számlát), a sale `invoice_uncertain`
státuszba kerül, és admin kézi feloldása szükséges — lásd "Ismert,
tudatosan vállalt maradék korlátok" lentebb.

### NAV Online Számla

**Fontos: a NAV-beküldés ASZINKRON.** A kassza SOSE vár a NAV-ra — egy
`invoice_provider=nav` melletti eladás így zajlik:

```
eladás → helyi tranzakció (commit) → invoices queue-bejegyzés (status=queued) → eladás sikeres
```

A kasszás azonnal "Eladás sikeres — a számla NAV beküldése folyamatban"
visszajelzést lát, a tényleges NAV-kommunikációt egy külön háttér-
worker végzi (lásd lent). Ha a NAV éppen nem elérhető, az eladás AKKOR
IS sikeres marad — csak a számla queue-bejegyzése vár tovább.

**Beállítás** (Beállítások → Számlázás):
1. NAV Online Számla technikai felhasználó regisztrálása (ingyenes) a
   [onlineszamla.nav.gov.hu](https://onlineszamla.nav.gov.hu) portálon
   — login, jelszó, aláíró kulcs (signer key), csere kulcs (exchange
   key), majd a saját (céges) adószám első 8 számjegye.
2. Kiállító (eladó) adatai — a NAV, a Számlázz.hu-val ellentétben, nem
   tárol "cégprofilt", minden egyes számlán szükséges a kiállító
   neve/címe.
3. Teszt rendszer (`api-test.onlineszamla.nav.gov.hu`) használata
   BEKAPCSOLVA marad, amíg élesben ki nem próbáltad — a teszt API-n
   beküldött számlák sose kerülnek a NAV valós nyilvántartásába.
4. "NAV számla queue háttér-feldolgozás" bekapcsolása, ÉS a cron
   feladat beállítása (lásd lent) — enélkül a queue-bejegyzések
   `queued` állapotban maradnak, sose kerülnek ténylegesen beküldésre.

**A NAV számla queue** (`invoices` tábla, `provider='nav'`) állapotgépe:

```
queued ──► processing ──► submitted ──► done   (véglegesen elfogadva)
   ▲            │              │
   │            │              └────────► failed  (NAV véglegesen elutasította)
   │            ▼
   │        (átmeneti hiba: hálózat/timeout/HTTP 5xx)
   └──────  queued, backoff-bal újraütemezve
                │
                ▼ (9 backoff-kör kimerült)
            dead_letter  (admin kézi újrapróbálkozása szükséges)

processing (manageInvoice hívás közben timeout — NEM tudni, a NAV
            megkapta-e a kérést) ──► uncertain
                │
                ▼ (queryTransactionList-alapú egyeztetés, max 3x)
     submitted (találat)  VAGY  uncertain_manual (nincs találat 3x után —
                                VALÓDI terminális állapot, admin kézi
                                újrapróbálkozása szükséges, lásd lent)
```

**Sose küld vak duplikált számlát**: ha a `manageInvoice` hívás
timeout/hálózati hiba miatt válasz nélkül marad, a rendszer NEM
próbálja újra automatikusan — nem tudható, hogy a NAV megkapta-e a
kérést. A sor helyette `uncertain` állapotba kerül, és a NAV saját,
erre a célra ajánlott `queryTransactionList` műveletével egyezteti,
hogy a kérés ténylegesen megérkezett-e (a talált tranzakciók eredeti
tartalmát a saját, ismert számlaszámunkkal veti össze). Ha ez sem tud
egyértelmű választ adni néhány próbálkozás után, admin kézi beavatkozás
szükséges (`webroot/api/nav-invoice-retry.php`, vezetői jogszinttel).

**Cron beállítás — pontos üzemeltetési útmutató.**

| Kérdés | Válasz |
|---|---|
| Melyik endpoint? | `webroot/api/nav-queue-run.php` |
| Milyen gyakran? | **Percenként** (`* * * * *`) — biztonságos, a végpont saját maga dönti el, van-e esedékes teendő |
| Milyen header? | `X-Cron-Token: <cron_secret>` — ugyanaz a meglévő mechanizmus, mint `auto-sync-run.php`/`auto-backup-run.php`-nál, **nincs külön NAV-specifikus secret** |
| Milyen jogosultság? | Nincs böngésző-bejelentkezés — a cron-token teljesen helyettesíti (lásd `_bootstrap.php` `$cronScripts`) |
| Melyik köröket futtatja egy hívás? | Mindhármat, minden egyes hívásnál, sorban: (1) `queued` → `manageInvoice` beküldés, (2) `submitted` → `queryTransactionStatus` ellenőrzés, (3) `uncertain` → `queryTransactionList`-egyeztetés — mindegyik kör legfeljebb 10 sort dolgoz fel hívásonként |

```
* * * * * curl -s -H "X-Cron-Token: <a beállított cron_secret>" http://localhost:8000/api/nav-queue-run.php > /dev/null
```

**Mi történik, ha a cron kimarad** (leáll a szerver, törlődik a
crontab-bejegyzés, stb.)? **Semmi vészes.** Az eladás maga már réges-
régen, a queue-tól teljesen függetlenül sikeres volt — csak a
`queued`/`submitted`/`uncertain` sorok halmozódnak, `next_attempt_at`-
juk egyre inkább a múltba csúszik. Amint a cron újraindul, a worker a
KÖVETKEZŐ percben egyszerűen felveszi a fonalat onnan, ahol abbamaradt
— nincs "elveszett" munka, nincs időkorlát, ameddig a cron
visszatérhet. (A kivétel a `dead_letter` és az `uncertain_manual`
állapot: mindkettőből VALÓDI terminális állapot, onnan való
kilábaláshoz admin kézi retry szükséges, `webroot/api/nav-invoice-retry.php`
— ez szándékos, nem a cron-kimaradás következménye. Az `uncertain_manual`
akkor áll be, ha a bizonytalan kimenetelű kérés
`queryTransactionList`-alapú egyeztetése 3 próbálkozás után sem tud
egyértelmű választ adni — a rendszer ekkor SZÁNDÉKOSAN leáll az
automatikus egyeztetéssel, hogy ne terhelje feleslegesen/korlátlanul a
NAV API-t, és admin figyelmét kérje.)

**Elavult ("stale") zár helyreállítása**: ha egy worker-futás menet
közben megszakad (pl. a PHP-folyamat összeomlik egy `manageInvoice`
hívás KÖZBEN), a claim-elt sor `processing`/`processing_status_check`/
`processing_uncertain_recovery` állapotban, zárolva marad. Ez NEM
ragad be örökre: minden claim-lekérdezés figyelmen kívül hagyja a 10
percnél régebbi zárakat (`NavInvoiceQueueWorker::STALE_LOCK_SECONDS`),
tehát a KÖVETKEZŐ cron-futás (legfeljebb 10 perc múlva) automatikusan
újra felveheti a sort — nincs szükség manuális beavatkozásra egy
egyszerű folyamat-összeomlás után.

**Dokumentáció-frissítés (1.3.1)**: az alábbi két pont korábban itt még
nyitott/jövőbeli feladatként volt leírva — mindkettő azóta LEZÁRULT.
A NAV-számlaszám ma `Database::allocateInvoiceNumber()`-rel, egy KÜLÖN,
Számlázz.hu-tól teljesen független `invoice_sequences` táblából
allokálódik (`FT-NAV-{év}-{szám:06d}` formátumban), NEM az `invoices`
tábla osztott auto-increment id-jából — lásd lentebb a "Számla-műveletek
adatmodell" szakaszt a teljes indoklásért. A KIMENŐ oldal `MODIFY`/
`STORNO` (helyesbítés/sztornó) beküldése is elkészült, valódi NAV
sandbox lánccal igazolva — lásd "MODIFY/STORNO — tényleges NAV/
Számlázz.hu beküldés (1.1.0)" lentebb. A BEJÖVŐ oldal (más adózók által
kiállított számlák NAV-szinkronja) lásd lent.

### NAV Online Számla — Beérkezett számlák (bejövő szinkron)

Ez a FORDÍTOTT irány a fenti kimenő NAV-integrációhoz képest: más
adózók által a FountainTrade tulajdonosának kiállított, a NAV-nál
regisztrált számlák (`invoiceDirection=INBOUND`) helyi szinkronizálása,
listázása és részletnézete — **kizárólag olvasás jellegű**, a NAV-hoz
ebben a körben semmi nem kerül beküldésre.

**Miért külön adatmodell a kimenő `invoices` táblától?** Az `invoices`
tábla állapotgépe (`queued→processing→submitted→done/failed/dead_letter/
uncertain`) a MI SAJÁT beküldésünk életciklusát írja le — egy bejövő
számlának nincs "beküldési állapota", egyszerűen VAN vagy NINCS a
NAV-nál. Emiatt 3 KÜLÖN tábla tárolja: `incoming_invoices` (fejléc-
szintű adatok, digest-forrásból), `incoming_invoice_items` (tételsorok,
LAZY módon, csak a részletnézet első megnyitásakor lekérdezve),
`incoming_invoice_sync` (race-safe sync-állapotgép, egyetlen sor a
`'nav'` providerhez).

**Beállítás** (Beállítások → Számlázás): a fenti kimenő NAV-hitelesítő
adatokat használja (nincs külön bejövő-specifikus titok) — csak a "NAV
bejövő számla sync háttér-feldolgozás" jelölőnégyzetet kell bekapcsolni,
ÉS a cron feladatot beállítani (lásd lent).

**Szinkron algoritmus**: a NAV `queryInvoiceDigest` operációja
`insDate` (a NAV saját, monoton feldolgozási időbélyege) szerinti UTC
időablakban listáz, lapozva. A NAV hivatalos specifikációja szerint egy
lekérdezési ablak **legfeljebb 35 nap (840 óra)** lehet — élő NAV
sandbox hívással is igazolt korlát (`BAD_QUERY_PARAM_RANGE_EXCEEDED`
hiba egy 60 napos próba-ablakra). Emiatt egy hosszabb (pl. első sync,
vagy egy régóta kimaradt cron utáni) céltartományt a rendszer
automatikusan ≤35-napos ablakokra darabolja, és **minden egyes,
sikeresen VÉGIGLAPOZOTT ablak után azonnal előrehaladtja a sync
cursor-t** (`sync_cursor_ins_date`) — egy megszakadt/korlátozott futás
így sose dolgozza fel feleslegesen újra a már kész ablakokat.

**Deduplikáció**: a NAV adatmodellje szerint minden eredeti ÉS minden
módosító/sztornó dokumentum SAJÁT, egyedi `invoiceNumber`-t kap az adott
szállítónál (ÁFA tv. 169-170. §) — ez a `UNIQUE(supplier_tax_number,
invoice_number, batch_index)` a helyi dedup-kulcs, `INSERT OR IGNORE`
véd az ismételt sync duplikálása ellen. Emellett a sync-állapotsor
atomikus claim-je (`Database::claimIncomingInvoiceSync()`) garantálja,
hogy egyszerre csak EGY sync-futás (manuális VAGY automatikus)
dolgozhat — valódi, több-folyamatos konkurrencia-teszttel bizonyítva
(`tests/DatabaseTest.php::testIncomingInvoiceSyncClaimIsAtomicAcrossRealConcurrentProcesses()`).

**Módosítás/sztornó**: a digest `invoiceOperation` (`CREATE`/`MODIFY`/
`STORNO`) + `originalInvoiceNumber` + `modificationIndex` mezői alapján
a "Beérkezett számlák" nézet badge-ként jelzi a típust, és a
részletnézetben — ha megtalálható helyben — kattintható hivatkozást ad
az eredeti számlára.

**Tételsorok, LAZY betöltve**: a digest-lekérdezés NEM ad tétel-szintű
adatot — csak a részletnézet ELSŐ megnyitásakor indul egy
`queryInvoiceData` hívás (`invoiceData.xsd`-séma, UGYANAZ, amit a
`NavInvoiceXmlBuilder` a kimenő oldalon ír), ami a tételsorokat és a
szállító országát adja vissza. Ez tudatos terheléscsökkentés — egy
digest-lapon akár 50+ számla is lehet, ezek mindegyikéhez sync-enként
külön lekérdezést indítani irreális NAV-terhelés lenne.

**Bruttó összeg**: a NAV digest-válasza NEM ad külön bruttó mezőt, csak
nettó és ÁFA összeget — a `gross_total` a FountainTrade saját, HELYBEN
számított értéke (nettó + ÁFA), ezt a "Beérkezett számlák" részletnézet
is explicit jelzi.

**Manuális sync** ("Beérkezett számlák" oldal → "Számlák frissítése"
gomb): választható időszak (utolsó 7 nap / utolsó 30 nap / egyedi
dátumtól) — ez az ELSŐ sync kezdőpontját állítja be (`sync_cursor_ins_date`
addig NULL). Ha a cron-worker MÁR fut, a manuális trigger `409`-et ad
(ugyanazt az atomikus claim-et próbálja, mint a cron), NEM indít
párhuzamos syncet.

**Cron beállítás.**

| Kérdés | Válasz |
|---|---|
| Melyik endpoint? | `webroot/api/nav-incoming-sync-run.php` |
| Milyen gyakran? | **Óránként** javasolt (`0 * * * *`) — a bejövő számlák NEM annyira időérzékenyek, mint a kimenő beküldés |
| Milyen header? | `X-Cron-Token: <cron_secret>` — UGYANAZ a mechanizmus/titok, mint `nav-queue-run.php`-nál |
| Egy hívás mennyit dolgoz fel? | Legfeljebb 3, egyenként ≤35-napos ablakot (timeout-védelem — egy erősen elmaradt sync több cron-tick alatt éri utol magát) |

```
0 * * * * curl -s -H "X-Cron-Token: <a beállított cron_secret>" http://localhost:8000/api/nav-incoming-sync-run.php > /dev/null
```

**Mi történik, ha a cron kimarad?** Semmi vészes — a `sync_cursor_ins_date`
a legutóbbi sikeres állapotnál marad, a következő futás onnan folytatja.
A 35 napos ablak-darabolás miatt egy hosszan kimaradt cron (pl. hetekig
állt) több cron-tick alatt éri utol magát, nem egyetlen HTTP-kérésben —
**nincs adatvesztés, csak késés**.

**Hiba/retry**: a kimenő NAV queue-val megegyező 9-lépéses exponenciális
backoff (1p/5p/15p/30p/1ó/3ó/6ó/12ó/24ó). Állapotok: `idle`/`running`/
`success`/`retry`/`failed` — nincs külön `dead_letter`, egy kimerült
backoff `failed`-re vált, de a legközelebbi manuális VAGY automatikus
próbálkozás továbbra is megpróbálhatja.

**PDF**: a NAV Online Számla API nem biztosít PDF-et bejövő számlákhoz
(csak strukturált XML/JSON-adatot) — a "Beérkezett számlák" nézet ezt
explicit jelzi, NEM generál hamis PDF-et.

**Ismert korlátok**: a `queryInvoiceChainDigest` (teljes módosítási lánc
lekérdezése) NEM használt — a lista-/részletnézet a digest-szintű
`originalInvoiceNumber`/`modificationIndex` mezőkből épül fel, ami az
egyszerű (egy-szintű) módosítási láncokat lefedi.

## Számla-műveletek adatmodell — MODIFY/STORNO előkészítés (1.1.0)

**Ez a szakasz a KIMENŐ számlák MÓDOSÍTÁSÁNAK/SZTORNÓZÁSÁNAK
adatmodell-rétegét dokumentálja** — a számlaszám-generálás
provider-függetlenítése, az üzleti típus (normal/modification/storno)
bevezetése, az eredeti↔módosítás/sztornó kapcsolat, és mindkettő
konkurrencia-biztos allokálása. **A tényleges NAV/Számlázz.hu MODIFY/
STORNO kérés-összeállítás/beküldés a következő szakaszban
("MODIFY/STORNO — tényleges NAV/Számlázz.hu beküldés (1.1.0)")
dokumentált — MÁR bekötve, valódi NAV sandbox lánccal (CREATE→MODIFY→
STORNO, mindhárom DONE) igazolva.**

### Invoice_type — üzleti típus a technikai állapottól elválasztva

Az `invoices` tábla `status` mezője (queued/processing/submitted/done/
failed/...) a számla TECHNIKAI életciklusát írja le, változatlanul. Egy
ÚJ, ettől teljesen független `invoice_type` mező (`normal` | `modification`
| `storno`) írja le az ÜZLETI típust — egy módosító számla pl.
`invoice_type='modification'` ÉS `status='queued'` egyszerre, pontosan
úgy, mint egy eredeti számla induláskor.

### original_invoice_id — explicit kapcsolat, sose string-parszolás

Minden `modification`/`storno` sor `original_invoice_id` oszlopa (FK,
`invoices(id)`-re hivatkozva) közvetlenül az EREDETI (`invoice_type='normal'`)
számlára mutat — SOHA egy köztes módosításra (lásd lent, "modificationIndex").
`normal` soroknál ez a mező NULL. A kapcsolat MINDIG explicit adatbázis-
hivatkozás, sose `invoice_number` string alapján való keresés.

### NAV számlaszám-sorozat — a korábbi, `invoices.id`-alapú séma megszűnt

**Korábbi állapot (1.0.x)**: a NAV felé beküldött `invoiceNumber`
`Database::insertQueuedInvoice()`-ban `SM-NAV-{év}-{id}` formában
képződött, ahol `{id}` az `invoices` tábla Számlázz.hu-val OSZTOTT
auto-increment oszlopa volt (lásd a korábbi ROADMAP-bejegyzés, most
lezárva).

**1.1.0 óta**: `Database::allocateInvoiceNumber(string $provider): int`
egy KÜLÖN, `invoice_sequences` táblából (oszlopok: `provider`,
`last_allocated_number`) allokál — konkurrencia-biztosan (egy
tranzakción belüli atomikus `UPDATE ... SET last_allocated_number =
last_allocated_number + 1` + visszaolvasás, ugyanaz az elv, mint a
projekt más "UPDATE ... WHERE" claim-mintáinál, pl.
`claimUpdateLock()`), SOSE `MAX(invoice_number)+1`-gyel. A végleges
string-formátumot EGYETLEN helyen, `InvoiceNumbering::format()` adja —
jelenleg `FT-NAV-{év}-{szám:06d}` (a `FT` a FountainTrade rebrand után).

A migráció (`Database::migrateV22InvoiceOperationsBody()`) a MEGLÉVŐ
(1.0.x-ben kiállított) `invoice_number` értékeket **nem generálja újra**
— azok jogilag/technikailag változatlanok maradnak. Az ÚJ sorozat a
migráció idején a **legmagasabb korábbi NAV `invoices.id` fölött**
indul (egyszeri, migráció-időpontbeli `MAX()`-lekérdezés — ez NEM
azonos a tiltott "minden allokáláskor `MAX()+1`" mintával), hogy a régi
és az új számok sorrendje sose legyen félreérthető.

A Számlázz.hu-hoz is létrejön egy `invoice_sequences` sor
(kiterjeszthetőség), de a jelenlegi kód SOSE inkrementálja ténylegesen —
a Számlázz.hu MINDIG a saját maga generálta, a válaszban visszakapott
számlaszámot használja (`szlahu_szamlaszam`), változatlanul.

### modificationIndex — eredeti-számlánkénti, MODIFY+STORNO közös számláló

A NAV `InvoiceReferenceType.modificationIndex` mezője (hivatalos NAV
dokumentáció, github.com/nav-gov-hu/Online-Invoice) 1-től induló,
az EREDETI számlára vonatkozó ÖSSZES módosítás/sztornó KÖZÖS,
folyamatos sorszáma — NEM külön-külön MODIFY-onkénti/STORNO-onkénti.
Példa:

```
Eredeti számla
 ├── MODIFY  → modificationIndex 1
 ├── MODIFY  → modificationIndex 2
 └── STORNO  → modificationIndex 3
```

`Database::allocateModificationIndex(int $originalInvoiceId): int` egy
KÜLÖN, `invoice_modification_sequences` táblából (kulcs:
`original_invoice_id`) allokál, ugyanazzal az atomikus mintával, mint
`allocateInvoiceNumber()`.

### operation_key — egységes duplikálás-védelem (uniqueness ÉS idempotency)

Az `invoices.operation_key` (UNIQUE index) EGYETLEN mechanizmus, ami a
kérés két, tudatosan elkülönített fogalmát is kiszolgálja, MÁS-MÁS
képzési szabállyal:

- **normal** (a meglévő `insertQueuedInvoice()`/`upsertInvoiceMirror()`
  útvonalak): `create:{sale_id}:{provider}` — DETERMINISZTIKUS, tehát
  pontosan azt a garanciát adja, mint a korábbi (1.1.0-ban törölt)
  `UNIQUE(sale_id, provider)` — a normál CREATE-folyamat emiatt
  változatlanul, visszafelé kompatibilisen működik.
- **storno**: `storno:{original_invoice_id}` — SZINTÉN determinisztikus,
  ezért STRUKTURÁLISAN terminális: akárhány konkurrens sztornó-kísérlet
  érkezik ugyanarra az eredeti számlára, a UNIQUE index pontosan egyet
  enged át (bizonyítva egy valódi, 16 párhuzamos OS-folyamatos teszttel,
  lásd `tests/InvoiceOperationRelationTest.php`).
- **modification**: `modify:{original_invoice_id}:{kliens-generált UUID}`
  — a UUID-t a (későbbi körben megépítendő) kliens egyetlen alkalommal
  generálja egy adott módosítási szándék indításakor, és ugyanazt küldi
  újra egy dupla kattintás/hálózati retry esetén — ez véd az EGY adott
  kísérlet duplikálása ellen, miközben KÉT, ténylegesen különböző
  módosítás (más UUID) mindkettő sikeresen létrejöhet.

Miért NEM `UNIQUE(sale_id, provider, invoice_type)`: ez blokkolná a
fenti, szándékosan engedélyezett "MODIFY, majd MÉG egy MODIFY" láncot
(mindkettő ugyanazt a sale_id+provider+'modification' hármast adná).

### Validáció

`Database::createInvoiceOperation()` (a `modification`/`storno` sorok
létrehozásának egyetlen belépési pontja) ellenőrzi: az `invoice_type`
kizárólag `modification`/`storno` lehet; `original_invoice_id` egy
LÉTEZŐ, `invoice_type='normal'` sorra mutat (sose egy másik
módosításra/sztornóra); önhivatkozás elleni védelem (a beszúrás után,
védelmi mélységként). `normal` soroknál `original_invoice_id` és
`modification_index` NULL marad, `modification`/`storno` soroknál
mindkettő kitöltött — ez az invariáns minden létrehozási úton
(`insertQueuedInvoice()`, `upsertInvoiceMirror()`, `createInvoiceOperation()`)
érvényesül.

### Indexek

`idx_invoices_sale_provider` (nem-unique, a törölt régi UNIQUE helyett),
`idx_invoices_operation_key` (UNIQUE), `idx_invoices_original_invoice_id`,
`idx_invoices_invoice_type`, `idx_invoices_status_next_attempt`,
`idx_invoices_provider` — lásd `Database::migrateV22InvoiceOperationsBody()`.

## MODIFY/STORNO — tényleges NAV/Számlázz.hu beküldés (1.1.0)

A fenti adatmodell-réteg felett ez a szakasz a TÉNYLEGES helyesbítő
(módosító) és sztornó számla NAV/Számlázz.hu felé történő beküldését
dokumentálja — mindkét provider támogatja mindkét műveletet, a meglévő
`InvoiceService`/`InvoiceProviderInterface`/`NavInvoiceProvider`/
`NavInvoiceQueueWorker`/`SzamlazzInvoiceProvider` architektúrát bővítve,
NEM egy párhuzamos rendszerrel.

### Architektúra

```
UI (Kimenő számlák részletnézet)
 → webroot/api/invoice-modify.php / invoice-storno.php
 → InvoiceService::requestModification()/requestStorno()   — KÖZPONTI validáció
 → InvoiceProviderInterface::requestModification()/requestStorno()
 → NavInvoiceProvider (aszinkron, queue-n át) / SzamlazzInvoiceProvider (szinkron)
```

Az `InvoiceService` a KIZÁRÓLAGOS belépési pont — validálja: admin
jogosultság, az eredeti számla létezik és `invoice_type='normal'` és
`status='done'`, nincs már lezáró (nem véglegesen sikertelen) sztornó
(`Database::invoiceHasBlockingStorno()`), a provider ténylegesen
támogatja a műveletet (`InvoiceProviderInterface::supportsOperation()`).
A provider EZUTÁN csak már validált, előkészített payloadot kap — saját
maga nem dönt üzleti szabályról.

### NAV MODIFY/STORNO

A NAV Online Számla ADATSZOLGÁLTATÁSI modell (nem dokumentum-kiállítás)
— a `manageInvoice` `invoiceOperation` envelope-mezője CREATE/MODIFY/
STORNO (`NavClient::manageInvoiceOperation()`, a korábbi
`manageInvoiceCreate()` ennek vékony CREATE-wrappere), az invoiceData
XML-be pedig egy `invoiceReference` blokk kerül (`NavInvoiceXmlBuilder`),
az `invoice` ELSŐ gyermekeként, `invoiceHead` előtt:

```xml
<invoiceReference>
  <originalInvoiceNumber>...</originalInvoiceNumber>
  <modifyWithoutMaster>false</modifyWithoutMaster>
  <modificationIndex>...</modificationIndex>
</invoiceReference>
```

**Három, VALÓDI NAV sandbox hívással (nem csak séma-olvasással) feltárt,
kötelező részlet** — mindegyiket egy éles CREATE→MODIFY→STORNO sandbox-
lánc igazolta, a végleges implementáció mindhárom pontban a NAV tényleges
válasza alapján készült:

1. **`lineModificationReference` kötelező minden tételsoron**, ha a
   dokumentum `invoiceReference`-t hordoz — ennek hiányában a NAV
   ABORTED-del utasítja el ("Tételsort tartalmazó módosító okirat esetén
   a tételsor módosítás jellegének megadása kötelező"). A `<line>`
   MÁSODIK gyermeke, közvetlenül `lineNumber` után (invoiceData.xsd
   `LineType` szekvencia).
2. **`lineOperation` MINDIG `CREATE`**, sose `MODIFY` — bár a séma
   mindkettőt engedi, a NAV üzleti szabálya explicit: "Módosító vagy
   érvénytelenítő számláról beküldött adatszolgáltatásban a lineOperation
   elem értékének minden esetben „CREATE"-nek kell lennie." (első
   próbálkozás `MODIFY`-jal ABORTED lett).
3. **`lineNumberReference` a TELJES lánc (eredeti + minden korábbi
   módosítás/sztornó) kumulatív tételszámát folytatja**, NEM az adott
   dokumentum saját, 1-től induló sorszámozását — enélkül "A megadott
   sorszámmal már létezik tétel a számlaláncban" hibát ad. Ezt
   `NavInvoiceProvider::computeLineNumberOffset()` számolja ki a művelet
   LÉTREHOZÁSAKOR (az eredeti + minden addig létező kapcsolódó sor
   `payload_json`-jának tétel-darabszámából), és menti a payload
   `line_number_offset` mezőjébe — `submit()` ezt már készen olvassa,
   DB-hozzáférés nélkül.

**A queue-integráció NEM külön mechanizmus**: `NavInvoiceQueueWorker`
változatlan — `claimQueuedInvoiceForSubmission()` provider-szűrt, NEM
`invoice_type`-szűrt, tehát egy `createInvoiceOperation()`-nel létrehozott
`modification`/`storno` sor UGYANÚGY `status='queued'`-del kerül be, és a
meglévő worker automatikusan felveszi. `NavInvoiceProvider::submit()`
`invoice_type` szerint ágazik el (normal→CREATE, modification→MODIFY,
storno→STORNO XML-mel) — ez az EGYETLEN módosítás a submit()-ben, a
`checkStatus()`/`recoverUncertain()`/`classifyFailure()` VÁLTOZATLAN
(ezek a `provider_ref`/`invoice_number` alapján generikusan működnek,
sose esnek vissza implicit CREATE-re timeout után).

### Számlázz.hu MODIFY/STORNO

- **MODIFY**: UGYANAZT az Agent XML endpointot (`action-xmlagentxmlfile`)
  és `xmlszamla` sémát használja, mint a CREATE — a `fejlec` blokkban a
  meglévő `helyesbitoszamla` mező (korábban mindig `'false'`) `'true'`-ra
  vált, és közvetlenül utána egy ÚJ `helyesbitettSzamlaszam` (az eredeti
  számla száma) elem íródik ki — a hivatalos Agent XSD `fejlec`
  szekvenciáját követve (`SzamlazzClient::buildInvoiceXml()`,
  `$modifyOriginalInvoiceNumber` paraméter).
- **STORNO**: KÜLÖN sémájú kérés (`xmlszamlast` gyökérelem), KÜLÖN POST
  mezőnéven (`action-szamla_agent_st`, NEM `action-xmlagentxmlfile`) —
  `beallitasok` → `fejlec` (`szamlaszam`=az eredeti számla száma,
  `keltDatum`) — `SzamlazzClient::stornoInvoice()`. Ez KÜLÖNBÖZIK a
  korábbi, tudatosan NEM-valódi `createCreditNote()`-tól (ami egy sima,
  negált mennyiségű ÚJ CREATE, lásd annak docblockja) — a MODIFY/STORNO
  ténylegesen a Számlázz.hu saját helyesbítő/sztornó mechanizmusát
  használja.
- Szinkron flow (`SzamlazzInvoiceProvider::requestModification()`/
  `requestStorno()`): a `Database::createInvoiceOperation()` claim UTÁN
  AZONNAL hívja a Számlázz.hu-t, az eredményt `updateInvoiceOperationResult()`-tal
  írja vissza — ez EGY MÁSIK metódus, mint a CREATE-flow
  `upsertInvoiceMirror()`-je (ami `operation_key='create:...'`-re épülő
  upsert, egy modify/storno sorra hívva összekeverte volna az eredeti
  tükör-bejegyzést).
- **Bizonytalan (transport-hiba utáni) kimenetel kézi feloldása**: mivel
  a Számlázz.hu SZINKRON (nincs queue-worker, ami automatikusan
  újrapróbálná), egy `uncertain_manual` állapotú modify/storno sor admin
  kézi újraindítást igényel: `webroot/api/szamlazz-operation-retry.php`
  — `Database::resetInvoiceForManualRetry()` (UGYANAZ, provider-agnosztikus
  metódus, mint a NAV-nál) 'queued'-ra állítja a MEGLÉVŐ sort, majd
  `InvoiceService::retrySzamlazzOperation()` ténylegesen újra elindítja
  (`SzamlazzInvoiceProvider::executeAndRecordOperation()`).

### Idempotencia / operation_key

- **modify**: `modify:{original_invoice_id}:{operation_uuid}` — az
  `operation_uuid`-t a HÍVÓ (UI: `crypto.randomUUID()` a modal
  megnyitásakor, egyszer, retry/dupla-kattintásnál újraküldve) adja meg;
  `InvoiceService` fallback-ként generál egyet, ha hiányzik (VÉDELEM, de
  ekkor egy ténylegesen megismételt HTTP-kérés nem ismerhető fel
  idempotensként).
- **storno**: `storno:{original_invoice_id}` — determinisztikus, uuid
  nélkül, STRUKTURÁLISAN egyszeri. Mindkét eset RuntimeException helyett
  graceful `{success:false, already_in_progress:true}` eredményt ad egy
  ütközésnél (mindkét provider konzisztensen, lásd
  `NavInvoiceProvider::enqueueOperation()`/`SzamlazzInvoiceProvider::performOperation()`).
- **Valódi, 16 különálló OS-folyamatos teszt** bizonyítja mindkét esetet
  (`tests/InvoiceOperationConcurrencyTest.php`): 16 konkurrens MODIFY
  UGYANAZZAL az uuid-vel → pontosan 1 sikeres + 15 graceful elutasított;
  ugyanez STORNO-ra (uuid nélkül).

### STORNO tartalom — a lánc AKTUÁLIS állapotát vonja vissza

`InvoiceService::buildStornoContext()` a tételeket/összegeket NEM MINDIG
az eredeti számlából veszi — ha az eredetihez már tartozik legalább egy
SIKERESEN (`status='done'`) kiállított módosítás, a LEGUTÓBBIT (legnagyobb
`modification_index`) használja alapul. **Valódi NAV sandbox hívással
feltárt indoklás**: a NAV a sztornó nettó/ÁFA összegét a lánc (eredeti +
összes sikeres módosítás) összesítéséhez viszonyítva ellenőrzi — egy,
kizárólag az eredetit visszavonó sztornó egy már módosított árú láncnál
technikai figyelmeztető üzenetet kapott (nem nullázódó összesítés), még
ha végül DONE is lett. A javítás UTÁN egy teljes sandbox-lánc (CREATE
1270 Ft → MODIFY 1500 Ft-ra → STORNO) mindhárom lépésben tisztán DONE
lett, figyelmeztetés nélkül.

### UI — Kimenő számlák

Az EREDETI számla részletnézetén (`kimeno-szamlak.js`) "Módosító számla"/
"Sztornó számla" gomb jelenik meg — KIZÁRÓLAG akkor, ha a backend
(`webroot/api/invoice-detail.php` `can_modify`/`can_storno` mezője,
UGYANAZ a szabály, mint `InvoiceService::validateOperationRequest()`)
engedélyezettnek jelzi; a frontend sose dönt pénzügyi jogosultságról
saját maga, és a gombok egy MÁR LÉTREHOZOTT módosítás/sztornó sor SAJÁT
nézetén SOSE jelennek meg (csak a gyökér eredetin). MODIFY előtt egy
tételszerkesztő modal (`#modify-modal`) jelenik meg, a számla TÉNYLEGES
(`payload_json`-ban tárolt) tartalmával előtöltve; STORNO egyetlen
megerősítő kérdéssel indul ("Ez a művelet ÚJ, önálló pénzügyi bizonylatot
hoz létre..."). Mindkét kapcsolódó számla-lánc (`getInvoiceOperationsForOriginal()`)
megjelenik a részletnézetben, kattintható navigációval a lánc bármely
tagjához.

### API-végpontok

| Endpoint | Módszer | Jogosultság |
|---|---|---|
| `webroot/api/invoice-modify.php` | POST | admin + CSRF |
| `webroot/api/invoice-storno.php` | POST | admin + CSRF |
| `webroot/api/szamlazz-operation-retry.php` | POST | admin + CSRF |
| `webroot/api/invoice-detail.php` | GET | bejelentkezés (a `can_modify`/`can_storno`/`operations`/`original` mezőkkel bővítve) |

### Audit napló

`Database::logAudit()` rögzíti: `invoice_modify_request`,
`invoice_storno_request`, `invoice_operation_manual_retry` — actor
(staff_id), időbélyeg, entity_type='invoice', entity_id, eredmény
(siker/hiba + invoice_number vagy hibaüzenet). Titok sose kerül a
naplóba.

### Valódi NAV sandbox eredmény

Egy teljes CREATE→MODIFY→STORNO lánc (a TELJES production kódúton
keresztül: `Database` → `NavInvoiceProvider::enqueue()`/
`requestModification()`/`requestStorno()` → `submit()`/`checkStatus()`,
UGYANÚGY, ahogy a `NavInvoiceQueueWorker` ténylegesen hívná) mindhárom
lépésben `DONE` végállapotot ért el, a STORNO payloadja igazoltan a
legutóbbi (módosított árú) állapotot vonta vissza. A folyamat során talált
és javított három valódi hiba (fenti "Három, VALÓDI NAV sandbox hívással
feltárt..." szakasz) mind bekerült a `tests/NavInvoiceXmlBuilderTest.php`/
`tests/NavInvoiceProviderTest.php` regressziós tesztjeibe.

### Ismert korlátozások

- A `lineModificationReference`/`lineNumberReference` implementáció azzal
  a feltételezéssel dolgozik, hogy egy MODIFY/STORNO dokumentum tételei
  1:1, sorrendhelyes megfelelésben állnak a lánc korábbi tételeivel — ha
  egy admin egy helyesbítéskor TÖBB/KEVESEBB tételt ad meg, mint az
  eredeti/előző állapot, a `lineNumberReference`-számozás továbbra is
  helyesen FOLYTATÓDIK (a kumulatív offset miatt), de a NAV-oldali
  tétel-szintű "melyik tételt melyik módosítja" megfeleltetés ETTŐL
  FÜGGETLENÜL nincs finomhangolva egy komplex, tételeket cserélő
  módosításhoz — egyszerű esetekre (árváltoztatás, mennyiségi javítás,
  teljes visszavonás) bizonyítottan működik.
- Számlázz.hu STORNO valódi Agent-válasszal (nem csak a hivatalos
  dokumentáció alapján) még nincs sandbox-szal lezárva (a Számlázz.hu-nak
  nincs nyilvános, hitelesítő-adat nélküli teszt-végpontja, ellentétben a
  NAV-val) — a `tests/SzamlazzClientTest.php` a kérés-struktúrát (mezőnév,
  gyökérelem, mezősorrend) egy loopback stub-szerverrel bizonyítja, a
  válasz-értelmezés a meglévő, már bevált `handleResponse()`-ra épül.
- Egy komplex, tételszám-eltérő MODIFY (tétel hozzáadása/törlése) NAV-oldali
  `lineOperation` finomítást igényelhet a jövőben (lásd fent).

## Adatbázis: SQLite vs MySQL

Az app mindkettőn fut, a `config/config.php` → `db.driver` állítja be:

```php
'db' => [
    'driver' => 'sqlite', // vagy 'mysql'
    'sqlite' => ['path' => __DIR__ . '/../data/stock.sqlite'],
    'mysql'  => [
        'host' => '127.0.0.1', 'port' => 3306,
        'database' => 'stock_manager', 'username' => 'stock_manager', 'password' => '',
        'charset' => 'utf8mb4',
    ],
],
```

Az **SQLite** (alapértelmezett) nulla beállítást igényel, és egy kassza
normál kiskereskedelmi forgalmához tényleg megfelelő — a WAL mód
automatikusan bekapcsol, ami lehetővé teszi, hogy az olvasások és
írások egymást ne blokkolják.

A **MySQL 8**-ra érdemes váltani, ha már valódi egyidejű terhelés van —
többen használják egyszerre az appot, forgalmasabb bolt sok napi
eladással, vagy egyszerűen egy "valódi" adatbázis-szervert szeretnél a
könnyebb mentés/replikáció/monitorozás miatt megosztott tárhelyen. A
váltáshoz:

1. Hozz létre egy adatbázist és felhasználót MySQL 8-ban, majd
   importáld a sémát:
   ```
   mysql -u root -p -e "CREATE DATABASE stock_manager CHARACTER SET utf8mb4"
   mysql -u root -p stock_manager < schema.mysql.sql
   ```
2. Töltsd ki a `mysql` blokkot a `config.php`-ban, és állítsd a
   `driver`-t `'mysql'`-re.
3. Győződj meg róla, hogy a `pdo_mysql` PHP kiterjesztés be van
   kapcsolva.

A séma (`schema.mysql.sql`) InnoDB-t használ, `DECIMAL`-t minden
pénzösszeg-oszlophoz (nincs lebegőpontos kerekítési csúszás évek
eladási előzménye után), `JSON`-t a napi zárás bontásaihoz, és
indexeket minden oszlopon, ami szerint az app ténylegesen szűr
(`sales.created_at`, `sale_items.sale_id`,
`products.barcode`/`group_name`, stb.).

A meglévő SQLite telepítések automatikusan nem módosulnak — nincs
beépített SQLite→MySQL adatmigráló eszköz; egy egyszeri, ekkora
léptékű átköltözéshez a SQLite táblák CSV-be exportálása és az új
MySQL sémába (oszlopsorrendet egyeztetve) töltése a pragmatikus út.

### Amit a nagyobb eladási volumenhez optimalizáltunk

- **Nincs több N+1 lekérdezés**: a napi zárás riport korábban
  eladásonként egy külön lekérdezést futtatott a tételek behúzásához;
  most egyetlen `WHERE sale_id IN (...)` lekérdezéssel húzza be egy
  nap összes tételét.
- **Tranzakciók a több-írásos műveletek körül**: egy kassza-eladás, egy
  sok tételes beszerzés, egy WooCommerce behúzás és a CSV import
  mindegyike egy tranzakcióba van csomagolva, ahelyett hogy minden
  utasítást külön committálna — a CSV import esetében (több ezer sor)
  ez különösen sokkal gyorsabb, mind SQLite-on, mind MySQL-en.
- **Kevesebb kör soronként**: a készletfrissítés korábban újra
  lekérdezte (`SELECT`) a terméket rögtön az `UPDATE` után, csak hogy
  naplózza az új mennyiséget; az új érték most a memóriában már
  meglévő adatokból számolódik.
- **A séma/migrációs ellenőrzések most gyorsítótárazva vannak**:
  korábban minden egyes API-kérés újrafuttatta a teljes migrációs
  készletet (több `ALTER TABLE` próbálkozás try/catch-be csomagolva,
  minden alkalommal eldobva). Egy `schema_version` tábla most egyetlen
  indexelt `SELECT`-té teszi ezt a gyors útvonalon — a migrációk csak
  egyszer futnak ténylegesen le, amikor egy adatbázisnak először
  szüksége van rájuk.
- **Indexek hozzáadva** a `sales.created_at`, `sale_items.sale_id`,
  `purchase_items.purchase_id`, és `products.group_name` oszlopokra
  (mind a `schema.sql`, mind a `schema.mysql.sql` tartalmazza).

Ez semmin nem változtat viselkedésben — ugyanazok a funkciók, csak
olcsóbb futtatni, ahogy az eladási tábla tízezres-százezres sorszámra
nő.

### Migráció megbízhatóság / helyreállítás

**Élesben reprodukált és javított hiba**: egy migráció (a Phase 6
"Beérkezett számlák" bejövő-számla tábláinak létrehozása) félbeszakadt
egy fejlesztés közbeni élő szerverkérés miatt úgy, hogy a
`schema_version` már 20-ra ugrott, miközben a 3 új tábla (`incoming_
invoices`/`incoming_invoice_items`/`incoming_invoice_sync`) ténylegesen
NEM jött létre — a "Beérkezett számlák" oldal fatal error-t dobott
minden kérésnél. **A valódi ok**: a migráció nem volt tranzakcióba
csomagolva (minden `CREATE TABLE`/`INSERT` önállóan, azonnal
commit-olt), és a seed-sor beszúrása nem volt idempotens.

**A javítás két rétegű, motoronként eltérő garanciával**:
1. **SQLite**: a migráció SAJÁT tranzakcióba van csomagolva (ugyanaz a
   `$wasInTransaction`-minta, amit a `migrateV6ManualSaleItems()` már
   korábban is használt egy kockázatosabb tábla-újraépítéshez) — SQLite-on
   a `CREATE TABLE`/`CREATE INDEX` TELJES ÉRTÉKŰEN tranzakcionális, tehát
   itt **valódi, teljes atomicitás** érhető el: egy genuinely sikertelen
   migráció a MÁR létrehozott táblákat is visszavonja, a `schema_version`
   SEM lép előre (valódi, reprodukált teszttel bizonyítva, lásd lent).
2. **MySQL**: a `CREATE TABLE`/`ALTER TABLE` a MySQL/InnoDB dokumentált,
   általános viselkedése miatt (implicit commit) tranzakción belül SEM
   vonható vissza — ez NEM ennek a projektnek a korlátja (ugyanez igaz
   pl. a Rails/Laravel/Doctrine migrációs rendszereire is). Az itt
   érvényes, motor-független garancia emiatt NEM a tranzakciós rollback,
   hanem az, hogy **minden migrációs lépés idempotens** (`CREATE TABLE
   IF NOT EXISTS`, `CREATE INDEX IF NOT EXISTS`, és — ez volt a
   konkrétan hiányzó rész — `INSERT OR IGNORE`/`INSERT IGNORE` a
   seed-soroknál sima `INSERT` helyett): egy megszakadt migráció a
   KÖVETKEZŐ kérésnél, a hiányzó résztől folytatva, HIBA NÉLKÜL
   fejeződik be — nincs "poison" állapot, ami minden további kérést
   elhasalna.

**A `schema_version` SOSE léphet előre a szükséges objektumok
létrehozása nélkül, motortól függetlenül** — ez egy STRUKTURÁLIS
garancia: az `ensureSchema()` a teljes migrációs láncot (minden
`migrateVN...()` hívást) egyetlen, KÖRÜLÖTTE lévő try/catch NÉLKÜLI
szekvenciában futtatja, a `setSchemaVersion()` pedig ennek a
szekvenciának a LEGUTOLSÓ sora — emiatt BÁRMELYIK migrációs lépésből
származó kivétel (a tényleges hiba, VAGY akár egy másodlagos, a
rollback-kísérlet során felmerülő kivétel MySQL-en, ahol a PDO
tranzakció-állapota az implicit commit után bizonytalan lehet)
garantáltan megakadályozza, hogy a `setSchemaVersion()` valaha
lefusson — ezt közvetlenül, sor-szintű kódátvizsgálással ellenőriztem
(nem csak feltételeztem).

**MySQL-specifikus korlát, átlátszóan dokumentálva**: ebben a
fejlesztői környezetben NEM állt rendelkezésre futó MySQL-szerver — a
fenti MySQL-re vonatkozó elemzés (idempotencia, biztonságos
újrafuttathatóság) kód-szintű átvizsgáláson és a MySQL dokumentált,
általános DDL/implicit-commit viselkedésén alapul, NEM egy éles
MySQL-en ténylegesen lefuttatott teszten. Production MySQL-bevezetés
előtt érdemes a `tests/MigrationAtomicityTest.php` konkurrencia- és
megszakítás-teszteit egy valódi MySQL-példányon is lefuttatni (a
tesztek maguk motor-függetlenek, csak a `config/config.php` `db.driver`
átállítása szükséges hozzá).

**Tesztek** (`tests/MigrationAtomicityTest.php`): megszakadt-majd-
újrafuttatott migráció hibamentesen befejeződik (két különböző
megszakítási ponton is); egy GENUINE hiba a MÁR létrehozott táblákat is
visszavonja SQLite-on, a `schema_version` nem lép előre; valódi,
16 párhuzamos folyamattal futtatott konkurrencia-teszt igazolja, hogy
több egyidejű `Database`-példányosítás (mind ugyanazt a migrálatlan
fájlt látva) sose hibázik, és a végállapot konzisztens (mindhárom
tábla létrejön, pontosan egy seed-sor).

**Ha egy éles adatbázis mégis ebbe az állapotba kerülne** (`schema_
version` már a legújabb, de egy migrációhoz tartozó tábla hiányzik):
az adott `migrateVN...()` metódus egy PHP Reflection-nal közvetlenül
újra lefuttatható (lásd a fenti javítás előtti hiba diagnosztizálásának
és javításának menetét) — VAGY egyszerűbben, a `schema_version` sort
kézzel eggyel visszaállítva a következő `ensureSchema()`-hívás (bármelyik
oldal megnyitása) automatikusan, hiba nélkül újra lefuttatja a hiányzó
migrációt (a fenti idempotencia-garancia miatt). Mindkét esetben
KÖTELEZŐ egy biztonsági mentés készítése a `data/stock.sqlite`-ról
előtte.

## Logó és automatikus szinkron (felső sáv beállításai)

Minden oldal felső sávjában van egy logó (bal felül), egy szinkron
ikon és egy fogaskerék ikon, mindkettő a dedikált **`beallitasok.html`**
(Beállítások) oldalra mutat — a beállítások már nem egy felugró ablak,
így könnyen könyvjelzőzhetők vagy közvetlenül linkelhetők. Az az oldal
egy teljes szélességű, fülekre bontott elrendezés:

- **Logó**: menj a Beállítások → Logó fülre PNG/JPG/WEBP/SVG
  feltöltéséhez (max 2 MB). A `webroot/assets/logo.<kiterjesztés>`
  helyre mentődik, felülírva bármely korábbi feltöltést. Az
  "Alaplogóra visszaállítás" eltávolítja, és visszaesik a becsomagolt
  `assets/logo-default.svg`-re.
- **Szinkron ikon**: bármikor kattintható egy azonnali WooCommerce
  behúzáshoz (ugyanaz, mint a régi gomb, csak most ikon — pörög futás
  közben, toast-üzenetben mutatja az eredményt).
- **Automatikus szinkron**: a Beállítások → Szinkronizálás fülön
  kapcsolható be és állítható be az időköz. Ez egy beállítást vált a
  `data/settings.json`-ban — **nem** fut magától a háttérben, mivel
  semmi nem tartja "ébren" a PHP beépített szerverét a kérések között.
  Hogy ténylegesen fusson, adj hozzá egy cron feladatot, ami meghívja
  az ellenőrző-és-futtató végpontot (biztonságos percenként hívni —
  no-op marad, amíg a beállított időköz le nem telik):

  ```
  * * * * * curl -s http://localhost:8000/api/auto-sync-run.php > /dev/null
  ```

  Az utolsó automatikus futás időpontja és eredménye ugyanazon a
  beállítás-fülön jelenik meg.

## Napi zárás (napi zárás / eladási összesítő)

A `zaras.html` (minden oldalról linkelve) egy forgalmi összesítőt mutat
bármely dátumra:

- Összesítők: eladások száma, bruttó/nettó bevétel, beszedett áfa
- Bontás fizetési mód szerint (Készpénz/Átutalás/Bankkártya/...) —
  minden kassza-eladás mostantól rögzít egy fizetési módot (egy
  választó a végösszeg mellett, mindig látható, nem csak számla
  igénylésekor)
- Bontás áfakulcs szerint
- A nap egyedi tranzakciói, mindegyikhez egy "Nyugta" link a nyugta
  újranyomtatásához
- A **"Nyomtatás"** kinyomtatja a teljes összesítő oldalt a böngészőn
  keresztül (egy nyomtatási stíluslap elrejti a navigációt/gombokat,
  csak a riportot hagyja meg)
- A **"Napi zárás rögzítése"** eltárol egy zárási rekordot az adott
  dátumra a `closings` táblában. Ugyanarra a dátumra újra futtatva
  felülírja a rekordot (hasznos, ha egy késői számla-újrapróbálkozás
  megváltoztatta a számokat) — ez egy könyvelési pillanatkép, nem
  valami, ami blokkolná a további eladásokat azon a dátumon.

## Nyugtanyomtató támogatás

Két független mód a nyugta nyomtatására, a `receipt.html`-ről elérhetők
(fizetés után a "Nyugta megtekintése/nyomtatása" gombbal nyílik meg,
vagy a "Nyugta" linkkel a Napi zárás oldalról):

- **Böngészőből**: mindig elérhető, szó szerint bármelyik nyomtatóval
  működik, amihez az operációs rendszerednek már van drivere — ez
  egyszerűen `window.print()` egy formázott nyugta-nézeten.
- **Hálózati nyomtatóra**: nyers ESC/POS parancsokat küld TCP-n
  keresztül egy hálózati hőnyomtató "raw"/9100-as portos felületére —
  ugyanaz a mechanizmus, amit a legtöbb megfizethető Ethernet/WiFi
  nyugtanyomtató használ (Epson TM-*, Xprinter, Zjiang, stb.), nem kell
  hozzá speciális driver. Állítsd be az IP-t/portot/papírszélességet a
  Beállítások → Nyomtató alatt.

### Karakterkódolás (ékezetes karakterek)

**Korábbi, hibás állapot (javítva)**: az `EscPosPrinter` korábban minden
ékezetes karaktert egyszerű ASCII-re transzliterált (iconv TRANSLIT),
ami a VALÓS nyomtatón olvashatatlan, aposztróf-szerű torzítást okozott
("Termék" helyett "Term'ek") — a nyomtató ténylegesen támogatta a
megfelelő kódlapot, csak sose lett kiválasztva/elküldve neki.

A javítás után a nyomtató a `ESC t` (Select character code table)
paranccsal explicit kiválasztja a kívánt kódlapot, és a szöveget ARRA a
kódlapra konvertálja (nem ASCII-re). Az Epson TM-T20III hivatalos
ESC/POS Command Reference-e (download4.epson.biz) és a modellenkénti
Code Page Support tábla alapján a TM-T20III a 0-5, 11-21, 26, 30-53
kódlap-oldalakat támogatja — ebbe három, a magyar ékezetes
karakterkészletet (á é í ó ö ő ú ü ű + nagybetűs változatok)
teljeskörűen lefedő kódlap tartozik:

| Beállítás | ESC/POS kódlap | `ESC t` paraméter |
|---|---|---|
| `cp852` (alapértelmezett) | PC852 (DOS Latin 2) | 18 |
| `cp1250` | WPC1250 (Windows Latin 2) | 45 |
| `iso88592` | ISO-8859-2 (Latin 2) | 39 |

**Valódi Epson TM-T20III hardveren ellenőrizve** (nem csak feltételezve):
a `cp852` alapértelmezéssel egy élő teszt nyomtatás (`ÁÉÍÓÖŐÚÜŰ áéíóöőúüű`
és "Árvíztűrő tükörfúrógép") helyesen, torzítás nélkül jelent meg a
papíron. Ha egy MÁSIK Epson/ESC-POS modellen az alapértelmezett kódlap
mégsem lenne megfelelő, válts a Beállítások → Nyomtató "Kódlap"
legördülőjén — mindhárom opció hivatalosan igazoltan támogatott, a
"Teszt nyomtatás" gombbal azonnal ellenőrizhető, melyik ad helyes
eredményt a saját nyomtatódon.

A böngészős nyomtatásnak nincs ilyen korlátja, mivel az normál HTML/CSS.

A csak-USB (nem hálózati) hőnyomtatók közvetlenül nem támogatottak;
vagy állíts eléjük egy kis nyomtatószervert, vagy hagyatkozz a
böngészős nyomtatásra, ha az operációs rendszernek már van hozzá
drivere.

### QR-kód a nyugtán

Ha a nyomtatód támogatja (az Epson TM-T20III hivatalosan igazoltan
támogatja — `GS ( k` Two-dimensional Code Commands, `<Function 165/167/
169/180/181>`, valódi hardveren tesztelve), a "QR-kód nyomtatása a
nyugtára" bekapcsolásával minden nyomtatott nyugta aljára egy QR-kód
kerül, ami a MÁR MEGLÉVŐ digitális nyugta linkjére mutat
(`receipt.html?sale_id=...&token=...`). Ehhez meg kell adni egy
"Digitális nyugta publikus alap-URL"-t (a vevő telefonjáról is elérhető
webcím) — ha ez üres, a QR-kód egyszerűen kimarad (SOSE generálunk egy
nem működő linket). A "Teszt nyomtatás" gomb mellett egy "Teszt
nyomtatás mintát is tartalmazzon" jelölőnégyzettel egy minta-QR-kód is
kérhető (fix, nem-üzleti teszt-szöveggel), a nyomtató QR-támogatásának
ellenőrzésére, digitális nyugta beállítása nélkül is.

### Automatikus hálózati nyomtatás

A "Automatikus nyomtatás minden kasszaeladás után" bekapcsolásával
minden sikeres kasszaeladás után a rendszer automatikusan elküldi a
nyugtát a beállított hálózati nyomtatóra — alapból KIKAPCSOLVA. **A
nyomtatási hiba SOSE rontja el/vonja vissza az eladást**: az eladás a
nyomtatás megkísérlése ELŐTT már véglegesen, tartósan rögzítve van
(lásd `webroot/api/sale.php` — a nyomtatás a tranzakció COMMIT-ja
UTÁNI, teljesen különálló, hibatűrő lépés). Ha a nyomtatás mégis
sikertelen, a kasszás egyértelmű "Figyelem: az automatikus nyomtatás
sikertelen" visszajelzést kap, és a nyugta a "Nyugta megtekintése /
nyomtatása" gombbal bármikor manuálisan újranyomtatható — nincs
külön "retry" mechanizmus/queue, mert egy azonnali kasszai
visszajelzés + egyszerű manuális újranyomtatás elegendő (nem egy
háttérben, később is befejeződő NAV-jellegű folyamat).

## Automatikus mentések (helyi + Dropbox/Google Drive)

A Beállítások → Mentés (minden oldalról linkelve) beállítja az
adatbázis automatikus napi mentését — ugyanúgy működik, függetlenül
attól, melyik drivert használod:

- **Mentés most**: azonnal készít egy mentést, az ütemezéstől
  függetlenül.
- **Automatikus napi mentés**: kapcsold be, és válassz egy időpontot.
  **SQLite**-on egy teljes pillanatkép készül `VACUUM INTO` paranccsal
  (biztonságos akkor is, ha az app közben használatban van, szemben a
  nyers fájl másolásával) — az eredmény egy `.sqlite` fájl.
  **MySQL**-en a `mysqldump`-ot hívja meg, ha elérhető
  (`--single-transaction`, hogy konzisztens pillanatkép legyen
  táblazárolás nélkül), vagy egy tisztán PHP-alapú dumper-re esik
  vissza, ami sorban a lemezre streameli a sorokat, ha az `exec()` le
  van tiltva — az eredmény mindkét esetben egy sima `.sql` fájl, ami
  `mysql -u ... database < backup.sql` paranccsal visszaállítható. Az
  automatikus szinkronhoz hasonlóan ehhez is kell egy cron feladat,
  hogy ténylegesen elsüljön, amíg a böngésző nincs nyitva:

  ```
  */15 * * * * curl -s http://localhost:8000/api/auto-backup-run.php > /dev/null
  ```

  Maga a végpont csak naponta egyszer fut le, a beállított időpontban
  vagy azután — a cron gyakoribb ütemezése ennél ártalmatlan.

- **Megőrzött mentések (retention)**: alapból **7** — minden mentés
  után az ennél régebbi mentések törlődnek, mind helyben
  (`data/backups/`), mind a felhőben, ha be van állítva egy
  szolgáltató. Ugyanezen a fülön állítható.
- **Titkosítás nyugalmi állapotban**: minden mentés-fájl (a DB-pillanatkép
  ÉS a mellé csomagolt `settings.json` — benne minden WooCommerce/
  Számlázz.hu/NAV/felhő hitelesítő adattal) AES-256-GCM-mel titkosítva
  kerül lemezre/felhőbe, `.enc` kiterjesztéssel. A titkosító kulcs a
  `data/.backup-encryption-key` fájlban él (automatikusan generálódik
  első használatkor, `chmod 600`), a mentésektől külön. **Ezt a kulcsot
  a data/ mappa többi tartalmával együtt kell biztonságban tartani/külön
  is menteni** — ha elvész, minden korábban készült titkosított mentés
  véglegesen visszaállíthatatlanná válik (nincs "elfelejtett kulcs"
  helyreállítás). Ez nem helyettesít egy valódi kulcskezelő szolgáltatást
  (KMS/HSM) — egy ellopott/rosszul konfigurált felhő-tárhelyre feltöltött
  mentési fájl ellen véd, nem egy teljes szerver-kompromisszum ellen.

### Felhő szinkron

Két opcionális szolgáltató választható a "Felhő szinkronizálás"
legördülőből:

- **Dropbox** — az egyszerűbb út. A [Dropbox App
  Console](https://www.dropbox.com/developers/apps) oldalon hozz létre
  egy appot, majd a Permissions fülön kapcsold be a
  `files.content.write` és `files.content.read` jogokat, aztán
  generálj egy hozzáférési tokent az app beállítás-oldalán, és illeszd
  be. Nincs szükség böngészős OAuth folyamatra egy ilyen egyszeri,
  személyes használati esethez.

- **Google Drive** — sajnos nincs hasonló egy-kattintásos token; a
  Google egyszeri OAuth2 beállítást igényel:
  1. A [Google Cloud Console](https://console.cloud.google.com/)
     oldalon hozz létre egy projektet, kapcsold be a **Google Drive
     API**-t, és hozz létre "Desktop app" típusú OAuth 2.0 hitelesítő
     adatokat — ez ad egy Client ID-t és Client Secret-et.
  2. Szerezz egy refresh tokent egyszer, ezekkel az adatokkal — a
     leggyorsabb út a [Google OAuth 2.0
     Playground](https://developers.google.com/oauthplayground):
     fogaskerék ikon → "Use your own OAuth credentials" bejelölése →
     illeszd be a Client ID-t/Secret-et → az 1. lépésben válaszd a
     Drive API v3 `drive.file` hatókört → engedélyezés → a 2. lépésben
     kattints az "Exchange authorization code for tokens" gombra →
     másold ki a megjelenő **refresh tokent**.
  3. Illeszd be a Client ID-t, Client Secret-et és a refresh tokent a
     Beállításokba. (Opcionálisan egy célmappa Drive ID-ja is
     megadható — üresen hagyva a Drive gyökerébe tölt fel.)

  Az access tokenek óránként lejárnak; a `GoogleDriveProvider` minden
  mentéskor újra becseréli a refresh tokent egy frissre, így egyszeri
  beállítás után ez működni fog, amíg maga a refresh token nincs
  visszavonva.

Egyszerre csak egy szolgáltató van használatban (amelyik ki van
választva) — ez nem egyidejű, mindkettőre való tükrözésre lett
tervezve.

## Önfrissítés (GitHub Release-alapú)

A FountainTrade a GitHub-ot (`zsoltacsai/stock-manager-app`, lásd
`config/config.php` `update` szakasza) használja EGYETLEN hivatalos
frissítés-forrásként. **SOSE a `main` branch aktuális állapotát** tölti le —
kizárólag egy explicit, publikált (nem draft, nem prerelease) **GitHub
Release**-t, annak `manifest.json` és `fountaintrade-{verzió}.zip`
csatolmányain keresztül.

### Verziókezelés

- **`current_version`**: a ténylegesen telepített kód verziója —
  `src/AppVersion.php::CURRENT`, EGYETLEN központi forrás (mindenhol ebből
  olvasva: rendszerállapot oldal, frissítés-ellenőrzés stb.).
- **`latest_version`**: a legutóbbi ellenőrzéskor talált, GitHub-on elérhető
  verzió (`update_state` tábla).
- **`channel`**: jelenleg csak `stable` létezik.
- Verziószám: SemVer (`1.0.0`, `1.1.0`, `1.1.1`). **Downgrade blokkolva.**

### Release készítése (fejlesztői/kiadási folyamat)

1. Verzióemelés: `src/AppVersion.php::CURRENT` frissítése az új verzióra.
2. Git tag létrehozása (`v1.1.0` formátumban) a kiadandó commit-on, majd
   GitHub Release létrehozása ebből a tag-ből (NEM draft).
3. Artifact összeállítása: a tag-elt commit tartalmának egy ZIP-je
   (pontosan azok a fájlok/mappák, amiket a `git` verziókezel — lásd
   `.gitignore` —, tehát a `data/`, `config/installer-generated.php`,
   `invoices/`, feltöltött `webroot/assets/*` fájlok SOSE kerülnek bele),
   `fountaintrade-1.1.0.zip` néven.
4. `manifest.json` összeállítása és csatolása a Release-hez:
   ```json
   {
     "product": "FountainTrade",
     "channel": "stable",
     "version": "1.1.0",
     "commit": "<a tag-elt commit TELJES SHA-ja>",
     "artifact": "fountaintrade-1.1.0.zip",
     "sha256": "<a ZIP fájl SHA-256 hash-e>",
     "min_upgradable_version": "1.0.0"
   }
   ```
   A `sha256` a TÉNYLEGESEN feltöltött ZIP hash-e legyen (pl.
   `sha256sum fountaintrade-1.1.0.zip`), a `commit` pedig a tag által
   ténylegesen jelölt commit teljes SHA-ja — mindkettőt a kliens
   FÜGGETLENÜL, a GitHub API-ból is leellenőrzi (lásd
   `src/UpdateVerifier.php`), tehát egy elírt/elavult manifest-mező
   egyszerűen elutasított frissítést eredményez, nem hibás telepítést.
5. `min_upgradable_version`: ha az új release-re csak egy adott, minimum
   telepített verzióról lehet közvetlenül frissíteni (pl. egy köztes
   migráció miatt), itt kell megadni — egy régebbi verzióról induló
   kliens a telepítés helyett egyértelmű hibaüzenetet kap.

### Biztonság — mit ellenőriz a kliens, és mit SOSE fogad el vakon

- A letöltés KIZÁRÓLAG a valódi GitHub-infrastruktúra (`github.com`,
  `api.github.com`, `objects.githubusercontent.com`,
  `release-assets.githubusercontent.com` — ez utóbbi egy valódi
  kiadás-letöltés során derült ki tényleges átirányítási célként, lásd
  `GitHubReleaseClient::ALLOWED_ASSET_HOSTS` docblokkja) felé
  engedélyezett — sem a manifest, sem a release JSON semmilyen mezője nem
  befolyásolhatja ezt, és az ellenőrzés az ESETLEGES átirányítás UTÁNI
  tényleges célt is újra ellenőrzi (lásd
  `GitHubReleaseClient::downloadAsset()`).
- A manifest `version`-je a GitHub tag NEVÉVEL, a `commit`-ja a GitHub Git
  Data API-ból FÜGGETLENÜL feloldott commit-SHA-val, a letöltött fájl
  valódi SHA-256 hash-e a manifest `sha256` mezőjével kerül összevetésre —
  a manifest ÖNMAGÁBAN sose tekinthető megbízhatónak.
- A ZIP kicsomagolása előtt minden bejegyzés path traversal (`..`),
  abszolút útvonal, Windows-meghajtóbetűjel és szimlink szempontjából
  ellenőrzött ("Zip Slip" védelem) — egyetlen gyanús bejegyzés a TELJES
  kicsomagolást megszakítja.
- Downgrade és a `min_upgradable_version`-nél régebbi telepítésről indított
  frissítés blokkolva.

### Automatikus ellenőrzés (cron)

Az ellenőrzés (SOSE a tényleges telepítés) egy olcsó, cron-hívott végpont:

```
*/30 * * * * curl -s -X GET https://kassza.pelda.hu/api/update-check-run.php \
    -H "X-Cron-Token: <a Beállítások → Mentés alatt beállított cron_secret>"
```

A végpont maga dönti el, esedékes-e ténylegesen egy GitHub-lekérdezés a
Beállítások → Frissítések alatt beállított időköz (6/12/24 óra/7 nap)
alapján — a percenkénti/félóránkénti hívás ártalmatlan. Csak akkor fut,
ha a "Automatikus ellenőrzés" be van kapcsolva (alapból KIKAPCSOLVA).

### Automatikus (felügyelet nélküli) telepítés (cron, CLI)

**Csak akkor állítsd be, ha kifejezetten felügyelet nélküli, éjszakai
frissítést szeretnél** — a Beállítások → Frissítések "Automatikus
telepítés" kapcsolója (alapból KIKAPCSOLVA) engedélyezi, a tényleges
telepítést pedig egy KÜLÖN CLI-szkript végzi, SOSE egy HTTP-végpont (a
telepítés percekig tarthat — HTTP-timeout-nak sose szabad kiszolgáltatva
lennie):

```
0 4 * * * /usr/bin/php8.3 /var/www/stock-manager-app/tools/update-install-cli.php >> /var/log/fountaintrade-update.log 2>&1
```

Ez a szkript saját maga ellenőriz (nem kell külön `update-check-run.php`
előtte), és csak akkor telepít, ha ténylegesen van újabb, érvényes release
ÉS az automatikus telepítés be van kapcsolva.

**Fontos, éles (nginx+php-fpm) telepítésen ellenőrizendő beállítás**:
`config/config.php` `update.php_cli_binary` mezője — a tényleges
migráció/egészség-ellenőrzés egy VALÓDI CLI PHP-folyamatban fut (lásd
lentebb), amihez a rendszer CLI PHP-jának elérési útja kell (pl.
`/usr/bin/php8.3`), NEM a php-fpm futtatható. Ha ez hibásan van
beállítva, a telepítés az ELŐKÉSZÍTÉS-ellenőrzés (preflight) szakaszban,
MIELŐTT bármi production-fájlhoz nyúlna, egyértelmű hibával leáll.

### Manuális telepítés (Beállítások → Frissítések → "Frissítés most")

Admin jogosultságot és a szokásos CSRF-védelmet igényli. A böngésző
azonnal "elindítva" választ kap — a tényleges telepítés a php-fpm
`fastcgi_finish_request()` mechanizmusával a válasz elküldése UTÁN,
ugyanabban a szerver-oldali folyamatban folytatódik (nem HTTP-
timeout-hoz kötött). A beépített PHP fejlesztői szerver (`php -S`, amin
ez fejlesztés közben tesztelve is lett) nem ismeri ezt a mechanizmust —
ott a kérés a telepítés végéig szinkron blokkol, ami fejlesztői
környezetben elfogadható.

### Mi történik telepítéskor (állapotgép)

```
idle → checking → update_available → downloading → verifying →
backing_up → maintenance → installing → migrating → health_check →
completed
```

Hiba esetén: `failed` (ha még semmi nem íródott ki production-útvonalra),
vagy `rolling_back` → `rolled_back` (ha már íródtak ki fájlok, de a
migráció/egészség-ellenőrzés elbukott — ilyenkor a fájlok ÉS a teljes
adatbázis is visszaáll a telepítés ELŐTTI állapotra), vagy — ha maga a
visszaállítás is elbukik — `manual_recovery_required` (ilyenkor a
karbantartási mód SZÁNDÉKOSAN bekapcsolva marad, és SSH-s kézi
beavatkozás szükséges: `data/update-rollback/` alatt megtalálható a
telepítés előtti kódfa legutóbbi mentése, `data/backups/` alatt a
telepítés előtti adatbázis-mentés).

**Miért nem szimlinkes "releases/x.y.z + current" atomikus csere**: a
dokumentált production telepítés (lásd lentebb, "Beüzemelés") nginx-et
használ, aminek `root` direktívája közvetlenül a `webroot/` almappára
mutat, nem egy szimlinkre — ennek átállítása egy külön, opcionális
infrastruktúra-döntés lenne az üzemeltető részéről. Helyette a
FountainTrade egy EGYENÉRTÉKŰ biztonsági garanciájú, másolás-alapú
mechanizmust használ: a release teljes egészében egy staging könyvtárban
validálódik, a jelenlegi élő kódfa (a data/config/invoices/feltöltött
fájlok KIVÉTELÉVEL) egy rollback-mentésbe másolódik MIELŐTT bármi
felülíródna, és bármilyen hiba esetén ebből a mentésből áll vissza minden.

### Mit ŐRIZ MEG egy frissítés — konfiguráció-megőrzés

Egy release-artifact KIZÁRÓLAG az alkalmazás kódját tartalmazza. Egy
frissítés SOSE nyúl (nem is tartalmazza a letöltött csomag):
`config/` (beleértve a telepítő által generált `installer-generated.php`-t
is), `data/` (adatbázis, `settings.json`, mentések, NAV-token-cache stb.),
`invoices/` (kiállított PDF-ek), és a `webroot/assets/` alatti FELTÖLTÖTT
tartalom (logók, termékképek — a `.gitignore`-ban is védett minták).

### Frissítési előzmények

Beállítások → Frissítések → "Előzmények": minden ténylegesen megkísérelt
(admin- vagy cron-indított) frissítés tartós naplója — dátum, forrás-
és cél-verzió, indító (admin/cron), eredmény, backup-hivatkozás,
visszaállítási állapot.

## Ismert korlátok / útközben eldöntendő dolgok

- Az áfakulcs termékenként van tárolva (`vat_rate`, alapból a
  configból) — győződj meg róla, hogy egyezik a WooCommerce terméken
  beállítottal, mivel a WooCommerce REST API-ja nem ad megbízhatóan
  hozzáférést magához az adókulcs-értékhez.
- A számla vevője alapból egy általános "készpénzes vevő" — köss be
  egy valódi vevő-keresést/űrlapot, ha névre szóló számla kell a
  kasszánál.
- Nincs hitelesítés a helyi web-felületen — egyetlen kassza-gépen, egy
  megbízható helyi hálózaton futásra lett szánva. Tégy elé HTTP basic
  auth-ot (vagy kösd a PHP szerverét kizárólag a 127.0.0.1-hez), ha
  nálad nem ez a beállítás.

## Legújabb ebben a körben

- **Import javítás**: `.xls`/`.xlsx` fájlok mostantól közvetlenül
  feltölthetők — a szerver felismeri a bináris Excel fájlokat
  (fájl-aláírás alapján, nem kiterjesztés alapján), és automatikusan
  konvertálja `soffice --headless --convert-to csv` paranccsal, ha
  telepítve van a LibreOffice. Ha nincs, egyértelmű hibaüzenetet kapsz,
  ami kézi konverzióra kér a régi, csendben félreértelmezett adat
  helyett.
- **Irányítószám → Település automatikus kitöltés**: egy 4-jegyű
  irányítószám begépelése a "Vevő számlát kér" űrlapon automatikusan
  kitölti a települést, egy becsomagolt `data/irsz.csv` fájlból (3038
  irányítószám).
- **NAV cégadat lekérdezés** (best-effort): egy "Lekérdezés" gomb az
  adószám mező mellett kitöltheti a cégnevet/címet a NAV Online Számla
  `queryTaxpayer` API-ján keresztül. **Ehhez saját NAV technikai
  felhasználó hitelesítő adat kell** (Beállítások → Számlázz.hu → NAV
  cégadat lekérdezés), és nincs élő NAV-fiókon letesztelve — ellenőrizd
  az `api-test.onlineszamla.nav.gov.hu`-val, mielőtt élesben
  hagyatkoznál rá. Hitelesítő adatok szerzéséhez: regisztrálj egy
  ingyenes "technikai felhasználót" az onlineszamla.nav.gov.hu oldalon
  (Beállítások → Technikai felhasználó létrehozása), ami ad egy
  login/jelszó párost, plusz aláíró és csere kulcsokat.
- **Beállítások, nem csak config.php**: a Számlázz.hu és WooCommerce
  hitelesítő adatok mostantól a Beállításokból is beállíthatók (a
  WooCommerce fülön egy rövid magyarázattal a behúzás/kiküldés/webhook
  szinkronról) — felülírják a `config.php`-t, ha ki vannak töltve, így
  nem kell fájlokat kézzel szerkeszteni a napi kulcs-rotációhoz.
- **Alacsony készlet riasztás**: egy globális alapértelmezett küszöb
  (Beállítások → Készlet riasztás), termékenként felülírható (Árucikkek
  → Módosítás). A küszöbön vagy az alatt lévő termékek sárga jelvényt
  kapnak az Árucikkeknél; egy opcionális webhook és/vagy e-mail elsül
  közvetlenül egy olyan eladás után, ami átlépi a határt.
- **Túlértékesítés engedélyezett**: a kassza már nem blokkol egy
  eladást elégtelen készlet miatt — rögzíti az eladást, hagyja
  negatívba menni a készletet, és jelzi a fizetési válaszban és a
  kosárban is (a negatívba forduló sorok kiemelve). A következő
  beszerzésnél korrigálható.
- **Árucikkek lista**: az oszlopfejlécek mostantól kattinthatók a
  rendezéshez (név, cikkszám, csoport, vonalkód, készlet, árak) — újra
  kattintva megfordítja.
- Az **Eladások** és **Beszerzések** mostantól saját oldalak: minden
  korábbi eladás/beszerzés kereshető dátum (natív naptár-választó),
  azonosító, és név/cégnév szerint, átkattintható részletnézettel.
- **Bal oldali ikon-oldalsáv** került minden oldalra, a meglévő felső
  navigáció mellé — Kassza/Beszerzés/Árucikkek/Napi zárás plusz a
  logód és egy beállítás-gyorsgomb, gyorsabb váltáshoz a linkfeliratok
  olvasása nélkül.
- A dátummezők (Napi zárás, Eladások, Beszerzések) mostantól natív
  `<input type="date">` mezők — kattints bárhova a mezőben egy
  naptárért.
- A "Fizetési mód" legördülő (és általában minden `<select>` elem)
  mostantól illeszkedik az app sötét sablonjához, a böngésző
  alapértelmezett stílusa helyett.
- **Világos sablon**: Beállítások → Megjelenés lehetővé teszi a váltást
  sötét és világos között. Mivel a színek egyszer vannak definiálva CSS
  változóként, és mindenhol azokra hivatkozik a kód, a világos sablon
  egyetlen felülíró blokk a `style.css`-ben — nincs szükség
  oldalankénti stílusra. A választás mentésre kerül mind a
  `localStorage`-ba (hogy azonnal érvényesüljön a következő
  oldalbetöltéskor, mielőtt a stíluslap egyébként sötétre villanna),
  mind a `data/settings.json`-ba (hogy egy friss böngésző-profilból is
  emlékezzen rá).
- **Kamerás vonalkód-olvasás**: minden vonalkód mező (Kassza,
  Beszerzés, a termék-szerkesztő ablak, és az Árucikkek keresőszűrő)
  mostantól egy kamera ikont kap mellette, ami egy élő szkennert nyit a
  böngésző beépített `BarcodeDetector` API-jával — nincs külső könyvtár
  vagy CDN-függőség. **Böngésző-támogatási korlát**: 2026 eleje szerint
  ez az API csak Chromium-alapú böngészőkben létezik (Chrome, Edge,
  Opera, Android WebView) — a Safari és a Firefox nem implementálja,
  így ott a gomb egy egyértelmű üzenetet mutat, ami visszairányít a
  kézi bevitelhez vagy egy USB-szkennerhez, ahelyett hogy csendben
  elhasalna. Emellett HTTPS-t vagy `localhost`-ot igényel (a böngészők
  letiltják a kamera-hozzáférést sima HTTP-n bármely más hoszton) — ha
  az app egy LAN IP-n fut HTTP-n, a kamera gomb ott sem fog működni; a
  kézi/USB-szkenneres bevitelt ez egyik esetben sem érinti.

## Beszállító-törzs

Egy új `beszallitok.html` oldal kezeli a mentett beszállítókat (név,
kapcsolat, cím, adószám, fizetési feltételek). A Beszerzés oldalon egy
mentett beszállító kiválasztása az új legördülőből automatikusan
kitölti a meglévő szabadszöveges mezőket, és összeköti a
`supplier_id`-t a beszerzési rekorddal — az egyszeri/nem regisztrált
beszállítók továbbra is pontosan úgy működnek, mint korábban, ha
közvetlenül begépeled a mezőket.

## Törzsvásárlói / hűségpont rendszer

Alapból kikapcsolva — kapcsold be a Beállítások → Törzsvásárlói pontok
alatt, ahol a két arányt is beállítod: hány Ft költés ér 1 pontot, és 1
pont mennyi kedvezményt ér beváltáskor. Bekapcsolva:

- A Kassza fizetés egy vásárló-keresőmezőt kap (név vagy telefonszám
  szerint). Egy vásárló kiválasztása megmutatja a pontegyenlegét, és
  lehetővé teszi az eladónak, hogy némelyiket kedvezményként beváltsa
  az eladás befejezése előtt; a pontok utána a ténylegesen kifizetett
  összeg alapján íródnak jóvá.
- A `vasarlok.html` kezeli a vásárlólistát, és megmutatja minden
  vásárló teljes pontelőzményét (minden jóváírás/beváltás, azzal az
  eladással, amiből származott).
- **Ismert korlát**: ha egy vásárló egyszerre vált be pontokat *és* kér
  névre szóló számlát ugyanabban az eladásban, a Számlázz.hu számla a
  teljes, kedvezmény előtti összegre kerül kiállításra, nem a
  ténylegesen fizetett, csökkentett végösszegre — egy kedvezmény
  helyes, vegyes áfakulcsok közötti arányosítása nem tűnt megérni a
  bonyolultságot egy a gyakorlatban valószínűleg ritka kombinációhoz (a
  betérő hűségpont-beváltás és a névre szóló B2B számla általában nem
  szokott egybeesni).

## Rendszerállapot (rendszerállapot oldal)

A `rendszerallapot.php` egy pillantásra összefogja mindazt, ami
egyébként szét lenne szórva a Beállítások fülein és a szinkron-naplóban
— lásd a részletes leírást a "FountainTrade 1.4.0 — Operations &
Reliability" szakaszban.

## Telepíthető mobil-app (PWA)

Az app telepíthető — "Hozzáadás a kezdőképernyőhöz" mobilon, vagy a
telepítés ikon a Chrome/Edge címsorában asztali gépen — egy manifest és
egy service worker segítségével (`manifest.json`, `sw.js`). Amit ez ad,
és amit nem:

- **Ad**: egy app ikont, egy önálló ablakot (böngésző-keret nélkül), és
  a felület (HTML/CSS/JS) azonnal betölt gyorsítótárból, még egy
  akadozó kapcsolaton is.
- **Nem ad**: teljesen offline működést a tényleges kasszahasználathoz.
  A termékárak, készletszintek, és minden írás (eladás, beszerzés,
  szinkron) továbbra is a szervert igényli — a service worker
  szándékosan sosem gyorsítótáraz `/api/` válaszokat, mivel egy
  elavult ár vagy készletszám mutatása fizetéskor rosszabb lenne, mint
  egy egyértelmű "nincs kapcsolat" hiba. Ha a hálózat kiesik műszak
  közben, az app kerete még megnyílik, de az eladás rögzítése nem fog
  működni, amíg vissza nem jön.
- A kamerás szkennerhez hasonlóan a telepíthetőség maga is HTTPS-t vagy
  `localhost`-ot igényel — a böngészők nem regisztrálnak service
  workert sima HTTP-n egy LAN IP-n.

## Vásárlói törzs a számla-űrlapon (Kassza)

A `customers` tábla (amit már a hűségpontok is használnak) mostantól
számlázási adatokat is tárol (irányítószám, város, cím, ország), így
egyben címjegyzékként is szolgál számlákhoz. A "Vevő számlát kér"
űrlapon:

- A **Név / Cégnév** mezőbe gépelés élőben keres a mentett vásárlók
  között, és a találatokat a mező alatt mutatja — egy kiválasztása
  kitölti a címet, adószámot, és automatikusan "céges"-re vált, ha a
  vásárlónak van adószáma.
- A mező melletti ikon egy teljes választót nyit meg: minden mentett
  vásárló, kereshetően, soronként **Kiválasztás** (kitölti az
  űrlapot) és **Szerkesztés** (a mentett adatok szerkesztése)
  gombokkal, plusz **+ Új vásárló** egy új felvételéhez — előre
  kitöltve azzal, ami már be van gépelve a Név / Cégnév mezőbe.
- Ez ugyanaz a vásárlólista, amit a `vasarlok.html` kezel — itt egy
  szerkesztése vagy felvétele azt a listát is frissíti, és fordítva.

## Kézi tétel hozzáadása eladáskor

A Kasszán van egy "+ Kézi tétel hozzáadása" gomb (a termékkereső
alatt) valami olyasmihez, ami egyáltalán nincs a készletben — egy
szolgáltatás, egy szállítási díj, egy egyszeri tétel — de mégis
szerepelnie kell a vásárló nyugtáján vagy számláján. Egy kézi tétel:

- Saját neve, mennyisége, bruttó egységára és áfakulcsa van,
  közvetlenül a kasszánál megadva.
- Megjelenik a kosárban, és pontosan úgy számít bele a végösszegbe,
  mint egy normál terméksor.
- Mentésre kerül az eladáson, és ugyanúgy szerepel a Számlázz.hu
  számlán / nyomtatott nyugtán, mint egy valódi terméksor.
- **Nem** érinti semmilyen módon a készletet vagy a WooCommerce-t —
  nincs mögötte termék, amit frissíteni kellene.
- Ehhez a `sale_items.product_id` mezőnek nullázhatóvá kellett válnia.
  A MySQL ezt közvetlen `ALTER TABLE ... MODIFY COLUMN`-nal támogatja;
  az SQLite egyáltalán nem támogatja egy `NOT NULL` megszorítás
  lazítását `ALTER TABLE`-lel, így egy meglévő SQLite adatbázis
  frissítése újraépíti a `sale_items` táblát (másolás → törlés →
  átnevezés) — ez a szokásos, dokumentált módja ennek SQLite-ban. Ez
  automatikusan, egyszer fut le, amikor az app először indul a
  frissítés után.

## Telepítő (első indításos telepítő)

Az app első megnyitása átirányít az `install.php`-ra — egy kicsi,
**kihagyható** varázsló, nem kemény követelmény. Mivel az SQLite már
nulla konfigurációval is működik, a telepítő két dologért létezik:

- **MySQL választása SQLite helyett**, egy host/port/adatbázis/
  hitelesítő adat űrlappal, ahelyett hogy kézzel kellene szerkeszteni a
  `config/config.php`-t. Teszteli a kapcsolatot, és létrehozza az
  adatbázist (`CREATE DATABASE IF NOT EXISTS`), ha még nem létezik,
  mielőtt bármit is írna.
- **A bolt nevének/címének beállítása**, ami a nyugtákon jelenik meg,
  kódszerkesztő megnyitása nélkül.

Bármelyik utat is választod (vagy a "Kihagyás"-t a teljes kihagyáshoz
és az SQLite alapértelmezett megtartásához), megírja a
`config/installer-generated.php` fájlt — a config.php ezt automatikusan
beolvasztja, ha létezik — és létrehozza a `data/.installed` fájlt, hogy
a varázsló többé ne jelenjen meg. A jelzőfájl törlése újra
megjelenítené, de erre normál esetben nincs ok.

**Frissítés egy telepítő nélküli verzióról**: semmi nem változik.
Minden oldal egy gyors, helyi fájl-ellenőrzéssel ellenőrzi a telepítési
állapotot, mielőtt bármi más betöltődne; ha már van egy működő SQLite
adatbázisfájl (vagy már kézzel szerkesztetted a config.php-t),
automatikusan már telepítettként kezeli, ahelyett hogy megszakítana
egy működő beállítást.

**Miért a `webroot/install.php`-ban van, és nem a projekt gyökerében**:
csak a `webroot/`-ot szolgálja ki a webszerver — a `config/`, `src/`,
`data/`, és a `schema*.sql` szándékosan azon kívül vannak, hogy sose
legyenek közvetlen URL-lel elérhetők. A telepítőnek elérhetőnek kell
lennie, így a `webroot/`-on belül kell lennie, az `index.html` mellett,
még ha az, amit beállít (`config/`, `data/`), egy szinttel feljebb is
van.

## Kedvezménykód / kupon

A `kedvezmenyek.html` kezeli a kuponokat — egy kód, egy kedvezmény
(százalékos vagy fix Ft), és opcionális szabályok (lejárati dátum,
felhasználási limit, minimum vásárlási összeg). A Kasszán egy kód
beírása élőben ellenőrzi és alkalmazza; a kedvezmény a részösszegre
vonatkozik, a hűségpontok vagy egy ajándékutalvány előtt.

## Ajándékutalvány

Ugyanaz az oldal, második fül. A kuponnal ellentétben egy
ajándékutalvány egy **egyenleget** hordoz, nem egy egyszeri
kedvezményt — indíts egyet egy kezdő összeggel, és több vásárláson
keresztül is elkölthető, amíg az egyenleg nullára nem fogy. Egy
beváltása a kasszánál annyit fedez a fennmaradó összegből, amennyit az
egyenleg enged (a kupon és hűségpont-kedvezmények alkalmazása után), és
a teljes tranzakciós előzménye (kiállítás, minden beváltás) látható a
lista soránál.

**Kedvezmény sorrend fizetéskor**: kupon → hűségpontok →
ajándékutalvány. Mindegyik szerver-oldalon újra ellenőrzésre kerül az
eladás pillanatában — nem a kassza felületén már megjelenítettre
hagyatkozva —, mivel itt cserél ténylegesen gazdát pénz. Ugyanaz az
ismert korlát, mint a hűségpontoknál: ha ezek bármelyike kombinálódik
egy ugyanabban az eladásban kért névre szóló számlával, a Számlázz.hu
számla a teljes, kedvezmény előtti összegre kerül kiállításra (egy
kedvezmény vegyes áfakulcsok közötti arányosítása nem tűnt megérni a
bonyolultságot egy a gyakorlatban valószínűleg ritka kombinációhoz).

## Ártörténet

Minden alkalommal, amikor egy termék nettó vagy bruttó ára változik (a
szerkesztő ablakon keresztül — nem CSV importon, ami különben
elárasztaná ezt tömeges import-zajjal), a régi és új érték naplózásra
kerül. A termék szerkesztő ablakának újranyitása rögtön ott mutatja az
előzményt, nem kell hozzá külön oldal.

## Vonalkód-generálás + címke nyomtatás

A termék-szerkesztő ablaknak van egy **Generálás** gombja, ami egy
friss, érvényes EAN-13 kóddal tölti ki a vonalkód mezőt — a "20"
előtaggal, amit a GS1 belső/bolti használatra tart fenn, így egy
generált kód sosem ütközhet később egy valódi gyártó tényleges
vonalkódjával. A **Címke nyomtatása** egy kis nyomtatható címkét (név,
ár, és egy szkennelhető vonalkód) nyit meg egy új ablakban. Maga a
vonalkód SVG-ként renderelődik az `ean13.js`-ben, ami az EAN-13
kódolási táblázatok nulláról épített implementációja — nincs külső
könyvtár vagy CDN-függőség, a kamerás szkenner és a PWA funkciók
filozófiájával összhangban.

## Termékleírás, kép, márka és WooCommerce-szinkron kapcsoló

A termék-szerkesztő ablak új "Leírás és kép" füle:

- **Rövid és hosszú leírás** — a WooCommerce `short_description` /
  `description` mezőjének felelnek meg, és egy valódi **TinyMCE
  szerkesztőt** kapnak (félkövér/dőlt, felsorolás, hivatkozás, táblázat,
  HTML-nézet) — ugyanazt a szerkesztőmotort, amit a WordPress/WooCommerce
  klasszikus termékleírás-mezője is használ. A kimenet ezért ugyanolyan
  tiszta, szemantikus HTML (`<p>`, `<strong>`, `<ul><li>` stb.), mint amit
  a WooCommerce oldalán szerkesztve kapnál — a WordPress oldalon
  visszanyitva ugyanúgy formázva jelenik meg és marad szerkeszthető. A
  szerkesztő helyben van csomagolva (`webroot/vendor/tinymce/`, önállóan
  letöltött, nyílt forráskódú GPL kiadás), nincs hozzá API-kulcs vagy
  külső CDN-függőség, és automatikusan követi az app sötét/világos
  sablonját is. A szerkesztő jobb alsó sarkánál lefelé húzva a magassága
  kézzel átméretezhető, ha a beépített (150 / 300 px) kezdőméret szűknek
  bizonyulna egy hosszabb szöveghez.
- **Márka** — a WooCommerce natív márka-taxonómiáján (`brands`) keresztül
  szinkronizál; a WooCommerce a nevet automatikusan hozzárendeli egy
  meglévő márkához, vagy létrehozza, ha még nem létezik.
- **Termékkép** — feltöltéskor a szerver automatikusan középre vágja
  1:1 arányúra (ha nem volt eleve négyzet alakú), és WEBP formátumban
  menti (`webroot/assets/products/`). A négyzet cél-mérete (alapból
  1200×1200 px, 200–4000 px között állítható) a Beállítások →
  WooCommerce fülön módosítható — a már feltöltött képeket ez
  visszamenőleg nem alakítja át, csak az ezután feltöltötteket. WEBP,
  JPG/JPEG és GIF fogadható el bemenetként. Mellette megadható a kép
  **alt szövege** is (SEO), ami a WooCommerce-be küldött képadat `alt`
  mezőjébe kerül.
- **"Szinkronizáljon a WooCommerce-szel" kapcsoló** — alapból bekapcsolva.
  Kikapcsolva a termék "csak üzletben" marad: sem a Beszerzés oldal
  "Sync WooCommerce-ből" gombja (behúzás), sem az eladás/beszerzés utáni
  készlet-kiküldés, sem a webhook nem érinti többé — így a csak fizikai
  boltban kapható termékek biztonságosan kizárhatók a webshop-szinkronból.

**Márka-megfeleltetés**: Beállítások → WooCommerce → "Márka-megfeleltetés"
felsorolja az összes, valamelyik termékhez már beírt helyi márkanevet, és
mindegyikhez egy legördülőben kiválasztható a hozzá tartozó, tényleges
WooCommerce márka (a WooCommerce natív `products/brands` végpontjáról
élőben lekérve). Amit itt megfeleltetsz, az szinkron-kiküldéskor a
kiválasztott WooCommerce márkanéven megy ki, függetlenül attól, hogy a
helyi mező mit tartalmaz — ez akadályozza meg, hogy elgépelés vagy eltérő
írásmód miatt felesleges, duplikált márka jöjjön létre a webshopban.
Amit nem feleltetsz meg, az a helyi néven kerül kiküldésre.

Amikor egy már szinkronizált (van `wc_product_id`-je) terméket
módosítasz, a mentés — ha a szinkron be van kapcsolva — automatikusan
kiküldi a nevet, árat, leírásokat és márkát (a fenti megfeleltetésen
átvezetve) a WooCommerce felé (`WooCommerceClient::pushProduct()`).
**Fontos technikai részlet, amit egy valódi WooCommerce teszt-példányon
ellenőriztünk**: a WooCommerce REST API `brands` mezője — a `categories`
mezővel ellentétben — kizárólag numerikus azonosítót fogad el, egy puszta
`{"name": "..."}` bejegyzést csendben, hibaüzenet nélkül eldob. Emiatt a
kiküldés előbb feloldja a márkanevet egy valódi WooCommerce márka-ID-ra
(megkeresi a pontosan egyező nevű márkát, vagy létrehozza, ha még nincs).

**A kép kiküldése külön feltételhez kötött**: a WooCommerce szerverének
egy nyilvánosan elérhető URL-t kell tudnia letölteni, ezért ez csak akkor
működik, ha a Beállítások → WooCommerce fülön ki van töltve egy "Kívülről
elérhető alap URL" — enélkül minden más mező szinkronizál, csak a kép nem
(a hiba nem állítja le a mentést, csak a `sync_log`-ban jelenik meg). Egy
további, tesztelés közben feltárt korlát: a WordPress alapból csak a 80,
443 és 8080 portokról fogad el ilyen kimenő letöltést (`http_allowed_safe_ports`
szűrő) — ha a kassza szerver ettől eltérő, nem szabványos porton fut, és
nincs elé állítva reverse proxy (lásd a távoli szerveres telepítési
útmutatót, ahol Nginx a 80-as porton fogad), a WooCommerce oldalon ezt
külön engedélyezni kell. A kép csak akkor kerül újra kiküldésre, ha
ténylegesen változott (nem minden mentésnél), mivel a letöltés + több
méretben történő újramintázás a WooCommerce oldalon számottevően tovább
tarthat, mint egy sima mezőfrissítés.

## Beérkező eladások (webshop-rendelések jóváhagyással)

A WooCommerce webhookja (Woo → Beállítások → Speciális → Webhookok, "Rendelés
frissítve" esemény) mostantól **nem csökkenti azonnal a helyi készletet** —
ehelyett a fizetett (`processing`/`completed` állapotú) rendelés
piszkozatként bekerül az új **Beérkező eladások** menüpontba
(`beerkezo-eladasok.php`, elérhető az oldalsávból és a felső navigációból
is). Ez a review-lépés védi ki, hogy egy hibás/gyanús/kétszer kiküldött
webhook-hívás észrevétlenül módosítsa a raktárkészletet.

- **Értesítés**: amíg piszkozat vár, piros pötty jelenik meg az oldalsáv
  "Beérkező eladások" ikonján és a felső harang-értesítésen is; ha a
  piszkozatok száma nő két lekérdezés között (kb. 25 másodpercenként
  ellenőrizve), egy felugró értesítés is megjelenik ("Új rendelés érkezett a
  webáruházból").
- **Tétel-párosítás**: minden rendeléstétel megpróbálódik párosítani egy
  helyi termékkel a WooCommerce termék-ID alapján (`wc_product_id`) — a
  párosítottak zöld "Párosítva" jelvényt kapnak, a párosítatlanok piros
  "Nincs helyi termék" jelvényt (ezek az összegben/számlán szerepelnek, de a
  leadáskor nem csökkentik semmelyik termék készletét).
- **Rendelés leadása**: ellenőrzés után a "Rendelés leadása" gomb valódi
  eladás-rekordot hoz létre (megjelenik az Eladások listában is), és csak
  ekkor csökkenti a párosított tételek helyi készletét — a WooCommerce felé
  nem küld vissza készlet-frissítést, mivel a rendelés maga onnan érkezett
  (a Woo már a saját oldalán kezeli a készletét).
- **Fizetési mód**: a rendeléshez a WooCommerce-ből érkező fizetési mód
  (`payment_method_title`, pl. "Stripe") van előre kiválasztva egy
  szerkeszthető legördülőben — lásd lent a bővíthető fizetési módok listát.
- **Egy kattintásos számlázás**: a "Számla kiállítása azonnal" jelölőnégyzet
  (leadáskor), vagy utólag egy "Számla kiállítása" gomb (a leadott
  rendelés részletei alatt) a Számlázz.hu integrációval, a rendelés
  számlázási címéből (WooCommerce billing-mezők) automatikusan összeállítva
  állítja ki a számlát — nem kell újra begépelni a vevő adatait. Ha a
  rendelés számlázási címe hiányos (név/irányítószám/település/cím
  bármelyike hiányzik), a jelölőnégyzet/gomb egyértelmű üzenettel jelzi ezt.
- **Elutasítás**: ha egy rendelés hibás vagy nem kell feldolgozni,
  "Elutasítás"-sal archiválható — a készletet ez sem érinti.

**Bővíthető fizetési módok**: Beállítások → Számlázz.hu fül tetején egy
"Fizetési módok" lista kezelhető (hozzáadás/törlés) — ez adja a kasszán és a
Beérkező eladásoknál is választható fizetési módokat. Alapból Készpénz,
Átutalás, Bankkártya, PayPal, Utánvét szerepel; webshopos fizetési
szolgáltatók (pl. Stripe) hozzáadhatók, hogy a webshop-rendelések leadásakor
a valódi fizetési mód legyen kiválasztható, ne csak a kasszás alapértelmezés.

## Mentés-visszaállítás (backup restore)

A Beállítások → Mentés mostantól egy **Visszaállítás** gombot kínál
minden listázott helyi mentés mellett, plusz egy fájlfeltöltést egy
máshonnan (másik gépről, felhő-letöltésből) származó mentés
visszaállításához. Ez a legroncsolóbb művelet az egész alkalmazásban
(visszavonhatatlanul felülírja az éles adatbázist), ezért a
hozzáférés-ellenőrzése többrétegű: **vezetői (admin) szerepkör
szükséges** hozzá (ugyanaz a szabály, mint a mentés készítésénél/
listázásánál), ÉS — ha egyáltalán van beüzemelve dolgozói PIN-rendszer
— egy **friss vezetői PIN megadása is kötelező ugyanazzal a kéréssel**,
nem elég a már bejelentkezett munkamenet szerepköre önmagában. Ha
egyáltalán nincs dolgozói PIN-rendszer használatban, lásd a "Dolgozói
jogszintek" szakaszt: ilyenkor bármelyik, az alkalmazás-jelszóval
bejelentkezett munkamenet elérheti ezt is. Bármelyik úton:

- Egy friss biztonsági mentés a **jelenlegi** élő adatokról
  automatikusan elkészül, mielőtt bármihez is hozzányúlna — így a
  visszaállítás maga is visszavonható, ha kiderül, hogy rossz fájl
  volt.
- SQLite: a feltöltött/kiválasztott fájl megnyitásra és ellenőrzésre
  kerül (valódi SQLite fájl, nem sérült vagy nem odaillő), mielőtt
  lecserélné az élő adatbázisfájlt.
- MySQL: előnyben részesíti a `mysql` CLI binárist a dump futtatásához
  (ugyanígy, ahogy a mentések maguk is a `mysqldump`-ot részesítik
  előnyben); ha a CLI nem elérhető (gyakori megosztott tárhelyen), egy
  PHP-n keresztüli, a dump utasításait egyenként végrehajtó megoldásra
  esik vissza.
- Egy megerősítő párbeszédablak jelenik meg, mielőtt bármelyik
  visszaállítási út folytatódna — ez a művelet felülírja az élő
  adatokat.

## Részleges visszáru / sztornó

Egy Eladások eladás részletnézetén a "Visszáru rögzítése" soronként egy
mennyiség-mezőt tár fel, felső korláttal az adott sorból még vissza nem
küldött mennyiségre (így ugyanazon eladás egy második részleges
visszárúja sem tud túl sokat visszaküldeni). A megerősítés
visszaállítja a készletet a visszaküldött tételekre, és naplózza a
visszárut.

**Ismert korlát**: a `sales` tábla csak a vevő nevét tárolja, nem az
eredeti Számlázz.hu számlán szereplő teljes számlázási címet/adószámot
— így jóváíró számla sosem generálódik automatikusan. Ha az eredeti
eladáshoz tartozott számla, a visszáru képernyő csak felszínre hozza
azt a számlaszámot, hogy manuálisan ki lehessen állítani a jóváíró
számlát a Számlázz.hu-n, hivatkozva rá. Egy
`SzamlazzClient::createCreditNote()` metódus létezik jövőbeli
használatra, ha valaha a teljes vevő-számlázási adat eltárolásra kerül
az eladási rekordon, de nincs letesztelve a Számlázz.hu tényleges
jóváíró-számla viselkedése ellen — ellenőrizd az aktuális
dokumentációval, mielőtt hagyatkoznál rá.

## Több felhasználó / PIN-kód

A `staff.html` kezeli a dolgozókat (név + egy 4-8 jegyű PIN,
`password_hash`-sel hash-elve). A Kassza felső sávja mutatja, ki van
bejelentkezve — rákattintva egy PIN-kérő nyílik meg; a bejelentkezett
dolgozó a `localStorage`-ban van megjegyezve (nem egy valódi
munkamenet), és onnantól minden eladáshoz hozzá van rendelve. Ez
elszámoltatásra való (ki ütötte be mit), nem valódi
hozzáférés-vezérlésre — bárki megnyithatja a bejelentkező ablakot, és
választhat másik nevet, ha ismeri egy PIN-t, ahogy a legtöbb kisbolti
kassza-beállításnál.

## Leltározás

A `leltar.html` egy leltározást indít, ami minden aktív termék
jelenlegi készletét "várt" mennyiségként pillanatképezi le, majd
lehetővé teszi egy megszámolt mennyiség megadását termékenként
(kereséssel szűkíthető a hosszú lista), élőben mutatva a különbséget. A
lezárás opcionálisan a megszámolt mennyiségeket alkalmazza
korrekcióként az élő készletre — vagy csak rögzíti az eltérési
riportot a készlet érintése nélkül, ha ez nincs bepipálva.

## Kimutatás / Export CSV

Az Eladások, Beszerzések, és Napi zárás mindegyikének van egy "Export
CSV" gombja, ami figyelembe veszi az éppen alkalmazott szűrőket
(dátum, azonosító, keresés). A fájl tartalmaz egy UTF-8 BOM-ot, hogy a
Windows-os Excel helyesen felismerje a kódolást, ahelyett hogy
összezavarná az ékezetes magyar karaktereket.

Az **Árucikkek** és a **Vásárlók** listája ezen felül soronkénti
kijelölő jelölőnégyzetet is kapott (fejlécben "mind kijelölése"
opcióval), és két export gombot: "Export CSV" és "Export XLS". Ha van
kijelölt sor, csak azokat exportálja; ha nincs, az éppen szűrt/látható
listát. Az XLS export a széles körben támogatott "Excel 2003 XML"
(SpreadsheetML) formátumot írja — ehhez nincs szükség a `zip`
kiterjesztésre (a valódi, tömörített .xlsx-hez az kellene), csak a már
amúgy is kötelező `xmlwriter`-re. Ennek egyetlen ártalmatlan
mellékhatása, hogy egy újabb Excel megnyitáskor egy "a fájlformátum és
a kiterjesztés nem egyezik" figyelmeztetést mutathat — ez "Igen"-nel
simán megnyílik, ez egy elterjedt technika .xls export generálására
natív bináris író könyvtár nélkül.

## Dashboard grafikonokkal

A Rendszerállapot mostantól egy bevétel-trend grafikont is tartalmaz
(14/30/90 nap) — egy nulláról épített, egyszerű SVG oszlopdiagram a
`rendszerallapot.js`-ben, grafikon-könyvtár nélkül, az EAN-13
vonalkód-renderelő filozófiájával összhangban. Vidd az egeret egy
oszlop fölé az adott nap pontos összegéért és eladásszámáért.

## Digitális nyugta e-mailben

Egy eladás után a nyugta panel egy e-mail mezőt kap (előre kitöltve a
kiválasztott törzsvásárló mentett e-mail címével, ha van), és egy
"E-mail küldése" gombot, ami a nyugta egy HTML változatát küldi el.

**Alapértelmezetten** ez a PHP beépített `mail()` függvényét használja —
ez viszont csak akkor működik, ha a szervernek van beállított
levelezés-továbbítója (sendmail/postfix, gyakori valódi megosztott
tárhelyen), **nem** fog magától működni `php -S` helyi fejlesztésen
vagy a legtöbb friss VPS telepítésen.

### SMTP (Beállítások → Email)

Ha be van állítva SMTP host, a rendszer AZT használja `mail()` helyett
— működik `sendmail`/levelezés-továbbító nélkül is, bármelyik valódi
SMTP-szolgáltatóval (Gmail, saját tárhelyi SMTP, SendGrid/Mailgun SMTP-
kompatibilis módban, stb.).

**Miért PHPMailer, és miért nem saját SMTP-implementáció**: a projekt
Composer NÉLKÜL fut (lásd `tests/bootstrap.php`) — egy saját SMTP-
kliens írása (TLS/STARTTLS, autentikáció, MIME-encoding mind
finomság-érzékeny terület) könnyen hibás/nem biztonságos lenne. Ehelyett
a PHPMailer könyvtár 3 forrásfájlja van közvetlenül bevendorolva
(`vendor/phpmailer/{Exception,PHPMailer,SMTP}.php`, **v7.1.1**,
github.com/PHPMailer/PHPMailer, LGPL-2.1 licenc, Composer NÉLKÜLI
"közvetlen include" használat — ezt a PHPMailer saját dokumentációja is
kifejezetten támogatja/dokumentálja). Nincs `composer.json`/lockfile
(a projekt egésze nem használ Composert) — a verzió-követés ITT, ebben
a README-ben történik.

**Frissítés**: töltsd le az új release ugyanezen 3 fájlját
(`src/Exception.php`, `src/PHPMailer.php`, `src/SMTP.php` a PHPMailer
GitHub repo-jából) a `vendor/phpmailer/` mappába, majd futtasd a teljes
PHPUnit suite-ot (`tests/MailerServiceTest.php` egy valódi, helyi SMTP-
protokollt beszélő teszt-szerverrel ellenőrzi a küldést) — biztonsági
frissítés esetén ez a szokásos módja.

**Mezők**: host, port, felhasználónév, jelszó, titkosítás (Nincs /
SSL-TLS / STARTTLS — alapértelmezett: STARTTLS, a legtöbb modern SMTP-
szolgáltató ezt várja a 587-es porton), feladó neve/email címe.

**Biztonság**: az SMTP jelszó SOSE jelenik meg nyers formában egy
`GET /api/settings.php` válaszban (csak egy `smtp_password_set: true/
false` jelző, ugyanaz a minta, mint a NAV/WooCommerce/Dropbox
titkoknál) — a mentéskor üresen hagyott jelszó-mező NEM törli a
korábban elmentett értéket. Egy sikertelen teszt-küldés hibaüzenete
(`webroot/api/smtp-test.php`) SOSE tartalmazza a jelszót.

**Teszt email küldése**: a Beállítások → Email alján — admin jogszintet
és a normál CSRF-védelmet igényli, akárcsak a nyomtató-teszt. A
mentetlen form-értékekkel is tesztelhető ("Mentés" előtt is), pontosan
úgy, mint a nyomtató-teszt gomb.

**`REAL EXTERNAL SMTP DELIVERY NOT TESTED`** — az SMTP-kliens teljes
protokoll-folyamata (EHLO/MAIL FROM/RCPT TO/DATA/QUIT, hitelesítés,
hibakezelés) egy valódi, helyi teszt-SMTP-szerverrel van bizonyítva
(`tests/MailerServiceTest.php`), DE ebben a fejlesztői környezetben
nem állt rendelkezésre biztonságosan használható, valódi külső SMTP-
fiók — emiatt a TÉNYLEGES külső kézbesítés (egy valódi Gmail/tárhelyi/
SendGrid-jellegű SMTP-szolgáltatóval végződő, ténylegesen megérkező
email) production bevezetés előtti, még ellenőrizendő pont marad.

## Dolgozói jogszintek

A dolgozóknak mostantól van egy szerepköre (Eladó vagy Vezető), a
`staff.html`-en beállítva. Ez továbbra is elszámoltatási eszköz marad,
nem valódi hozzáférés-vezérlő rendszer — ahogy korábban is
dokumentálva, bárki megnyithatja a PIN-kérő ablakot, és választhat
másik nevet. Ami valódi: minden érzékeny/roncsoló művelet (termék-,
vásárló- és beszállító-törlés, GDPR-export/törlés, ajándékutalvány/
kupon kiállítás, biztonsági mentés készítése/listázása/**visszaállítása**,
WooCommerce-szinkron és kapcsolat-teszt, biztonsági beállítások mentése)
**szerver-oldalon van kikényszerítve** — ha egy dolgozó be van
jelentkezve, és nem admin, a kérés elutasításra kerül (403),
függetlenül attól, mit mutat a felület.

**FONTOS a megosztott jelszavas, dolgozói PIN nélküli telepítéseknek**:
ha a boltban egyáltalán nincs beüzemelve a dolgozói PIN-rendszer (senki
sincs felvéve a `staff.html`-en), a fenti admin-kapuk mind **engedékenyek
maradnak** — bárki, aki a megosztott alkalmazás-jelszóval be tud
jelentkezni, ténylegesen admin-jogosultsággal fér hozzá minden fenti
művelethez, a biztonsági mentés visszaállítását is beleértve. Ez
szándékos, dokumentált tervezési döntés (egy egyszemélyes/kisboltos
telepítésnek nincs szüksége külön dolgozói szerepkörökre), NEM hiba —
de fontos tudatában lenni: ha valódi jogosultsági elkülönítést
szeretnél a dolgozók között, állíts be legalább egy admin szerepkörű
dolgozói PIN-t.

## Tevékenységnapló (audit log)

Az `audit-log.html` mutatja a naplózott műveleteket (jelenleg:
termék-törlések, bővíthető más műveletekre később), azzal, hogy ki és
mikor csinálta. A megőrzési idő alapból 30 nap, és beállítható a
Beállítások → Tevékenységnapló alatt — a régebbi bejegyzések
automatikusan törlődnek a következő íráskor, nem kell hozzá külön
cron.

## Hűségszintek (loyalty tiers)

A meglévő pontrendszer tetejére a vásárlók mostantól automatikus
százalékos kedvezményt is kapnak az élettartam-költésük alapján
(`customers.total_spent`, minden befejezett eladáson követve) — Bronz
(nincs kedvezmény) → Ezüst → Arany, a küszöbökkel és
kedvezmény-százalékokkal a Beállítások → Törzsvásárlói pontok alatt
állíthatók. Automatikusan alkalmazva a kupon és pont-kedvezmények után,
egy ajándékutalvány előtt. A `vasarlok.html` mutatja minden vásárló
jelenlegi szintjét.

## Globális kereső (Ctrl+K)

Nyomd meg a **Ctrl+K**-t (vagy kattints a kereső ikonra) bármelyik
oldalon, hogy egyszerre keress termékek, vásárlók és eladások között.
Ez a `topbar.js`-ből van beinjektálva minden oldal felső sávjába,
ahelyett hogy minden oldal HTML-jéhez külön hozzá lenne adva — a
`.topbar-actions` már egységesen jelen van az oldalakon, így ez egy
egyfájlos változtatás marad ~15 oldal módosítása helyett.

## Értesítési központ (notification center)

Egy harang ikon a kereső ikon mellett (ugyanazzal az injektálási
móddal) egy jelvényt mutat, ha van mire figyelni — alacsony készlet,
szinkron-hibák, számla-hibák — ugyanabból az adatból húzva, amit a
`rendszerallapot.html` már úgyis felszínre hoz. Egy riasztásra
kattintva a releváns oldalra ugrik.

## Mobil UI-átvizsgálás és javítások

A teljes felület átnézésre került mobil nézetre, és a következő valós
hibák kerültek javításra:

- **Az oldalsáv fixen 72px-et foglalt keskeny képernyőn is** — most
  768px alatt eltűnik, a fejléc navigációja és az értesítési harang
  továbbra is elérhetővé teszi a legfontosabb oldalakat.
- **A Kassza fő elrendezése** (`380px + 1fr` oszlopok) összenyomódott
  vagy kifolyt volna keskeny képernyőn — 900px alatt egy oszlopba esik.
- **A `.field-row` (páros mezők, pl. irányítószám/település)** fixen
  2 oszlopos volt mindenhol az appban — 480px alatt egy oszlopba esik.
- **9 oldal táblázata** (Kedvezmények, Dolgozók, Leltár, Napló,
  Beszállítók, Vásárlók, Eladások, Beszerzések, Napi zárás) nem volt
  vízszintesen görgethető konténerbe csomagolva — mobilon ez az egész
  oldal vízszintes görgetését okozta volna sok oszlopos táblázatoknál.
- **A `.products-toolbar`** (keresőmező + gomb) most tördelődik 640px
  alatt, ahelyett hogy összenyomódna.
- **iOS Safari zoom-hiba**: 6 helyen volt 14-15px-es betűméret input/
  select/textarea elemeken — ez fókuszáláskor automatikus nagyítást vált
  ki iOS-en. Mind 16px-re javítva.
- Több inline `display:flex` sor (input+gomb kombináció adószám-
  lekérdezésnél, kupon/utalvány kódnál, leltár-lezárásnál) nem
  tördelődött — most `flex-wrap` és megfelelő `flex-basis` értékekkel
  biztonságosan tördelődnek keskeny képernyőn.
- Az újonnan épített értesítési dropdown fix szélessége/pozicionálása
  túlfuthatott volna keskeny telefonon — `min()` CSS függvénnyel
  garantáltan a viewport szélességén belül marad.

**Amit ez a kör nem fedett le**: a demó fájl (`stock-manager-demo.html`)
nem lett átvizsgálva ebben a körben, illetve funkcionális (nem UI/CSS)
hibák tesztelése sem történt.

## Rövid beépített útmutató

`utmutato.html` — a fő munkafolyamatok rövid, statikus leírása. Egy "?"
súgó ikon nyílik meg rá minden oldal fejlécéből (`topbar.js`-ből
injektálva, mint a kereső és az értesítési harang).

## QR-kód a nyomtatott nyugtán

A `receipt.html` most egy QR-kódot is tartalmaz, ami visszamutat magára a
nyugtára — a vásárló ezt beszkennelve digitálisan is elmentheti, e-mail
küldés (és így szerver-oldali levelezés-konfiguráció) nélkül.

**Tervezési döntés**: a QR-kódot a böngésző generálja helyben, egy
becsomagolt, nyílt forráskódú (MIT licenc) JS-könyvtárral
(`webroot/vendor/qrcode-generator/`, kazuhikoarase/qrcode-generator) — nem
egy külső, publikus QR-generáló képszolgáltatással. Ennek oka nem csak
megbízhatóság: a QR-kód a nyugta **titkos, bejelentkezés nélküli
megtekintést lehetővé tevő tokenjét** is tartalmazza a linkben — egy
külső szolgáltatásnak elküldve ez a token (és ezzel a nyugta tartalma)
megjelenne annak a szolgáltatásnak a szerver-naplóiban is, nem csak a
vásárlónál. A helyi generálás nem igényel internetkapcsolatot sem.

**Fontos korlátozás**: a QR-kód a nyugta oldalának **aktuális URL-jére**
mutat. Ha az app `localhost`-on vagy egy csak a kassza gépéről elérhető
címen fut, a vásárló telefonja nem fogja tudni megnyitni a linket — ehhez
az appnak egy ténylegesen kívülről is elérhető domainen, vagy legalább a
bolt Wi-Fi hálózatán belül mindkét fél számára elérhető IP-címen/porton
kell futnia.

## Automatikus beszerzési javaslat generálás

`beszerzesi-javaslat.html` (Rendszerállapotról linkelve) az alacsony
készletű termékeket a termék-szerkesztőben beállítható **preferált
beszállító** szerint csoportosítva mutatja, egy egyszerű javasolt
mennyiséggel (a küszöb duplájára tölti fel — nem valódi keresleti
előrejelzés, de jó kiindulópont). A "Beszerzés indítása ezzel a
beszállítóval" gomb átviszi a kiválasztott tételeket és mennyiségeket a
Beszerzés oldalra (a beszállító is automatikusan kiválasztva), ahol
tovább szerkeszthetők a tényleges rögzítés előtt.

## Több telephely / raktár kezelése

`telephelyek.html` — telephelyek felvétele, és termékenkénti
készletmozgatás köztük. **Fontos tervezési döntés**: `products.stock_qty`
marad az ELSŐDLEGES, összesített mennyiség, amit minden más funkció
(WooCommerce szinkron, alacsony készlet riasztás, leltározás stb.)
változatlanul használ — a telephelyenkénti bontás egy kiegészítő réteg
(`location_stock` tábla) felette. Ez azt jelenti:

- **Egytelephelyes boltoknál semmi nem változik** — ha nincs felvéve
  telephely, a Kasszán meg sem jelenik a telephely-választó, és minden
  pontosan úgy működik, mint korábban.
- Ha van felvéve legalább egy telephely, a Kasszán megjelenik egy
  választó — a kiválasztott telephely készlete is csökken eladáskor, az
  összesített mennyiség mellett (nem helyette).
- A telephelyek közti mozgatás nem érinti az összesített mennyiséget,
  csak a megoszlást.

## Ügyféllista (bővített vásárlói profil)

`vasarlok.html` mostantól saját oldalsáv-ikonnal elérhető menüpont (nem
csak kontextusból, a Kasszáról linkelve). A vásárló-szerkesztő modal
fülekre bontva:

- **Adatok** — a korábbi szerkesztő űrlap, változatlanul.
- **Statisztika** — hűségszint, összes elköltés, vásárlások száma,
  átlagos kosárérték, **első és utolsó vásárlás dátuma**, plusz a teljes
  pontelőzmény.
- **Vásárolt tételek** — az összes valaha megvásárolt tétel listája,
  dátum szerint csökkenő sorrendben.

Az "Adatok" fül mindig elérhető (új vásárló felvételéhez is kell); a
"Statisztika" és "Vásárolt tételek" fülek csak meglévő vásárlónál
jelennek meg, hiszen új vásárlónak még nincs előzménye.

## Teljes átvizsgálás — kód, adatbázis, reszponzív UI (2026-08-30)

**Adatbázis-teljesítmény**: a SQLite séma **10 indexet** nem tartalmazott,
amit a MySQL séma igen — köztük a `sales.customer_id`-t, amire az
Ügyféllista statisztika-lekérdezései (`getCustomerStats`,
`getCustomerPurchasedItems`) épülnek. Nagyobb adatbázisnál ez lassú,
teljes tábla-vizsgálatot okozott volna minden vásárló-részlet
megnyitásakor. Pótolva mindkét helyen (friss telepítés + migráció a
schema_version 11-es lépésével).

**Adatbázis-konzisztencia**: mind a 22 tábla oszlopai ellenőrizve és
megerősítve — pontosan egyeznek SQLite és MySQL között.

**Reszponzív UI**: néhány további `display:flex` fejléc-sor (pl.
Rendszerállapot "Bevétel trend" fejléce a napszám-választóval) kapott
biztonsági `flex-wrap`-ot a korábbi körökben már alkalmazott mintát
követve, a legkeskenyebb telefonokon esetlegesen szoros illeszkedés
elkerülésére.

## FountainTrade 1.1.1 — stabilitási és megbízhatósági javítások

Ez egy **maintenance/reliability release** — nincs benne új nagy üzleti
funkció, kizárólag a napi használat stabilitását és adatbiztonságát
javító, célzott javítások, a jelenlegi 1.1.0 architektúrához illesztve.

### Beszerzés-idempotencia

A beszerzés-rögzítés (`api/purchase-save.php`) korábban NEM rendelkezett
idempotencia-védelemmel — csak a kasszai eladás (`api/sale.php`)
`sales.idempotency_key`-je (lásd fentebb, 1.0-s bevezetés). Egy dupla
kattintás, hálózati újrapróbálkozás vagy timeout utáni kézi újraküldés
emiatt két külön beszerzési rekordot hozhatott létre, duplán megnövelt
készlettel.

A javítás PONTOSAN a bevált sale-mintát követi:
- A kliens (`beszerzes.js`) egy `crypto.randomUUID()`-alapú
  `idempotency_key`-t generál minden beszerzés-rögzítési kísérlethez,
  ami sikeres mentésig változatlan marad (retry ugyanazt küldi újra).
- `purchases.idempotency_key` + `purchases.idempotency_fingerprint`
  oszlopok, UNIQUE INDEX a kulcson (lásd
  `Database::migrateV23PurchaseIdempotency()`).
- `api/purchase-save.php`: előzetes ellenőrzés (`findPurchaseByIdempotencyKey()`)
  gyors, nem-versenyhelyzetes újraküldésre; a TÉNYLEGES, versenyhelyzet-
  mentes védelmet a DB UNIQUE indexe adja — egy `PDOException`
  UNIQUE-ütközés esetén a nyertes kérés eredménye kerül visszajátszásra
  (`replayed: true`), nem hiba.
- Az ujjlenyomat (fingerprint) megvédi az ellen, hogy ugyanazt a kulcsot
  valaki egy ténylegesen ELTÉRŐ tartalmú kéréshez próbálja újrafelhasználni
  (409 Conflict).
- Valódi, 16 különálló OS-folyamattal bizonyítva
  (`tests/PurchaseAndWcPushConcurrencyTest.php`): 16 egyidejű, azonos
  kulcsú beszerzés-kísérlet → pontosan 1 `purchases`-sor, pontosan 1×-es
  készletnövekedés.

### WooCommerce készlet-push — aszinkron queue

Korábban `api/sale.php`, `api/purchase-save.php` és
`api/stock-take-complete.php` mindegyike **szinkron, blokkoló** módon
hívta a `WooCommerceClient::updateStock()`-ot a kérés-válasz cikluson
BELÜL — egy lassú vagy elérhetetlen WooCommerce-szerver emiatt
közvetlenül megnövelte a kassza/beszerzés/leltár válaszidejét (akár a
cURL timeout teljes hosszáig).

Az új architektúra PONTOSAN a bevált NAV-queue mintáját követi (lásd
lentebb "Számla-műveletek" szakasz), csak egyfázisú állapotgéppel:

```
sale/purchase/stock-take COMMIT
        ↓ (ugyanabban a tranzakcióban: Database::enqueueWcPush())
wc_push_queue (queued)
        ↓ (külön, cron-indított worker: WcPushQueueWorker)
queued → processing → done | failed | dead_letter (retry esetén vissza queued-ra)
```

- **`wc_push_queue` tábla** (`Database::migrateV24WcPushQueue()`):
  `status`/`attempts`/`next_attempt_at`/`locked_at`/`last_error` — a
  `invoices` tábla queue-állapotgépének mintája, csak NINCS "beküldve,
  státuszra várunk" köztes fázis (a WC `updateStock()` hívás önmagában
  eldönti a sikert).
- **Idempotens beütemezés**: `operation_key = 'push:{trigger_type}:{trigger_id}:{product_id}'`
  UNIQUE — ugyanaz a kiváltó esemény (dupla kattintás) nem ütemezhető be
  kétszer. A TÉNYLEGES "ne fusson kétszer egyidejűleg" védelmet a
  `claimQueuedWcPush()` feltételes UPDATE-je adja (ugyanaz a
  claim-with-lock minta, mint a NAV queue-nál).
- **A push a KIVÁLTÁS pillanatában rögzül, a KÜLDÉS a push PILLANATÁBAN
  érvényes, friss készletet olvassa** — nem egy beütemezéskori
  pillanatképet — így egy gyorsan egymást követő két esemény (pl. egy
  eladás és egy utána következő beszerzés) sose írhat felül egy
  elavult abszolút értékkel.
- **Retry/backoff**: ugyanaz a 9-lépéses ütemezés (1p/5p/15p/30p/1ó/3ó/
  6ó/12ó/24ó), majd `dead_letter` (admin kézi újrapróbálkozásig).
- **Hiba-osztályozás** (`WooCommerceRequestException`, lásd
  `src/WooCommerceClient.php`): connect-timeout ÉS teljes-kérés-timeout
  KÜLÖN (`CURLOPT_CONNECTTIMEOUT` ≠ `CURLOPT_TIMEOUT`); DNS-hiba/
  kapcsolódási hiba/időtúllépés → mind ÁTMENETI (retryable); HTTP 5xx →
  ÁTMENETI; HTTP 4xx → VÉGLEGES (business rejection, nem retryable);
  2xx válasz hibás JSON törzzsel → ÁTMENETI (nem tudható biztosan mi
  történt, de az `updateStock()` idempotens, egy felesleges
  újrapróbálkozás ártalmatlan).
- **Cron-végpont**: `api/wc-queue-run.php` (a meglévő `X-Cron-Token`
  mechanizmuson át, `cron_secret`, ugyanúgy mint `nav-queue-run.php`).
  Javasolt gyakoriság: percenként vagy néhány percenként.
- Valódi, 16 folyamatos konkurrencia-teszttel bizonyítva, hogy egyetlen
  push-sor sose fut le kétszer párhuzamosan
  (`tests/PurchaseAndWcPushConcurrencyTest.php`).

**Ismert korlát**: a `wc_push_errors` API-mező minden válaszban
visszafelé kompatibilitásból megmarad, de MOST MINDIG üres — egy
push-hiba a queue-ban, `sync_log`-ban naplózva jelenik meg, nem a kérés
azonnali válaszában (hiszen a push már NEM szinkron).

### Ár-validáció

Korábban SEHOL nem volt szerver-oldali ellenőrzés arra, hogy egy termék
nettó/bruttó eladási ára vagy beszerzési ára ne lehessen negatív (a
`zero_price` import-előnézeti számláló csak informatív volt, sosem
blokkolt). Új, központi `PriceValidator` osztály (`src/PriceValidator.php`):
üzleti szabály — **negatív ár SOSE fogadható el, NULLA ár megengedett**
(ez már korábban is előfordult, pl. promóciós tételeknél, ezt a
viselkedést a javítás nem változtatja meg).

Alkalmazva minden ár-írási útvonalon:
- `api/product-save.php` — kézi termékszerkesztés (400-as hiba negatív/
  nem-numerikus árra), PLUSZ egy gyors, UX-célú kliens-oldali előzetes
  ellenőrzés (`product-modal.js`) — a szerver marad a HITELES forrás.
- CSV/JutaSoft import (`ProductRowNormalizer::validationError()`) — lásd
  lentebb "Import — soronkénti hibakezelés".
- WooCommerce-behúzás (`Database::upsertProductFromWc()`) — védelmi
  mélység: ha a WC véletlenül negatív árat adna vissza, a HELYI (régi,
  érvényes) ár marad meg, nem íródik felül egy nyilvánvalóan hibás
  értékkel.

### Import — soronkénti hibakezelés + JutaSoft regressziós fixture

**Soronkénti (nem all-or-nothing) hibakezelés**: `api/import-commit.php`
egyetlen tranzakcióban dolgozza fel a teljes fájlt (ez NEM változott),
de egy hibás ÁR miatt érvénytelen sor MOST már egyszerűen kihagyásra
kerül (`rejected` tömb a válaszban, ok+sor+név), NEM dobja el az egész
importot — egy "98 érvényes + 2 hibás" eredménye 98 importált + 2
elutasított sor, nem egy teljes rollback. Az `import-preview.php` egy
külön `invalid_price` számlálóval (a meglévő `zero_price`-tól
KÜLÖNVÁLASZTVA — a nulla ár megengedett, a negatív nem) már ELŐZETESEN
jelzi ezt, mielőtt a tényleges importálás megtörténne.

**JutaSoft regressziós fixture** (`tests/fixtures/jutasoft_export.csv` +
`tests/JutasoftImportFixtureTest.php`): egy valódi, reprezentatív
Jutasoft "Raktárkészlet nyomtatás" export-struktúra (6 sornyi
riport-metaadat a fejléc előtt, termék-sorok teljes/részleges
azonosítóval, egy negatív (hibás) árú sor, két összesítő/ÁFA-bontás sor
azonosító nélkül) — végigfuttatva a TELJES pipeline-on
(`CsvImporter::readRows()` → `ProductRowNormalizer`), nem csak
elszigetelt inline stringeken. Külön regresszió bizonyítja, hogy a
"Besz.ár" oszlop (`purchase_price_net`) NETTÓ értékként, ÁFA-konverzió
NÉLKÜL kerül be (ez már korábban is így működött, most explicit
tesztelve).

**Import ideiglenes fájlok**: `data/imports/*.upload`/`*.csv` — korábban
egy elindított, de sose befejezett import (böngészőlap bezárva előnézet
után) örökre a könyvtárban maradt (élesben egy 435 KB-os, hetekkel
korábbi árva fájl bizonyította). Az `import-preview.php` MOST minden
ÚJ előnézet-indításkor egy opportunista seprést végez: minden 4 óránál
régebbi `.upload`/`.csv` fájlt töröl — konkurrencia-biztos (egy éppen
folyamatban lévő import fájlja sosem ilyen régi).

### Frontend API-hibakezelés

Az audit szerint 18 lista-betöltő oldal fetch()-hívása NEM (vagy csak
részben) ellenőrizte a HTTP-válasz `ok` állapotát, mielőtt a törzsét
adatként feldolgozta volna — egy nem-2xx JSON hibaválasz emiatt csendben
"üres listaként" jelent meg, a valódi hibaüzenet sose jutott el a
felhasználóhoz. Új, központi `webroot/api.js` (`fetchJson()` segédfüggvény,
minden oldalra felvéve `topbar.js` elé) — minden érintett lista-betöltő
mostantól ezt hívja, és a dobott hibát a saját listaterületén jeleníti
meg, a betöltés-állapotot mindig visszaállítva.

### Backend hibakezelés

`webroot/api/_bootstrap.php` egy globális `set_exception_handler()` +
`register_shutdown_function()` párost kapott: minden, egyébként el nem
kapott `Throwable`/klasszikus PHP fatal hiba egységes, secret nélküli
JSON `{"error": "..."}` válaszra fordul (500), a teljes részlet az
`error_log`-ba kerül. `display_errors` explicit kikapcsolva — korábban
ez a hoszt saját PHP-konfigurációjától függött, nem volt garantált, hogy
egy el nem kapott hiba ne HTML-formázott, esetleg fájlelérési utat
tartalmazó választ adjon vissza egy `Content-Type: application/json`
válaszba ágyazva.

### Tesztek

Új tesztfájlok: `tests/WooCommerceClientTest.php` (valódi loopback
stub-szerver — timeout/DNS/4xx/5xx/hibás JSON osztályozás),
`tests/WcPushQueueWorkerTest.php` (claim/retry/backoff/dead-letter,
szkriptelt fake klienssel), `tests/PurchaseAndWcPushConcurrencyTest.php`
(2 valódi 16-folyamatos teszt), `tests/JutasoftImportFixtureTest.php` +
`tests/fixtures/jutasoft_export.csv`. Bővített meglévő fájlok:
`tests/DatabaseTest.php`, `tests/HttpSecurityTest.php`,
`tests/MigrationAtomicityTest.php`.

### Adatbázis-migráció

`Database::SCHEMA_VERSION` 22 → **24** (23 = beszerzés-idempotencia,
24 = `wc_push_queue`). Mindkét motoron (SQLite + MySQL) migrálva, friss
telepítési séma is frissítve.

**Váratlanul feltárt és javított, meglévő hiba** (nem 1.1.1-es
regresszió, de csak ekkor vált megfigyelhetővé): a self-update
rollback-folyamata (`UpdateInstaller`) a MEGLÉVŐ, még nyitva tartott
adatbázis-kapcsolat ALATT írta felül nyersen a SQLite-fájlt egy
mentésből — WAL-módban ez valódi fájlsérülést okozhatott
("database disk image is malformed"), amit egy nagyobb séma
(pontosan a fenti `wc_push_queue` tábla bevezetése) determinisztikusan
reprodukálhatóvá tett. Javítva: `Database::closeForExternalFileReplacement()`
+ `reconnect()` — a kapcsolat a nyers fájlcsere KÖRÜL explicit le- majd
újranyílik, nem csak utólag cserélődik le.

## FountainTrade 1.2.0 — Dashboard és üzleti riportok

Feature release: a meglévő sales/purchases/inventory/invoice/WooCommerce
adatokból ad valódi üzleti áttekintést — nincs új külső integráció, minden
riport a MÁR meglévő adatmodellből épül fel (nincs párhuzamos "stock ledger"
vagy egyéb duplikált adattábla).

### Dashboard (`dashboard.php`)

Az alkalmazás alapértelmezett kezdőoldala — bejelentkezés után és a
csupasz gyökér-URL-en (`http://host/`) is ez nyílik meg. A "Kassza" oldal
saját, explicit `index.php` URL-jén (pl. a sidebar-linkről) változatlanul
elérhető, a Kassza tartalma nem módosult — csak a *bejelentkezés utáni
alapértelmezett cél* és a *gyökér-URL viselkedése* változott. A sidebar-ban
NINCS külön Dashboard-menüpont — a logó (bal felső sarok) vezet rá minden
oldalról, minden nézetben (asztali/tablet/mobil).

Szándékosan gyors, tömör napi áttekintő, nem egy újabb, szűrhető
riport-oldal (azokhoz lásd lent a Forgalmi/Készlet riportot) — logikai
sorrendben:

1. **Fejléc** — mai dátum magyar formátumban + névnap
   (`src/HungarianNameDays.php`: lokális, verziózott, teljes éves
   naptáradat, NINCS runtime külső API-hívás; ha egy napra a forrásban
   nincs hitelesen megállapítható névnap — jelenleg január 23-24. és
   február 29. —, a mező egyszerűen `null`, a felület nem jelenít meg
   "Névnap:" sort, sose kitalált nevet), és egy rendszerállapot-jelző
   (🟢/🟠/🔴) — KIZÁRÓLAG már meglévő, ténylegesen mért jelekből (24 órás
   sync-hiba, WooCommerce/NAV-számla sikertelenségek), sose fiktív állapot.
2. **Mai KPI-k** — mai árbevétel/eladásszám/átlagos kosárérték/beszerzés,
   ahol értelmezhető a tegnapi naphoz viszonyított %-os változással (csak
   akkor jelenik meg, ha a tegnapi bázisadat ténylegesen nem nulla).
3. **"Figyelmet igényel"** — elfogyott/alacsony készletű termékek, az
   előrejelzés alapján 7 napon belül várhatóan kifogyó termékek (a
   meglévő forecast-logikából, lásd lent), feldolgozás alatt lévő webshop-
   rendelések, sikertelen számlák/WooCommerce-szinkron — csak a
   ténylegesen fennálló (>0) tételek, mindegyik a megfelelő meglévő
   oldalra/riportba mutat.
4. **Napi állapotok** — napi zárás állapota, webshop-rendelések,
   számlázási hibák (7 nap).
5. **Bevétel — utolsó 7 nap** — a meglévő `revenue-trend.php`-t
   újrahasznosítja (`?days=7`), nincs duplikált business logic.
6. **Mai top termékek** / **Mai fizetési módok** — kompakt lista, link a
   teljes Forgalmi riportra.

Minden Dashboard-adat a MEGLÉVŐ Database-metódusokból épül fel
(`dashboard-summary.php`) — egyetlen új, apró kiegészítés
(`Database::countLowRunwayProducts()`), ami maga is csak a meglévő
alacsony-készlet/előrejelzés-metódusokat komponálja össze, nem vezet be
új üzleti szabályt.

A riport-oldalak saját, szabadon állítható időszakválasztója
(ma/tegnap/7 nap/30 nap/aktuális hónap/előző hónap/egyedi) minden
riport-oldalon egységes, a dátumhatárokat KIZÁRÓLAG a backend számolja ki
(`src/ReportPeriod.php`, `Europe/Budapest` időzóna, ugyanaz, mint a napi
zárásnál) — a kliens csak egy kulcsszót küld.

### Forgalmi riport (`sales-report.php`)

Bruttó/nettó forgalom, eladásszám, átlagos kosárérték, fizetésimód szerinti
bontás (darabszám/összeg/százalék, a `payment_methods` beállításból, nincs
hardcodolt lista), napi bontás, és Top termékek (csoport/minimum darabszám
szűréssel). A visszárukat a visszáru SAJÁT napja szerint vonja le — ugyanaz
az elv, mint a már meglévő napi zárásnál/bevétel-trendnél — és a Top
termékek nettósítva (eladott − visszáru) számol, nem egyszerű
`SUM(qty)`-vel. Az `invoices` (NAV modification/storno-lánc) tábla SOSE
kerül a forgalom-számításba — a `sales`/`returns` a forgalom egyetlen
forrása, ez zárja ki a sales/invoice fogalom összekeverését.

### Készlet riport (`inventory-report.php`)

Összesített termékszám/készleten/nulla/negatív/alacsony készlet, teljes
készletérték (a MEGLÉVŐ `purchase_price_net` — utolsó ismert nettó
beszerzési ár — mezőből, nincs új cost accounting bevezetve), legnagyobb
készletértékű termékek. Alacsony készletű termékek lapos listája (a
meglévő, beszállító szerint csoportosító Beszerzési javaslat oldal
mellett), szűrhető alacsony/kifogyott állapotra, CSV exporttal.

### Készletmozgások (`stock-movements.php` + termék-részletező fül)

A MEGLÉVŐ mozgás-forrás táblák (`sale_items`, `purchase_items`,
`return_items`, `stock_take_items`, `stock_transfers`) uniója, dátum/típus/
termék szerint szűrhető, lapozható, CSV exporttal. A termék-részletező
modaljában ("Árucikkek" oldal) új "Készletmozgások" fül mutatja az adott
termék elmúlt 365 napi mozgásait. Nincs before/after (historikus
pillanatkép) mező — a meglévő adatmodell ezt nem biztosítja, a riport ezt
őszintén NULL-ként jelzi, nem hamis pontossággal. A telephelyek KÖZÖTTI
mozgatás (nettó 0 hatás az összesített készletre) nem szerepel itt — azt a
meglévő Telephelyek-oldal saját előzmény-nézete fedi le.

### Készlet-előrejelzés

Egyszerű, átlátható modell (nincs ML): `átlagos napi fogyás (visszáruval
nettósítva) + aktuális készlet = becsült hátralévő napok`, állítható
időablakkal (alapértelmezett 30 nap). Négy állapot, hamis pontosság
nélkül:

- **`out_of_stock`** — a készlet már most is ≤ 0, nincs értelme napot
  becsülni.
- **`insufficient_data`** — a termékhez az ablakban csak EGY elszigetelt
  eladási nap tartozik — egyetlen adatpont nem megbízható ráta.
- **`zero_consumption`** — a termék az ablakban egyáltalán nem fogyott
  (de VAN adat, ez egy magabiztos "nem fogy" megállapítás, nem
  bizonytalanság).
- **`ok`** — legalább 2 különböző eladási nap + pozitív nettó fogyás →
  `becsült hátralévő napok = aktuális készlet / átlagos napi fogyás`.

Az előrejelzés megjelenik a Beszerzési javaslat oldalon (a meglévő,
beszállító szerint csoportosított listát egészíti ki, a javasolt-mennyiség
logikát nem módosítja) és a Készlet riport alacsony-készlet listáján.

### WooCommerce szinkron-monitor (`woocommerce-sync.php`)

Admin UI a 1.1.1-ben bevezetett `wc_push_queue`-hoz: állapot szerinti
összesítő (queued/processing/done/failed/dead_letter) és a sikertelen
sorok listája kézi "Újrapróbálás" gombbal. A gomb kizárólag terminális
(failed/dead_letter) sorra engedélyezett, vezetői jogszintet igényel — a
MEGLÉVŐ `Database::resetWcPushForManualRetry()`-t hívja, nincs új
állapotgép. A Dashboard egy rövid összesítőt mutat ("Várakozó: N",
"Sikertelen: N" csak ha van), sose blokkolja a kasszát.

### NAV számla-queue összesítő

A Dashboardon látható a függőben lévő/sikertelen NAV-számlák száma (a
MEGLÉVŐ `Invoices::INVOICE_STATUS_BUCKETS` leképezéssel, nincs új state
machine), link a Kimenő számlák oldalra. Csak akkor jelenik meg
tartalmasan, ha a `invoice_provider` beállítás ténylegesen `nav`.

### API

Új, kizárólag olvasó (GET, bejelentkezés szükséges) végpontok:
`dashboard-summary.php`, `sales-report.php`, `top-products-report.php`,
`inventory-report.php`, `low-stock-report.php`, `stock-movements-report.php`,
`product-stock-movements.php`, `stock-forecast.php`,
`woocommerce-sync-status.php`, plusz 4 CSV export végpont (sales/inventory/
low-stock/stock-movements — mind a meglévő `csv_safe()` formula-injekció
védelemmel). Egyetlen állapotváltoztató végpont: `woocommerce-sync-retry.php`
(POST, admin + CSRF). A `purchase-suggestions.php` additívan bővült
(`forecast` mező soronként), a válasz-alakja egyébként változatlan.

### Adatbázis-migráció

`Database::SCHEMA_VERSION` 24 → **25** — KIZÁRÓLAG index (nincs új
tábla/oszlop): `idx_returns_created_at`, `idx_return_items_product_id`,
`idx_stock_take_items_product_id` — a riportok új dátum-/termék-szerinti
lekérdezéseihez, mindkét motoron (SQLite + MySQL), friss telepítési séma is
frissítve.

### Teljesítmény

A Dashboard KPI-jai aggregált SQL-lekérdezések (nincs termékenkénti/
soronkénti külön query) — a készletáttekintés egyetlen `SUM(CASE WHEN...)`
lekérdezés, a forecast bulk-metódusa két batch-lekérdezéssel dolgozza fel
akár több száz terméket is. Nincs cache bevezetve a valós idejű adatokra
(mai forgalom, aktuális készlet, függő szinkron) — ezeknél a friss adat
fontosabb, mint a válaszidő egy pár tized másodperces megtakarítása.

### Ismert korlátok

- A Készletmozgások riport nem tartalmaz before/after készlet-
  pillanatképet (lásd fent) — csak a mennyiségváltozást.
- A készlet-előrejelzés lineáris átlagra épül, szezonalitást/trendet nem
  ismer fel — ez szándékos, dokumentált egyszerűsítés (lásd fent).
- A Forgalmi riport a teljes időszak eladásait/tételeit egyszerre tölti be
  a szerver memóriájába (2 batch lekérdezéssel) a pontos, tétel-szintű
  kedvezmény-arányosítás miatt — ez ennek az alkalmazás-méretnek
  (egybolti POS) megfelelő, nagyon nagy (több tízezer eladás/hónap)
  forgalomnál érdemes lehet később ezt is aggregált SQL-re váltani.

## FountainTrade 1.3.0 — Beszerzési döntéstámogatás és árrés

Az 1.2.0 Dashboard/riportok/forecast alapjára építve: a rendszer mostantól
KONKRÉT beszerzési döntést támogat (nem csak megmutatja a készlet
állapotát), plusz termékszintű és riport-szintű árrés-számítást és
készletérték-mutatókat ad. Nincs új adatbázistábla/migráció — minden a
MEGLÉVŐ adatmodellből (`purchase_items`, `products.net_price`/
`.purchase_price_net`) és a MEGLÉVŐ forecast-logikából
(`Database::getStockForecastBulk()`) épül.

### A döntési képletek — `src/PurchaseDecisionService.php`

Egyetlen, DB-független, önmagában unit-tesztelhető osztály adja MINDEN
biztonsági készlet / rendelési pont / javasolt mennyiség / árrés
számítást (lásd a fájl saját, részletes docblockját a teljes
indoklásért) — nincs szétszórva az endpointok között.

**Miért nem a klasszikus, statisztikai biztonsági-készlet-képlet?** A
jelenlegi adatmodell nem tárol sem beszállítónkénti szállítási időt, sem
kereslet-szórást — ezeket ÖNKÉNYESEN kitalálni tiltott (lásd a fejlesztési
kör 1. pontja). Ehelyett:

```text
biztonsági készlet  = a termékhez beállított (vagy alapértelmezett) riasztási küszöb
                       (a MEGLÉVŐ low_stock_threshold / low_stock_default_threshold —
                       ez az EGYETLEN már létező, boltvezető-szándékot kifejező adat)

rendelési pont       = biztonsági készlet + (napi fogyás × 7 nap)
                       [REVIEW_PERIOD_DAYS — dokumentált, fix alapérték,
                        mert nincs tárolt szállítási idő]

javasolt mennyiség   = max(0, biztonsági készlet + (napi fogyás × 14 nap) − aktuális készlet)
                       [TARGET_COVERAGE_DAYS — dokumentált, fix alapérték]
```

A napi fogyás forrása a MEGLÉVŐ `getStockForecastBulk()` — ha egy
termékhez nincs elég eladási előzmény a megbízható becsléshez
(`insufficient_data`/`zero_consumption`), a javasolt mennyiség a régebbi
(1.1.1 óta bevált) "küszöb duplájára tölt fel" ökölszabályra esik vissza,
a sürgősség pedig `"low"` marad (sose `"urgent"`/`"soon"` megbízhatatlan
adatból).

**Sürgősség:** `urgent` (készlet ≤ 0, VAGY a forecast szerint ≤3 napon
belül kifogy), `soon` (forecast szerint ≤7 napon belül), `low` (a küszöb
alatt van, de nincs megbízható előrejelzés vagy távolabbi a kifogyás).

### Beszerzési javaslat lista (`beszerzesi-javaslat.php`)

A korábbi (1.1.1/1.2.0-as), beszállító szerint csoportosított nézetet
felváltja egy lapos, sürgősség szerint szűrhető lista (Sürgős/Hamarosan
elfogy/Alacsony készlet/Minden), termékenként a fenti mezőkkel + emberi
olvasható indoklással (`PurchaseDecisionService::buildReason()`, fix
szövegsablonok, nincs szabad szöveg-generálás). Több termék kijelölhető
és "Beszerzés indítása a kijelöltekkel" — ez a MEGLÉVŐ
`sm_purchase_prefill` sessionStorage-mechanizmust használja újra (lásd
`beszerzes.js`), nincs új beszerzési logika. Ha a kijelölt tételek mind
ugyanahhoz a preferált beszállítóhoz tartoznak, az előre kitöltődik;
vegyes beszállító esetén üresen marad, a felhasználó tölti ki.

**Beszerzési workflow — dokumentált korlát:** a lista minden sora
`"suggested"` vagy `"in_progress"` állapotot mutat — utóbbi azt jelzi,
hogy az elmúlt 3 napban MÁR volt beszerzés erre a termékre (a MEGLÉVŐ
`purchase_items`/`purchases` táblákból levezetve, nincs új "workflow
status" oszlop/tábla). A "Megrendelve → Részben beérkezett → Beérkezett"
teljes állapotgép NINCS implementálva — a jelenlegi purchase-modell
egyetlen, atomikus "a készlet MOST megérkezett" eseményt ír le, nincs
benne "megrendelve, de még nem érkezett meg" fogalom; ennek bevezetése a
tételek szintjén rendelt-vs-beérkezett mennyiség külön követését
igényelné, ami egy önálló architekturális bővítés, nem ennek a körnek a
hatóköre (lásd ROADMAP.md).

### Árrés

```text
Árrés Ft = nettó eladási ár − nettó beszerzési ár
Árrés %  = Árrés Ft / nettó eladási ár × 100
```

A MEGLÉVŐ `products.net_price` (nettó eladási ár) és
`products.purchase_price_net` (utolsó ismert nettó beszerzési ár) mezőket
használja — nincs új, párhuzamos ár-értelmezés. **Megbízhatóság-ellenőrzés:**
egy `purchase_price_net = 0` KÉTFÉLE dolgot jelenthet — "sose lett még
beszerezve a termék" (megbízhatatlan, 100%-os hamis árrést mutatna) vagy
"ténylegesen 0-ért lett beszerezve" (megbízható, ritka, de valós eset). A
kettő megkülönböztetéséhez a rendszer bulk lekérdezéssel ellenőrzi, van-e
EGYÁLTALÁN `purchase_items` sor a termékhez (`Database::productsHavePurchaseHistory()`)
— csak akkor számol árrést, ha van. Ahol nincs, a felület explicit
"nincs adat"-ot mutat, SOSE egy hamis 0 Ft-os vagy 100%-os árrést.

A Forgalmi riport (`sales-report.php`) és a Top termékek lista
termékenként és időszaki összesítésben is mutatja (lásd
`Database::getSalesMarginSummary()`/a kiegészített `getTopProductsReport()`),
és EXPLICIT jelzi, hány termékre nem számolható árrés. **Dokumentált
egyszerűsítés:** a nettósításhoz a termék JELENLEGI ÁFA-kulcsát használja
(nem az eladáskori `sale_items.vat_rate`-et), és a JELENLEGI
`purchase_price_net`-et (nem historikus/FIFO költséget) — konzisztensen
azzal, ahogy az 1.2.0 készletérték-számítás is a "jelenlegi állapot"
elvet követi.

### Készletérték-mutatók

`inventory-report.php` — nettó beszerzési áron ÉS nettó eladási áron is
megadja a teljes készlet értékét, plusz a potenciális árrés-értéket
(`Database::getInventoryValuationSummary()`) — utóbbi kettő csak a
megbízható költségű termékekre, a megbízhatatlanok számát explicit
jelezve. A Dashboardon (a kör 7. pontja szerint) csak EGY KPI jelenik
meg ("Készletérték (beszerzési áron)") — a teljes bontás a Készlet
riportban érhető el.

### Termék mini-dashboard (`termekek.php` → "Áttekintés" fül)

Egy termék megnyitásakor (meglévő terméknél) az "Áttekintés" fül az
alapértelmezett nézet — egyetlen backend-hívással
(`Database::getProductInsights()` → `product-insights.php`, lásd a kör
11. pontja: nincs 6-8 külön kérés) mutatja: állapot (készlet/érték/ár/
árrés), 30/90 napos forgalom, forecast, legutóbbi beszerzések, beszerzési
ártrend. Új termék létrehozásakor (nincs még mit áttekinteni) a "Fő
adatok" fül marad az induló nézet, változatlanul.

### Dashboard integráció

A "Figyelmet igényel" blokk beszerzési fókuszú tételei (`"X sürgősen
beszerzendő termék"`, `"Y termék N napon belül várhatóan elfogy"`) a
MEGLÉVŐ `getPurchaseRecommendations()`-t használják — ez FELVÁLTOTTA az
1.2.0-as, ad-hoc (zero_stock/forecast_low/low_stock) dashboard-only
logikát, hogy egyetlen, központi helyen (`PurchaseDecisionService`)
dőljön el, mi számít sürgősnek. Mindegyik tétel a Beszerzési javaslat
oldalra mutat, a megfelelő sürgősségi fülre előszűrve
(`?urgency=urgent`/`soon`/`low`).

### API

Új, kizárólag olvasó végpontok: `product-insights.php` (termék mini-
dashboard). Bővült válasszal: `purchase-suggestions.php` (a régi,
beszállító-csoportosított javaslat helyett a teljes döntéstámogató
lista), `inventory-report.php` (`valuation` mező), `sales-report.php`
(`margin` mező), `top-products-report.php` (margin mezők soronként —
maga a `getTopProductsReport()` bővült). Egyetlen mutáló végpont sincs
ebben a körben — a beszerzés tényleges rögzítése változatlanul a MEGLÉVŐ,
admin/CSRF-védett `purchase-save.php`-n megy keresztül.

### Ismert korlátok (1.3.0)

- A "Megrendelve → Részben beérkezett → Beérkezett" beszerzési workflow
  nincs implementálva (lásd fent — dokumentált, szándékos döntés).
- A beszerzési/margin-képletek a JELENLEGI (nem historikus) árakat és
  ÁFA-kulcsot használják — lásd fent.
- `REVIEW_PERIOD_DAYS`/`TARGET_COVERAGE_DAYS` fix, dokumentált
  konstansok (nem beszállítónkénti szállítási időből származtatva) — ha
  a jövőben a `suppliers` tábla kapna egy `lead_time_days` mezőt, ez
  lecserélhető lenne rá.

## Biztonság

**A valódi védelem az, hogy minden adat és minden művelet kizárólag az
API-n keresztül érhető el.** Ez az alapréteg, ami minden más fölött áll:
minden `api/*.php` végpont (a `_bootstrap.php`-n keresztül) elutasít
minden kérést bejelentkezés nélkül. Mivel minden adat és minden művelet
kizárólag ezen az API-n keresztül érhető el, még ha valaki bármilyen úton
hozzáférne egy oldal HTML/JS forrásához, tényleges adathoz vagy
funkcióhoz nem férne hozzá — a felület önmagában üres, működés nélküli.

Erre a réteg tetejére épül két további védelem:

1. **Kliens-oldali JS** (`topbar.js`) azonnal átirányít a `login.html`
   oldalra, ha nincs érvényes munkamenet — ez a felhasználói élményt
   szolgálja (gyors, egyértelmű átirányítás egy hibaüzenet helyett).
2. **Valódi oldal-szintű védelem**: mind a 17 dolgozói oldal ténylegesen
   PHP-fájl (nem statikus HTML), aminek a legelején egy szerver-oldali
   ellenőrzés fut le — ha nincs érvényes munkamenet, a szerver
   *egyáltalán nem küldi ki* az oldal tartalmát (lásd lentebb, "Valódi
   oldal-szintű védelem" szakasz).

Ez a rétegzés azt jelenti: még ha valamelyik réteg valamiért kimaradna
vagy hibásan működne, a másik kettő önmagában is elegendő védelmet ad.

### Üzemmód: Helyi vs. Nyilvános

Beállítások → Biztonság fülön kell **kifejezetten** megadni, hogy a
telepítés "Helyi" (`deployment_mode: local`, az alapértelmezés — csak ez
a gép/helyi hálózat éri el, jelszó nélkül is elérhető marad, a korábbi
viselkedésnek megfelelően) vagy "Nyilvános" (`deployment_mode: network`
— internetről/külső hálózatról is elérhető). Az alkalmazás szándékosan
**nem próbálja magától kitalálni** ezt (pl. "csak localhost-ról jön-e a
kérés") — egy reverse proxy vagy port-forwarding mögött ez a szerver
oldaláról nem állapítható meg megbízhatóan, egy rossz találgatás pedig
vagy feleslegesen zárná ki a helyi használatot, vagy (rosszabb esetben)
tévesen biztonságosnak hinné egy ténylegesen nyilvánosan elérhető
telepítést.

**"Nyilvános" üzemmódban a jelszavas védelem nem kapcsolható ki** — sem
úgy, hogy valaki megpróbálja kikapcsolni a már bekapcsolt jelszót, sem
úgy, hogy "Nyilvános"-ra váltana anélkül, hogy előbb beállítana egy
jelszót. Ezt a `security-settings-save.php` szerver-oldalon kényszeríti
ki (a felület csak segít elkerülni a hibát, de a valódi kapu a
szerveren van), és az `Auth::isEnabled()` is — ha a `settings.json`
valamiért sérült vagy kézzel szerkesztett állapotban `deployment_mode:
network`-öt tartalmazna `app_password_enabled: false` mellett, az
alkalmazás akkor is bejelentkezést követel meg (fail closed), nem esik
vissza csendben jelszó nélküli módra.

### Bejelentkezés (opcionális, kikapcsolható "Helyi" üzemmódban)

Beállítások → Biztonság fülön kapcsolható be egy alkalmazás-szintű jelszó
(alapból ki van kapcsolva — bekapcsolása után minden oldal bejelentkezést
kér). Ez **különbözik a dolgozói PIN-kódtól**: az csak elszámoltat (ki
dolgozott a Kasszánál), ez itt a teljes programhoz való hozzáférést zárja.

- Jelszó `password_hash()`-sel tárolva, sosem kerül vissza a kliensnek
  (a `settings.php` és minden más végpont explicit módon kiszűri).
- Session-cookie `HttpOnly` + `SameSite=Strict`, és — közvetlen HTTPS
  vagy egy megbízható, ugyanazon a gépen futó reverse proxy (lásd lent)
  esetén — `Secure` is. Emellé egy ténylegesen kikényszerített,
  token-alapú CSRF-védelem: minden nem-fehérlistás POST-kérésnek
  érvényes `X-CSRF-Token` fejlécet kell küldenie (`Auth::csrfToken()`
  ad ki egy tokent bejelentkezés nélkül is, `verifyCsrf()` ellenőrzi
  minden POST-on a `_bootstrap.php`-ban) — ez a `SameSite=Strict`
  fölötti, második, ténylegesen ellenőrzött védelmi réteg, nem csak egy
  jövőbeli lehetőség.
- A `Secure` sütijelző `$_SERVER['HTTPS']`-re támaszkodik, VAGY — ha az
  közvetlen TCP-kapcsolat (`REMOTE_ADDR`) maga is loopback (127.0.0.1 /
  ::1) — az `X-Forwarded-Proto: https` fejlécre, ugyanazzal a szűk
  bizalmi határral, mint amit a GeoBlocker az X-Forwarded-For-nál
  használ. Egy TÁVOLI (más gépen/konténerben futó) reverse proxy mögötti
  telepítésen ez a fejléc NEM lesz megbízható — ilyenkor a webszervert
  kell úgy beállítani, hogy a valódi HTTPS-állapotot a PHP felé is
  közvetítse (pl. nginx+PHP-FPM esetén `fastcgi_param HTTPS on;` a
  443-as szerver-blokkban — lásd `telepites-tavoli-szerver.txt`).
- Automatikus kijelentkezés beállítható inaktivitás után (alapból 4 óra).
- **Rate limiting** mind az alkalmazás-jelszóra, mind a dolgozói PIN-re —
  túl sok sikertelen próbálkozás után ideiglenes zárolás (fájl-alapú
  számláló, nincs szükség extra adatbázis-táblához).

**A korábbi kompromisszum feloldva**: a nyomtatott nyugtákon lévő QR-kód
mostantól bejelentkezés nélkül is biztonságosan megtekinthető, mert egy
titkos, kitalálhatatlan tokent tartalmaz (lásd lentebb, "Titkos
nyugta-token" szakasz) — nem a kitalálható eladás-sorszámra támaszkodik.

### IP-cím / ország alapú hozzáférés-korlátozás

Opcionális, alapból kikapcsolva. A Beállítások → Biztonság → "IP-cím /
ország alapú védelem" az egész appot egy engedélyezett ország-listára
(ISO 3166-1 alpha-2 kódok) és/vagy adott IP-címekre vagy CIDR-tartományokra
korlátozza — mind IPv4, mind IPv6 támogatott.

- Szerver-oldalon két helyen érvényesül: minden `api/*.php` kérésnél (a
  `_bootstrap.php`-ban, a bejelentkezés-ellenőrzés előtt), és minden
  oldal-szintű fájlnál (a `GeoBlocker::enforce()`-on keresztül), így mind
  az adathozzáférés, mind az oldalbetöltés blokkolva van a nem
  engedélyezett látogatóknak.
- Az ország-felismerés az ingyenes, kulcs nélküli `ip-api.com`
  szolgáltatást használja; az eredményeket a szerver helyben, a
  `data/geoip-cache.json`-ban gyorsítótárazza 30 napig, hogy ugyanaz a
  látogató ne legyen minden kérésnél újra lekérdezve. Ez azt jelenti,
  hogy a szervernek kimenő internet-hozzáférésre van szüksége ahhoz,
  hogy a korlátozás egyáltalán működjön — ha a lekérdezés sikertelen
  (nincs internet, korlátozott a kéréshatár, stb.), a látogató
  átengedésre kerül tiltás helyett, hogy egy külső szolgáltatás kiesése
  ne zárhasson ki mindenkit.
- A privát/loopback IP-kről (LAN, localhost) érkező kérések mindig
  megkerülik a korlátozást — az azonos gépről vagy azonos hálózatról
  érkező hozzáférés emiatt sosem zárható ki.
- Egy olyan beállítás mentése, ami kizárná a mentést végző IP-t,
  hibaüzenettel elutasításra kerül, hogy elkerülje a véletlen
  önkizárást.
- Az `X-Forwarded-For`/`X-Real-IP` fejlécek csak akkor megbízhatók, ha a
  közvetlen TCP-partner (`REMOTE_ADDR`) maga is egy privát cím (azaz egy
  helyi reverse proxy áll előtte) — egy közvetlenül a nyilvános
  internetről csatlakozó kliens nem tudja meghamisítani az országát egy
  hamis fejléc küldésével.
- Maga a statikus `login.html` keret nincs korlátozva (nem tud PHP-t
  futtatni), de minden mögötte lévő funkció — beleértve magát a
  bejelentkezés API-hívást is — igen, így egy blokkolt látogató
  legfeljebb egy üres, működésképtelen keretet kap.

### HTTP biztonsági fejlécek

Minden API-válasz tartalmazza: `X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY`, `Referrer-Policy: same-origin`.

### XSS-védelem

Az adatbázisból származó szöveg (termék/vevő/beszállító név, jegyzetek,
kupon-kódok stb.) — ami importból, WooCommerce-ből vagy bármely
dolgozótól származhat — mindenhol egy megosztott `escapeHtml()`
függvényen megy át, mielőtt `innerHTML`-be kerülne. Ez tárolt XSS ellen
véd: egy rosszindulatú vagy hibás adat (pl. egy termék neve
`<script>`-tartalommal egy import-fájlból) nem futtatható kódként jelenik
meg, csak szövegként.

### Fájlfeltöltés

- **Logó-feltöltés**: valódi (nem a kliens által állított) MIME-típus
  ellenőrzés `finfo`-val, whitelistelt formátumok (PNG/JPG/WEBP/SVG),
  2 MB-os méretkorlát, szerver-generált fájlnév (nincs path traversal
  vagy tetszőleges fájlnév-kockázat).
- **Mentés-visszaállítás**: `basename()` védelem a fájlnév-paraméterre,
  `is_uploaded_file()` ellenőrzés a feltöltött fájlra, a visszaállítás
  előtt mindig automatikus biztonsági mentés készül.
- **CSV-import**: véletlenszerű, szerver-generált fájlnév (nincs
  köze a feltöltött fájl eredeti nevéhez), a LibreOffice-konverzió
  minden paramétere `escapeshellarg()`-gal védett shell-injekció ellen.

### Valódi oldal-szintű védelem (nem csak API-szintű)

A korábbi verzióban minden oldal statikus `.html` fájl volt, amit a
webszerver PHP-futtatás nélkül, közvetlenül kiszolgált — emiatt a
bejelentkezés-kényszer csak API-szinten érvényesült (a HTML/JS "váz"
maga mindig kiment, csak funkcionálisan volt üres bejelentkezés nélkül).

Ez mostantól más: **mind a 17 dolgozói oldal (`index.php`,
`termekek.php`, `beallitasok.php` stb.) valódi PHP-fájl**, aminek a
legelején egy szerver-oldali ellenőrzés fut le — ha nincs érvényes
munkamenet, a szerver **egyáltalán nem küldi ki az oldal tartalmát**,
hanem azonnal átirányít a bejelentkező oldalra. Ez egy valódi védelmi
réteg a korábbi, csak-API-szintű megoldás fölött.

**Ami szándékosan kivétel maradt**: `login.html` (magának a bejelentkező
oldalnak nyilvánosan elérhetőnek kell lennie), `install.php` (az
első-indításos telepítő, ami a jelszó beállítása előtt fut le),
`receipt.html` és `label-print.html` (lásd alább).

### Titkos nyugta-token (a QR-kód kompromisszum feloldása)

Minden eladáshoz egy kitalálhatatlan, véletlenszerű token generálódik
(`sales.receipt_token`). A nyomtatott nyugta QR-kódja ezt a tokent is
tartalmazza a linkben — így a `receipt.html` **bejelentkezés nélkül is
biztonságosan megtekinthető**, mert nem az eladás sorszámán (ami
kitalálható lenne), hanem ezen a titkos tokenen keresztül azonosítja
magát. Bejelentkezett dolgozó továbbra is token nélkül, közvetlenül a
munkamenetén keresztül férhet hozzá bármelyik nyugtához.

### Ismert, tudatosan vállalt maradék korlátok

- **Számlázz.hu-számlázás: transport-bizonytalanság kezelve (P1-5),
  vak duplikátum-kockázat strukturálisan kizárva.** A helyi adatbázis
  egy atomikus foglalással (`invoice_claim_at`, 90 másodperces
  elévülési ablak) kizárja, hogy két egyidejű kérés mindkettő
  ténylegesen kiállítson egy számlát ugyanarra az eladásra. Egy
  SzamlazzClient-hívás kétféleképpen bukhat el: (1) a Számlázz.hu
  ténylegesen VÁLASZOLT (akár elutasítással) — ez egy DEFINITÍV
  kimenet, biztonságosan `invoice_failed`-ként rögzül, azonnal
  retry-elhető; (2) a hívásra EGYÁLTALÁN nem érkezett válasz
  (hálózati hiba/timeout) — ez esetben NEM TUDHATÓ, hogy a Számlázz.hu
  ténylegesen létrehozta-e a számlát, ezért a sale `invoice_uncertain`
  állapotba kerül, ami STRUKTURÁLISAN (nem csak az elévülési ablak
  lejártáig) kizárja a további automatikus próbálkozást — lásd
  `Database::tryClaimInvoiceIssuance()`/`markSaleInvoiceUncertain()`.
  Admin a Számlázz.hu felületén ellenőrizve, a "Kimenő számlák" nézet
  "Feloldás" műveletével (`webroot/api/szamlazz-invoice-resolve-uncertain.php`)
  zárhatja le manuálisan: vagy megerősíti, hogy nem készült számla
  (a sale ismét számlázható lesz), vagy rögzíti a ténylegesen
  megtalált számlaszámot (új kísérlet NÉLKÜL). Maradék, ŐSZINTE
  KORLÁT: a Számlázz.hu Számla Agent API nem kínál dokumentált,
  megbízható idempotencia-kulcsot/lekérdezési mechanizmust, amivel a
  rendszer AUTOMATIKUSAN egyeztethetné a bizonytalan kimenetelt (mint
  ahogy a NAV-oldal a `queryTransactionList`-tel teszi) — ezért itt a
  védelem "sose küldj vakon másodikat, kérj admin megerősítést",
  NEM "automatikusan bizonyítottan pontosan egyszer".
- **A mentés-titkosítási kulcs nem valódi KMS/HSM** — lásd "Automatikus
  mentések" szakasz.
- Lásd még: "Üzemmód: Helyi vs. Nyilvános" (a reverse proxy mögötti
  HTTPS-felismerés korlátja) és "Dolgozói jogszintek" (a megosztott
  jelszavas, dolgozói PIN nélküli telepítések admin-jogosultsági
  következménye).

## Automatizált tesztek

A projektnek nincs Composer-függősége, ezért a [PHPUnit](https://phpunit.de/)
egyetlen önálló `.phar` fájlként fut, letöltés után:

```bash
mkdir -p tools
curl -L https://phar.phpunit.de/phpunit-10.phar -o tools/phpunit.phar
php tools/phpunit.phar
```

A `tests/` mappa a `Database`, `GeoBlocker` és `SimpleXlsWriter`
osztályok kritikus, üzletileg fontos útvonalait fedi le — mindegyik
teszt egy egyszer használatos, ideiglenes SQLite fájllal dolgozik
(`tests/bootstrap.php`), így az éles `data/stock.sqlite`-ot soha nem
érinti:

- eladás rögzítése + készletcsökkentés (pozitív és negatív/túlértékesített
  készlet esetén is),
- vonalkód-keresés és az automatikusan generált EAN-13 vonalkód
  ellenőrzőszámának helyessége,
- kuponérvényesítés (aktív/inaktív, lejárat, felhasználási korlát,
  minimum vásárlási összeg, százalékos vs. fix kedvezmény),
- dolgozói admin-jogosultság és PIN-ellenőrzés,
- IP-cím/ország alapú hozzáférés-korlátozás (privát IP, allow-lista,
  CIDR-tartomány, kikapcsolt állapot) — a valódi ország-lekérdezés
  (külső hálózati hívás az ip-api.com felé) szándékosan nincs lefedve,
  hogy a tesztek gyorsak és hálózatfüggetlenek maradjanak,
- az XLS-export XML-kimenetének érvényessége és HTML-escapelése.

A `tests/UrlSafetyTest.php` az SSRF-védelmet (`UrlSafety`) teszteli
közvetlenül, hálózat-független IP-literálokkal (loopback, RFC1918,
link-local/felhő-metaadat cím, IPv6 loopback/private, nem-http(s) séma,
beágyazott hitelesítő adat).

A `tests/HttpSecurityTest.php` a fentiektől eltérően **valódi HTTP-kéréseket**
küld egy, a teszt által automatikusan felállított és a végén eltakarított,
teljesen önálló (ideiglenes mappában futó, saját üres SQLite-tal induló) PHP
beépített-szerver példány ellen — ez fedi le a `_bootstrap.php`-n keresztül
ténylegesen érvényesülő viselkedést: bejelentkezés-kényszer és fail-closed
sérült `settings.json` esetén, CSRF-ellenőrzés (hiányzó/érvénytelen/érvényes
token, `logout.php` is), cron-hitelesítés (`X-Cron-Token`, böngésző-session
nem helyettesítheti), a telepítő token-ellenőrzése és a "Helyi"/"Nyilvános"
üzemmód-kényszerítés. Éles `data/` mappát sose érint.

## Külső függőségek / Composer-mentesség

A projekt szándékosan nem használ Composer-t vagy más csomagkezelőt —
minden PHP-kód saját, a repóban lévő forrás. A frontendhez viszont van
két **kézzel bemásolt, a repóba commitolt** (vendored) JS-könyvtár a
`webroot/vendor/` alatt:

- **TinyMCE 8.9.0** (2026-08-27-i kiadás) — `webroot/vendor/tinymce/` —
  a termékleírás gazdag-szöveg szerkesztőjéhez (lásd "Termékleírás, kép,
  márka és WooCommerce-szinkron kapcsoló" szakasz).
- **qrcode-generator** — `webroot/vendor/qrcode-generator/` — a nyugtán
  megjelenő QR-kód kliens-oldali generálásához.

**Ez azt jelenti, hogy ezeknek a könyvtáraknak a biztonsági frissítése
KÉZI folyamat**: nincs `npm audit`, `composer audit` vagy Dependabot-féle
automatikus riasztás, ami jelezné, ha a TinyMCE-nek (vagy a
qrcode-generatornak) új verziója/biztonsági javítása jelenik meg. Egy
jövőbeli biztonsági résre a jelenlegi felállásban csak úgy derülne fény,
ha valaki kézzel ellenőrzi a felsőbb verziót és összeveti a
`webroot/vendor/tinymce/tinymce.min.js` fájl elején lévő verziószámmal
(`/* TinyMCE version X.Y.Z (dátum) */`).

Ez a beállítás **szándékosan marad így ebben a körben** — egy teljes
Composer/npm-alapú build-lánc bevezetése ennél a projektméretnél
aránytalanul nagy architekturális változás lenne, és az RC
feature-freeze alatt nem indokolt (lásd ROADMAP.md). A gyakorlati
kompromisszum: a `webroot/vendor/` alatti fájlokat időnként (pl.
félévente, vagy ha egy konkrét TinyMCE CVE napvilágra kerül) kézzel
érdemes újra letölteni a hivatalos forrásból és lecserélni — ezt a
README-t frissítve az új verziószámmal.

A TinyMCE maga **csak a bejelentkezést/érvényes munkamenetet igénylő
oldalakon** töltődik be (`termekek.php` és `beszerzes.php` — lásd "Valódi
oldal-szintű védelem" szakasz), nem egy nyilvánosan, hitelesítés nélkül
elérhető felületen — ez csökkenti (de nem szünteti meg) egy esetleges
TinyMCE-sebezhetőség kihasználhatóságát, mert egy támadónak előbb
érvényes munkamenetre lenne szüksége.

Ez a kezdeti kör nem törekszik teljes lefedettségre (nincsenek HTTP-szintű
végpont-tesztek, pl. `webroot/api/*.php` közvetlen hívásai) — a cél az
volt, hogy a legkockázatosabb, pénzügyi hatású logika (készlet, kupon,
jogosultság) automatikusan ellenőrizhető legyen egy jövőbeli módosítás
után is.

## FountainTrade 1.4.0 — Operations & Reliability

Üzemeltetési/megbízhatósági kör — a cél NEM új üzleti funkció, hanem
hogy egy pillantással eldönthető legyen "rendben van-e a rendszer?", és
ha nem, "mi a baj?" és "mit tegyek?". Minden új felület a MEGLÉVŐ
architektúrára épül (audit_log, sync_log, `update_state`/`update_history`
minta, `getWcQueueStatusSummary()`/`getInvoiceQueueStatusSummary()`) —
nem készült párhuzamos naplózó/health-check rendszer.

### Rendszeresemény-napló (`system_events`)

Új, önálló tábla + `Database::logSystemEvent()`/`getSystemEvents()`/
`countRecentSystemEventsBySeverity()`. Minden bejegyzés: kategória
(`backup`/`woocommerce`/`nav`/`updater`/`printer`/`smtp`/`auth`/
`database`), súlyosság (`info`/`warning`/`error`), állapot
(`started`/`success`/`failure`), egy felhasználóbarát üzenet, és egy
KÜLÖN technikai részlet — utóbbi SOSE látszik nem-admin felhasználónak
(ugyanaz az "effektíve admin" ellenőrzés, mint a számla-részletnézeten).

**Miért külön tábla, és nem az `audit_log` bővítése?** Az `audit_log`
egy EMBER által végzett akciót ír le (`staff_id`, `action`,
`entity_type`/`id`) — nincs benne súlyosság/állapot-fogalom, és nincs
"háttérfolyamat, aminek nincs emberi szereplője" koncepció (pl. egy
automatikus cron-alapú szinkron). A `sync_log` sem alkalmas bővítésre:
annak SOHA nem volt semmilyen megőrzési/takarítási mechanizmusa (végtelenül
nő), és kizárólag a WooCommerce termékszinkron üzeneteire korlátozódik.
A `system_events` egy kereszt-metszeti, üzemeltetési idővonal — minden
íráskor automatikusan takarít a `system_events_retention_days`
beállítás szerint (alapértelmezés: 14 nap), tehát NEM nőhet korlátlanul.

### `audit_log` vs. `system_events` — a határvonal, és mi lett kiegészítve

Ebben a körben végigmentünk azon a listán, hogy a felhasználó-szándékú
akciók (készlet-korrekció, beszerzés, eladás, számla-akció,
visszaállítás, frissítés, biztonsági beállítás-módosítás, admin
beállítás-módosítás, dolgozó-változás) valóban vissza vannak-e követve
valahol — és VALÓDI, korábban hiányzó lefedettségi réseket találtunk:

- **Biztonsági beállítás-módosítás** (`security-settings-save.php`) és
  **admin beállítás-módosítás** (`settings.php`) — EGYIKNEK sem volt
  eddig SEMMILYEN nyoma sehol. Mindkettő mostantól `logAudit()`-ot ír
  (`security_settings_update`/`admin_settings_update`), de a `details`
  KIZÁRÓLAG a módosított mezők NEVEIT sorolja fel — a tényleges (sokszor
  titkos: jelszó/API-kulcs/token) ÉRTÉKEKET soha.
- **Dolgozó-változás** (`staff-save.php`) — új dolgozó felvétele vagy
  meglévő szerkesztése (beleértve a szerepkör-váltást és a PIN-cserét)
  szintén nem volt naplózva. Mostantól `staff_create`/`staff_update`
  eseményt ír, a PIN tényleges értéke nélkül (csak azt jelzi, történt-e
  csere).
- **Beszerzés** (`purchase-save.php`) — a `purchases` táblának NINCS
  `staff_id` oszlopa (lásd `schema.sql`), tehát "ki rögzítette ezt a
  beszerzést" enélkül visszakereshetetlen lett volna. Egy sémamódosítás
  (oszlop hozzáadása) aránytalanul nagy beavatkozás lett volna egy
  üzemeltetési körben — a MEGLÉVŐ `audit_log` mechanizmus pontosan erre
  a kérdésre való, ezért `purchase_create` eseményt ír, sémaváltoztatás
  nélkül.

Ahol NEM készült új naplózás, mert a lefedettség MÁR megvan a saját
üzleti táblán keresztül (a `staff_id`/`created_at` pár már ott van):
**eladás** (`sales.staff_id`), **készlet-korrekció/leltár**
(`stock_takes.staff_id`), **telephely-mozgatás**
(`stock_transfers.staff_id`), **visszáru** (`returns.staff_id`). Ezeknél
egy PÁRHUZAMOS `audit_log`-bejegyzés redundáns lenne, és a `sales` a
legforgalmasabb útvonal az egész appban — egy extra írás minden egyes
eladásnál indokolatlan terhelés/zaj lenne egy már teljes körűen
nyomon követett akcióhoz.

**Számla-akció** (`invoice-modify.php`/`invoice-storno.php`) és
**visszaállítás** (`backup-restore.php`) MÁR korábban is naplózva volt —
nem változott.

**Frissítés** (self-update) SZÁNDÉKOSAN a `system_events`-be kerül, NEM
az `audit_log`-ba — ez egy automatikus/háttérfolyamat, aminek nincs
egyetlen konkrét emberi szereplője egy adott pillanatban (lásd
`UpdateState::transitionTo()`), pontosan az `audit_log` és a
`system_events` közötti, e kör elején meghúzott elvi határ szerint.

### `HealthMonitor` — egyetlen állapot-forrás

`src/HealthMonitor.php` egyetlen, újrafelhasználható
`computeComponentStatuses()` függvényben számítja ki MINDEN komponens
(adatbázis, biztonsági mentés, WooCommerce, NAV, Számlázz.hu, SMTP,
nyomtató, frissítő) állapotát — öt lehetséges érték: `OK` / `WARNING` /
`ERROR` / `NOT_CONFIGURED` / `UNKNOWN`. Ugyanezt a számítást olvassa a
Rendszerállapot oldal ÉS a Dashboard widget ÉS a figyelmet-igénylő-elemek
listája — szándékosan NINCS két-három párhuzamos "mi a baj" logika.

Csak a ténylegesen beállított integrációk jelennek meg (egy be nem
állított WooCommerce/NAV/Számlázz.hu/SMTP egyszerűen hiányzik a
listából, nem hamis piros/zöld jelzést kap). A cron-alapú komponenseknél
(biztonsági mentés, WooCommerce szinkron, NAV várólista) az "elavult"
küszöb az adott feladat SAJÁT, dokumentált gyakoriságához igazodik (nincs
egy közös, globális időtúllépés-érték), és a döntés a frissesség MELLETT
a cron-végpontok "Hiba:" előtaggal jelzett összegzés-szövegét is
figyelembe veszi — enélkül egy tartósan hibázó, de rendszeresen lefutó
cron hamis-zöldet mutatna, mert az időbélyeg hibás lefutáskor is frissül.

A rendszer SOHA nem fabrikál "következő futás" időpontot — az alkalmazás
nem ismeri a Windows Feladatütemező tényleges belső ütemezését, és egy
kitalált érték hamis biztonságérzetet adna (lásd ROADMAP.md).

### Rendszerállapot oldal

A `rendszerallapot.php` kártyái: "Áttekintés" (a korábbi statisztika-
rács, kiegészítve az összesített állapottal), "Figyelmet igényel" (csak
akkor jelenik meg, ha ténylegesen van valami), "Rendszer komponensek"
(állapot-pötty + felirat, utolsó ellenőrzés/siker időpontja, rövid
felhasználóbarát hibaüzenet, WooCommerce/NAV-nál várólista-összegzés
kattintható linkkel, ahol értelmezhető egy "Kapcsolat tesztelése" gomb),
"Eseménynapló" (kategória/súlyosság szűrővel), és a korábbi WooCommerce
termékszinkron-napló (`sync_log`), immár külön, termék-szintű
részletező nézetként megtartva.

### Dashboard "Rendszer állapota" kártya

Kompakt, egy-soros-komponensenkénti lista a Dashboard tetején,
kattintható linkkel a teljes Rendszerállapot oldalra. **Nem okoz új
HTTP-kérést** — a már meglévő, egyetlen aggregált `dashboard-summary.php`
hívás adja vissza ugyanazt az adatot, amit a Rendszerállapot oldal is
használ.

### Manuális kapcsolat-tesztek

A Rendszerállapot oldalon "Kapcsolat tesztelése" gomb érhető el
WooCommerce-hez (`webroot/api/wc-test-connection.php`) és NAV-hoz (új
`webroot/api/nav-test-connection.php`, admin+CSRF védett, a meglévő
`NavClient::testConnection()`-t hívja). Mindkettő **kizárólag olvasás
jellegű** — nem hoz létre/módosít üzleti adatot, nem küld valódi
WooCommerce-rendelést, nem állít ki számlát. A Számlázz.hu-hoz
SZÁNDÉKOSAN nincs ilyen gomb: a `SzamlazzClient` Agent API-jának nincs
dokumentált, mellékhatás-mentes tesztművelete — minden metódusa valódi
számlát hoz létre, módosít vagy sztornóz, így egy "teszt" gomb
valójában üzleti adatot hozna létre.

### Eseménynaplózás a háttérfolyamatokban

`logSystemEvent()` hívás került a következő helyekre: biztonsági mentés
(kézi és automatikus — indul/kész/sikertelen, visszaállítás indul/kész/
sikertelen), WooCommerce (automatikus szinkron indul/kész/sikertelen,
várólista-feldolgozás lefutott/elakadt tétel), NAV (kimenő várólista
feldolgozva/számla feldolgozva/sikertelen, bejövő szinkron kész/
sikertelen), frissítő (MINDEN állapotváltás —
ellenőrzés/letöltés/ellenőrzés/mentés/telepítés/migráció/health-check/
kész/sikertelen/visszaállítás — közvetlenül `UpdateState::transitionTo()`-
ból, `idle` kivételével, ami túl gyakori/nem informatív lenne),
nyomtató (**csak a sikertelen** automatikus nyugtanyomtatás — a sikeres
nyomtatás szándékosan NINCS naplózva, hogy ne árassza el a naplót normál,
nagy forgalmú kasszahasználat mellett), SMTP-teszt, bejelentkezés
(siker/sikertelen — **az IP-cím SOHA nem kerül naplózásra**),
kijelentkezés.

### `install-windows.ps1` — Windows telepítő/beüzemelő szkript

Önálló PowerShell szkript, ami az `install.txt` kézi lépéseinek egy
részét automatizálja egy új Windows gépen:

- PHP megtalálása, verzió-ellenőrzés (≥8.1), az összes kötelező
  kiterjesztés (`pdo_sqlite`, `sqlite3`, `curl`, `mbstring`, `gd`,
  `xmlwriter`, `zip`, `fileinfo`, `openssl`) tényleges meglétének
  ellenőrzése, OPcache-figyelmeztetés.
- Az írható mappák (`data`, `data\backups`, `data\imports`, `invoices`,
  `webroot\assets`) létrehozása/ellenőrzése, VALÓDI írás-teszttel (nem
  csak jogosultság-kikövetkeztetéssel).
- A beépített PHP szerver és mind az öt automatikus háttérfeladat
  (WooCommerce szinkron, biztonsági mentés, NAV kimenő/bejövő,
  frissítés-ellenőrzés) Feladatütemező-bejegyzéseinek **idempotens**
  létrehozása/frissítése — egy második (vagy N-edik) futtatás
  ELLENŐRZI a meglévő bejegyzést, és csak frissíti, sose duplikál.
- **A cron-titkos token SOHA nincs beégetve a szkript forrásába** — vagy
  a `-CronToken` paraméterrel adható át, vagy a szkript a MÁR LÉTEZŐ
  `data\settings.json`-ból olvassa be; ha egyik sem elérhető, a
  cron-feladatok létrehozása figyelmeztetéssel kimarad, a végső
  összegzés pedig pontosan megmondja, mit kell utólag tenni.
- Opcionális asztali parancsikon (idempotens — nem hoz létre duplikátumot).
- Valódi HTTP-alapú egészség-ellenőrzés a telepítés végén (ideiglenesen
  elindítja a szervert, ha még nem fut, lekéri a főoldalt, majd leállítja).
- Végén egy áttekinthető, színkódolt összegzés (OK/FIGYELEM/HIBA soronként).

Futtatás: `.\install-windows.ps1` (alapértelmezett beüzemelés) vagy
`.\install-windows.ps1 -CronToken "<a Beállításokban beállított token>" -Port 8000`
(teljes beüzemelés, cron-feladatokkal együtt). Lásd a szkript saját
`Get-Help .\install-windows.ps1 -Full` súgóját a további paraméterekért.
