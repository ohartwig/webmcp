<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Application\Service;

use Koh\WebMcp\Application\Service\InputSchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InputSchemaValidatorTest extends TestCase
{
    private InputSchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new InputSchemaValidator();
    }

    /**
     * The schema of the built-in search tool, as advertised.
     *
     * @return array<string, mixed>
     */
    private static function searchSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'maxLength' => 200],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, mixed, list<string>}>
     */
    public static function cases(): iterable
    {
        yield 'conforming input passes' => [self::searchSchema(), ['query' => 'typo3', 'limit' => 5], []];
        yield 'an integral float is an integer' => [self::searchSchema(), ['query' => 'typo3', 'limit' => 5.0], []];
        yield 'a missing required property is named' => [self::searchSchema(), ['limit' => 5], ['input: missing required property "query"']];
        yield 'a wrong type is reported with the path, not the value' => [self::searchSchema(), ['query' => ['nested']], ['input.query: must be of type string']];
        yield 'a non-integral number is not an integer' => [self::searchSchema(), ['query' => 'x', 'limit' => 2.5], ['input.limit: must be of type integer']];
        yield 'a numeric string is not an integer' => [self::searchSchema(), ['query' => 'x', 'limit' => '5'], ['input.limit: must be of type integer']];
        yield 'bounds apply' => [self::searchSchema(), ['query' => 'x', 'limit' => 500], ['input.limit: must be at most 50']];
        yield 'string length applies' => [self::searchSchema(), ['query' => str_repeat('ä', 201)], ['input.query: must be at most 200 characters']];
        yield 'unknown properties are allowed unless the schema forbids them' => [self::searchSchema(), ['query' => 'x', 'extra' => 1], []];
        yield 'additionalProperties false forbids them without repeating the name' => [
            self::searchSchema() + ['additionalProperties' => false],
            ['query' => 'x', 'secret-name' => 1],
            ['input: additional properties are not allowed'],
        ];
        yield 'an empty schema constrains nothing' => [[], ['anything' => [1, 2]], []];
        yield 'an empty body is an empty object' => [self::searchSchema(), [], ['input: missing required property "query"']];
        yield 'a context tool with stdClass properties accepts an empty object' => [
            ['type' => 'object', 'properties' => new \stdClass()],
            [],
            [],
        ];
        yield 'enum is strict' => [
            ['type' => 'object', 'properties' => ['sort' => ['type' => 'string', 'enum' => ['asc', 'desc']]]],
            ['sort' => 'random'],
            ['input.sort: must be one of the allowed values'],
        ];
        yield 'arrays are checked item by item' => [
            ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 2]]],
            ['tags' => ['a', 2, 'c']],
            ['input.tags: must have at most 2 items', 'input.tags[1]: must be of type string'],
        ];
        yield 'a list where an object was promised is a type error' => [self::searchSchema(), ['a', 'b'], ['input: must be of type object']];
        yield 'a type list accepts any of its members' => [
            ['type' => 'object', 'properties' => ['id' => ['type' => ['integer', 'string']]]],
            ['id' => 'abc'],
            [],
        ];
        yield 'booleans are not integers' => [
            ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]],
            ['limit' => true],
            ['input.limit: must be of type integer'],
        ];
        yield 'nested objects are walked' => [
            ['type' => 'object', 'properties' => ['filter' => ['type' => 'object', 'properties' => ['lang' => ['type' => 'string']], 'required' => ['lang']]]],
            ['filter' => []],
            ['input.filter: missing required property "lang"'],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function validates(array $schema, mixed $input, array $expected): void
    {
        self::assertSame($expected, $this->validator->validate($schema, $input));
    }

    /**
     * A violation message carries the path and the rule. The value a caller
     * sent must never appear in it, because the message goes back out.
     */
    #[Test]
    public function violationsNeverContainTheValue(): void
    {
        $violations = $this->validator->validate(
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'additionalProperties' => false],
            ['query' => 12345, 'canary-property' => 'canary-value'],
        );

        self::assertNotSame([], $violations);
        foreach ($violations as $violation) {
            self::assertStringNotContainsString('12345', $violation);
            self::assertStringNotContainsString('canary', $violation);
        }
    }
}
