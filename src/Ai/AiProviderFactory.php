<?php

declare(strict_types=1);

require_once __DIR__ . '/AiProviderInterface.php';
require_once __DIR__ . '/LocalProvider.php';
require_once __DIR__ . '/AnthropicProvider.php';
require_once __DIR__ . '/OpenAiProvider.php';

/**
 * Az egyetlen hely, ahol az `ai_provider` beállítás (settings.json,
 * Settings::DEFAULTS) egy konkrét AiProviderInterface-példánnyá válik —
 * lásd a kör 5. pontja. SZIGORÚ fehérlista (`local`, `anthropic`,
 * `openai`): a beállításban tárolt érték SOSE használható közvetlenül
 * osztálynévként/dinamikus példányosításhoz, mindig egy explicit match
 * ágon megy keresztül. Ismeretlen/érvénytelen érték esetén BIZTONSÁGOSAN
 * elutasít (kivétel + korlátozott rendszeresemény-napló), sose esik
 * vissza csendben egy másik providerre — az elrejtene egy konfigurációs
 * hibát.
 *
 * Az InventoryAgent/AgentRunner ezt a factory-t sose látja/hívja —
 * KIZÁRÓLAG a végpont (pl. ai-inventory.php) hívja meg egyszer, a kapott
 * AiProviderInterface-t adja tovább az AgentRunner konstruktorának. A
 * provider-választás logika ezzel garantáltan az InventoryAgentEN KÍVÜL
 * marad (lásd a kör 4. pontja explicit tiltása).
 */
final class AiProviderFactory
{
    private const ALLOWED_PROVIDERS = ['local', 'anthropic', 'openai'];

    /**
     * Fázis 9 — a kör 17. pontja: determinisztikus modell-útválasztás.
     * A $complexity KIZÁRÓLAG a szerver-oldali hívó (pl. AiCopilot esetén
     * 'complex', egy közvetlen domain-agent esetén 'default') dönti el
     * — SOSE böngésző-bemenetből (lásd webroot/api/ai-copilot.php/
     * ai-inventory.php stb. — ezek a végpontok NEM fogadnak el
     * semmilyen modell/complexity mezőt a kérés törzséből). Ha az admin
     * nem állított be külön "komplex" modellt (üres string), a MEGLÉVŐ,
     * alap modellre esik vissza — ez a paraméter tehát alapból teljesen
     * no-op, amíg egy admin explicit ki nem tölti.
     *
     * @param array<string,mixed> $appSettings
     * @param 'default'|'complex' $complexity
     */
    public static function create(array $appSettings, ?Database $db = null, string $complexity = 'default'): AiProviderInterface
    {
        $providerName = (string) ($appSettings['ai_provider'] ?? 'local');

        if (!in_array($providerName, self::ALLOWED_PROVIDERS, true)) {
            self::logInvalidProvider($db, $appSettings, $providerName);
            // Fázis 10 — a kör 17. pontja: ez egy KONFIGURÁCIÓS hiba (az
            // admin ír be érvénytelen `ai_provider` értéket), NEM egy
            // valódi hálózati/provider-oldali elérhetetlenség — a kettő
            // eddig tévesen ugyanazt az 'unavailable' kategóriát kapta,
            // ami a naplózásban/diagnosztikában összemosta a két,
            // gyökeresen eltérő elhárítási utat igénylő esetet.
            throw new AiProviderException(
                "Ismeretlen AI-provider beállítás: \"$providerName\".",
                'configuration_error'
            );
        }

        $maxOutputTokens = isset($appSettings['ai_max_output_tokens']) && $appSettings['ai_max_output_tokens'] !== null
            ? (int) $appSettings['ai_max_output_tokens']
            : null;

        $isComplex = $complexity === 'complex';

        return match ($providerName) {
            'anthropic' => new AnthropicProvider(
                (string) $appSettings['anthropic_base_url'],
                (string) $appSettings['anthropic_api_key'],
                self::resolveModel($appSettings, 'anthropic_model', 'anthropic_model_complex', $isComplex),
                (int) $appSettings['anthropic_timeout_seconds'],
                $maxOutputTokens
            ),
            'openai' => new OpenAiProvider(
                (string) $appSettings['openai_base_url'],
                (string) $appSettings['openai_api_key'],
                self::resolveModel($appSettings, 'openai_model', 'openai_model_complex', $isComplex),
                (int) $appSettings['openai_timeout_seconds'],
                $maxOutputTokens
            ),
            default => new LocalProvider(
                (string) $appSettings['ai_local_base_url'],
                self::resolveModel($appSettings, 'ai_local_model', 'ai_local_model_complex', $isComplex),
                (int) $appSettings['ai_timeout_seconds'],
                $maxOutputTokens
            ),
        };
    }

    /**
     * PUBLIC — a kör 17. pontja: AiCopilot (mindig a "complex" ágat
     * jelenti) ugyanezt a döntést használja a NAPLÓZÁSHOZ/árazáshoz
     * használt modellnévhez, hogy az garantáltan megegyezzen a ténylegesen
     * létrehozott providerpéldány modelljével.
     */
    public static function resolveModel(array $appSettings, string $defaultKey, string $complexKey, bool $isComplex): string
    {
        if ($isComplex) {
            $complexModel = trim((string) ($appSettings[$complexKey] ?? ''));
            if ($complexModel !== '') {
                return $complexModel;
            }
        }
        return (string) ($appSettings[$defaultKey] ?? '');
    }

    private static function logInvalidProvider(?Database $db, array $appSettings, string $providerName): void
    {
        if ($db === null) {
            return;
        }
        try {
            $db->logSystemEvent(
                'ai',
                'invalid_provider_config',
                'warning',
                'failure',
                'Ismeretlen AI-provider beállítás (fehérlistán kívüli érték).',
                json_encode(['configured_value' => mb_substr($providerName, 0, 100)], JSON_UNESCAPED_UNICODE),
                (int) ($appSettings['system_events_retention_days'] ?? 14)
            );
        } catch (Throwable $e) {
            error_log('[fountaintrade] AiProviderFactory invalid-provider log sikertelen: ' . $e->getMessage());
        }
    }
}
