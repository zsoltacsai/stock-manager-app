<?php

/**
 * Egy magyar cég bejegyzett nevének/címének lekérdezése adószám alapján a
 * NAV Online Számla API queryTaxpayer műveletén keresztül. Ugyanezt az API-t
 * használja a legtöbb magyar számlázó/könyvelő szoftver is — de ehhez saját
 * NAV "technikai felhasználó" hitelesítő adatok kellenek, amit egyszeri,
 * ingyenes lépésben lehet beállítani a NAV Online Számla portálon (lásd a
 * README "NAV cégadat-lekérdezés" szakaszát).
 *
 * FONTOS: ez az integráció nincs élő NAV-fiókon letesztelve (nem állt
 * rendelkezésre teszt hitelesítő adat a fejlesztéskor) — a kérés-aláírási
 * lépések a NAV nyilvános specifikációját követik, de érdemes leellenőrizni
 * a NAV teszt-rendszerén (api-test.onlineszamla.nav.gov.hu), mielőtt élesben
 * hagyatkoznál rá, és szükség esetén itt módosítani, ha a NAV tényleges
 * válasza máshogy néz ki, mint amit lentebb feldolgoz.
 */
class NavTaxpayerLookup
{
    private string $login;
    private string $password;
    private string $signerKey;
    private string $exchangeKey;
    private string $ownTaxNumber;
    private string $baseUrl;

    public function __construct(array $cfg)
    {
        $this->login = $cfg['nav_login'];
        $this->password = $cfg['nav_password'];
        $this->signerKey = $cfg['nav_signer_key'];
        $this->exchangeKey = $cfg['nav_exchange_key'] ?? '';
        $this->ownTaxNumber = $cfg['nav_tax_number'];
        $this->baseUrl = !empty($cfg['nav_test_mode'])
            ? 'https://api-test.onlineszamla.nav.gov.hu/invoiceService/v3/queryTaxpayer'
            : 'https://api.onlineszamla.nav.gov.hu/invoiceService/v3/queryTaxpayer';
    }

    public function lookup(string $taxNumber): array
    {
        $taxNumber = preg_replace('/[^0-9]/', '', $taxNumber);
        $taxNumberCore = substr($taxNumber, 0, 8);

        if (strlen($taxNumberCore) !== 8) {
            return ['found' => false, 'error' => 'Érvénytelen adószám formátum.'];
        }

        $requestId = 'SM' . date('YmdHis') . rand(1000, 9999);
        $timestamp = gmdate('Y-m-d\TH:i:s.000\Z');
        $timestampForSignature = gmdate('YmdHis');

        $requestSignature = strtoupper(hash('sha3-512', $requestId . $timestampForSignature . $this->signerKey));
        $passwordHash = strtoupper(hash('sha512', $this->password));

        $xml = $this->buildRequestXml($requestId, $timestamp, $passwordHash, $requestSignature, $taxNumberCore);

        try {
            $response = $this->post($xml);
        } catch (Throwable $e) {
            return ['found' => false, 'error' => $e->getMessage()];
        }

        return $this->parseResponse($response);
    }

    private function buildRequestXml(string $requestId, string $timestamp, string $passwordHash, string $signature, string $taxNumber): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<QueryTaxpayerRequest xmlns="http://schemas.nav.gov.hu/OSA/3.0/api" xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common">
    <common:header>
        <common:requestId>{$requestId}</common:requestId>
        <common:timestamp>{$timestamp}</common:timestamp>
        <common:requestVersion>3.0</common:requestVersion>
        <common:headerVersion>1.0</common:headerVersion>
    </common:header>
    <common:user>
        <common:login>{$this->login}</common:login>
        <common:passwordHash cryptoType="SHA-512">{$passwordHash}</common:passwordHash>
        <common:taxNumber>{$this->ownTaxNumber}</common:taxNumber>
        <common:requestSignature cryptoType="SHA3-512">{$signature}</common:requestSignature>
    </common:user>
    <common:software>
        <common:softwareId>STOCKMANAGER0000001</common:softwareId>
        <common:softwareName>StockManager</common:softwareName>
        <common:softwareOperation>LOCAL_SOFTWARE</common:softwareOperation>
        <common:softwareMainVersion>1.0</common:softwareMainVersion>
        <common:softwareDevName>Fountainbridge</common:softwareDevName>
        <common:softwareDevContact>info@example.com</common:softwareDevContact>
    </common:software>
    <taxNumber>{$taxNumber}</taxNumber>
</QueryTaxpayerRequest>
XML;
    }

    private function post(string $xml): string
    {
        $ch = curl_init($this->baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/xml'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            throw new RuntimeException("NAV lekérdezési hiba ($status): $response");
        }
        return $response;
    }

    private function parseResponse(string $xmlString): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        if ($xml === false) {
            return ['found' => false, 'error' => 'A NAV válasza nem értelmezhető.'];
        }

        $xml->registerXPathNamespace('a', 'http://schemas.nav.gov.hu/OSA/3.0/api');
        $validity = $xml->xpath('//a:taxpayerValidity');
        if (empty($validity) || (string) $validity[0] !== 'true') {
            return ['found' => false, 'error' => 'Ezzel az adószámmal nem található érvényes cég.'];
        }

        $name = (string) ($xml->xpath('//a:taxpayerName')[0] ?? '');
        $zip = (string) ($xml->xpath('//a:postalCode')[0] ?? '');
        $city = (string) ($xml->xpath('//a:city')[0] ?? '');
        $streetName = (string) ($xml->xpath('//a:streetName')[0] ?? '');
        $publicPlaceCategory = (string) ($xml->xpath('//a:publicPlaceCategory')[0] ?? '');
        $number = (string) ($xml->xpath('//a:number')[0] ?? '');

        $address = trim("$streetName $publicPlaceCategory $number.");

        return [
            'found'   => true,
            'name'    => $name,
            'zip'     => $zip,
            'city'    => $city,
            'address' => $address,
        ];
    }
}
