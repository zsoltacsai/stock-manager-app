<?php

declare(strict_types=1);

// Minimális, önálló teszt-bootstrap — nincs Composer autoload ebben a
// projektben, úgyhogy a src/ osztályokat közvetlenül requireoljuk.
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/GeoBlocker.php';
require_once __DIR__ . '/../src/SimpleXlsWriter.php';
require_once __DIR__ . '/../src/UrlSafety.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/BackupManager.php';
require_once __DIR__ . '/../src/CsvImporter.php';
require_once __DIR__ . '/../src/ProductRowNormalizer.php';
require_once __DIR__ . '/../src/InvoiceService.php';
require_once __DIR__ . '/../src/NavClient.php';
require_once __DIR__ . '/../src/NavInvoiceXmlBuilder.php';
require_once __DIR__ . '/../src/NavInvoiceProvider.php';
require_once __DIR__ . '/../src/NavInvoiceQueueWorker.php';
require_once __DIR__ . '/../src/NavTokenCache.php';
require_once __DIR__ . '/../src/NavIncomingInvoiceSync.php';
require_once __DIR__ . '/../src/NavIncomingInvoiceSyncWorker.php';
require_once __DIR__ . '/../src/EscPosPrinter.php';
require_once __DIR__ . '/../src/ReceiptPrinter.php';
require_once __DIR__ . '/../src/MailerService.php';
require_once __DIR__ . '/../src/PriceValidator.php';
require_once __DIR__ . '/../src/WooCommerceClient.php';
require_once __DIR__ . '/../src/WcPushQueueWorker.php';
require_once __DIR__ . '/../src/ReportPeriod.php';
require_once __DIR__ . '/../src/HungarianNameDays.php';
require_once __DIR__ . '/../src/PurchaseDecisionService.php';
require_once __DIR__ . '/../src/HealthMonitor.php';
require_once __DIR__ . '/../src/ClientHmac.php';
require_once __DIR__ . '/../src/ClientNonceStore.php';
require_once __DIR__ . '/../src/ClientAuthenticator.php';
require_once __DIR__ . '/../src/ClientSessionBridge.php';
require_once __DIR__ . '/../src/ClientProxy.php';
require_once __DIR__ . '/../src/AppVersion.php';
require_once __DIR__ . '/../src/ClientServerHealth.php';
require_once __DIR__ . '/../src/Ai/AiRetryPolicy.php';
require_once __DIR__ . '/../src/Ai/LocalProvider.php';
require_once __DIR__ . '/../src/Ai/OllamaHealth.php';
require_once __DIR__ . '/../src/Ai/AnthropicProvider.php';
require_once __DIR__ . '/../src/Ai/AnthropicHealth.php';
require_once __DIR__ . '/../src/Ai/OpenAiProvider.php';
require_once __DIR__ . '/../src/Ai/OpenAiHealth.php';
require_once __DIR__ . '/../src/Ai/AiProviderFactory.php';
require_once __DIR__ . '/../src/Ai/AgentRunner.php';
require_once __DIR__ . '/../src/Ai/AiAuditLogger.php';
require_once __DIR__ . '/../src/Ai/Agents/InventoryAgent.php';
require_once __DIR__ . '/../src/Ai/Tools/SalesTools.php';
require_once __DIR__ . '/../src/Ai/Agents/SalesAgent.php';
require_once __DIR__ . '/../src/Ai/AnomalyDetector.php';
require_once __DIR__ . '/../src/Ai/Tools/AnomalyTools.php';
require_once __DIR__ . '/../src/Ai/Agents/AnomalyAgent.php';
require_once __DIR__ . '/../src/Ai/CopilotRunResult.php';
require_once __DIR__ . '/../src/Ai/Agents/AiCopilot.php';
require_once __DIR__ . '/../src/Ai/OllamaProvisioner.php';
require_once __DIR__ . '/../src/Ai/AiDailyIntelligence.php';
require_once __DIR__ . '/../src/Ai/ActionProposal.php';
require_once __DIR__ . '/../src/Ai/ActionProposalService.php';
require_once __DIR__ . '/../src/Ai/ActionExecutor.php';
require_once __DIR__ . '/../src/Ai/AiRateLimiter.php';

/**
 * Minden tesztfüggvény saját, egyszer használatos SQLite fájllal dolgozik
 * (nem az éles data/stock.sqlite-tal!), hogy a tesztek egymástól és az
 * éles adatoktól is teljesen függetlenek legyenek.
 */
function tests_new_database(): Database
{
    $path = sys_get_temp_dir() . '/sm_test_' . bin2hex(random_bytes(8)) . '.sqlite';
    register_shutdown_function(static function () use ($path) {
        @unlink($path);
        @unlink($path . '-shm');
        @unlink($path . '-wal');
    });

    return new Database(
        ['driver' => 'sqlite', 'sqlite' => ['path' => $path]],
        dirname(__DIR__)
    );
}
