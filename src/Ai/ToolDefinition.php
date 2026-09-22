<?php

declare(strict_types=1);

/**
 * Egy regisztrált AI-eszköz teljes leírása — a ToolRegistry-n keresztül
 * jut el a providerhez (LLM-nek felajánlott eszközlista) ÉS a tényleges
 * végrehajtáshoz. A $handler SOSE a felhasználó/LLM által megadott,
 * dinamikusan feloldott callback — mindig a hívó (pl. InventoryTools::
 * registerAll()) által, fordítási időben rögzített PHP callable, lásd
 * ToolRegistry::register() docblokkja.
 */
final class ToolDefinition
{
    /**
     * @param array<string,mixed> $inputSchema JSON-schema-szerű leírás (type/properties/required) —
     *   ez kerül a providernek elküldött eszköz-listába, hogy a modell tudja, milyen argumentumokat várunk.
     * @param callable(array<string,mixed>):array<string,mixed> $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        /** @var callable */
        public $handler,
        public readonly bool $readOnly = true,
    ) {
    }
}
