<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 9 — determinisztikus modell-útválasztás tesztjei (a kör 17/29.
 * pontja). A böngésző SOSE tud modellt választani — ez a teszt-osztály
 * KIZÁRÓLAG AiProviderFactory::create()-et hívja (a szerver-oldali
 * döntési pontot), SOHA nincs benne HTTP-bemenet.
 */
final class AiModelRoutingTest extends TestCase
{
    private function usePrivate(AiProviderInterface $provider, string $property)
    {
        $ref = new ReflectionProperty($provider, $property);
        $ref->setAccessible(true);
        return $ref->getValue($provider);
    }

    public function testSimpleRequestUsesConfiguredDefaultModel(): void
    {
        $settings = ['ai_provider' => 'anthropic', 'anthropic_base_url' => 'https://api.anthropic.com', 'anthropic_api_key' => 'k', 'anthropic_model' => 'claude-sonnet-5', 'anthropic_model_complex' => 'claude-opus-5', 'anthropic_timeout_seconds' => 30];
        $provider = AiProviderFactory::create($settings, null, 'default');
        $this->assertSame('claude-sonnet-5', $this->usePrivate($provider, 'model'));
    }

    public function testComplexRequestUsesConfiguredComplexModel(): void
    {
        $settings = ['ai_provider' => 'anthropic', 'anthropic_base_url' => 'https://api.anthropic.com', 'anthropic_api_key' => 'k', 'anthropic_model' => 'claude-sonnet-5', 'anthropic_model_complex' => 'claude-opus-5', 'anthropic_timeout_seconds' => 30];
        $provider = AiProviderFactory::create($settings, null, 'complex');
        $this->assertSame('claude-opus-5', $this->usePrivate($provider, 'model'));
    }

    public function testComplexRequestFallsBackToDefaultWhenNoComplexModelConfigured(): void
    {
        $settings = ['ai_provider' => 'anthropic', 'anthropic_base_url' => 'https://api.anthropic.com', 'anthropic_api_key' => 'k', 'anthropic_model' => 'claude-sonnet-5', 'anthropic_model_complex' => '', 'anthropic_timeout_seconds' => 30];
        $provider = AiProviderFactory::create($settings, null, 'complex');
        $this->assertSame('claude-sonnet-5', $this->usePrivate($provider, 'model'));
    }

    public function testRoutingWorksAcrossAllThreeProviders(): void
    {
        $localSettings = ['ai_provider' => 'local', 'ai_local_base_url' => 'http://127.0.0.1:11434', 'ai_local_model' => 'qwen3:8b', 'ai_local_model_complex' => 'qwen3:30b', 'ai_timeout_seconds' => 30];
        $this->assertSame('qwen3:30b', $this->usePrivate(AiProviderFactory::create($localSettings, null, 'complex'), 'model'));
        $this->assertSame('qwen3:8b', $this->usePrivate(AiProviderFactory::create($localSettings, null, 'default'), 'model'));

        $openaiSettings = ['ai_provider' => 'openai', 'openai_base_url' => 'https://api.openai.com', 'openai_api_key' => 'k', 'openai_model' => 'gpt-6-sol', 'openai_model_complex' => 'gpt-6-sol-pro', 'openai_timeout_seconds' => 30];
        $this->assertSame('gpt-6-sol-pro', $this->usePrivate(AiProviderFactory::create($openaiSettings, null, 'complex'), 'model'));
        $this->assertSame('gpt-6-sol', $this->usePrivate(AiProviderFactory::create($openaiSettings, null, 'default'), 'model'));
    }

    public function testCopilotAlwaysRoutesToComplexModelConfiguration(): void
    {
        // A Copilot a kör 17. pontja szerinti PÉLDA-jelzőt ("Copilot vs
        // direct agent") használja — MINDIG a "complex" ágat jelenti,
        // lásd AiCopilot::configuredModel().
        $db = tests_new_database();
        $settings = ['ai_local_model' => 'qwen3:8b', 'ai_local_model_complex' => 'qwen3:30b'];
        $provider = new FakeAiProvider([new AiChatResponse('válasz', [])]);
        $copilot = new AiCopilot($provider, $db, $settings, 5);

        $ref = new ReflectionMethod($copilot, 'configuredModel');
        $ref->setAccessible(true);
        $this->assertSame('qwen3:30b', $ref->invoke($copilot));
    }

    public function testDirectAgentConstructionNeverConsultsComplexModelSetting(): void
    {
        // A közvetlen domain-agent (nem Copilot-on keresztül) a MEGLÉVŐ,
        // 'default' útválasztási ágon megy — ezt maga az AiProviderFactory
        // hívási helye (webroot/api/ai-inventory.php stb.) dönti el, ez a
        // teszt csak azt bizonyítja, hogy 'default' complexity SOSE
        // választja a "komplex" modellt, még ha az be is van állítva.
        $settings = ['ai_provider' => 'local', 'ai_local_base_url' => 'http://127.0.0.1:11434', 'ai_local_model' => 'qwen3:8b', 'ai_local_model_complex' => 'qwen3:30b', 'ai_timeout_seconds' => 30];
        $provider = AiProviderFactory::create($settings); // alapértelmezett 3. paraméter: 'default'
        $this->assertSame('qwen3:8b', $this->usePrivate($provider, 'model'));
    }

    public function testInvalidConfiguredProviderIsStillRejectedRegardlessOfComplexity(): void
    {
        // A kör 29. pontja — "invalid configured model"/"routing remains
        // deterministic": egy érvénytelen PROVIDER-beállítás (nem
        // modell) mindkét complexity-ágon egyformán, biztonságosan
        // elutasítandó — a szigorú fehérlista (AiProviderFactory::
        // ALLOWED_PROVIDERS) SOSE kerülhető meg a complexity paraméterrel.
        $settings = ['ai_provider' => 'nem_letezo_provider'];
        $this->expectException(AiProviderException::class);
        AiProviderFactory::create($settings, null, 'complex');
    }
}
