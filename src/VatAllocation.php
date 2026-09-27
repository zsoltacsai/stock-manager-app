<?php

declare(strict_types=1);

/**
 * AZ EGYETLEN értékesítési ÁFA-allokációs és kerekítési szabály (B-13, N-5).
 * Minden olyan hely, ami egy eladás (visszáru) bruttó/nettó/ÁFA értékét
 * mutatja — napi zárás, értékesítési riport (→ Dashboard, AI-metrikák), ÉS
 * a kiállított számla (Számlázz.hu / NAV XML, `invoices` tükör-összegek) —
 * ezt használja. Máshol NEM szabad eladásra ÁFÁ-t számolni.
 *
 * 1. Eladás-szintű érték: Database::saleGrossValue() — a befizetett összeg
 *    (kupon/pont/hűségszint-kedvezmény UTÁN) + az ajándékutalvánnyal
 *    fedezett rész (az utalvány fizetési eszköz, nem kedvezmény — B-06).
 *    Visszárunál Database::returnGrossValue().
 *
 * 2. Sor-allokáció (breakdown()): az értéket FILLÉRRE pontosan osztjuk szét
 *    a sorokra a kedvezmény ELŐTTI sor-bruttó (unit_price × qty) arányában,
 *    a legnagyobb maradék módszerével. A kedvezmény (kupon, pont,
 *    hűségszint) így arányosan terheli a sorokat, ÁFA-kulcstól függetlenül.
 *
 * 3. Kerekítés — KIZÁRÓLAG itt, és csak SOR-szinten (lineFromGrossCents()):
 *      sor nettó = round(sor bruttó / (1 + kulcs), 2)   (nem numerikus
 *                  kulcsnál — AAM/TAM — nettó = bruttó);
 *      sor ÁFA   = sor bruttó − sor nettó.
 *    Nincs egységár-szintű ÁFA- vagy nettó-számítás (az N-5 hibája éppen az
 *    volt, hogy a számla egységárat kerekített, majd abból szorzott: a
 *    3 × 10 Ft / 20 Ft kupon eladás számlája 9.99 / 7.86 lett a
 *    riport 10.00 / 7.87 / 2.13 értéke helyett).
 *
 * 4. Maradék fillér: a szétosztás maradékát a legnagyobb törtrészű sorok
 *    kapják, egyenlőségnél a KORÁBBI sor (stabil rendezés) — ugyanarra a
 *    bemenetre mindig ugyanaz a sor, futásonként nem változik.
 *
 * 5. Garanciák (egész fillérben számolva, lebegőpontos maradék nélkül):
 *      - minden soron nettó + ÁFA = bruttó (az ÁFA definíció szerint a
 *        különbség);
 *      - a sorok bruttójának összege = az eladás értéke (a szétosztás
 *        egész fillérekben pontos);
 *      - mivel a riport, a napi zárás és a számla UGYANAZT a breakdown()
 *        eredményt használja ugyanazokra a sorokra, a számla nettó/ÁFA/
 *        bruttó összege = az eladás riportban látott értéke.
 *
 * 6. Számla-reprezentáció (invoiceItems()): a számla-XML egységárat és
 *    mennyiséget is vár (Számlázz.hu: nettoEgysegar × mennyiseg =
 *    nettoErtek; NAV: unitPrice × quantity = lineNetAmount), két
 *    tizedesre. Ha az allokált sor-bruttó vagy -nettó nem osztható
 *    maradék nélkül a mennyiséggel, a sort LEGFELJEBB HÁROM, azonos nevű,
 *    ÁFA-kulcsú alsorra bontjuk, amelyek egységára 1 fillérben tér el
 *    (pl. 3 db, bruttó 10.00 / nettó 7.87 → 2 db × 3.33/2.62 és
 *    1 db × 3.34/2.63). Minden alsoron egységár × mennyiség = sorérték
 *    pontosan, és az alsorok összege = az allokált sor. Nincs független
 *    "kerekítési korrekció" sor és nincs végösszeg-felülírás. A bontás
 *    determinisztikus (a +1 filléres egységek mindig az elsők).
 *
 * 7. Visszáru (returnAllocation(), A-03): a visszavett tételek pénzügyi
 *    értéke NEM egy újabb arányosítás, hanem az eredeti eladás 2–3. pont
 *    szerinti sor-allokációjának (bruttó és nettó, egész fillérben)
 *    egységenkénti felosztása: egy q darabos sor G fillérjéből az első
 *    (G mod q) visszavett darab floor(G/q)+1, a többi floor(G/q) fillér —
 *    ugyanígy a nettó. Egy visszáru a korábban már visszavett n darab UTÁN
 *    következő k darabot kapja: cum(n+k) − cum(n). Így:
 *      - egy sor összes visszavett darabjának értéke pontosan G (és N),
 *        bármilyen részletekben és bármilyen sorrendben vették vissza;
 *      - a visszáru ÁFÁ-ja = visszavett bruttó − visszavett nettó, soronként
 *        a sor saját kulcsán (nincs újabb kerekítés a részleteken);
 *      - teljes visszavételkor a visszáruk összege fillérre az eladás
 *        értéke, nettója és ÁFÁ-ja, kulcsonként is.
 *    A FIZETÉSI visszatérítés (a fizetési módon visszaadott pénz) KÜLÖN
 *    fogalom: ugyanezzel a felosztással a befizetett összegből (sales.total)
 *    számolódik; az ajándékutalványra jutó rész visszaírása változatlanul a
 *    teljes visszavételkor történik (B-06, reverseSaleBenefits()).
 *
 * A számla-XML-ek (SzamlazzClient, NavInvoiceXmlBuilder) az így allokált
 * tételeket (`allocated` = true) változtatás nélkül írják ki — ÁFÁ-t nem
 * számolnak újra (renderLine()). Allokáció nélküli tétel (a javítás előtt
 * tárolt payload, egy admin által szerkesztett módosító számla tételsora)
 * a korábbi, egységár-alapú módon renderelődik — így egy régi számla
 * sztornója pontosan a ténylegesen kiállított összegeket fordítja vissza.
 */
final class VatAllocation
{
    /**
     * DB-05 — a rendszerben használt ÁFA-kódok (a termék-, beszerzés- és
     * kassza-űrlapok választói, a NAV-leképezés — NavInvoiceXmlBuilder::
     * vatCategory() — és a Számlázz.hu `afakulcs` értékei). A backend az
     * irányadó: minden értékesítési adatot író végpont és a számla-építők
     * ezt a listát ellenőrzik, nem a UI választójára hagyatkoznak.
     */
    public const SUPPORTED_RATES = ['27', '18', '5', '0', 'AAM', 'TAM'];

    public static function isSupportedRate(mixed $rate): bool
    {
        return is_string($rate) && in_array($rate, self::SUPPORTED_RATES, true);
    }

    /**
     * @param array<int, array{unit_price:mixed, qty:mixed, vat_rate:mixed}> $lines
     * @return array{lines: list<array{vat_rate:string, gross:float, net:float, vat:float}>, gross:float, net:float, vat:float}
     */
    public static function breakdown(float $value, array $lines): array
    {
        [$lines, $cents] = self::allocateCents($value, $lines);
        $result = ['lines' => [], 'gross' => 0.0, 'net' => 0.0, 'vat' => 0.0];
        $sum = ['gross' => 0, 'net' => 0, 'vat' => 0];
        foreach ($lines as $i => $line) {
            $amounts = self::lineFromGrossCents($cents[$i], (string) ($line['vat_rate'] ?? ''));
            $result['lines'][] = [
                'vat_rate' => (string) ($line['vat_rate'] ?? ''),
                'gross' => $amounts['gross'] / 100.0,
                'net' => $amounts['net'] / 100.0,
                'vat' => $amounts['vat'] / 100.0,
            ];
            foreach ($sum as $k => $v) {
                $sum[$k] = $v + $amounts[$k];
            }
        }
        foreach ($sum as $k => $v) {
            $result[$k] = $v / 100.0;
        }
        return $result;
    }

    /**
     * Egy sor bruttójából (egész fillér) a nettó és az ÁFA — a 3. pont
     * szabálya, egész fillérben. Előjelhelyes (negatív bruttónál szimmetrikus).
     *
     * @return array{gross:int, net:int, vat:int}
     */
    public static function lineFromGrossCents(int $grossCents, string $vatRate): array
    {
        $rate = trim($vatRate);
        $net = is_numeric($rate)
            ? (int) round($grossCents / (1 + ((float) $rate) / 100))
            : $grossCents;
        return ['gross' => $grossCents, 'net' => $net, 'vat' => $grossCents - $net];
    }

    /**
     * Egy eladás számlatételei: breakdown() soronkénti értékei, számla-
     * reprezentációra bontva (6. pont).
     *
     * @param array<int, array{name:mixed, qty:mixed, unit_price:mixed, vat_rate:mixed}> $lines
     *        a kedvezmény ELŐTTI egységárral — ugyanaz a bemenet, amiből a
     *        riport az eladást (sale_items) bontja
     * @return list<array{name:string, qty:float, unit_price_gross:float, unit_price_net:float, line_gross:float, line_net:float, line_vat:float, vat_rate:string, allocated:bool}>
     */
    public static function invoiceItems(float $value, array $lines): array
    {
        [$lines, $cents] = self::allocateCents($value, $lines);
        $items = [];
        foreach ($lines as $i => $line) {
            $rate = (string) ($line['vat_rate'] ?? '');
            $amounts = self::lineFromGrossCents($cents[$i], $rate);
            foreach (self::splitForUnitPrice((float) $line['qty'], $amounts['gross'], $amounts['net']) as [$qty, $unitGross, $unitNet]) {
                $gross = (int) round($unitGross * $qty);
                $net = (int) round($unitNet * $qty);
                $items[] = [
                    'name' => (string) ($line['name'] ?? ''),
                    'qty' => $qty,
                    'unit_price_gross' => $unitGross / 100.0,
                    'unit_price_net' => $unitNet / 100.0,
                    'line_gross' => $gross / 100.0,
                    'line_net' => $net / 100.0,
                    'line_vat' => ($gross - $net) / 100.0,
                    'vat_rate' => $rate,
                    'allocated' => true,
                ];
            }
        }
        return $items;
    }

    /**
     * Az allokált számlatételek összegei (a tükör-bejegyzés net/vat/gross
     * mezőihez) — egész fillérben összegezve.
     *
     * @return array{net:float, vat:float, gross:float}
     */
    public static function totals(array $items): array
    {
        $sum = ['net' => 0, 'vat' => 0, 'gross' => 0];
        foreach ($items as $item) {
            $line = self::renderLine($item);
            foreach ($sum as $k => $v) {
                $sum[$k] = $v + (int) round($line[$k] * 100);
            }
        }
        return ['net' => $sum['net'] / 100.0, 'vat' => $sum['vat'] / 100.0, 'gross' => $sum['gross'] / 100.0];
    }

    /**
     * Egy számlatétel kiírandó értékei. Allokált tételnél a tárolt értékek
     * változatlanul (ÁFA-újraszámolás nincs); allokáció nélküli (régi
     * payload, szerkesztett módosító tételsor) tételnél a korábbi,
     * egységár-alapú számítás — lásd az osztály docblockja.
     *
     * @return array{qty:float, unit_net:float, net:float, vat:float, gross:float}
     */
    public static function renderLine(array $item): array
    {
        $qty = (float) $item['qty'];
        if (!empty($item['allocated'])) {
            return [
                'qty' => $qty,
                'unit_net' => (float) $item['unit_price_net'],
                'net' => (float) $item['line_net'],
                'vat' => (float) $item['line_vat'],
                'gross' => (float) $item['line_gross'],
            ];
        }
        $rate = trim((string) $item['vat_rate']);
        $grossUnit = (float) $item['unit_price_gross'];
        $netUnit = is_numeric($rate) ? round($grossUnit / (1 + ((float) $rate) / 100), 2) : $grossUnit;
        $net = round($netUnit * $qty, 2);
        $gross = round($grossUnit * $qty, 2);
        return ['qty' => $qty, 'unit_net' => $netUnit, 'net' => $net, 'vat' => round($gross - $net, 2), 'gross' => $gross];
    }

    /**
     * Sztornóhoz: a tételek előjel-fordítása (negatív mennyiség, negatív
     * sorértékek, pozitív egységár) — az allokált értékek pontos ellentettje.
     */
    public static function negateItems(array $items): array
    {
        return array_map(static function (array $item): array {
            $item['qty'] = -abs((float) ($item['qty'] ?? 0));
            if (!empty($item['allocated'])) {
                foreach (['line_gross', 'line_net', 'line_vat'] as $k) {
                    $item[$k] = -abs((float) $item[$k]);
                }
            }
            return $item;
        }, $items);
    }

    /**
     * A 7. pont: egy visszáru pénzügyi értéke az eredeti eladás allokációjából.
     *
     * @param float $saleValue az eladás értéke (Database::saleGrossValue())
     * @param float $salePaid  a fizetési módon befizetett rész (sales.total)
     * @param array<int, array{id:mixed, qty:mixed, unit_price:mixed, vat_rate:mixed}> $saleLines
     *        az eladás ÖSSZES sora (sale_items) — a sorrend id szerint rögzül
     * @param array<int, int> $alreadyReturned sale_item_id => a KORÁBBI visszárukban visszavett darab
     * @param list<array{sale_item_id:mixed, qty:mixed}> $returnRows ennek a visszárunak a sorai
     * @return array{rows: list<array{gross:float, net:float, vat:float, paid:float, vat_rate:string}>, gross:float, net:float, vat:float, paid:float}
     * @throws InvalidArgumentException ismeretlen eladási tétel vagy a még visszavehetőnél több darab esetén
     */
    public static function returnAllocation(float $saleValue, float $salePaid, array $saleLines, array $alreadyReturned, array $returnRows): array
    {
        $saleLines = array_values($saleLines);
        usort($saleLines, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        [$valueLines, $valueCents] = self::allocateCents($saleValue, $saleLines);
        [, $paidCents] = self::allocateCents($salePaid, $saleLines);
        // Pozitív tételérték nélküli eladásnál (allocateCents() tartalék-sora)
        // nincs mit visszaosztani — minden visszavett darab értéke 0.
        $hasValue = count($valueLines) === count($saleLines);

        $bySaleItem = [];
        foreach ($saleLines as $i => $line) {
            $rate = (string) ($line['vat_rate'] ?? '');
            $gross = $hasValue ? $valueCents[$i] : 0;
            $bySaleItem[(int) $line['id']] = [
                'qty' => (int) $line['qty'],
                'vat_rate' => $rate,
                'gross' => $gross,
                'net' => self::lineFromGrossCents($gross, $rate)['net'],
                'paid' => $hasValue ? $paidCents[$i] : 0,
            ];
        }

        $taken = [];
        $rows = [];
        $sum = ['gross' => 0, 'net' => 0, 'vat' => 0, 'paid' => 0];
        foreach ($returnRows as $row) {
            $id = (int) ($row['sale_item_id'] ?? 0);
            $k = (int) ($row['qty'] ?? 0);
            if (!isset($bySaleItem[$id])) {
                throw new InvalidArgumentException("Ismeretlen eladási tétel: #$id");
            }
            $line = $bySaleItem[$id];
            $n = ($alreadyReturned[$id] ?? 0) + ($taken[$id] ?? 0);
            if ($k <= 0 || $n + $k > $line['qty']) {
                throw new InvalidArgumentException("Az eladási tételből (#$id) legfeljebb " . max(0, $line['qty'] - $n) . ' db vihető még vissza.');
            }
            $taken[$id] = ($taken[$id] ?? 0) + $k;
            $cents = [];
            foreach (['gross', 'net', 'paid'] as $f) {
                $cents[$f] = self::unitShare($line[$f], $line['qty'], $n + $k) - self::unitShare($line[$f], $line['qty'], $n);
            }
            $cents['vat'] = $cents['gross'] - $cents['net'];
            $rows[] = [
                'gross' => $cents['gross'] / 100.0,
                'net' => $cents['net'] / 100.0,
                'vat' => $cents['vat'] / 100.0,
                'paid' => $cents['paid'] / 100.0,
                'vat_rate' => $line['vat_rate'],
            ];
            foreach ($sum as $f => $v) {
                $sum[$f] = $v + $cents[$f];
            }
        }
        return [
            'rows' => $rows,
            'gross' => $sum['gross'] / 100.0,
            'net' => $sum['net'] / 100.0,
            'vat' => $sum['vat'] / 100.0,
            'paid' => $sum['paid'] / 100.0,
        ];
    }

    /**
     * Egy q darabos sor $cents fillérjéből az első $units darabra jutó rész:
     * az első ($cents mod q) darab 1 fillérrel többet kap (7. pont).
     */
    private static function unitShare(int $cents, int $qty, int $units): int
    {
        if ($qty <= 0 || $units <= 0) {
            return 0;
        }
        $base = intdiv($cents, $qty);
        return $units * $base + min($units, $cents - $base * $qty);
    }

    /**
     * A 2. és 4. pont: az érték egész fillérekre osztása a sorok között.
     *
     * @return array{0: list<array>, 1: array<int, int>} [a (tartalék-sorral kiegészített) sorok, soronkénti fillér]
     */
    private static function allocateCents(float $value, array $lines): array
    {
        $valueCents = (int) round($value * 100);
        $lines = array_values($lines);
        $raw = [];
        foreach ($lines as $i => $line) {
            $raw[$i] = max(0.0, (float) $line['unit_price'] * (int) $line['qty']);
        }
        $rawTotal = array_sum($raw);
        if ($raw === [] || $rawTotal <= 0) {
            // Tétel (vagy pozitív tételérték) nélkül nincs mihez rendelni a
            // kulcsot — ismeretlen kulcsú, ÁFA nélküli értékként jelenik meg.
            $lines = [['name' => '', 'vat_rate' => '', 'unit_price' => 1, 'qty' => 1]];
            $raw = [1.0];
            $rawTotal = 1.0;
        }

        $cents = [];
        $remainders = [];
        foreach ($raw as $i => $r) {
            $exact = $r / $rawTotal * $valueCents;
            $cents[$i] = (int) floor($exact);
            $remainders[$i] = $exact - $cents[$i];
        }
        $left = $valueCents - array_sum($cents);
        arsort($remainders, SORT_NUMERIC); // stabil: egyenlő maradéknál az előbbi sor
        foreach (array_keys($remainders) as $i) {
            if ($left <= 0) {
                break;
            }
            $cents[$i]++;
            $left--;
        }
        return [$lines, $cents];
    }

    /**
     * A 6. pont: egy allokált sor (bruttó/nettó egész fillérben) felbontása
     * két tizedesre ábrázolható egységárú alsorokra.
     *
     * @return list<array{0:float, 1:int, 2:int}> [mennyiség, egység-bruttó fillér, egység-nettó fillér]
     */
    private static function splitForUnitPrice(float $qty, int $grossCents, int $netCents): array
    {
        $q = (int) $qty;
        if ($q < 1 || (float) $q !== $qty || $grossCents < 0 || $netCents < 0) {
            // Nem pozitív egész mennyiség vagy negatív érték (az eladási
            // folyamatban nem fordul elő): egyetlen sor, a sorértékek
            // pontosak, az egységár kerekített.
            $div = $qty != 0.0 ? $qty : 1.0;
            return [[$qty, (int) round($grossCents / $div), (int) round($netCents / $div)]];
        }
        $gBase = intdiv($grossCents, $q);
        $gRest = $grossCents - $gBase * $q;
        $nBase = intdiv($netCents, $q);
        $nRest = $netCents - $nBase * $q;
        $both = min($gRest, $nRest);
        $one = max($gRest, $nRest);

        $groups = [];
        if ($q - $one > 0) {
            $groups[] = [(float) ($q - $one), $gBase, $nBase];
        }
        if ($both > 0) {
            $groups[] = [(float) $both, $gBase + 1, $nBase + 1];
        }
        if ($one - $both > 0) {
            $groups[] = $gRest > $nRest
                ? [(float) ($one - $both), $gBase + 1, $nBase]
                : [(float) ($one - $both), $gBase, $nBase + 1];
        }
        return $groups;
    }
}
