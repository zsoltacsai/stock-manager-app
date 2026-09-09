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
}
