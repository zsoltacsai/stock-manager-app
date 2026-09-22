<?php

declare(strict_types=1);

/** Egyetlen, a providertől visszakapott eszköz-hívási kérés. */
final class ToolCall
{
    /** @param array<string,mixed> $arguments */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
    ) {
    }
}
