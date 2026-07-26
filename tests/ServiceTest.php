<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XReplyAgent\AI\ProviderInterface;
use XReplyAgent\AI\Service;

final class ServiceTest extends TestCase
{
    public function testRepairsMalformedProviderOutputOnceForAnalysisStage(): void
    {
        $provider = new class implements ProviderInterface {
            private int $calls = 0;

            public function name(): string
            {
                return 'fake';
            }

            public function complete(array $request): array
            {
                $this->calls++;
                if ($this->calls === 1) {
                    return ['ok' => true, 'content' => '{broken', 'usage' => []];
                }

                return [
                    'ok' => true,
                    'content' => json_encode([
                        'main_topic' => 'product launch',
                        'secondary_topics' => ['workflow'],
                        'tone' => 'measured',
                        'sentiment' => 'positive',
                        'likely_intent' => 'announce_update',
                        'important_context' => ['Synthetic post'],
                        'conversation_angles' => ['Ask a useful question'],
                        'factual_claims_requiring_caution' => ['Verify timing.'],
                        'safety_or_reputation_risks' => ['Avoid overpromising.'],
                        'recommended_reply_approach' => 'Lead with a concise observation.',
                        'confidence_notes' => 'Repair succeeded.',
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'usage' => [],
                ];
            }
        };

        $service = new Service($provider);
        $result = $service->stage(
            'analysis',
            [
                'message' => 'Launch update',
                'post_text' => 'Launch update',
            ],
            [
                'system_prompt' => 'Return JSON only.',
            ],
            [
                'instructions' => 'Keep replies concise.',
            ]
        );

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['schema_valid']);
        $this->assertTrue($result['repair_attempted']);
        $this->assertSame('fake', $result['provider']);
        $this->assertSame('product launch', $result['payload']['main_topic']);
    }
}
