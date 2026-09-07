# Changelog

Ez a fájl a Stock Manager verzióinak fontosabb változásait követi. A
formátum lazán a [Keep a Changelog](https://keepachangelog.com/) elvét
követi.

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
