<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

/**
 * PERF-04 — könnyű termékjegyzék (id, név, vonalkód; nem törölt, név szerint)
 * a készletmozgás-riport termékszűrőjéhez. Streamelve íródik ki: a PHP
 * memóriaigénye nem nő a katalógus méretével.
 */
http_response_code(200);
echo '{"products":[';
$first = true;
$db->eachProductLookupRow(static function (array $row) use (&$first): void {
    echo ($first ? '' : ',') . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $first = false;
});
echo ']}';
