<?php

/**
 * A NAV Online Számla v3 invoiceData.xsd szerinti "InvoiceData" XML
 * felépítése a Stock Manager saját eladás-adataiból (ugyanaz a
 * buyer/items alak, amit InvoiceProviderInterface::issueSync()/enqueue()
 * is kap — lásd InvoiceService). Minden elem/mező neve az invoiceData.xsd-
 * ből és a NAV publikus minta-XML-jeiből ("Belfoldi termekertekesites.xml",
 * "Belfoldi termekertekesites maganszemelynek.xml",
 * github.com/nav-gov-hu/Online-Invoice) igazolt — SEM a struktúra, sem az
 * enum-értékek nincsenek kitalálva.
 *
 * FONTOS, TUDATOS KÜLÖNBSÉG a Számlázz.hu-s SzamlazzClient::buildInvoiceXml()
 * -hez képest: ott az "elado" (eladó) blokk teljesen ÜRES marad, mert a
 * Számlázz.hu fiók oldalán van eltárolva a cég adata — a NAV Online Számla
 * API-nak viszont NINCS ilyen fiók-fogalma, minden egyes invoiceData
 * dokumentumnak saját magának kell tartalmaznia a teljes supplierInfo
 * blokkot (adószám, név, cím). A Stock Manager Beállításai jelenleg NEM
 * tartalmaznak strukturált cégnév/cím mezőt (csak a szabad szöveges
 * receipt_header_lines-t) — ezért a supplierInfo-t ez az osztály NEM a
 * Settings-ből olvassa, hanem explicit paraméterként várja el a hívótól.
 * Lásd a Phase 5A záró jelentés "KNOWN LIMITATIONS" pontját.
 *
 * A vevő szabad szöveges "cim" mezőjét (nev/irsz/telepules/cim/adoszam —
 * ugyanaz az alak, mint amit a SzamlazzClient is vár) a NAV egy
 * strukturáltabb címmel (streetName + publicPlaceCategory + number)
 * várja — ez a felbontás csak BEST-EFFORT heurisztika (lásd splitAddress()
 * docblockja), mert a szabad szöveges címből nem lehet garantáltan,
 * egyértelműen visszafejteni a NAV-struktúrát.
 */
class NavInvoiceXmlBuilder
{
    /**
     * @param array $params {
     *     invoice_number: string,
     *     issue_date?: string (Y-m-d, alapértelmezett: ma),
     *     delivery_date?: string (Y-m-d, alapértelmezett: issue_date),
     *     payment_date?: string (Y-m-d, alapértelmezett: issue_date),
     *     currency?: string (alapértelmezett: HUF),
     *     appearance?: string (base:InvoiceAppearanceType — alapértelmezett: PAPER),
     *     payment_method?: ?string (a Stock Manager saját, szabad szöveges fizetési módja),
     *     supplier: array{tax_number:string, name:string, zip:string, city:string, address:string, bank_account?:?string},
     *     buyer: array{nev:string, irsz:string, telepules:string, cim:string, adoszam?:?string},
     *     items: array<array{name:string, qty:float, unit_price_gross:float, vat_rate:string}>,
     * }
     */
    public static function build(array $params): string
    {
        $issueDate = $params['issue_date'] ?? date('Y-m-d');
        $deliveryDate = $params['delivery_date'] ?? $issueDate;
        $paymentDate = $params['payment_date'] ?? $issueDate;
        $currency = $params['currency'] ?? 'HUF';
        $appearance = $params['appearance'] ?? 'PAPER';
        $supplier = $params['supplier'];
        $buyer = $params['buyer'];
        $items = $params['items'];

        $xw = new XMLWriter();
        $xw->openMemory();
        $xw->startDocument('1.0', 'UTF-8');

        $xw->startElementNs(null, 'InvoiceData', 'http://schemas.nav.gov.hu/OSA/3.0/data');
        $xw->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');
        $xw->writeAttributeNs('xmlns', 'common', null, 'http://schemas.nav.gov.hu/NTCA/1.0/common');
        $xw->writeAttributeNs('xmlns', 'base', null, 'http://schemas.nav.gov.hu/OSA/3.0/base');
        $xw->writeAttribute('xsi:schemaLocation', 'http://schemas.nav.gov.hu/OSA/3.0/data invoiceData.xsd');

        $xw->writeElement('invoiceNumber', (string) $params['invoice_number']);
        $xw->writeElement('invoiceIssueDate', $issueDate);
        $xw->writeElement('completenessIndicator', 'false');

        $xw->startElement('invoiceMain');
        $xw->startElement('invoice');

        $xw->startElement('invoiceHead');
        self::writeSupplierInfo($xw, $supplier);
        self::writeCustomerInfo($xw, $buyer);

        $xw->startElement('invoiceDetail');
        $xw->writeElement('invoiceCategory', 'NORMAL');
        $xw->writeElement('invoiceDeliveryDate', $deliveryDate);
        $xw->writeElement('currencyCode', $currency);
        // A Stock Manager kizárólag HUF-ban számláz — a NAV mégis MINDIG
        // megköveteli az exchangeRate mezőt (nem csak devizás számlánál),
        // HUF esetén 1 értékkel (lásd a NAV saját HUF-mintaszámláját).
        $xw->writeElement('exchangeRate', '1');
        // FONTOS: az InvoiceDetailType XSD-sorrendje szigorú (xs:sequence)
        // — paymentMethod MEGELŐZI paymentDate-et. Élő NAV sandbox
        // manageInvoice hívással igazolt hiba (a korábbi, felcserélt
        // sorrend a NAV szerverén "ABORTED" feldolgozási státuszt és
        // cvc-complex-type.2.4.a XML-validációs hibát okozott).
        if (!empty($params['payment_method'])) {
            $xw->writeElement('paymentMethod', self::mapPaymentMethod($params['payment_method']));
        }
        $xw->writeElement('paymentDate', $paymentDate);
        $xw->writeElement('invoiceAppearance', $appearance);
        $xw->endElement(); // invoiceDetail

        $xw->endElement(); // invoiceHead

        $xw->startElement('invoiceLines');
        $xw->writeElement('mergedItemIndicator', 'false');
        $lineNumber = 0;
        $netTotalsByVat = [];
        $invoiceNetAmount = 0.0;
        $invoiceVatAmount = 0.0;
        foreach ($items as $item) {
            $lineNumber++;
            $qty = (float) $item['qty'];
            $vatRate = (string) $item['vat_rate'];
            $vatPct = is_numeric($vatRate) ? ((float) $vatRate) / 100 : 0.0;
            $grossUnit = (float) $item['unit_price_gross'];
            $netUnit = round($grossUnit / (1 + $vatPct), 2);
            $netTotal = round($netUnit * $qty, 2);
            $grossTotal = round($grossUnit * $qty, 2);
            $vatTotal = round($grossTotal - $netTotal, 2);

            $xw->startElement('line');
            $xw->writeElement('lineNumber', (string) $lineNumber);
            $xw->writeElement('lineExpressionIndicator', 'true');
            $xw->writeElement('lineNatureIndicator', 'PRODUCT');
            $xw->writeElement('lineDescription', (string) $item['name']);
            $xw->writeElement('quantity', self::formatDecimal($qty));
            $xw->writeElement('unitOfMeasure', 'PIECE');
            $xw->writeElement('unitPrice', self::formatDecimal($netUnit));

            $xw->startElement('lineAmountsNormal');
            $xw->startElement('lineNetAmountData');
            $xw->writeElement('lineNetAmount', self::formatDecimal($netTotal));
            $xw->writeElement('lineNetAmountHUF', self::formatDecimal($netTotal));
            $xw->endElement();
            $xw->startElement('lineVatRate');
            $xw->writeElement('vatPercentage', self::formatDecimal($vatPct, 4));
            $xw->endElement();
            $xw->startElement('lineVatData');
            $xw->writeElement('lineVatAmount', self::formatDecimal($vatTotal));
            $xw->writeElement('lineVatAmountHUF', self::formatDecimal($vatTotal));
            $xw->endElement();
            $xw->startElement('lineGrossAmountData');
            $xw->writeElement('lineGrossAmountNormal', self::formatDecimal($grossTotal));
            $xw->writeElement('lineGrossAmountNormalHUF', self::formatDecimal($grossTotal));
            $xw->endElement();
            $xw->endElement(); // lineAmountsNormal

            $xw->endElement(); // line

            $key = self::formatDecimal($vatPct, 4);
            if (!isset($netTotalsByVat[$key])) {
                $netTotalsByVat[$key] = ['net' => 0.0, 'vat' => 0.0, 'gross' => 0.0];
            }
            $netTotalsByVat[$key]['net'] += $netTotal;
            $netTotalsByVat[$key]['vat'] += $vatTotal;
            $netTotalsByVat[$key]['gross'] += $grossTotal;
            $invoiceNetAmount += $netTotal;
            $invoiceVatAmount += $vatTotal;
        }
        $xw->endElement(); // invoiceLines

        $xw->startElement('invoiceSummary');
        $xw->startElement('summaryNormal');
        foreach ($netTotalsByVat as $vatPctKey => $sums) {
            $xw->startElement('summaryByVatRate');
            $xw->startElement('vatRate');
            $xw->writeElement('vatPercentage', $vatPctKey);
            $xw->endElement();
            $xw->startElement('vatRateNetData');
            $xw->writeElement('vatRateNetAmount', self::formatDecimal($sums['net']));
            $xw->writeElement('vatRateNetAmountHUF', self::formatDecimal($sums['net']));
            $xw->endElement();
            $xw->startElement('vatRateVatData');
            $xw->writeElement('vatRateVatAmount', self::formatDecimal($sums['vat']));
            $xw->writeElement('vatRateVatAmountHUF', self::formatDecimal($sums['vat']));
            $xw->endElement();
            $xw->startElement('vatRateGrossData');
            $xw->writeElement('vatRateGrossAmount', self::formatDecimal($sums['gross']));
            $xw->writeElement('vatRateGrossAmountHUF', self::formatDecimal($sums['gross']));
            $xw->endElement();
            $xw->endElement(); // summaryByVatRate
        }
        $xw->writeElement('invoiceNetAmount', self::formatDecimal($invoiceNetAmount));
        $xw->writeElement('invoiceNetAmountHUF', self::formatDecimal($invoiceNetAmount));
        $xw->writeElement('invoiceVatAmount', self::formatDecimal($invoiceVatAmount));
        $xw->writeElement('invoiceVatAmountHUF', self::formatDecimal($invoiceVatAmount));
        $xw->endElement(); // summaryNormal
        $xw->startElement('summaryGrossData');
        $invoiceGrossAmount = $invoiceNetAmount + $invoiceVatAmount;
        $xw->writeElement('invoiceGrossAmount', self::formatDecimal($invoiceGrossAmount));
        $xw->writeElement('invoiceGrossAmountHUF', self::formatDecimal($invoiceGrossAmount));
        $xw->endElement(); // summaryGrossData
        $xw->endElement(); // invoiceSummary

        $xw->endElement(); // invoice
        $xw->endElement(); // invoiceMain

        $xw->endElement(); // InvoiceData
        $xw->endDocument();

        return $xw->outputMemory();
    }

    private static function writeSupplierInfo(XMLWriter $xw, array $supplier): void
    {
        $xw->startElement('supplierInfo');
        $xw->startElement('supplierTaxNumber');
        $xw->writeElementNs('base', 'taxpayerId', null, self::coreTaxNumber((string) $supplier['tax_number']));
        $xw->endElement();
        $xw->writeElement('supplierName', (string) $supplier['name']);
        $xw->startElement('supplierAddress');
        self::writeDetailedAddress($xw, (string) $supplier['zip'], (string) $supplier['city'], (string) $supplier['address']);
        $xw->endElement(); // supplierAddress
        if (!empty($supplier['bank_account'])) {
            $xw->writeElement('supplierBankAccountNumber', (string) $supplier['bank_account']);
        }
        $xw->endElement(); // supplierInfo
    }

    private static function writeCustomerInfo(XMLWriter $xw, array $buyer): void
    {
        $hasTaxNumber = !empty($buyer['adoszam']);

        $xw->startElement('customerInfo');
        $xw->writeElement('customerVatStatus', $hasTaxNumber ? 'DOMESTIC' : 'PRIVATE_PERSON');
        if ($hasTaxNumber) {
            $xw->startElement('customerVatData');
            $xw->startElement('customerTaxNumber');
            $xw->writeElementNs('base', 'taxpayerId', null, self::coreTaxNumber((string) $buyer['adoszam']));
            $xw->endElement(); // customerTaxNumber
            $xw->endElement(); // customerVatData
        }
        // FONTOS, élő NAV sandbox manageInvoice hívással igazolt üzleti
        // szabály: customerVatStatus=PRIVATE_PERSON esetén a NAV
        // ELUTASÍTJA (ABORTED, "Magánszemély vevő adatai nem adhatóak
        // meg.") a customerName/customerAddress megadását — ez pontosan
        // egyezik a NAV saját hivatalos mintájával is
        // ("Belfoldi termekertekesites maganszemelynek.xml"), amiben a
        // customerInfo KIZÁRÓLAG a customerVatStatus mezőt tartalmazza.
        // Nagy értékű (a törvény szerint azonosítást igénylő) magán-
        // személyes eladásoknál ettől eltérő szabály érvényesülhet — ez
        // Phase 5A hatókörén kívül esik, lásd a záró jelentés KNOWN
        // LIMITATIONS pontját.
        if ($hasTaxNumber) {
            if (!empty($buyer['nev'])) {
                $xw->writeElement('customerName', (string) $buyer['nev']);
            }
            if (!empty($buyer['irsz']) && !empty($buyer['telepules']) && !empty($buyer['cim'])) {
                $xw->startElement('customerAddress');
                self::writeDetailedAddress($xw, (string) $buyer['irsz'], (string) $buyer['telepules'], (string) $buyer['cim']);
                $xw->endElement(); // customerAddress
            }
        }
        $xw->endElement(); // customerInfo
    }

    private static function writeDetailedAddress(XMLWriter $xw, string $zip, string $city, string $freeformStreet): void
    {
        [$streetName, $category, $number] = self::splitAddress($freeformStreet);

        $xw->startElement('base:detailedAddress');
        $xw->writeElement('base:countryCode', 'HU');
        $xw->writeElement('base:postalCode', $zip !== '' ? $zip : '0000');
        $xw->writeElement('base:city', $city !== '' ? $city : 'ismeretlen');
        $xw->writeElement('base:streetName', $streetName);
        $xw->writeElement('base:publicPlaceCategory', $category);
        if ($number !== null && $number !== '') {
            $xw->writeElement('base:number', $number);
        }
        $xw->endElement(); // base:detailedAddress
    }

    /**
     * A NAV a címet streetName + publicPlaceCategory (közterület jellege,
     * pl. "utca"/"tér"/"körút") + number (házszám) bontásban várja — a
     * Stock Manager (Számlázz.hu-val megegyező) "cim" mezője viszont egy
     * szabad szöveg (pl. "Fő utca 1."). Ez a felbontás BEST-EFFORT
     * heurisztika: felismer néhány gyakori magyar közterület-típusszót a
     * szöveg végén, és aköré vágja szét a stringet. Ha nem talál ismert
     * típusszót, a teljes szöveget streetName-be teszi, a
     * publicPlaceCategory-t (ami NAV-kötelező, de SZABAD SZÖVEGES mező,
     * NEM zárt enum) egy semleges "egyéb" értékkel tölti ki — ez
     * schema-valid, de nem feltétlenül pontos. Éles, nem teszt-adatra ezt
     * érdemes lesz egy strukturáltabb cím-beviteli mezővel kiváltani.
     */
    private static function splitAddress(string $freeText): array
    {
        $freeText = trim($freeText);
        if ($freeText === '') {
            return ['ismeretlen', 'egyéb', null];
        }

        $categories = ['utca', 'út', 'tér', 'körút', 'sétány', 'park', 'dűlő', 'sor', 'rakpart', 'liget', 'fasor', 'köz', 'sugárút', 'krt', 'u'];
        $pattern = '/^(.+?)\s+(' . implode('|', array_map(static fn ($c) => preg_quote($c, '/'), $categories)) . ')\.?\s*(\S.*)?$/iu';

        if (preg_match($pattern, $freeText, $m)) {
            return [trim($m[1]), mb_strtolower(trim($m[2])), isset($m[3]) ? trim(rtrim($m[3], '. ')) : null];
        }

        return [$freeText, 'egyéb', null];
    }

    private static function mapPaymentMethod(string $method): string
    {
        $normalized = mb_strtolower(trim($method));
        return match (true) {
            str_contains($normalized, 'készpénz'), str_contains($normalized, 'keszpenz'), str_contains($normalized, 'cash') => 'CASH',
            str_contains($normalized, 'kártya'), str_contains($normalized, 'kartya'), str_contains($normalized, 'card') => 'CARD',
            str_contains($normalized, 'utalvány'), str_contains($normalized, 'utalvany'), str_contains($normalized, 'voucher') => 'VOUCHER',
            str_contains($normalized, 'átutalás'), str_contains($normalized, 'atutalas'), str_contains($normalized, 'transfer') => 'TRANSFER',
            default => 'OTHER',
        };
    }

    private static function coreTaxNumber(string $raw): string
    {
        return substr(preg_replace('/[^0-9]/', '', $raw), 0, 8);
    }

    private static function formatDecimal(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
