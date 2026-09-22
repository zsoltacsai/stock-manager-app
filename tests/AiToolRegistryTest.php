<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AiToolRegistryTest extends TestCase
{
    private function makeTool(string $name, callable $handler, bool $readOnly = true): ToolDefinition
    {
        return new ToolDefinition($name, "Teszt eszköz: $name", ['type' => 'object', 'properties' => []], $handler, $readOnly);
    }

    public function testRegisteredToolIsFoundAndListed(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->makeTool('echo_tool', fn (array $args) => ['echo' => $args]));

        $this->assertTrue($registry->has('echo_tool'));
        $this->assertFalse($registry->has('nope'));
        $this->assertCount(1, $registry->all());
        $this->assertSame('echo_tool', $registry->all()[0]->name);
    }

    public function testDuplicateRegistrationIsRejected(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->makeTool('dup', fn (array $args) => []));

        $this->expectException(InvalidArgumentException::class);
        $registry->register($this->makeTool('dup', fn (array $args) => []));
    }

    public function testUnknownToolCallReturnsFailedResultNotException(): void
    {
        $registry = new ToolRegistry();
        $result = $registry->execute(new ToolCall('call_1', 'does_not_exist', []));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Ismeretlen eszköz', $result->error);
    }

    public function testSuccessfulExecutionReturnsOkResultWithData(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->makeTool('add', fn (array $args) => ['sum' => ($args['a'] ?? 0) + ($args['b'] ?? 0)]));

        $result = $registry->execute(new ToolCall('call_1', 'add', ['a' => 2, 'b' => 3]));

        $this->assertTrue($result->success);
        $this->assertSame(5, $result->data['sum']);
    }

    public function testInvalidArgumentExceptionMessageIsPassedThroughAsSafe(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->makeTool('strict', function (array $args) {
            if (empty($args['id'])) {
                throw new InvalidArgumentException('Az "id" mező kötelező.');
            }
            return ['id' => $args['id']];
        }));

        $result = $registry->execute(new ToolCall('call_1', 'strict', []));

        $this->assertFalse($result->success);
        $this->assertSame('Az "id" mező kötelező.', $result->error);
    }

    public function testUnexpectedExceptionIsNeverLeakedRaw(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->makeTool('boom', function (array $args) {
            throw new RuntimeException('nagyon-titkos-belso-adatbazis-hiba /var/www/secret-path');
        }));

        $result = $registry->execute(new ToolCall('call_1', 'boom', []));

        $this->assertFalse($result->success);
        $this->assertStringNotContainsString('titkos', $result->error);
        $this->assertStringNotContainsString('/var/www', $result->error);
        $this->assertSame('Az eszköz végrehajtása sikertelen.', $result->error);
    }

    public function testHandlerNotReturningArrayIsTreatedAsFailure(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->makeTool('bad_return', fn (array $args) => 'nem tömb'));

        $result = $registry->execute(new ToolCall('call_1', 'bad_return', []));

        $this->assertFalse($result->success);
    }

    public function testReadOnlyClassificationIsPreserved(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->makeTool('ro', fn (array $args) => [], true));
        $registry->register($this->makeTool('rw', fn (array $args) => [], false));

        $tools = $registry->all();
        $byName = [];
        foreach ($tools as $t) {
            $byName[$t->name] = $t;
        }
        $this->assertTrue($byName['ro']->readOnly);
        $this->assertFalse($byName['rw']->readOnly);
    }

    public function testProviderToolListHasExpectedShape(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new ToolDefinition(
            'get_thing',
            'Egy dolog lekérése.',
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            fn (array $args) => ['id' => $args['id'] ?? null],
        ));

        $list = $registry->toProviderToolList();
        $this->assertCount(1, $list);
        $this->assertSame('function', $list[0]['type']);
        $this->assertSame('get_thing', $list[0]['function']['name']);
        $this->assertSame('object', $list[0]['function']['parameters']['type']);
    }
}
