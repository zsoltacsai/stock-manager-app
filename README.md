# Stock Manager — localhost vonalkód-kassza

**Verzió: 1.0 beta 9**

Egy önállóan üzemeltethető PHP alkalmazás egy kisbolt/webshop teljes napi
üzemeltetéséhez: USB vonalkódolvasós kassza, beszerzés és leltár, több
telephely és dolgozói jogszint, törzsvásárlói/hűségpont-rendszer, kuponok
és ajándékutalványok, Számlázz.hu számlázás, automatikus mentés, és
kétirányú WooCommerce-szinkron — beleértve a webáruházból beérkező
rendelések ellenőrzött, piszkozat-alapú feldolgozását is (lásd lent:
Beérkező eladások).

## Követelmények

- PHP 8.1+ (fejlesztve és tesztelve **PHP 8.5** ellen), `curl`,
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
2. Töltsd fel az onnan exportált **CSV**-t (ha csak .xls/.xlsx van,
   mentsd előbb CSV-ként Excelből vagy LibreOffice-ból — a szerver nem
   tudja közvetlenül feldolgozni a bináris Excel fájlokat).
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

Minden kassza-eladás meghívja a Számlázz.hu Számla Agent XML API-ját
(`src/SzamlazzClient.php`), hogy valódi számlát állítson ki, és letölti
a PDF-et az `invoices/` mappába. Ha a számla létrehozása sikertelen
(pl. hibás agent kulcs, hálózati akadozás), az eladás helyben ekkor is
rögzítésre kerül `invoice_failed` státusszal, hogy ne vesszen el a
tranzakció — a számla utólag manuálisan újra kiállítható a Számlázz.hu
felületén, az adatbázisban lévő eladási adatok alapján.

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
  hozzá speciális driver. Állítsd be az IP-t/portot/papírszélességet,
  és küldj egy teszt oldalt a Beállítások → Nyomtató alól.

**Érdemes tudni erről a korlátról**: az ESC/POS nyomtatóknak egy
nyomtató-specifikus kódlap-parancs kell az ékezetes karakterek helyes
megjelenítéséhez, ami modellenként/firmware-enként eltér. Ahelyett hogy
kockáztatná a torz szöveget, az `EscPosPrinter` mindent egyszerű
ASCII-re alakít nyomtatás előtt (á→a, ő→o, stb.) — az ékezetek elvesznek
a hálózaton nyomtatott nyugtán, de semmi nem jelenik meg halandzsaként.
A böngészős nyomtatásnak nincs ilyen korlátja, mivel az normál HTML/CSS
— használd ezt, ha számít az ékezetes szöveg a fizikai nyugtán.

A csak-USB (nem hálózati) hőnyomtatók közvetlenül nem támogatottak;
vagy állíts eléjük egy kis nyomtatószervert, vagy hagyatkozz a
böngészős nyomtatásra, ha az operációs rendszernek már van hozzá
drivere.

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

A `rendszerallapot.html` egy pillantásra összefogja mindazt, ami
egyébként szét van szórva a Beállítások fülein és a szinkron-naplóban:
az utolsó WooCommerce szinkron és mentés időpontjai/eredményei, az
alacsony készletű termékek és a legutóbbi számla-hibák száma, hogy a
nyomtató/Számlázz.hu/hűségprogram funkciók be vannak-e állítva, és a
szinkron-napló utolsó 20 bejegyzése (a hibák kiemelve).

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
visszaállításához. Bármelyik úton:

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

**Fontos**: ez a PHP beépített `mail()` függvényét használja — nincs
SMTP könyvtár, nincs külső függőség, összhangban az app többi részével.
De a `mail()` csak akkor működik, ha a szervernek van beállított
levelezés-továbbítója (sendmail/postfix, gyakori valódi megosztott
tárhelyen). Ez **nem** fog magától működni `php -S` helyi fejlesztésen
vagy a legtöbb friss VPS telepítésen, külön levelezés-beállítás nélkül
— a végpont egy egyértelmű hibát ad vissza, ami ezt elmagyarázza,
ahelyett hogy csendben elhasalna, amikor a `mail()` hibát jelez.

## Dolgozói jogszintek

A dolgozóknak mostantól van egy szerepköre (Eladó vagy Vezető), a
`staff.html`-en beállítva. Ez továbbra is elszámoltatási eszköz marad,
nem valódi hozzáférés-vezérlő rendszer — ahogy korábban is
dokumentálva, bárki megnyithatja a PIN-kérő ablakot, és választhat
másik nevet. Ami valódi: a **termék-törlés szerver-oldalon van
kikényszerítve** a `product-save.php`-ban — ha egy dolgozó be van
jelentkezve, és nem admin, a kérés elutasításra kerül (403),
függetlenül attól, mit mutat a felület. Ha egyáltalán nincs
bejelentkezett dolgozó (a PIN funkció nincs használva), ez megengedő
marad.

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

**Tervezési döntés**: saját QR-enkóder helyett egy külső, publikus
QR-generáló képszolgáltatást (`api.qrserver.com`) használ. Egy hibás saját
implementáció (a QR hibajavítás/maszkolás sokkal összetettebb, mint az
EAN-13 vonalkód kódtáblája volt) egy nem-olvasható kódot eredményezhetne —
ez rosszabb, mint egyáltalán nem mutatni QR-kódot. Ha nincs internet a
nyugta megnyitásakor, a kép csendben eltűnik, nem dob hibát.

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

### Bejelentkezés (opcionális, kikapcsolható)

Beállítások → Biztonság fülön kapcsolható be egy alkalmazás-szintű jelszó
(alapból ki van kapcsolva — bekapcsolása után minden oldal bejelentkezést
kér). Ez **különbözik a dolgozói PIN-kódtól**: az csak elszámoltat (ki
dolgozott a Kasszánál), ez itt a teljes programhoz való hozzáférést zárja.

- Jelszó `password_hash()`-sel tárolva, sosem kerül vissza a kliensnek
  (a `settings.php` és minden más végpont explicit módon kiszűri).
- Session-cookie `HttpOnly` + `SameSite=Strict` — ez jelentősen csökkenti
  a CSRF-kockázatot anélkül, hogy minden POST-kérésbe tokent kellene
  fűzni. (Egy `Auth::csrfToken()`/`verifyCsrf()` pár is elérhető jövőbeli,
  token-alapú védelemhez, ha valaha szükség lenne rá.)
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

### Ami nem ebben a körben lett megoldva

Egy teljes, token-alapú CSRF-védelem (minden POST-kérésbe fűzött egyedi
token) nem került bevezetésre — ehelyett a `SameSite=Strict`
session-cookie adja a gyakorlati védelmet, mivel több tucat meglévő
API-hívás módosítása jelentős kockázattal járt volna egy ilyen nagy
kódbázisban. Az `Auth` osztály tartalmazza a szükséges építőelemeket
(`csrfToken()`, `verifyCsrf()`), ha valaha szükség lenne rá.
