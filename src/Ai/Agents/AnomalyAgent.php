<?php

declare(strict_types=1);

require_once __DIR__ . '/../AiProviderInterface.php';
require_once __DIR__ . '/../ToolRegistry.php';
require_once __DIR__ . '/../AgentRunner.php';
require_once __DIR__ . '/../AgentRunResult.php';
require_once __DIR__ . '/../AiContextLimits.php';
require_once __DIR__ . '/../AiCostLimits.php';
require_once __DIR__ . '/../AiStreamEvent.php';
require_once __DIR__ . '/../Tools/AnomalyTools.php';
require_once __DIR__ . '/../Tools/SalesTools.php';
require_once __DIR__ . '/../Tools/InventoryTools.php';

/**
 * A harmadik FountainTrade AI-agent (Fázis 5) — KIZÁRÓLAG olvasás,
 * pontosan ugyanazzal a szerkezettel és garanciákkal, mint
 * InventoryAgent/SalesAgent (lásd ott a részletes indoklást). SOSE
 * módosíthat készletet, árat, eladást, kasszát, vevőt vagy rendelést, és
 * SOSE indíthat pénzügyi műveletet.
 *
 * UGYANAZT az AgentRunner-t használja, mint a másik két agent — nincs
 * harmadik AgentRunner, nincs agent-/provider-specifikus elágazás sem
 * itt, sem az AgentRunner-ben (lásd a kör 15. pontja: "provider
 * neutrality" — ez a fájl SOSE hivatkozik egyetlen konkrét
 * providerre/osztályára sem).
 *
 * A ToolRegistry-be az AnomalyTools MELLETT a MEGLÉVŐ SalesTools ÉS
 * InventoryTools is regisztrálva van (lásd a kör 8. pontja: "cross-agent
 * tool usage") — a system prompt explicit megmondja a modellnek, mikor
 * indokolt egy talált anomália mögé mélyebbre menni egy meglévő
 * eszközzel (pl. get_stock_status/get_product_sales_trend). Sem a
 * SalesTools, sem az InventoryTools implementációja NEM változott ehhez
 * — nincs duplikált üzleti logika.
 *
 * A LEGFONTOSABB, a system promptban explicit kikényszerített szabály
 * (lásd a kör 9/11. pontja): a modell a talált anomáliákat KIZÁRÓLAG
 * MAGYARÁZZA — az "anomália-e ez", a súlyosság, és minden %-os
 * számítás a backend (AnomalyDetector) determinisztikus döntése, SOSE a
 * modellé. A modell KORRELÁCIÓT jelezhet ("X és Y együtt erre utalhat"),
 * de OKOZATI állítást ("X-et Y okozza") csak akkor tehet, ha a backend
 * ténylegesen okozati bizonyítékot ad — jelenleg NEM ad, ezért a system
 * prompt ezt a jelen fázisban gyakorlatilag mindig tiltja.
 */
final class AnomalyAgent
{
    private const SYSTEM_INSTRUCTION = <<<'PROMPT'
Te a FountainTrade kasszaprogram Anomália-elemző asszisztense vagy. A boltvezetőnek/dolgozónak segítesz szokatlan forgalmi/készlet-mintázatok azonosításában, magyarázatában és fontossági sorrendbe állításában — magyar nyelven, tömör, üzleti hangvételű válaszokkal.

SZIGORÚ SZABÁLYOK:
1. Kizárólag a rendelkezésedre álló eszközöket (tools) használhatod — SOSE találj ki, becsülj vagy emlékezetből mondj konkrét számadatot, és SOSE "fedezz fel" olyan anomáliát, amit egy eszköz nem jelzett vissza. Az anomáliák listáját, a súlyosságukat és minden %-os/abszolút számítást a rendszer (a backend) állapítja meg — te ezeket KIZÁRÓLAG MAGYARÁZOD, sose számolod újra vagy módosítod.
2. Egy eszköz eredménye háromféle lehet egy adott mutatóra: "anomália" (van eltérés), "normál" (nincs), vagy "elégtelen adat" (nem volt elég megfigyelés egy megbízható döntéshez). A HÁROM ÁLLAPOTOT SOSE keverd össze — "elégtelen adat" SOSE jelenti azt, hogy "minden rendben van", ezt mindig külön, egyértelműen közöld.
3. Amikor egy anomáliát magyarázol, mindig hivatkozz a konkrét bizonyítékra (evidence/reason_code mezők, aktuális/alap érték, %-os változás) — ne általánosíts üres frázisokkal.
4. KORRELÁCIÓ VS. OKOZATISÁG — ez KRITIKUS: ha egyszerre látsz pl. csökkenő eladást ÉS magas készletet ugyanarra a termékre, azt mondhatod, hogy ez EGYÜTT lassuló készletforgásra UTALHAT ("ez arra utalhat, hogy..."), de SOSE állíthatod, hogy az egyik ok a másiknak ("a készlet növekedését X okozza") — a rendszer NEM ad okozati bizonyítékot, csak együttjárást. Fogalmazz óvatosan: "lehetséges magyarázat", "érdemes megvizsgálni", SOSE "ez azért van, mert...".
5. Csak OLVASÁSRA vagy képes: nem módosíthatsz készletet, árat, eladást, kasszát, vevőt vagy rendelést. Ha a felhasználó ilyet kérne, mondd el, hogy ehhez a megfelelő FountainTrade felületet kell használnia — te csak elemzést/javaslatot tudsz adni.
6. Készlet- vagy forgalmi-elemző eszközöket (get_stock_status, get_product_sales_trend, stb.) KIZÁRÓLAG akkor használj kiegészítésül, ha egy MÁR talált anomália mélyebb megértéséhez ténylegesen szükséges — ne kérdezz le felesleges adatot.
7. Ha a felhasználó "mi a legnagyobb probléma jelenleg?" jellegű kérdést tesz fel, a MÁR súlyosság szerint rendezett, backend által visszaadott anomália-listát foglald össze — SOSE alkoss saját, a backend adataitól független fontossági sorrendet.
8. Légy tömör — pár mondatos, lényegre törő üzleti válasz, nem esszé.
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
     * Fázis 9 — lásd InventoryAgent::answerStreaming() azonos docblokkja.
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
        AnomalyTools::registerAll($registry, $this->db, $this->appSettings);
        SalesTools::registerAll($registry, $this->db, $this->appSettings);
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
        return 'anomaly';
    }
}
