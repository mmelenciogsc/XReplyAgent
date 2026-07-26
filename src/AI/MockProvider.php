<?php

declare(strict_types=1);

namespace XReplyAgent\AI;

use XReplyAgent\Support\Similarity;

final class MockProvider implements ProviderInterface
{
    public function name(): string
    {
        return 'mock';
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function complete(array $request): array
    {
        $stage = (string) ($request['stage'] ?? 'analysis');
        $context = (array) ($request['context'] ?? []);
        $postText = (string) ($context['post_text'] ?? $request['message'] ?? '');
        $analysis = (array) ($context['analysis'] ?? []);

        return match ($stage) {
            'analysis' => $this->response($this->analysisPayload($postText, $context)),
            'generation' => $this->response($this->generationPayload($postText, $analysis, $context)),
            'scoring' => $this->response($this->scoringPayload($context)),
            'recommendations' => $this->response($this->recommendationPayload($context)),
            default => $this->response([
                'message' => 'Mock provider received an unknown stage.',
            ]),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function response(array $payload): array
    {
        return [
            'ok' => true,
            'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'usage' => [
                'prompt_tokens' => 120,
                'completion_tokens' => 180,
                'total_tokens' => 300,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function analysisPayload(string $postText, array $context): array
    {
        $lower = strtolower($postText);
        $topic = 'general commentary';
        $intent = 'seek_conversation';
        $tone = $this->toneForText($lower);
        $sentiment = $this->sentimentForText($lower);

        if (str_contains($lower, 'local seo') || str_contains($lower, 'aeo') || str_contains($lower, 'geo') || str_contains($lower, 'answer engine')) {
            $topic = 'search visibility and answer engines';
            $intent = 'share_framework';
        } elseif (str_contains($lower, 'local service') || str_contains($lower, 'booking') || str_contains($lower, 'follow-ups') || str_contains($lower, 'reviews') || str_contains($lower, 'customer q&a') || str_contains($lower, 'busywork stack')) {
            $topic = 'local service workflow automation';
            $intent = 'signal_process_improvement';
        } elseif (str_contains($lower, 'local ai processing') || str_contains($lower, 'on-device') || str_contains($lower, 'edge ai') || str_contains($lower, 'local ai')) {
            $topic = 'local ai processing';
            $intent = 'signal_product_direction';
        } elseif (str_contains($lower, 'business ai transformation') || str_contains($lower, 'ai transformation') || str_contains($lower, 'workforce') || str_contains($lower, 'agents')) {
            $topic = 'business ai transformation';
            $intent = 'share_framework';
        } elseif (str_contains($lower, 'launch') || str_contains($lower, 'shipping')) {
            $topic = 'product launch';
            $intent = 'announce_update';
        } elseif (str_contains($lower, 'team') || str_contains($lower, 'hiring')) {
            $topic = 'team update';
            $intent = 'share_progress';
        } elseif (str_contains($lower, 'accessibility')) {
            $topic = 'accessibility update';
            $intent = 'invite_feedback';
        } elseif (str_contains($lower, 'security') || str_contains($lower, 'privacy')) {
            $topic = 'security and privacy';
            $intent = 'signal_standards';
        }

        $secondary = array_values(array_filter([
            str_contains($lower, 'local seo') || str_contains($lower, 'aeo') ? 'search visibility' : null,
            str_contains($lower, 'local service') || str_contains($lower, 'booking') ? 'operational workflow' : null,
            str_contains($lower, 'local ai') ? 'local inference' : null,
            str_contains($lower, 'business ai') || str_contains($lower, 'agents') ? 'business automation' : null,
            str_contains($lower, 'launch') ? 'launch timing' : null,
            str_contains($lower, 'team') ? 'team coordination' : null,
            str_contains($lower, 'feedback') ? 'feedback loop' : null,
            str_contains($lower, 'accessibility') ? 'accessible design' : null,
            str_contains($lower, 'privacy') ? 'privacy posture' : null,
        ]));

        return [
            'main_topic' => $topic,
            'secondary_topics' => $secondary !== [] ? $secondary : ['general context'],
            'tone' => $tone,
            'sentiment' => $sentiment,
            'likely_intent' => $intent,
            'important_context' => [
                'The post should be answered directly, without filler.',
                !empty($context['desired_objective']) ? 'Desired objective: ' . (string) $context['desired_objective'] : 'No explicit objective supplied.',
            ],
            'conversation_angles' => [
                'Offer a practical observation.',
                'Ask a useful, low-friction question.',
                'Add a measured perspective that keeps the conversation moving.',
            ],
            'factual_claims_requiring_caution' => [
                'Verify any product, performance, or timeline claims before publishing.',
            ],
            'safety_or_reputation_risks' => [
                'Avoid overclaiming certainty or engagement outcomes.',
            ],
            'recommended_reply_approach' => 'Lead with a concise observation, then add a question or practical next step.',
            'confidence_notes' => 'Mock confidence is moderate because the post text is brief and context is limited.',
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function generationPayload(string $postText, array $analysis, array $context): array
    {
        $topic = (string) ($analysis['main_topic'] ?? 'general commentary');
        $tone = (string) ($analysis['tone'] ?? 'measured');
        $replyMax = max(80, (int) ($context['max_reply_chars'] ?? 280));

        $topicReplies = match ($topic) {
            'search visibility and answer engines' => [
                ['Useful Observation', 'That shift is real. Are you optimizing for answer selection as much as rankings now?'],
                ['Thoughtful Question', 'How are you tracking when AI surfaces the brand instead of the page?'],
                ['Measured Counterpoint', 'Feels like the play is moving from keywords to clarity and entity coverage.'],
            ],
            'local service workflow automation' => [
                ['Useful Observation', 'That’s the right frame. Which workflow usually moves the needle first: booking, follow-ups, or reviews?'],
                ['Thoughtful Question', 'Where does the busywork stack usually break first in the day-to-day flow?'],
                ['Measured Counterpoint', 'The biggest win is usually removing repetition, not adding another dashboard.'],
            ],
            'local ai processing' => [
                ['Useful Observation', 'Local processing changes the latency and privacy tradeoff in a useful way. Which workloads stay on-device?'],
                ['Thoughtful Question', 'What feels most different once inference is local instead of cloud-first?'],
                ['Measured Counterpoint', 'The interesting part is less the model and more the control the local stack gives back.'],
            ],
            'business ai transformation' => [
                ['Useful Observation', 'The real shift is operational, not just conversational. Which workflow changed first?'],
                ['Thoughtful Question', 'Are you seeing the biggest gains in intake, support, or internal coordination?'],
                ['Measured Counterpoint', 'The strongest AI wins usually come from removing friction, not from adding more surface area.'],
            ],
            default => [
                ['Useful Observation', 'Clean update. The part that stands out is the operational discipline behind it. What changed first?'],
                ['Thoughtful Question', 'Interesting direction. Which constraint mattered most when you made the call?'],
                ['Measured Counterpoint', 'That reads as deliberate, which is rare. The next iteration will tell the real story.'],
            ],
        };

        $candidates = [];
        foreach ($topicReplies as $index => $reply) {
            $candidates[] = [
                'approach_label' => (string) $reply[0],
                'reply_text' => $this->trimReply((string) $reply[1], $replyMax),
            ];
        }

        foreach ($candidates as $index => &$candidate) {
            $candidate['short_rationale'] = match ($index) {
                0 => 'Frames the post with a practical observation.',
                1 => 'Invites a specific follow-up without sounding generic.',
                default => 'Adds a measured point of view with light tension.',
            };
            $candidate['relevance_score'] = 88 - ($index * 3);
            $candidate['naturalness_score'] = 86 - ($index * 2);
            $candidate['originality_score'] = 82 + ($index * 2);
            $candidate['tone_match_score'] = 90 - $index;
            $candidate['conversation_potential_score'] = 85 + ($index * 2);
            $candidate['risk_score'] = 8 + $index;
            $candidate['total_score'] = $candidate['relevance_score']
                + $candidate['naturalness_score']
                + $candidate['originality_score']
                + $candidate['tone_match_score']
                + $candidate['conversation_potential_score']
                - $candidate['risk_score'];
            $candidate['character_count'] = mb_strlen((string) $candidate['reply_text']);
        }
        unset($candidate);

        return [
            'reply_candidates' => $candidates,
            'topic_summary' => sprintf('%s | tone %s', $topic, $tone),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function scoringPayload(array $context): array
    {
        $candidates = array_values((array) ($context['reply_candidates'] ?? []));
        $candidates = Similarity::distinctReplies($candidates, 3);
        $indices = [];
        foreach ($candidates as $position => $candidate) {
            $indices[] = (int) ($candidate['candidate_index'] ?? ($position + 1));
        }

        return [
            'ranked_candidate_indices' => $indices !== [] ? $indices : [1, 2, 3],
            'top_choice_index' => $indices[0] ?? 1,
            'ranking_notes' => [
                'Prefer the reply with the clearest next step and low risk.',
                'Keep the ranking stable and transparent.',
            ],
            'confidence_notes' => 'Ranking is deterministic in mock mode and preserves the three-candidate structure.',
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function recommendationPayload(array $context): array
    {
        $metrics = (array) ($context['metrics_json'] ?? []);
        $sampleSize = (int) ($context['sample_size'] ?? ($metrics['count'] ?? 0));

        return [
            'summary' => 'Use the highest-ranked reply when the post is current and specific; otherwise keep the reply brief and neutral.',
            'sample_size_warning' => $sampleSize > 3 ? 'Sample size is modest but usable.' : 'Sample size is thin; treat recommendations as directional.',
            'recommended_next_actions' => [
                'Review the selected reply for any factual claims.',
                'Publish only after a human sanity check.',
                'Record measured performance later for the analytics screen.',
            ],
            'persona_adjustments' => [
                'Keep the tone measured and avoid manufactured enthusiasm.',
            ],
            'prompt_adjustment_notes' => [
                'If replies feel repetitive, sharpen the specific question in the generation prompt.',
            ],
            'confidence_notes' => 'Recommendations are conservative until the post is published and measured.',
        ];
    }

    private function toneForText(string $text): string
    {
        if (str_contains($text, 'excited') || str_contains($text, 'thrilled')) {
            return 'upbeat';
        }

        if (str_contains($text, 'careful') || str_contains($text, 'consider')) {
            return 'measured';
        }

        return 'measured';
    }

    private function sentimentForText(string $text): string
    {
        if (str_contains($text, 'great') || str_contains($text, 'love') || str_contains($text, 'win')) {
            return 'positive';
        }

        if (str_contains($text, 'issue') || str_contains($text, 'risk') || str_contains($text, 'problem')) {
            return 'cautious';
        }

        return 'neutral';
    }

    private function trimReply(string $text, int $limit): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(0, $limit - 1))) . '…';
    }
}
