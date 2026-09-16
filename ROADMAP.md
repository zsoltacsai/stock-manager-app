# Fejlesztési terv — 1.1 és azon túl

Ez a lista olyan ötleteket tartalmaz, amik **jelenleg (1.0) nem érik meg**
a bevezetést — vagy mert egy másik komponens már lefedi a szükségletet,
vagy mert a projekt jelenlegi mérete/célközönsége mellett a
komplexitás/haszon arány rossz. Egy jövőbeli 1.1-es (vagy későbbi) körben
érdemes újra megnézni őket, ha a körülmények változnak.

## Dashboard, üzleti riportok, készlet-előrejelzés, WooCommerce szinkron-monitor — MEGOLDVA (1.2.0)

Feature release: a meglévő sales/purchases/inventory/invoice/WooCommerce
adatokból ad üzleti áttekintést, új adatmodell/külső integráció nélkül.
Lásd README "FountainTrade 1.2.0 — Dashboard és üzleti riportok" szakasza
a teljes technikai indoklásért:

- Dashboard főoldal KPI-kkal, egységes backend-authoritative időszakválasztóval.
- Forgalmi riport (napi/fizetésimód/Top termékek bontás, visszáru-nettósítás).
- Készlet riport (készletérték, alacsony/kifogyott lista, CSV export).
- Készletmozgás-riport a meglévő eladás/beszerzés/visszáru/leltár adatokból
  — nincs párhuzamos stock ledger.
- Egyszerű, hamis pontosság nélküli készlet-előrejelzés.
- WooCommerce szinkron-monitor admin-jogszintű kézi újrapróbálkozással.

## Beszerzés-idempotencia, WooCommerce-push megbízhatóság, ár-validáció, import-keményítés, frontend hibakezelés — MEGOLDVA (1.1.1)

Öt, egymástól független stabilitási/megbízhatósági javítás egy
maintenance release-ben — nem új üzleti funkció, hanem a napi használat
alatt már bizonyítottan előforduló hézagok zárása. Lásd README
"FountainTrade 1.1.1 — stabilitási és megbízhatósági javítások" szakasza
a teljes technikai indoklásért:

- Beszerzés-rögzítés idempotencia-védelme (a sale-nél már bevált minta,
  most a beszerzésre is kiterjesztve — valódi 16-folyamatos
  konkurrencia-teszttel bizonyítva).
- WooCommerce készlet-push aszinkron queue-ra állítva (a NAV queue
  mintáját követve) — egy lassú/elérhetetlen WooCommerce-szerver többé
  nem lassítja a kasszát/beszerzést/leltárt.
- Központi, minden ár-írási útvonalon (kézi szerkesztés, CSV/JutaSoft
  import, WooCommerce-behúzás) érvényesített ár-validáció.
- Import: soronkénti (nem all-or-nothing) hibakezelés hibás áraknál +
  valódi JutaSoft export-fixture regresszió + árva ideiglenes fájlok
  automatikus seprése.
- 18 lista-betöltő frontend oldal + a backend globális hibakezelője —
  egy HTTP-hiba többé nem jelenik meg csendben üres listaként/nyers PHP
  hibaként.

## NAV Online Számla — számlaszám-generálás — MEGOLDVA (1.1.0)

**Ez a korábban nyitott döntési pont a FountainTrade 1.1.0 MODIFY/STORNO
előkészítő körében (schema/numbering réteg) lezárult.** A korábbi,
`invoices.id`-ból képzett ("SM-NAV-{év}-{id}") számlázás megszűnt — lásd
a README "Számla-műveletek adatmodell (1.1.0)" szakaszát a teljes,
jelenlegi tervezésért: `Database::allocateInvoiceNumber()` egy KÜLÖN,
provider-kulcsolt, atomikusan növelt `invoice_sequences` táblából
allokál, ami a Számlázz.hu-s sorok beszúrásaitól TELJESEN FÜGGETLEN. A
meglévő (1.0.x-ben kiállított) számlaszámok VÁLTOZATLANOK maradtak — a
migráció nem generálta újra őket (lásd `Database::migrateV22InvoiceOperationsBody()`
docblockja) —, az ÚJ sorozat a migráció idején a legmagasabb korábbi NAV
`invoices.id` fölött indult, hogy a régi és az új számok sose
keveredhessenek/ütközhessenek.

## Számla MÓDOSÍTÁS és SZTORNÓ (NAV + Számlázz.hu) — MEGOLDVA (1.1.0)

**A 2026-09-09-i specifikáció (korábban "Phase 8", 1.1-re halasztva)
implementálva és VALÓDI NAV sandbox lánccal (CREATE→MODIFY→STORNO,
mindhárom DONE) igazolva.** A teljes tervezés/implementáció a README
"Számla-műveletek adatmodell — MODIFY/STORNO előkészítés (1.1.0)" és
"MODIFY/STORNO — tényleges NAV/Számlázz.hu beküldés (1.1.0)" szakaszaiban
dokumentált. Röviden, a korábbi specifikáció pontjaihoz igazítva:

- **Architektúra**: a meglévő `InvoiceService`/`InvoiceProviderInterface`
  rétegen keresztül, `NavInvoiceProvider`/`SzamlazzInvoiceProvider`
  bővítve — NEM párhuzamos rendszer.
- **NAV MODIFY/STORNO**: `manageInvoice` `invoiceOperation`
  CREATE/MODIFY/STORNO envelope + `invoiceReference`/
  `lineModificationReference` XML-blokkok — HÁROM, kizárólag valódi NAV
  sandbox hívással feltárható kötelező részlettel (lásd README), amiket
  a séma-dokumentáció önmagában NEM tárt fel.
- **Adatmodell**: `invoice_type` (normal/modification/storno) a `status`
  (technikai életciklus) mezőtől elválasztva — lásd az előző ROADMAP-
  szakasz + README.
- **Idempotencia**: `operation_key` (attempt-kulcsolt MODIFY-hoz,
  determinisztikus STORNO-hoz) — valódi, 16-folyamatos konkurrencia-
  teszttel igazolva mindkét esetre.
- **Jogosultság**: `require_admin()` + CSRF minden állapotváltó
  végponton (`invoice-modify.php`, `invoice-storno.php`,
  `szamlazz-operation-retry.php`).
- **UI**: backend-vezérelt gomb-láthatóság (a frontend sose dönt
  pénzügyi jogosultságról), megerősítő dialógus, kapcsolódó számla-lánc
  navigáció.
- **NAV uncertain-recovery**: változatlan, generikus mechanizmus —
  sose esik vissza implicit CREATE-re (a `checkStatus()`/
  `recoverUncertain()` `provider_ref`/`invoice_number` alapján dönt,
  `invoice_type`-tól függetlenül).
- **Tesztelés**: valódi NAV sandbox lánc, ~90 új/bővített teszt, 2 új
  valódi 16-folyamatos konkurrencia-teszt, 3x tiszta regresszió.

## NAV Online Számla — Beérkezett számlák: ismert korlátok / jövőbeli bővítés

A bejövő (más adózók által kiállított) számlák NAV-szinkronja
(`src/NavIncomingInvoiceSync.php`, `src/NavIncomingInvoiceSyncWorker.php`,
"Beérkezett számlák" nézet) elkészült — lásd README "NAV Online Számla
— Beérkezett számlák" szakaszát. Két, SZÁNDÉKOSAN e körön kívül hagyott
pont maradt jövőbeli bővítésre:

1. **Többszintű módosítási lánc**: a jelenlegi implementáció a digest-
   szintű `originalInvoiceNumber`/`modificationIndex` mezőkből épít
   egy-egy közvetlen (egy-szintű) hivatkozást az eredeti számlára — ha
   egy számlát TÖBBSZÖR módosítanak (A → módosítja B → módosítja C), a
   UI-ban mindegyik a SAJÁT `originalInvoiceNumber`-ére mutat, de nincs
   összesített "teljes lánc" nézet. A NAV `queryInvoiceChainDigest`
   operációja pontosan erre való (a teljes módosítási lánc egy
   hívással lekérdezhető) — bevezetése egy jövőbeli körben indokolt, ha
   a gyakorlatban gyakoriak a 2+ szintű módosítási láncok.
2. **Production PHP környezet TLS/CA-bundle ellenőrzése**: a Phase 6
   valódi NAV sandbox tesztje során kiderült, hogy ennek a
   fejlesztői gépnek a különálló PHP 8.3 telepítése (`C:\tools\php83`)
   NEM rendelkezik alapértelmezett `curl.cainfo`/`openssl.cafile`
   beállítással, emiatt MINDEN kimenő HTTPS-hívás (NAV, WooCommerce,
   Dropbox/Google Drive stb.) `self-signed certificate in certificate
   chain` hibával elbukik, amíg valaki explicit CA-bundle-t nem állít
   be. Ez a projekt kódját NEM érinti (a `NavClient`/`WooCommerceClient`
   a rendszer alapértelmezett CA-tárolójára támaszkodik, ahogy minden
   PHP curl-alapú kliensnek kellene) — de **production telepítés előtt
   ellenőrizni kell, hogy a célszerver PHP-jának van-e működő CA-tára**
   (a legtöbb csomagolt PHP-disztribúció — pl. XAMPP, Docker hivatalos
   image-ek — alapból rendelkezik ilyennel, ez a hiányosság ennek az
   egy fejlesztői gépnek egy speciális, standalone telepítéséhez
   kötődött).

## Többdevizás támogatás

Az egész adatmodell (termékek, eladások, beszerzések) `currency` mezőt
visz magával, de a UI és a riportok (napi zárás, dashboard) mind
HUF-ban összesítenek, árfolyam-kezelés nincs. Egy magyar kisboltnak/
webshopnak ez ma nem releváns.
**Trigger, ami miatt érdemes lenne**: ha külföldi (pl. EUR-ban fizető)
webshop-vásárlók aránya jelentőssé válna, vagy a bolt nemzetközi
terjeszkedésbe kezdene.

## Kétfaktoros hitelesítés (2FA)

Jelenleg egy megosztott alkalmazás-jelszó (Beállítások → Biztonság) védi
az egész appot, plusz egy PIN-kód azonosítja, melyik dolgozó dolgozik a
kasszánál. Ekkora csapat (jellemzően 1-5 fő, egy fizikai helyszínen)
mellett ez arányos védelem — egy TOTP-alapú 2FA (pl. Google
Authenticator) jelentős UX-terhet adna a napi bejelentkezéshez képest
alacsony tényleges kockázatcsökkenésért.
**Trigger, ami miatt érdemes lenne**: ha az app távolról (nem a bolt
saját hálózatáról/IP-jéről) is elérhetővé válna rendszeresen, vagy ha a
dolgozói létszám/telephelyek száma jelentősen nőne.

## POS bankkártya-terminál integráció (pl. PAX A920Pro)

Jelenleg a kasszás fizetés rögzítése tisztán informatív: a fizetési mód
(Készpénz/Bankkártya/stb.) egy legördülőből választható
(`payment_methods` beállítás), de az összeg a terminálon MANUÁLISAN
kerül beütésre, a kassza és a terminál között nincs adatkapcsolat. A cél
egy ún. "fél-integrált" (semi-integrated) kapcsolat lenne, ahol a kassza
automatikusan átküldi a fizetendő összeget a terminálnak, és visszakapja
a tranzakció eredményét (sikeres/sikertelen, bizonylat-adatok) — így nem
kell kétszer (kasszában és terminálon) begépelni az összeget, és nem
fordulhat elő elgépelésből adódó eltérés.

Ez a meglévő hálózati hőnyomtató-integrációhoz (Beállítások → Nyomtató,
IP-cím alapú, ESC/POS parancsokkal) hasonló architektúrájú lehetne
(szerver-oldali, a terminál IP-jére/portjára küldött parancsok), DE
lényegesen nagyobb munka és kockázat, mert:
- a pontos protokoll **terminál- és/vagy kártyaelfogadó
  (bank/pénzügyi szolgáltató, pl. OTP SimplePay, K&H, Erste, Adyon,
  Nayax stb.) függő** — nincs egységes, minden PAX-terminálra érvényes
  nyílt protokoll, a konkrét integrációhoz az adott elfogadó/terminál-
  forgalmazó SDK-ja vagy protokoll-leírása kell (gyakran csak
  regisztrált fejlesztői partnerként érhető el, NDA/tanúsítási
  folyamattal járhat);
- a tranzakció-kezelés hibalehetőségei (időtúllépés, megszakított
  kapcsolat, részleges visszaigazolás) pénzügyi következménnyel járnak,
  tehát ez egy jóval óvatosabb, alaposabb teszteléssel bevezetendő
  funkció lenne, mint egy nyomtató-parancs;
- valószínűleg terminál-/elfogadó-specifikus modulokat igényelne (lásd
  alábbi "Moduláris/plugin-alapú bővíthetőség" pont), mert egy adott
  bolt csak egyfajta terminált/elfogadót használ, nem mindet egyszerre.

**Trigger, ami miatt érdemes lenne**: ha egy konkrét ügyfél/bolt
ténylegesen rendelkezik egy adott terminál-típussal ÉS annak
elfogadójától elérhető a szükséges integrációs SDK/protokoll-leírás —
enélkül ez csak elméleti terv maradna, mert a tényleges megvalósítás
terminál-specifikus.

## Moduláris/plugin-alapú bővíthetőség

Jelenleg minden integráció (WooCommerce, Számlázz.hu, NAV, Dropbox,
Google Drive) közvetlenül be van építve a fő kódba (`src/*Client.php`
osztályok, `config/config.php` beállítások, `webroot/api/*.php`
végpontok) — nincs egy általános "modul/plugin" keretrendszer, aminek
csak be kellene dugni egy új integrációt kódmódosítás nélkül.

Egy tényleges plugin-architektúra (pl. egy `modules/` mappa, ahova
külön mappánként kerülne minden integráció saját config-sémával,
saját API-végpontokkal, saját UI-beli beállítás-fülekkel, amiket az app
automatikusan felfedez és betölt) jelentős architekturális átalakítás
lenne — nem egy funkció, hanem egy előfeltétel a KÉSŐBBI funkciókhoz
(pl. az alábbi Foodora-szinkronhoz vagy a fenti terminál-integrációhoz),
ha azokból többől is lesz, és nem éri meg mindegyiket egyedi, egyszeri
beépítésként kezelni.

**Trigger, ami miatt érdemes lenne**: ha 2-3 további külső
integráció (pl. Foodora + egy második ételrendelő platform + egy
második terminál-típus) ténylegesen napirendre kerülne — egyetlen új
integrációhoz (pl. csak Foodora) nem éri meg előbb egy egész
plugin-keretrendszert megépíteni, egyszerűbb azt is a meglévő
`*Client.php` mintára, közvetlenül beépíteni, ahogy a WooCommerce/
Számlázz.hu is készült.

## Egyéb API-szinkron — pl. Foodora (ételkiszállító platformok)

A meglévő WooCommerce-integráció mintája (kétirányú termék-/készlet-
szinkron, plusz a beérkező webshop-rendelések piszkozat-alapú,
jóváhagyás előtti feldolgozása — lásd "Beérkező eladások" szakasz)
átültethető lenne más értékesítési csatornákra is, pl. Foodora,
Wolt, Bolt Food — ezek mindegyike saját REST/webhook API-val rendelkezik
a beérkező rendelések fogadásához és a készlet/elérhetőség
frissítéséhez.

Ez technikailag a legközelebb áll egy már bejáratott mintához (a
`webshop-order-confirm.php`/`webshop-order-reject.php` piszkozat-
jóváhagyási folyamat gyakorlatilag változtatás nélkül újrahasználható
lenne más forrásból érkező rendelésekre is), DE minden platform saját
API-regisztrációt, hitelesítést és — jellemzően — saját
étlap-/termékkatalógus-szinkronizálási logikát igényel (pl. a Foodora
API-nak saját elvárásai vannak az étel-kategóriákra, allergén-adatokra,
nyitvatartásra), ami egy éttermi/vendéglátós profilú FountainTrade
telepítésnek releváns, egy bolt/kiskereskedés jellegűnek viszont nem.

**Trigger, ami miatt érdemes lenne**: ha egy konkrét vendéglátós
ügyfél ténylegesen használja/használná a Foodorát (vagy hasonló
platformot), és rendelkezésre áll a fejlesztői API-hozzáférés
(API-kulcs/partneri regisztráció) a teszteléshez.

## Egyéb, alacsonyabb prioritású ötletek

Ezek nem hangzottak el kifejezetten korábban, de a fentiek mellé
kívánkoznak — ha a projekt tovább nő, érdemes megfontolni:

- **Több HTTP-szintű (végpont) automatizált teszt üzleti folyamatokra** —
  a `tests/HttpSecurityTest.php` (biztonsági audit körben hozzáadva) már
  lefedi az auth/CSRF/cron/telepítő HTTP-szintű viselkedését, de a
  legtöbb `webroot/api/*.php` végpont üzleti logikája (pl. teljes
  beszerzés/leltár/visszáru folyamatok) még csak a `Database`-osztályon
  keresztül, közvetlenül van tesztelve, nem valódi HTTP-kérésen át.
- **Webhook-alapú (nem csak polling) értesítések** más csatornákra
  (pl. Slack/Discord) a beérkező rendelésekhez, a meglévő e-mail/webhook
  alacsony-készlet mintára építve.
- **Raktárkészlet-előrejelzés** a beszerzési javaslat mellé (pl. átlagos
  napi fogyás alapján "X nap múlva fogy ki" jelzés).
