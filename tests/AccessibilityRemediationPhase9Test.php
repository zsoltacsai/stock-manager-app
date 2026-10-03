<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Phase 9 accessibility audit (accessibility-audit-phase9-2026-10-03.md)
 * remediáció — regressziós tesztek a CRITICAL/HIGH/MEDIUM confirmed
 * findingokra. Ugyanazzal a mintával, mint a Phase 7/8 tesztek: a tényleges
 * billentyűzet-/screen reader-viselkedést PHPUnit alatt nem lehet valódi
 * böngészővel igazolni — ezekhez a remediation report browser-validation
 * szakasza tartalmazza az élő bizonyítékot. Ez a fájl azt igazolja
 * statikusan, amit lehet: a szükséges HTML/CSS/JS elemek, attribútumok és
 * bekötések ténylegesen jelen vannak-e a forrásban.
 */
final class AccessibilityRemediationPhase9Test extends TestCase
{
    private function webroot(string $rel): string
    {
        return dirname(__DIR__) . '/webroot/' . $rel;
    }

    private function src(string $file): string
    {
        return (string) file_get_contents($this->webroot($file));
    }

    // ------------------------------------------------------------------
    // KBD-01 (CRITICAL) — POS termékkereső billentyűzet-elérhetősége.
    // ------------------------------------------------------------------

    public function testSharedSearchListboxKeyboardHelperExists(): void
    {
        $js = $this->src('topbar.js');
        $this->assertStringContainsString('window.attachSearchListboxKeyboard = function', $js);
    }

    public function testSearchListboxHelperHandlesAllRequiredKeys(): void
    {
        $js = $this->src('topbar.js');
        preg_match('/window\.attachSearchListboxKeyboard = function.*?\n};/s', $js, $m);
        $this->assertNotEmpty($m, 'Nem található az attachSearchListboxKeyboard függvénytörzse.');
        $body = $m[0];
        $this->assertStringContainsString("'ArrowDown'", $body);
        $this->assertStringContainsString("'ArrowUp'", $body);
        $this->assertStringContainsString("'Enter'", $body);
        $this->assertStringContainsString("'Escape'", $body);
        $this->assertStringContainsString("role', 'combobox'", $body);
        $this->assertStringContainsString("role', 'listbox'", str_replace('resultsEl.setAttribute(\'role\', \'listbox\')', "role', 'listbox'", $body) . $body);
        $this->assertStringContainsString('aria-activedescendant', $body);
    }

    public function testPosProductSearchWiresTheSharedKeyboardListbox(): void
    {
        $js = $this->src('app.js');
        $this->assertMatchesRegularExpression(
            '/attachSearchListboxKeyboard\(searchInput,\s*searchResults/',
            $js,
            'A POS termékkereső (search-input/search-results) nincs bekötve a megosztott billentyűzet-listbox segédre (KBD-01).'
        );
        $this->assertStringContainsString("row.setAttribute('role', 'option')", $js);
        $this->assertStringContainsString('searchListbox.sync()', $js);
    }

    public function testBuyerAndCustomerAndGlobalAndTransferSearchAlsoWireTheListbox(): void
    {
        $this->assertStringContainsString('attachSearchListboxKeyboard(buyerName, buyerNameResults', $this->src('app.js'));
        $this->assertStringContainsString('attachSearchListboxKeyboard(customerSearch, customerSearchResults', $this->src('app.js'));
        $this->assertStringContainsString('attachSearchListboxKeyboard(searchInput, searchResults', $this->src('topbar.js'));
        $this->assertStringContainsString('attachSearchListboxKeyboard(transferProductSearch, transferProductResults', $this->src('telephelyek.js'));
    }

    // ------------------------------------------------------------------
    // STAT-01/STAT-02 (CRITICAL/HIGH) — toast aria-live.
    // ------------------------------------------------------------------

    public function testToastContainerGetsRoleAndAriaLiveAtInit(): void
    {
        $js = $this->src('topbar.js');
        $this->assertMatchesRegularExpression('/toast\.setAttribute\(\'role\', \'status\'\)/', $js);
        $this->assertMatchesRegularExpression('/toast\.setAttribute\(\'aria-live\', \'polite\'\)/', $js);
        $this->assertMatchesRegularExpression('/toast\.setAttribute\(\'aria-atomic\', \'true\'\)/', $js);
    }

    public function testShowToastSwitchesToAssertiveAlertOnError(): void
    {
        $js = $this->src('topbar.js');
        preg_match('/function showToast\(msg, kind, opts\) \{.*?\n    \}/s', $js, $m);
        $this->assertNotEmpty($m, 'Nem található a showToast() függvénytörzse.');
        $body = $m[0];
        $this->assertStringContainsString("const isError = kind === 'error';", $body);
        $this->assertStringContainsString("isError ? 'alert' : 'status'", $body);
        $this->assertStringContainsString("isError ? 'assertive' : 'polite'", $body);
    }

    public function testOrderPopupNoLongerCreatesItsOwnDetachedToastElement(): void
    {
        $js = $this->src('topbar.js');
        preg_match('/function showOrderPopup\(newCount, total\) \{.*?\n    \}/s', $js, $m);
        $this->assertNotEmpty($m, 'Nem található a showOrderPopup() függvénytörzse.');
        $body = $m[0];
        $this->assertStringNotContainsString('document.createElement', $body, 'STAT-02 regresszió: a rendelés-popup ismét saját, frissen beszúrt DOM-elemet hozna létre.');
        $this->assertStringContainsString('showToast(msg,', $body);
    }

    // ------------------------------------------------------------------
    // SR-01 / TBL-02 / TBL-05 / TBL-06 (CRITICAL/HIGH) — responsive
    // táblázat-szemantika.
    // ------------------------------------------------------------------

    public function testResponsiveTableEnhancerRestoresTableRowCellRoles(): void
    {
        $js = $this->src('topbar.js');
        $this->assertStringContainsString("table.setAttribute('role', 'table')", $js);
        $this->assertStringContainsString("tbody.setAttribute('role', 'rowgroup')", $js);
        $this->assertStringContainsString("tr.setAttribute('role', 'row')", $js);
        $this->assertStringContainsString("isTitle ? 'rowheader' : 'cell'", $js);
        $this->assertMatchesRegularExpression('/document\.querySelectorAll\(\'table\.rt-cards\'\)/', $js);
    }

    public function testResponsiveTableEnhancerObservesTbodyMutationsForRerenders(): void
    {
        $js = $this->src('topbar.js');
        $this->assertMatchesRegularExpression(
            '/new MutationObserver\(\(\) => enhanceTable\(table\)\)\.observe\(tbody, \{ childList: true, subtree: true \}\)/',
            $js,
            'A reszponzív táblázat-javító szkriptnek figyelnie kell a tbody újra-renderelését (szűrés/lapozás/rendezés után), nem csak az első betöltéskor kell lefutnia.'
        );
    }

    public function testResponsiveTableEnhancerGivesCheckboxAndActionButtonsRowSpecificNames(): void
    {
        $js = $this->src('topbar.js');
        $this->assertStringContainsString("'Kijelölés: ' + rowName", $js);
        $this->assertStringContainsString("' – ' + rowName", $js);
        $this->assertStringContainsString(".row-select-checkbox", $js);
        $this->assertStringContainsString(".edit-btn", $js);
        $this->assertStringContainsString(".toggle-delete-btn", $js);
    }

    public function testAllNineRtCardsConsumerPagesUseTheSharedRtTitleConvention(): void
    {
        // A központi JS-javító a ".rt-title" osztályt használja a sor
        // "rowheader" cellájának azonosítására — ha egy oldal ettől eltérő
        // mintát használna, a központi fix néma maradna rajta.
        $pages = ['termekek.js', 'eladasok.js', 'beszerzesek.js', 'beerkezett-szamlak.js', 'beszallitok.js', 'kassza-riport.js', 'inventory-report.js', 'beszerzesi-javaslat.js', 'vasarlok.js'];
        foreach ($pages as $file) {
            $this->assertStringContainsString('rt-title', $this->src($file), "$file nem használja az rt-title konvenciót — a Phase 9 táblázat-szemantika javítás ott nem fut le.");
        }
    }

    // ------------------------------------------------------------------
    // FOC-01 / FOC-02 (HIGH) — mobil navigációs fiók inert-kezelése.
    // ------------------------------------------------------------------

    public function testDrawerStartsInertSoClosedLinksLeaveTheTabOrder(): void
    {
        $js = $this->src('topbar.js');
        $this->assertMatchesRegularExpression(
            '/drawer\.inert = true;\s*\n\s*\n?\s*\/\/ Phase 9.*?FOC-02/s',
            $js,
            'A drawernek induláskor (zárt állapotban) inert-nek kell lennie (FOC-01).'
        );
    }

    public function testOpenDrawerMakesBackgroundInertAndCloseRestoresIt(): void
    {
        $js = $this->src('topbar.js');
        preg_match('/function openDrawer\(\) \{.*?\n    \}/s', $js, $open);
        preg_match('/function closeDrawer\(\) \{.*?\n    \}/s', $js, $close);
        $this->assertNotEmpty($open);
        $this->assertNotEmpty($close);
        $this->assertStringContainsString('drawer.inert = false;', $open[0]);
        $this->assertStringContainsString('setBackgroundInert(true);', $open[0]);
        $this->assertStringContainsString('setBackgroundInert(false);', $close[0]);
        $this->assertStringContainsString('drawer.inert = true;', $close[0]);
    }

    public function testSetBackgroundInertExcludesDrawerAndBackdropThemselves(): void
    {
        $js = $this->src('topbar.js');
        preg_match('/function setBackgroundInert\(isInert\) \{.*?\n    \}/s', $js, $m);
        $this->assertNotEmpty($m, 'Nem található a setBackgroundInert() segédfüggvény.');
        $this->assertStringContainsString('if (el === drawer || el === backdrop) return;', $m[0]);
    }

    // ------------------------------------------------------------------
    // FOC-03 / DLG-01 / DLG-02 (HIGH) — közös modal focus-trap/szemantika.
    // ------------------------------------------------------------------

    public function testModalObserverAddsDialogRoleAriaModalAndLabelledby(): void
    {
        $js = $this->src('topbar.js');
        preg_match('/function ensureDialogSemantics\(modal\) \{.*?\n    \}/s', $js, $m);
        $this->assertNotEmpty($m, 'Nem található az ensureDialogSemantics() segédfüggvény.');
        $body = $m[0];
        $this->assertStringContainsString("modal.setAttribute('role', 'dialog')", $body);
        $this->assertStringContainsString("modal.setAttribute('aria-modal', 'true')", $body);
        $this->assertStringContainsString('aria-labelledby', $body);
    }

    public function testModalKeydownHandlerTrapsTabAndShiftTab(): void
    {
        $js = $this->src('topbar.js');
        $this->assertMatchesRegularExpression(
            "/document\\.addEventListener\\('keydown', \\(e\\) => \\{\\s*\\n\\s*if \\(e\\.key !== 'Tab' \\|\\| !activeModal/",
            $js,
            'Nem található a modal Tab-csapda keydown-kezelője.'
        );
        $this->assertStringContainsString('e.shiftKey && document.activeElement === first', $js);
        $this->assertStringContainsString('!e.shiftKey && document.activeElement === last', $js);
    }

    public function testModalOpenRestoresFocusToTriggerOnClose(): void
    {
        $js = $this->src('topbar.js');
        preg_match('/function onModalClosed\(modal\) \{.*?\n    \}/s', $js, $m);
        $this->assertNotEmpty($m, 'Nem található az onModalClosed() segédfüggvény.');
        $this->assertStringContainsString('lastTrigger.focus()', $m[0]);
    }

    public function testModalObserverWatchesTheSharedModalOverlayClassAppWide(): void
    {
        $js = $this->src('topbar.js');
        $this->assertStringContainsString("modal.classList.contains('modal-overlay')", $js);
        $this->assertMatchesRegularExpression(
            '/\.observe\(document\.body, \{ attributes: true, attributeFilter: \[\'class\'\], attributeOldValue: true, subtree: true \}\)/',
            $js
        );
    }

    // ------------------------------------------------------------------
    // FORM-01 (CRITICAL) — validációs/hiba-visszajelzések bejelentése.
    // ------------------------------------------------------------------

    public function testFeedbackElementsGetRoleAndAriaLiveBasedOnErrorClass(): void
    {
        $js = $this->src('topbar.js');
        preg_match('/function markLive\(el\) \{.*?\n    \}/s', $js, $m);
        $this->assertNotEmpty($m, 'Nem található a markLive() segédfüggvény (FORM-01).');
        $body = $m[0];
        $this->assertStringContainsString("el.classList.contains('error')", $body);
        $this->assertStringContainsString("isError ? 'alert' : 'status'", $body);
        $this->assertStringContainsString("isError ? 'assertive' : 'polite'", $body);
        $this->assertStringContainsString("document.querySelectorAll('.feedback, .modal-feedback')", $js);
    }

    // ------------------------------------------------------------------
    // SEM-01 (HIGH) — <main> landmark.
    // ------------------------------------------------------------------

    public function testMainLandmarkAddedToContentWrapperOnFormerlyUnlandmarkedPages(): void
    {
        $pages = ['dashboard.php', 'ai-asszisztens.php', 'beallitasok.php', 'vasarlok.php', 'kasszazaras.php', 'zaras.php', 'staff.php'];
        foreach ($pages as $file) {
            $this->assertMatchesRegularExpression(
                '/<div role="main" class="import-panel/',
                $this->src($file),
                "$file nem kapott role=\"main\" landmarket (SEM-01)."
            );
        }
    }

    /**
     * termekek.php a többi oldaltól eltérően NEM a közös ".import-panel"
     * mintát, hanem egy saját "<section class=\"filter-panel\">" wrappert
     * használ a fő tartalomhoz — ezért ugyanazt a role="main"-t itt külön,
     * a saját mintájára szabva kellett pótolni.
     */
    public function testTermekekPageHasItsOwnMainLandmarkOnTheFilterPanelSection(): void
    {
        $this->assertStringContainsString('<section role="main" class="filter-panel">', $this->src('termekek.php'));
    }

    public function testIndexAndBeszerzesStillUseRealMainElementUnchanged(): void
    {
        // Ezek a Phase 9 előtt is <main>-t használtak — nem szabad duplikált
        // landmarket okozni rajtuk.
        $this->assertStringContainsString('<main class="layout">', $this->src('index.php'));
        $this->assertStringContainsString('<main class="layout">', $this->src('beszerzes.php'));
    }

    // ------------------------------------------------------------------
    // SEM-02 (MEDIUM) — navigation landmark elnevezés.
    // ------------------------------------------------------------------

    public function testNavLandmarksHaveDistinguishingAriaLabels(): void
    {
        $this->assertStringContainsString('aria-label="Gyors navigáció"', $this->src('headermenu.php'));
        $this->assertStringContainsString('aria-label="Fő navigáció"', $this->src('sidebarmenu.php'));
    }

    // ------------------------------------------------------------------
    // SEM-11 / SEM-13 (HIGH/MEDIUM) — toggle-switch állapot és név.
    // ------------------------------------------------------------------

    public function testToggleSwitchEnhancerSetsSwitchRoleAndAriaChecked(): void
    {
        $js = $this->src('topbar.js');
        preg_match('/function enhanceSwitch\(btn\) \{.*?\n    \}/s', $js, $m);
        $this->assertNotEmpty($m, 'Nem található az enhanceSwitch() segédfüggvény.');
        $body = $m[0];
        $this->assertStringContainsString("btn.setAttribute('role', 'switch')", $body);
        $this->assertStringContainsString("classList.contains('on') ? 'true' : 'false'", $body);
        $this->assertStringContainsString("closest('.toggle-line')", $body);
    }

    public function testAllToggleSwitchInstancesSitInsideAToggleLineWithALabelSpan(): void
    {
        $pages = ['beallitasok.php', 'beszallitok.php', 'beszerzes.php', 'termekek.php', 'vasarlok.php'];
        foreach ($pages as $file) {
            $html = $this->src($file);
            if (!str_contains($html, 'toggle-switch')) {
                continue;
            }
            $this->assertMatchesRegularExpression(
                '/<div class="toggle-line"[^>]*>\s*<span>[^<]+<\/span>\s*<button type="button" class="toggle-switch/',
                $html,
                "$file egy toggle-switch gombja nem a várt .toggle-line > span + button mintát követi — a központi aria-label-javítás ott néma maradna."
            );
        }
    }

    // ------------------------------------------------------------------
    // SEM-12/13 (MEDIUM) — title-only ikongombok aria-label-je.
    // ------------------------------------------------------------------

    public function testTitleOnlyIconEnhancerMirrorsTitleToAriaLabel(): void
    {
        $js = $this->src('topbar.js');
        preg_match('/function enhance\(el\) \{\s*\n\s*if \(el\.hasAttribute\(\'aria-label\'\)\) return;.*?\n    \}/s', $js, $m);
        $this->assertNotEmpty($m, 'Nem található a title-only ikonokat javító enhance() függvény.');
        $this->assertStringContainsString("el.textContent.trim() !== ''", $m[0]);
        $this->assertStringContainsString("el.setAttribute('aria-label', title)", $m[0]);
    }

    // ------------------------------------------------------------------
    // TAB-01 (MEDIUM) — ARIA tabs szemantika.
    // ------------------------------------------------------------------

    public function testTabEnhancerBuildsTablistTabTabpanelRelationship(): void
    {
        $js = $this->src('topbar.js');
        preg_match('/function enhanceTabGroup\(tabs\) \{.*?\n    \}/s', $js, $m);
        $this->assertNotEmpty($m, 'Nem található az enhanceTabGroup() segédfüggvény.');
        $body = $m[0];
        $this->assertStringContainsString("tabs.setAttribute('role', 'tablist')", $body);
        $this->assertStringContainsString("btn.setAttribute('role', 'tab')", $body);
        $this->assertStringContainsString("panel.setAttribute('role', 'tabpanel')", $body);
        $this->assertStringContainsString('aria-controls', $body);
        $this->assertStringContainsString('aria-labelledby', $body);
        $this->assertStringContainsString('aria-selected', $body);
    }

    public function testAllSixTabGroupsUseTheDataTabToPanelIdConvention(): void
    {
        $cases = [
            'termekek.php' => ['tab-overview', 'tab-main', 'tab-prices'],
            'beallitasok.php' => ['tab-sync-settings', 'tab-logo-settings'],
            'ai-asszisztens.php' => ['tab-ai-chat', 'tab-ai-history'],
            'vasarlok.php' => ['c-tab-data'],
            'telephelyek.php' => ['tab-locations', 'tab-transfer'],
            'kedvezmenyek.php' => ['tab-coupons', 'tab-gift-cards'],
        ];
        foreach ($cases as $file => $ids) {
            $html = $this->src($file);
            foreach ($ids as $id) {
                $this->assertStringContainsString("data-tab=\"$id\"", $html, "$file: hiányzik a data-tab=\"$id\" gomb.");
                $this->assertMatchesRegularExpression(
                    '/id="' . preg_quote($id, '/') . '" class="tab-panel/',
                    $html,
                    "$file: hiányzik az id=\"$id\" panel — a data-tab -> panel id megfeleltetés megtört volna."
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // TBL-01 (HIGH) — scope="col" a táblázat-fejlécekben.
    // ------------------------------------------------------------------

    public function testTableHeadersHaveScopeColOnSampleOfPages(): void
    {
        $pages = ['termekek.php', 'index.php', 'eladasok.php', 'kassza-riport.php', 'vasarlok.php', 'beallitasok.php'];
        foreach ($pages as $file) {
            $html = $this->src($file);
            // substr_count('<th')-tal a "<thead" is matchelne — ezért kell a
            // szóhatár ([\\s>]), hogy csak a valódi <th> elemeket számoljuk.
            preg_match_all('/<th[\s>]/', $html, $thMatches);
            $thCount = count($thMatches[0]);
            $scopedCount = substr_count($html, '<th scope="col"');
            $this->assertGreaterThan(0, $thCount, "$file-ban nincs <th> elem, a teszt nem tud mit ellenőrizni.");
            $this->assertSame($thCount, $scopedCount, "$file: nem minden <th> kapott scope=\"col\"-t (TBL-01).");
        }
    }

    // ------------------------------------------------------------------
    // CTR-01/02/03/05 (HIGH/MEDIUM) — kontraszt-javító tokenek.
    // ------------------------------------------------------------------

    public function testContrastOverrideTokensAreDefinedAndUsed(): void
    {
        $css = $this->src('style.css');
        $this->assertStringContainsString('--accent-strong: #0f7a37;', $css);
        $this->assertStringContainsString('--danger-strong: #c62828;', $css);
        $this->assertStringContainsString('--stock-badge-warn-text: #2e1c00;', $css);
        $this->assertMatchesRegularExpression('/\.stock-badge\.zero \{ background: var\(--danger-strong, var\(--danger\)\); color: #fff; \}/', $css);
        $this->assertMatchesRegularExpression('/\.stock-badge\.warn \{ background: var\(--warn\); color: var\(--stock-badge-warn-text, #452b00\); \}/', $css);
        $this->assertStringContainsString('background: var(--accent-strong);', $css);
        $this->assertStringContainsString('html[data-theme="light"] a:focus-visible { outline-color: var(--accent-strong); }', $css);
    }

    // ------------------------------------------------------------------
    // MOT-01 (MEDIUM) — prefers-reduced-motion.
    // ------------------------------------------------------------------

    public function testReducedMotionMediaQueryDisablesDecorativeAnimationsOnly(): void
    {
        $css = $this->src('style.css');
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\) \{/', $css);
        preg_match('/@media \(prefers-reduced-motion: reduce\) \{(.*?)\n\}/s', $css, $m);
        $this->assertNotEmpty($m);
        $body = $m[1];
        $this->assertStringContainsString('.mobile-nav-drawer', $body);
        $this->assertStringContainsString('.flash-added', $body);
        $this->assertStringContainsString('.saved-flash', $body);
        // A valós, folyamatban lévő állapotot jelző spinnereket SZÁNDÉKOSAN
        // nem tiltjuk le — ne regresszáljon vissza ide.
        $this->assertStringNotContainsString('.spinner', $body);
        $this->assertStringNotContainsString('icon-spin', $body);
    }

    // ------------------------------------------------------------------
    // FORM-05/FORM-06 (CRITICAL/HIGH) — PIN-megerősítő és fájl-visszaállítás
    // mezőinek címkézése.
    // ------------------------------------------------------------------

    public function testPinConfirmInputHasAnAccessibleLabel(): void
    {
        $html = $this->src('beallitasok.php');
        $this->assertMatchesRegularExpression(
            '/<input type="password" id="pin-confirm-input" autocomplete="off" aria-labelledby="pin-confirm-text">/',
            $html
        );
    }

    public function testRestoreFileInputLabelIsProperlyAssociated(): void
    {
        $html = $this->src('beallitasok.php');
        $this->assertStringContainsString('<label for="restore-file-input"', $html);
    }

    // ------------------------------------------------------------------
    // FORM-08/FORM-09 (HIGH) — leltár/visszáru mennyiség-mezők neve.
    // ------------------------------------------------------------------

    public function testStockTakeCountedInputHasRowSpecificAccessibleName(): void
    {
        $js = $this->src('leltar.js');
        $this->assertMatchesRegularExpression(
            '/class="counted-input"[^>]*aria-label="Megszámolt mennyiség – \$\{escapeHtml\(item\.name\)\}"/',
            $js
        );
    }

    public function testReturnQtyInputHasRowSpecificAccessibleName(): void
    {
        $js = $this->src('eladasok.js');
        $this->assertMatchesRegularExpression(
            '/class="return-qty-input"[^>]*aria-label="Visszaveendő mennyiség – \$\{escapeHtml\(item\.name\)\}"/',
            $js
        );
    }

    // ------------------------------------------------------------------
    // Regresszió — Phase 6/7/8 minták sértetlenek maradtak.
    // ------------------------------------------------------------------

    public function testPhase8PosCartResponsiveClassStillIntact(): void
    {
        $this->assertStringContainsString('<table class="cart-table pos-cart-table">', $this->src('index.php'));
    }

    public function testPhase8StickyModalActionsCssStillIntact(): void
    {
        $this->assertMatchesRegularExpression('/\.modal-actions\s*\{[^}]*position:\s*sticky/s', $this->src('style.css'));
    }

    public function testPhase6ServerSideProductSearchEndpointStillUsed(): void
    {
        // KBD-01 javítása ne vezessen vissza egy teljes katalógus
        // kliensoldali betöltéséhez — a szerveroldali keresés maradjon.
        $this->assertStringContainsString("fetchJson('/api/product-search.php?limit=20&q=", $this->src('app.js'));
    }

    public function testVisuallyHiddenUtilityClassDoesNotUseDisplayNone(): void
    {
        $css = $this->src('style.css');
        preg_match('/\.visually-hidden \{(.*?)\}/s', $css, $m);
        $this->assertNotEmpty($m, 'Nem található a .visually-hidden segédosztály.');
        $this->assertStringNotContainsString('display: none', $m[1], 'A .visually-hidden nem használhat display:none-t, mert az az accessibility-fából is kivenné a tartalmat.');
    }
}
