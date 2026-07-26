<?php

declare(strict_types=1);

namespace XReplyAgent\AI;

final class Service
{
    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly SchemaValidator $validator = new SchemaValidator()
    ) {
    }

    /**
     * Backwards-compatible entry point for a single stage run.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $prompt
     * @param array<string, mixed> $persona
     * @return array<string, mixed>
     */
    public function respond(array $input, array $prompt, array $persona): array
    {
        return $this->stage((string) ($input['stage'] ?? 'analysis'), $input, $prompt, $persona);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $prompt
     * @param array<string, mixed> $persona
     * @return array<string, mixed>
     */
    public function stage(string $stage, array $input, array $prompt, array $persona): array
    {
        $schema = $this->schemaForStage($stage, $prompt);
        $messages = $this->buildMessages($stage, $input, $prompt, $persona);
        $startedAt = microtime(true);

        $first = $this->provider->complete([
            'stage' => $stage,
            'messages' => $messages,
            'schema' => $schema,
            'schema_name' => (string) ($prompt['slug'] ?? $stage),
            'context' => $input,
            'input_text' => (string) ($input['post_text'] ?? $input['message'] ?? ''),
        ]);

        $raw = (string) ($first['content'] ?? '');
        $decoded = $this->decodeJson($raw);
        $validation = $this->validator->validate($schema, $decoded);
        $repairAttempted = false;
        $errorCategory = '';

        if (empty($first['ok'])) {
            $errorCategory = 'provider_error';
        }

        if (!$validation['valid']) {
            $repairAttempted = true;
            $repair = $this->provider->complete([
                'stage' => $stage,
                'messages' => $this->repairMessages($stage, $messages, $raw, $schema),
                'schema' => $schema,
                'schema_name' => (string) ($prompt['slug'] ?? $stage) . '_repair',
                'context' => $input,
                'input_text' => (string) ($input['post_text'] ?? $input['message'] ?? ''),
            ]);
            $raw = (string) ($repair['content'] ?? '');
            $decoded = $this->decodeJson($raw);
            $validation = $this->validator->validate($schema, $decoded);
            $first = $repair;
            $errorCategory = $validation['valid'] ? 'malformed_json_repaired' : 'schema_validation';
        }

        if (!$validation['valid']) {
            $decoded = $this->fallbackPayload($stage, $input);
            $validation = $this->validator->validate($schema, $decoded);
            $errorCategory = $errorCategory !== '' ? $errorCategory : 'schema_validation';
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        if ($errorCategory === '' && $repairAttempted) {
            $errorCategory = 'malformed_json_repaired';
        }

        return [
            'ok' => true,
            'provider' => $this->provider->name(),
            'payload' => $decoded,
            'schema_valid' => $validation['valid'],
            'validation_errors' => $validation['errors'],
            'repair_attempted' => $repairAttempted,
            'usage' => (array) ($first['usage'] ?? []),
            'raw' => $raw,
            'latency_ms' => $latencyMs,
            'error_category' => $errorCategory,
        ];
    }

    /**
     * @param array<string, mixed> $prompt
     * @return array<string, mixed>
     */
    private function schemaForStage(string $stage, array $prompt): array
    {
        $schemaSource = $prompt['response_schema'] ?? [];
        if (is_array($schemaSource)) {
            $schema = $schemaSource;
        } else {
            $schema = json_decode((string) $schemaSource, true);
        }
        if (is_array($schema) && $schema !== []) {
            return $schema;
        }

        return match ($stage) {
            'analysis' => $this->analysisSchema(),
            'generation' => $this->generationSchema(),
            'scoring' => $this->scoringSchema(),
            'recommendations' => $this->recommendationSchema(),
            default => $this->analysisSchema(),
        };
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $prompt
     * @param array<string, mixed> $persona
     * @return array<int, array<string, mixed>>
     */
    private function buildMessages(string $stage, array $input, array $prompt, array $persona): array
    {
        $personaInstructions = trim((string) ($persona['instructions'] ?? ''));
        $system = trim((string) ($prompt['system_prompt'] ?? ''));
        $userTemplate = trim((string) ($prompt['user_prompt'] ?? ''));
        $postText = trim((string) ($input['post_text'] ?? $input['post_content'] ?? $input['message'] ?? ''));
        $context = [
            'stage' => $stage,
            'post_text' => $postText,
            'source_url' => (string) ($input['source_url'] ?? ''),
            'context_text' => (string) ($input['context_text'] ?? ''),
            'desired_objective' => (string) ($input['desired_objective'] ?? ''),
            'topic_hint' => (string) ($input['topic_hint'] ?? ''),
            'analysis_json' => $input['analysis_json'] ?? [],
            'reply_candidates_json' => $input['reply_candidates_json'] ?? [],
            'metrics_json' => $input['metrics_json'] ?? [],
            'persona' => $persona,
        ];

        $message = $userTemplate !== '' ? $this->renderTemplate($userTemplate, $context) : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            [
                'role' => 'system',
                'content' => trim($system . "\n\nPersona:\n" . $personaInstructions . "\n\nReturn valid JSON only. Do not use markdown. Keep the schema exact."),
            ],
            [
                'role' => 'user',
                'content' => (string) $message,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $schema
     * @return array<int, array<string, mixed>>
     */
    private function repairMessages(string $stage, array $messages, string $raw, array $schema): array
    {
        return [
            [
                'role' => 'system',
                'content' => 'Repair the JSON for the ' . $stage . ' stage. Return JSON only and preserve the exact schema. Do not add commentary.',
            ],
            [
                'role' => 'user',
                'content' => json_encode(
                    [
                        'schema' => $schema,
                        'original_messages' => $messages,
                        'malformed_output' => $raw,
                    ],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function analysisSchema(): array
    {
        return [
            'type' => 'object',
            'required' => [
                'main_topic',
                'secondary_topics',
                'tone',
                'sentiment',
                'likely_intent',
                'important_context',
                'conversation_angles',
                'factual_claims_requiring_caution',
                'safety_or_reputation_risks',
                'recommended_reply_approach',
                'confidence_notes',
            ],
            'properties' => [
                'main_topic' => ['type' => 'string'],
                'secondary_topics' => ['type' => 'array', 'items' => ['type' => 'string']],
                'tone' => ['type' => 'string'],
                'sentiment' => ['type' => 'string'],
                'likely_intent' => ['type' => 'string'],
                'important_context' => ['type' => 'array', 'items' => ['type' => 'string']],
                'conversation_angles' => ['type' => 'array', 'items' => ['type' => 'string']],
                'factual_claims_requiring_caution' => ['type' => 'array', 'items' => ['type' => 'string']],
                'safety_or_reputation_risks' => ['type' => 'array', 'items' => ['type' => 'string']],
                'recommended_reply_approach' => ['type' => 'string'],
                'confidence_notes' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generationSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['reply_candidates'],
            'properties' => [
                'reply_candidates' => [
                    'type' => 'array',
                    'minItems' => 3,
                    'maxItems' => 3,
                    'items' => [
                        'type' => 'object',
                        'required' => [
                            'reply_text',
                            'approach_label',
                            'short_rationale',
                            'relevance_score',
                            'naturalness_score',
                            'originality_score',
                            'tone_match_score',
                            'conversation_potential_score',
                            'risk_score',
                            'total_score',
                            'character_count',
                        ],
                        'properties' => [
                            'reply_text' => ['type' => 'string'],
                            'approach_label' => ['type' => 'string'],
                            'short_rationale' => ['type' => 'string'],
                            'relevance_score' => ['type' => 'number'],
                            'naturalness_score' => ['type' => 'number'],
                            'originality_score' => ['type' => 'number'],
                            'tone_match_score' => ['type' => 'number'],
                            'conversation_potential_score' => ['type' => 'number'],
                            'risk_score' => ['type' => 'number'],
                            'total_score' => ['type' => 'number'],
                            'character_count' => ['type' => 'integer'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scoringSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['ranked_candidate_indices', 'top_choice_index', 'ranking_notes', 'confidence_notes'],
            'properties' => [
                'ranked_candidate_indices' => [
                    'type' => 'array',
                    'minItems' => 3,
                    'maxItems' => 3,
                    'items' => ['type' => 'integer'],
                ],
                'top_choice_index' => ['type' => 'integer'],
                'ranking_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'confidence_notes' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recommendationSchema(): array
    {
        return [
            'type' => 'object',
            'required' => [
                'summary',
                'sample_size_warning',
                'recommended_next_actions',
                'persona_adjustments',
                'prompt_adjustment_notes',
                'confidence_notes',
            ],
            'properties' => [
                'summary' => ['type' => 'string'],
                'sample_size_warning' => ['type' => 'string'],
                'recommended_next_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'persona_adjustments' => ['type' => 'array', 'items' => ['type' => 'string']],
                'prompt_adjustment_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'confidence_notes' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function fallbackPayload(string $stage, array $input): array
    {
        $replyMax = max(80, (int) ($input['max_reply_chars'] ?? 280));

        return match ($stage) {
            'generation' => [
                'reply_candidates' => [
                    $this->fallbackCandidate('Useful Observation', 'Concise. What mattered most in the decision?', $replyMax, 86, 84, 82, 90, 88, 7),
                    $this->fallbackCandidate('Thoughtful Question', 'What changed the result most?', $replyMax, 85, 83, 80, 89, 86, 6),
                    $this->fallbackCandidate('Measured Counterpoint', 'There is probably more to learn once the next step lands.', $replyMax, 82, 82, 84, 87, 85, 8),
                ],
            ],
            'scoring' => [
                'ranked_candidate_indices' => [1, 2, 3],
                'top_choice_index' => 1,
                'ranking_notes' => ['Fallback ranking applied because validation failed.'],
                'confidence_notes' => 'Low-confidence fallback.',
            ],
            'recommendations' => [
                'summary' => 'Use the safest clearly relevant reply and keep the rest as alternates.',
                'sample_size_warning' => 'Fallback recommendations are conservative.',
                'recommended_next_actions' => ['Review the selected candidate.', 'Record the publication decision.', 'Capture performance later.'],
                'persona_adjustments' => ['Keep the tone measured.'],
                'prompt_adjustment_notes' => ['Tighten the stage prompt if this repeats.'],
                'confidence_notes' => 'Fallback response used after validation failed.',
            ],
            default => [
                'main_topic' => 'general commentary',
                'secondary_topics' => ['general context'],
                'tone' => 'measured',
                'sentiment' => 'neutral',
                'likely_intent' => 'seek_conversation',
                'important_context' => ['Fallback analysis used after validation failed.'],
                'conversation_angles' => ['Offer a useful observation.'],
                'factual_claims_requiring_caution' => ['Verify claims before publishing.'],
                'safety_or_reputation_risks' => ['Avoid overclaiming certainty.'],
                'recommended_reply_approach' => 'Lead with a practical observation.',
                'confidence_notes' => 'Fallback response used after validation failed.',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackCandidate(
        string $label,
        string $reply,
        int $limit,
        float $relevance,
        float $naturalness,
        float $originality,
        float $toneMatch,
        float $conversation,
        float $risk
    ): array {
        $reply = trim(mb_substr($reply, 0, $limit));
        return [
            'reply_text' => $reply,
            'approach_label' => $label,
            'short_rationale' => 'Fallback candidate.',
            'relevance_score' => $relevance,
            'naturalness_score' => $naturalness,
            'originality_score' => $originality,
            'tone_match_score' => $toneMatch,
            'conversation_potential_score' => $conversation,
            'risk_score' => $risk,
            'total_score' => $relevance + $naturalness + $originality + $toneMatch + $conversation - $risk,
            'character_count' => mb_strlen($reply),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderTemplate(string $template, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{{' . $key . '}}'] = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return strtr($template, $replacements);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }

        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
