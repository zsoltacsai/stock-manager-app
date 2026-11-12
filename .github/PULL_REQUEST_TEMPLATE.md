## Mílestone

- [ ] M0 – fejlesztői infrastruktúra
- [ ] M1 – <!-- az Owner által kijelölt tartalom -->
- [ ] Egyéb: <!-- pl. hotfix -->

## Mit változtat ez a PR?

<!-- Rövid, tényekre szorítkozó leírás. Új funkció, javítás, dokumentáció? -->

## Hogyan tesztelted?

- [ ] `powershell -ExecutionPolicy Bypass -File scripts/verify-all.ps1` helyben zöld
- [ ] CI zöld (CI Gate)
- [ ] Ha a viselkedés változott: új/módosított teszt a `tests/` mappában
- [ ] Ha kézi ellenőrzés kellett: lépések és eredmény

<!-- Ha szándékosan nincs teszt: indoklás + [skip-test-policy] a leírásban. -->

## Adatbiztonság (KÖTELEZŐ)

- [ ] Nem írtam felül meglévő fájlt vagy adatot; a változtatás additív
- [ ] `data/`, `invoices/`, `config/installer-generated.php` érintetlen
- [ ] Minden adatbázis-migráció additív és visszaforgatható (nincs adatvesztés)
- [ ] Nem force-pusholtam és nem írtam át a git történelmet
- [ ] Nem került titok (kulcs, jelszó, token, valódi vevőadat) a repóba

## Kockázat és visszaállítás

<!-- Mi törhet el, és hogyan áll vissza? Melyik commit az ismert jó állapot? -->

## Kapcsolódó dokumentáció

<!-- MILESTONES.md, docs/DEVELOPMENT_WORKFLOW.md, CHANGELOG.md frissítve? -->
