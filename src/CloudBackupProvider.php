<?php

interface CloudBackupProvider
{
    public function upload(string $localFilePath, string $remoteFileName): void;

    public function listBackups(): array;

    public function delete(string $id): void;

    public function name(): string;
}
