<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmStructuredOutputContract;
use PHPUnit\Framework\TestCase;

final class LlmStructuredOutputContractTest extends TestCase
{
    public function testNestedOptionalObjectUnionRestoresOriginalContract(): void
    {
        $schema = ['type' => 'object', 'required' => ['result'], 'properties' => [
            'result' => ['anyOf' => [
                ['type' => ['object', 'null'], 'properties' => [
                    'missing' => ['type' => 'number'],
                    'nullable' => ['type' => ['number', 'null']],
                ]],
                ['type' => 'string'],
            ]],
        ]];
        $wire = LlmStructuredOutputContract::openAiSchema($schema);
        self::assertFalse($wire['properties']['result']['anyOf'][0]['additionalProperties']);
        self::assertSame(['result' => ['nullable' => null]], LlmStructuredOutputContract::decode(
            '{"result":{"missing":null,"nullable":null}}', $schema, 'openai'
        ));
    }

    public function testExactBoundariesAndRealZeroRemainValid(): void
    {
        $schema = ['type' => 'object', 'required' => ['facts', 'note'], 'properties' => [
            'facts' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 1, 'items' => [
                'type' => 'number', 'minimum' => 0, 'maximum' => 1,
            ]],
            'note' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 2],
        ]];
        self::assertSame(['facts' => [0], 'note' => '甲乙'], LlmStructuredOutputContract::decode(
            '{"facts":[0],"note":"甲乙"}', $schema
        ));
    }

    public function testOpenAiAdapterDoesNotMutateBusinessSchema(): void
    {
        $schema = ['type' => 'object', 'properties' => ['note' => ['type' => 'string']]];
        LlmStructuredOutputContract::openAiSchema($schema);
        self::assertArrayNotHasKey('required', $schema);
        self::assertArrayNotHasKey('additionalProperties', $schema);
    }

    public function testAnyOfPreservesExplicitNullAcceptedByAnOriginalBranch(): void
    {
        $branches = [
            ['type' => 'object', 'properties' => ['note' => ['type' => 'string']]],
            ['type' => 'object', 'required' => ['note'], 'properties' => ['note' => ['type' => 'null']]],
        ];
        foreach ([$branches, array_reverse($branches)] as $orderedBranches) {
            $schema = ['type' => 'object', 'required' => ['result'], 'properties' => [
                'result' => ['anyOf' => $orderedBranches],
            ]];
            self::assertSame(['result' => ['note' => null]], LlmStructuredOutputContract::decode(
                '{"result":{"note":null}}', $schema, 'openai'
            ));
        }
    }

    public function testFailedAnyOfCandidateDoesNotMutateNestedObjectsForTheNextBranch(): void
    {
        $schema = ['type' => 'object', 'required' => ['result'], 'properties' => [
            'result' => [
                'properties' => ['details' => ['required' => ['note']]],
                'anyOf' => [
                    ['type' => 'object', 'required' => ['details'], 'properties' => [
                        'details' => ['type' => 'object', 'properties' => [
                            'note' => ['type' => 'string'], 'missing' => ['type' => 'string'],
                        ]],
                    ]],
                    ['type' => 'object', 'required' => ['details'], 'properties' => [
                        'details' => ['type' => 'object', 'required' => ['note'], 'properties' => [
                            'note' => ['type' => 'null'], 'missing' => ['type' => 'string'],
                        ]],
                    ]],
                ],
            ],
        ]];
        self::assertSame(['result' => ['details' => ['note' => null]]], LlmStructuredOutputContract::decode(
            '{"result":{"details":{"note":null,"missing":null}}}', $schema, 'openai'
        ));
    }

    public function testObjectEnumMatchesJsonValuesWithoutDependingOnPropertyOrder(): void
    {
        $schema = ['type' => 'object', 'required' => ['item'], 'properties' => [
            'item' => ['type' => 'object', 'required' => ['ok', 'facts'], 'properties' => [
                'ok' => ['type' => 'boolean'],
                'facts' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['value'],
                    'properties' => ['value' => ['type' => 'number']]]],
            ], 'enum' => [['ok' => true, 'facts' => [['value' => 1]]]]],
        ]];
        foreach (['deepseek', 'openai'] as $provider) {
            self::assertSame(['item' => ['facts' => [['value' => 1]], 'ok' => true]], LlmStructuredOutputContract::decode(
                '{"item":{"facts":[{"value":1.0}],"ok":true}}', $schema, $provider
            ));
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('differentJsonEnumValues')]
    public function testEnumKeepsDifferentJsonValuesDistinct(string $json, mixed $allowed): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('enum mismatch');
        LlmStructuredOutputContract::decode($json, [
            'type' => 'object', 'required' => ['value'], 'properties' => ['value' => ['enum' => [$allowed]]],
        ]);
    }

    public static function differentJsonEnumValues(): array
    {
        return [
            'false versus zero' => ['{"value":false}', 0],
            'zero versus false' => ['{"value":0}', false],
            'number versus string' => ['{"value":1}', '1'],
            'object versus list' => ['{"value":{}}', []],
            'list versus object' => ['{"value":[]}', new \stdClass()],
            'numeric object keys versus list' => ['{"value":{"0":"a"}}', ['a']],
            'array order' => ['{"value":[2,1]}', [1, 2]],
            'nested boolean versus zero' => ['{"value":{"ok":false}}', ['ok' => 0]],
            'distinct large numbers' => ['{"value":9007199254740993}', 9007199254740992.0],
        ];
    }
}
