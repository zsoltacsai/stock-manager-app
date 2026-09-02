<?php

require_once __DIR__ . '/CloudBackupProvider.php';

/**
 * Biztonsági mentések feltöltése Dropboxra egy generált hozzáférési token
 * segítségével (Dropbox App Console → az app → "Generate access token").
 * Ez a legegyszerűbb út egyetlen, önállóan üzemeltetett telepítéshez —
 * nincs szükség böngészős OAuth-folyamatra. A token a generálás módjától
 * függően lejárhat — ha a feltöltés 401-es hibával kezd elakadni, generálj
 * újat Beállítások alatt.
 */
class DropboxProvider implements CloudBackupProvider
{
    private string $accessToken;
    private string $folder;

    public function __construct(string $accessToken, string $folder = '/StockManagerBackups')
    {
        $this->accessToken = $accessToken;
        $this->folder = '/' . trim($folder, '/');
    }

    public function name(): string
    {
        return 'Dropbox';
    }

    public function upload(string $localFilePath, string $remoteFileName): void
    {
        $path = $this->folder . '/' . $remoteFileName;

        $ch = curl_init('https://content.dropboxapi.com/2/files/upload');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Dropbox-API-Arg: ' . json_encode([
                    'path'       => $path,
                    'mode'       => 'overwrite',
                    'autorename' => false,
                    'mute'       => true,
                ]),
                'Content-Type: application/octet-stream',
            ],
            CURLOPT_POSTFIELDS => file_get_contents($localFilePath),
            CURLOPT_TIMEOUT    => 60,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("Dropbox feltöltési hiba: $err");
        }
        if ($status >= 400) {
            throw new RuntimeException("Dropbox feltöltési hiba ($status): $response");
        }
    }

    public function listBackups(): array
    {
        $ch = curl_init('https://api.dropboxapi.com/2/files/list_folder');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['path' => $this->folder]),
            CURLOPT_TIMEOUT    => 30,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            return [];
        }

        $data = json_decode($response, true);
        $entries = $data['entries'] ?? [];

        return array_map(fn($e) => ['id' => $e['path_lower'], 'name' => $e['name']], array_filter(
            $entries,
            fn($e) => ($e['.tag'] ?? '') === 'file'
        ));
    }

    public function delete(string $id): void
    {
        $ch = curl_init('https://api.dropboxapi.com/2/files/delete_v2');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['path' => $id]),
            CURLOPT_TIMEOUT    => 30,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
