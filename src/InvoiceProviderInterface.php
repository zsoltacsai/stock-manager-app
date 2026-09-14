<?php

/**
 * Közös szerződés a számlázási szolgáltatók (Számlázz.hu, NAV Online
 * Számla) számára — sale.php / webshop-order-invoice.php /
 * webshop-order-confirm.php ezen keresztül, NEM közvetlenül a
 * SzamlazzClient-en (vagy egy leendő NAV-klienshez) fordulnak, lásd
 * InvoiceService.
 *
 * SZÁNDÉKOSAN nincs egyetlen, egységes "küldd el és várd meg az
 * eredményt" metódus mindkét irányhoz: a Számlázz.hu-hívás SZINKRON (a
 * válasz a kérésen belül megvan), a NAV Online Számla viszont a saját
 * API-ja szerint ASZINKRON (a manageInvoice csak egy transactionId-t ad
 * vissza, a tényleges elfogadás/elutasítás egy külön
 * queryTransactionStatus-lekérdezéssel derül ki, később, egy háttér-
 * worker által) — egy egységes szinkron metódus ezt a valóságot fedné
 * el, és pont azt a hibát okozná, amit a NAV-integráció kifejezetten el
 * akar kerülni: hogy egy külső hívásra várva blokkolja/lassítsa az
 * eladás rögzítését.
 */
interface InvoiceProviderInterface
{
    /**
     * Igaz, ha ez a szolgáltató aszinkron — a hívó ilyenkor NEM kap
     * azonnali végeredményt, csak azt, hogy a beküldés beütemezve lett.
     */
    public function isAsync(): bool;

    /**
     * Csak SZINKRON szolgáltatóknál hívandó (isAsync() === false).
     * Ugyanazt az eredmény-alakot adja vissza, mint eddig a
     * SzamlazzClient::createInvoice(): kulcsai success/invoice_number/
     * pdf_path/error. Emellett, ha a hiba oka kifejezetten az, hogy egy
     * másik kérés már foglalta a számlázást (lásd
     * Database::tryClaimInvoiceIssuance()), a tömb egy opcionális
     * 'already_in_progress' => true kulcsot is tartalmaz — ez teszi
     * lehetővé, hogy egy hívó ezt az esetet megkülönböztesse egy VALÓDI
     * szolgáltatói hibától (pl. HTTP 409-cel), string-egyezés helyett.
     */
    public function issueSync(array $context): array;

    /**
     * Csak ASZINKRON szolgáltatóknál hívandó (isAsync() === true) — a
     * tényleges beküldést NEM ez a hívás végzi, csak egy tartós
     * feldolgozási sorba állítja be (a valódi elküldés egy külön
     * háttér-worker dolga, később) — a sale.php kérés emiatt sose
     * blokkolódik a külső hívásra várva.
     */
    public function enqueue(Database $db, int $saleId, array $context): void;

    /**
     * Igaz, ha ez a szolgáltató ténylegesen támogatja a megadott üzleti
     * műveletet — $operation ∈ {'modify','storno'} ('create' mindig
     * implicit támogatott, nem ezen a metóduson keresztül kérdezhető).
     * Az InvoiceService ezt hívja meg VALIDÁCIÓKÉNT, mielőtt egyáltalán
     * megpróbálná a requestModification()/requestStorno()-t meghívni —
     * a frontend SOSE dönthet erről saját maga (lásd a kör 18. pontja).
     */
    public function supportsOperation(string $operation): bool;

    /**
     * Egy MEGLÉVŐ, 'normal' típusú $original számlához tartozó helyesbítő
     * (módosító) számla indítása. $context ugyanazokat a kulcsokat várja,
     * mint issueSync()/enqueue() (db, sale_id, buyer, items, totals,
     * payment_method, language), PLUSZ egy 'operation_uuid' kulcsot — a
     * hívó (InvoiceService) felelőssége ezt egyszer, az adott módosítási
     * KÍSÉRLET indításakor legenerálni, és minden retry/dupla-kattintás
     * esetén UGYANAZT visszaadni (lásd Database::createInvoiceOperation()
     * 'modify:{original_invoice_id}:{uuid}' operation_key mintája —
     * ez adja az attempt-szintű idempotenciát).
     *
     * Szinkron szolgáltatónál (Számlázz.hu) a végleges eredménnyel tér
     * vissza (ugyanaz az alak, mint issueSync()); aszinkron szolgáltatónál
     * (NAV) csak a tartós queue-bejegyzést hozza létre és
     * ['success'=>false,'pending'=>true,...]-t ad, a tényleges NAV-kérést a
     * meglévő queue-worker küldi be KÉSŐBB (lásd NavInvoiceProvider::submit()
     * invoice_type szerinti elágazása) — NINCS külön MODIFY/STORNO queue.
     */
    public function requestModification(Database $db, array $original, array $context): array;

    /**
     * Egy MEGLÉVŐ, 'normal' típusú $original számla sztornózása (érvénytelenítése)
     * új, önálló pénzügyi bizonylat formájában. Ugyanaz az elv, mint
     * requestModification()-nél — lásd ott a $context/visszatérési alak
     * részletezését. STORNO esetén $context['items']/'buyer' jellemzően az
     * eredeti számla adataiból származik (a hívó, InvoiceService tölti fel),
     * NEM a felhasználó szabad bevitele.
     */
    public function requestStorno(Database $db, array $original, array $context): array;
}
