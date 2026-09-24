<?php

declare(strict_types=1);

/**
 * Fázis 8B — egy konkrét, végrehajtható javaslat-típus (pl. reorder_draft)
 * TÉNYLEGES üzleti mutációja EZEN a szerződésen keresztül. Lásd
 * ActionExecutor::execute() — a stratégia egy MÁR NYITOTT DB-tranzakción
 * BELÜL fut (a hívó nyitja/commitolja/görgeti vissza), a stratégia
 * SOSE kezel saját tranzakciót.
 *
 * A stratégia:
 *  - SOSE hív LLM-et/AI-providert (a kör 30. pontja: "the execution
 *    framework must be provider-neutral... does not depend on which
 *    provider generated it" — a végrehajtás a jóváhagyás UTÁN teljesen
 *    determinisztikus).
 *  - SOSE bízik a $proposal['evidence']/['proposal_json'] mezőkben
 *    tárolt, a javaslat LÉTREHOZÁSAKORI mennyiségi/ár-adatokban — minden
 *    végrehajtáshoz szükséges értéket a Database-ből FRISSEN kell
 *    lekérdeznie (a kör 9. pontja: "The LLM is never authoritative for
 *    quantity").
 *  - `ActionExecutionStaleException`-t dob, ha az üzleti állapot már nem
 *    támasztja alá a javaslatot; egyéb `Throwable`-t egy technikai hiba
 *    esetén (ekkor az ActionExecutor a tranzakciót visszagörgeti és
 *    'execution_failed'-ként rögzíti).
 */
interface ExecutableActionStrategy
{
    /**
     * @param array<string,mixed> $proposal Database::getActionProposal() nyers sora (status='executing')
     * @return array<string,mixed> bounded, JSON-szerializálható strukturált eredmény (lásd a kör 20. pontja)
     */
    public function execute(Database $db, array $proposal): array;
}
