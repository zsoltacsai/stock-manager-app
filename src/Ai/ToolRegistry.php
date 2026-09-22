<?php

declare(strict_types=1);

require_once __DIR__ . '/ToolDefinition.php';
require_once __DIR__ . '/ToolCall.php';
require_once __DIR__ . '/ToolResult.php';

/**
 * Az EGYETLEN hely, ahonnan egy AI-eszköz ténylegesen meghívható. A
 * providertől (LLM) visszakapott ToolCall::$name kizárólag a MÁR
 * regisztrált, fordítási időben rögzített eszközök nevei közül
 * választhat — egy ismeretlen nevű hívás sose fut le, mindig egy
 * biztonságos ToolResult::fail()-lel tér vissza (a hívó/AgentRunner
 * dönti el, hogyan folytatja onnan). Ez zárja ki, hogy a modell
 * tetszőleges PHP-kódot vagy nyers SQL-t hívjon meg — lásd a projekt AI
 * biztonsági alapelve (README "AI asszisztens" szakasza).
 */
final class ToolRegistry
{
    /** @var array<string,ToolDefinition> */
    private array $tools = [];

    public function register(ToolDefinition $tool): void
    {
        if (isset($this->tools[$tool->name])) {
            throw new InvalidArgumentException("Az eszköz neve már regisztrálva van: {$tool->name}");
        }
        $this->tools[$tool->name] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** @return ToolDefinition[] */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * A providernek küldött eszköz-leírás lista (OpenAI/Ollama "function
     * calling" formátum — ugyanezt a JSON-alakot fogadja el az Ollama
     * /api/chat "tools" mezője).
     *
     * @return array<int,array<string,mixed>>
     */
    public function toProviderToolList(): array
    {
        return array_map(static fn (ToolDefinition $t) => [
            'type' => 'function',
            'function' => [
                'name' => $t->name,
                'description' => $t->description,
                'parameters' => $t->inputSchema,
            ],
        ], array_values($this->tools));
    }

    /**
     * Egyetlen eszköz-hívás végrehajtása — SOSE dob kivételt kifelé: egy
     * ismeretlen eszköznév, egy a handler által dobott kivétel, vagy
     * bármilyen egyéb hiba mindig egy ToolResult::fail()-ként tér vissza,
     * hogy az AgentRunner ezt egyszerűen egy újabb "tool" üzenetként
     * továbbadhassa a modellnek (ami esetleg más argumentumokkal
     * újrapróbálkozhat), a teljes agent-futást ne szakítsa meg egyetlen
     * eszköz-hiba.
     */
    public function execute(ToolCall $call): ToolResult
    {
        if (!$this->has($call->name)) {
            return ToolResult::fail($call->id, $call->name, "Ismeretlen eszköz: {$call->name}");
        }
        $tool = $this->tools[$call->name];
        try {
            $data = ($tool->handler)($call->arguments);
            if (!is_array($data)) {
                throw new RuntimeException('Az eszköz handler-je nem tömböt adott vissza.');
            }
            return ToolResult::ok($call->id, $call->name, $data);
        } catch (InvalidArgumentException $e) {
            // Bemenet-validációs hiba — a handler SZÁNDÉKOSAN, kézzel írt,
            // biztonságosan mutatható üzenettel dobta (ugyanaz a minta,
            // mint a RuntimeException-alapú üzleti visszajelzéseknél a
            // többi API-végponton, pl. return-create.php) — ez NEM nyers
            // kivétel-részlet, a modell/felhasználó felé is mehet.
            return ToolResult::fail($call->id, $call->name, $e->getMessage());
        } catch (Throwable $e) {
            // Minden más (pl. váratlan DB-hiba) — lásd az osztály
            // docblokkja: a hívó felé csak ez az általános, biztonságos
            // üzenet jut el, a $e->getMessage() ITT NEM kerül a
            // válaszba/naplóba nyersen (ugyanaz az elv, mint
            // send_generic_error_response()-nál, lásd webroot/api/_bootstrap.php).
            return ToolResult::fail($call->id, $call->name, 'Az eszköz végrehajtása sikertelen.');
        }
    }
}
