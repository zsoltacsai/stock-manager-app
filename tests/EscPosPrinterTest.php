<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * EscPosPrinter tesztek — a hálózati réteget egy VALÓDI, helyi TCP-
 * listenerrel helyettesítjük (nem mock — a tényleges fsockopen()/fwrite()
 * útvonalat gyakorolja be, csak a MÁSIK végén egy teszt-"nyomtató" ül,
 * ami rögzíti a pontosan kapott bájtokat) — így a küldött ESC/POS
 * bájtsorozat byte-pontosan ellenőrizhető, valódi hardver nélkül. A
 * VALÓDI Epson TM-T20III hardveren végzett élő ellenőrzést lásd a
 * Phase 6/7 checkpoint report "PRINTER" szakaszát.
 */
final class EscPosPrinterTest extends TestCase
{
    /**
     * Elindít egy helyi TCP "fake printer"-t, lefuttatja $sendAction-t
     * (ami a valódi EscPosPrinter-en keresztül csatlakozik ehhez a
     * címhez és ír), majd visszaadja a pontosan kapott nyers bájtokat.
     */
    private function captureBytes(callable $sendAction): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server, 'Nem sikerült helyi teszt-listenert indítani.');
        $addr = stream_socket_get_name($server, false);
        $port = (int) substr($addr, strrpos($addr, ':') + 1);

        $sendAction('127.0.0.1', $port);

        // A kliens (EscPosPrinter::send()) MÁR írt és lezárta a saját
        // oldalát, mire idáig érünk — a TCP backlog ezt megtartja, az
        // accept() itt biztonságosan megkapja.
        $conn = @stream_socket_accept($server, 2);
        $this->assertNotFalse($conn, 'A teszt-listener nem kapott kapcsolatot.');
        stream_set_timeout($conn, 2);
        $data = stream_get_contents($conn);
        fclose($conn);
        fclose($server);

        return (string) $data;
    }

    private function sampleSale(): array
    {
        return [
            'id' => 42,
            'created_at' => '2026-09-09 12:00:00',
            'total' => 1270.0,
            'payment_method' => 'Készpénz',
            'items' => [
                ['name' => 'Termék A', 'qty' => 1, 'unit_price' => 1000.0],
            ],
        ];
    }

    // ---- Kódlap-választás ----

    public function testDefaultEncodingIsCp852AndSelectsCorrectPage(): void
    {
        $bytes = $this->captureBytes(function (string $ip, int $port) {
            $printer = new EscPosPrinter($ip, $port, 42);
            $printer->printTestPage(['name' => 'Teszt Bolt']);
        });
        // ESC t <18> = 0x1B 0x74 0x12 — PC852, közvetlenül az ESC @ init után.
        $this->assertStringContainsString("\x1B\x74\x12", $bytes);
    }

    public function testCp1250SelectsPage45(): void
    {
        $bytes = $this->captureBytes(function (string $ip, int $port) {
            $printer = new EscPosPrinter($ip, $port, 42, 'cp1250');
            $printer->printTestPage(['name' => 'Teszt Bolt']);
        });
        $this->assertStringContainsString("\x1B\x74\x2D", $bytes); // 0x2D = 45
    }

    public function testIso88592SelectsPage39(): void
    {
        $bytes = $this->captureBytes(function (string $ip, int $port) {
            $printer = new EscPosPrinter($ip, $port, 42, 'iso88592');
            $printer->printTestPage(['name' => 'Teszt Bolt']);
        });
        $this->assertStringContainsString("\x1B\x74\x27", $bytes); // 0x27 = 39
    }

    public function testUnknownEncodingFallsBackToDefaultWithoutError(): void
    {
        $bytes = $this->captureBytes(function (string $ip, int $port) {
            $printer = new EscPosPrinter($ip, $port, 42, 'nonexistent-encoding');
            $printer->printTestPage(['name' => 'Teszt Bolt']);
        });
        $this->assertStringContainsString("\x1B\x74\x12", $bytes); // cp852 default
    }

    // ---- Magyar ékezetes karakterek ----

    public function testHungarianCharactersEncodeToCp852(): void
    {
        $bytes = $this->captureBytes(function (string $ip, int $port) {
            $printer = new EscPosPrinter($ip, $port, 42, 'cp852');
            $printer->printReceipt($this->sampleSale(), ['Árvíztűrő tükörfúrógép'], []);
        });
        $expected = iconv('UTF-8', 'CP852', 'Árvíztűrő tükörfúrógép');
        $this->assertStringContainsString($expected, $bytes);
        // A korábbi, hibás viselkedés (ASCII-transliterálás aposztróf-
        // torzítással) NEM térhet vissza — "T¸r" vagy "T'r" formájú
        // torzítás sose jelenhet meg "Tűrő" helyett.
        $this->assertStringNotContainsString("T'r", $bytes);
    }

    public function testHungarianCharactersEncodeToCp1250(): void
    {
        $bytes = $this->captureBytes(function (string $ip, int $port) {
            $printer = new EscPosPrinter($ip, $port, 42, 'cp1250');
            $printer->printReceipt($this->sampleSale(), ['Árvíztűrő tükörfúrógép'], []);
        });
        $expected = iconv('UTF-8', 'CP1250', 'Árvíztűrő tükörfúrógép');
        $this->assertStringContainsString($expected, $bytes);
    }

    public function testFullHungarianCharacterSetRoundTripsForAllCodepages(): void
    {
        $sample = 'ÁÉÍÓÖŐÚÜŰ áéíóöőúüű';
        foreach (array_keys(EscPosPrinter::CODEPAGES) as $encoding) {
            $bytes = $this->captureBytes(function (string $ip, int $port) use ($encoding, $sample) {
                $printer = new EscPosPrinter($ip, $port, 42, $encoding);
                $printer->printReceipt($this->sampleSale(), [$sample], []);
            });
            $iconvName = EscPosPrinter::CODEPAGES[$encoding]['iconv'];
            $expected = iconv('UTF-8', $iconvName, $sample);
            $this->assertNotFalse($expected, "$encoding: az iconv magának a várt értéknek az előállítása sikertelen volt.");
            $this->assertStringContainsString($expected, $bytes, "$encoding kódlapra a teljes magyar ékezetkészletnek torzítás nélkül kell megjelennie.");
        }
    }

    // ---- ASCII regresszió ----

    public function testPlainAsciiTextIsUnaffected(): void
    {
        $sale = $this->sampleSale();
        $sale['items'][0]['name'] = 'Ascii Termek A'; // szandekosan ekezet nelkuli, hogy a "nincs ekezet -> nincs valtozas" allitas valoban tesztelve legyen
        $bytes = $this->captureBytes(function (string $ip, int $port) use ($sale) {
            $printer = new EscPosPrinter($ip, $port, 42, 'cp852');
            $printer->printReceipt($sale, ['Test Shop ASCII'], []);
        });
        $this->assertStringContainsString('Test Shop ASCII', $bytes);
        $this->assertStringContainsString('Ascii Termek A', $bytes);
        $this->assertStringContainsString('OSSZESEN', $bytes);
    }

    public function testAmountsAndLineBreaksArePreserved(): void
    {
        $bytes = $this->captureBytes(function (string $ip, int $port) {
            $printer = new EscPosPrinter($ip, $port, 42, 'cp852');
            $printer->printReceipt($this->sampleSale(), [], []);
        });
        $this->assertStringContainsString("Nyugta #42\n", $bytes);
        $this->assertStringContainsString('1 270', $bytes); // money() ezres-tagolással
        $this->assertMatchesRegularExpression('/\n\n\n/', $bytes, 'A záró sortöréseknek meg kell maradniuk.');
    }

    // ---- Vezérlőbájt-szűrés (biztonság) ----

    public function testControlBytesAreStrippedFromUntrustedText(): void
    {
        $sale = $this->sampleSale();
        $sale['items'][0]['name'] = "Rossz\x1B@termek"; // ESC @ (init) becsempészési kísérlet
        $bytes = $this->captureBytes(function (string $ip, int $port) use ($sale) {
            $printer = new EscPosPrinter($ip, $port, 42, 'cp852');
            $printer->printReceipt($sale, [], []);
        });
        // A becsempészett ESC bájt NEM maradhat a tétel-név után, csak a
        // szándékos, a metódusok által beszúrt vezérlőkódokban (pl. a
        // valódi ESC @ init a bájtfolyam ELEJÉN) szabad előfordulnia.
        $this->assertStringNotContainsString("Rossz\x1B@termek", $bytes);
        // Az ESC (0x1B) vezérlőbájt eltűnik, DE a "@" ÖNMAGÁBAN sima
        // nyomtatható karakter (0x40, nem esik a 0x00-0x1F tartományba)
        // — a becsempészett "ESC @" (init parancs) ÍGY ártalmatlan,
        // szövegként megjelenő "@" jellé esik szét, nem hajtódik végre.
        $this->assertStringContainsString('Rossz@termek', $bytes);
    }

    // ---- QR kód ----

    public function testQrCodeCommandSequenceMatchesEpsonSpec(): void
    {
        $bytes = $this->captureBytes(function (string $ip, int $port) {
            $printer = new EscPosPrinter($ip, $port, 42, 'cp852');
            $printer->printQrCode('HELLO');
        });
        // Az Epson hivatalos "GS ( k" Two-dimensional Code Commands
        // referenciája szerinti pontos bájtsorrend (lásd
        // EscPosPrinter::buildQrCode() docblockja — TM-T20III-on
        // hivatalosan igazolt fn=165/167/169/180/181):
        $expected = "\x1D\x28\x6B\x04\x00\x31\x41\x32\x00" // fn165 select model 2
            . "\x1D\x28\x6B\x03\x00\x31\x43\x05" // fn167 module size = 5
            . "\x1D\x28\x6B\x03\x00\x31\x45\x31" // fn169 error correction M
            . "\x1D\x28\x6B\x08\x00\x31\x50\x30HELLO" // fn180 store (pL=8=5+3)
            . "\x1D\x28\x6B\x03\x00\x31\x51\x30"; // fn181 print
        $this->assertStringContainsString($expected, $bytes);
    }

    public function testQrCodeOmittedWhenPayloadIsNull(): void
    {
        $bytes = $this->captureBytes(function (string $ip, int $port) {
            $printer = new EscPosPrinter($ip, $port, 42, 'cp852');
            $printer->printReceipt($this->sampleSale(), [], [], null, null);
        });
        // Semmilyen "GS ( k" (1D 28 6B) szekvencia nem szerepelhet, ha
        // nincs QR-payload megadva.
        $this->assertStringNotContainsString("\x1D\x28\x6B", $bytes);
    }

    public function testQrCodeIncludedWhenPayloadProvided(): void
    {
        $bytes = $this->captureBytes(function (string $ip, int $port) {
            $printer = new EscPosPrinter($ip, $port, 42, 'cp852');
            $printer->printReceipt($this->sampleSale(), [], [], null, 'https://example.com/r/1');
        });
        $this->assertStringContainsString("\x1D\x28\x6B", $bytes);
        $this->assertStringContainsString('https://example.com/r/1', $bytes);
    }

    public function testQrPayloadIsTruncatedAtSpecMaximum(): void
    {
        $longPayload = str_repeat('A', 8000); // meghaladja a 7089 bájtos specifikációs korlátot
        $ref = new ReflectionMethod(EscPosPrinter::class, 'buildQrCode');
        $ref->setAccessible(true);
        $printer = new EscPosPrinter('127.0.0.1', 1, 42, 'cp852');
        $built = $ref->invoke($printer, $longPayload);
        // 5 parancs fejléc-overhead-je (fn165=9 + fn167=8 + fn169=8 +
        // fn180 fejléc=8 + fn181=8 = 41 bájt) + a levágott adat (max 7089
        // bájt) = legfeljebb 7130 bájt — SOSE több.
        $this->assertLessThanOrEqual(41 + 7089, strlen($built), 'A payload-nak a specifikációs korlátnál (7089 bájt) le kell vágódnia.');
        $this->assertStringNotContainsString(str_repeat('A', 7090), $built, 'A ténylegesen elküldött adatrésznek legfeljebb 7089 bájtosnak szabad lennie.');
    }

    // ---- Érvénytelen nyomtató-konfiguráció ----

    public function testConnectionFailureThrowsRuntimeExceptionWithoutCrashingPhp(): void
    {
        $this->expectException(RuntimeException::class);
        // 127.0.0.1:1 -- gyakorlatilag garantáltan nincs semmi ezen a porton.
        $printer = new EscPosPrinter('127.0.0.1', 1, 42, 'cp852');
        $printer->printTestPage(['name' => 'Teszt']);
    }
}
