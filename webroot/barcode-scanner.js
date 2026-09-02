/**
 * Kamerás vonalkód-olvasás, minden "vonalkód" mezőnél megosztva használva.
 * A böngésző natív BarcodeDetector API-ját használja — nincs külső
 * könyvtár, nincs CDN-függőség. 2026 elején csak a Chromium-alapú
 * böngészők (Chrome, Edge, Opera, Android WebView) támogatják; a Safari
 * és a Firefox nem, ott egy egyértelmű üzenet jelenik meg, ami visszairányít
 * a kézi/USB-szkenneres bevitelhez, ahelyett hogy csendben elhasalna.
 *
 * Használat: helyezz el bárhol egy gombot class="camera-scan-btn" és
 * data-target="<a kitöltendő mező id-je>" attribútumokkal. Ez a szkript
 * mindegyiket az oldalon már meglévő, egyetlen közös #camera-scanner-modal
 * ablakhoz köti.
 */
(function () {
    const modal = document.getElementById('camera-scanner-modal');
    if (!modal) return;

    const video = document.getElementById('camera-scanner-video');
    const statusEl = document.getElementById('camera-scanner-status');
    const closeBtn = document.getElementById('camera-scanner-close');

    let stream = null;
    let scanning = false;
    let currentTargetId = null;

    function stopCamera() {
        scanning = false;
        if (stream) {
            stream.getTracks().forEach(t => t.stop());
            stream = null;
        }
        modal.classList.remove('open');
    }

    function fillTargetAndClose(code) {
        stopCamera();
        const input = document.getElementById(currentTargetId);
        if (!input) return;
        input.value = code;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        // A kassza/beszerzés vonalkód-mezői Enterre reagálnak — ezt
        // szimuláljuk, hogy a kamerás szkennelés pontosan úgy viselkedjen,
        // mint egy USB-szkenner, ami "begépeli" a kódot és Entert nyom,
        // így a meglévő kosárba-adás logika változtatás nélkül újrahasznosul.
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        input.focus();
    }

    async function scanFrame(detector) {
        if (!scanning) return;
        try {
            const barcodes = await detector.detect(video);
            if (barcodes.length > 0) {
                fillTargetAndClose(barcodes[0].rawValue);
                return;
            }
        } catch (e) {
        }
        requestAnimationFrame(() => scanFrame(detector));
    }

    async function startScan(targetId) {
        currentTargetId = targetId;
        modal.classList.add('open');
        statusEl.textContent = 'Kamera indítása...';

        if (!('BarcodeDetector' in window)) {
            statusEl.textContent = 'A böngésződ nem támogatja a kamerás vonalkód-olvasást (ez Chrome/Edge böngészőkben működik). Használd a mező kézi kitöltését vagy egy USB-s vonalkódolvasót.';
            return;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        } catch (err) {
            statusEl.textContent = 'Nem sikerült elérni a kamerát: ' + err.message;
            return;
        }

        video.srcObject = stream;
        try {
            await video.play();
        } catch (e) { /* some browsers auto-play once metadata loads anyway */ }

        statusEl.textContent = 'Tartsd a vonalkódot a keretbe...';

        let detector;
        try {
            detector = new BarcodeDetector({
                formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'codabar', 'itf'],
            });
        } catch (e) {
            statusEl.textContent = 'A kamerás vonalkód-felismerés nem támogatott ebben a böngészőben.';
            stopCamera();
            return;
        }

        scanning = true;
        requestAnimationFrame(() => scanFrame(detector));
    }

    document.querySelectorAll('.camera-scan-btn').forEach(btn => {
        btn.addEventListener('click', () => startScan(btn.dataset.target));
    });

    if (closeBtn) closeBtn.addEventListener('click', stopCamera);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) stopCamera();
    });
})();
