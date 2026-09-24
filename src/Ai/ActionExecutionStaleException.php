<?php

declare(strict_types=1);

/**
 * Fázis 8B — a kör 6. pontja: "Before execution, re-fetch current
 * business state." Egy végrehajtás-stratégia (pl. ReorderDraftExecutor)
 * EZT dobja, ha a jóváhagyás óta (vagy akár MÁR a claim után, a
 * tranzakción belüli revalidáláskor) az üzleti állapot már NEM
 * támasztja alá a javaslatot (pl. a készlet időközben helyreállt, vagy a
 * termék törölve lett) — az ActionExecutor ezt KÜLÖN kapja el a
 * generikus Throwable-ágtól, és a javaslatot 'stale'-re (NEM
 * 'execution_failed'-re) állítja, mert ez NEM technikai hiba, hanem
 * legitim, determinisztikus üzleti döntés.
 */
final class ActionExecutionStaleException extends RuntimeException
{
}
