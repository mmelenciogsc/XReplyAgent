<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XReplyAgent\AI\SchemaValidator;

final class SchemaValidatorTest extends TestCase
{
    public function testAcceptsValidThreeCandidateReplySchema(): void
    {
        $validator = new SchemaValidator();
        $schema = [
            'type' => 'object',
            'required' => ['reply_candidates'],
            'properties' => [
                'reply_candidates' => [
                    'type' => 'array',
                    'minItems' => 3,
                    'maxItems' => 3,
                    'items' => [
                        'type' => 'object',
                        'required' => ['reply_text', 'approach_label'],
                        'properties' => [
                            'reply_text' => ['type' => 'string'],
                            'approach_label' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'additionalProperties' => false,
        ];

        $result = $validator->validate($schema, [
            'reply_candidates' => [
                ['reply_text' => 'A', 'approach_label' => 'One'],
                ['reply_text' => 'B', 'approach_label' => 'Two'],
                ['reply_text' => 'C', 'approach_label' => 'Three'],
            ],
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function testRejectsMissingRequiredField(): void
    {
        $validator = new SchemaValidator();
        $schema = [
            'type' => 'object',
            'required' => ['reply_candidates'],
            'properties' => [
                'reply_candidates' => [
                    'type' => 'array',
                    'minItems' => 3,
                    'maxItems' => 3,
                    'items' => ['type' => 'string'],
                ],
            ],
            'additionalProperties' => false,
        ];

        $result = $validator->validate($schema, []);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testRejectsInvalidArrayItemTypes(): void
    {
        $validator = new SchemaValidator();
        $schema = [
            'type' => 'object',
            'required' => ['reply_candidates'],
            'properties' => [
                'reply_candidates' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'additionalProperties' => false,
        ];

        $result = $validator->validate($schema, [
            'reply_candidates' => ['one', 42],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }
}
