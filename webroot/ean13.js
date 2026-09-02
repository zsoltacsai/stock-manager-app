const EAN13 = (function () {
    const L_CODE = {
        '0': '0001101', '1': '0011001', '2': '0010011', '3': '0111101', '4': '0100011',
        '5': '0110001', '6': '0101111', '7': '0111011', '8': '0110111', '9': '0001011',
    };
    const G_CODE = {
        '0': '0100111', '1': '0110011', '2': '0011011', '3': '0100001', '4': '0011101',
        '5': '0111001', '6': '0000101', '7': '0010001', '8': '0001001', '9': '0010111',
    };
    const R_CODE = {
        '0': '1110010', '1': '1100110', '2': '1101100', '3': '1000010', '4': '1011100',
        '5': '1001110', '6': '1010000', '7': '1000100', '8': '1001000', '9': '1110100',
    };
    const PARITY = {
        '0': 'LLLLLL', '1': 'LLGLGG', '2': 'LLGGLG', '3': 'LLGGGL', '4': 'LGLLGG',
        '5': 'LGGLLG', '6': 'LGGGLL', '7': 'LGLGLG', '8': 'LGLGGL', '9': 'LGGLGL',
    };

    function checkDigit(twelve) {
        let sum = 0;
        for (let i = 0; i < 12; i++) {
            sum += (i % 2 === 0) ? Number(twelve[i]) : Number(twelve[i]) * 3;
        }
        return (10 - (sum % 10)) % 10;
    }

    function isValid(code) {
        return /^\d{13}$/.test(code) && checkDigit(code.slice(0, 12)) === Number(code[12]);
    }

    function renderSVG(code, opts) {
        opts = opts || {};
        const barWidth = opts.barWidth || 2;
        const height = opts.height || 80;
        const quietZone = barWidth * 10;

        if (!/^\d{13}$/.test(code)) {
            return '<svg xmlns="http://www.w3.org/2000/svg"><text x="0" y="15">Érvénytelen vonalkód</text></svg>';
        }

        const first = code[0];
        const parity = PARITY[first];
        let bits = '101';
        for (let i = 0; i < 6; i++) {
            const digit = code[1 + i];
            bits += (parity[i] === 'L') ? L_CODE[digit] : G_CODE[digit];
        }
        bits += '01010';
        for (let i = 0; i < 6; i++) {
            bits += R_CODE[code[7 + i]];
        }
        bits += '101';

        const totalWidth = bits.length * barWidth + quietZone * 2;
        let bars = '';
        for (let i = 0; i < bits.length; i++) {
            if (bits[i] === '1') {
                bars += `<rect x="${quietZone + i * barWidth}" y="0" width="${barWidth}" height="${height}" fill="#000"/>`;
            }
        }

        const textY = height + 16;
        const digitsSpaced = first + ' ' + code.slice(1, 7) + ' ' + code.slice(7);

        return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${totalWidth} ${height + 24}" width="${totalWidth}" height="${height + 24}">
            <rect x="0" y="0" width="${totalWidth}" height="${height + 24}" fill="#fff"/>
            ${bars}
            <text x="${totalWidth / 2}" y="${textY}" font-family="monospace" font-size="14" text-anchor="middle" letter-spacing="2">${digitsSpaced}</text>
        </svg>`;
    }

    return { checkDigit, isValid, renderSVG };
})();
