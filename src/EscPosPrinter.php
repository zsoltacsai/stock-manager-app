<?php

/**
 * ESC/POS hálózati (TCP/9100) nyomtató-kliens. Karakterkódolás: az Epson
 * TM-T20III hivatalos ESC/POS Command Reference-e (download4.epson.biz,
 * `ESC t` — Select character code table, és a modellenkénti Code Page
 * Support tábla) alapján a nyomtató a 0-5, 11-21, 26, 30-53 kódlap-
 * oldalakat támogatja — ebbe beleértve a Magyar ékezetes karakterekhez
 * (á é í ó ö ő ú ü ű + nagybetűs változatok) szükséges HÁROM jelölt
 * kódlapot is: 18 (PC852: Latin2), 39 (ISO8859-2: Latin2), 45 (WPC1250:
 * Latin2/Windows-1250) — mindhármat teljeskörűen lefedi. Valódi Epson
 * TM-T20III hardveren tesztelve (lásd Phase 6/7 checkpoint report),
 * NEM feltételezett kitalált kódlappal.
 *
 * KORÁBBI, HIBÁS viselkedés (javítva): a `toAscii()` minden ékezetes
 * karaktert sima ASCII-re transzliterált (iconv TRANSLIT), ami a
 * VALÓS nyomtatón olvashatatlan, aposztróf-szerű torzítást ("Term'ek"
 * "Termék" helyett) okozott — a nyomtató valójában TÁMOGATTA a
 * megfelelő kódlapot, csak sose lett kiválasztva/elküldve neki.
 */
class EscPosPrinter
{
    private const ESC = "\x1B";
    private const GS = "\x1D";

    /**
     * Kódlap-választék: `page` az ESC/POS `ESC t n` parancs bájtértéke
     * (lásd fenti docblock — mind a hárommal igazoltan lefedhető a teljes
     * magyar ékezetkészlet), `iconv` a PHP iconv() célkódolás-neve.
     */
    public const CODEPAGES = [
        'cp852'    => ['page' => 18, 'iconv' => 'CP852', 'label' => 'CP852 (DOS Latin 2)'],
        'cp1250'   => ['page' => 45, 'iconv' => 'CP1250', 'label' => 'CP1250 (Windows Latin 2)'],
        'iso88592' => ['page' => 39, 'iconv' => 'ISO-8859-2', 'label' => 'ISO-8859-2 (Latin 2)'],
    ];

    private const DEFAULT_ENCODING = 'cp852';

    /**
     * QR Code hibajavítási szintek (ESC/POS `GS ( k <Function 169>`
     * paramétere) — lásd buildQrCode() docblockja.
     */
    private const QR_ERROR_CORRECTION_LEVELS = ['L' => 48, 'M' => 49, 'Q' => 50, 'H' => 51];

    private string $ip;
    private int $port;
    private int $paperWidth;
    private string $encoding;

    public function __construct(string $ip, int $port, int $paperWidth = 42, string $encoding = self::DEFAULT_ENCODING)
    {
        $this->ip = $ip;
        $this->port = $port;
        $this->paperWidth = $paperWidth;
        $this->encoding = isset(self::CODEPAGES[$encoding]) ? $encoding : self::DEFAULT_ENCODING;
    }

    /**
     * @param string|null $qrPayload ha nem null ÉS a nyomtatón be van kapcsolva a QR-kód nyomtatás
     *   (lásd a hívó print-receipt.php-t), egy QR-kód kerül a nyugta aljára ezzel a tartalommal.
     */
    public function printReceipt(array $sale, array $headerLines, array $footerLines, ?string $logoPath = null, ?string $qrPayload = null): void
    {
        $out = self::ESC . '@' . $this->selectCodepage();

        if ($logoPath) {
            $out .= self::ESC . 'a' . "\x01";
            $out .= $this->buildLogoRaster($logoPath);
        }

        $out .= $this->buildReceiptBody($sale, $headerLines, $footerLines);

        if ($qrPayload !== null && $qrPayload !== '') {
            $out .= self::ESC . 'a' . "\x01";
            $out .= $this->buildQrCode($qrPayload);
            $out .= "\n";
        }

        $out .= "\n\n\n";
        $out .= self::GS . 'V' . "\x00";
        $this->send($out);
    }

    public function printTestPage(array $shop, ?string $logoPath = null, bool $includeQrSample = false): void
    {
        $out = self::ESC . '@' . $this->selectCodepage();
        $out .= self::ESC . 'a' . "\x01";
        if ($logoPath) {
            $out .= $this->buildLogoRaster($logoPath);
        }
        $out .= $this->bold(true) . $this->encodeText($shop['name'] ?: 'Stock Manager') . "\n" . $this->bold(false);
        $out .= "Teszt nyomtatas\n";
        $out .= str_repeat('-', $this->paperWidth) . "\n";
        $out .= date('Y-m-d H:i:s') . "\n";
        $out .= 'Kodlap: ' . self::CODEPAGES[$this->encoding]['label'] . "\n";
        $out .= str_repeat('-', $this->paperWidth) . "\n";
        // Karakterteszt blokk — a magyar ekezetes karakterkeszlet teljes
        // lefedese (kis- es nagybetus valtozatok is), lasd az osztaly
        // docblockjat es a Phase 6/7 checkpoint report hardware-teszt
        // szakaszat a valos nyomtatott eredmenyert.
        $out .= "Karakterteszt:\n";
        $out .= $this->encodeText('ÁÉÍÓÖŐÚÜŰ áéíóöőúüű') . "\n";
        $out .= $this->encodeText('Árvíztűrő tükörfúrógép') . "\n";
        $out .= str_repeat('-', $this->paperWidth) . "\n";
        $out .= "Ha ezt olvasod, a nyomtato\nkapcsolat rendben mukodik.\n";

        if ($includeQrSample) {
            $out .= "\n";
            $out .= self::ESC . 'a' . "\x01";
            $out .= $this->buildQrCode('Stock Manager - nyomtato teszt');
            $out .= "\n";
        }

        $out .= "\n\n\n";
        $out .= self::GS . 'V' . "\x00";
        $this->send($out);
    }

    /**
     * Standalone QR-kód nyomtatás (pl. külön "QR teszt" végpontból) — a
     * $payload TELJESEN a hívó felelőssége, ez az osztály NEM tételez fel
     * semmilyen konkrét üzleti tartalmat/formátumot (lásd Phase 6/7
     * checkpoint report "ne találj ki üzleti tartalmat" pontja).
     */
    public function printQrCode(string $payload): void
    {
        $out = self::ESC . '@' . $this->selectCodepage();
        $out .= self::ESC . 'a' . "\x01";
        $out .= $this->buildQrCode($payload);
        $out .= "\n\n\n";
        $out .= self::GS . 'V' . "\x00";
        $this->send($out);
    }

    private function selectCodepage(): string
    {
        return self::ESC . 't' . chr(self::CODEPAGES[$this->encoding]['page']);
    }

    private function buildReceiptBody(array $sale, array $headerLines, array $footerLines): string
    {
        $w = $this->paperWidth;
        $out = '';

        $out .= self::ESC . 'a' . "\x01";
        foreach ($headerLines as $i => $line) {
            $line = $this->encodeText($line);
            $out .= $i === 0 ? $this->bold(true) . $line . "\n" . $this->bold(false) : $line . "\n";
        }
        $out .= self::ESC . 'a' . "\x00";
        $out .= str_repeat('-', $w) . "\n";

        $out .= "Nyugta #" . $sale['id'] . "\n";
        $out .= ($sale['created_at'] ?? date('Y-m-d H:i:s')) . "\n";
        if (!empty($sale['szamlazz_invoice_number'])) {
            $out .= "Szamla: " . $this->encodeText($sale['szamlazz_invoice_number']) . "\n";
        }
        $out .= str_repeat('-', $w) . "\n";

        foreach ($sale['items'] as $item) {
            $out .= $this->wrapText($this->encodeText($item['name']), $w) . "\n";
            $qtyPrice = sprintf('%s x %s', $item['qty'], $this->money($item['unit_price']));
            $lineTotal = $this->money($item['unit_price'] * $item['qty']);
            $out .= $this->twoColumns($qtyPrice, $lineTotal, $w) . "\n";
        }

        $out .= str_repeat('-', $w) . "\n";
        $out .= $this->bold(true);
        $out .= $this->twoColumns('OSSZESEN', $this->money($sale['total']) . ' Ft', $w) . "\n";
        $out .= $this->bold(false);
        $out .= $this->twoColumns('Fizetes:', $this->encodeText($sale['payment_method'] ?? 'Keszpenz'), $w) . "\n";

        if ($footerLines) {
            $out .= "\n";
            $out .= self::ESC . 'a' . "\x01";
            foreach ($footerLines as $line) {
                $out .= $this->encodeText($line) . "\n";
            }
        }

        return $out;
    }

    private function buildLogoRaster(string $path): string
    {
        if (!extension_loaded('gd') || !is_file($path)) {
            return '';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $loaders = [
            'png'  => 'imagecreatefrompng',
            'jpg'  => 'imagecreatefromjpeg',
            'jpeg' => 'imagecreatefromjpeg',
            'webp' => 'imagecreatefromwebp',
        ];
        if (!isset($loaders[$ext]) || !function_exists($loaders[$ext])) {
            return '';
        }

        $src = @($loaders[$ext])($path);
        if (!$src) {
            return '';
        }

        $srcWidth = imagesx($src);
        $srcHeight = imagesy($src);
        if ($srcWidth < 1 || $srcHeight < 1) {
            imagedestroy($src);
            return '';
        }

        $targetWidth = min(300, $srcWidth);
        $targetWidth += (8 - $targetWidth % 8) % 8;
        $targetHeight = max(1, (int) round($srcHeight * ($targetWidth / $srcWidth)));
        $targetHeight = min($targetHeight, 250);

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        imagecopyresampled($resized, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcWidth, $srcHeight);
        imagedestroy($src);

        $bytesPerRow = (int) ceil($targetWidth / 8);
        $data = '';
        for ($y = 0; $y < $targetHeight; $y++) {
            $rowBits = '';
            for ($x = 0; $x < $targetWidth; $x++) {
                $rgb = imagecolorat($resized, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $luminance = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                $rowBits .= $luminance < 128 ? '1' : '0';
            }
            while (strlen($rowBits) % 8 !== 0) {
                $rowBits .= '0';
            }
            for ($i = 0; $i < strlen($rowBits); $i += 8) {
                $data .= chr(bindec(substr($rowBits, $i, 8)));
            }
        }
        imagedestroy($resized);

        $xL = $bytesPerRow & 0xFF;
        $xH = ($bytesPerRow >> 8) & 0xFF;
        $yL = $targetHeight & 0xFF;
        $yH = ($targetHeight >> 8) & 0xFF;

        return self::GS . 'v0' . chr(0) . chr($xL) . chr($xH) . chr($yL) . chr($yH) . $data;
    }

    /**
     * QR-kód ESC/POS parancssorozat — az Epson hivatalos "GS ( k" Two-
     * dimensional Code Commands referenciája alapján (download4.epson.biz/
     * sec_pubs/pos/reference_en/escpos/gs_lparen_lk_fn{165,167,169,180,181}.html),
     * a TM-T20III hivatalosan listázott, támogatott parancsai — SEMMI
     * nincs kitalálva:
     *   <Function 165> modell kiválasztás: 1D 28 6B 04 00 31 41 32 00
     *     (n1=50='2': modell 2, n2=0 — a specifikáció ajánlott alapértéke)
     *   <Function 167> modulméret: 1D 28 6B 03 00 31 43 <n> (n=dots, 1-16)
     *   <Function 169> hibajavítási szint: 1D 28 6B 03 00 31 45 <n>
     *     (n=48/L~7%, 49/M~15%, 50/Q~25%, 51/H~30%)
     *   <Function 180> adat tárolása: 1D 28 6B <pL> <pH> 31 50 30 <adat>
     *     (pL+pH×256 = az adat bájthossza + 3, "8-Bit Byte Mode" — bármely
     *     0-255 bájt engedett, a nyers UTF-8 payload-ot közvetlenül küldjük)
     *   <Function 181> nyomtatás: 1D 28 6B 03 00 31 51 30
     * A payload hosszkorlátja (pL+pH×256 max 7092, tehát az adat max 7089
     * bájt) a specifikáció szerinti — egy tetszőlegesen hosszú payload-ot
     * emiatt levágunk, nem küldünk érvénytelen hosszmezőt.
     */
    private function buildQrCode(string $payload, int $moduleSize = 5, string $errorCorrection = 'M'): string
    {
        $moduleSize = max(1, min(16, $moduleSize));
        $ecLevel = self::QR_ERROR_CORRECTION_LEVELS[$errorCorrection] ?? self::QR_ERROR_CORRECTION_LEVELS['M'];

        $data = substr($payload, 0, 7089);
        $k = strlen($data);
        $storeLen = $k + 3;
        $pL = $storeLen & 0xFF;
        $pH = ($storeLen >> 8) & 0xFF;

        $out = '';
        $out .= self::GS . '(k' . chr(4) . chr(0) . chr(49) . chr(65) . chr(50) . chr(0); // <Function 165> select model 2
        $out .= self::GS . '(k' . chr(3) . chr(0) . chr(49) . chr(67) . chr($moduleSize); // <Function 167> module size
        $out .= self::GS . '(k' . chr(3) . chr(0) . chr(49) . chr(69) . chr($ecLevel); // <Function 169> error correction
        $out .= self::GS . '(k' . chr($pL) . chr($pH) . chr(49) . chr(80) . chr(48) . $data; // <Function 180> store data
        $out .= self::GS . '(k' . chr(3) . chr(0) . chr(49) . chr(81) . chr(48); // <Function 181> print

        return $out;
    }

    private function send(string $data): void
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($this->ip, $this->port, $errno, $errstr, 5);
        if (!$socket) {
            throw new RuntimeException("Nem sikerült csatlakozni a nyomtatóhoz ({$this->ip}:{$this->port}): $errstr");
        }
        // A fsockopen() 5 mp-es timeoutja csak a kapcsolódásra vonatkozik —
        // enélkül egy olyan cél, ami elfogadja a TCP-kapcsolatot, de sosem
        // olvassa ki a socketet, a fwrite()-ot akár a PHP script-időkorlátig
        // (max_execution_time) is blokkolhatná, egy PHP worker-t lefoglalva.
        stream_set_timeout($socket, 5);
        fwrite($socket, $data);
        fclose($socket);
    }

    /**
     * Az ESC/POS nyomtatónak MEG KELL mondani, melyik kódlapot használja
     * (lásd selectCodepage() / ESC t, az osztály docblockja) — ez a
     * metódus a kiválasztott kódlapra konvertálja a szöveget (NEM sima
     * ASCII-re, lásd a korábbi, hibás toAscii() docblockja a git
     * történetben). A TRANSLIT//IGNORE modifier BIZTONSÁGI HÁLÓ marad
     * azokra a (ritka) Unicode karakterekre, amik SEM ASCII-ban, SEM a
     * kiválasztott kódlapban nincsenek (pl. egy speciális szimbólum egy
     * termék nevében) — ilyenkor sima ASCII-re esik vissza a mojibake
     * elkerülésére, ékezet nélkül, ahelyett hogy hibás bájtokat küldene.
     *
     * Emellett ez az EGYETLEN hely, ahol a nyers ESC/POS bájtfolyamba
     * kerülő, kívülről befolyásolható szöveg (termék-/vevőnév, fizetési
     * mód, kézi tétel neve stb.) átmegy — ezért itt szűrjük ki a
     * vezérlőbájtokat (0x00-0x1F) is, MÉG a kódlap-konverzió UTÁN (egyik
     * támogatott kódlap sem térképez semmit a 0x00-0x1F tartományba, ez a
     * szűrés így minden kódlapra helyesen működik). Az iconv TRANSLIT/
     * IGNORE ugyanis csak a nem-célkódlapos karaktereket alakítja/dobja
     * el, egy már ASCII-tartományba eső vezérlőbájtot (pl. 0x1B = ESC,
     * 0x1D = GS) változatlanul hagyna — enélkül egy erre felkészített
     * termék- vagy vevőnév (pl. egy kézi kosártétel neve) tetszőleges
     * nyomtató-parancsot csempészhetne be (pénztárfiók nyitása, papírvágás
     * stb.), a nyugtán legfeljebb egy hiányzó karakterként észrevehetően.
     * A saját magunk beszúrt sortöréseit ("\n") ez nem érinti, mert
     * azokat mindig az encodeText() hívása UTÁN fűzzük hozzá, sose ide
     * adjuk be.
     */
    private function encodeText(string $s): string
    {
        $target = self::CODEPAGES[$this->encoding]['iconv'];
        $converted = @iconv('UTF-8', $target . '//TRANSLIT//IGNORE', $s);
        if ($converted === false) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        }
        $out = $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '', $s);
        return preg_replace('/[\x00-\x1F]/', '', $out);
    }

    private function bold(bool $on): string
    {
        return self::ESC . 'E' . ($on ? "\x01" : "\x00");
    }

    private function money(float $n): string
    {
        return number_format($n, 0, ',', ' ');
    }

    private function wrapText(string $text, int $width): string
    {
        return wordwrap($text, $width, "\n", true);
    }

    private function twoColumns(string $left, string $right, int $width): string
    {
        $padding = max(1, $width - strlen($left) - strlen($right));
        return $left . str_repeat(' ', $padding) . $right;
    }
}
