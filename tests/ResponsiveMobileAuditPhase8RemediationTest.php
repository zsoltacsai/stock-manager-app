<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Phase 8 responsive/mobile audit (responsive-mobile-audit-phase8-2026-09-28.md)
 * remediáció — regressziós tesztek RESP-01 (HIGH) .. RESP-08 (LOW).
 *
 * A böngésző-viselkedést (médialekérdezések tényleges alkalmazása,
 * elrendezés) PHPUnit alatt nem lehet valódi layout-motorral igazolni —
 * ezekhez a jelentés browser-validation szakasza tartalmazza a bizonyítékot
 * (élő emulált-viewport tesztelés). Ez a fájl azt igazolja statikusan, amit
 * lehet: a szükséges HTML/CSS/JS elemek, osztályok és bekötések ténylegesen
 * jelen vannak-e a forrásban, ugyanazzal a mintával, mint a Phase 7
 * UxAuditPhase7MediumLowFindingsTest.php.
 */
final class ResponsiveMobileAuditPhase8RemediationTest extends TestCase
{
    private function webroot(string $rel): string
    {
        return dirname(__DIR__) . '/webroot/' . $rel;
    }

    private function src(string $file): string
    {
        return (string) file_get_contents($this->webroot($file));
    }

    private function srcWithoutLineComments(string $file): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $this->src($file));
        $stripped = array_map(static fn (string $line): string => (string) preg_replace('#^\s*//.*$#', '', $line), $lines);
        return implode("\n", $stripped);
    }

    /** sidebarmenu.php-t önmagában rendereli ki egy adott "aktuális oldal" kontextusban. */
    private function renderSidebar(string $currentScript): string
    {
        $_SERVER['SCRIPT_NAME'] = '/' . $currentScript;
        ob_start();
        include $this->webroot('sidebarmenu.php');
        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------------
    // RESP-01 (HIGH) — mobil navigáció fallback.
    // ------------------------------------------------------------------

    public function testMobileNavToggleButtonExistsInHeadermenu(): void
    {
        $html = $this->src('headermenu.php');
        $this->assertStringContainsString('id="mobile-nav-toggle"', $html);
        $this->assertStringContainsString('aria-controls="mobile-nav-drawer"', $html);
    }

    public function testMobileNavDrawerAndBackdropExist(): void
    {
        $html = $this->renderSidebar('dashboard.php');
        $this->assertStringContainsString('id="mobile-nav-drawer"', $html);
        $this->assertStringContainsString('id="mobile-nav-backdrop"', $html);
        $this->assertStringContainsString('id="mobile-nav-close"', $html);
    }

    /**
     * A legfontosabb RESP-01 regresszió: a mobil fiók (drawer) minden olyan
     * célpontot tartalmazzon, ami a desktop ikon-sávban (icon-sidebar) is
     * szerepel — Dashboard, mind a 14 $__smSidebarLinks bejegyzés, és
     * Beállítások. Ha valaki a jövőben csak az egyik listát bővíti, ez a
     * teszt elbukik.
     */
    public function testMobileNavDrawerHasTheSameDestinationsAsTheDesktopSidebar(): void
    {
        $html = $this->renderSidebar('dashboard.php');

        // Phase 9 accessibility remediation (SEM-02) óta az icon-sidebar
        // nav-nak saját aria-label-je is van — a mintát ezért kell [^>]*-ra
        // bővíteni, nem az attribútum-sorrendhez/pontos szöveghez kötni.
        preg_match('/<nav class="icon-sidebar"[^>]*>(.*?)<\/nav>/s', $html, $sidebarMatch);
        preg_match('/<nav id="mobile-nav-drawer"[^>]*>(.*?)<\/nav>/s', $html, $drawerMatch);
        $this->assertNotEmpty($sidebarMatch, 'Nem található az icon-sidebar blokk.');
        $this->assertNotEmpty($drawerMatch, 'Nem található a mobile-nav-drawer blokk.');

        preg_match_all('/href="([a-z0-9_-]+\.php)"/i', $sidebarMatch[1], $sidebarHrefs);
        preg_match_all('/href="([a-z0-9_-]+\.php)"/i', $drawerMatch[1], $drawerHrefs);

        $sidebarLinks = array_unique($sidebarHrefs[1]);
        $drawerLinks = array_unique($drawerHrefs[1]);

        $this->assertGreaterThanOrEqual(15, count($sidebarLinks), 'Az icon-sidebar-nak legalább 15 linket kell tartalmaznia (Dashboard + 14).');
        sort($sidebarLinks);
        sort($drawerLinks);
        $this->assertSame(
            $sidebarLinks,
            $drawerLinks,
            'A mobil navigációs fióknak (drawer) PONTOSAN ugyanazokat a célpontokat kell tartalmaznia, mint a desktop ikon-sávnak (RESP-01).'
        );

        // Explicit, névvel is ellenőrizve a Phase 8 audit által kiemelt,
        // korábban mobilon elérhetetlen célpontok — köztük a Phase 7-ben
        // hozzáadott Leltározás.
        foreach (['dashboard.php', 'leltar.php', 'kassza-riport.php', 'vasarlok.php', 'telephelyek.php', 'rendszerallapot.php', 'kliensek.php', 'ai-asszisztens.php', 'kimeno-szamlak.php', 'beerkezett-szamlak.php', 'beallitasok.php'] as $expected) {
            $this->assertContains($expected, $drawerLinks, "A mobil fiókból hiányzik: $expected");
        }
    }

    public function testMobileNavDrawerMarksTheCurrentPageActive(): void
    {
        $html = $this->renderSidebar('termekek.php');
        preg_match('/<nav id="mobile-nav-drawer"[^>]*>(.*?)<\/nav>/s', $html, $drawerMatch);
        $this->assertNotEmpty($drawerMatch);
        $this->assertMatchesRegularExpression(
            '/<a href="termekek\.php" class="mobile-nav-link active">/',
            $drawerMatch[1],
            'Az aktuális oldalnak aktívként kell szerepelnie a mobil fiókban is.'
        );
    }

    public function testIconSidebarScrollsInsteadOfSilentlyClippingInShortViewports(): void
    {
        $css = $this->src('style.css');
        $this->assertMatchesRegularExpression(
            '/\.icon-sidebar\s*\{[^}]*overflow-y:\s*auto/s',
            $css,
            'Az .icon-sidebar-nak overflow-y:auto kell legyen, különben alacsony magasságú (fekvő) viewportnál némán levágódik a lista vége (RESP-01 landscape-eset).'
        );
    }

    public function testMobileNavToggleIsOnlyVisibleAtOrBelowTabletPortraitWidth(): void
    {
        $css = $this->src('style.css');
        $this->assertMatchesRegularExpression(
            '/@media \(max-width:\s*768px\)\s*\{\s*\.mobile-nav-toggle\s*\{\s*display:\s*inline-flex/s',
            $css,
            'A hamburger gomb csak ≤768px szélességnél jelenjen meg — desktopon rejtve kell maradnia.'
        );
    }

    public function testWebshopOrderBadgeUpdatesBothTheDesktopAndMobileCopies(): void
    {
        $js = $this->src('topbar.js');
        $this->assertStringContainsString("document.querySelectorAll('[data-badge-group=\"sidebar-webshop-badge\"]')", $js);
        $html = $this->renderSidebar('dashboard.php');
        $this->assertSame(2, substr_count($html, 'data-badge-group="sidebar-webshop-badge"'), 'Mindkét (desktop + mobil) pötty-span-nek meg kell kapnia a data-badge-group jelölést.');
    }

    // ------------------------------------------------------------------
    // RESP-02 (HIGH) — Árucikkek táblázat mobilon.
    // ------------------------------------------------------------------

    public function testProductsTableHasTheResponsiveCardClass(): void
    {
        $html = $this->src('termekek.php');
        $this->assertMatchesRegularExpression('/<table class="products-table rt-cards"/', $html);
    }

    public function testProductsPageHasMobileSelectAllAndSortControls(): void
    {
        $html = $this->src('termekek.php');
        $this->assertStringContainsString('id="select-all-products-mobile"', $html);
        $this->assertStringContainsString('id="products-mobile-sort"', $html);
        // Legalább a Készlet szerinti rendezésnek jelen kell lennie mindkét
        // irányban — ez a legvalószínűbb, amit egy admin mobilon keres.
        $this->assertStringContainsString('value="stock_qty:asc"', $html);
        $this->assertStringContainsString('value="stock_qty:desc"', $html);
    }

    public function testProductsJsBindsTheMobileSelectAllToTheSameSelectionState(): void
    {
        $js = $this->src('termekek.js');
        $this->assertStringContainsString("document.getElementById('select-all-products-mobile')", $js);
        $this->assertStringContainsString('function bindSelectAll(el)', $js);
        $this->assertStringContainsString('bindSelectAll(selectAllProductsMobile)', $js);
        // A "mind kijelölése" állapot-szinkronizálásnak (checked/indeterminate)
        // mindkét checkboxot érintenie kell, ne csak a desktopot.
        $this->assertMatchesRegularExpression(
            '/\[selectAllProducts,\s*selectAllProductsMobile\]\.forEach/',
            $js
        );
    }

    public function testProductsJsBindsTheMobileSortDropdownToTheSameSortState(): void
    {
        $js = $this->src('termekek.js');
        $this->assertStringContainsString("document.getElementById('products-mobile-sort')", $js);
        $this->assertMatchesRegularExpression(
            "/productsMobileSort\\.addEventListener\\('change'.*?sortColumn = col;\\s*sortDir = dir;/s",
            $js
        );
    }

    public function testProductsRowTemplateLabelsEveryCardCellForMobileDisplay(): void
    {
        $js = $this->src('termekek.js');
        foreach (['Cikkszám', 'Csoport', 'Vonalkód', 'Készlet', 'Nettó Beszerzési ár', 'Nettó Eladási ár', 'Bruttó Eladási ár', 'Webshopban'] as $label) {
            $this->assertStringContainsString("data-label=\"$label\"", $js, "Hiányzik a(z) '$label' mobil kártya-címke a termékek sorsablonjából.");
        }
        $this->assertStringContainsString('class="rt-title"', $js);
        $this->assertStringContainsString('class="rt-actions"', $js);
    }

    // ------------------------------------------------------------------
    // RESP-03 (MEDIUM) — ugyanaz a minta a többi auditált listaoldalon.
    // ------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function resp03PageProvider(): array
    {
        return [
            'Eladások' => ['eladasok.php'],
            'Beszerzések' => ['beszerzesek.php'],
            'Beérkezett számlák' => ['beerkezett-szamlak.php'],
            'Beszállítók' => ['beszallitok.php'],
            'Kassza-riport' => ['kassza-riport.php'],
            'Beszerzési javaslat' => ['beszerzesi-javaslat.php'],
            'Ügyféllista' => ['vasarlok.php'],
        ];
    }

    /**
     * @dataProvider resp03PageProvider
     */
    public function testResp03PageUsesTheSharedResponsiveTableClass(string $file): void
    {
        $html = $this->src($file);
        $this->assertMatchesRegularExpression(
            '/<table class="sample-table rt-cards"/',
            $html,
            "$file nem kapta meg a közös rt-cards responsive táblázat-osztályt (RESP-03)."
        );
    }

    public function testInventoryReportBothTablesUseTheSharedResponsiveTableClass(): void
    {
        $html = $this->src('inventory-report.php');
        $this->assertSame(
            2,
            substr_count($html, '<table class="sample-table rt-cards">'),
            'A Készlet riport mindkét táblázatának (legnagyobb készletértékű + alacsony készletű) kártyás nézetet kell kapnia.'
        );
    }

    public function testBeszerzesiJavaslatMobileSelectAllIsWiredToTheSameSelectionState(): void
    {
        $html = $this->src('beszerzesi-javaslat.php');
        $this->assertStringContainsString('id="bj-select-all-mobile"', $html);
        $js = $this->src('beszerzesi-javaslat.js');
        $this->assertStringContainsString("document.getElementById('bj-select-all-mobile')", $js);
        $this->assertStringContainsString('function bindSelectAll(el)', $js);
    }

    public function testVasarlokMobileSelectAllIsWiredToTheSameSelectionState(): void
    {
        $html = $this->src('vasarlok.php');
        $this->assertStringContainsString('id="select-all-customers-mobile"', $html);
        $js = $this->src('vasarlok.js');
        $this->assertStringContainsString("document.getElementById('select-all-customers-mobile')", $js);
    }

    public function testResponsiveCardCssRespectsTheHiddenUtilityClass(): void
    {
        // Regressziós védelem egy konkrét, menet közben talált hibára: a
        // .rt-cards td { display:flex } specificitása nagyobb, mint a
        // .hidden { display:none }-é — enélkül a szabály nélkül egy
        // "hidden" cella (pl. a Visszáru-mód rejtett oszlopa) kártyás
        // nézetben visszalátszana.
        $css = $this->src('style.css');
        $this->assertMatchesRegularExpression('/\.rt-cards td\.hidden\s*\{\s*display:\s*none/', $css);
    }

    // ------------------------------------------------------------------
    // RESP-04a (MEDIUM) — POS kosár-táblázat.
    // ------------------------------------------------------------------

    public function testPosCartTableHasItsOwnScopedResponsiveClass(): void
    {
        $html = $this->src('index.php');
        $this->assertMatchesRegularExpression('/<table class="cart-table pos-cart-table">/', $html);

        $css = $this->src('style.css');
        $this->assertMatchesRegularExpression('/\.pos-cart-table\s+tr\s*\{[^}]*display:\s*flex/s', $css);
    }

    public function testPurchaseCartTableIsUnaffectedByThePosSpecificFix(): void
    {
        // A Beszerzés (beszerzes.php) 7 oszlopos kosarát az audit nem
        // vizsgálta/igazolta hibásnak — a RESP-04a javítás emiatt
        // SZÁNDÉKOSAN csak a .pos-cart-table-re szűkített, ne a
        // beszerzes.php táblázatára is.
        $html = $this->src('beszerzes.php');
        $this->assertStringNotContainsString('pos-cart-table', $html);
    }

    // ------------------------------------------------------------------
    // RESP-04b (MEDIUM) — modal footer rövid viewporton.
    // ------------------------------------------------------------------

    public function testModalActionsIsStickyPositionedWithinItsScrollingModalCard(): void
    {
        $css = $this->src('style.css');
        $this->assertMatchesRegularExpression(
            '/\.modal-actions\s*\{[^}]*position:\s*sticky;\s*bottom:\s*0/s',
            $css,
            'A .modal-actions gombsornak sticky pozíciójúnak kell lennie, hogy rövid viewportnál ne tűnjön el a látható területről.'
        );
    }

    // ------------------------------------------------------------------
    // RESP-05 (MEDIUM) — nagy összegek a részletező modalokban.
    // ------------------------------------------------------------------

    public function testSaleDetailModalTableUsesTheResponsiveCardClass(): void
    {
        $js = $this->src('eladasok.js');
        $this->assertMatchesRegularExpression('/<table class="sample-table rt-cards">/', $js);
        $this->assertStringContainsString('data-label="Egységár"', $js);
        $this->assertStringContainsString('data-label="Össz."', $js);
    }

    public function testPurchaseDetailModalTableUsesTheResponsiveCardClass(): void
    {
        $js = $this->src('beszerzesek.js');
        $this->assertMatchesRegularExpression('/<table class="sample-table rt-cards">/', $js);
        $this->assertStringContainsString('data-label="Bruttó érték"', $js);
    }

    // ------------------------------------------------------------------
    // RESP-06 / RESP-08 (LOW) — touch célméretek.
    // ------------------------------------------------------------------

    public function testIconButtonsHaveAnExpandedHitAreaWithoutChangingVisualSize(): void
    {
        $css = $this->src('style.css');
        // A vizuális méret (34x34) VÁLTOZATLAN kell maradjon — csak egy
        // ::before bővíti a találati területet.
        $this->assertMatchesRegularExpression('/\.icon-btn\s*\{[^}]*width:\s*34px;\s*height:\s*34px/s', $css);
        $this->assertMatchesRegularExpression('/\.icon-btn::before\s*\{[^}]*inset:\s*-5px/s', $css);
    }

    public function testRemoveButtonHasAnExpandedHitAreaWithoutChangingVisualSize(): void
    {
        $css = $this->src('style.css');
        $this->assertMatchesRegularExpression('/\.remove-btn\s*\{[^}]*width:\s*32px;\s*height:\s*32px/s', $css);
        $this->assertMatchesRegularExpression('/\.remove-btn::before\s*\{[^}]*inset:\s*-4px/s', $css);
    }

    public function testQtyStepperButtonsAreGenuinelyLargerNotJustHitSlop(): void
    {
        // Itt (a +/− gombok közötti 4px rés miatt) egy hit-slop átfedné a
        // szomszédos gombot/mennyiség-mezőt — a valódi méretet kellett
        // növelni 24px-ről legalább 28px-re.
        $css = $this->src('style.css');
        preg_match('/\.qty-step-btn\s*\{[^}]*width:\s*(\d+)px/s', $css, $m);
        $this->assertNotEmpty($m, 'Nem található a .qty-step-btn width szabálya.');
        $this->assertGreaterThanOrEqual(28, (int) $m[1], 'A mennyiség-léptető gomboknak legalább 28px-esnek kell lenniük (eredetileg 24px volt).');
    }

    // ------------------------------------------------------------------
    // Regresszió-védelem: a Phase 6/7 teljesítmény-javítások NE térjenek
    // vissza a Phase 8 remediáció miatt.
    // ------------------------------------------------------------------

    public function testProductsPageStillUsesServerSidePaginationNotAFullCatalogLoad(): void
    {
        $js = $this->srcWithoutLineComments('termekek.js');
        $this->assertStringContainsString('/api/products-page.php', $js);
        $this->assertStringContainsString('const PAGE_SIZE = 100', $js);
    }

    public function testPosStillHasNoDanglingLoadProductsCall(): void
    {
        // UX-01 (Phase 7) regresszió-védelme — a Phase 8 CSS/HTML
        // módosítások ne vezessenek vissza egy loadProducts()-hívást a
        // Kassza checkout-ágába.
        $js = $this->srcWithoutLineComments('app.js');
        $this->assertDoesNotMatchRegularExpression('/\bloadProducts\(\)/', $js);
    }
}
