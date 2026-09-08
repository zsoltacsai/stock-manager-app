<?php

class ProductRowNormalizer
{
    private const ALLOWED_VAT_RATES = ['27', '18', '5', '0'];

    public static function normalize(array $row, array $profile): array
    {
        $stockQty    = self::parseNumber($row['stock_qty'] ?? '0');
        $purchaseNet = self::parseNumber($row['purchase_price_net'] ?? '0');
        $net         = self::parseNumber($row['net_price'] ?? '0');
        $gross       = self::parseNumber($row['price'] ?? '0');

        // Néhány forrás program (pl. Jutasoft) nem ad külön nettó eladási
        // ár oszlopot, csak bruttó árat és egy sortonkénti ÁFA%-ot — ilyen
        // esetben a nettó árat ebből számítjuk, mielőtt az egyébként is
        // meglévő bruttó/nettó arányból való visszakövetkeztetésre
        // (inferVatRate()) hagyatkoznánk, mert az itt nettó=0 mellett
        // úgyis csak a profil alapértelmezett ÁFA-kulcsát adná vissza.
        $vatRateField = trim((string) ($row['vat_rate'] ?? ''));
        if ($net <= 0 && $gross > 0 && $vatRateField !== '') {
            $vatPercent = self::parseNumber($vatRateField);
            if ($vatPercent > 0) {
                $net = round($gross / (1 + $vatPercent / 100), 2);
            }
        }

        return [
            'name'               => trim($row['name'] ?? ''),
            'barcode'            => trim($row['barcode'] ?? ''),
            'cikkszam'           => trim($row['cikkszam'] ?? ''),
            'group_name'         => trim($row['group_name'] ?? ''),
            'unit'               => trim($row['unit'] ?? '') ?: 'db',
            'notes'              => trim($row['notes'] ?? ''),
            'stock_qty'          => (int) round($stockQty),
            'purchase_price_net' => $purchaseNet,
            'net_price'          => $net,
            'price'              => $gross,
            'currency'           => $profile['default_currency'] ?? 'HUF',
            'vat_rate'           => self::inferVatRate($net, $gross, $profile['default_vat_rate'] ?? '27'),
        ];
    }

    /**
     * Igaz, ha ezt a (már normalizált) sort NEM szabad tényleges
     * termékként kezelni — se előnézetben számolni "beszúrandó/
     * frissítendő" oszlopba, se ténylegesen importálni. Két oka lehet:
     *   1) nincs név (üres sor / hibás sor) — ez minden profilra érvényes.
     *   2) a profil kifejezetten jelezte (skip_rows_without_identifier),
     *      hogy vonalkód ÉS cikkszám hiányában is ki kell hagyni — ez a
     *      Jutasoft-riport végén lévő összesítő/ÁFA-bontás sorokat szűri
     *      ki, amiknek van "neve" (valójában egy összesítő felirat), de
     *      semmilyen termék-azonosítójuk nincs.
     */
    public static function shouldSkip(array $normalized, array $profile): bool
    {
        if ($normalized['name'] === '') {
            return true;
        }
        if (!empty($profile['skip_rows_without_identifier'])
            && $normalized['barcode'] === ''
            && $normalized['cikkszam'] === ''
        ) {
            return true;
        }
        return false;
    }

    public static function parseNumber(string $raw): float
    {
        $s = trim($raw);
        if ($s === '') {
            return 0.0;
        }

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            if (strrpos($s, ',') > strrpos($s, '.')) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($hasComma) {
            $s = str_replace(',', '.', $s);
        }

        $s = preg_replace('/[^0-9.\-]/', '', $s);
        return $s === '' || $s === '-' ? 0.0 : (float) $s;
    }

    private static function inferVatRate(float $net, float $gross, string $default): string
    {
        if ($net <= 0) {
            return $default;
        }
        $percent = round((($gross / $net) - 1) * 100);
        foreach (self::ALLOWED_VAT_RATES as $allowed) {
            if (abs($percent - (float) $allowed) < 1.0) {
                return $allowed;
            }
        }
        return $default;
    }
}
