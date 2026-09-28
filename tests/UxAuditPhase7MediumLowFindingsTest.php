<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Phase 7 UX audit (ui-ux-audit-phase7-2026-09-27.md) — regressziós tesztek
 * a MEDIUM (UX-04..UX-09) és LOW (UX-10..UX-13) findingek javításaihoz.
 * A CRITICAL/HIGH findingek (UX-01, UX-02, UX-03) saját, dedikált teszt-
 * fájlokban vannak (CashSessionSaleEnforcementHttpTest, StockTakeNavigationTest,
 * és az app.js-beli UX-01 javítás browseres, élőben ellenőrzött).
 *
 * A tisztán kliens-oldali JS-viselkedést (nincs böngésző a PHPUnit alatt)
 * statikus forráskód-ellenőrzéssel bizonyítjuk — ugyanaz a minta, amit a
 * StockTakeNavigationTest is használ a UX-03-hoz.
 */
final class UxAuditPhase7MediumLowFindingsTest extends TestCase
{
    private function webroot(string $rel): string
    {
        return dirname(__DIR__) . '/webroot/' . $rel;
    }

    private function src(string $file): string
    {
        return (string) file_get_contents($this->webroot($file));
    }

    /** Sortörésenkénti "// ..." komment eltávolítása, hogy egy magyarázó
     *  szöveg ("... natív alert() helyett ...") ne hamisítsa a "nincs
     *  többé alert()-hívás" ellenőrzést. */
    private function srcWithoutLineComments(string $file): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $this->src($file));
        $stripped = array_map(static fn (string $line): string => preg_replace('#^\s*//.*$#', '', $line), $lines);
        return implode("\n", $stripped);
    }

    // ------------------------------------------------------------------
    // UX-04 — kupon/utalvány hibaüzenet a mező közelében, nem a távoli
    // #checkout-feedback-ben.
    // ------------------------------------------------------------------

    public function testCouponFeedbackElementExistsNearTheCouponFields(): void
    {
        $html = $this->src('index.php');
        $this->assertMatchesRegularExpression(
            '/id="coupon-code".*?id="gift-card-code".*?id="coupon-feedback"/s',
            $html,
            'A #coupon-feedback elemnek a kupon/utalvány mezők UTÁN, közvetlen közelében kell lennie (UX-04).'
        );
    }

    public function testCouponAndGiftCardErrorsAreWrittenToTheDedicatedFeedbackElement(): void
    {
        $js = $this->src('app.js');
        $this->assertStringNotContainsString(
            "checkoutFeedback.textContent = data.error || 'A kupon nem érvényes.'",
            $js,
            'A kupon-hiba ne a távoli #checkout-feedback-be íródjon (UX-04 regresszió).'
        );
        $this->assertStringContainsString(
            "couponFeedback.textContent = data.error || 'A kupon nem érvényes.'",
            $js
        );
        $this->assertStringContainsString(
            "couponFeedback.textContent = data.error || 'Az utalvány nem érvényes.'",
            $js
        );
    }

    // ------------------------------------------------------------------
    // UX-05 — a visszáru sikerüzenete túlélje a re-render/openDetail()-t.
    // ------------------------------------------------------------------

    public function testReturnSuccessMessageIsPassedThroughTheDetailRefresh(): void
    {
        $js = $this->src('eladasok.js');
        $this->assertMatchesRegularExpression(
            '/openDetail\(sale\.id,\s*`Visszáru rögzítve/',
            $js,
            'A visszáru sikerüzenetnek túl kell élnie az openDetail() általi re-rendert (UX-05).'
        );
        $this->assertStringContainsString('async function openDetail(id, successMessage)', $js);
        $this->assertStringContainsString('successMessage ? `<p class="feedback ok"', $js);
    }

    // ------------------------------------------------------------------
    // UX-06 — néma lista-csonkolás jelzése (screen vs. total).
    // ------------------------------------------------------------------

    public function testCountSalesReflectsTheFullMatchCountIndependentlyOfTheListLimit(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct([
            'name' => 'UX-06 teszt termék', 'unit' => 'db', 'vat_rate' => '27',
            'net_price' => 1000, 'price' => 1270, 'barcode' => null,
        ]);
        $db->incrementStock($productId, 100);

        for ($i = 0; $i < 5; $i++) {
            $saleId = $db->insertSale(1270.0, 'Készpénz');
            $db->insertSaleItem($saleId, [
                'product_id' => $productId, 'name' => 'UX-06 teszt termék', 'qty' => 1,
                'unit_price' => 1270, 'vat_rate' => '27',
            ]);
        }

        // A lista (kis limittel, mintha csonkolt lenne) kevesebb sort adjon
        // vissza, mint a valódi összes találat (count) — pontosan ez a
        // különbség, amit a UI a "300 / 1240 eladás" jelzéshez használ.
        $limited = $db->listSales([], 2);
        $total = $db->countSales([]);
        $this->assertCount(2, $limited);
        $this->assertGreaterThanOrEqual(5, $total);
        $this->assertGreaterThan(count($limited), $total, 'A total-nak nagyobbnak kell lennie a csonkolt listánál, hogy a UI jelezni tudja a csonkolást.');
    }

    public function testCountPurchasesReflectsTheFullMatchCountIndependentlyOfTheListLimit(): void
    {
        $db = tests_new_database();
        for ($i = 0; $i < 4; $i++) {
            $db->recordPurchase(
                ['supplier_name' => 'UX-06 Teszt Beszállító', 'payment_method' => 'Készpénz'],
                [],
                'ux06-purchase-' . $i
            );
        }

        $limited = $db->listPurchases([], 1);
        $total = $db->countPurchases([]);
        $this->assertCount(1, $limited);
        $this->assertGreaterThanOrEqual(4, $total);
        $this->assertGreaterThan(count($limited), $total);
    }

    public function testSalesListAndPurchasesListEndpointsExposeATotalField(): void
    {
        $salesEndpoint = $this->src('api/sales-list.php');
        $this->assertStringContainsString("'total' => \$db->countSales(\$filters)", $salesEndpoint);

        $purchasesEndpoint = $this->src('api/purchases-list.php');
        $this->assertStringContainsString("'total' => \$db->countPurchases(\$filters)", $purchasesEndpoint);
    }

    public function testSalesAndPurchasesListPagesShowATruncationNoticeWhenTotalExceedsTheReturnedCount(): void
    {
        $eladasokJs = $this->src('eladasok.js');
        $this->assertMatchesRegularExpression('/total\s*>\s*sales\.length/', $eladasokJs);
        $this->assertStringContainsString('szűrj a teljes listához', $eladasokJs);

        $beszerzesekJs = $this->src('beszerzesek.js');
        $this->assertMatchesRegularExpression('/total\s*>\s*purchases\.length/', $beszerzesekJs);
        $this->assertStringContainsString('szűrj a teljes listához', $beszerzesekJs);
    }

    // ------------------------------------------------------------------
    // UX-07 — telephelyi/globális készlet-eltérés magyarázata.
    // ------------------------------------------------------------------

    public function testStockTransferPageExplainsALocationVsGlobalStockMismatch(): void
    {
        $js = $this->src('telephelyek.js');
        $this->assertStringContainsString('locationTotal !== globalStockQty', $js);
        $this->assertStringContainsString('eltér az összesített készlettől', $js);
    }

    // ------------------------------------------------------------------
    // UX-08 — betöltés-jelzés konzisztencia a lista-oldalakon.
    // ------------------------------------------------------------------

    public function testSalesPurchasesAndInvoiceListsShowALoadingIndicatorWhileFetching(): void
    {
        foreach (['eladasok.js', 'beszerzesek.js', 'kimeno-szamlak.js'] as $file) {
            $js = $this->src($file);
            $this->assertStringContainsString('Betöltés...', $js, "$file-nek jeleznie kell a betöltést (UX-08).");
        }
    }

    // ------------------------------------------------------------------
    // UX-09 — a "Kimenő számlák" ikonja ne legyen azonos/nagyon hasonló a
    // "Beérkezett számlák" ikonjával.
    // ------------------------------------------------------------------

    public function testOutgoingAndIncomingInvoiceSidebarIconsAreVisuallyDistinct(): void
    {
        $sidebar = $this->src('sidebarmenu.php');
        $this->assertMatchesRegularExpression("/'href'\\s*=>\\s*'kimeno-szamlak\\.php'/", $sidebar);
        // A korábbi ikon ugyanazzal a "dokumentum" alap-path-tal kezdődött,
        // mint a "Beérkezett számlák" ikonja — ez a path most már ne
        // szerepeljen a Kimenő számlák bejegyzésében.
        $this->assertDoesNotMatchRegularExpression(
            "/'href'\\s*=>\\s*'kimeno-szamlak\\.php'[^\\]]*'icon'\\s*=>\\s*'<path d=\"M14 2H6/s",
            $sidebar,
            'A Kimenő számlák ikonja ne ossza a dokumentum-alapú path-ot a Beérkezett számlákéval (UX-09).'
        );
    }

    // ------------------------------------------------------------------
    // UX-10 — Pénztárgépek kereszthivatkozás a Telephelyek oldalról.
    // ------------------------------------------------------------------

    public function testLocationsPageLinksToCashRegisterAdministration(): void
    {
        $html = $this->src('telephelyek.php');
        $this->assertStringContainsString('href="penztargepek.php"', $html);
    }

    // ------------------------------------------------------------------
    // UX-11 — maszkolt PIN modal a mentés-visszaállításhoz, dupla-kattintás
    // elleni védelem.
    // ------------------------------------------------------------------

    public function testBackupRestorePinIsRequestedThroughAMaskedInputModalNotNativePrompt(): void
    {
        $html = $this->src('beallitasok.php');
        $this->assertStringContainsString('id="pin-confirm-modal"', $html);
        $this->assertMatchesRegularExpression('/id="pin-confirm-input"[^>]*type="password"|type="password"[^>]*id="pin-confirm-input"/', $html);

        $js = $this->src('topbar.js');
        $this->assertStringNotContainsString(
            "prompt('Vezetői PIN megerősítéshez",
            $js,
            'A vezetői PIN bekérése ne natív prompt()-tal történjen (UX-11).'
        );
        $this->assertStringContainsString('function askPinForRestore(', $js);
    }

    public function testBackupRestoreTriggersAreGuardedAgainstDoubleSubmit(): void
    {
        $js = $this->src('topbar.js');
        $this->assertStringContainsString('if (triggerBtn) triggerBtn.disabled = true;', $js);
        $this->assertStringContainsString('if (restoreFileBtn.disabled) return;', $js);
    }

    // ------------------------------------------------------------------
    // UX-12 — natív alert() lecserélése az app inline visszajelzésére.
    // ------------------------------------------------------------------

    public function testFlaggedFilesNoLongerUseNativeAlert(): void
    {
        foreach (['kliensek.js', 'leltar.js', 'termekek.js', 'vasarlok.js'] as $file) {
            $js = $this->srcWithoutLineComments($file);
            $this->assertDoesNotMatchRegularExpression('/[^.]\balert\(/', $js, "$file ne használjon natív alert()-et (UX-12).");
        }
        $topbarJs = $this->srcWithoutLineComments('topbar.js');
        $this->assertDoesNotMatchRegularExpression('/[^.]\balert\(/', $topbarJs, 'topbar.js ne használjon natív alert()-et (UX-12).');
        $this->assertStringContainsString('window.showToast = showToast;', $this->src('topbar.js'));
    }

    // ------------------------------------------------------------------
    // UX-13 — élő figyelmeztetés negatív/érvénytelen árra gépelés közben.
    // ------------------------------------------------------------------

    public function testProductPriceFieldsWarnLiveOnInvalidValues(): void
    {
        $js = $this->src('product-modal.js');
        $this->assertStringContainsString('function checkPriceWarning()', $js);
        $this->assertMatchesRegularExpression("/pNet\\.addEventListener\\('input', \\(\\) => \\{\\s*checkPriceWarning\\(\\);/", $js);
        $this->assertMatchesRegularExpression("/pGross\\.addEventListener\\('input', \\(\\) => \\{\\s*checkPriceWarning\\(\\);/", $js);

        foreach (['termekek.php', 'beszerzes.php'] as $file) {
            $html = $this->src($file);
            $this->assertStringContainsString('id="p-price-warning"', $html, "$file-nek tartalmaznia kell a #p-price-warning elemet (UX-13).");
        }
    }
}
