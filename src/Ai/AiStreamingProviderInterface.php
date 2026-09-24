<?php

declare(strict_types=1);

require_once __DIR__ . '/AiChatResponse.php';
require_once __DIR__ . '/AiStreamEvent.php';

/**
 * Fázis 9 — a kör 3. pontja: OPCIONÁLIS, ADDITÍV streamelési képesség.
 * A MEGLÉVŐ AiProviderInterface::chat() (szinkron) SZERZŐDÉSE
 * VÁLTOZATLAN marad — ez egy KÜLÖN interfész, amit egy Provider
 * OPCIONÁLISAN, TOVÁBBI implementációként vállalhat (lásd LocalProvider/
 * AnthropicProvider/OpenAiProvider — mindhárom megvalósítja, de az
 * AgentRunner SOSE feltételezi ezt egy AiProviderInterface-példányról:
 * mindig `instanceof AiStreamingProviderInterface`-t ellenőriz, és
 * hiányában automatikusan a MEGLÉVŐ, szinkron chat()-re esik vissza —
 * lásd a kör 9. pontja, "Streaming is an enhancement, not a hard
 * dependency").
 *
 * KRITIKUS (a kör 4. pontja): egy eszköz-hívás argumentumai a stream
 * KÖZBEN még TÖREDÉKESEK lehetnek (pl. Anthropic input_json_delta,
 * OpenAI function_call_arguments.delta) — az implementáció FELELŐSSÉGE,
 * hogy ezeket a provider saját, natív "blokk lezárva"/"elem kész"
 * jelzéséig PUFFERELJE, és a visszaadott AiChatResponse::$toolCalls
 * KIZÁRÓLAG TELJES, érvényes argumentumú ToolCall-okat tartalmazzon —
 * pontosan ugyanazt a szerződést teljesítve, mint a szinkron chat().
 * Az $onEvent callback-nek küldött `tool_call_arguments_delta` esemény
 * KIZÁRÓLAG UX-célú (élő "gépelés" hatás), az AgentRunner/ToolRegistry
 * SOSE dolgozik fel argumentumot ebből az eseményből.
 */
interface AiStreamingProviderInterface
{
    /**
     * @param array<int,array{role:string,content:?string,tool_calls?:array,tool_call_id?:string,name?:string}> $messages
     * @param ToolDefinition[] $tools
     * @param callable(AiStreamEvent):void $onEvent KIZÁRÓLAG UX-progresszió — sose befolyásolja a visszatérési értéket.
     * @throws AiProviderException lásd AiProviderInterface::chat() docblokkja.
     */
    public function chatStream(array $messages, array $tools, callable $onEvent): AiChatResponse;
}
