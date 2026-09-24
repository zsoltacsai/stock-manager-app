<?php

declare(strict_types=1);

require_once __DIR__ . '/ToolCall.php';
require_once __DIR__ . '/ToolResult.php';
require_once __DIR__ . '/AiContextLimits.php';

/**
 * Könnyű, KIZÁRÓLAG egyetlen agent-futás időtartamára élő üzenet-lista —
 * SZÁNDÉKOSAN nincs tartós/adatbázisba mentett beszélgetés-előzmény ebben
 * a körben (lásd a kör 5. pontja — ez később, külön kör tárgya lehet,
 * anélkül, hogy ez az osztály megváltozna: egy jövőbeli perzisztencia-
 * réteg egyszerűen elmenthetné a toArray() kimenetét).
 *
 * FÁZIS 9 — a kör 10/11/12. pontja: determinisztikus kontextus-
 * korlátozás/tömörítés, EGY helyen — ez az EGYETLEN belépési pont, ahol
 * bármilyen tartalom (felhasználói kérdés, eszköz-eredmény, korábbi
 * kör) a beszélgetésbe kerül, tehát ez a TERMÉSZETES, egységes hely a
 * korlátozáshoz (nem szükséges minden egyes Tools-osztályban/Providerben
 * külön-külön megismételni).
 */
final class ConversationManager
{
    /** @var array<int,array{role:string,content:?string,tool_calls?:array,tool_call_id?:string,name?:string}> */
    private array $messages = [];

    private readonly AiContextLimits $limits;

    /** A kör 11. pontja — "record the limit event"; a hívó (AgentRunner) ebből tudja, történt-e tömörítés. */
    private bool $wasCompacted = false;

    public function __construct(string $systemInstruction, string $userMessage, ?AiContextLimits $limits = null)
    {
        $this->limits = $limits ?? new AiContextLimits();
        $this->messages[] = ['role' => 'system', 'content' => $systemInstruction];
        // A kör 10. pontja — "maximum user input size": a felhasználói
        // kérdés SOSE vágódik le csendben itt (az a végpont felelőssége,
        // hogy ELŐBB, egyértelmű hibaüzenettel utasítsa el a túl hosszú
        // bemenetet — lásd webroot/api/ai-copilot.php) — ez a bounded()
        // hívás egy VÉDELMI MÉLYSÉG-réteg, ha valamiért mégis idáig jutna.
        $this->messages[] = ['role' => 'user', 'content' => self::bounded($userMessage, $this->limits->maxUserInputChars)];
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
        $this->enforceLimits();
    }

    public function addToolResult(ToolResult $result): void
    {
        $payload = $result->toProviderPayload();
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

        // A kör 12. pontja — "ensure bounded total serialized size... Do
        // NOT truncate JSON in a way that produces invalid JSON... reduce
        // item count, reduce field content deterministically, preserve
        // valid structured format." Lásd boundToolPayload() docblokkja a
        // pontos algoritmusért.
        if (mb_strlen($json) > $this->limits->maxToolResultChars) {
            $payload = self::boundToolPayload($payload, $this->limits->maxToolResultChars);
            $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        $this->messages[] = [
            'role' => 'tool',
            'tool_call_id' => $result->toolCallId,
            'name' => $result->name,
            'content' => $json,
        ];
        $this->enforceLimits();
    }

    public function wasCompacted(): bool
    {
        return $this->wasCompacted;
    }

    /** @return array<int,array{role:string,content:?string,tool_calls?:array,tool_call_id?:string,name?:string}> */
    public function toArray(): array
    {
        return $this->messages;
    }

    private static function bounded(string $text, int $maxChars): string
    {
        return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars) . '…' : $text;
    }

    /**
     * Determinisztikus, MINDIG érvényes JSON-t eredményező eszköz-
     * eredmény-korlátozás — a kör 12. pontja két lépcsőben:
     *
     * 1) STRUKTÚRA-TUDATOS: ha a payload `data` mezője tartalmaz egy (a
     *    LEGNAGYOBB, listaszerű) tömb-értéket — ez a jellemző alak a
     *    MEGLÉVŐ InventoryTools/SalesTools/AnomalyTools eszközöknél
     *    (pl. `products`/`anomalies`/`entries` listák) —, azt a tömböt
     *    a VÉGÉTŐL csökkentjük (elemszám felezése), amíg a JSON a
     *    korlát alá nem kerül, VAGY 1 elemre nem apad. Ez pontosan a
     *    kör saját javaslata: "reduce item count... deterministically."
     * 2) ÁLTALÁNOS TARTALÉK: ha az 1) lépés után is túl nagy (pl. egyetlen,
     *    eleve hatalmas mező, vagy nincs egyértelmű lista), a teljes
     *    `data` mezőt egy KIS, MINDIG érvényes, őszinte "truncated"
     *    boríték váltja fel — SOSE vágjuk a MÁR kódolt JSON-stringet
     *    nyersen (ami érvénytelen JSON-t eredményezne).
     *
     * @param array<string,mixed> $payload ToolResult::toProviderPayload() alakja
     * @return array<string,mixed>
     */
    private static function boundToolPayload(array $payload, int $maxChars): array
    {
        if (!($payload['success'] ?? false) || !is_array($payload['data'] ?? null)) {
            // Hiba-payload, vagy nincs 'data' tömb — nincs mit
            // strukturáltan csökkenteni, egyenesen a tartalék ágra.
            return self::truncatedEnvelope($payload, $maxChars);
        }

        $data = $payload['data'];
        $listKey = null;
        $listLength = 0;
        foreach ($data as $key => $value) {
            if (is_array($value) && array_is_list($value) && count($value) > $listLength) {
                $listKey = $key;
                $listLength = count($value);
            }
        }

        if ($listKey !== null && $listLength > 1) {
            $candidate = $payload;
            $items = $data[$listKey];
            $originalCount = count($items);
            while (count($items) > 1) {
                $items = array_slice($items, 0, (int) ceil(count($items) / 2));
                $candidate['data'][$listKey] = $items;
                $candidate['data']['_truncated'] = true;
                $candidate['data']['_truncated_field'] = $listKey;
                $candidate['data']['_original_count'] = $originalCount;
                if (mb_strlen((string) json_encode($candidate, JSON_UNESCAPED_UNICODE)) <= $maxChars) {
                    return $candidate;
                }
            }
            $payload = $candidate;
        }

        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (mb_strlen($json) <= $maxChars) {
            return $payload;
        }

        return self::truncatedEnvelope($payload, $maxChars);
    }

    /**
     * A VÉGSŐ, MINDIG érvényes JSON-t adó tartalék — őszintén jelzi,
     * hogy tartalom lett elhagyva (SOSE hazudik hamis, hiányos adatot
     * teljesnek), a MÉRETÉT (nem a tartalmát) is megadva, hogy a modell
     * legalább tudja, mennyi maradt ki.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function truncatedEnvelope(array $payload, int $maxChars): array
    {
        $originalSize = mb_strlen((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return [
            'success' => $payload['success'] ?? false,
            'truncated' => true,
            'original_size_chars' => $originalSize,
            'note' => 'Az eszköz eredménye túl nagy volt a kontextus-korláthoz — a teljes adat nem fért el. Kérj szűkebb/pontosabb lekérdezést.',
        ];
    }

    /**
     * A kör 11. pontja — determinisztikus tömörítés, ha a beszélgetés az
     * üzenetszám VAGY a teljes karakter-méret korlátját túllépné.
     * "Kör"-önként (egy assistant-üzenet + az UTÁNA következő tool-
     * eredmények) dobjuk el a LEGRÉGEBBI, NEM védett köröket — SOSE a
     * system/user üzenetet (index 0/1), és SOSE a LEGUTÓBBI kört (a kör
     * 11. pontja: "preserve tool evidence needed for the current task...
     * do not silently discard critical current-turn evidence").
     */
    private function enforceLimits(): void
    {
        $exceedsCount = count($this->messages) > $this->limits->maxMessages;
        $exceedsChars = $this->totalChars() > $this->limits->maxTotalContextChars;
        if (!$exceedsCount && !$exceedsChars) {
            return;
        }

        $turnBoundaries = $this->turnStartIndexes();
        // Az UTOLSÓ kört SOSE dobjuk el — legalább 1 kört meg kell
        // hagyni a system/user üzeneteken felül (a kör 11. pontja: "do
        // not silently discard critical current-turn evidence"). Ha
        // csak 1 (vagy 0) kör van, nincs mit tömöríteni — a metódus
        // biztonságosan, VÉGTELEN CIKLUS NÉLKÜL leáll (a kör 27. pontja:
        // "no infinite compaction loop").
        while (count($turnBoundaries) > 1 && (count($this->messages) > $this->limits->maxMessages || $this->totalChars() > $this->limits->maxTotalContextChars)) {
            $oldestTurnStart = array_shift($turnBoundaries);
            $nextTurnStart = $turnBoundaries[0] ?? count($this->messages);
            $removeCount = $nextTurnStart - $oldestTurnStart;
            array_splice($this->messages, $oldestTurnStart, $removeCount);
            $this->wasCompacted = true;
            // Az indexek a splice UTÁN eltolódtak — a MARADÉK
            // turnBoundaries értékeit is csökkentjük ugyanennyivel.
            foreach ($turnBoundaries as $i => $idx) {
                $turnBoundaries[$i] = $idx - $removeCount;
            }
        }
    }

    private function totalChars(): int
    {
        $total = 0;
        foreach ($this->messages as $m) {
            $total += mb_strlen((string) ($m['content'] ?? ''));
            if (!empty($m['tool_calls'])) {
                $total += mb_strlen((string) json_encode($m['tool_calls'], JSON_UNESCAPED_UNICODE));
            }
        }
        return $total;
    }

    /**
     * Minden 'assistant' szerepű üzenet index-e (index 2-től, a system/
     * user üzenetek UTÁN) — ez jelöli egy "kör" kezdetét (az assistant-
     * üzenet + az utána, a KÖVETKEZŐ assistant-üzenetig tartó tool-
     * eredmények tartoznak egy körbe).
     *
     * @return int[]
     */
    private function turnStartIndexes(): array
    {
        $indexes = [];
        foreach ($this->messages as $i => $m) {
            if ($i >= 2 && ($m['role'] ?? '') === 'assistant') {
                $indexes[] = $i;
            }
        }
        return $indexes;
    }
}
