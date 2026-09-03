
// Globálisan elérhető escape-függvény: mivel az app rengeteg helyen épít
// innerHTML-sablonokat adatbázisból (import/WooCommerce-ből is) származó
// szöveggel (termék/vevő/beszállító név, jegyzet stb.), ez a védelem
// tárolt XSS ellen — egy rosszindulatú vagy hibás import különben
// futtatható kódot csempészhetne be egy termék nevén keresztül.
window.escapeHtml = function (str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

// Globális fetch-becsomagolás: ha munkamenet közben lejár a bejelentkezés
// (vagy valaki egy másik fülön kijelentkezik), bármelyik API-hívás 401-et
// ad vissza auth_required jelzéssel — ilyenkor ahelyett, hogy minden egyes
// oldal saját hibaüzenetet mutatna, egységesen a login oldalra irányítunk.
// Ez csak kényelmi réteg — a tényleges elutasítást a szerver már megtette.
(function () {
    const originalFetch = window.fetch;
    window.fetch = function (...args) {
        return originalFetch.apply(this, args).then(async (response) => {
            if (response.status === 401 && !location.pathname.endsWith('/login.html')) {
                try {
                    const clone = response.clone();
                    const data = await clone.json();
                    if (data && data.auth_required) {
                        const redirect = encodeURIComponent(location.pathname.split('/').pop());
                        location.href = 'login.html?redirect=' + redirect;
                    }
                } catch (e) { /* not JSON, or already navigating away — ignore */ }
            }
            return response;
        });
    };
})();

// Egyetlen megosztott /api/settings.php hívás minden oldalon — több szkript
// is szeretné ugyanezt az adatot (topbar logó/téma/szinkron-jelzés, és egyes
// oldalakon a saját JS-ük is: app.js a törzsvásárlói pontokhoz, termekek.js
// az alacsony készlet küszöbhöz, vasarlok.js a hűségszintekhez) — korábban
// mindegyik saját fetch-et indított, duplán terhelve ugyanazt az egyszálú
// PHP dev szervert. Most csak egyszer fut le, a többi hívó erre vár rá.
window.smSettingsPromise = fetch('/api/settings.php').then(r => r.json()).catch(() => ({}));

fetch('/api/install-status.php')
    .then(r => r.json())
    .then(data => {
        if (!data.installed && !location.pathname.endsWith('/install.php')) {
            location.href = 'install.php';
        }
    })
    .catch(() => { /* if the check itself fails, don't block the app over it */ });

// Bejelentkezés-ellenőrzés — ez CSAK a felhasználói élményt szolgálja
// (gyors átirányítás a login oldalra, hogy ne kelljen egy üres/hibás
// oldalt bámulni). A VALÓDI védelem szerver-oldalon van: minden API-hívás
// magától elutasít mindent bejelentkezés nélkül (lásd api/_bootstrap.php),
// szóval ha ez a kliens-oldali ellenőrzés bármi miatt nem futna le, attól
// még semmilyen adat vagy funkció nem válik elérhetővé — legfeljebb a
// statikus oldal-váz látszik, tartalom és működés nélkül.
window.smCsrfToken = null;

// A headermenu.php-ben lévő kijelentkezés gomb — csak akkor látszik, ha a
// jelszavas védelem be van kapcsolva (lásd lent, az auth-status válaszban).
const headerLogoutBtn = document.getElementById('header-logout-btn');
if (headerLogoutBtn) {
    headerLogoutBtn.addEventListener('click', async () => {
        try {
            await fetch('/api/logout.php', { method: 'POST' });
        } catch (e) { /* proceed to redirect regardless */ }
        location.href = 'login.html';
    });
}

if (!location.pathname.endsWith('/login.html') && !location.pathname.endsWith('/install.php')) {
    fetch('/api/auth-status.php')
        .then(r => r.json())
        .then(data => {
            window.smCsrfToken = data.csrf_token || null;
            if (data.enabled && !data.logged_in) {
                const redirect = encodeURIComponent(location.pathname.split('/').pop());
                location.href = 'login.html?redirect=' + redirect;
            }
            if (headerLogoutBtn) headerLogoutBtn.classList.toggle('hidden', !data.enabled);
        })
        .catch(() => { /* ha ez sem elérhető, az API-hívások úgyis 401-et adnak vissza */ });
}

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js').catch(() => {
        });
    });
}
(function () {
    const sidebarLogo = document.getElementById('sidebar-logo');
    const syncBtn = document.getElementById('sync-icon-btn');
    const toast = document.getElementById('sync-toast');

    const autoSyncEnabled = document.getElementById('auto-sync-enabled');
    const autoSyncInterval = document.getElementById('auto-sync-interval');
    const lastAutoSync = document.getElementById('last-auto-sync');
    const settingsSyncFeedback = document.getElementById('settings-sync-feedback');
    const settingsSaveSyncBtn = document.getElementById('settings-save-sync-btn');

    const logoPreview = document.getElementById('logo-preview');
    const logoFileInput = document.getElementById('logo-file-input');
    const logoUploadBtn = document.getElementById('logo-upload-btn');
    const logoResetBtn = document.getElementById('logo-reset-btn');
    const settingsLogoFeedback = document.getElementById('settings-logo-feedback');

    const printLogoPreview = document.getElementById('print-logo-preview');
    const printLogoFileInput = document.getElementById('print-logo-file-input');
    const printLogoUploadBtn = document.getElementById('print-logo-upload-btn');
    const printLogoResetBtn = document.getElementById('print-logo-reset-btn');
    const settingsPrintLogoFeedback = document.getElementById('settings-print-logo-feedback');

    const printerEnabled = document.getElementById('printer-enabled');
    const printerIp = document.getElementById('printer-ip');
    const printerPort = document.getElementById('printer-port');
    const printerPaperWidth = document.getElementById('printer-paper-width');
    const printerTestBtn = document.getElementById('printer-test-btn');
    const settingsSavePrinterBtn = document.getElementById('settings-save-printer-btn');
    const settingsPrinterFeedback = document.getElementById('settings-printer-feedback');

    const backupEnabled = document.getElementById('backup-enabled');
    const backupTime = document.getElementById('backup-time');
    const backupRetention = document.getElementById('backup-retention');
    const backupProvider = document.getElementById('backup-provider');
    const backupDropboxFields = document.getElementById('backup-dropbox-fields');
    const backupGoogleFields = document.getElementById('backup-google-fields');
    const dropboxAccessToken = document.getElementById('dropbox-access-token');
    const dropboxFolder = document.getElementById('dropbox-folder');
    const googleClientId = document.getElementById('google-client-id');
    const googleClientSecret = document.getElementById('google-client-secret');
    const googleRefreshToken = document.getElementById('google-refresh-token');
    const googleFolderId = document.getElementById('google-folder-id');
    const lastBackup = document.getElementById('last-backup');
    const backupNowBtn = document.getElementById('backup-now-btn');
    const settingsSaveBackupBtn = document.getElementById('settings-save-backup-btn');
    const settingsBackupFeedback = document.getElementById('settings-backup-feedback');
    const backupListEl = document.getElementById('backup-list');

    const paymentMethodsList = document.getElementById('payment-methods-list');
    const paymentMethodsAddInput = document.getElementById('payment-methods-add-input');
    const paymentMethodsAddBtn = document.getElementById('payment-methods-add-btn');
    const settingsSavePaymentMethodsBtn = document.getElementById('settings-save-payment-methods-btn');
    const settingsPaymentMethodsFeedback = document.getElementById('settings-payment-methods-feedback');
    let currentPaymentMethods = [];

    const szamlazzAgentKey = document.getElementById('szamlazz-agent-key');
    const szamlazzDefaultPayment = document.getElementById('szamlazz-default-payment');
    const szamlazzDefaultVat = document.getElementById('szamlazz-default-vat');
    const szamlazzSendEmail = document.getElementById('szamlazz-send-email');
    const settingsSaveSzamlazzBtn = document.getElementById('settings-save-szamlazz-btn');
    const settingsSzamlazzFeedback = document.getElementById('settings-szamlazz-feedback');

    const navLogin = document.getElementById('nav-login');
    const navPassword = document.getElementById('nav-password');
    const navSignerKey = document.getElementById('nav-signer-key');
    const navExchangeKey = document.getElementById('nav-exchange-key');
    const navTaxNumber = document.getElementById('nav-tax-number');
    const navTestMode = document.getElementById('nav-test-mode');
    const settingsSaveNavBtn = document.getElementById('settings-save-nav-btn');
    const settingsNavFeedback = document.getElementById('settings-nav-feedback');

    const wcStoreUrl = document.getElementById('wc-store-url');
    const wcConsumerKey = document.getElementById('wc-consumer-key');
    const wcConsumerSecret = document.getElementById('wc-consumer-secret');
    const wcBarcodeSource = document.getElementById('wc-barcode-source');
    const wcBarcodeMetaWrap = document.getElementById('wc-barcode-meta-wrap');
    const wcBarcodeMetaKey = document.getElementById('wc-barcode-meta-key');
    const wcWebhookSecret = document.getElementById('wc-webhook-secret');
    const wcPublicBaseUrl = document.getElementById('wc-public-base-url');
    const productImageSize = document.getElementById('product-image-size');
    const settingsSaveWcBtn = document.getElementById('settings-save-wc-btn');
    const brandMappingReloadBtn = document.getElementById('brand-mapping-reload-btn');
    const brandMappingList = document.getElementById('brand-mapping-list');
    const settingsSaveBrandMappingBtn = document.getElementById('settings-save-brand-mapping-btn');
    const settingsBrandMappingFeedback = document.getElementById('settings-brand-mapping-feedback');
    let currentBrandMapping = {};
    const settingsWcFeedback = document.getElementById('settings-wc-feedback');

    const lowStockDefault = document.getElementById('low-stock-default');
    const lowStockWebhook = document.getElementById('low-stock-webhook');
    const lowStockEmail = document.getElementById('low-stock-email');
    const settingsSaveLowstockBtn = document.getElementById('settings-save-lowstock-btn');
    const settingsLowstockFeedback = document.getElementById('settings-lowstock-feedback');

    const themeDarkRadio = document.getElementById('theme-dark');
    const themeLightRadio = document.getElementById('theme-light');
    const settingsAppearanceFeedback = document.getElementById('settings-appearance-feedback');

    const receiptHeaderLines = document.getElementById('receipt-header-lines');
    const receiptFooterLines = document.getElementById('receipt-footer-lines');
    const receiptShowLogo = document.getElementById('receipt-show-logo');
    const settingsSaveReceiptBtn = document.getElementById('settings-save-receipt-btn');
    const settingsReceiptFeedback = document.getElementById('settings-receipt-feedback');

    const loyaltyEnabled = document.getElementById('loyalty-enabled');
    const loyaltyHufPerPoint = document.getElementById('loyalty-huf-per-point');
    const loyaltyPointValue = document.getElementById('loyalty-point-value');
    const tierSilverThreshold = document.getElementById('tier-silver-threshold');
    const tierSilverDiscount = document.getElementById('tier-silver-discount');
    const tierGoldThreshold = document.getElementById('tier-gold-threshold');
    const tierGoldDiscount = document.getElementById('tier-gold-discount');
    const settingsSaveLoyaltyBtn = document.getElementById('settings-save-loyalty-btn');
    const settingsLoyaltyFeedback = document.getElementById('settings-loyalty-feedback');

    const auditRetentionDays = document.getElementById('audit-retention-days');
    const settingsSaveAuditBtn = document.getElementById('settings-save-audit-btn');
    const settingsAuditFeedback = document.getElementById('settings-audit-feedback');

    const securityPasswordEnabled = document.getElementById('security-password-enabled');
    const securityNewPassword = document.getElementById('security-new-password');
    const securityNewPasswordConfirm = document.getElementById('security-new-password-confirm');
    const securitySessionTimeout = document.getElementById('security-session-timeout');
    const securityMaxAttempts = document.getElementById('security-max-attempts');
    const securityLockoutMinutes = document.getElementById('security-lockout-minutes');
    const settingsSaveSecurityBtn = document.getElementById('settings-save-security-btn');
    const settingsSecurityFeedback = document.getElementById('settings-security-feedback');
    const securityLogoutBtn = document.getElementById('security-logout-btn');

    const geoBlockEnabled = document.getElementById('geo-block-enabled');
    const geoBlockCountries = document.getElementById('geo-block-countries');
    const geoBlockAllowIps = document.getElementById('geo-block-allow-ips');
    const geoAddOwnIpBtn = document.getElementById('geo-add-own-ip-btn');
    const geoCurrentInfo = document.getElementById('geo-current-info');
    const settingsSaveGeoBtn = document.getElementById('settings-save-geo-btn');
    const settingsGeoFeedback = document.getElementById('settings-geo-feedback');

    function applyTheme(theme) {
        if (theme === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        try { localStorage.setItem('sm_theme', theme); } catch (e) { /* ignore */ }
    }

    async function saveTheme(theme) {
        applyTheme(theme);
        try {
            await fetch('/api/settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ theme }),
            });
            if (settingsAppearanceFeedback) settingsAppearanceFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsAppearanceFeedback.classList.add('saved-flash');
                setTimeout(() => settingsAppearanceFeedback.classList.remove('saved-flash'), 1200);
        } catch (e) {
            if (settingsAppearanceFeedback) settingsAppearanceFeedback.textContent = 'A mentés nem sikerült, de a sablon a böngészőben aktív maradt.';
        }
    }

    // A fejlécbe injektált gyorsváltó gomb (lásd lejjebb, a második IIFE-ben)
    // ebből a scope-ból hívja meg ugyanezt a mentési logikát.
    window.smSaveTheme = saveTheme;

    if (themeDarkRadio) themeDarkRadio.addEventListener('change', () => { if (themeDarkRadio.checked) saveTheme('dark'); });
    if (themeLightRadio) themeLightRadio.addEventListener('change', () => { if (themeLightRadio.checked) saveTheme('light'); });

    function showToast(msg, kind) {
        if (!toast) return;
        toast.textContent = msg;
        toast.className = 'sync-toast show' + (kind ? ' ' + kind : '');
        setTimeout(() => toast.classList.remove('show'), 4000);
    }

    async function loadSettings() {
        try {
            const data = await window.smSettingsPromise;
            applySettings(data);
        } catch (e) {
        }
    }

    function applySettings(data) {
        if (sidebarLogo) sidebarLogo.src = data.logo_url;
        if (logoPreview) logoPreview.src = data.logo_url;
        if (printLogoPreview) printLogoPreview.src = data.print_logo_url;

        const theme = data.theme === 'light' ? 'light' : 'dark';
        applyTheme(theme);
        if (themeDarkRadio) themeDarkRadio.checked = theme === 'dark';
        if (themeLightRadio) themeLightRadio.checked = theme === 'light';

        if (autoSyncEnabled) autoSyncEnabled.checked = !!data.auto_sync_enabled;
        if (autoSyncInterval) autoSyncInterval.value = String(data.auto_sync_interval_minutes || 15);
        if (syncBtn) syncBtn.classList.toggle('auto-on', !!data.auto_sync_enabled);
        if (lastAutoSync) {
            lastAutoSync.textContent = data.last_auto_sync_at
                ? `Utolsó automatikus szinkron: ${new Date(data.last_auto_sync_at).toLocaleString('hu-HU')}` +
                  (data.last_auto_sync_summary ? ` — ${data.last_auto_sync_summary}` : '')
                : 'Még nem futott automatikus szinkron.';
        }
        if (printerEnabled) printerEnabled.checked = !!data.printer_enabled;
        if (printerIp) printerIp.value = data.printer_ip || '';
        if (printerPort) printerPort.value = String(data.printer_port || 9100);
        if (printerPaperWidth) printerPaperWidth.value = String(data.printer_paper_width || 42);

        if (backupEnabled) backupEnabled.checked = !!data.backup_enabled;
        if (backupTime) backupTime.value = data.backup_time || '23:30';
        if (backupRetention) backupRetention.value = String(data.backup_retention_count || 7);
        if (backupProvider) {
            backupProvider.value = data.backup_provider || 'none';
            toggleProviderFields(backupProvider.value);
        }
        if (dropboxAccessToken) dropboxAccessToken.value = data.dropbox_access_token || '';
        if (dropboxFolder) dropboxFolder.value = data.dropbox_folder || '/StockManagerBackups';
        if (googleClientId) googleClientId.value = data.google_client_id || '';
        if (googleClientSecret) googleClientSecret.value = data.google_client_secret || '';
        if (googleRefreshToken) googleRefreshToken.value = data.google_refresh_token || '';
        if (googleFolderId) googleFolderId.value = data.google_folder_id || '';
        if (lastBackup) {
            lastBackup.textContent = data.last_backup_at
                ? `Utolsó mentés: ${new Date(data.last_backup_at).toLocaleString('hu-HU')}` +
                  (data.last_backup_summary ? ` — ${data.last_backup_summary}` : '')
                : 'Még nem történt mentés.';
        }

        currentPaymentMethods = Array.isArray(data.payment_methods) && data.payment_methods.length ? data.payment_methods : [
            { value: 'Készpénz', color: '#16a34a' }, { value: 'Bankkártya', color: '#3b82f6' },
        ];
        renderPaymentMethodsList();
        if (szamlazzDefaultPayment) {
            szamlazzDefaultPayment.innerHTML = currentPaymentMethods.map(m => `<option value="${escapeHtml(m.value)}">${escapeHtml(m.value)}</option>`).join('');
        }

        if (szamlazzAgentKey) szamlazzAgentKey.value = data.szamlazz_agent_key || '';
        if (szamlazzDefaultPayment) szamlazzDefaultPayment.value = data.szamlazz_default_payment || 'Készpénz';
        if (szamlazzDefaultVat) szamlazzDefaultVat.value = data.szamlazz_default_vat || '27';
        if (szamlazzSendEmail) szamlazzSendEmail.checked = !!data.szamlazz_send_email;

        if (navLogin) navLogin.value = data.nav_login || '';
        if (navPassword) navPassword.value = data.nav_password || '';
        if (navSignerKey) navSignerKey.value = data.nav_signer_key || '';
        if (navExchangeKey) navExchangeKey.value = data.nav_exchange_key || '';
        if (navTaxNumber) navTaxNumber.value = data.nav_tax_number || '';
        if (navTestMode) navTestMode.checked = !!data.nav_test_mode;

        if (wcStoreUrl) wcStoreUrl.value = data.wc_store_url || '';
        if (wcConsumerKey) wcConsumerKey.value = data.wc_consumer_key || '';
        if (wcConsumerSecret) wcConsumerSecret.value = data.wc_consumer_secret || '';
        if (wcBarcodeSource) {
            wcBarcodeSource.value = data.wc_barcode_source || 'sku';
            if (wcBarcodeMetaWrap) wcBarcodeMetaWrap.classList.toggle('hidden', wcBarcodeSource.value !== 'meta');
        }
        if (wcBarcodeMetaKey) wcBarcodeMetaKey.value = data.wc_barcode_meta_key || '_barcode';
        if (wcWebhookSecret) wcWebhookSecret.value = data.wc_webhook_secret || '';
        if (wcPublicBaseUrl) wcPublicBaseUrl.value = data.wc_public_base_url || '';
        if (productImageSize) productImageSize.value = String(data.product_image_size ?? 1200);
        currentBrandMapping = (data.brand_mapping && typeof data.brand_mapping === 'object') ? data.brand_mapping : {};

        if (lowStockDefault) lowStockDefault.value = String(data.low_stock_default_threshold ?? 5);
        if (lowStockWebhook) lowStockWebhook.value = data.low_stock_notify_webhook || '';
        if (lowStockEmail) lowStockEmail.value = data.low_stock_notify_email || '';

        if (receiptHeaderLines) receiptHeaderLines.value = data.receipt_header_lines || '';
        if (receiptFooterLines) receiptFooterLines.value = data.receipt_footer_lines || '';
        if (receiptShowLogo) receiptShowLogo.checked = !!data.receipt_show_logo;

        if (loyaltyEnabled) loyaltyEnabled.checked = !!data.loyalty_enabled;
        if (loyaltyHufPerPoint) loyaltyHufPerPoint.value = String(data.loyalty_huf_per_point ?? 100);
        if (loyaltyPointValue) loyaltyPointValue.value = String(data.loyalty_point_value_huf ?? 5);
        if (tierSilverThreshold) tierSilverThreshold.value = String(data.loyalty_tier_silver_threshold ?? 50000);
        if (tierSilverDiscount) tierSilverDiscount.value = String(data.loyalty_tier_silver_discount ?? 5);
        if (tierGoldThreshold) tierGoldThreshold.value = String(data.loyalty_tier_gold_threshold ?? 150000);
        if (tierGoldDiscount) tierGoldDiscount.value = String(data.loyalty_tier_gold_discount ?? 10);
        if (auditRetentionDays) auditRetentionDays.value = String(data.audit_log_retention_days ?? 30);
        if (securityPasswordEnabled) securityPasswordEnabled.classList.toggle('on', !!data.app_password_enabled);
        if (securitySessionTimeout) securitySessionTimeout.value = String(data.session_timeout_minutes ?? 240);
        if (securityMaxAttempts) securityMaxAttempts.value = String(data.login_max_attempts ?? 5);
        if (securityLockoutMinutes) securityLockoutMinutes.value = String(data.login_lockout_minutes ?? 15);

        if (geoBlockEnabled) geoBlockEnabled.classList.toggle('on', !!data.geo_block_enabled);
        if (geoBlockCountries) geoBlockCountries.value = data.geo_block_countries || '';
        if (geoBlockAllowIps) geoBlockAllowIps.value = data.geo_block_allow_ips || '';
    }

    async function loadGeoStatus() {
        if (!geoCurrentInfo) return;
        try {
            const res = await fetch('/api/geo-status.php');
            const data = await res.json();
            geoCurrentInfo.textContent = 'Jelenlegi IP-címed: ' + data.ip +
                (data.country ? ' (' + data.country + ')' : ' (ország nem állapítható meg)');
        } catch (e) {
            geoCurrentInfo.textContent = '';
        }
    }

    if (geoAddOwnIpBtn) {
        geoAddOwnIpBtn.addEventListener('click', async () => {
            geoAddOwnIpBtn.disabled = true;
            try {
                const res = await fetch('/api/geo-status.php');
                const data = await res.json();
                if (!data.ip) throw new Error('ismeretlen IP-cím');
                const existing = geoBlockAllowIps.value.split(',').map(s => s.trim()).filter(Boolean);
                if (!existing.includes(data.ip)) {
                    existing.push(data.ip);
                    geoBlockAllowIps.value = existing.join(', ');
                }
            } catch (e) {
                alert('A saját IP-cím lekérdezése sikertelen: ' + e.message);
            }
            geoAddOwnIpBtn.disabled = false;
        });
    }

    if (wcBarcodeSource) {
        wcBarcodeSource.addEventListener('change', () => {
            if (wcBarcodeMetaWrap) wcBarcodeMetaWrap.classList.toggle('hidden', wcBarcodeSource.value !== 'meta');
        });
    }

    function toggleProviderFields(provider) {
        if (backupDropboxFields) backupDropboxFields.classList.toggle('hidden', provider !== 'dropbox');
        if (backupGoogleFields) backupGoogleFields.classList.toggle('hidden', provider !== 'googledrive');
    }
    if (backupProvider) {
        backupProvider.addEventListener('change', () => toggleProviderFields(backupProvider.value));
    }

    async function loadBackupList() {
        if (!backupListEl) return;
        try {
            const res = await fetch('/api/backup-list.php');
            const data = await res.json();
            const backups = data.backups || [];
            backupListEl.innerHTML = backups.length
                ? backups.map(b => `
                    <div class="search-result-item">
                        <span style="font-size:12px;">${b.created_at} — ${b.filename} (${Math.round(b.size / 1024)} KB)</span>
                        <button type="button" class="row-actions-btn restore-backup-btn" data-filename="${b.filename}">Visszaállítás</button>
                    </div>
                `).join('')
                : '<p class="muted" style="font-size:12px;">Még nincs mentés.</p>';

            backupListEl.querySelectorAll('.restore-backup-btn').forEach(btn => {
                btn.addEventListener('click', () => restoreFromBackup(btn.dataset.filename));
            });
        } catch (e) {
            backupListEl.textContent = '';
        }
    }

    async function restoreFromBackup(filename) {
        const confirmed = confirm(
            `Biztosan visszaállítod ezt a mentést: "${filename}"?\n\n` +
            `A jelenlegi adatok felülíródnak (bár erről is készül egy biztonsági mentés visszaállítás előtt).`
        );
        if (!confirmed) return;

        const restoreFeedback = document.getElementById('restore-feedback');
        if (restoreFeedback) {
            restoreFeedback.textContent = 'Visszaállítás folyamatban...';
            restoreFeedback.className = 'modal-feedback';
        }
        try {
            const formData = new FormData();
            formData.append('filename', filename);
            try {
                const staffRaw = localStorage.getItem('sm_current_staff');
                if (staffRaw) formData.append('staff_id', JSON.parse(staffRaw).id);
            } catch (e) { /* ignore corrupt storage */ }
            const res = await fetch('/api/backup-restore.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
            if (restoreFeedback) {
                restoreFeedback.textContent = `Sikeres visszaállítás. Biztonsági mentés a korábbi állapotról: ${data.safety_backup}`;
                restoreFeedback.className = 'modal-feedback';
            }
            loadBackupList();
        } catch (err) {
            if (restoreFeedback) {
                restoreFeedback.textContent = 'Hiba: ' + err.message;
                restoreFeedback.className = 'modal-feedback error';
            }
        }
    }

    const restoreFileInput = document.getElementById('restore-file-input');
    const restoreFileBtn = document.getElementById('restore-file-btn');
    if (restoreFileBtn) {
        restoreFileBtn.addEventListener('click', async () => {
            const restoreFeedback = document.getElementById('restore-feedback');
            if (!restoreFileInput.files.length) {
                restoreFeedback.textContent = 'Válassz ki egy fájlt.';
                restoreFeedback.className = 'modal-feedback error';
                return;
            }
            const confirmed = confirm(
                'Biztosan visszaállítod az adatbázist a feltöltött fájlból?\n\n' +
                'A jelenlegi adatok felülíródnak (bár erről is készül egy biztonsági mentés visszaállítás előtt).'
            );
            if (!confirmed) return;

            restoreFeedback.textContent = 'Feltöltés és visszaállítás folyamatban...';
            restoreFeedback.className = 'modal-feedback';
            try {
                const formData = new FormData();
                formData.append('file', restoreFileInput.files[0]);
                try {
                    const staffRaw = localStorage.getItem('sm_current_staff');
                    if (staffRaw) formData.append('staff_id', JSON.parse(staffRaw).id);
                } catch (e) { /* ignore corrupt storage */ }
                const res = await fetch('/api/backup-restore.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                restoreFeedback.textContent = `Sikeres visszaállítás. Biztonsági mentés a korábbi állapotról: ${data.safety_backup}`;
                restoreFeedback.className = 'modal-feedback';
                restoreFileInput.value = '';
                loadBackupList();
            } catch (err) {
                restoreFeedback.textContent = 'Hiba: ' + err.message;
                restoreFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (syncBtn) {
        syncBtn.addEventListener('click', async () => {
            if (syncBtn.classList.contains('syncing')) return;
            syncBtn.classList.add('syncing');
            try {
                const res = await fetch('/api/sync-pull.php');
                const data = await res.json();
                if (!res.ok) {
                    showToast('Hiba: ' + (data.error || 'ismeretlen hiba'), 'error');
                } else {
                    showToast(`Szinkronizálva: ${data.imported} termék frissítve, ${data.skipped} kihagyva.`, 'ok');
                    document.dispatchEvent(new CustomEvent('stockmanager:synced', { detail: data }));
                }
            } catch (err) {
                showToast('Hiba: ' + err.message, 'error');
            } finally {
                syncBtn.classList.remove('syncing');
            }
        });
    }

    if (settingsSaveSyncBtn) {
        settingsSaveSyncBtn.addEventListener('click', async () => {
            settingsSyncFeedback.textContent = 'Mentés...';
            settingsSyncFeedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        auto_sync_enabled: autoSyncEnabled.checked,
                        auto_sync_interval_minutes: parseInt(autoSyncInterval.value, 10),
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                applySettings(data);
                settingsSyncFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsSyncFeedback.classList.add('saved-flash');
                setTimeout(() => settingsSyncFeedback.classList.remove('saved-flash'), 1200);
            } catch (err) {
                settingsSyncFeedback.textContent = 'Hiba: ' + err.message;
                settingsSyncFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (logoFileInput) {
        logoFileInput.addEventListener('change', () => {
            if (logoFileInput.files.length && logoPreview) {
                logoPreview.src = URL.createObjectURL(logoFileInput.files[0]);
            }
        });
    }

    if (logoUploadBtn) {
        logoUploadBtn.addEventListener('click', async () => {
            if (!logoFileInput.files.length) {
                settingsLogoFeedback.textContent = 'Válassz egy képfájlt.';
                settingsLogoFeedback.className = 'modal-feedback error';
                return;
            }
            settingsLogoFeedback.textContent = 'Feltöltés...';
            settingsLogoFeedback.className = 'modal-feedback';

            const formData = new FormData();
            formData.append('logo', logoFileInput.files[0]);

            try {
                const res = await fetch('/api/logo-upload.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                if (logoPreview) logoPreview.src = data.logo_url;
                settingsLogoFeedback.textContent = 'Logó frissítve.';
                logoFileInput.value = '';
            } catch (err) {
                settingsLogoFeedback.textContent = 'Hiba: ' + err.message;
                settingsLogoFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (logoResetBtn) {
        logoResetBtn.addEventListener('click', async () => {
            try {
                const res = await fetch('/api/logo-reset.php', { method: 'POST' });
                const data = await res.json();
                if (logoPreview) logoPreview.src = data.logo_url;
                settingsLogoFeedback.textContent = 'Alaplogó visszaállítva.';
                settingsLogoFeedback.className = 'modal-feedback';
            } catch (err) {
                settingsLogoFeedback.textContent = 'Hiba: ' + err.message;
                settingsLogoFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (printLogoFileInput) {
        printLogoFileInput.addEventListener('change', () => {
            if (printLogoFileInput.files.length && printLogoPreview) {
                printLogoPreview.src = URL.createObjectURL(printLogoFileInput.files[0]);
            }
        });
    }

    if (printLogoUploadBtn) {
        printLogoUploadBtn.addEventListener('click', async () => {
            if (!printLogoFileInput.files.length) {
                settingsPrintLogoFeedback.textContent = 'Válassz egy képfájlt.';
                settingsPrintLogoFeedback.className = 'modal-feedback error';
                return;
            }
            settingsPrintLogoFeedback.textContent = 'Feltöltés...';
            settingsPrintLogoFeedback.className = 'modal-feedback';

            const formData = new FormData();
            formData.append('print_logo', printLogoFileInput.files[0]);

            try {
                const res = await fetch('/api/print-logo-upload.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                if (printLogoPreview) printLogoPreview.src = data.print_logo_url;
                settingsPrintLogoFeedback.textContent = 'Nyomtatási logó frissítve.';
                printLogoFileInput.value = '';
            } catch (err) {
                settingsPrintLogoFeedback.textContent = 'Hiba: ' + err.message;
                settingsPrintLogoFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (printLogoResetBtn) {
        printLogoResetBtn.addEventListener('click', async () => {
            try {
                await fetch('/api/print-logo-reset.php', { method: 'POST' });
                if (printLogoPreview) printLogoPreview.src = 'assets/logo-default.svg';
                settingsPrintLogoFeedback.textContent = 'Alaplogóra visszaállítva.';
                settingsPrintLogoFeedback.className = 'modal-feedback';
            } catch (err) {
                settingsPrintLogoFeedback.textContent = 'Hiba: ' + err.message;
                settingsPrintLogoFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (settingsSavePrinterBtn) {
        settingsSavePrinterBtn.addEventListener('click', async () => {
            settingsPrinterFeedback.textContent = 'Mentés...';
            settingsPrinterFeedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        printer_enabled: printerEnabled.checked,
                        printer_ip: printerIp.value.trim(),
                        printer_port: parseInt(printerPort.value, 10) || 9100,
                        printer_paper_width: parseInt(printerPaperWidth.value, 10) || 42,
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                applySettings(data);
                settingsPrinterFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsPrinterFeedback.classList.add('saved-flash');
                setTimeout(() => settingsPrinterFeedback.classList.remove('saved-flash'), 1200);
            } catch (err) {
                settingsPrinterFeedback.textContent = 'Hiba: ' + err.message;
                settingsPrinterFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (printerTestBtn) {
        printerTestBtn.addEventListener('click', async () => {
            if (!printerIp.value.trim()) {
                settingsPrinterFeedback.textContent = 'Add meg a nyomtató IP címét.';
                settingsPrinterFeedback.className = 'modal-feedback error';
                return;
            }
            settingsPrinterFeedback.textContent = 'Nyomtatás...';
            settingsPrinterFeedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/printer-test.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        printer_ip: printerIp.value.trim(),
                        printer_port: parseInt(printerPort.value, 10) || 9100,
                        printer_paper_width: parseInt(printerPaperWidth.value, 10) || 42,
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                settingsPrinterFeedback.textContent = 'Teszt nyugta elküldve a nyomtatóra.';
            } catch (err) {
                settingsPrinterFeedback.textContent = 'Hiba: ' + err.message;
                settingsPrinterFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (settingsSaveBackupBtn) {
        settingsSaveBackupBtn.addEventListener('click', async () => {
            settingsBackupFeedback.textContent = 'Mentés...';
            settingsBackupFeedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        backup_enabled: backupEnabled.checked,
                        backup_time: backupTime.value.trim() || '23:30',
                        backup_retention_count: parseInt(backupRetention.value, 10) || 7,
                        backup_provider: backupProvider.value,
                        dropbox_access_token: dropboxAccessToken.value.trim(),
                        dropbox_folder: dropboxFolder.value.trim(),
                        google_client_id: googleClientId.value.trim(),
                        google_client_secret: googleClientSecret.value.trim(),
                        google_refresh_token: googleRefreshToken.value.trim(),
                        google_folder_id: googleFolderId.value.trim(),
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                applySettings(data);
                settingsBackupFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsBackupFeedback.classList.add('saved-flash');
                setTimeout(() => settingsBackupFeedback.classList.remove('saved-flash'), 1200);
            } catch (err) {
                settingsBackupFeedback.textContent = 'Hiba: ' + err.message;
                settingsBackupFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (backupNowBtn) {
        backupNowBtn.addEventListener('click', async () => {
            backupNowBtn.disabled = true;
            settingsBackupFeedback.textContent = 'Mentés folyamatban...';
            settingsBackupFeedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/backup-now.php', { method: 'POST' });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                settingsBackupFeedback.textContent = data.summary;
                loadSettings();
                loadBackupList();
            } catch (err) {
                settingsBackupFeedback.textContent = 'Hiba: ' + err.message;
                settingsBackupFeedback.className = 'modal-feedback error';
            } finally {
                backupNowBtn.disabled = false;
            }
        });
    }

    if (settingsSaveSzamlazzBtn) {
        settingsSaveSzamlazzBtn.addEventListener('click', async () => {
            settingsSzamlazzFeedback.textContent = 'Mentés...';
            settingsSzamlazzFeedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        szamlazz_agent_key: szamlazzAgentKey.value.trim(),
                        szamlazz_default_payment: szamlazzDefaultPayment.value,
                        szamlazz_default_vat: szamlazzDefaultVat.value,
                        szamlazz_send_email: szamlazzSendEmail.checked,
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                applySettings(data);
                settingsSzamlazzFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsSzamlazzFeedback.classList.add('saved-flash');
                setTimeout(() => settingsSzamlazzFeedback.classList.remove('saved-flash'), 1200);
            } catch (err) {
                settingsSzamlazzFeedback.textContent = 'Hiba: ' + err.message;
                settingsSzamlazzFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (settingsSaveNavBtn) {
        settingsSaveNavBtn.addEventListener('click', async () => {
            settingsNavFeedback.textContent = 'Mentés...';
            settingsNavFeedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        nav_login: navLogin.value.trim(),
                        nav_password: navPassword.value,
                        nav_signer_key: navSignerKey.value.trim(),
                        nav_exchange_key: navExchangeKey.value.trim(),
                        nav_tax_number: navTaxNumber.value.trim(),
                        nav_test_mode: navTestMode.checked,
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                applySettings(data);
                settingsNavFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsNavFeedback.classList.add('saved-flash');
                setTimeout(() => settingsNavFeedback.classList.remove('saved-flash'), 1200);
            } catch (err) {
                settingsNavFeedback.textContent = 'Hiba: ' + err.message;
                settingsNavFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (settingsSaveWcBtn) {
        settingsSaveWcBtn.addEventListener('click', async () => {
            settingsWcFeedback.textContent = 'Mentés...';
            settingsWcFeedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        wc_store_url: wcStoreUrl.value.trim(),
                        wc_consumer_key: wcConsumerKey.value.trim(),
                        wc_consumer_secret: wcConsumerSecret.value.trim(),
                        wc_barcode_source: wcBarcodeSource.value,
                        wc_barcode_meta_key: wcBarcodeMetaKey.value.trim(),
                        wc_webhook_secret: wcWebhookSecret.value.trim(),
                        wc_public_base_url: wcPublicBaseUrl.value.trim(),
                        product_image_size: parseInt(productImageSize.value, 10) || 1200,
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                applySettings(data);
                settingsWcFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsWcFeedback.classList.add('saved-flash');
                setTimeout(() => settingsWcFeedback.classList.remove('saved-flash'), 1200);
            } catch (err) {
                settingsWcFeedback.textContent = 'Hiba: ' + err.message;
                settingsWcFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (settingsSaveLowstockBtn) {
        settingsSaveLowstockBtn.addEventListener('click', async () => {
            settingsLowstockFeedback.textContent = 'Mentés...';
            settingsLowstockFeedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        low_stock_default_threshold: parseInt(lowStockDefault.value, 10) || 0,
                        low_stock_notify_webhook: lowStockWebhook.value.trim(),
                        low_stock_notify_email: lowStockEmail.value.trim(),
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                applySettings(data);
                settingsLowstockFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsLowstockFeedback.classList.add('saved-flash');
                setTimeout(() => settingsLowstockFeedback.classList.remove('saved-flash'), 1200);
            } catch (err) {
                settingsLowstockFeedback.textContent = 'Hiba: ' + err.message;
                settingsLowstockFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (settingsSaveReceiptBtn) {
        settingsSaveReceiptBtn.addEventListener('click', async () => {
            settingsReceiptFeedback.textContent = 'Mentés...';
            settingsReceiptFeedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        receipt_header_lines: receiptHeaderLines.value,
                        receipt_footer_lines: receiptFooterLines.value,
                        receipt_show_logo: receiptShowLogo.checked,
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                applySettings(data);
                settingsReceiptFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsReceiptFeedback.classList.add('saved-flash');
                setTimeout(() => settingsReceiptFeedback.classList.remove('saved-flash'), 1200);
            } catch (err) {
                settingsReceiptFeedback.textContent = 'Hiba: ' + err.message;
                settingsReceiptFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (settingsSaveLoyaltyBtn) {
        settingsSaveLoyaltyBtn.addEventListener('click', async () => {
            settingsLoyaltyFeedback.textContent = 'Mentés...';
            settingsLoyaltyFeedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        loyalty_enabled: loyaltyEnabled.checked,
                        loyalty_huf_per_point: parseInt(loyaltyHufPerPoint.value, 10) || 100,
                        loyalty_point_value_huf: parseInt(loyaltyPointValue.value, 10) || 0,
                        loyalty_tier_silver_threshold: parseInt(tierSilverThreshold.value, 10) || 0,
                        loyalty_tier_silver_discount: parseFloat(tierSilverDiscount.value) || 0,
                        loyalty_tier_gold_threshold: parseInt(tierGoldThreshold.value, 10) || 0,
                        loyalty_tier_gold_discount: parseFloat(tierGoldDiscount.value) || 0,
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                applySettings(data);
                settingsLoyaltyFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsLoyaltyFeedback.classList.add('saved-flash');
                setTimeout(() => settingsLoyaltyFeedback.classList.remove('saved-flash'), 1200);
            } catch (err) {
                settingsLoyaltyFeedback.textContent = 'Hiba: ' + err.message;
                settingsLoyaltyFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (settingsSaveAuditBtn) {
        settingsSaveAuditBtn.addEventListener('click', async () => {
            settingsAuditFeedback.textContent = 'Mentés...';
            settingsAuditFeedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        audit_log_retention_days: parseInt(auditRetentionDays.value, 10) || 30,
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                applySettings(data);
                settingsAuditFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsAuditFeedback.classList.add('saved-flash');
                setTimeout(() => settingsAuditFeedback.classList.remove('saved-flash'), 1200);
            } catch (err) {
                settingsAuditFeedback.textContent = 'Hiba: ' + err.message;
                settingsAuditFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (securityPasswordEnabled) {
        securityPasswordEnabled.addEventListener('click', () => securityPasswordEnabled.classList.toggle('on'));
    }

    if (settingsSaveSecurityBtn) {
        settingsSaveSecurityBtn.addEventListener('click', async () => {
            settingsSecurityFeedback.textContent = 'Mentés...';
            settingsSecurityFeedback.className = 'modal-feedback';
            try {
                const payload = {
                    app_password_enabled: securityPasswordEnabled.classList.contains('on'),
                    session_timeout_minutes: parseInt(securitySessionTimeout.value, 10) || 240,
                    login_max_attempts: parseInt(securityMaxAttempts.value, 10) || 5,
                    login_lockout_minutes: parseInt(securityLockoutMinutes.value, 10) || 15,
                };
                if (securityNewPassword.value) {
                    payload.new_password = securityNewPassword.value;
                    payload.new_password_confirm = securityNewPasswordConfirm.value;
                }
                const res = await fetch('/api/security-settings-save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                applySettings(data);
                securityNewPassword.value = '';
                securityNewPasswordConfirm.value = '';
                settingsSecurityFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsSecurityFeedback.classList.add('saved-flash');
                setTimeout(() => settingsSecurityFeedback.classList.remove('saved-flash'), 1200);
            } catch (err) {
                settingsSecurityFeedback.textContent = 'Hiba: ' + err.message;
                settingsSecurityFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (securityLogoutBtn) {
        securityLogoutBtn.addEventListener('click', async () => {
            try {
                await fetch('/api/logout.php', { method: 'POST' });
            } catch (e) { /* proceed to redirect regardless */ }
            location.href = 'login.html';
        });
    }

    // --- Fizetési módok: bővíthető lista (kassza + beérkező webshop-
    // rendelések fizetésimód-választója innen olvassa a beállítást). ---
    function renderPaymentMethodsList() {
        if (!paymentMethodsList) return;
        paymentMethodsList.innerHTML = currentPaymentMethods.map((m, idx) => `
            <div class="search-result-item" data-idx="${idx}">
                <span><span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:${escapeHtml(m.color || '#64748b')}; margin-right:8px; vertical-align:-1px;"></span>${escapeHtml(m.value)}</span>
                <button type="button" class="row-actions-btn danger payment-method-remove-btn" data-idx="${idx}"${currentPaymentMethods.length <= 1 ? ' disabled' : ''}>Törlés</button>
            </div>
        `).join('');
        paymentMethodsList.querySelectorAll('.payment-method-remove-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                currentPaymentMethods.splice(parseInt(btn.dataset.idx, 10), 1);
                renderPaymentMethodsList();
            });
        });
    }

    const PAYMENT_METHOD_COLOR_PALETTE = ['#16a34a', '#a855f7', '#3b82f6', '#14b8a6', '#f97316', '#e11d48', '#64748b', '#eab308'];

    if (paymentMethodsAddBtn) {
        paymentMethodsAddBtn.addEventListener('click', () => {
            const value = (paymentMethodsAddInput.value || '').trim();
            if (!value) return;
            if (currentPaymentMethods.some(m => m.value.toLowerCase() === value.toLowerCase())) {
                paymentMethodsAddInput.value = '';
                return;
            }
            const color = PAYMENT_METHOD_COLOR_PALETTE[currentPaymentMethods.length % PAYMENT_METHOD_COLOR_PALETTE.length];
            currentPaymentMethods.push({ value, color });
            paymentMethodsAddInput.value = '';
            renderPaymentMethodsList();
        });
    }
    if (paymentMethodsAddInput) {
        paymentMethodsAddInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); paymentMethodsAddBtn.click(); }
        });
    }
    if (settingsSavePaymentMethodsBtn) {
        settingsSavePaymentMethodsBtn.addEventListener('click', async () => {
            settingsPaymentMethodsFeedback.textContent = 'Mentés...';
            settingsPaymentMethodsFeedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ payment_methods: currentPaymentMethods }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                applySettings(data);
                settingsPaymentMethodsFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsPaymentMethodsFeedback.classList.add('saved-flash');
                setTimeout(() => settingsPaymentMethodsFeedback.classList.remove('saved-flash'), 1200);
            } catch (err) {
                settingsPaymentMethodsFeedback.textContent = 'Hiba: ' + err.message;
                settingsPaymentMethodsFeedback.className = 'modal-feedback error';
            }
        });
    }

    async function loadBrandMappingUi() {
        if (!brandMappingList) return;
        brandMappingList.innerHTML = '<p class="muted" style="font-size:12px;">Betöltés...</p>';
        try {
            const [localRes, wcRes] = await Promise.all([
                fetch('/api/product-brands-list.php'),
                fetch('/api/wc-brands-list.php'),
            ]);
            const localData = await localRes.json();
            const wcData = await wcRes.json();
            const localBrands = localData.brands || [];
            const wcBrands = wcRes.ok ? (wcData.brands || []) : null;

            if (localBrands.length === 0) {
                brandMappingList.innerHTML = '<p class="muted" style="font-size:12px;">Még nincs egyetlen árucikkhez sem márka megadva.</p>';
                return;
            }
            if (!wcBrands) {
                brandMappingList.innerHTML = '<p class="muted" style="font-size:12px;">A WooCommerce márkák betöltése sikertelen: '
                    + escapeHtml(wcData.error || 'ismeretlen hiba') + '</p>';
                return;
            }

            const options = '<option value="">— helyi néven, ahogy van —</option>' +
                wcBrands.map(b => `<option value="${escapeHtml(b.name)}">${escapeHtml(b.name)}</option>`).join('');

            brandMappingList.innerHTML = localBrands.map(brand => `
                <div class="field-row" style="align-items:center; margin-top:0;">
                    <div style="align-self:center;">${escapeHtml(brand)}</div>
                    <div>
                        <select data-local-brand="${escapeHtml(brand)}" class="brand-mapping-select">${options}</select>
                    </div>
                </div>
            `).join('');

            brandMappingList.querySelectorAll('.brand-mapping-select').forEach(sel => {
                const local = sel.dataset.localBrand;
                if (currentBrandMapping[local]) sel.value = currentBrandMapping[local];
            });
        } catch (e) {
            brandMappingList.innerHTML = '<p class="muted" style="font-size:12px;">Betöltési hiba.</p>';
        }
    }

    if (brandMappingReloadBtn) {
        brandMappingReloadBtn.addEventListener('click', loadBrandMappingUi);
    }

    if (settingsSaveBrandMappingBtn) {
        settingsSaveBrandMappingBtn.addEventListener('click', async () => {
            settingsBrandMappingFeedback.textContent = 'Mentés...';
            settingsBrandMappingFeedback.className = 'modal-feedback';
            const mapping = {};
            brandMappingList.querySelectorAll('.brand-mapping-select').forEach(sel => {
                if (sel.value) mapping[sel.dataset.localBrand] = sel.value;
            });
            try {
                const res = await fetch('/api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ brand_mapping: mapping }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                applySettings(data);
                settingsBrandMappingFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsBrandMappingFeedback.classList.add('saved-flash');
                setTimeout(() => settingsBrandMappingFeedback.classList.remove('saved-flash'), 1200);
            } catch (err) {
                settingsBrandMappingFeedback.textContent = 'Hiba: ' + err.message;
                settingsBrandMappingFeedback.className = 'modal-feedback error';
            }
        });
    }

    if (geoBlockEnabled) {
        geoBlockEnabled.addEventListener('click', () => geoBlockEnabled.classList.toggle('on'));
    }

    if (settingsSaveGeoBtn) {
        settingsSaveGeoBtn.addEventListener('click', async () => {
            settingsGeoFeedback.textContent = 'Mentés...';
            settingsGeoFeedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/security-settings-save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        geo_block_enabled: geoBlockEnabled.classList.contains('on'),
                        geo_block_countries: geoBlockCountries.value,
                        geo_block_allow_ips: geoBlockAllowIps.value,
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                applySettings(data);
                settingsGeoFeedback.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Mentve';
                settingsGeoFeedback.classList.add('saved-flash');
                setTimeout(() => settingsGeoFeedback.classList.remove('saved-flash'), 1200);
            } catch (err) {
                settingsGeoFeedback.textContent = 'Hiba: ' + err.message;
                settingsGeoFeedback.className = 'modal-feedback error';
            }
        });
    }

    loadSettings();
    loadBackupList();
    loadGeoStatus();
    if (brandMappingList) {
        window.smSettingsPromise.then(() => loadBrandMappingUi());
    }
})();

// =========================================================================
// GLOBÁLIS KERESŐ (Ctrl+K) + ÉRTESÍTÉSI KÖZPONT
// Mindkettő innen kerül be minden oldal felső sávjába, ahelyett hogy
// minden egyes oldal HTML-jét külön kellene szerkeszteni — a
// .topbar-actions már egységesen jelen van minden oldalon, ami betölti
// ezt a szkriptet.
// =========================================================================
(function () {
    const topbarActions = document.querySelector('.topbar-actions');
    if (!topbarActions) return;

    // --- Globális kereső ---
    const searchBtn = document.createElement('button');
    searchBtn.className = 'icon-btn';
    searchBtn.title = 'Globális kereső (Ctrl+K)';
    searchBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';
    topbarActions.insertBefore(searchBtn, topbarActions.firstChild);

    const searchModal = document.createElement('div');
    searchModal.className = 'modal-overlay';
    searchModal.id = 'global-search-modal';
    searchModal.innerHTML = `
        <div class="modal-card" style="max-width:560px; padding-top:16px;">
            <input type="text" id="global-search-input" placeholder="Keresés termékben, vásárlóban, eladásban..." style="font-size:16px;">
            <div id="global-search-results" style="max-height:400px; overflow-y:auto;"></div>
        </div>
    `;
    document.body.appendChild(searchModal);

    const searchInput = document.getElementById('global-search-input');
    const searchResults = document.getElementById('global-search-results');

    function openSearch() {
        searchModal.classList.add('open');
        searchInput.value = '';
        searchResults.innerHTML = '';
        setTimeout(() => searchInput.focus(), 50);
    }
    function closeSearch() {
        searchModal.classList.remove('open');
    }

    searchBtn.addEventListener('click', openSearch);
    searchModal.addEventListener('click', (e) => { if (e.target === searchModal) closeSearch(); });
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            searchModal.classList.contains('open') ? closeSearch() : openSearch();
        }
        if (e.key === 'Escape' && searchModal.classList.contains('open')) closeSearch();
    });

    let searchDebounce = null;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchDebounce);
        const q = searchInput.value.trim();
        if (q.length < 2) {
            searchResults.innerHTML = '';
            return;
        }
        searchDebounce = setTimeout(async () => {
            try {
                const res = await fetch('/api/global-search.php?query=' + encodeURIComponent(q));
                const data = await res.json();
                renderSearchResults(data);
            } catch (e) {
                searchResults.innerHTML = '<p class="feedback error">A keresés sikertelen.</p>';
            }
        }, 200);
    });

    function fmtMoney(n) {
        return new Intl.NumberFormat('hu-HU').format(Math.round(n)) + ' Ft';
    }

    function renderSearchResults(data) {
        const sections = [];
        if (data.products && data.products.length) {
            sections.push('<p class="muted" style="margin:10px 0 4px;">Termékek</p>' + data.products.map(p => `
                <div class="search-result-item" onclick="location.href='termekek.php'">
                    <span>${escapeHtml(p.name)}${p.barcode ? ' · ' + escapeHtml(p.barcode) : ''}</span>
                    <span>${fmtMoney(p.price)} · ${p.stock_qty} db</span>
                </div>
            `).join(''));
        }
        if (data.customers && data.customers.length) {
            sections.push('<p class="muted" style="margin:10px 0 4px;">Vásárlók</p>' + data.customers.map(c => `
                <div class="search-result-item" onclick="location.href='vasarlok.php'">
                    <span>${escapeHtml(c.name)}</span>
                    <span>${escapeHtml(c.phone || c.email || '')}</span>
                </div>
            `).join(''));
        }
        if (data.sales && data.sales.length) {
            sections.push('<p class="muted" style="margin:10px 0 4px;">Eladások</p>' + data.sales.map(s => `
                <div class="search-result-item" onclick="location.href='eladasok.php'">
                    <span>Eladás #${s.id}${s.buyer_name ? ' · ' + escapeHtml(s.buyer_name) : ''}</span>
                    <span>${fmtMoney(s.total)} · ${s.created_at}</span>
                </div>
            `).join(''));
        }
        searchResults.innerHTML = sections.length ? sections.join('') : '<p class="muted" style="margin-top:10px;">Nincs találat.</p>';
    }

    // --- Értesítési központ ---
    const notifBtn = document.createElement('button');
    notifBtn.className = 'icon-btn';
    notifBtn.title = 'Értesítések';
    notifBtn.style.position = 'relative';
    notifBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg><span class="badge-dot hidden" id="notif-badge-dot"></span>';
    topbarActions.insertBefore(notifBtn, topbarActions.firstChild);

    const notifDropdown = document.createElement('div');
    notifDropdown.id = 'notif-dropdown';
    notifDropdown.style.cssText = 'display:none; position:fixed; top:56px; right:12px; width:min(320px, calc(100vw - 24px)); background:var(--panel); border:1px solid var(--border); border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,0.15); padding:14px; z-index:50; max-height:70vh; overflow-y:auto;';
    document.body.appendChild(notifDropdown);

    async function loadNotifications() {
        try {
            const res = await fetch('/api/system-status.php');
            const data = await res.json();
            const alerts = [];
            if (data.webshop_orders_draft_count > 0) {
                alerts.push({ text: `${data.webshop_orders_draft_count} új rendelés a webáruházból`, href: 'beerkezo-eladasok.php' });
            }
            if (data.low_stock_count > 0) {
                alerts.push({ text: `${data.low_stock_count} termék alacsony készleten`, href: 'termekek.php' });
            }
            if (data.invoice_failures_7d > 0) {
                alerts.push({ text: `${data.invoice_failures_7d} sikertelen számla (7 nap)`, href: 'rendszerallapot.php' });
            }
            if (data.sync_failures_24h > 0) {
                alerts.push({ text: `${data.sync_failures_24h} sync hiba (24 óra)`, href: 'rendszerallapot.php' });
            }

            document.getElementById('notif-badge-dot').style.display = alerts.length > 0 ? 'block' : 'none';
            notifDropdown.innerHTML = alerts.length
                ? alerts.map(a => `<div class="search-result-item" onclick="location.href='${a.href}'" style="cursor:pointer;"><span>${a.text}</span></div>`).join('')
                : '<p class="muted" style="margin:0;">Nincs aktív értesítés.</p>';
            refreshWebshopOrderBadge(data.webshop_orders_draft_count || 0);
        } catch (e) {
            notifDropdown.innerHTML = '<p class="muted" style="margin:0;">Az értesítések betöltése sikertelen.</p>';
        }
    }

    notifBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = notifDropdown.style.display === 'block';
        notifDropdown.style.display = isOpen ? 'none' : 'block';
        if (!isOpen) loadNotifications();
    });
    document.addEventListener('click', (e) => {
        if (!notifDropdown.contains(e.target) && e.target !== notifBtn) {
            notifDropdown.style.display = 'none';
        }
    });

    loadNotifications();

    // --- Beérkező webshop-rendelések: piros pötty a sidebaron + felugró
    // értesítés, ha a piszkozatok száma nő két lekérdezés között. A
    // lekérdezés a system-status.php-t használja (amit a fenti bell-ikon
    // is hív), ezért itt csak a sidebar-badge frissítésére és az
    // esetleges popupra koncentrál — a bell-dropdown tartalmát a fenti
    // loadNotifications() adja.
    let lastKnownDraftCount = null;

    function refreshWebshopOrderBadge(count) {
        const badge = document.getElementById('sidebar-webshop-badge');
        if (badge) badge.classList.toggle('hidden', count === 0);
    }

    function showOrderPopup(newCount, total) {
        const popup = document.createElement('div');
        popup.className = 'sync-toast show ok';
        popup.style.cursor = 'pointer';
        popup.style.zIndex = '60';
        popup.textContent = newCount === 1
            ? `Új rendelés érkezett a webáruházból! (összesen ${total} feldolgozatlan)`
            : `${newCount} új rendelés érkezett a webáruházból! (összesen ${total} feldolgozatlan)`;
        popup.addEventListener('click', () => { location.href = 'beerkezo-eladasok.php'; });
        document.body.appendChild(popup);
        setTimeout(() => popup.remove(), 7000);
    }

    async function pollWebshopOrders() {
        try {
            const res = await fetch('/api/system-status.php');
            const data = await res.json();
            const count = data.webshop_orders_draft_count || 0;
            refreshWebshopOrderBadge(count);
            if (lastKnownDraftCount !== null && count > lastKnownDraftCount && !location.pathname.endsWith('/beerkezo-eladasok.php')) {
                showOrderPopup(count - lastKnownDraftCount, count);
                document.getElementById('notif-badge-dot').style.display = 'block';
            }
            lastKnownDraftCount = count;
        } catch (e) { /* a következő lekérdezés úgyis újrapróbálja */ }
    }
    pollWebshopOrders();
    setInterval(pollWebshopOrders, 25000);

    // --- Sötét/világos gyorsváltó ---
    const themeToggleBtn = document.createElement('button');
    themeToggleBtn.className = 'icon-btn';
    themeToggleBtn.title = 'Sötét/világos sablon váltása';
    function renderThemeToggleIcon() {
        const isLight = document.documentElement.getAttribute('data-theme') === 'light';
        themeToggleBtn.innerHTML = isLight
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>';
    }
    renderThemeToggleIcon();
    themeToggleBtn.addEventListener('click', () => {
        const isLight = document.documentElement.getAttribute('data-theme') === 'light';
        if (window.smSaveTheme) window.smSaveTheme(isLight ? 'dark' : 'light');
        renderThemeToggleIcon();
    });
    topbarActions.insertBefore(themeToggleBtn, topbarActions.firstChild);

    // --- Súgó ---
    if (!location.pathname.endsWith('/utmutato.php')) {
        const helpBtn = document.createElement('a');
        helpBtn.href = 'utmutato.php';
        helpBtn.target = '_blank';
        helpBtn.className = 'icon-btn';
        helpBtn.title = 'Útmutató';
        helpBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
        topbarActions.insertBefore(helpBtn, topbarActions.firstChild);
    }

    // --- Escape zárja be a nyitott modalt ---
    // A globális kereső modalnak saját Escape-kezelője már van (lásd
    // fentebb) — az itt a "topmost" nyitott .modal-overlay-t zárja be,
    // ezért a keresőét kihagyja, hogy ne próbálja kétszer bezárni.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const openModals = document.querySelectorAll('.modal-overlay.open');
        if (!openModals.length) return;
        const last = openModals[openModals.length - 1];
        if (last.id === 'global-search-modal') return;
        last.classList.remove('open');
    });
})();
