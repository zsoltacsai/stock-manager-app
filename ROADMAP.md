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

## Egyéb, alacsonyabb prioritású ötletek

Ezek nem hangzottak el kifejezetten korábban, de a fentiek mellé
kívánkoznak — ha a projekt tovább nő, érdemes megfontolni:

- **HTTP-szintű (végpont) automatizált tesztek** — a mostani PHPUnit-kör
  csak a `Database`/`GeoBlocker`/`SimpleXlsWriter` osztályokat fedi,
  a `webroot/api/*.php` végpontokat (auth, validáció, HTTP-válaszkódok)
  nem.
- **Webhook-alapú (nem csak polling) értesítések** más csatornákra
  (pl. Slack/Discord) a beérkező rendelésekhez, a meglévő e-mail/webhook
  alacsony-készlet mintára építve.
- **Raktárkészlet-előrejelzés** a beszerzési javaslat mellé (pl. átlagos
  napi fogyás alapján "X nap múlva fogy ki" jelzés).
