---
name: Mílestone
about: Új mílestone tervezése és követése (M0, M1, ...)
title: "[M1] "
---

## Cél

<!-- Egy mondat: mitől lesz jobb a termék/üzemeltetés a mílestone végén? -->

## Hatókör

- [ ] <!-- feladat 1 -->
- [ ] <!-- feladat 2 -->

## Nem része ennek a mílestone-nak

<!-- Szándékosan kihagyott dolgok, hogy ne csússzon a hatókör. -->

## Adatbiztonsági hatás

- Érint-e adatbázis-sémát? Ha igen, additív migráció lesz-e?
- Érint-e valódi `data/` vagy `invoices/` tartalmat? (Helyes válasz: nem.)

## Definition of Done

- [ ] Minden változás izolált ágon, PR-ban érkezett
- [ ] CI Gate zöld
- [ ] Új/módosított tesztek a `tests/` mappában
- [ ] `MILESTONES.md` és szükség esetén a `CHANGELOG.md` frissítve
- [ ] Nincs adatvesztés, nincs force-push, nincs titok a repóban
