# Fejlesztői munkafolyamat (M0)

Ez a dokumentum az **M0 – fejlesztői infrastruktúra** mílestone eredménye:
rögzíti, hogyan fejlesztjük a FountainTrade-et mílestone-onként, milyen
automatikus kapuk védik a `main` ágat, és milyen szabályok védik a valódi
adatokat.

A mílestone-állapot és a soron következő mílestone: [`MILESTONES.md`](../MILESTONES.md).

## 1. Alapelvek

1. **Minden változás izolált ágon, pull requestben érkezik.** A `main` ágra
   közvetlen push nincs (branch protection).
2. **A zöld CI a belépőjegy.** A PR csak akkor merge-elhető, ha a
   `CI Gate` ellenőrzés zöld.
3. **Adatot nem írunk felül.** Meglévő fájl, adatbázis-sor, beállítás vagy
   git-történelem nem tűnhet el. A változtatás additív.
4. **Termékkód-változáshoz teszt tartozik.** A CI teszt-politika ezt
   ellenőrzi (kivétel: indokolt `[skip-test-policy]`).
5. **Nem kezdünk el automatikusan új mílestone-t.** Az Owner jelöli ki.

## 2. Ágak és pull requestek

| Ág | Cél |
| --- | --- |
| `main` | Védett, mindig zöld, mindig telepíthető állapot |
| `milestone/mNN-rovid-leiras` | Egy mílestone teljes munkája (pl. `milestone/m0-dev-infrastructure`) |
| `fix/rovid-leiras` | Hibajavítás, ha nem fér egy mílestone-ba |
| `docs/rovid-leiras` | Csak dokumentáció |

Folyamat:

```text
main → ág létrehozása → fejlesztés → helyi ellenőrzés → push → PR
     → CI Gate zöld → review → merge (squash vagy merge commit)
```

- Egy PR egy mílestone (vagy egy jól körülhatárolt javítás).
- A PR leírása a sablont követi (`.github/PULL_REQUEST_TEMPLATE.md`),
  benne az **Adatbiztonság** szakasz kitöltése kötelező.
- Force push és történelem-átírás tilos. Ha a PR-t frissíteni kell,
  új commit jön rá.
- Merge után az ág törölhető; a `main` marad az igazság.

## 3. Automatikus CI (`.github/workflows/ci.yml`)

Minden PR-nél és minden `main`-re történő push-nál fut:

| Job | Mit ellenőriz | Blokkoló? |
| --- | --- | --- |
| `PHP szintaxis` | Minden követett `*.php` fájl `php -l` ellenőrzése | Igen |
| `PHPUnit (PHP 8.3)` | A teljes teszt-készlet az autoritatív PHP-verzión | Igen |
| `PHPUnit (PHP 8.4)` | Ugyanaz a suite a következő PHP-ágon (előrejelzés) | Igen |
| `Teszt-politika` | Termékkód-változás → legyen `tests/` változás is (csak PR) | Igen |
| `Titok-szkennelés` | Gitleaks a munkakönyvtáron, allowlisttel | Igen |
| `CI Gate` | A fenti jobok összesített eredménye | **Ez a kötelező check** |

A Windows telepítő Pester-tesztjei külön workflow-ban futnak
(`.github/workflows/windows-installer.yml`), csak akkor, ha a telepítő
vagy annak tesztjei változnak. Ez **nem** része a `CI Gate`-nek.

### Mit jelent a "zöld"?

- Nincs szintaxis-hiba.
- A teljes PHPUnit suite lefut; a kihagyott tesztek száma nem nő
  indokolatlanul (a kihagyások platform- vagy környezetfüggők, ezeket a
  teszt maga indokolja).
- A valódi `data/` és `invoices/` mappa bitre érintetlen maradt.
- Nincs új titok a fájlokban.
- Termékkód-változásnál van hozzá tartozó teszt.

## 4. Teszt-politika

A `scripts/ci/check-test-policy.sh` a PR-ban megváltozott fájlokat nézi.
Ha az alábbiak bármelyike változik, és a `tests/` mappában nincs változás,
a job elbukik:

- `src/**`
- `webroot/api/**`
- `config/**`
- `install-windows.ps1`, `install-windows-lib.ps1`
- `schema.sql`, `schema.mysql.sql`

**Kivétel:** ha a PR leírása tartalmazza a `[skip-test-policy]` jelölést.
Ilyenkor a leírásban meg kell indokolni, miért nincs teszt (pl. csak
komment, csak dokumentáció, vagy a viselkedés nem változik).

Ez a szabály nem azt jelenti, hogy minden apró módosításhoz új teszt
kell — azt jelenti, hogy **nem lehet csendben, teszt nélkül megváltoztatni
a termékkódot**.

## 5. Adatmegőrzés — a legfontosabb szabály

A fejlesztés során a legfontosabb korlát: **a meglévő adat és fájlok nem
veszhetnek el.**

### Tilos

- Meglévő fájl tartalmát "felülírni" úgy, hogy abból információ vész el.
  Új fájl, hozzáfűzés, célzott, indokolt módosítás rendben.
- A valódi `data/stock.sqlite`, `data/settings.json`, `invoices/` vagy
  `config/installer-generated.php` módosítása teszttel vagy szkripttel.
- Destruktív adatbázis-migráció (oszlop/tábla törlés, adat újraírás)
  anélkül, hogy az visszaforgatható lenne.
- Force push, rebase a közös ágon, git történelem átírása.
- Valódi vevőadat, jelszó, kulcs vagy token commitolása.

### Kötelező

- A tesztek saját, ideiglenes SQLite fájllal dolgozzanak
  (`tests_new_database()`), sose a valódi `data/` mappával.
- Minden migráció **additív** legyen (új tábla/oszlop/index), és legyen
  visszaforgatható.
- Új beállítás alapértéke a meglévő, biztonságos viselkedést tartsa meg.
- A CI `Adatvédelmi őr` lépése és a helyi `verify-all.ps1` ugyanezt
  ellenőrzi: ha a `data/` vagy `invoices/` mappa megváltozott, a futás
  elbukik.

Ha egy változtatás mégis adatot érintene, azt **külön, explicit
migrációs lépésként**, visszaállítási tervvel és az Owner jóváhagyásával
kell megtenni — nem egy funkciófejlesztés mellékhatásaként.

## 6. Helyi fejlesztés

### Követelmények

- PHP 8.3 (a projekt autoritatív verziója), legalább a következő
  kiterjesztésekkel: `mbstring`, `pdo_sqlite`, `sqlite3`, `dom`,
  `xmlwriter`, `tokenizer`, `ctype`, `curl`, `openssl`, `zip`, `intl`, `gd`
- Node.js 22+ (a `tests/js/*.cjs` frontend-harness tesztekhez)
- Git for Windows

> A winget-es minimál PHP-ból hiányozhat a `mbstring` és a `pdo_sqlite`.
> Ilyenkor használj teljes PHP 8.3 telepítést, pl.
> `C:\tools\php83\php.exe`.

### Egy paranccsal

```powershell
powershell -ExecutionPolicy Bypass -File scripts/verify-all.ps1
```

Ez lefuttatja a szintaxis-ellenőrzést, a teljes PHPUnit suite-ot, az
adatvédelmi őrt, és ha van Pester, a telepítő teszteket is.

Részleges futtatás:

```powershell
# Csak szintaxis + adatőr (gyors)
powershell -ExecutionPolicy Bypass -File scripts/verify-all.ps1 -SkipTests

# Megadott PHP-val
powershell -ExecutionPolicy Bypass -File scripts/verify-all.ps1 -PhpPath C:\tools\php83\php.exe
```

### Közvetlenül

```bash
# PHPUnit (a phar-t a scripts/ci/fetch-phpunit.sh vagy a verify-all.ps1 tölti le)
php tools/phpunit.phar

# Egy teszt-osztály
php tools/phpunit.phar tests/CashSessionTest.php

# Pester (opcionális, Windows)
Invoke-Pester -Script tests/Install-WindowsTests.ps1
```

### Opcionális pre-commit hook

```bash
git config core.hooksPath .githooks
```

Ez commit előtt csak a staged PHP fájlok szintaxisát ellenőrzi. Nem
helyettesíti a CI-t.

## 7. Branch protection beállítása

A `main` ág védelme a GitHubon él, nem a repóban. Beállítás (egyszer,
tulajdonosi jogosultsággal):

```bash
bash scripts/ci/setup-branch-protection.sh
```

Amit beállít:

- pull request kötelező a `main`-re (0 kötelező jóváhagyás, hogy egyedüli
  tulajdonos mellett ne legyen önmagát jóváhagyó kényszer),
- kötelező, zöld `CI Gate` státusz,
- force push tiltva,
- ágtörlés tiltva,
- nyitott beszélgetések feloldása kötelező merge előtt.

Ellenőrzés:

```bash
gh api repos/zsoltacsai/stock-manager-app/branches/main/protection
```

## 8. Új mílestone indítása

1. Az Owner kitölti a mílestone issue-t (`.github/ISSUE_TEMPLATE/milestone.md`)
   és a `MILESTONES.md`-ben rögzíti a célt.
2. Ág: `git switch -c milestone/mNN-rovid-leiras main`
3. Fejlesztés kisebb commitokkal; minden viselkedésváltozáshoz teszt.
4. `scripts/verify-all.ps1` helyben zöld.
5. PR nyitása a sablonnal; a leírásban az adatbiztonsági szakasz kitöltve.
6. CI Gate zöld → review → merge.
7. `MILESTONES.md` és szükség esetén `CHANGELOG.md` frissítése még a PR-ban.

## 9. Hiba esetén

- **A CI piros, de a hiba nem a PR-hoz tartozik?** Először reprodukáld
  helyben (`verify-all.ps1`). Ha infrastruktúra-hiba, külön `fix/` PR-ben
  javítsd, ne a funkció-PR-ban kapcsold ki az ellenőrzést.
- **Sürgős javítás kell a `main`-re?** Ugyanúgy PR-ral, csak gyorsítva.
  A branch protection szándékosan akkor sem enged közvetlen push-t.
- **Visszaállítás:** a `main` minden merge-je egy ismert jó állapot.
  `git revert <merge-commit>` vagy az előző release visszatelepítése.
  Adatbázis-migráció visszaforgatása csak additív migráció esetén
  biztonságos; ellenkező esetben előbb mentés.

## 10. Ismert korlátok (M0)

- A PHP 8.4 job előrejelző: ha a suite ott elbukik egy PHP 8.4-es
  viselkedésváltozás miatt, a javítás külön PR-t érdemel; nem szabad a
  jobot csendben kikapcsolni.
- A Gitleaks a **munkakönyvtárat** szkenneli (nem a teljes git
  történelmet), hogy a kezdeti, ismert hamis pozitívok ne blokkolják a
  fejlesztést. A teljes történelem vizsgálata későbbi mílestone lehet.
- A Pester suite nem CI-kapuzó, külön Windows workflow futtatja.
- A frontend tesztek `node:vm` alatt futnak, nem valódi böngészőben; a
  valódi böngészős ellenőrzés továbbra is kézi.
