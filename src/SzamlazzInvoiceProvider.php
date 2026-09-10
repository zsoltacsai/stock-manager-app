<?php

require_once __DIR__ . '/InvoiceProviderInterface.php';
require_once __DIR__ . '/SzamlazzClient.php';

/**
 * A meglévő, VÁLTOZATLAN SzamlazzClient vékony becsomagolása az új
 * InvoiceProviderInterface mögé. A tényleges számlázási logika
 * (tryClaimInvoiceIssuance()/attachInvoiceToSale() atomikus foglalás,
 * a SzamlazzClient::createInvoice() hívás maga) BYTE-AZONOS azzal, ami
 * korábban közvetlenül sale.php-ban / webshop-order-invoice.php-ban /
 * webshop-order-confirm.php-ban volt — csak most egy helyen él, és a
 * siker/hiba után egy másodlagos, szolgáltató-független tükör-
 * bejegyzést is ír az `invoices` táblába (lásd
 * Database::upsertInvoiceMirror()), hogy egy jövőbeli, egységes "Kimenő
 * számlák" nézet a NAV-számlákkal egy helyen tudja mutatni.
 */
class SzamlazzInvoiceProvider implements InvoiceProviderInterface
{
    private array $config;

    public function __construct(array $szamlazzConfig)
    {
        $this->config = $szamlazzConfig;
    }

    public function isAsync(): bool
    {
        return false;
    }

    /**
     * Várt $context kulcsok: db (Database), sale_id (int), buyer
     * (tömb), items (tömb — name/qty/unit_price_gross/vat_rate),
     * language (?string), payment_method (?string), totals (tömb —
     * net/vat/gross/currency, a tükör-bejegyzéshez).
     */
    public function issueSync(array $context): array
    {
        /** @var Database $db */
        $db = $context['db'];
        $saleId = (int) $context['sale_id'];

        // P1-5: ha egy KORÁBBI kísérlet bizonytalan kimenetellel zárult
        // (a Számlázz.hu válasza elveszett — lásd lent), a sale
        // 'invoice_uncertain' állapotban van, és tryClaimInvoiceIssuance()
        // STRUKTURÁLISAN kizárja az automatikus újra-claim-elést. Ez az
        // előzetes ellenőrzés csak egy világosabb, specifikus hibaüzenetet
        // ad — a tényleges védelmet a claim WHERE-feltétele adja, ez az
        // olvasás csupán advisory (ugyanaz az elv, mint a queue-claim
        // mintáknál máshol az appban).
        $sale = $db->getSaleWithItems($saleId);
        if ($sale !== null && ($sale['status'] ?? '') === 'invoice_uncertain') {
            return [
                'success' => false, 'invoice_number' => null, 'pdf_path' => null,
                'error' => 'A korábbi számlázási kísérlet kimenetele bizonytalan (a Számlázz.hu válasza elveszett) — admin kézi ellenőrzése/feloldása szükséges, mielőtt új kísérlet indulhatna.',
                'uncertain' => true,
            ];
        }

        // Atomikus foglalás a Számlázz.hu hívás ELŐTT — lásd
        // Database::tryClaimInvoiceIssuance() docblockja. Ugyanaz a minta,
        // mint korábban közvetlenül a hívó végpontokban volt.
        if (!$db->tryClaimInvoiceIssuance($saleId)) {
            // 'already_in_progress' — opcionális, plusz jelző a szokásos
            // success/invoice_number/pdf_path/error mellett, KIFEJEZETTEN
            // azért, hogy egy hívó (lásd webshop-order-invoice.php) meg
            // tudja különböztetni ezt az esetet egy VALÓDI Számlázz.hu-s
            // hibától, és — ha korábban is így volt — vissza tudjon adni
            // rá saját, specifikus HTTP-választ (pl. 409), anélkül hogy
            // string-alapú hibaüzenet-egyezésre kellene támaszkodnia.
            return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => 'A számla kiállítása már folyamatban van.', 'already_in_progress' => true];
        }

        $szamlazz = new SzamlazzClient($this->config);
        try {
            $result = $szamlazz->createInvoice(
                $context['buyer'],
                $context['items'],
                (string) $saleId,
                $context['language'] ?? null,
                $context['payment_method'] ?? null
            );
        } catch (Throwable $e) {
            // P1-5, KULCSFONTOSSÁGÚ MEGKÜLÖNBÖZTETÉS: a SzamlazzClient
            // KIZÁRÓLAG akkor dob kifelé (lásd SzamlazzClient::postXml()),
            // ha a HTTP-hívásra EGYÁLTALÁN nem érkezett válasz (curl-szintű
            // transport-hiba) — minden EGYÉB kimenetet (sikeres kiállítás,
            // VAGY a Számlázz.hu által explicit visszautasított kérés,
            // szlahu_error fejléccel) egy sima, visszaadott tömbként közöl
            // (lásd handleResponse()), sose kivétellel. Egy idekerülő
            // kivétel tehát MINDIG "nem tudni, a Számlázz.hu megkapta-e a
            // kérést" — NEM szabad ugyanúgy kezelni, mint egy megerősített
            // elutasítást (a korábbi hiba pontosan ez volt: mindkettő
            // csendben 'invoice_failed'-ként végződött, ami azonnali
            // automatikus újrapróbálkozást engedett, és egy elveszett-
            // válaszos esetben VALÓS duplikált számlát kockáztatott).
            $db->markSaleInvoiceUncertain($saleId, $e->getMessage());
            try {
                $totals = $context['totals'] ?? [];
                $db->upsertInvoiceMirror(
                    $saleId, 'szamlazz', false, null, null, $e->getMessage(),
                    (float) ($totals['net'] ?? 0.0), (float) ($totals['vat'] ?? 0.0),
                    (float) ($totals['gross'] ?? 0.0), (string) ($totals['currency'] ?? 'HUF'),
                    'uncertain_manual'
                );
            } catch (Throwable $mirrorError) {
                // Lásd lent — a tükör-bejegyzés írási hibája sose írhatja
                // felül a fő eredményt.
            }
            return [
                'success' => false, 'invoice_number' => null, 'pdf_path' => null,
                'error' => $e->getMessage(), 'uncertain' => true,
            ];
        }

        // Ide csak a KÉT DEFINITÍV kimenet jut el: sikeres kiállítás, vagy
        // a Számlázz.hu által ténylegesen megválaszolt (tehát megerősítve
        // ELUTASÍTOTT) kérés — mindkettő biztonságosan retry-elhető, ha
        // sikertelen, mert TUDJUK, hogy nem jött létre számla.
        $db->attachInvoiceToSale(
            $saleId,
            $result['invoice_number'] ?? null,
            $result['pdf_path'] ?? null,
            $result['success'] ? 'completed' : 'invoice_failed'
        );

        try {
            $totals = $context['totals'] ?? [];
            $db->upsertInvoiceMirror(
                $saleId,
                'szamlazz',
                (bool) $result['success'],
                $result['invoice_number'] ?? null,
                $result['pdf_path'] ?? null,
                $result['error'] ?? null,
                (float) ($totals['net'] ?? 0.0),
                (float) ($totals['vat'] ?? 0.0),
                (float) ($totals['gross'] ?? 0.0),
                (string) ($totals['currency'] ?? 'HUF')
            );
        } catch (Throwable $e) {
            // A tükör-bejegyzés írása SOSE hiúsíthatja meg / módosíthatja a
            // már ténylegesen megtörtént (vagy sikertelen) Számlázz.hu-s
            // számlázás eredményét — legfeljebb az egységesített "Kimenő
            // számlák" nézet marad emiatt hiányos egy sorral, amíg valaki
            // ki nem javítja a hiba okát.
        }

        return $result;
    }

    public function enqueue(Database $db, int $saleId, array $context): void
    {
        throw new RuntimeException('A Számlázz.hu szinkron szolgáltató, az enqueue() nem alkalmazható rá.');
    }
}
