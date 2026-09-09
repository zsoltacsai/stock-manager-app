# Fejlesztési terv — 1.1 és azon túl

Ez a lista olyan ötleteket tartalmaz, amik **jelenleg (1.0) nem érik meg**
a bevezetést — vagy mert egy másik komponens már lefedi a szükségletet,
vagy mert a projekt jelenlegi mérete/célközönsége mellett a
komplexitás/haszon arány rossz. Egy jövőbeli 1.1-es (vagy későbbi) körben
érdemes újra megnézni őket, ha a körülmények változnak.

## NAV Online Számla — production előtti nyitott döntési pont: számlaszám-generálás

**Ez a szakasz frissítve, mert a korábbi feltételezés ("csak akkor
kellene, ha a Számlázz.hu integráció megszűnne") azóta elavult**: a
közvetlen NAV Online Számla kiállítás Phase 5A/5B-ben ténylegesen
elkészült (`src/NavClient.php`, `src/NavInvoiceProvider.php`,
`src/NavInvoiceQueueWorker.php`) — valódi NAV sandbox környezetben,
teljes tokenExchange → manageInvoice → queryTransactionStatus
lánccal, tartós, race-safe, retry-képes queue-val bizonyítva.
`invoice_provider='nav'` a Beállításokban ma is bekapcsolható és
ténylegesen működik.

**Egyetlen, KIFEJEZETTEN production előtt eldöntendő, még NYITOTT
pont maradt**: a NAV felé beküldött `invoiceNumber` (a ténylegesen
kiállított számla jogi sorszáma, NEM egy belső/technikai azonosító —
lásd `src/NavInvoiceXmlBuilder.php` és a NAV invoiceData.xsd
`invoiceNumber` mezője) jelenleg a
`Database::insertQueuedInvoice()`-ban `SM-NAV-{év}-{id}` formában
képződik, ahol `{id}` az `invoices` tábla saját, AUTO_INCREMENT
oszlopa — ez az id-szekvencia a Számlázz.hu-s sorokkal (provider=
`szamlazz`) OSZTOTT, tehát a NAV-számlák sorszáma nem garantáltan
folytonos/gapless, ha közben Számlázz.hu-s sor is beszúrásra kerül.

**Pontos kódnyomvonal** (ellenőrizhető, file:line hivatkozásokkal):

1. **Hol képződik**: `Database::insertQueuedInvoice()`
   (`src/Database.php:1513`) — `$invoiceNumber = sprintf('SM-NAV-%s-%06d',
   date('Y'), $id);` — KÖZVETLENÜL az `invoices` sor sikeres `INSERT`-je
   UTÁN, a friss auto-increment `$id`-ból, MÉG A NAV-HÍVÁS ELŐTT (a queue
   worker csak ezután, később küldi be a `manageInvoice`-ot).
2. **Milyen mezőbe kerül**: ugyanott egy közvetlen `UPDATE invoices SET
   invoice_number = ? WHERE id = ?` írja a saját `invoices.invoice_number`
   oszlopba (`src/Database.php:1514` körül).
3. **Hol kerül bele a NAV requestbe**: `NavInvoiceXmlBuilder::build()`
   (`src/NavInvoiceXmlBuilder.php:68`) — `$xw->writeElement('invoiceNumber',
   (string) $params['invoice_number']);` — ez az `invoices.invoice_number`
   értéke kerül szó szerint a `manageInvoice` kéréshez csatolt
   `invoiceData` XML `<invoiceNumber>` elemébe (`NavInvoiceProvider::submit()`
   adja át `$invoiceRow['invoice_number']`-ként).
4. **Milyen adatbázis ID-ból származik**: az `invoices` tábla SAJÁT,
   provider-független `id` oszlopából (NEM a `sales.id`-ból, NEM egy
   NAV-only sorszámlálóból) — ez a lényegi, még eldöntendő pont.
5. **Hogyan különül el a `provider_ref`/transactionId-tól**: teljesen
   külön oszlop, külön életciklus. `invoice_number` **egyszer**, a
   queue-ba kerüléskor (`insertQueuedInvoice()`-ban) képződik, MÉG A NAV
   MEGKERESÉSE ELŐTT. `provider_ref` ezzel szemben **csak sikeres
   `manageInvoice` UTÁN**, a NAV válaszából származó `transactionId`-val
   töltődik ki, `Database::markInvoiceSubmitted()`-ben
   (`src/Database.php:1618`, `SET ... provider_ref = ?`) — ez a NAV
   beküldés technikai nyomon-követő azonosítója, SOSE számlaszám. A
   "Kimenő számlák" UI részletnézete (`webroot/kimeno-szamlak.js`)
   emiatt explicit külön címkével jeleníti meg: `invoice_number` mint
   "Számlaszám", `provider_ref` mint **"NAV tranzakcióazonosító (NEM
   számlaszám...)"**.

Magyar ÁFA-törvényi elvárás a számlaszámozás folytonossága egy adott
számlázási "tartományon" belül — emiatt **production bevezetés előtt
külön meg kell vizsgálni és véglegesíteni** a NAV-only számlaszám-
tartomány kérdését (pl. egy saját, csak NAV-provider sorokra vonatkozó
sequence/counter bevezetése, vagy a könyvelővel egyeztetett más
numbering-konvenció). Ezt a döntést a projekt tulajdonosa és/vagy a
könyvelője hozza meg — technikai implementáció csak azután, hogy a
konkrét séma eldőlt.

**Trigger, ami miatt ezt production előtt véglegesen el KELL dönteni**:
mielőtt `invoice_provider='nav'` valódi, éles (nem teszt-rendszerű)
NAV-fiókkal, valódi vevőknek kiállított számlákra bekapcsolásra kerül.

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
nyitvatartásra), ami egy éttermi/vendéglátós profilú Stock Manager
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
