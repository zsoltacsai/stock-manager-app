<?php

declare(strict_types=1);

require_once __DIR__ . '/../AiProviderInterface.php';
require_once __DIR__ . '/../ToolRegistry.php';
require_once __DIR__ . '/../AgentRunner.php';
require_once __DIR__ . '/../AgentRunResult.php';
require_once __DIR__ . '/../AiContextLimits.php';
require_once __DIR__ . '/../AiCostLimits.php';
require_once __DIR__ . '/../AiStreamEvent.php';
require_once __DIR__ . '/../Tools/InventoryTools.php';

/**
 * Az első FountainTrade AI-agent — KIZÁRÓLAG olvasás (lásd a kör 7.
 * pontja: nem módosíthat készletet, nem hozhat létre beszerzést, nem
 * változtathat árat, nem indíthat pénzügyi műveletet). A system prompt
 * explicit megköti a modellt, hogy tényleges számokat SOSE találjon ki —
 * minden számadatnak a regisztrált eszközök valamelyikéből kell jönnie.
 */
final class InventoryAgent
{
    private const SYSTEM_INSTRUCTION = <<<'PROMPT'
Te a FountainTrade kasszaprogram Készlet-asszisztense vagy. A boltvezetőnek/dolgozónak segítesz a készletgazdálkodással kapcsolatos kérdésekben, magyar nyelven, tömör, üzleti hangvételű válaszokkal.

SZIGORÚ SZABÁLYOK:
1. Kizárólag a rendelkezésedre álló eszközöket (tools) használhatod adat lekérdezésére — SOSE találj ki, becsülj vagy emlékezetből mondj konkrét számadatot (készletmennyiség, eladási sebesség, dátum, forgalom). Minden számnak egy eszköz-hívás eredményéből kell származnia.
2. Ha egy kérdés megválaszolásához hiányzik egy adat, vagy egy eszköz-hívás nem adott eredményt, mondd ki EGYÉRTELMŰEN, hogy az adott információ nem áll rendelkezésre — ne töltsd ki a hiányt találgatással.
3. Csak OLVASÁSRA vagy képes: nem módosíthatsz készletet, nem hozhatsz létre beszerzést vagy rendelést, nem változtathatsz árat, és nem indíthatsz semmilyen pénzügyi műveletet. Ha a felhasználó ilyet kérne, mondd el, hogy ehhez a megfelelő FountainTrade felületet (pl. Beszerzés, Árucikkek) kell használnia — te csak elemzést/javaslatot tudsz adni.
4. Bármilyen ajánlásod (pl. "érdemes lenne rendelni") EGYÉRTELMŰEN javaslatként fogalmazd meg, ne kész, végrehajtott döntésként.
5. Ha több eszközt is érdemes kombinálni (pl. alacsony készlet + eladási sebesség + készletmozgás) egy megalapozottabb válaszhoz, tedd meg — de mindig jelezd, mire alapozod a következtetésedet.
6. Légy tömör — pár mondatos, lényegre törő üzleti válasz, nem esszé.
PROMPT;

    public function __construct(
        private readonly AiProviderInterface $provider,
        private readonly Database $db,
        private readonly array $appSettings,
        private readonly int $maxIterations = 5,
    ) {
    }

    public function answer(string $question): AgentRunResult
    {
        return $this->buildRunner()->run(self::SYSTEM_INSTRUCTION, $question);
    }

    /**
     * Fázis 9 — a kör 6. pontja: streamelt válasz UGYANAZZAL az üzleti
     * logikával (regisztrált eszközök, system prompt), mint answer() —
     * KIZÁRÓLAG a végrehajtó (run() → runStreaming()) tér el.
     *
     * @param callable(AiStreamEvent):void $onEvent
     */
    public function answerStreaming(string $question, callable $onEvent): AgentRunResult
    {
        return $this->buildRunner()->runStreaming(self::SYSTEM_INSTRUCTION, $question, $onEvent, self::name());
    }

    private function buildRunner(): AgentRunner
    {
        $registry = new ToolRegistry();
        InventoryTools::registerAll($registry, $this->db, $this->appSettings);

        return new AgentRunner(
            $this->provider,
            $registry,
            $this->maxIterations,
            AiContextLimits::fromSettings($this->appSettings),
            AiCostLimits::fromSettings($this->appSettings),
            (bool) ($this->appSettings['ai_streaming_enabled'] ?? true)
        );
    }

    public static function name(): string
    {
        return 'inventory';
    }
}
