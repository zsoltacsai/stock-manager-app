<?php

class SzamlazzClient
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function createInvoice(array $buyer, array $items, string $externalId = '', ?string $languageOverride = null, ?string $paymentMethodOverride = null): array
    {
        $xml = $this->buildInvoiceXml($buyer, $items, $externalId, $languageOverride, $paymentMethodOverride);

        $tmpXmlFile = tempnam(sys_get_temp_dir(), 'szamla_') . '.xml';
        file_put_contents($tmpXmlFile, $xml);

        try {
            [$headers, $body] = $this->postXml($tmpXmlFile);
        } finally {
            @unlink($tmpXmlFile);
        }

        return $this->handleResponse($headers, $body);
    }

    /**
     * Jóváíró számlát állít ki részleges vagy teljes visszáruhoz, egy
     * vadonatúj számla létrehozásával, a visszavett tételeken NEGATÍV
     * mennyiséggel.
     *
     * MEGJEGYZÉS: ez egy legjobb-erőfeszítés jellegű megoldás. A
     * Számlázz.hu Számla Agentje külön "sztornó" (teljes érvénytelenítés)
     * műveletet és "helyesbítő számla" fogalmat is kínál, ami közvetlenül
     * hivatkozik az eredeti számlaszámra — ezek a könyvelésed
     * szempontjából helyesebbek lehetnek egy negatív mennyiségű számlánál,
     * attól függően, hogy a könyvelőd hogyan szeretné kezelni a visszárut.
     * Ellenőrizd ezt a Számlázz.hu aktuális dokumentációjával és a
     * könyvelőd elvárásaival, mielőtt éles könyvelésben hagyatkoznál rá.
     *
     * @param array $items lista ['name','qty','unit_price_gross','vat_rate'] elemekből — a qty legyen POZITÍV, belül negálódik
     */
    public function createCreditNote(array $buyer, array $items, string $externalId = ''): array
    {
        $negatedItems = array_map(static function (array $item): array {
            $item['qty'] = -abs((float) $item['qty']);
            return $item;
        }, $items);

        return $this->createInvoice($buyer, $negatedItems, $externalId);
    }

    private function buildInvoiceXml(array $buyer, array $items, string $externalId, ?string $languageOverride = null, ?string $paymentMethodOverride = null): string
    {
        $cfg = $this->cfg;
        $today = date('Y-m-d');

        $xw = new XMLWriter();
        $xw->openMemory();
        $xw->startDocument('1.0', 'UTF-8');

        $xw->startElementNs(null, 'xmlszamla', 'http://www.szamlazz.hu/xmlszamla');
        $xw->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');
        $xw->writeAttribute(
            'xsi:schemaLocation',
            'http://www.szamlazz.hu/xmlszamla https://www.szamlazz.hu/szamla/docs/xsds/agent/xmlszamla.xsd'
        );

        $xw->startElement('beallitasok');
        $xw->writeElement('szamlaagentkulcs', $cfg['agent_key']);
        $xw->writeElement('eszamla', $cfg['e_invoice'] ? 'true' : 'false');
        $xw->writeElement('szamlaLetoltes', $cfg['download_pdf'] ? 'true' : 'false');
        $xw->writeElement('valaszVerzio', '1');
        $xw->writeElement('szamlaKulsoAzon', $externalId);
        $xw->endElement();

        $xw->startElement('fejlec');
        $xw->writeElement('keltDatum', $today);
        $xw->writeElement('teljesitesDatum', $today);
        $xw->writeElement('fizetesiHataridoDatum', $today);
        $xw->writeElement('fizmod', $paymentMethodOverride ?: $cfg['payment_method']);
        $xw->writeElement('penznem', $cfg['currency']);
        $xw->writeElement('szamlaNyelve', $languageOverride ?: $cfg['language']);
        $xw->writeElement('megjegyzes', 'Helyben történt eladás (till sale)');
        $xw->writeElement('rendelesSzam', $externalId);
        $xw->writeElement('elolegszamla', 'false');
        $xw->writeElement('vegszamla', 'false');
        $xw->writeElement('helyesbitoszamla', 'false');
        $xw->writeElement('dijbekero', 'false');
        $xw->endElement();

        $xw->startElement('elado');
        $xw->endElement();

        $xw->startElement('vevo');
        $xw->writeElement('nev', $buyer['nev']);
        if (!empty($buyer['orszag'])) {
            $xw->writeElement('orszag', $buyer['orszag']);
        }
        $xw->writeElement('irsz', $buyer['irsz']);
        $xw->writeElement('telepules', $buyer['telepules']);
        $xw->writeElement('cim', $buyer['cim']);
        if (!empty($buyer['email'])) {
            $xw->writeElement('email', $buyer['email']);
            $xw->writeElement('sendEmail', $cfg['send_email'] ? 'true' : 'false');
        }
        if (!empty($buyer['adoszam'])) {
            $xw->writeElement('adoszam', $buyer['adoszam']);
        }
        if (!empty($buyer['megjegyzes'])) {
            $xw->writeElement('megjegyzes', $buyer['megjegyzes']);
        }
        $xw->endElement();

        $xw->startElement('tetelek');
        foreach ($items as $item) {
            $qty       = (float) $item['qty'];
            $vatRate   = (string) $item['vat_rate'];
            $vatPct    = is_numeric($vatRate) ? ((float) $vatRate) / 100 : 0.0;

            $grossUnit = (float) $item['unit_price_gross'];
            $netUnit   = is_numeric($vatRate) ? round($grossUnit / (1 + $vatPct), 2) : $grossUnit;

            $netTotal   = round($netUnit * $qty, 2);
            $grossTotal = round($grossUnit * $qty, 2);
            $vatTotal   = round($grossTotal - $netTotal, 2);

            $xw->startElement('tetel');
            $xw->writeElement('megnevezes', $item['name']);
            $xw->writeElement('mennyiseg', (string) $qty);
            $xw->writeElement('mennyisegiEgyseg', $cfg['unit_label']);
            $xw->writeElement('nettoEgysegar', (string) $netUnit);
            $xw->writeElement('afakulcs', $vatRate);
            $xw->writeElement('nettoErtek', (string) $netTotal);
            $xw->writeElement('afaErtek', (string) $vatTotal);
            $xw->writeElement('bruttoErtek', (string) $grossTotal);
            $xw->endElement();
        }
        $xw->endElement();

        $xw->endElement();
        $xw->endDocument();

        return $xw->outputMemory();
    }

    private function postXml(string $xmlFilePath): array
    {
        $ch = curl_init($this->cfg['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'action-xmlagentxmlfile' => new CURLFile($xmlFilePath, 'text/xml', 'invoice.xml'),
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Szamlazz.hu request failed: $err");
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $body       = substr($raw, $headerSize);

        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        return [$headers, $body];
    }

    private function handleResponse(array $headers, string $body): array
    {
        if (!empty($headers['szlahu_error'])) {
            return [
                'success'        => false,
                'invoice_number' => null,
                'pdf_path'       => null,
                'error'          => urldecode($headers['szlahu_error']),
            ];
        }

        $invoiceNumber = isset($headers['szlahu_szamlaszam'])
            ? urldecode($headers['szlahu_szamlaszam'])
            : null;

        if (str_starts_with($body, '%PDF')) {
            @mkdir($this->cfg['pdf_dir'], 0775, true);
            $safeNumber = $invoiceNumber ? preg_replace('/[^A-Za-z0-9_-]/', '_', $invoiceNumber) : date('Ymd_His');
            $path = rtrim($this->cfg['pdf_dir'], '/') . '/invoice_' . $safeNumber . '.pdf';
            file_put_contents($path, $body);

            return [
                'success'        => true,
                'invoice_number' => $invoiceNumber,
                'pdf_path'       => $path,
                'error'          => null,
            ];
        }

        $text = trim($body);

        if (str_starts_with($text, 'xmlagentresponse=DONE')) {
            return [
                'success'        => true,
                'invoice_number' => $invoiceNumber ?? trim(explode(';', $text, 2)[1] ?? ''),
                'pdf_path'       => null,
                'error'          => null,
            ];
        }

        return [
            'success'        => false,
            'invoice_number' => null,
            'pdf_path'       => null,
            'error'          => $text !== '' ? $text : 'Unknown Szamlazz.hu error',
        ];
    }
}
