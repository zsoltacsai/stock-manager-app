<?php

class EscPosPrinter
{
    private const ESC = "\x1B";
    private const GS = "\x1D";

    private string $ip;
    private int $port;
    private int $paperWidth;

    public function __construct(string $ip, int $port, int $paperWidth = 42)
    {
        $this->ip = $ip;
        $this->port = $port;
        $this->paperWidth = $paperWidth;
    }

    public function printReceipt(array $sale, array $headerLines, array $footerLines, ?string $logoPath = null): void
    {
        $out = self::ESC . '@';

        if ($logoPath) {
            $out .= self::ESC . 'a' . "\x01";
            $out .= $this->buildLogoRaster($logoPath);
        }

        $out .= $this->buildReceiptBody($sale, $headerLines, $footerLines);
        $this->send($out);
    }

    public function printTestPage(array $shop, ?string $logoPath = null): void
    {
        $out = self::ESC . '@';
        $out .= self::ESC . 'a' . "\x01";
        if ($logoPath) {
            $out .= $this->buildLogoRaster($logoPath);
        }
        $out .= $this->bold(true) . $this->toAscii($shop['name'] ?: 'Stock Manager') . "\n" . $this->bold(false);
        $out .= "Teszt nyomtatas\n";
        $out .= str_repeat('-', $this->paperWidth) . "\n";
        $out .= date('Y-m-d H:i:s') . "\n";
        $out .= "Ha ezt olvasod, a nyomtato\nkapcsolat rendben mukodik.\n";
        $out .= "\n\n\n";
        $out .= self::GS . 'V' . "\x00";
        $this->send($out);
    }

    private function buildReceiptBody(array $sale, array $headerLines, array $footerLines): string
    {
        $w = $this->paperWidth;
        $out = '';

        $out .= self::ESC . 'a' . "\x01";
        foreach ($headerLines as $i => $line) {
            $line = $this->toAscii($line);
            $out .= $i === 0 ? $this->bold(true) . $line . "\n" . $this->bold(false) : $line . "\n";
        }
        $out .= self::ESC . 'a' . "\x00";
        $out .= str_repeat('-', $w) . "\n";

        $out .= "Nyugta #" . $sale['id'] . "\n";
        $out .= ($sale['created_at'] ?? date('Y-m-d H:i:s')) . "\n";
        if (!empty($sale['szamlazz_invoice_number'])) {
            $out .= "Szamla: " . $this->toAscii($sale['szamlazz_invoice_number']) . "\n";
        }
        $out .= str_repeat('-', $w) . "\n";

        foreach ($sale['items'] as $item) {
            $out .= $this->wrapText($this->toAscii($item['name']), $w) . "\n";
            $qtyPrice = sprintf('%s x %s', $item['qty'], $this->money($item['unit_price']));
            $lineTotal = $this->money($item['unit_price'] * $item['qty']);
            $out .= $this->twoColumns($qtyPrice, $lineTotal, $w) . "\n";
        }

        $out .= str_repeat('-', $w) . "\n";
        $out .= $this->bold(true);
        $out .= $this->twoColumns('OSSZESEN', $this->money($sale['total']) . ' Ft', $w) . "\n";
        $out .= $this->bold(false);
        $out .= $this->twoColumns('Fizetes:', $this->toAscii($sale['payment_method'] ?? 'Keszpenz'), $w) . "\n";

        if ($footerLines) {
            $out .= "\n";
            $out .= self::ESC . 'a' . "\x01";
            foreach ($footerLines as $line) {
                $out .= $this->toAscii($line) . "\n";
            }
        }
        $out .= "\n\n\n";
        $out .= self::GS . 'V' . "\x00";

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

    private function send(string $data): void
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($this->ip, $this->port, $errno, $errstr, 5);
        if (!$socket) {
            throw new RuntimeException("Nem sikerült csatlakozni a nyomtatóhoz ({$this->ip}:{$this->port}): $errstr");
        }
        fwrite($socket, $data);
        fclose($socket);
    }

    /**
     * Az ESC/POS nyomtatóknak nyomtató-specifikus kódlap-parancs kell az
     * ékezetes karakterek helyes megjelenítéséhez, ez modellenként/
     * firmware-enként eltér. Ahelyett hogy rosszul találnánk ki és
     * olvashatatlan szöveget nyomtatnánk, ez sima ASCII-re alakít
     * (á→a, ő→o, stb.), így a kimenet mindig olvasható marad —
     * az ékezetek elvesznek, de sosem lesz belőle "mojibake".
     */
    private function toAscii(string $s): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $transliterated !== false ? $transliterated : preg_replace('/[^\x20-\x7E]/', '', $s);
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
