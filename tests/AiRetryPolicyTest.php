<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 10 — a kör 9/23. pontja: az ÚJ, KIZÁRÓLAG a nem-streamelt
 * provider-kérésekre alkalmazott, korlátozott újrapróbálkozási logika
 * (lásd AiRetryPolicy.php docblokkja a teljes indoklásért) közvetlen
 * tesztjei.
 */
final class AiRetryPolicyTest extends TestCase
{
    public function testSucceedsImmediatelyWithoutRetryOnFirstSuccess(): void
    {
        $calls = 0;
        $result = AiRetryPolicy::run(function () use (&$calls) {
            $calls++;
            return 'ok';
        });
        $this->assertSame('ok', $result);
        $this->assertSame(1, $calls);
    }

    public function testRetriesOnTransientKindAndEventuallySucceeds(): void
    {
        $calls = 0;
        $result = AiRetryPolicy::run(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw new AiProviderException('átmeneti hiba', 'unavailable');
            }
            return 'ok a 3. próbálkozásra';
        });
        $this->assertSame('ok a 3. próbálkozásra', $result);
        $this->assertSame(3, $calls, 'Legfeljebb 3 TELJES kísérlet engedélyezett (1 + 2 újrapróbálkozás).');
    }

    public function testGivesUpAfterMaxAttemptsAndRethrowsLastException(): void
    {
        $calls = 0;
        try {
            AiRetryPolicy::run(function () use (&$calls) {
                $calls++;
                throw new AiProviderException('folyamatosan időtúllépés', 'timeout');
            });
            $this->fail('AiProviderException-t vártunk.');
        } catch (AiProviderException $e) {
            $this->assertSame('timeout', $e->kind);
        }
        $this->assertSame(3, $calls, 'Pontosan 3 kísérlet után fel kell adnia — nincs végtelen/korlátlan újrapróbálkozás.');
    }

    /** @dataProvider nonRetryableKinds */
    public function testNeverRetriesDeterministicFailureKinds(string $kind): void
    {
        $calls = 0;
        try {
            AiRetryPolicy::run(function () use (&$calls, $kind) {
                $calls++;
                throw new AiProviderException('determinisztikus hiba', $kind);
            });
            $this->fail('AiProviderException-t vártunk.');
        } catch (AiProviderException $e) {
            // várt
        }
        $this->assertSame(1, $calls, "'$kind' SOSE próbálható újra — hitelesítési/konfigurációs/formátum-hiba egy ismételt kéréssel sem oldódna meg, csak feleslegesen lassítaná a hibát.");
    }

    public static function nonRetryableKinds(): array
    {
        return [
            'configuration_error' => ['configuration_error'],
            'auth_error' => ['auth_error'],
            'malformed_response' => ['malformed_response'],
            'http_error' => ['http_error'],
        ];
    }

    public function testNonProviderExceptionsAreNeverCaughtOrRetried(): void
    {
        // Egy VÁRATLAN (nem AiProviderException) kivétel — pl. egy
        // programozási hiba a hívott callable-ben — SOSE nyelődik el
        // csendben újrapróbálkozásként.
        $this->expectException(RuntimeException::class);
        AiRetryPolicy::run(function () {
            throw new RuntimeException('ez nem egy provider-hiba');
        });
    }

    public function testRetryTimingIsBoundedNotInstantaneous(): void
    {
        // Nem a pontos időzítést teszteljük (az flaky lenne), csak azt,
        // hogy TÉNYLEGESEN történik várakozás a próbálkozások között
        // (nem egy szoros, várakozás nélküli ciklus) — "no retry storms".
        $calls = 0;
        $start = microtime(true);
        try {
            AiRetryPolicy::run(function () use (&$calls) {
                $calls++;
                throw new AiProviderException('x', 'rate_limit');
            });
        } catch (AiProviderException $e) {
            // várt
        }
        $elapsed = microtime(true) - $start;
        // 2 újrapróbálkozás, 200ms + 400ms = legalább 600ms.
        $this->assertGreaterThanOrEqual(0.5, $elapsed, 'A várakozásoknak ténylegesen meg kell történniük, nem csak elméletben léteznek.');
        $this->assertLessThan(3.0, $elapsed, 'A teljes várakozás korlátozott kell legyen — nem lassulhat el ok nélkül.');
    }
}
