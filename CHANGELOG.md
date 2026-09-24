# Changelog

Ez a fájl a FountainTrade verzióinak fontosabb változásait követi. A
formátum lazán a [Keep a Changelog](https://keepachangelog.com/) elvét
követi.

## [Unreleased] — AI Copilot: javaslatok, végrehajtás, streaming és éles-üzemi validáció (Fázis 8A/8B/9/10/11)

**Az 1.0 RC feature freeze alóli, egyenként jóváhagyott kivételek —
NINCS verziószám-emelés ehhez a szakaszhoz**, az öt kör (8A/8B/9/10/11)
kódja már a `main`-en van, de formális kiadásként (release/tag) még
nem lett elnevezve. Lásd README "AI Asszisztens" szakasza (Fázis 6-11
alszakaszok) a teljes technikai indoklásért.

### Added — Fázis 8A: AI Action Proposals (javaslat + emberi jóváhagyás)

- A Napi Intelligencia MOST — a MEGLÉVŐ `AnomalyDetector`-találatokból,
  saját anomália-logika nélkül — konkrét, jóváhagyásra váró
  `ActionProposal` sorokat is generálhat (pl. `reorder_draft`), NEM csak
  szöveges összefoglalót.
- Új `action_proposals` tábla + `ai-proposal-list.php`/
  `ai-proposal-detail.php`/`ai-proposal-approve.php`/
  `ai-proposal-reject.php` végpontok, admin-only, CSRF-védett.
- "Javaslatok" fül az AI Asszisztens oldalon — a jóváhagyás/elutasítás
  ÖNMAGÁBAN SOSE hajt végre üzleti műveletet, kizárólag a javaslat
  állapotát változtatja.

### Added — Fázis 8B: validált AI-művelet-végrehajtás

- Jóváhagyott `reorder_draft` javaslat mostantól ténylegesen
  VÉGREHAJTHATÓ — új, minimális `purchase_order_drafts` tábla (NEM a
  meglévő `purchases`, ami már beérkezett készletet ír le, lásd README
  "Miért nem a meglévő `purchases` tábla?").
- `ActionExecutor` + `ExecutableActionStrategy` interfész +
  `ReorderDraftExecutor` — friss elavulás-ellenőrzés (`current_stock`
  egyezés) VÉGREHAJTÁS ELŐTT, atomikus állapot-claim
  (`executing`/`executed`/`execution_failed`), teljes audit.
- Egy elakadt (folyamat-összeomlás miatt `executing`-ben ragadt)
  végrehajtás egy időalapú ablak (alapértelmezett 30 perc) után
  újra-lefoglalható — valódi, több párhuzamos OS-folyamatos teszttel
  bizonyítva.

### Added — Fázis 9: Copilot UX, élő (streamelt) válaszok, kontextus-/költség-korlátok

- **Élő streamelés mind a négy agent-hez** egyetlen új SSE-végponton
  (`ai-agent-stream.php`) keresztül — Ollama (NDJSON), Anthropic (SSE),
  OpenAI (SSE) mindegyike a hivatalos dokumentáció alapján, egyetlen,
  szigorúan whitelistelt, provider-független eseményalakra (`AiStreamEvent`)
  fordítva. Automatikus, átlátszó visszaesés nem-streamelt módra, ha egy
  provider/beállítás nem támogatja.
- **Kontextus-korlátok és automatikus tömörítés** (`ConversationManager`)
  — determinisztikus, forduló-alapú FIFO-tömörítés hosszú
  beszélgetéseknél/nagy eszköz-eredményeknél, MINDIG érvényes JSON-t
  megőrizve.
- **Determinisztikus költség-/sebesség-korlátok** — eszköz-hívás-darabszám
  (`AgentRunner`-szinten) és opcionális dollár-alapú korlát (a Copilot
  ügynök-fan-out szintjén), plusz a MEGLÉVŐ audit-naplóra épülő
  kérés-közötti minimális várakozás (`AiRateLimiter`).
- **Őszinte (SOSE kitalált) árazás-becslés** (`AiPricing`) — jelenleg
  KIZÁRÓLAG a helyi (Ollama, $0) bejegyzés szerepel; Anthropic/OpenAI
  esetén a UI "nem ismert ehhez a modellhez" szöveget mutat, sose hamis
  számot.
- **Admin-konfigurálható modell-útválasztás** a Copilot összetett,
  több-ügynökös kérdéseihez (`*_model_complex` beállítások) — a böngésző
  sose választhat modellt.
- **`ClientProxy` streamelő relé** (`forwardStreaming()`) — a Kliens/
  Szerver architektúra streamelt válaszokat is valódi, darabonkénti
  progresszióval relézi (nem pufferelve), időzítés-alapú, valódi
  két-folyamatos teszttel bizonyítva; megszakítás-biztos mindkét hopon.
- AI Asszisztens oldal: élő agent-/eszköz-progressz, folyamatosan bővülő
  válaszszöveg, "Mégse" gomb, token-használat/becsült-költség sáv (a
  MEGLÉVŐ AI-előzményekbe/Dashboardba is felszínre hozva — séma-módosítás
  nélkül). Beállítások → AI asszisztens: teljes vezérlés a fentiekhez.

### Changed — Fázis 10: éles-üzemi validáció, benchmark, megbízhatóság

**Validáció/hardening kör, NEM új feature** — a Fázis 9-ben épített
infrastruktúra TÉNYLEGES, mért viselkedésének dokumentálása, és a menet
közben talált, valódi rések minimális javítása. Lásd README "Fázis 10"
alszakasz a teljes technikai indoklásért.

- **Provider-hiba-kategória végig a naplózásig/UI-ig** — új
  `AgentRunResult::$failureCategory`/`CopilotRunResult::$failureCategory`
  mező (időtúllépés/hitelesítés/rate-limit/stb. megkülönböztetve, nem
  csak egy általános hibaüzenet) — új "Hibakategória" sor az
  AI-előzményekben.
- **Egységesített audit-naplózás** — a streamelt és nem-streamelt
  végpontok korábbi, párhuzamos, duplikált naplózó-logikája megszűnt,
  mindkettő a közös `AiAuditLogger::logRun()`/`logCopilotRun()`-t hívja.
- **Pontosított HTTP-státusz → hiba-kategória leképezés** mindhárom
  Providerben (érvénytelen `ai_provider` konfiguráció most
  `configuration_error`, nem tévesen `unavailable`; `LocalProvider`
  megkülönbözteti a 4xx-et az 5xx-től).
- **Korlátozott (bounded) újrapróbálkozás** (`AiRetryPolicy`) átmeneti
  (`rate_limit`/`timeout`/`unavailable`) hibákra — legfeljebb 3 kísérlet,
  legfeljebb 2 másodperc összes várakozással, KIZÁRÓLAG a nem-streamelt
  útvonalon (streamelt válasznál egy csendes ismétlés megduplázná a már
  kiküldött tartalmat), strukturálisan elkülönítve az
  `ActionExecutor`-tól/üzleti mutációktól.
- **Két hivatalosan dokumentált, dátumozott árazási bejegyzés**
  (`AiPricing.php`) a ténylegesen konfigurált `claude-sonnet-5`/
  `gpt-6-sol` modellekhez (korábban csak a helyi Ollama $0 szerepelt).
- Új, újrafuttatható `tools/ai-benchmark.php` CLI-eszköz a 7 kanonikus
  munkateherhez (nem hamisít eredményt provider hiányában — egyértelműen
  "nem elérhető"-t jelez).

### Changed — Fázis 11: valódi felhő-provider (Anthropic/OpenAI) validáció

**Validáció kör, NEM új feature, kódmódosítás NEM történt** — cél az
`AnthropicProvider`/`OpenAiProvider` valódi API-kulccsal való
ellenőrzése, ha rendelkezésre áll. Lásd README "Fázis 11" alszakasz.

- Anthropic/OpenAI kulcs EBBEN a környezetben SEM állt rendelkezésre —
  `configured: nem, usable: nem` mindkettőre, "No Fake Green" elv szerint
  őszintén jelölve, nem szimulálva.
- Hivatalos dokumentáció újra-ellenőrizve: Anthropic Messages API és
  OpenAI Responses API protokollja, valamint a Fázis 10-es árazás
  VÁLTOZATLAN. Új, dokumentált (nem javított) ismert korlát: az OpenAI
  "long context" díjszabási sávja jelenleg NEM modellezett az
  `AiPricing.php`-ban.
- Biztonsági regresszió közvetlen forráskód-ellenőrzéssel ÚJRA
  megerősítve: provider-példányosítás kizárólag a factory-n/health-check
  osztályokon keresztül, `ActionExecutor` kizárólag a dedikált
  végrehajtás-végponton, retry strukturálisan nem érhet el
  eszköz-hívást/üzleti műveletet.
- **Tiszta (izolált, konkurens terhelés NÉLKÜLI) valódi Ollama-benchmark**
  megismételve a Fázis 10 konkurencia-torzításának kiküszöbölésével: 4/14
  munkaterhelés-futás sikeres, 10/14 időtúllépés — SZINTE UGYANOLYAN
  arányban, mint a Fázis 10 torzított mérése. Ez FELÜLVIZSGÁLJA a Fázis
  10 hipotézisét: a lassúság fő oka NEM (kizárólag) a konkurens
  CPU-terhelés, hanem hogy a gép a `qwen3:8b`-t kizárólag CPU-n futtatja
  (`size_vram: 0`), GPU-gyorsítás nélkül.

### Tests

- Fázis 10: `AiRetryPolicyTest` (9 teszt), `AiAuditLoggerTest` (5 teszt),
  plusz kiegészítések a meglévő Provider-/kontextus-/árazás-
  tesztkészletekben.
- Fázis 11: nincs új tesztfájl (valódi validáció, nem stub-bővítés) — a
  MEGLÉVŐ teljes tesztkészlet újra lefuttatva, izoláltan (nincs konkurens
  CPU-terhelés).
- Teljes regresszió a Fázis 11 végén: **1470/1470 teszt zöld (6450
  assertion, 2 kihagyott)** — torzítatlan, izolált mérés.
- Pester-suite (`tests/Install-WindowsTests.ps1`): 74/74 zöld, mindkét
  körben újra lefuttatva.

### Known limitations

- Anthropic/OpenAI VALÓDI API-kulccsal streamelve MÉG NINCS élesben
  ellenőrizve (Fázis 9/10/11 mindegyike kulcs hiányában dokumentálta ezt
  — csak kontrollált, dokumentáció-hű stub-szerverekkel bizonyított) — a
  helyi (Ollama) streamelés viszont valódi, futó példánnyal, élő
  böngésző-teszttel ÉS izolált benchmarkkal bizonyítottan helyesen
  működik (bár lassan, GPU-gyorsítás nélküli gépen).
- Az OpenAI "long context" díjszabási sávja NEM modellezett
  `AiPricing.php`-ban (Fázis 11-ben felfedezve, bizonyíték hiányában
  szándékosan nem implementálva).
- A Copilot jelenleg NEM tudatos a javaslatokról (nem listázza/magyarázza
  őket) — dokumentált, jövőbeli bővítési lehetőség.
- Nincs UI a `purchase_order_drafts` piszkozatok önálló böngészéséhez —
  csak a kiváltó javaslat részletnézetében jelenik meg.
- Nincs beszállítói/külső procurement-integráció — a piszkozat kézzel
  vihető át valódi beszerzéssé a meglévő felületen.

## [1.5.0] — 2026-09-22 (Kasszakezelés + Több-terminálos Kliens/Szerver architektúra)

**Két nagy terület: (1) kasszanyitás/kasszazárás/pénzmozgás egy adott
pénztárgéphez kötve, és (2) egy opcionális Kliens/Szerver üzemmód, ahol
egy Szerver gép tárolja az egyetlen adatbázist, és tetszőleges számú
Kliens-terminál (saját adatbázis NÉLKÜL) éri el ugyanazt a boltot a
hálózaton keresztül.** A Standalone (egygépes) üzemmód változatlan
alapértelmezett marad — a Kliens/Szerver mód teljesen opcionális, a
telepítő szerepkör-választásával kapcsolható be.

### Added — Kasszakezelés

- **Kasszanyitás/kasszazárás/pénzmozgás** — új `cash_registers`/
  `cash_sessions`/`cash_movements` táblák, pénztárgépenként legfeljebb egy
  nyitott műszak (atomikus `INSERT ... SELECT ... WHERE NOT EXISTS`
  nyitáskor, `UPDATE ... WHERE status='open'` zárásnál — utóbbi a bevált
  `completeStockTake()` minta közvetlen klónja). Valódi, párhuzamos
  OS-folyamatos (`proc_open`) tesztekkel bizonyítva mindkét irányban.
- **Várható készpénz képlet, kizárólag szerver-oldalon számolva**:
  nyitó + készpénzes eladások − készpénzes visszatérítések + pénzbevét −
  pénzkiadás. `Settings::payment_methods` minden bejegyzése kap egy új
  `is_cash` mezőt (alapból csak "Készpénz" = igaz), egy régi
  `settings.json` erre utólag, olvasáskor kerül feltöltésre.
- `sales`/`returns` táblák új `cash_session_id` oszlopa — mind az eladás,
  mind a visszatérítés a MŰVELET PILLANATÁBAN ténylegesen nyitott
  műszakhoz kötődik, egy korrelált al-lekérdezéssel, UGYANABBAN az
  INSERT-ben, ahol maga a sor létrejön — ez zárja ki azt a
  versenyhelyzetet, amiben egy korábban (akár csak töredék másodperccel
  előbb) lekért, azóta esetleg lezárt műszak-azonosító tévesen
  érvényesülne. Valódi, több egyidejű OS-folyamattal (eladás/visszáru
  vs. zárás, ugyanazon pénztárgépen) bizonyítva.
- Új oldalak: `penztargepek.php` (admin pénztárgép-kezelés), `kasszazaras.php`
  (zárás — teljes bontással: nyitó/készpénzes eladás/visszatérítés/be/ki/
  várható), `kassza-riport.php` (szűrhető lista + CSV export + nyomtatás).
- POS fejléc élő kassza-jelző (`Kassza: NYITVA/ZÁRVA`) + Pénzbevét/
  Pénzkiadás/Kasszazárás gyorsműveletek — nulla plusz HTTP-kérés
  (`locations-list.php` válaszába fűzve). Dashboard rendszerállapot-
  kártya kompakt kassza-sora ugyanezzel a fegyelemmel.
- Idempotencia-védelem kasszanyitásnál/pénzmozgásnál (a `sales.idempotency_key`
  bevált mintája) — dupla kattintás/hálózati újrapróbálkozás nem hoz létre
  duplikált műszakot/pénzmozgást.
- Új audit-log akciók: `cash_register_create/update`, `cash_session_open/close`,
  `cash_in`, `cash_out`.

### Added — Kliens/Szerver architektúra

- **`node_role`** (`standalone` | `server` | `client`) a
  `config/installer-generated.php`-ban — deploy-időben eldöntött,
  architekturális tény, nem futásidőben módosítható üzleti beállítás.
  Standalone (az alapértelmezett) viselkedése bit-azonos a korábbi
  verziókkal.
- **`ClientProxy`** — Kliens módban `webroot/api/_bootstrap.php` a
  `node_role` ellenőrzése után azonnal a Szerverre proxyzza a teljes
  kérést (byte-transzparensen, bináris válaszokat — pl. számla-PDF,
  mentés-letöltés — is beleértve), mielőtt bármilyen helyi
  `Settings`/`Auth`/adatbázis-logika lefutna. Egyetlen API-végpontnak
  sincs `if ($clientMode)` elágazása — a Kliens ugyanazt az API-t hívja,
  mint a helyi felület.
- **Két rétegű hitelesítés a Kliens→Szerver kérésen**: gép-szintű
  HMAC-SHA256 aláírás (`X-Client-Id`/`X-Client-Timestamp`/
  `X-Client-Signature`, ~120 másodperces replay-ablak, egyszer
  felhasználható nonce-tár), plusz egy Szerver-oldali `client_sessions`
  tábla, ami egy opak `client_session_id`-t egy ténylegesen bejelentkezett
  dolgozóhoz köt — a Szerver SOSE bízik egy Kliens által közvetlenül
  beküldött `staff_id`-ban.
- **Admin "Kliensek" oldal** — regisztráció (a `client_secret` KIZÁRÓLAG a
  regisztráció pillanatában jelenik meg, utána soha többé nem kérhető
  le), titok-csere (rotate), letiltás/engedélyezés, végleges visszavonás
  (revoke), utolsó látott időpont és (ha a Kliens jelentette) verzió.
- **CSRF-híd** — a Szerver a proxyzott, gép-hitelesített kérésekre nem a
  saját (Kliens-oldalon értelmezhetetlen) session-CSRF-tokent, hanem egy
  a `client_sessions` sorhoz kötött, külön tokent vár és ellenőriz.
- **Worker/cron-feladat szétválasztás** — mind a 6 háttérfolyamat
  (WooCommerce szinkron/push, NAV kimenő/bejövő, mentés,
  frissítés-ellenőrzés — a korábban hiányzó `wc-queue-run.php` bejegyzés
  is pótolva) kizárólag Szerver/Standalone módban kerül regisztrálásra a
  Windows Feladatütemezőben; Kliens módban a telepítő aktívan eltávolítja
  a korábbi worker-feladatokat, a Szerver-oldali backend-topológia-őr
  pedig függetlenül is elutasít bármilyen cron/worker-végpont-hívást egy
  Kliens node-on.
- **Hálózati kötés** — Szerver mód `0.0.0.0`-ra köt (DHCP-biztos, minden
  interfészre), Önálló és Kliens mód továbbra is kizárólag `localhost`-ra.
  Szerver módban a telepítő egy, kizárólag LocalSubnet-re korlátozott
  (SOSE "Any"/internet) Windows Firewall-szabályt hoz létre/frissít
  idempotensen.
- **`ClientServerHealth`** — a Kliens saját, a meglévő (Szerver-oldali,
  8-komponensű) `HealthMonitor`-tól teljesen elkülönülő kapcsolat-
  állapota (`server_reachable`, `api_reachable`, `authenticated`,
  `server_version`, `compatible`, `last_success`, `last_error`), 30
  másodperces, gép-helyi cache-eléssel (nem minden kérésnél pingel).
  Topbar státusz-jelvény + részletek-panel ("Szerver kapcsolódva"/"Szerver
  nem elérhető", Szerver URL/Verzió/API/Utolsó sikeres kapcsolat) — soha
  nem jelenít meg nyers kivételt, fájlrendszer-útvonalat, hitelesítő
  adatot vagy belső konfigurációt.
- **Verzió-kompatibilitás** — `AppVersion::isMajorMinorCompatible()`
  centrális, egyetlen forrás; csak major.minor számít, egy patch-szintű
  eltérés önmagában nem tiltó ok. Inkompatibilis Kliens minden proxyzott
  üzleti kérése egyértelmű, technikai részlet nélküli hibával (HTTP 409,
  `"A kliens frissítése szükséges."`) utasítódik el, MIELŐTT a kérés
  egyáltalán eljutna a Szerver üzleti logikájáig.
- **Multipart/form-data (fájlfeltöltés) proxyzás** — a Kliens a saját
  `$_POST`/`$_FILES`-ából újraépíti a kimenő multipart törzset, és egy
  tartalom-alapú (nem nyers `php://input`-alapú, ott ugyanis multipart
  esetén mindkét oldalon üres) HMAC-digestet számol, amit a Szerver
  ugyanígy, függetlenül reprodukál a hitelesítéshez — valódi bináris
  fájlfeltöltésekkel (logó, termékkép, biztonsági mentés, import-fájl)
  végponttól végpontig bizonyítva.
- Windows telepítő: szerepkör-választó képernyő (Önálló/Szerver/Kliens),
  a Kliens saját parancsikonja mindig a helyi Kliens Dashboardjára mutat
  (sose a konfigurált távoli Szerver-címre — nem kerüli meg a
  `ClientProxy`-t), a Kliens Client Secret bekérése `-AsSecureString`-gel
  (sose nyílt szövegként visszhangozva), HTTP figyelmeztetés, ha a
  megadott Szerver-cím nem HTTPS.

### Fixed — release-blocker javítások

- **Kasszaműszak-race, eladásnál ÉS visszárunál** — mindkét helyen egy
  korábban külön lekért, a tényleges DB-írás pillanatára potenciálisan
  elavuló nyitott-műszak-azonosítót adott át a hívó; mindkettő javítva egy
  a tényleges INSERT-tel egyidejű, korrelált al-lekérdezésre — lásd fent.
- **Feltöltött, titkosított biztonsági mentés visszaállítása** — a
  titkosítás-felismerés korábban a feltöltött fájl PHP által generált,
  soha nem `.enc`-re végződő ideiglenes fájlnevéből dőlt el, ezért egy
  feltöltött titkosított mentés sose fejtődött vissza helyesen. Mostantól
  kizárólag a fájl TARTALMA (egy fix formátum-jelző, illetve a SQLite
  fájlformátum saját aláírása) dönt — sose a fájlnévből/kiterjesztésből,
  és sose megbízhatatlan tartalom-találgatással.
- **Nyers kivétel-üzenet a mentés-visszaállítás hibaválaszában** — a
  `backup-restore.php` mostantól a projekt 1.3.1 óta bevált, egységes
  hibaüzenet-mintáját követi (`send_generic_error_response()`): a
  felhasználó csak egy általános hibaüzenetet kap, a technikai részlet
  kizárólag a belső rendszeresemény-naplóba kerül.

### Tests

- `tests/CashSessionTest.php`, `tests/CashSessionEndpointsHttpTest.php`,
  `tests/CashSessionSaleRaceTest.php`,
  `tests/CashSessionSaleRaceConcurrencyTest.php`,
  `tests/ReturnCashSessionRaceTest.php`,
  `tests/ReturnCashSessionRaceConcurrencyTest.php` — kasszakezelés +
  mindkét race-javítás, egységszinten és valódi többfolyamatos
  konkurrenciával is.
- `tests/ClientProxyTest.php`, `tests/ClientProxyHttpTest.php`,
  `tests/ClientAuthenticatorTest.php`, `tests/ClientHmacTest.php`,
  `tests/ClientNonceStoreTest.php`, `tests/ClientServerHealthTest.php`,
  `tests/ClientProxyVersionGateHttpTest.php`,
  `tests/ClientProxyMultipartHttpTest.php`,
  `tests/ClientLastSeenVersionHttpTest.php`,
  `tests/ClientWorkerGatingHttpTest.php` — a teljes Kliens/Szerver réteg,
  valódi HTTP-n, valódi két-folyamatos Kliens+Szerver felállással.
- `tests/BackupManagerTest.php` (bővítve), `tests/BackupRestoreHttpTest.php`
  — a titkosítás-felismerés és a hibaüzenet-javítás, valódi HTTP-
  feltöltéssel.
- Windows telepítő: `tests/Install-WindowsTests.ps1` (Pester) — 54 teszt,
  a szerepkör-választás, hálózati kötés és tűzfal-szabály döntési
  logikájával bővítve.

### Known limitations

- A hálózati (Ethernet/WiFi) hőnyomtató-integráció mindig a Szerver
  gépéről küld ESC/POS parancsot a nyomtató IP-jére — Kliens-terminálról
  nincs közvetlen nyomtató-elérés (a böngészős nyomtatás Kliens-oldalon
  is mindig működik, driver nélkül).
- Offline mód (Kliens működése Szerver-kapcsolat nélkül) továbbra sincs —
  ez tudatosan nem célja az 1.5.0-nak.
- A pénztárgép-választó a POS fejlécben csak akkor jelenik meg, ha egy
  telephelyhez több pénztárgép is tartozik.

## [1.4.1] — 2026-09-21 (Windows telepítő professzionalizálása)

**Kizárólag telepítési élmény/üzemeltethetőség/hibajavítás — nincs
alkalmazás-oldali üzleti funkció.** A teljes láncot (UAC → PHP → app →
Feladatütemező → szerver → háttérfeladatok → böngésző → Dashboard →
Windows-restart) valódi, éles felhasználói UAC-elfogadással, 3 egymást
követő telepítő-futással és egy tényleges géprestarttal bizonyítottuk —
nem csak szintaxis-ellenőrzéssel.

### Added

- **`FountainTrade-Setup.bat`** — minimális indítówrapper: OS/PowerShell-
  ellenőrzés, majd `install-windows.ps1` indítása (az UAC-emelést maga a
  PS1 végzi, tömb-alapú `-ArgumentList`-tel — lásd lent), a globális
  Execution Policy tartós módosítása nélkül. Nem tartalmaz titkot/
  tokent/API-kulcsot.
- **`install-windows.ps1` — teljes átdolgozás**: self-elevation, két
  automatikusan felismert üzemmód (friss ügyféltelepítés a legutóbbi
  publikált GitHub Release-ből, manifest+SHA-256+független commit-
  kereszt-ellenőrzéssel; vagy meglévő/fejlesztői mappa), automatikus PHP-
  telepítés hivatalos, ellenőrzött forrásból (`windows.php.net` saját
  `releases.json`-ja, SHA-256-tal), `C:\ProgramData\FountainTrade`
  alapértelmezett célkönyvtár explicit ACL-lel (nem-admin napi
  használathoz), automatikus cron-titkos-token-generálás, a telepítő
  varázsló helyes (token-nel ellátott) megnyitása, Dashboard-ra mutató
  parancsikonok, crash-esetén-újrainduló szerver-task, teljes
  transzkript-naplózás (`%TEMP%\FountainTrade-install-*.log`).
- **Rejtett-ablakos VBScript-indítówrapper** (`wscript.exe` +
  `WScript.Shell.Run(cmd, 0, True)`) mind a szerver-, mind a cron-
  taskokhoz — release-blocking UX-hiba megszüntetve (korábban a
  curl.exe/php.exe közvetlen Feladatütemező-akcióként felvillanó/
  tartósan nyitva maradó konzolablakokat okozott). Mellékesen a
  cron-titkos token is eltűnt a Feladatütemező saját, böngészhető
  Action-mezőjéből.
- **Valódi, több felbontású (16–256px) `fountaintrade-kassa.ico`** a
  Desktop/Start Menu parancsikonokhoz — a FountainTrade UI saját
  "Kassza" oldalsáv-motívuma (Feather Icons "shopping-cart", MIT
  licenc), a favicon.svg-vel azonos szín/stílus-nyelven, saját
  build-eszközzel (`tools/generate-kassza-icon.ps1`) generálva.
- **`install-windows-lib.ps1`** — a tisztán logikai, mellékhatás-mentes
  segédfüggvények külön fájlba emelve, egyszerű dot-source-olással
  tesztelve.
- **`tests/Install-WindowsTests.ps1`** — 28 Pester-teszt: GitHub-hoszt
  fehérlista, cron-token-generálás/-megőrzés/-véletlenszerűség/UTF-8-
  biztonság, Zip Slip-védelem, rejtett-ablakos indítás, Feladatütemező-
  visszaolvasás-ellenőrzés, .ico jelenlét/érvényesség/IconLocation.

### Fixed (mind ÉLŐ végrehajtással, nem csak syntax-check-kel felfedezve)

- `New-ScheduledTaskTrigger -Once (Get-Date) ...` hibás szintaxis volt
  (a `-Once` egy switch, az időpont a `-At` paraméterbe tartozik) — emiatt
  egyetlen cron-háttérfeladat sem jött volna létre soha.
- `-RepetitionDuration ([TimeSpan]::MaxValue)` a Feladatütemező saját XML-
  sémája szerint érvénytelen — javítva `(New-TimeSpan -Days 3650)`-re.
- A `Register-ScheduledTask`/`Set-ScheduledTask` CIM-hibája alapértelmezetten
  NEM terminating error — egy ténylegesen elutasított hívás után a szkript
  TÉVESEN "[OK]"-t írt volna ki. Minden Feladatütemező-hívás mostantól
  explicit `-ErrorAction Stop` + try/catch alatt fut, ÉS a regisztráció
  UTÁN visszaolvasva is ellenőrzött (nem elég a hívás sikeres visszatérése).
- A `git/ref/tags/` GitHub API-hívás a "v" előtaggal levágott
  verziószámmal indult (404), a tag EREDETI nevével kellett volna.
- **A self-elevation korábban MINDIG 0 (siker) kilépési kóddal tért
  vissza**, függetlenül az emelt telepítés tényleges kimenetelétől — ezt
  éles, kétszeri UAC-elfogadással fedeztük fel (a hívó ablak "csendben
  bezáródni" tűnt). Most a valódi gyerek-folyamat kilépési kódját
  propagálja.
- **[SÚLYOS, valódi adatvesztést okozó hiba] Kódlap-hiba a
  `settings.json` olvasásánál**: a `Get-Content -Raw` explicit
  `-Encoding` nélkül Windows PowerShell 5.1 alatt egy BOM nélküli
  fájlnál a RENDSZER ANSI kódlapját használta UTF-8 helyett (magyar
  Windows-on CP1250) — ez a felhasználó ÉLES `settings.json`-ját
  ténylegesen korrumpálta (fizetési módok, nyugta-szövegek,
  Számlázz.hu alap fizetési mód) az első valódi UAC-teszt közben.
  Minden `Get-Content`-hívás lecserélve
  `[System.IO.File]::ReadAllText(path, [System.Text.Encoding]::UTF8)`-ra.
  A sérült éles adat — a felhasználó jóváhagyásával, biztonsági
  másolat után — helyreállítva. Regressziós Pester-teszttel bizonyítva.
- **Kritikus UX**: a parancsikonok `IconLocation`-je egy `.svg`-re
  mutatott — a Windows Shell `.lnk`-formátuma ezt sosem támogatta, üres/
  alapértelmezett ikont eredményezve. Javítva valódi `.ico`-val, a
  hiányzó `WorkingDirectory` is pótolva.
- **Elavult parancsikon port-váltás után** nem frissült volna — most a
  meglévő parancsikon Target/Arguments/Icon mindegyike újraellenőrzött,
  csak akkor ír, ha ténylegesen eltér.

### Teszt-módszertani megfigyelés

Egy korábbi Pester-tesztelési módszer (AST-kinyerés + `Invoke-Expression`
egy futó szkriptből, plusz egy ténylegesen path-traversal-bejegyzést
tartalmazó ZIP-fixture) — a tartalomtól függetlenül — víruskereső
malware-heurisztikáját ütötte meg, és a TESZT FÁJLT karanténba
helyezte/törölte. A tényleges `install-windows.ps1` és az általa
generált `.vbs` fájlok NEM lettek érintve, és 3 valódi telepítő-futás +
egy restart alatt sem jelentkezett hasonló. Áttervezve: egyszerű
dot-source-olás + a Zip Slip-védelem tiszta stringeken tesztelve.

### Known limitations

- Teljesen tiszta (OOBE) Windows telepítés nem lett éles UAC-cal
  tesztelve ezen a körön — ez a gép már használt fejlesztői gép
  (dev-mode út); a GitHub-letöltés+ellenőrzés külön, valódi teszttel
  igazolt.
- A bundled-ikon *fallback*-másolási ága (ha egy letöltött GitHub
  Release régebbi az ikon bevezetésénél) kód-szinten/szintaxisban
  ellenőrzött, nem élesben, hiányzó-ikon-forgatókönyvvel lefuttatva.
- A rejtett-ablakos VBScript-technika elméletileg más (agresszívabb)
  antivirus-terméknél kiválthat riasztást — ezen a gépen 3 valódi
  telepítő-futás + restart alatt sem jelentkezett ilyen.
- A szkript egyetlen FountainTrade-példányra lett tervezve gépenként —
  a Feladatütemező-bejegyzés-nevek nincsenek célkönyvtár szerint
  névtér-elkülönítve.

## [1.4.0] — 2026-09-17 (Operations & Reliability — rendszerállapot és üzemeltetés)

**Üzemeltetési/megbízhatósági release — szándékosan NEM új üzleti funkció.**
A cél: a felhasználó/üzemeltető egy pillantással lássa "Rendben van a
rendszer?", és ha nem, "mi a baj?" és "mit tegyek?". Minden új felület a
MEGLÉVŐ architektúrára épül (lásd Ismert korlátok/döntések alul) — nem
készült párhuzamos naplózó/health-check rendszer.

### Added

- **Új `system_events` tábla és `Database::logSystemEvent()`/
  `getSystemEvents()`/`countRecentSystemEventsBySeverity()`** — kategória
  (backup/woocommerce/nav/updater/printer/smtp/auth/database),
  súlyosság (info/warning/error), állapot (started/success/failure),
  felhasználóbarát üzenet + KÜLÖN technikai részlet (utóbbi csak
  adminoknak látszik). Minden íráskor automatikusan takarít a
  konfigurálható megőrzési idő szerint (`system_events_retention_days`,
  alapértelmezés: 14 nap) — a napló NEM nőhet korlátlanul. Szándékosan
  KÜLÖN tábla az `audit_log`-tól (az egy ember-által-végzett-akció napló,
  nincs benne súlyosság/állapot/háttérfolyamat-fogalom) és a `sync_log`-tól
  (annak soha nem volt semmilyen takarítása, és csak a WooCommerce
  termékszinkronra korlátozódik) — lásd `migrateV26SystemEvents()`
  docblokkja a teljes indoklásért.
- **`HealthMonitor` domain-szolgáltatás** (`src/HealthMonitor.php`) — EGY
  közös, újrafelhasználható állapot-számítás (`computeComponentStatuses()`)
  minden komponensre (adatbázis, biztonsági mentés, WooCommerce, NAV,
  Számlázz.hu, SMTP, nyomtató, frissítő), öt állapottal: OK / WARNING /
  ERROR / NOT_CONFIGURED / UNKNOWN. Csak a ténylegesen beállított
  integrációk jelennek meg. A cron-alapú komponenseknél a "elavult" döntés
  az adott feladat SAJÁT, dokumentált gyakoriságához igazodik (nincs egy
  közös, globális időtúllépés), és figyelembe veszi, hogy egy tartósan
  hibázó, de rendszeresen lefutó cron a résztvevő végpontok "Hiba:"
  előtaggal jelzett összegzése alapján ISMERI FEL a hibát, nem csak a
  frissesség alapján (egy hibázó cron ugyanis a hibás lefutáskor is
  frissíti az időbélyeget). A rendszer SOHA nem fabrikál "következő
  futás" időpontot — a Feladatütemező tényleges ütemezését az alkalmazás
  nem ismeri.
- **`Rendszerállapot` oldal átdolgozva valódi üzemeltetési monitorrá**
  (`webroot/rendszerallapot.php`/`.js`): "Figyelmet igényel" kártya, új
  "Rendszer komponensek" lista (állapot-jelzés, utolsó ellenőrzés/siker,
  rövid hibaüzenet, várólista-összegzés WooCommerce/NAV-nál), új
  "Eseménynapló" kártya kategória/súlyosság szűrővel. A meglévő
  WooCommerce termékszinkron-napló (`sync_log`) megmaradt, külön,
  termék-részletező nézetként.
- **Dashboard "Rendszer állapota" kompakt kártya** — egy soros állapot
  komponensenként, kattintható link a Rendszerállapot oldalra. NEM okoz
  új HTTP-kérést a Dashboard megnyitásakor: a `dashboard-summary.php`
  már meglévő, egyetlen aggregált hívása adja vissza az adatot.
- **Manuális "Kapcsolat tesztelése" health-check** WooCommerce-hez
  (`wc-test-connection.php`, meglévő végpont kiegészítve eseménynaplózással)
  és NAV-hoz (új `nav-test-connection.php`, admin+CSRF védett, a meglévő
  `NavClient::testConnection()`-t hívja). Egyik teszt SEM módosít üzleti
  adatot, nem hoz létre számlát, nem küld valódi WooCommerce-rendelést.
  A Számlázz.hu-hoz SZÁNDÉKOSAN nincs kapcsolat-teszt gomb — a
  `SzamlazzClient` Agent API-jának nincs mellékhatás-mentes,
  dokumentum-létrehozás nélküli tesztművelete (lásd Ismert korlátok).
- **Várólista-összegzés a Rendszerállapoton** — "WooCommerce várólista: N
  függő / N sikertelen", "NAV várólista: N függő / N sikertelen", a
  meglévő `getWcQueueStatusSummary()`/`getInvoiceQueueStatusSummary()`
  forrásokból, kattintható linkkel a részletes nézetre.
- **Eseménynaplózás minden érintett háttérfolyamatnál**: biztonsági
  mentés (indul/kész/sikertelen/visszaállítás indul/kész/sikertelen),
  WooCommerce (szinkron indul/kész/sikertelen, várólista feldolgozva/
  elakadt tétel), NAV (kimenő várólista feldolgozva/számla feldolgozva/
  sikertelen, bejövő szinkron kész/sikertelen), frissítő (minden
  állapotváltás — ellenőrzés/letöltés/telepítés/migráció/health-check/
  kész/sikertelen/visszaállítás — `UpdateState::transitionTo()`-ból),
  nyomtató (csak SIKERTELEN automatikus nyugtanyomtatás — a sikeres
  nyomtatás szándékosan NINCS naplózva, hogy ne árassza el a naplót
  normál, nagy forgalmú működés mellett), SMTP-teszt, bejelentkezés
  (siker/sikertelen — IP-cím SOHA nem kerül naplózásra), kijelentkezés.
- **`install-windows.ps1`** — új, önálló Windows telepítő/beüzemelő
  szkript: PHP-verzió és kötelező kiterjesztések ellenőrzése, írható
  mappák létrehozása/ellenőrzése, a beépített PHP szerver és mind az öt
  automatikus háttérfeladat (WooCommerce szinkron, biztonsági mentés, NAV
  kimenő/bejövő, frissítés-ellenőrzés) Feladatütemező-bejegyzéseinek
  IDEMPOTENS létrehozása/frissítése, asztali parancsikon, valódi
  HTTP-alapú egészség-ellenőrzés a telepítés végén. A cron-token SOHA
  nincs beégetve a szkript forrásába — vagy paraméterként adható át, vagy
  a szkript a MÁR LÉTEZŐ `data\settings.json`-ból olvassa, egyébként
  figyelmeztetéssel kihagyja a cron-feladatok létrehozását.
- **`audit_log` lefedettségi rés pótolva** (a kör 12. pontjának
  ellenőrzése során talált, korábban NEM naplózott, valódi hiány):
  biztonsági beállítás-módosítás, admin beállítás-módosítás,
  dolgozó-felvétel/-szerkesztés, és beszerzés rögzítése mostantól
  `logAudit()`-ot ír — lásd README "`audit_log` vs. `system_events` — a
  határvonal" szakasza a teljes indoklásért (mit miért naplózunk most,
  és mit miért nem, mert az már máshonnan visszakövethető).

### Fixed

- **Frissítés-ellenőrzés false-positive "új verzió elérhető" jelzés** — a
  korábbi (ebben a körben bevezetett, majd még ugyanebben a körben,
  böngészős teszteléssel felfedezett) logika a tárolt
  `update_state.current_version` mezőt hasonlította a legutóbb ismert
  legújabb verzióhoz, de ez a DB-ben tárolt érték csak egy TÉNYLEGES
  önfrissítés lefutásakor frissül, és emiatt tetszőlegesen elavult
  lehet a ténylegesen futó kódhoz képest. A `HealthMonitor` mostantól
  mindig a ténylegesen futó kód verzióját (`AppVersion::CURRENT`) veti
  össze a legújabb ismert verzióval, sose a DB-gyorsítótárazott értéket.
- **WooCommerce/NAV komponens tévesen "Nincs beállítva" státuszt mutatott**,
  ha a hozzá tartozó automatikus cron ki volt kapcsolva, holott az
  integráció maga be volt állítva — összekeverve "nincs konfigurálva" és
  "az automatizmus szándékosan ki van kapcsolva" állapotokat. Mostantól a
  WooCommerce kikapcsolt automatizmusa OK-ként (ez egy legitim admin-
  döntés), a NAV kikapcsolt automatizmusa viszont FIGYELMEZTETÉSKÉNT
  jelenik meg (a README szerint dokumentált kockázat: a számlák
  feldolgozatlanul torlódnának).
- **`wc-test-connection.php` az UrlSafety-elutasítási ágon (pl. belső/
  loopback cím, vagy fel nem oldható host) NEM naplózta az eseményt** —
  ez az ág a try/catch ELŐTT tért vissza, így egy ilyen teszt-kudarc
  (a leggyakoribb valódi hiba egy hibásan beállított Áruház URL-nél)
  a felhasználó felé helyesen jelent meg, de az Eseménynaplóba SOSE
  került be. Élő, szándékosan kiváltott hiba-teszteléssel fedezve fel
  (a kör 20. pontja) — javítva, regressziós HTTP-teszttel bizonyítva.
- **A "Kapcsolat tesztelése" gomb 375px-es mobil nézetben átfedte a
  komponens nevét** — a komponens-sor `flex-wrap:nowrap` volt, emiatt a
  szöveges oszlop (WooCommerce/NAV neve, üzenet, státusz) egy ~71px
  széles, nagyon magas, sok sorra tördelt oszloppá zsugorodott a gomb
  mellett, ahelyett hogy a gomb saját sorra került volna. Élő, mind a 6
  megkövetelt töréspontnál (1920/1440/1280/1024/768/375px) elvégzett
  reszponzív ellenőrzés során fedezve fel (a kör 15. pontja) — javítva
  `flex-wrap:wrap`-re váltással, a gomb mostantól saját sorra kerül
  keskeny képernyőn.
- **`nav-incoming-sync-run.php` egy valódi, korábban rejtett hibaállapot-
  jelzési inkonzisztenciát tartalmazott**: a sikertelen kimenetel
  összegzése nem a többi cron-végpont által következetesen használt
  "Hiba:" előtaggal kezdődött, emiatt ez a konkrét hibaállapot (a bejövő
  NAV-szinkron backoff-kimerülése) észrevétlen maradt volna az új
  health-monitor számára.

### Known limitations / tudatos döntések

- Nincs önálló, szó szerinti "Feladat | Utolsó futás | Eredmény"
  cron-monitor táblázat — a cron-gyakoriság/frissesség-információ a
  `HealthMonitor` egyes komponens-sorainak "utolsó ellenőrzés"/"utolsó
  siker" mezőibe olvasztva jelenik meg, szándékosan EGYETLEN
  állapot-forrásból (nem egy második, párhuzamos "mi a baj" logika).
- Számlázz.hu-hoz nincs "Kapcsolat tesztelése" gomb — a `SzamlazzClient`
  Agent API-jának nincs dokumentált, mellékhatás-mentes tesztművelete;
  minden metódusa valódi számlát hoz létre/módosít/sztornóz.
- A `Rendszerállapot` komponens-táblázat szándékosan NEM mutat
  "Következő futás" oszlopot — az alkalmazás nem ismeri a Windows
  Feladatütemező tényleges ütemezését, és egy fabrikált érték hamis
  biztonságérzetet adna.

## [1.3.1] — 2026-09-16 (Stabilizáció — audit és hibajavítás)

**Stabilitási release — NEM új funkciófejlesztés.** Teljes körű kód-,
adatintegritás-, security-, teljesítmény- és UX-audit alapján, a
ténylegesen feltárt és javított hibák. A meglévő működés szándékosan
NEM változott azokon a pontokon, ahol nem volt valódi hiba.

### Fixed

- **`getStockMovements()` — a `product_id` szűrő minden forrás-ágban
  (eladás/beszerzés/visszáru/leltár/telephely-mozgatás) érvénytelen SQL-t
  eredményezett** (a szűrő az `ORDER BY`/`LIMIT` UTÁN lett hozzáfűzve a
  WHERE-tag helyett) — emiatt a termék mini-dashboard "Készletmozgások"
  füle és a Készletmozgások riport termék-szűrője MINDIG SQL-hibával
  (500) bukott el. Mind az 5 forrás-ág javítva, regressziós teszttel.
- **Biztonsági mentés visszaállítása korrumpálhatta az élő adatbázis-
  kapcsolatot** — a `restoreFromFile()` a live SQLite fájlt nyers
  fájlmásolással írja felül, de a bootstrap által már megnyitott `$db`
  kapcsolat nem lett előtte lezárva/utána újranyitva (ugyanaz a
  hibaosztály, amit az updater 1.1.1-ben már megoldott a saját
  migráció-visszaállítási útján — ez az útvonal kimaradt belőle).
- **Leltár lezárása nem volt versenyhelyzet-biztos** — két, majdnem
  egyidejű lezárási kérés (dupla kattintás, hálózati újrapróbálkozás)
  mindkettő alkalmazhatta volna a leltári korrekciót, duplázva a
  stock_qty-eltérést. Atomikus `UPDATE ... WHERE completed_at IS NULL`
  védelem, valódi 12-folyamatos konkurrencia-teszttel bizonyítva.
- **WooCommerce webhook csendben elveszíthetett rendeléseket** — az
  `insertWebshopOrderDraft()` MINDEN adatbázis-hibát (nem csak a valódi
  `wc_order_id` UNIQUE-ütközést) "már ismert rendelésként" nyelt el; a
  webhook erre 200 OK-t adott a WooCommerce-nek, ami emiatt sose próbálta
  újraküldeni egy átmeneti (pl. lock-) hiba esetén ténylegesen elveszett
  rendelést. Mostantól csak a valódi UNIQUE-ütközés nyelődik el, minden
  más hiba 5xx-et ad (WooCommerce-újraküldést kiváltva).
- **Nyomtató-írás csonka lehetett észrevétlenül** — az ESC/POS
  socket-írás nem ellenőrizte a ténylegesen kiírt bájtok számát; egy
  lassú/túlterhelt hálózati nyomtatónál egy csonka nyugta is
  "sikeresként" térhetett vissza. Ciklusban írunk, időtúllépés/hiba
  esetén explicit kivétellel.
- **`security-settings-save.php` minden hívása nyers szövegben küldte
  vissza az ÖSSZES konfigurált titkot** (cron_secret, WooCommerce/
  Számlázz.hu/NAV/felhő hitelesítő adatok) — a `settings.php`-nál már
  meglévő maszkolás erre a végpontra korábban nem lett átvezetve. A
  maszkolás egyetlen közös helyre került (`Settings::maskSecretFields()`),
  mindkét végpont ezt használja.
- **7 infrastruktúra-szintű/érzékeny végpont require_admin() nélkül**
  (tevékenységnapló, vásárlók tömeges PII-exportja, telephely mentés,
  bolt-logó és nyomtatási logó feltöltése/törlése) — bármelyik
  bejelentkezett dolgozó (akár egy sima pénztáros is) elérte őket. Mind a
  7 admin-jogszinthez kötve, HTTP-szintű regressziós teszttel.
- Kisebb N+1 lekérdezés-minták: leltár indítása (per-termék INSERT egy
  `INSERT...SELECT` helyett), leltár lezárása (redundáns SELECT UPDATE
  után), és a WooCommerce teljes-katalógus behúzás (egyetlen, a teljes
  szinkron idejére az író-zárat lefoglaló tranzakció helyett 200-as
  csomagokban commit-olva — ez volt az egyetlen ténylegesen komoly
  (P0-osztályú) teljesítményprobléma: nagy katalógusnál blokkolhatta a
  kasszás eladást).
- `gift-card-detail.php` a teljes ajándékutalvány-listát töltötte be és
  PHP-ban szűrt egyetlen rekordra `id` alapján — direkt `findGiftCardById()`.
- SQLite/MySQL séma-eltérés: `sale_items`/`purchase_items` FK-jai
  eltérő `ON DELETE` viselkedéssel (MySQL: CASCADE, SQLite: RESTRICT) —
  most mindkét motor RESTRICT (a biztonságosabb alapértelmezés egy
  pénzügyi előzményhez, sose töröljön csendben tételeket).
- Holt kód eltávolítva (`isWebhookOrderProcessed()`/
  `markWebhookOrderProcessed()` — a webhook-dedup azóta a
  `webshop_orders.wc_order_id` UNIQUE indexére épül).
- README dokumentáció-javítás: a NAV számlaszám-generálás és a kimenő
  MODIFY/STORNO státusza tévesen "még nyitott"/"jövőbeli feladat"-ként
  volt leírva, holott mindkettő 1.1.0-ban lezárult; a GitHub-letöltés
  engedélyezett host-listája hiányos volt (`release-assets.githubusercontent.com`).
- Rebrand-maradványok eltávolítva (`[stock-manager]` log-előtag →
  `[fountaintrade]`, a service worker cache-neve).

### Ismert, szándékosan e körön kívül hagyott pontok

- **CSV/JutaSoft import + WooCommerce/NAV/Számlázz.hu/SMTP hibatűrés,
  security (auth/CSRF/XSS/SQLi/SSRF/Zip-Slip/updater-tamper), IDOR
  audit**: teljes körűen átvizsgálva, konkrét hibát NEM talált (a
  meglévő védelem — pl. `UrlSafety`, a Zip-extrakció szimlink-ellenőrzése,
  a `csv_safe()` formula-injekció elleni védelem — a tényleges kód
  nyomon követésével igazoltan helyesen működik).
- Több `webroot/api/*.php` végpont saját, lokális `catch` blokkban
  `$e->getMessage()`-t ad vissza a kliensnek 500 esetén (a globális,
  bootstrap-szintű generikus hibaüzenet helyett) — alacsony súlyú
  inkonzisztencia, a hibaüzenetek eddig vizsgálva sose tartalmaztak
  titkot/elérési utat, csak PHP/DB-szintű technikai szöveget. Nem
  javítva ebben a körben (sok, egyenként triviális helyet érintene, a
  kockázat/haszon alacsony egy stabilizációs körben) — jövőbeli
  körben érdemes egységesíteni.
- A VAT/nettó↔bruttó átváltás számítása 11+ helyen, egymástól
  függetlenül van megírva (nincs egy közös `VatCalculator` segédosztály)
  — valódi duplikáció, de a kiváltásuk (11+ hívási hely, finom
  kerekítési eltérésekkel) érdemi regressziós kockázatot hordozna egy
  stabilizációs release-ben csak a de-riskelés kedvéért. Dokumentált,
  jövőbeli refaktor-jelölt.
- `returns.credit_invoice_number` oszlop és a hozzá tartozó (holt)
  `setReturnCreditInvoice()` — egy korábbi kör befejezetlenül hagyott
  funkciója (az oszlopot semmi nem tölti ki élesben). Nem törölve (a
  törlés migrációt igényelne, ami önmagában nem indokolt egy
  stabilizációs körben) — vagy be kell kötni egy jövőbeli körben, vagy
  formálisan törölni.
- SQLite/MySQL kolláció-eltérés (`utf8mb4_unicode_ci` vs. alapértelmezett
  BINARY) a legtöbb UNIQUE szöveges oszlopnál — a gyakorlatban érintett
  esetek (kupon-/ajándékutalvány-kód) már alkalmazás-szinten
  `strtoupper()`-elve vannak, a maradék kitettség (pl. `products.barcode`)
  alacsony kockázatú. Nem módosítva.
- Migráció-lánccal (V1→V25) felépített, ÉS friss `schema.sql`-lel
  telepített adatbázis séma-egyezőségét ma csak részben fedi teszt
  (`SchemaDriftTest.php` a két FRISS-telepítési fájlt hasonlítja össze,
  nem a migráció-lánc végeredményét a frissel) — ismert tesztlefedettségi
  rés, konkrét eltérés NEM került elő a mintavételes ellenőrzés során.

### Database

- Nincs új tábla, nincs új oszlop, nincs migráció.
  `Database::SCHEMA_VERSION` változatlanul 25.

### Kiegészítés — FINAL RELEASE GATE audit (második hullám, a `v1.3.1` tag UTÁN)

**Fontos**: a `v1.3.1` git tag a `6192981` commitra mutat — az alábbi
javítások EZUTÁN, egy formális release-gate ellenőrzés során kerültek
elő, tehát egy KÉSŐBBI commitban vannak, amit a `v1.3.1` tag NEM fed le.
Lásd a release-gate végső riportot a pontos indoklásért, hogy miért nem
maradhatott a `v1.3.1` tag változatlanul a tényleges kiadási állapotra.

- **SQL injection formális audit — LEZÁRVA**: a teljes kódbázis (`src/Database.php`
  ~250+ query helye, minden `webroot/api/*.php`, import-réteg, migrációk)
  átvizsgálva — nem került elő kihasználható SQL injection. Lásd a
  release-gate riport "SQL Injection" szakaszát.
- **Nyers kivétel-üzenetek formális felülvizsgálata**: a korábbi kör
  "kb. 8 endpoint, sose tartalmazott titkot/elérési utat" állítása
  RÉSZBEN pontatlan volt — a formális audit ténylegesen reprodukálható
  SQL/séma-töredék szivárgást talált (pl. "UNIQUE constraint failed:
  coupons.code" egy duplikált kuponkódnál) és lehetséges fájlrendszer-
  elérési út szivárgást a mentés-visszaállítás hibaágán. A legszélesebb
  elérésű és legkönnyebben reprodukálható helyeken (`sale.php`,
  `webshop-order-confirm.php`, `coupon-save.php`, `gift-card-save.php`,
  `purchase-save.php`, `import-commit.php`, `stock-transfer.php`,
  `stock-take-complete.php`, `return-create.php`, `sync-pull.php`,
  `auto-sync-run.php`) javítva — a technikai részlet mostantól csak a
  szerver naplójába kerül, a kliens egy generikus üzenetet kap. A
  VALÓDI, hasznos üzleti hibaüzenetek (pl. "Ez a leltár már le van
  zárva.", "...tételből időközben már csak N db vihető vissza.")
  TUDATOSAN megmaradtak — ezek nem technikai kivétel-részletek, hanem
  a `Database.php` saját, kézzel írt, biztonságos visszajelzései.
- **Tudatosan NEM javítva** (dokumentált döntés): a mentés-visszaállítás
  (`backup-restore.php`/`backup-now.php`/`auto-backup-run.php`)
  hibaágai továbbra is a `BackupManager` nyers kivétel-üzenetét adják
  vissza, ami elvétve fájl-elérési utat tartalmazhat. Ez a MÁR eddig is
  legszigorúbban védett végpont (vezetői jogszint + friss PIN-
  ellenőrzés a visszaállításnál) — az elérhető információ (helyi
  fájlnév a `data/backups/` alatt) alacsony kockázatú a már ennyire
  megbízható felhasználó számára, és a javítás vagy a `BackupManager`
  gondosan hangolt (és nemrég, a leltár/backup-kapcsolat javításakor
  is módosított) üzeneteinek kockázatosabb átírását, vagy a hasznos
  diagnosztikai részlet elvesztését jelentette volna egy valódi hiba
  elhárításakor — a kockázat/haszon arány itt nem indokolta a
  módosítást egy stabilizációs körben.
- **Dokumentáció-javítás**: a README "Termék importálás" szakasza
  tévesen azt állította, hogy .xls/.xlsx importhoz kézzel CSV-vé kell
  menteni a fájlt — valójában a szerver natívan (LibreOffice nélkül)
  olvassa be mindkét formátumot, az `install.txt` már eddig is helyesen
  ezt írta le.
- Teljes körű, valódi böngészős UX/security/adatintegritás-verifikáció
  (desktop 1280px + mobil 375px minden fő oldalon, egy teljes
  Beszerzési javaslat → előtöltés → mentés → készlet/árrés/
  beszerzési-előzmény lánc valódi végigjátszása, CSRF/cron-token/
  path-traversal/SQL-szerű input/XSS/open-redirect élő próba) — lásd a
  release-gate végső riportot a teljes eredményért.

## [1.3.0] — 2026-09-16 (Beszerzés, árrés és készletintelligencia)

**Feature release — az 1.2.0 Dashboard/riportok/forecast alapjára építve
konkrét beszerzési döntéstámogatás, árrés- és készletérték-számítás. Lásd
README "FountainTrade 1.3.0" szakasza a teljes technikai leírásért.**

### Added

- `src/PurchaseDecisionService.php` — központi, DB-független döntési
  szolgáltatás: biztonsági készlet, rendelési pont, javasolt mennyiség,
  sürgősségi besorolás, indoklás, árrés-számítás.
- Beszerzési javaslat lista (`beszerzesi-javaslat.php`) teljesen
  újratervezve: sürgősség szerint szűrhető (sürgős/hamarosan elfogy/
  alacsony készlet/minden), több termék kijelölhető és egyben
  beszerzésbe indítható (a meglévő prefill-mechanizmust újrahasznosítva).
- Termék mini-dashboard (`termekek.php` → "Áttekintés" fül,
  `product-insights.php`) — állapot/árrés, 30/90 napos forgalom,
  forecast, beszerzési előzmény, ártrend, egyetlen API-hívásban.
- Termékszintű és riport-szintű árrés (Ft és %) a Forgalmi riportban és a
  Top termékek listában, megbízhatóság-ellenőrzéssel (sose fabrikált
  árrés megbízhatatlan/hiányzó beszerzési árból).
- Készletérték-mutatók (`inventory-report.php`): nettó beszerzési ÉS
  eladási áron, plusz potenciális árrés-érték.
- Dashboard "Figyelmet igényel" blokk beszerzési fókuszú tételei, a
  Beszerzési javaslat oldalra mutatva (sürgősség szerint előszűrve).

### Changed

- `purchase-suggestions.php` válasza teljesen új alakú (a korábbi,
  beszállító-csoportosított formátum helyett a teljes döntéstámogató
  lista) — a fájlnév/URL változatlan.
- `getTopProductsReport()` margin mezőkkel bővült.
- A Dashboard "Figyelmet igényel" logikája a MEGLÉVŐ, központi
  `getPurchaseRecommendations()`-t használja az 1.2.0-as ad-hoc
  (zero_stock/forecast_low/low_stock) számítás helyett — a régóta nem
  használt `countLowRunwayProducts()` eltávolítva.

### Database

- Nincs új tábla, nincs új oszlop, nincs migráció — minden a meglévő
  `purchase_items`/`products` adatokból és a meglévő forecast-logikából
  épül. `Database::SCHEMA_VERSION` változatlanul 25 (ugyanaz, mint
  1.2.0-ban).

### Ismert korlátok (dokumentált, szándékos döntések)

- **Nincs `Ordered → Partially received → Received` beszerzési workflow-
  állapotgép.** A jelenlegi purchase-modell egyetlen, atomikus "a
  készlet MOST megérkezett" eseményt ír le — nincs benne "megrendelve,
  de még nem érkezett meg" fogalom. A lista helyette a MEGLÉVŐ adatokból
  levezetett "Javasolt"/"Folyamatban" (volt-e rá beszerzés az elmúlt 3
  napban) jelzést mutatja.
- **A margin/current-cost számítás NEM historikus áron alapul** — a
  termék JELENLEGI `net_price`/`purchase_price_net`/ÁFA-kulcs mezőit
  használja minden (akár régebbi) eladásra is, nem egy FIFO/mozgóátlag
  költségmodellt.
- **A forecast fix, dokumentált coverage/lead-time feltételezésekkel
  dolgozik** (`REVIEW_PERIOD_DAYS=7`, `TARGET_COVERAGE_DAYS=14`), mert a
  `suppliers` tábla nem tárol tényleges szállítási időt.

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
