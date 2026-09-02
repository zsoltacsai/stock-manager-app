const params = new URLSearchParams(window.location.search);
const saleId = params.get('sale_id');
const receiptToken = params.get('token') || '';

const receiptContent = document.getElementById('receipt-content');
const feedback = document.getElementById('feedback');
const printNetworkBtn = document.getElementById('print-network-btn');

const fmt = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(Number(n) || 0)) + ' Ft';

// Ez az oldal nem tölti be a topbar.js-t (önálló, minimál nyugta-nézet),
// ezért itt egy saját, kicsi másolata van ugyanannak az escape-védelemnek.
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

let saleData = null;

async function loadReceipt() {
    if (!saleId) {
        receiptContent.textContent = 'Hiányzó eladás azonosító.';
        return;
    }
    try {
        const res = await fetch('/api/receipt-detail.php?sale_id=' + encodeURIComponent(saleId) + '&token=' + encodeURIComponent(receiptToken));
        const data = await res.json();
        if (!res.ok) {
            receiptContent.textContent = 'Hiba: ' + (data.error || 'ismeretlen hiba');
            return;
        }
        saleData = data;
        renderReceipt(data);
        printNetworkBtn.style.display = data.printer_enabled ? 'inline-block' : 'none';
    } catch (err) {
        receiptContent.textContent = 'Hiba: ' + err.message;
    }
}

function renderReceipt(data) {
    const sale = data.sale;
    const receipt = data.receipt || { header_lines: [], footer_lines: [], show_logo: false, logo_url: '' };

    const itemsHtml = sale.items.map(item => `
        <div class="item-name">${escapeHtml(item.name)}</div>
        <div class="line">
            <span>${item.qty} x ${fmt(item.unit_price)}</span>
            <span>${fmt(item.unit_price * item.qty)}</span>
        </div>
    `).join('');

    const logoHtml = receipt.show_logo && receipt.logo_url
        ? `<img src="${receipt.logo_url}" alt="Logó" style="max-width:80px; max-height:80px; display:block; margin:0 auto 10px;">`
        : '';

    const headerHtml = receipt.header_lines.length
        ? receipt.header_lines.map((line, i) => i === 0
            ? `<h2>${escapeHtml(line)}</h2>`
            : `<div class="addr">${escapeHtml(line)}</div>`
          ).join('')
        : '';

    const footerHtml = receipt.footer_lines.length
        ? receipt.footer_lines.map(line => `<div class="footer-note">${escapeHtml(line)}</div>`).join('')
        : '';

    const qrTargetUrl = window.location.href;
    const qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=110x110&data=' + encodeURIComponent(qrTargetUrl);
    const qrHtml = `
        <div style="text-align:center; margin-top:14px;">
            <img src="${qrImageUrl}" alt="QR kód" width="90" height="90" style="display:inline-block;" onerror="this.parentElement.style.display='none';">
            <div style="font-size:10px; color:#888; margin-top:4px;">Digitális nyugta megtekintése</div>
        </div>
    `;

    receiptContent.innerHTML = `
        ${logoHtml}
        ${headerHtml}
        <hr>
        <div class="line"><span>Nyugta</span><span>#${sale.id}</span></div>
        <div class="line"><span>Dátum</span><span>${sale.created_at}</span></div>
        ${sale.szamlazz_invoice_number ? `<div class="line"><span>Számla</span><span>${escapeHtml(sale.szamlazz_invoice_number)}</span></div>` : ''}
        <hr>
        ${itemsHtml}
        <hr>
        <div class="total-line"><span>ÖSSZESEN</span><span>${fmt(sale.total)}</span></div>
        <div class="line" style="margin-top:4px;"><span>Fizetés</span><span>${escapeHtml(sale.payment_method)}</span></div>
        ${footerHtml}
        ${qrHtml}
    `;
}

printNetworkBtn.addEventListener('click', async () => {
    feedback.textContent = 'Nyomtatás...';
    feedback.className = '';
    try {
        const res = await fetch('/api/print-receipt.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sale_id: saleId }),
        });
        const data = await res.json();
        if (!res.ok) {
            feedback.textContent = 'Hiba: ' + (data.error || 'ismeretlen hiba');
            feedback.className = 'error';
            return;
        }
        feedback.textContent = 'Elküldve a nyomtatóra.';
        feedback.className = 'ok';
    } catch (err) {
        feedback.textContent = 'Hiba: ' + err.message;
        feedback.className = 'error';
    }
});

loadReceipt();
