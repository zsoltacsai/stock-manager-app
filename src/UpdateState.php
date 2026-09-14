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

    public function transitionTo(string $state, array $extra = []): void
    {
        if (!in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException("Ismeretlen update-állapot: $state");
        }
        error_log('[fountaintrade-update] state -> ' . $state . ($extra ? ' ' . json_encode($extra, JSON_UNESCAPED_UNICODE) : ''));
        $this->db->updateUpdateState(array_merge(['state' => $state], $extra));
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
