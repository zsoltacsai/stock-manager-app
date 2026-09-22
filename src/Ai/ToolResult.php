<?php

declare(strict_types=1);

/** Egy eszköz-végrehajtás strukturált eredménye — ez megy vissza a providernek "tool" szerepű üzenetként. */
final class ToolResult
{
    /** @param array<string,mixed>|null $data */
    private function __construct(
        public readonly string $toolCallId,
        public readonly string $name,
        public readonly bool $success,
        public readonly ?array $data,
        public readonly ?string $error,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function ok(string $toolCallId, string $name, array $data): self
    {
        return new self($toolCallId, $name, true, $data, null);
    }

    /**
     * @param string $error Rövid, biztonságosan a modellnek (és — hiba esetén — végső soron a
     *   felhasználónak is) mutatható üzenet. SOSE nyers kivétel-szöveg vagy belső technikai részlet —
     *   lásd ToolRegistry::execute() docblokkja.
     */
    public static function fail(string $toolCallId, string $name, string $error): self
    {
        return new self($toolCallId, $name, false, null, $error);
    }

    /** A providernek küldött "tool" üzenet tartalma (JSON-be kódolva). */
    public function toProviderPayload(): array
    {
        return $this->success
            ? ['success' => true, 'data' => $this->data]
            : ['success' => false, 'error' => $this->error];
    }
}
