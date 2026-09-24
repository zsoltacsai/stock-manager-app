<?php

declare(strict_types=1);

/**
 * Fázis 10 — a kör 6. pontja: KICSI, ISMÉTELHETŐ benchmark-eszköz valódi
 * (vagy stub-) AI-providerekhez futtatott, EGYENÉRTÉKŰ munkaterhelésekhez
 * — a projektben korábban nem létezett ilyen. KIZÁRÓLAG egy IZOLÁLT,
 * ideiglenes SQLite-adatbázison fut (SOSE az éles `data/stock.sqlite`-on
 * — lásd a kör 4. pontja "Do NOT use production business data").
 *
 * Használat:
 *   php tools/ai-benchmark.php --provider=local [--runs=1] [--out=path.json]
 *     [--base-url=http://127.0.0.1:11434] [--model=qwen3:8b] [--timeout=180]
 *
 * A --provider=anthropic/--provider=openai KIZÁRÓLAG akkor fut ténylegesen
 * (valódi hálózati hívással), ha a megfelelő --api-key paramétert (vagy
 * ANTHROPIC_API_KEY/OPENAI_API_KEY környezeti változót) MEGADJÁK — enélkül
 * a script egyértelműen jelzi, hogy az adott provider nem elérhető, és
 * KIHAGYJA (SOSE hamisít eredményt, lásd a kör 3. pontja).
 *
 * A jelentés SOSE tartalmaz nyers modell-választ/API-kulcsot — csak a
 * MÉRT metaadatokat (időzítés, eszköz-hívások, token-használat, becsült
 * költség, siker/hiba).
 */

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Ai/AiProviderFactory.php';
require_once __DIR__ . '/../src/Ai/AiPricing.php';
require_once __DIR__ . '/../src/Ai/Agents/InventoryAgent.php';
require_once __DIR__ . '/../src/Ai/Agents/SalesAgent.php';
require_once __DIR__ . '/../src/Ai/Agents/AnomalyAgent.php';
require_once __DIR__ . '/../src/Ai/Agents/AiCopilot.php';

function bench_parse_argv(array $argv): array
{
    $out = [];
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            [$k, $v] = explode('=', substr($arg, 2), 2);
            $out[$k] = $v;
        }
    }
    return $out;
}

function bench_seed_database(string $path): Database
{
    if (file_exists($path)) {
        unlink($path);
    }
    $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
    $pdo = $db->pdo();

    // Kis, IZOLÁLT, önmagában konzisztens teszt-adathalmaz — SOSE éles adat.
    $pdo->exec("INSERT INTO products (name, unit, price, net_price, stock_qty, low_stock_threshold, group_name, vat_rate) VALUES
        ('Benchmark Csavarhúzó', 'db', 2500, 1969, 2, 5, 'Szerszám', '27'),
        ('Benchmark Kalapács', 'db', 4500, 3543, 1, 5, 'Szerszám', '27'),
        ('Benchmark Festék 5L', 'db', 8900, 7008, 20, 5, 'Festék', '27')");

    $today = date('Y-m-d');
    for ($i = 0; $i < 5; $i++) {
        $stmt = $pdo->prepare("INSERT INTO sales (created_at, total, payment_method) VALUES (?, ?, 'Készpénz')");
        $stmt->execute(["$today " . sprintf('%02d:00:00', 9 + $i), 2500 + $i * 100]);
        $saleId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate) VALUES (?, 1, 'Benchmark Csavarhúzó', 1, 2500, '27')")->execute([$saleId]);
    }

    return $db;
}

function bench_settings(array $args): array
{
    return [
        'ai_enabled' => true,
        'ai_provider' => $args['provider'] ?? 'local',
        'ai_local_base_url' => $args['base-url'] ?? 'http://127.0.0.1:11434',
        'ai_local_model' => $args['model'] ?? 'qwen3:8b',
        'ai_timeout_seconds' => (int) ($args['timeout'] ?? 180),
        'ai_max_iterations' => 5,
        'ai_max_output_tokens' => null,
        'anthropic_api_key' => $args['api-key'] ?? getenv('ANTHROPIC_API_KEY') ?: '',
        'anthropic_model' => $args['model'] ?? 'claude-sonnet-5',
        'anthropic_base_url' => 'https://api.anthropic.com',
        'anthropic_timeout_seconds' => (int) ($args['timeout'] ?? 60),
        'openai_api_key' => $args['api-key'] ?? getenv('OPENAI_API_KEY') ?: '',
        'openai_model' => $args['model'] ?? 'gpt-6-sol',
        'openai_base_url' => 'https://api.openai.com',
        'openai_timeout_seconds' => (int) ($args['timeout'] ?? 60),
        'ai_max_context_messages' => 60,
        'ai_max_input_chars' => 4000,
        'ai_max_tool_result_chars' => 4000,
        'ai_max_total_context_chars' => 60000,
        'ai_max_tool_calls' => 20,
        'ai_max_estimated_cost_per_request' => null,
        'ai_streaming_enabled' => true,
    ];
}

/** @return array{available:bool,reason:?string} */
function bench_check_provider_available(string $provider, array $settings): array
{
    return match ($provider) {
        'local' => (function () use ($settings) {
            $fp = @fsockopen(parse_url($settings['ai_local_base_url'], PHP_URL_HOST), (int) (parse_url($settings['ai_local_base_url'], PHP_URL_PORT) ?: 80), $errno, $errstr, 2);
            if ($fp) {
                fclose($fp);
                return ['available' => true, 'reason' => null];
            }
            return ['available' => false, 'reason' => "Ollama nem érhető el ($settings[ai_local_base_url]): $errstr"];
        })(),
        'anthropic' => $settings['anthropic_api_key'] !== ''
            ? ['available' => true, 'reason' => null]
            : ['available' => false, 'reason' => 'Nincs megadva Anthropic API-kulcs (--api-key vagy ANTHROPIC_API_KEY).'],
        'openai' => $settings['openai_api_key'] !== ''
            ? ['available' => true, 'reason' => null]
            : ['available' => false, 'reason' => 'Nincs megadva OpenAI API-kulcs (--api-key vagy OPENAI_API_KEY).'],
        default => ['available' => false, 'reason' => "Ismeretlen provider: $provider"],
    };
}

/** @return array<string,mixed> egy futás mért eredménye */
function bench_run_workload(string $label, callable $invoke): array
{
    fwrite(STDERR, "[bench] futtatás: $label ... ");
    $startedAt = microtime(true);
    $firstEventAt = null;
    $onEvent = function ($event) use (&$firstEventAt) {
        if ($firstEventAt === null) {
            $firstEventAt = microtime(true);
        }
    };
    try {
        $result = $invoke($onEvent);
        $durationMs = (microtime(true) - $startedAt) * 1000;
        $ttfeMs = $firstEventAt !== null ? ($firstEventAt - $startedAt) * 1000 : null;
        fwrite(STDERR, sprintf("kész (%s, %.0f ms)\n", $result->success ? 'siker' : 'hiba', $durationMs));
        return [
            'label' => $label,
            'success' => $result->success,
            'duration_ms' => round($durationMs, 1),
            'time_to_first_event_ms' => $ttfeMs !== null ? round($ttfeMs, 1) : null,
            'iterations' => $result->iterations,
            'tools_used' => $result->toolsUsed,
            'agents_used' => $result instanceof CopilotRunResult ? $result->agentsUsed : null,
            'streamed' => $result->streamed,
            'usage' => $result->usage?->toArray(),
            'failure_category' => $result->failureCategory,
            'error' => $result->success ? null : $result->error,
        ];
    } catch (Throwable $e) {
        $durationMs = (microtime(true) - $startedAt) * 1000;
        fwrite(STDERR, "KIVÉTEL ({$e->getMessage()})\n");
        return [
            'label' => $label,
            'success' => false,
            'duration_ms' => round($durationMs, 1),
            'time_to_first_event_ms' => null,
            'iterations' => null,
            'tools_used' => [],
            'agents_used' => null,
            'streamed' => false,
            'usage' => null,
            'failure_category' => 'unknown',
            'error' => $e->getMessage(),
        ];
    }
}

// -----------------------------------------------------------------------

$args = bench_parse_argv(array_slice($argv, 1));
$provider = $args['provider'] ?? 'local';
$runs = max(1, (int) ($args['runs'] ?? 1));
$settings = bench_settings($args);

$availability = bench_check_provider_available($provider, $settings);
if (!$availability['available']) {
    fwrite(STDOUT, json_encode([
        'provider' => $provider,
        'available' => false,
        'reason' => $availability['reason'],
        'workloads' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(0);
}

$dbPath = sys_get_temp_dir() . '/ft_ai_benchmark_' . bin2hex(random_bytes(6)) . '.sqlite';
$db = bench_seed_database($dbPath);

$providerInstance = AiProviderFactory::create($settings, $db);
$model = match ($providerInstance->name()) {
    'anthropic' => $settings['anthropic_model'],
    'openai' => $settings['openai_model'],
    default => $settings['ai_local_model'],
};

$workloads = [
    'inventory_simple' => fn ($onEvent) => (new InventoryAgent($providerInstance, $db, $settings, 5))->answerStreaming('Melyik termékek vannak alacsony készleten?', $onEvent),
    'inventory_tool_call' => fn ($onEvent) => (new InventoryAgent($providerInstance, $db, $settings, 5))->answerStreaming('Melyik termékek fogyhatnak ki hamarosan?', $onEvent),
    'sales_simple' => fn ($onEvent) => (new SalesAgent($providerInstance, $db, $settings, 5))->answerStreaming('Mennyi volt a forgalom az elmúlt 30 napban?', $onEvent),
    'sales_tool_call' => fn ($onEvent) => (new SalesAgent($providerInstance, $db, $settings, 5))->answerStreaming('Mutasd a legjobban fogyó termékeket az elmúlt 30 napban.', $onEvent),
    'anomaly_check' => fn ($onEvent) => (new AnomalyAgent($providerInstance, $db, $settings, 5))->answerStreaming('Van jelenleg jelentős készlet- vagy értékesítési anomália?', $onEvent),
    'copilot_single_domain' => fn ($onEvent) => (new AiCopilot($providerInstance, $db, $settings, 5))->answerStreaming('Mennyi volt a forgalom ezen a héten?', $onEvent),
    'copilot_cross_domain' => fn ($onEvent) => (new AiCopilot($providerInstance, $db, $settings, 5))->answerStreaming('Van olyan termék, amelyből nő a készlet, miközben csökken az értékesítés?', $onEvent),
];

$report = [
    'provider' => $providerInstance->name(),
    'model' => $model,
    'available' => true,
    'date' => date('c'),
    'runs_per_workload' => $runs,
    'workloads' => [],
];

foreach ($workloads as $label => $invoke) {
    $samples = [];
    for ($i = 1; $i <= $runs; $i++) {
        $samples[] = bench_run_workload("$label (#$i/$runs)", $invoke);
    }
    $report['workloads'][$label] = $samples;
}

@unlink($dbPath);

$json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$outPath = $args['out'] ?? null;
if ($outPath !== null) {
    file_put_contents($outPath, $json);
    fwrite(STDERR, "[bench] jelentés mentve: $outPath\n");
}
fwrite(STDOUT, $json . "\n");
