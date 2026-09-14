<?php

require_once __DIR__ . '/AppVersion.php';
require_once __DIR__ . '/UpdateInstaller.php';
require_once __DIR__ . '/UpdateState.php';

/**
 * Egyetlen belépési pont az önfrissítő rendszerhez a webroot/api/update-*.php
 * végpontok számára — pontosan ugyanaz a szerep, mint InvoiceService-nek a
 * számlázásnál: a végpontok EZEN keresztül szólítják meg az
 * UpdateInstaller/UpdateState-et, sose közvetlenül.
 */
final class UpdateService
{
    private Database $db;
    private array $config;
    private string $appRoot;
    private Settings $settingsStore;
    private UpdateState $state;

    public function __construct(Database $db, array $config, string $appRoot, Settings $settingsStore)
    {
        $this->db = $db;
        $this->config = $config;
        $this->appRoot = $appRoot;
        $this->settingsStore = $settingsStore;
        $this->state = new UpdateState($db);
    }

    public function getStatus(): array
    {
        $state = $this->state->current();
        return [
            'product'                          => AppVersion::PRODUCT,
            'current_version'                  => AppVersion::CURRENT,
            'channel'                           => AppVersion::DEFAULT_CHANNEL,
            'state'                             => $state['state'],
            'latest_version'                    => $state['latest_version'],
            'latest_release_tag'                => $state['latest_release_tag'],
            'latest_release_notes'               => $state['latest_release_notes'],
            'latest_published_at'               => $state['latest_published_at'],
            'update_available'                  => !empty($state['latest_version']) && AppVersion::compare($state['latest_version'], AppVersion::CURRENT) > 0,
            'last_check_at'                     => $state['latest_checked_at'],
            'last_check_error'                  => $state['last_check_error'],
            'last_successful_update_at'         => $state['last_successful_update_at'],
            'last_successful_update_version'    => $state['last_successful_update_version'],
            'progress_message'                  => $state['progress_message'],
            'lock_held'                         => $this->db->isUpdateLockHeld(),
        ];
    }

    public function checkNow(): array
    {
        return $this->buildInstaller()->checkForUpdate();
    }

    /**
     * Csak elindítja a telepítést — a hívó (webroot/api/update-install.php)
     * felelőssége, hogy ez ne blokkolja a böngészőt (lásd ott a
     * fastcgi_finish_request()-es docblockot).
     */
    public function install(string $triggerSource, ?string $actor): array
    {
        return $this->buildInstaller()->install($triggerSource, $actor);
    }

    public function getHistory(int $limit = 50): array
    {
        return $this->state->history($limit);
    }

    private function buildInstaller(): UpdateInstaller
    {
        return new UpdateInstaller($this->db, $this->config, $this->appRoot, $this->settingsStore);
    }
}
