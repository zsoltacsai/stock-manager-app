# Mílestone-ok

A FountainTrade mílestone-alapú fejlesztéssel készül. Ez a fájl a
**állapot** igazsága: melyik mílestone van kész, melyik van folyamatban,
és mi következik. A folyamat részletei:
[`docs/DEVELOPMENT_WORKFLOW.md`](docs/DEVELOPMENT_WORKFLOW.md).

## Állapot

| Mílestone | Tartalom | Ág / PR | Állapot |
| --- | --- | --- | --- |
| **M0** | Fejlesztői infrastruktúra: CI, teszt-politika, izolált ág + PR folyamat, adatvédelmi őr | `milestone/m0-dev-infrastructure` → PR #2 | Kész, CI zöld — jóváhagyásra vár |
| M1 | *Az Owner jelöli ki* | – | Nem indult el |

> Új mílestone csak akkor kerül a táblába, ha az Owner kijelölte. A
> mílestone-ok nem indulnak el automatikusan.

## Definition of Done (minden mílestone-ra)

- [ ] Minden változás izolált ágon, pull requestben érkezett.
- [ ] A `CI Gate` zöld (PHP 8.3 és 8.4, szintaxis, teszt-politika,
      titok-szkennelés, adatvédelmi őr).
- [ ] A viselkedésváltozásra van teszt a `tests/` mappában.
- [ ] Adatvesztés nélkül: `data/`, `invoices/`, meglévő beállítások és a
      git történelem érintetlen; minden migráció additív és
      visszaforgatható.
- [ ] `MILESTONES.md` és szükség esetén `CHANGELOG.md` frissítve.
- [ ] A PR leírásában az adatbiztonsági nyilatkozat kitöltve.

## M0 – Fejlesztői infrastruktúra

**Cél:** a mílestone-alapú fejlesztés gépi védelme. A kód változatlan
marad; a cél a kapuk és a folyamat.

Szállítmány:

- `.github/workflows/ci.yml` – szintaxis, teljes PHPUnit (PHP 8.3 + 8.4),
  teszt-politika, titok-szkennelés, összesített `CI Gate`.
- `.github/workflows/windows-installer.yml` – Pester a telepítőhöz, külön,
  nem blokkoló workflow.
- `scripts/ci/*` – a CI lépései külön, helyben is futtatható szkriptekben.
- `scripts/verify-all.ps1` – egyparancsos helyi ellenőrzés.
- `.github/PULL_REQUEST_TEMPLATE.md`, `.github/ISSUE_TEMPLATE/milestone.md`
  – mílestone-alapú PR/issue sablonok.
- `.gitleaks.toml` – dokumentált, szűk allowlist a szintetikus
  teszt-hitelesítő adatokra és a dokumentációs token-helyőrzőkre.
- `.githooks/pre-commit` – opcionális helyi szintaxis-kapú.
- `docs/DEVELOPMENT_WORKFLOW.md` – a folyamat leírása.
- Branch protection a `main` ágon: PR kötelező, `CI Gate` kötelező, nincs
  force push, nincs ágtörlés.

**Nem része az M0-nak:** új termékfunkció, adatbázis-séma változtatás,
`data/` vagy `invoices/` tartalom módosítása.
