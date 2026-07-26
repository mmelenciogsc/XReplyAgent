<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XReplyAgent\AI\MockProvider;

final class MockProviderTest extends TestCase
{
    public function testGeneratesStructuredAnalysisPayload(): void
    {
        $provider = new MockProvider();
        $result = $provider->complete([
            'stage' => 'analysis',
            'message' => 'Launch update with accessibility notes.',
        ]);

        $this->assertTrue($result['ok']);

        $payload = json_decode((string) $result['content'], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('main_topic', $payload);
        $this->assertArrayHasKey('secondary_topics', $payload);
        $this->assertArrayHasKey('recommended_reply_approach', $payload);
    }

    public function testGeneratesExactlyThreeReplyCandidatesForGenerationStage(): void
    {
        $provider = new MockProvider();
        $result = $provider->complete([
            'stage' => 'generation',
            'message' => 'Help me write a reply.',
            'context' => [
                'analysis' => [
                    'main_topic' => 'product launch',
                    'tone' => 'measured',
                ],
            ],
        ]);

        $payload = json_decode((string) $result['content'], true);
        $this->assertIsArray($payload);
        $this->assertCount(3, $payload['reply_candidates']);
        $this->assertArrayHasKey('approach_label', $payload['reply_candidates'][0]);
        $this->assertArrayHasKey('reply_text', $payload['reply_candidates'][0]);
    }

    public function testGeneratesRankingPayloadForScoringStage(): void
    {
        $provider = new MockProvider();
        $result = $provider->complete([
            'stage' => 'scoring',
            'context' => [
                'reply_candidates' => [
                    ['candidate_index' => 1, 'reply_text' => 'First'],
                    ['candidate_index' => 2, 'reply_text' => 'Second'],
                    ['candidate_index' => 3, 'reply_text' => 'Third'],
                ],
            ],
        ]);

        $payload = json_decode((string) $result['content'], true);
        $this->assertIsArray($payload);
        $this->assertCount(3, $payload['ranked_candidate_indices']);
        $this->assertSame(1, $payload['top_choice_index']);
    }
}
