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
     * @param array<string,mixed> $appSettings
     */
    public static function create(array $appSettings, ?Database $db = null): AiProviderInterface
    {
        $providerName = (string) ($appSettings['ai_provider'] ?? 'local');

        if (!in_array($providerName, self::ALLOWED_PROVIDERS, true)) {
            self::logInvalidProvider($db, $appSettings, $providerName);
            throw new AiProviderException(
                "Ismeretlen AI-provider beállítás: \"$providerName\".",
                'unavailable'
            );
        }

        $maxOutputTokens = isset($appSettings['ai_max_output_tokens']) && $appSettings['ai_max_output_tokens'] !== null
            ? (int) $appSettings['ai_max_output_tokens']
            : null;

        return match ($providerName) {
            'anthropic' => new AnthropicProvider(
                (string) $appSettings['anthropic_base_url'],
                (string) $appSettings['anthropic_api_key'],
                (string) $appSettings['anthropic_model'],
                (int) $appSettings['anthropic_timeout_seconds'],
                $maxOutputTokens
            ),
            'openai' => new OpenAiProvider(
                (string) $appSettings['openai_base_url'],
                (string) $appSettings['openai_api_key'],
                (string) $appSettings['openai_model'],
                (int) $appSettings['openai_timeout_seconds'],
                $maxOutputTokens
            ),
            default => new LocalProvider(
                (string) $appSettings['ai_local_base_url'],
                (string) $appSettings['ai_local_model'],
                (int) $appSettings['ai_timeout_seconds'],
                $maxOutputTokens
            ),
        };
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
