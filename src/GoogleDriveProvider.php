<?php

require_once __DIR__ . '/CloudBackupProvider.php';

class GoogleDriveProvider implements CloudBackupProvider
{
    private string $clientId;
    private string $clientSecret;
    private string $refreshToken;
    private ?string $folderId;

    public function __construct(string $clientId, string $clientSecret, string $refreshToken, ?string $folderId = null)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->refreshToken = $refreshToken;
        $this->folderId = $folderId ?: null;
    }

    public function name(): string
    {
        return 'Google Drive';
    }

    private function getAccessToken(): string
    {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
                'grant_type'    => 'refresh_token',
            ]),
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            throw new RuntimeException('Google Drive token megújítása sikertelen — ellenőrizd a client id/secret/refresh token beállításokat.');
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            throw new RuntimeException('Google Drive nem adott vissza access tokent.');
        }
        return $data['access_token'];
    }

    public function upload(string $localFilePath, string $remoteFileName): void
    {
        $accessToken = $this->getAccessToken();

        $metadata = ['name' => $remoteFileName];
        if ($this->folderId) {
            $metadata['parents'] = [$this->folderId];
        }

        $boundary = 'stockmanagerbackup' . bin2hex(random_bytes(8));
        $fileContent = file_get_contents($localFilePath);

        $body = "--$boundary\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . json_encode($metadata) . "\r\n"
            . "--$boundary\r\n"
            . "Content-Type: application/x-sqlite3\r\n\r\n"
            . $fileContent . "\r\n"
            . "--$boundary--";

        $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: multipart/related; boundary=' . $boundary,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT    => 60,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            throw new RuntimeException("Google Drive feltöltési hiba ($status): $response");
        }
    }

    public function listBackups(): array
    {
        $accessToken = $this->getAccessToken();

        $query = "name contains 'stockmanager_backup_' and trashed = false";
        if ($this->folderId) {
            $query .= " and '{$this->folderId}' in parents";
        }

        $ch = curl_init('https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q'      => $query,
            'fields' => 'files(id,name,createdTime)',
            'pageSize' => 100,
        ]));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            return [];
        }

        $data = json_decode($response, true);
        return array_map(fn($f) => ['id' => $f['id'], 'name' => $f['name']], $data['files'] ?? []);
    }

    public function delete(string $id): void
    {
        $accessToken = $this->getAccessToken();

        $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . urlencode($id));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT        => 20,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
