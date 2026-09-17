<?php

/**
 * Vékony becsomagolása az `update_state`/`update_history` olvasásának és
 * írásának (lásd Database::migrateV21Updates()). Az állapotgép ÁTMENETEINEK
 * (melyik állapotból melyikbe szabad lépni) döntési logikája az
 * UpdateInstaller-ben él — ez az osztály csak a perzisztencia-műveleteket
 * és a 17. pont szerinti kötelező naplózást ("Minden állapotváltást
 * logolj") kínálja egy stabil, jól nevesített felületen.
 */
final class UpdateState
{
    /** A 17. pontban felsorolt teljes állapotgép. */
    public const STATES = [
        'idle', 'checking', 'update_available', 'downloading', 'verifying', 'backing_up',
        'staging', 'maintenance', 'installing', 'migrating', 'health_check', 'completed',
        'failed', 'rolling_back', 'rolled_back', 'manual_recovery_required',
    ];

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function current(): array
    {
        return $this->db->getUpdateState();
    }

    public function update(array $fields): void
    {
        $this->db->updateUpdateState($fields);
    }

    /**
     * 1.4.0 — állapotonkénti severity/status/felhasználói üzenet a
     * központi rendszeresemény-naplóhoz (lásd a kör 3. pontja, "Updater"
     * eseménytípusok). Az UpdateInstaller.php-ban átadott `progress_message`
     * (ha van) MÁR eddig is kézzel írt, biztonságosan felhasználó elé
     * tárható szöveg volt (nem nyers kivétel-dump) — ez itt csak
     * ÚJRAHASZNOSÍTJA, nem generál új technikai részletet.
     */
    private const STATE_EVENT_META = [
        'checking'                 => ['info', 'started', 'Frissítés-ellenőrzés folyamatban.'],
        'update_available'         => ['info', 'success', 'Új verzió érhető el.'],
        'downloading'              => ['info', 'started', 'Frissítés letöltése folyamatban.'],
        'verifying'                => ['info', 'started', 'A letöltött csomag ellenőrzése.'],
        'backing_up'               => ['info', 'started', 'Biztonsági mentés készítése a frissítés előtt.'],
        'staging'                  => ['info', 'started', 'Frissítés előkészítése.'],
        'maintenance'              => ['info', 'started', 'Karbantartási mód bekapcsolva.'],
        'installing'               => ['info', 'started', 'Telepítés folyamatban.'],
        'migrating'                => ['info', 'started', 'Adatbázis-migráció folyamatban.'],
        'health_check'             => ['info', 'started', 'Egészség-ellenőrzés a telepítés után.'],
        'completed'                => ['info', 'success', 'A frissítés sikeresen befejeződött.'],
        'failed'                   => ['error', 'failure', 'A frissítés sikertelen volt.'],
        'rolling_back'             => ['warning', 'started', 'Visszaállítás folyamatban a frissítés hibája miatt.'],
        'rolled_back'              => ['warning', 'success', 'Sikertelen frissítés — visszaállítva az előző verzióra.'],
        'manual_recovery_required' => ['error', 'failure', 'A visszaállítás sikertelen — kézi beavatkozás szükséges.'],
    ];

    public function transitionTo(string $state, array $extra = []): void
    {
        if (!in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException("Ismeretlen update-állapot: $state");
        }
        error_log('[fountaintrade-update] state -> ' . $state . ($extra ? ' ' . json_encode($extra, JSON_UNESCAPED_UNICODE) : ''));
        $this->db->updateUpdateState(array_merge(['state' => $state], $extra));

        // "idle"-t szándékosan NEM logoljuk eseményként — ez a nyugalmi
        // alapállapot, minden egyes visszatérése (pl. egy sikeres frissítés
        // UTÁN) zajt jelentene, semmi újat nem mondana.
        if ($state !== 'idle' && isset(self::STATE_EVENT_META[$state])) {
            [$severity, $status, $defaultMessage] = self::STATE_EVENT_META[$state];
            $userMessage = !empty($extra['progress_message']) ? (string) $extra['progress_message'] : $defaultMessage;
            try {
                $this->db->logSystemEvent('updater', 'update_' . $state, $severity, $status, $userMessage);
            } catch (Throwable $e) {
                // A rendszeresemény-napló írása SOSE akaszthatja meg magát a
                // frissítési folyamatot — ugyanaz az elv, mint a nyomtatási
                // hibánál (lásd sale.php).
                error_log('[fountaintrade] UpdateState::transitionTo() system_event log sikertelen: ' . $e->getMessage());
            }
        }
    }

    public function recordHistoryStart(string $fromVersion, string $toVersion, string $triggerSource, ?string $actor, ?string $releaseTag = null, ?string $commitSha = null): int
    {
        return $this->db->insertUpdateHistory([
            'from_version'   => $fromVersion,
            'to_version'     => $toVersion,
            'trigger_source' => $triggerSource,
            'actor'          => $actor,
            'release_tag'    => $releaseTag,
            'commit_sha'     => $commitSha,
            'state'          => 'checking',
        ]);
    }

    public function recordHistoryFinish(int $historyId, string $state, ?string $error = null, ?string $backupReference = null, ?string $rollbackState = null): void
    {
        $this->db->updateUpdateHistory($historyId, [
            'state'            => $state,
            'finished_at'      => date('Y-m-d H:i:s'),
            'error'            => $error,
            'backup_reference' => $backupReference,
            'rollback_state'   => $rollbackState,
        ]);
    }

    public function updateHistoryFields(int $historyId, array $fields): void
    {
        $this->db->updateUpdateHistory($historyId, $fields);
    }

    public function history(int $limit = 50): array
    {
        return $this->db->listUpdateHistory($limit);
    }
}
