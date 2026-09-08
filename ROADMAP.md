# Fejlesztési terv — 1.1 és azon túl

Ez a lista olyan ötleteket tartalmaz, amik **jelenleg (1.0) nem érik meg**
a bevezetést — vagy mert egy másik komponens már lefedi a szükségletet,
vagy mert a projekt jelenlegi mérete/célközönsége mellett a
komplexitás/haszon arány rossz. Egy jövőbeli 1.1-es (vagy későbbi) körben
érdemes újra megnézni őket, ha a körülmények változnak.

## NAV valós idejű számla-adatszolgáltatás

Magyarországon minden számlát valós időben jelenteni kell a NAV Online
Számla rendszerébe. **Ezt jelenleg a Számlázz.hu már elvégzi automatikusan**
minden általa kiállított számlánál — a Stock Manager csak a Számlázz.hu
Számla Agent API-ját hívja, a NAV-jelentés a Számlázz.hu oldalán történik.

Ha valaha a Számlázz.hu integráció helyett (vagy mellett) közvetlen NAV
Online Számla kiállítás kellene (pl. saját számlázó motorral, Számlázz.hu
nélkül), akkor kellene idehozni a NAV Online Számla API v3 XML-alapú
`manageInvoice` végpontját — ez jelentős munka (XML aláírás, batch
feldolgozás, hibakezelés a NAV oldali validációs hibákra).
**Trigger, ami miatt érdemes lenne**: ha a Számlázz.hu integráció
megszűnne, vagy egy ügyfél kifejezetten a Számlázz.hu-tól független
számlázást kérne.

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
