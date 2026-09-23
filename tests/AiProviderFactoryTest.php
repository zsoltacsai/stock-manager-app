<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AiProviderFactory — a kör 5. pontjának szigorú-fehérlista követelménye:
 * a settings.json-ban tárolt "ai_provider" érték sose válhat közvetlenül
 * példányosított osztálynévvé, csak egy explicit match-ágon keresztül.
 * Ismeretlen érték esetén biztonságosan elutasít (kivétel), sose esik
 * vissza csendben egy másik providerre.
 */
final class AiProviderFactoryTest extends TestCase
{
    private function baseSettings(): array
    {
        return [
            'ai_local_base_url' => 'http://127.0.0.1:11434',
            'ai_local_model' => 'qwen3:8b',
            'ai_timeout_seconds' => 30,
            'ai_max_output_tokens' => null,
            'anthropic_api_key' => 'sk-ant-teszt',
            'anthropic_model' => 'claude-sonnet-5',
            'anthropic_base_url' => 'https://api.anthropic.com',
            'anthropic_timeout_seconds' => 30,
        ];
    }

    public function testDefaultsToLocalProviderWhenUnset(): void
    {
        $settings = $this->baseSettings();
        unset($settings['ai_provider']);
        $provider = AiProviderFactory::create($settings);
        $this->assertInstanceOf(LocalProvider::class, $provider);
        $this->assertSame('local', $provider->name());
    }

    public function testSelectsLocalProviderExplicitly(): void
    {
        $settings = $this->baseSettings();
        $settings['ai_provider'] = 'local';
        $provider = AiProviderFactory::create($settings);
        $this->assertInstanceOf(LocalProvider::class, $provider);
    }

    public function testSelectsAnthropicProviderExplicitly(): void
    {
        $settings = $this->baseSettings();
        $settings['ai_provider'] = 'anthropic';
        $provider = AiProviderFactory::create($settings);
        $this->assertInstanceOf(AnthropicProvider::class, $provider);
        $this->assertSame('anthropic', $provider->name());
    }

    public function testInvalidProviderValueIsRejectedNotInstantiated(): void
    {
        $settings = $this->baseSettings();
        // Egy próbált "class injection" jellegű, kártékony érték — a
        // factory-nak ezt is EGYSZERŰ fehérlista-elutasítással kell
        // kezelnie, sose próbálja meg osztályként feloldani.
        $settings['ai_provider'] = 'LocalProvider; DROP TABLE products;--';
        try {
            AiProviderFactory::create($settings);
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            $this->assertSame('unavailable', $e->kind);
        }
    }

    public function testEmptyStringProviderValueIsRejected(): void
    {
        $settings = $this->baseSettings();
        $settings['ai_provider'] = '';
        $this->expectException(AiProviderException::class);
        AiProviderFactory::create($settings);
    }

    public function testAnthropicProviderIsConstructedWithConfiguredSettings(): void
    {
        $settings = $this->baseSettings();
        $settings['ai_provider'] = 'anthropic';
        $settings['anthropic_model'] = 'claude-opus-5-5';
        $provider = AiProviderFactory::create($settings);
        // A modellnevet indirekt módon, egy checkAvailability()-hívással
        // ellenőrizzük (nincs publikus getter, szándékosan — lásd
        // AnthropicProvider konstruktor docblokkja), egy nem elérhető
        // porttal, hogy sose induljon valódi hálózati hívás.
        $reflection = new ReflectionProperty(AnthropicProvider::class, 'model');
        $reflection->setAccessible(true);
        $this->assertSame('claude-opus-5-5', $reflection->getValue($provider));
    }

    public function testInvalidProviderLogsABoundedSystemEventWhenDatabaseGiven(): void
    {
        $db = tests_new_database();
        $settings = $this->baseSettings();
        $settings['ai_provider'] = 'not_a_real_provider';

        try {
            AiProviderFactory::create($settings, $db);
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            // várt
        }

        $stmt = $db->pdo()->query("SELECT * FROM system_events WHERE category='ai' AND event_type='invalid_provider_config'");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertStringNotContainsString('DROP TABLE', $rows[0]['technical_detail'] ?? '');
    }
}
