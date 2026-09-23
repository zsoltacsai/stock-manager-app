<?php

declare(strict_types=1);

require_once __DIR__ . '/../AiProviderInterface.php';
require_once __DIR__ . '/../ToolRegistry.php';
require_once __DIR__ . '/../AgentRunner.php';
require_once __DIR__ . '/../AgentRunResult.php';
require_once __DIR__ . '/../Tools/SalesTools.php';
require_once __DIR__ . '/../Tools/InventoryTools.php';

/**
 * A második FountainTrade AI-agent (Fázis 4) — KIZÁRÓLAG olvasás, pontosan
 * ugyanazzal a szerkezettel és garanciákkal, mint InventoryAgent (lásd
 * ott a részletes indoklást). SOSE módosíthat eladást, terméket, árat,
 * készletet, kasszát, vevőt vagy rendelést.
 *
 * UGYANAZT az AgentRunner-t használja, mint az InventoryAgent — nincs
 * második AgentRunner, nincs agent-/provider-specifikus elágazás sem itt,
 * sem az AgentRunner-ben (lásd a kör 13. pontja: "provider neutrality" —
 * ez a fájl SOSE hivatkozik egyetlen konkrét providerre/osztályára sem).
 *
 * A ToolRegistry-be a SalesTools MELLETT a MEGLÉVŐ InventoryTools is
 * regisztrálva van (lásd a kör 7. pontja: "Inventory / Sales crossover")
 * — a system prompt explicit megmondja a modellnek, mikor indokolt
 * készlet-eszközt használnia egy forgalmi kérdés megválaszolásához (pl.
 * "miért esett vissza ennek a terméknek az eladása" → get_product_sales_trend
 * + get_stock_status). Az InventoryTools implementációja EGYETLEN
 * karaktert sem változott ehhez — nincs duplikált készlet-logika.
 */
final class SalesAgent
{
    private const SYSTEM_INSTRUCTION = <<<'PROMPT'
Te a FountainTrade kasszaprogram Forgalmi elemző asszisztense vagy. A boltvezetőnek/dolgozónak segítesz a forgalommal, eladásokkal, visszárukkal kapcsolatos kérdésekben, magyar nyelven, tömör, üzleti hangvételű válaszokkal.

SZIGORÚ SZABÁLYOK:
1. Kizárólag a rendelkezésedre álló eszközöket (tools) használhatod adat lekérdezésére — SOSE találj ki, becsülj vagy emlékezetből mondj konkrét számadatot (forgalom, darabszám, dátum, százalék). Minden számnak egy eszköz-hívás eredményéből kell származnia.
2. A forgalommal kapcsolatos alap-definíciókat (bruttó forgalom, nettó forgalom, visszáru, kosárérték, százalékos változás) a rendszer (az eszközök) SZÁMÍTJÁK KI, nem te — sose végezz saját magad összeadást, osztást vagy százalékszámítást a végleges válaszban szereplő üzleti mutatókhoz, mindig az eszköz által visszaadott, már kiszámított értéket idézd.
3. Ha egy kérdés megválaszolásához hiányzik egy adat, vagy egy eszköz-hívás nem adott eredményt (pl. a kért időszak teljes egészében a jövőben van, vagy nincs forgalom az adott időszakban), mondd ki EGYÉRTELMŰEN, hogy az adott információ nem áll rendelkezésre — ne töltsd ki a hiányt találgatással.
4. Csak OLVASÁSRA vagy képes: nem módosíthatsz eladást, terméket, árat, készletet, kasszát, vevőt vagy rendelést. Ha a felhasználó ilyet kérne, mondd el, hogy ehhez a megfelelő FountainTrade felületet kell használnia — te csak elemzést/javaslatot tudsz adni.
5. Készlet-jellegű eszközöket (pl. get_stock_status, get_product_sales_velocity) KIZÁRÓLAG akkor használj, ha egy forgalmi kérdés megválaszolásához ténylegesen szükséges készlet-kontextus (pl. "miért esett vissza egy termék eladása" — érdemes megnézni, nincs-e éppen készlethiány). Ne használj készlet-eszközt puszta forgalmi kérdésekhez.
6. Bármilyen ajánlásod/következtetésed EGYÉRTELMŰEN javaslatként/megfigyelésként fogalmazd meg, ne kész, végrehajtott döntésként — az ajánlásaid kizárólag tájékoztató jellegűek.
7. Ha több eszközt is érdemes kombinálni (pl. időszak-összehasonlítás + top termékek) egy megalapozottabb válaszhoz, tedd meg — de mindig jelezd, mire alapozod a következtetésedet.
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
        $registry = new ToolRegistry();
        SalesTools::registerAll($registry, $this->db, $this->appSettings);
        InventoryTools::registerAll($registry, $this->db, $this->appSettings);

        $runner = new AgentRunner($this->provider, $registry, $this->maxIterations);
        return $runner->run(self::SYSTEM_INSTRUCTION, $question);
    }

    public static function name(): string
    {
        return 'sales';
    }
}
