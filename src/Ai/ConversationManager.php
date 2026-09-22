<?php

declare(strict_types=1);

require_once __DIR__ . '/ToolCall.php';
require_once __DIR__ . '/ToolResult.php';

/**
 * Könnyű, KIZÁRÓLAG egyetlen agent-futás időtartamára élő üzenet-lista —
 * SZÁNDÉKOSAN nincs tartós/adatbázisba mentett beszélgetés-előzmény ebben
 * a körben (lásd a kör 5. pontja — ez később, külön kör tárgya lehet,
 * anélkül, hogy ez az osztály megváltozna: egy jövőbeli perzisztencia-
 * réteg egyszerűen elmenthetné a toArray() kimenetét).
 */
final class ConversationManager
{
    /** @var array<int,array{role:string,content:?string,tool_calls?:array,tool_call_id?:string,name?:string}> */
    private array $messages = [];

    public function __construct(string $systemInstruction, string $userMessage)
    {
        $this->messages[] = ['role' => 'system', 'content' => $systemInstruction];
        $this->messages[] = ['role' => 'user', 'content' => $userMessage];
    }

    /** @param ToolCall[] $toolCalls */
    public function addAssistantMessage(?string $content, array $toolCalls): void
    {
        $entry = ['role' => 'assistant', 'content' => $content];
        if ($toolCalls) {
            $entry['tool_calls'] = array_map(static fn (ToolCall $c) => [
                'id' => $c->id,
                'function' => ['name' => $c->name, 'arguments' => $c->arguments],
            ], $toolCalls);
        }
        $this->messages[] = $entry;
    }

    public function addToolResult(ToolResult $result): void
    {
        $this->messages[] = [
            'role' => 'tool',
            'tool_call_id' => $result->toolCallId,
            'name' => $result->name,
            'content' => json_encode($result->toProviderPayload(), JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @return array<int,array{role:string,content:?string,tool_calls?:array,tool_call_id?:string,name?:string}> */
    public function toArray(): array
    {
        return $this->messages;
    }
}
