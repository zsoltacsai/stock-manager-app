<?php

declare(strict_types=1);

require_once __DIR__ . '/AiChatResponse.php';
require_once __DIR__ . '/AiAvailability.php';

/**
 * A FountainTrade AI-rétegének EGYETLEN, provider-független szerződése —
 * az AgentRunner KIZÁRÓLAG ezen a felületen keresztül beszél bármelyik
 * providerrel (LocalProvider/Ollama most, később AnthropicProvider/
 * OpenAiProvider ugyanezzel a szerződéssel — lásd README "AI asszisztens"
 * szakasza a jövőbeli bővíthetőségről). Az AgentRunner SOSE tud/feltételez
 * semmit egy konkrét provider natív HTTP/válasz-formátumáról.
 */
interface AiProviderInterface
{
    /** Rövid, naplózható/megjeleníthető azonosító, pl. 'local'. */
    public function name(): string;

    /**
     * @param array<int,array{role:string,content:?string,tool_calls?:array,tool_call_id?:string,name?:string}> $messages
     *   Az Ollama/OpenAI "chat message" alak (role: system|user|assistant|tool) — ez a legkisebb közös
     *   nevező a jövőbeli providerek között, ezért ezt választottuk belső üzenet-alaknak is (lásd
     *   ConversationManager), nem egy külön, saját DTO-t.
     * @param ToolDefinition[] $tools
     * @throws AiProviderException kapcsolódási/időtúllépési/hibásan formázott válasz esetén.
     */
    public function chat(array $messages, array $tools): AiChatResponse;

    /** Könnyű, gyors elérhetőség-ellenőrzés — lásd OllamaHealth a cache-elt, felület felé mutatott változatért. */
    public function checkAvailability(): AiAvailability;
}
