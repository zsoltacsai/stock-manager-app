<?php

/**
 * Vékony, önmagában olvasható becsomagolása a Database `update_state`
 * során élő zárolási mezőknek (lock_token/lock_started_at/lock_hostname) —
 * lásd Database::migrateV21Updates() docblockja a tényleges, atomikus
 * UPDATE...WHERE claim-mechanizmusért (ugyanaz az elv, mint
 * claimIncomingInvoiceSync()-nál). Ez az osztály nem duplikál SQL-t, csak
 * egy stabil belépési pontot ad a hívóknak (UpdateService/UpdateInstaller/
 * a CLI worker) — lásd 18. pont: "Két cron vagy két admin request ne
 * tudjon párhuzamos update-et indítani."
 */
final class UpdateLock
{
    private Database $db;
    private string $token;
    private string $hostname;

    public function __construct(Database $db, ?string $token = null, ?string $hostname = null)
    {
        $this->db = $db;
        $this->token = $token ?? bin2hex(random_bytes(16));
        $this->hostname = $hostname ?? (string) (gethostname() ?: 'unknown-host');
    }

    public function token(): string
    {
        return $this->token;
    }

    /** @param int $staleAfterSeconds ennyi ideig nem frissített zár elavultnak (egy összeomlott korábbi futásnak) számít, és felülírható. */
    public function acquire(int $staleAfterSeconds = 3600): bool
    {
        return $this->db->claimUpdateLock($this->token, $this->hostname, $staleAfterSeconds);
    }

    /** Csak a zár TÉNYLEGES birtokosa (ez a token) oldhatja fel. */
    public function release(): void
    {
        $this->db->releaseUpdateLock($this->token);
    }

    public function isHeldByAnyone(int $staleAfterSeconds = 3600): bool
    {
        return $this->db->isUpdateLockHeld($staleAfterSeconds);
    }
}
