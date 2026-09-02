<?php

class XlsReader
{
    public static function readRows(string $path): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('A fájl nem olvasható.');
        }

        $stream = OleCompoundFile::readStream($bytes, ['Workbook', 'Book']);
        if ($stream === null) {
            throw new RuntimeException('Ez nem egy érvényes .xls fájl (nem található "Workbook" adatfolyam benne).');
        }

        return Biff8Parser::parseFirstSheet($stream);
    }
}

class OleCompoundFile
{
    private const FREESECT = 0xFFFFFFFF;
    private const ENDOFCHAIN = 0xFFFFFFFE;
    private const FATSECT = 0xFFFFFFFD;
    private const DIFSECT = 0xFFFFFFFC;

    public static function readStream(string $bytes, array $candidateNames): ?string
    {
        if (strlen($bytes) < 512 || substr($bytes, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('Ez nem egy érvényes OLE2 (.xls) fájl.');
        }

        $sectorShift = self::u16($bytes, 30);
        $miniSectorShift = self::u16($bytes, 32);
        $sectorSize = 1 << $sectorShift;
        $miniSectorSize = 1 << $miniSectorShift;
        $numFatSectors = self::u32($bytes, 44);
        $firstDirSector = self::u32($bytes, 48);
        $miniStreamCutoff = self::u32($bytes, 56);
        $firstMiniFatSector = self::u32($bytes, 60);
        $numMiniFatSectors = self::u32($bytes, 64);
        $firstDifatSector = self::u32($bytes, 68);
        $numDifatSectors = self::u32($bytes, 72);

        $fatSectorIds = [];
        for ($i = 0; $i < 109; $i++) {
            $id = self::u32($bytes, 76 + $i * 4);
            if ($id === self::FREESECT || $id === self::FATSECT) {
                continue;
            }
            $fatSectorIds[] = $id;
        }
        $difatSector = $firstDifatSector;
        $seen = 0;
        while ($difatSector !== self::ENDOFCHAIN && $difatSector !== self::FREESECT && $seen < $numDifatSectors + 1) {
            $data = self::readSector($bytes, $difatSector, $sectorSize);
            $entriesPerSector = intdiv($sectorSize, 4) - 1;
            for ($i = 0; $i < $entriesPerSector; $i++) {
                $id = self::u32($data, $i * 4);
                if ($id !== self::FREESECT && $id !== self::FATSECT) {
                    $fatSectorIds[] = $id;
                }
            }
            $difatSector = self::u32($data, $entriesPerSector * 4);
            $seen++;
        }

        $fat = [];
        $entriesPerFatSector = intdiv($sectorSize, 4);
        foreach ($fatSectorIds as $fatSecId) {
            $data = self::readSector($bytes, $fatSecId, $sectorSize);
            for ($i = 0; $i < $entriesPerFatSector; $i++) {
                $fat[] = self::u32($data, $i * 4);
            }
        }

        $dirBytes = self::readChain($bytes, $firstDirSector, $sectorSize, $fat);
        $entrySize = 128;
        $count = intdiv(strlen($dirBytes), $entrySize);

        $target = null;
        $rootStart = null;
        $rootSize = 0;
        for ($i = 0; $i < $count; $i++) {
            $off = $i * $entrySize;
            $nameLen = self::u16($dirBytes, $off + 64);
            if ($nameLen < 2) {
                continue;
            }
            $name = self::utf16le(substr($dirBytes, $off, $nameLen - 2));
            $objType = ord($dirBytes[$off + 66]);
            $startSector = self::u32($dirBytes, $off + 116);
            $size = self::u32($dirBytes, $off + 120);

            if ($objType === 5) {
                $rootStart = $startSector;
                $rootSize = $size;
            }
            if ($objType === 2 && in_array($name, $candidateNames, true)) {
                $target = ['start' => $startSector, 'size' => $size];
            }
        }

        if ($target === null) {
            return null;
        }

        if ($target['size'] < $miniStreamCutoff) {
            if ($rootStart === null) {
                throw new RuntimeException('Az .xls fájl szerkezete sérült (nincs gyökér bejegyzés).');
            }
            $miniFat = [];
            $entriesPerMiniFatSector = intdiv($sectorSize, 4);
            $chain = self::sectorChain($firstMiniFatSector, $sectorSize, $fat, $numFatSectors * $entriesPerFatSector + 4);
            foreach ($chain as $miniFatSecId) {
                $data = self::readSector($bytes, $miniFatSecId, $sectorSize);
                for ($i = 0; $i < $entriesPerMiniFatSector; $i++) {
                    $miniFat[] = self::u32($data, $i * 4);
                }
            }
            $rootStream = self::readChain($bytes, $rootStart, $sectorSize, $fat);
            return self::readMiniChain($rootStream, $target['start'], $miniSectorSize, $miniFat, $target['size']);
        }

        return substr(self::readChain($bytes, $target['start'], $sectorSize, $fat), 0, $target['size']);
    }

    private static function sectorChain(int $start, int $sectorSize, array $fat, int $maxSteps): array
    {
        $chain = [];
        $sec = $start;
        $steps = 0;
        while ($sec !== self::ENDOFCHAIN && $sec !== self::FREESECT && $sec >= 0 && $steps < $maxSteps) {
            $chain[] = $sec;
            $sec = $fat[$sec] ?? self::ENDOFCHAIN;
            $steps++;
        }
        return $chain;
    }

    private static function readChain(string $bytes, int $start, int $sectorSize, array $fat): string
    {
        $out = '';
        foreach (self::sectorChain($start, $sectorSize, $fat, count($fat) + 4) as $sec) {
            $out .= self::readSector($bytes, $sec, $sectorSize);
        }
        return $out;
    }

    private static function readMiniChain(string $rootStream, int $start, int $miniSectorSize, array $miniFat, int $size): string
    {
        $out = '';
        $sec = $start;
        $steps = 0;
        while ($sec !== self::ENDOFCHAIN && $sec !== self::FREESECT && $steps < count($miniFat) + 4) {
            $offset = $sec * $miniSectorSize;
            $out .= substr($rootStream, $offset, $miniSectorSize);
            $sec = $miniFat[$sec] ?? self::ENDOFCHAIN;
            $steps++;
        }
        return substr($out, 0, $size);
    }

    private static function readSector(string $bytes, int $sectorId, int $sectorSize): string
    {
        $offset = 512 + $sectorId * $sectorSize;
        return substr($bytes, $offset, $sectorSize);
    }

    private static function u16(string $s, int $off): int
    {
        return unpack('v', substr($s, $off, 2))[1];
    }

    private static function u32(string $s, int $off): int
    {
        return unpack('V', substr($s, $off, 4))[1];
    }

    private static function utf16le(string $s): string
    {
        $out = @iconv('UTF-16LE', 'UTF-8//IGNORE', $s);
        return $out !== false ? $out : $s;
    }
}

class Biff8Parser
{
    private const REC_BOF = 0x0809;
    private const REC_EOF = 0x000A;
    private const REC_BOUNDSHEET = 0x0085;
    private const REC_SST = 0x00FC;
    private const REC_CONTINUE = 0x003C;
    private const REC_LABELSST = 0x00FD;
    private const REC_LABEL = 0x0204;
    private const REC_RSTRING = 0x00D6;
    private const REC_NUMBER = 0x0203;
    private const REC_RK = 0x027E;
    private const REC_MULRK = 0x00BD;
    private const REC_FORMULA = 0x0006;
    private const REC_STRING = 0x0207;

    public static function parseFirstSheet(string $stream): array
    {
        $len = strlen($stream);
        $pos = 0;
        $sst = [];
        $firstSheetOffset = null;

        while ($pos + 4 <= $len) {
            $type = unpack('v', substr($stream, $pos, 2))[1];
            $recLen = unpack('v', substr($stream, $pos + 2, 2))[1];
            $dataStart = $pos + 4;

            if ($type === self::REC_SST) {
                [$sst, $consumedTo] = self::readSst($stream, $dataStart, $recLen);
                $pos = $consumedTo;
                continue;
            }
            if ($type === self::REC_BOUNDSHEET && $firstSheetOffset === null) {
                $firstSheetOffset = unpack('V', substr($stream, $dataStart, 4))[1];
            }
            if ($type === self::REC_EOF) {
                $pos = $dataStart + $recLen;
                break;
            }
            $pos = $dataStart + $recLen;
        }

        if ($firstSheetOffset === null || $firstSheetOffset >= $len) {
            throw new RuntimeException('Az .xls fájlban nem található munkalap.');
        }

        $rows = [];
        $maxColByRow = [];
        $pos = $firstSheetOffset;
        $pendingFormulaCell = null;

        while ($pos + 4 <= $len) {
            $type = unpack('v', substr($stream, $pos, 2))[1];
            $recLen = unpack('v', substr($stream, $pos + 2, 2))[1];
            $dataStart = $pos + 4;
            if ($dataStart + $recLen > $len) {
                break;
            }
            $data = substr($stream, $dataStart, $recLen);

            switch ($type) {
                case self::REC_EOF:
                    break 2;

                case self::REC_LABELSST:
                    $row = unpack('v', substr($data, 0, 2))[1];
                    $col = unpack('v', substr($data, 2, 2))[1];
                    $idx = unpack('V', substr($data, 6, 4))[1];
                    $rows[$row][$col] = $sst[$idx] ?? '';
                    $maxColByRow[$row] = max($maxColByRow[$row] ?? -1, $col);
                    break;

                case self::REC_LABEL:
                case self::REC_RSTRING:
                    $row = unpack('v', substr($data, 0, 2))[1];
                    $col = unpack('v', substr($data, 2, 2))[1];
                    $reader = new Biff8StringSplicer($stream, $dataStart + 6, $dataStart + $recLen, $len);
                    $rows[$row][$col] = $reader->readXlUnicodeString();
                    $maxColByRow[$row] = max($maxColByRow[$row] ?? -1, $col);
                    break;

                case self::REC_NUMBER:
                    $row = unpack('v', substr($data, 0, 2))[1];
                    $col = unpack('v', substr($data, 2, 2))[1];
                    $val = unpack('d', substr($data, 6, 8))[1];
                    $rows[$row][$col] = self::formatNumber($val);
                    $maxColByRow[$row] = max($maxColByRow[$row] ?? -1, $col);
                    break;

                case self::REC_RK:
                    $row = unpack('v', substr($data, 0, 2))[1];
                    $col = unpack('v', substr($data, 2, 2))[1];
                    $rkNum = unpack('V', substr($data, 6, 4))[1];
                    $rows[$row][$col] = self::formatNumber(self::decodeRk($rkNum));
                    $maxColByRow[$row] = max($maxColByRow[$row] ?? -1, $col);
                    break;

                case self::REC_MULRK:
                    $row = unpack('v', substr($data, 0, 2))[1];
                    $firstCol = unpack('v', substr($data, 2, 2))[1];
                    $lastCol = unpack('v', substr($data, $recLen - 2, 2))[1];
                    $n = $lastCol - $firstCol + 1;
                    for ($i = 0; $i < $n; $i++) {
                        $entryOff = 4 + $i * 6;
                        if ($entryOff + 6 > $recLen) {
                            break;
                        }
                        $rkNum = unpack('V', substr($data, $entryOff + 2, 4))[1];
                        $rows[$row][$firstCol + $i] = self::formatNumber(self::decodeRk($rkNum));
                    }
                    $maxColByRow[$row] = max($maxColByRow[$row] ?? -1, $lastCol);
                    break;

                case self::REC_FORMULA:
                    $row = unpack('v', substr($data, 0, 2))[1];
                    $col = unpack('v', substr($data, 2, 2))[1];
                    $resultBytes = substr($data, 6, 8);
                    if (substr($resultBytes, 6, 2) === "\xFF\xFF") {
                        $specialType = ord($resultBytes[0]);
                        if ($specialType === 0) {
                            $pendingFormulaCell = [$row, $col];
                        } else {
                            $rows[$row][$col] = '';
                        }
                    } else {
                        $val = unpack('d', $resultBytes)[1];
                        $rows[$row][$col] = self::formatNumber($val);
                    }
                    $maxColByRow[$row] = max($maxColByRow[$row] ?? -1, $col);
                    break;

                case self::REC_STRING:
                    if ($pendingFormulaCell !== null) {
                        $reader = new Biff8StringSplicer($stream, $dataStart, $dataStart + $recLen, $len);
                        [$r, $c] = $pendingFormulaCell;
                        $rows[$r][$c] = $reader->readXlUnicodeStringNoCch();
                        $pendingFormulaCell = null;
                    }
                    break;

                default:
                    break;
            }

            $pos = $dataStart + $recLen;
        }

        if (empty($rows)) {
            return [];
        }
        $result = [];
        $rowIndices = array_keys($rows);
        sort($rowIndices);
        foreach ($rowIndices as $r) {
            $maxCol = $maxColByRow[$r];
            $dense = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $dense[] = $rows[$r][$c] ?? '';
            }
            if (implode('', $dense) === '') {
                continue;
            }
            $result[] = $dense;
        }
        return $result;
    }

    private static function readSst(string $stream, int $dataStart, int $recLen): array
    {
        $splicer = new Biff8StringSplicer($stream, $dataStart, $dataStart + $recLen, strlen($stream));
        $splicer->skip(4);
        $unique = $splicer->readU32();
        $strings = [];
        for ($i = 0; $i < $unique; $i++) {
            $strings[] = $splicer->readXlUnicodeString();
        }
        return [$strings, $splicer->position()];
    }

    private static function decodeRk(int $rkNum): float
    {
        $fX100 = $rkNum & 0x01;
        $fInt = ($rkNum >> 1) & 0x01;
        if ($fInt) {
            $signed32 = $rkNum > 0x7FFFFFFF ? $rkNum - 0x100000000 : $rkNum;
            $value = $signed32 >> 2;
        } else {
            $high = $rkNum & 0xFFFFFFFC;
            $bytes = pack('V', 0) . pack('V', $high);
            $value = unpack('d', $bytes)[1];
        }
        return $fX100 ? $value / 100 : $value;
    }

    private static function formatNumber(float $n): string
    {
        if (is_nan($n) || is_infinite($n)) {
            return '';
        }
        if ($n === floor($n) && abs($n) < 1e15) {
            return (string) (int) $n;
        }
        return rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');
    }
}

class Biff8StringSplicer
{
    private string $stream;
    private int $pos;
    private int $recordEnd;
    private int $streamLen;

    public function __construct(string $stream, int $pos, int $recordEnd, int $streamLen)
    {
        $this->stream = $stream;
        $this->pos = $pos;
        $this->recordEnd = $recordEnd;
        $this->streamLen = $streamLen;
    }

    public function position(): int
    {
        return $this->pos;
    }

    public function skip(int $n): void
    {
        $this->readBytes($n);
    }

    public function readU16(): int
    {
        return unpack('v', $this->readBytes(2))[1];
    }

    public function readU32(): int
    {
        return unpack('V', $this->readBytes(4))[1];
    }

    public function readXlUnicodeString(): string
    {
        $cch = $this->readU16();
        return $this->readStringBody($cch);
    }

    public function readXlUnicodeStringNoCch(): string
    {
        return $this->readXlUnicodeString();
    }

    private function readStringBody(int $cch): string
    {
        $grbit = ord($this->readBytes(1));
        $uncompressed = ($grbit & 0x01) !== 0;
        $hasRuns = ($grbit & 0x08) !== 0;
        $hasExt = ($grbit & 0x04) !== 0;
        $cRun = $hasRuns ? $this->readU16() : 0;
        $cbExtRst = $hasExt ? $this->readU32() : 0;

        $charBytes = $uncompressed ? $cch * 2 : $cch;
        $raw = $this->readCharBytes($charBytes, $uncompressed);

        if ($hasRuns) {
            $this->readBytes($cRun * 4);
        }
        if ($hasExt) {
            $this->readBytes($cbExtRst);
        }

        return $raw;
    }

    private function readCharBytes(int $byteLen, bool $uncompressed): string
    {
        $out = '';
        $remainingChars = $uncompressed ? intdiv($byteLen, 2) : $byteLen;
        $currentlyUncompressed = $uncompressed;

        while ($remainingChars > 0) {
            $availableInRecord = $this->recordEnd - $this->pos;
            $bytesPerChar = $currentlyUncompressed ? 2 : 1;
            $charsAvailable = intdiv($availableInRecord, $bytesPerChar);

            if ($charsAvailable <= 0) {
                $this->crossIntoContinue();
                $currentlyUncompressed = $this->lastContinueUncompressed;
                continue;
            }

            $take = min($charsAvailable, $remainingChars);
            $bytes = $this->readRaw($take * $bytesPerChar);
            $out .= $currentlyUncompressed
                ? (@iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes) ?: '')
                : self::latin1ToUtf8($bytes);
            $remainingChars -= $take;
        }

        return $out;
    }

    private bool $lastContinueUncompressed = false;

    private function crossIntoContinue(): void
    {
        if ($this->pos + 4 > $this->streamLen) {
            throw new RuntimeException('Az .xls fájl megszakadt egy szöveg közepén (sérült fájl).');
        }
        $type = unpack('v', substr($this->stream, $this->pos, 2))[1];
        $len = unpack('v', substr($this->stream, $this->pos + 2, 2))[1];
        if ($type !== 0x003C) {
            throw new RuntimeException('Az .xls fájl formátuma nem a várt módon folytatódik (sérült fájl).');
        }
        $this->pos += 4;
        $this->recordEnd = $this->pos + $len;
        $flag = ord($this->readRaw(1));
        $this->lastContinueUncompressed = ($flag & 0x01) !== 0;
    }

    private function readBytes(int $n): string
    {
        if ($this->pos + $n > $this->recordEnd) {
            $out = $this->readRaw($this->recordEnd - $this->pos);
            $needed = $n - strlen($out);
            while ($needed > 0 && $this->pos + 4 <= $this->streamLen) {
                $type = unpack('v', substr($this->stream, $this->pos, 2))[1];
                $len = unpack('v', substr($this->stream, $this->pos + 2, 2))[1];
                if ($type !== 0x003C) {
                    break;
                }
                $this->pos += 4;
                $this->recordEnd = $this->pos + $len;
                $take = min($needed, $len);
                $out .= $this->readRaw($take);
                $needed -= $take;
            }
            return $out;
        }
        return $this->readRaw($n);
    }

    private function readRaw(int $n): string
    {
        $out = substr($this->stream, $this->pos, $n);
        $this->pos += $n;
        return $out;
    }

    private static function latin1ToUtf8(string $s): string
    {
        $out = @iconv('CP1252', 'UTF-8//IGNORE', $s);
        return $out !== false ? $out : $s;
    }
}
