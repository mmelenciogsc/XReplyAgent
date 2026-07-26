<?php

declare(strict_types=1);

namespace XReplyAgent\Domain;

use XReplyAgent\Domain\BrowserAutomation;
use XReplyAgent\AI\ProviderFactory;
use XReplyAgent\AI\Service;
use XReplyAgent\Storage\Store;

final class Workflow
{
    /**
     * Backwards-compatible alias.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function submitQuery(array $payload): array
    {
        return self::processSubmission($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function submitPostAnalysis(array $payload): array
    {
        return self::processSubmission($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function processSubmission(array $payload): array
    {
        $userId = get_current_user_id();
        $providerSettings = ProviderFactory::settings();
        $rateLimit = RateLimiter::consume('xra_ai', $userId, (string) ($payload['session_key'] ?? ''));
        if (empty($rateLimit['allowed'])) {
            return [
                'ok' => false,
                'error' => 'Daily AI limit reached.',
                'rate_limit' => $rateLimit,
            ];
        }

        $postText = trim((string) ($payload['post_text'] ?? ''));
        $sourceUrl = trim((string) ($payload['source_url'] ?? ''));
        $contextText = trim((string) ($payload['context_text'] ?? ''));
        $objective = trim((string) ($payload['desired_objective'] ?? $payload['objective'] ?? ''));
        $authorHandle = sanitize_text_field((string) ($payload['author_handle'] ?? ''));
        $authorName = sanitize_text_field((string) ($payload['author_name'] ?? ''));
        $personaId = (int) ($payload['persona_id'] ?? 0);
        $maxPostChars = max(500, (int) ($providerSettings['max_post_chars'] ?? 8000));
        $maxContextChars = max(250, (int) ($providerSettings['max_context_chars'] ?? 4000));
        $maxReplyChars = max(80, (int) ($providerSettings['max_reply_chars'] ?? 280));

        $postText = trim(mb_substr($postText, 0, $maxPostChars));
        $contextText = trim(mb_substr($contextText, 0, $maxContextChars));

        if ($postText === '') {
            Store::saveErrorLog('workflow', 'missing_post_text', 'Post text is required.', [
                'persona_id' => $personaId,
            ], 'warning', $userId);
            return ['ok' => false, 'error' => 'Post text is required.'];
        }

        if ($personaId <= 0) {
            Store::saveErrorLog('workflow', 'missing_persona', 'A persona is required.', [], 'warning', $userId);
            return ['ok' => false, 'error' => 'A persona is required.'];
        }

        $persona = Store::getPersona($personaId);
        if ($persona === []) {
            Store::saveErrorLog('workflow', 'persona_not_found', 'Persona not found.', [
                'persona_id' => $personaId,
            ], 'warning', $userId);
            return ['ok' => false, 'error' => 'Persona not found.'];
        }

        $postId = Store::savePost([
            'platform' => sanitize_text_field((string) ($payload['platform'] ?? 'x')),
            'external_post_id' => sanitize_text_field((string) ($payload['external_post_id'] ?? '')),
            'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
            'post_text' => $postText,
            'context_text' => $contextText !== '' ? $contextText : null,
            'desired_objective' => $objective,
            'author_handle' => $authorHandle,
            'author_name' => $authorName,
            'language' => sanitize_text_field((string) ($payload['language'] ?? 'en')),
            'status' => 'submitted',
            'created_by' => $userId,
            'metadata_json' => [
                'post_url' => $sourceUrl,
                'objective' => $objective,
            ],
        ]);

        $post = Store::getPost($postId);

        $prompts = Store::activePrompts();
        $provider = ProviderFactory::make();
        $service = new Service($provider);
        $topicHint = self::topicHint($postText, $objective);

        $analysisStage = $service->respond([
            'stage' => 'analysis',
            'post_id' => $postId,
            'post_content' => $postText,
            'platform' => (string) ($post['platform'] ?? 'x'),
            'author_handle' => $authorHandle,
            'objective' => $objective,
            'topic_hint' => $topicHint,
            'message' => $postText,
        ], $prompts['analysis'], $persona);
        $analysisPayload = (array) ($analysisStage['payload'] ?? []);
        $analysisContext = array_values(array_filter(array_merge(
            array_map('strval', (array) ($analysisPayload['important_context'] ?? [])),
            $sourceUrl !== '' ? ['Source URL: ' . $sourceUrl] : [],
            $objective !== '' ? ['Objective: ' . $objective] : [],
            $authorHandle !== '' ? ['Author handle: ' . $authorHandle] : [],
            $authorName !== '' ? ['Author name: ' . $authorName] : []
        )));

        $analysisId = Store::saveAnalysis([
            'post_id' => $postId,
            'persona_id' => $personaId,
            'prompt_version_id' => (int) ($prompts['analysis']['id'] ?? 0),
            'ai_request_id' => 0,
            'main_topic' => (string) ($analysisPayload['main_topic'] ?? $topicHint),
            'secondary_topics' => array_values(array_map('strval', (array) ($analysisPayload['secondary_topics'] ?? []))),
            'tone' => (string) ($analysisPayload['tone'] ?? ''),
            'sentiment' => (string) ($analysisPayload['sentiment'] ?? ''),
            'likely_intent' => (string) ($analysisPayload['likely_intent'] ?? $objective),
            'important_context' => $analysisContext,
            'conversation_angles' => array_values(array_map('strval', (array) ($analysisPayload['conversation_angles'] ?? []))),
            'factual_claims_requiring_caution' => array_values(array_map('strval', (array) ($analysisPayload['factual_claims_requiring_caution'] ?? []))),
            'safety_or_reputation_risks' => array_values(array_map('strval', (array) ($analysisPayload['safety_or_reputation_risks'] ?? []))),
            'recommended_reply_approach' => (string) ($analysisPayload['recommended_reply_approach'] ?? 'Use a concise, useful reply.'),
            'confidence_notes' => (string) ($analysisPayload['confidence_notes'] ?? ''),
            'raw_output' => $analysisStage['raw'] ?? '',
            'repair_attempted' => !empty($analysisStage['repair_attempted']),
        ]);

        self::saveStageRequest('analysis', $analysisStage, [
            'post_id' => $postId,
            'analysis_id' => $analysisId,
            'reply_set_id' => 0,
            'prompt_version_id' => (int) ($prompts['analysis']['id'] ?? 0),
            'persona_id' => $personaId,
            'request_json' => [
                'post_text' => $postText,
                'source_url' => $sourceUrl,
                'context_text' => $contextText,
                'desired_objective' => $objective,
            ],
            'model' => (string) ($providerSettings['model'] ?? 'gpt-4o-mini'),
        ]);

        $generationStage = $service->respond([
            'stage' => 'generation',
            'post_id' => $postId,
            'post_content' => $postText,
            'platform' => (string) ($post['platform'] ?? 'x'),
            'author_handle' => $authorHandle,
            'objective' => $objective,
            'topic_hint' => $topicHint,
            'analysis_payload' => $analysisPayload,
            'analysis_summary' => (string) ($analysisPayload['recommended_reply_approach'] ?? ''),
            'analysis_json' => $analysisPayload,
            'max_reply_chars' => $maxReplyChars,
            'message' => $postText,
        ], $prompts['generation'], $persona);

        $generationPayload = (array) ($generationStage['payload'] ?? []);
        $generationCandidates = self::normalizeGenerationCandidates((array) ($generationPayload['reply_candidates'] ?? []), $maxReplyChars);

        $scoringStage = $service->respond([
            'stage' => 'scoring',
            'post_id' => $postId,
            'post_content' => $postText,
            'platform' => (string) ($post['platform'] ?? 'x'),
            'author_handle' => $authorHandle,
            'objective' => $objective,
            'topic_hint' => $topicHint,
            'analysis_payload' => $analysisPayload,
            'reply_candidates' => $generationCandidates,
            'analysis_json' => $analysisPayload,
            'reply_candidates_json' => $generationCandidates,
            'message' => $postText,
        ], $prompts['scoring'], $persona);

        $scoringPayload = (array) ($scoringStage['payload'] ?? []);
        $rankedCandidates = self::rankCandidates(
            $generationCandidates,
            array_map('intval', (array) ($scoringPayload['ranked_candidate_indices'] ?? [1, 2, 3]))
        );
        while (count($rankedCandidates) < 3) {
            $rankedCandidates[] = self::fallbackCandidate(count($rankedCandidates) + 1, $topicHint);
        }
        $rankedCandidates = array_slice($rankedCandidates, 0, 3);

        $metricsSummary = Store::metricsSummary();
        $recommendationsStage = $service->respond([
            'stage' => 'recommendations',
            'post_id' => $postId,
            'post_content' => $postText,
            'platform' => (string) ($post['platform'] ?? 'x'),
            'author_handle' => $authorHandle,
            'objective' => $objective,
            'topic_hint' => $topicHint,
            'analysis_payload' => $analysisPayload,
            'reply_candidates' => $generationCandidates,
            'ranked_candidates' => $rankedCandidates,
            'analysis_json' => $analysisPayload,
            'reply_candidates_json' => $rankedCandidates,
            'metrics_json' => $metricsSummary,
            'sample_size' => (int) ($metricsSummary['count'] ?? 0),
            'message' => $postText,
        ], $prompts['recommendations'], $persona);

        $replySetId = Store::saveReplySet([
            'post_id' => $postId,
            'analysis_id' => $analysisId,
            'persona_id' => $personaId,
            'analysis_prompt_version_id' => (int) ($prompts['analysis']['id'] ?? 0),
            'generation_prompt_version_id' => (int) ($prompts['generation']['id'] ?? 0),
            'scoring_prompt_version_id' => (int) ($prompts['scoring']['id'] ?? 0),
            'recommendation_prompt_version_id' => (int) ($prompts['recommendations']['id'] ?? 0),
            'status' => 'review_queue',
            'rank_summary' => [
                'ranked_candidate_indices' => [1, 2, 3],
                'top_choice_index' => (int) ($scoringPayload['top_choice_index'] ?? 1),
                'ranking_notes' => (array) ($scoringPayload['ranking_notes'] ?? []),
            ],
            'recommendations' => $recommendationsStage['payload'] ?? [],
            'selected_candidate_id' => 0,
        ]);

        $candidateIds = Store::saveReplyCandidates($replySetId, self::candidatesForStorage($rankedCandidates));
        $selectedIndex = max(1, min(3, (int) ($scoringPayload['top_choice_index'] ?? 1)));
        $selectedCandidateId = $candidateIds[$selectedIndex - 1] ?? 0;
        if ($selectedCandidateId > 0) {
            Store::updateReplyCandidate($selectedCandidateId, [
                'status' => 'selected',
            ]);
            Store::saveReplySet([
                'id' => $replySetId,
                'post_id' => $postId,
                'analysis_id' => $analysisId,
                'persona_id' => $personaId,
                'analysis_prompt_version_id' => (int) ($prompts['analysis']['id'] ?? 0),
                'generation_prompt_version_id' => (int) ($prompts['generation']['id'] ?? 0),
                'scoring_prompt_version_id' => (int) ($prompts['scoring']['id'] ?? 0),
                'recommendation_prompt_version_id' => (int) ($prompts['recommendations']['id'] ?? 0),
                'status' => 'review_queue',
                'selected_candidate_id' => $selectedCandidateId,
                'rank_summary' => [
                    'ranked_candidate_indices' => [1, 2, 3],
                    'top_choice_index' => $selectedIndex,
                    'ranking_notes' => (array) ($scoringPayload['ranking_notes'] ?? []),
                ],
                'recommendations' => $recommendationsStage['payload'] ?? [],
            ]);
        }

        self::saveStageRequest('generation', $generationStage, [
            'post_id' => $postId,
            'analysis_id' => $analysisId,
            'reply_set_id' => $replySetId,
            'prompt_version_id' => (int) ($prompts['generation']['id'] ?? 0),
            'persona_id' => $personaId,
            'request_json' => [
                'post_text' => $postText,
                'objective' => $objective,
                'analysis_payload' => $analysisPayload,
            ],
            'model' => (string) ($providerSettings['model'] ?? 'gpt-4o-mini'),
        ]);

        self::saveStageRequest('scoring', $scoringStage, [
            'post_id' => $postId,
            'analysis_id' => $analysisId,
            'reply_set_id' => $replySetId,
            'prompt_version_id' => (int) ($prompts['scoring']['id'] ?? 0),
            'persona_id' => $personaId,
            'request_json' => [
                'post_text' => $postText,
                'objective' => $objective,
                'reply_candidates' => $generationCandidates,
            ],
            'model' => (string) ($providerSettings['model'] ?? 'gpt-4o-mini'),
        ]);

        self::saveStageRequest('recommendations', $recommendationsStage, [
            'post_id' => $postId,
            'analysis_id' => $analysisId,
            'reply_set_id' => $replySetId,
            'prompt_version_id' => (int) ($prompts['recommendations']['id'] ?? 0),
            'persona_id' => $personaId,
            'request_json' => [
                'post_text' => $postText,
                'objective' => $objective,
                'ranked_candidates' => $scoringPayload['ranked_candidates'] ?? [],
            ],
            'model' => (string) ($providerSettings['model'] ?? 'gpt-4o-mini'),
        ]);

        Store::saveAudit(
            'analyze_post',
            'post',
            $postId,
            'Post analyzed and reply candidates generated.',
            [
                'analysis_id' => $analysisId,
                'reply_set_id' => $replySetId,
                'selected_candidate_id' => $selectedCandidateId,
                'provider' => $analysisStage['provider'] ?? '',
            ],
            'info',
            $userId
        );

        return [
            'ok' => true,
            'post_id' => $postId,
            'analysis_id' => $analysisId,
            'reply_set_id' => $replySetId,
            'persona_id' => $personaId,
            'provider' => $analysisStage['provider'] ?? 'mock',
            'rate_limit' => $rateLimit,
            'post' => Store::getPost($postId),
            'analysis' => Store::getAnalysis($analysisId),
            'reply_set' => Store::getReplySet($replySetId),
            'reply_candidates' => $rankedCandidates,
            'recommendations' => $recommendationsStage['payload'] ?? [],
            'analytics' => Store::analyticsSummary(),
            'stages' => [
                'analysis' => $analysisStage,
                'generation' => $generationStage,
                'scoring' => $scoringStage,
                'recommendations' => $recommendationsStage,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function reviewReplySet(array $payload): array
    {
        $replySetId = (int) ($payload['reply_set_id'] ?? 0);
        $candidateId = (int) ($payload['candidate_id'] ?? 0);
        $action = sanitize_key((string) ($payload['action'] ?? 'approve'));
        $notes = trim((string) ($payload['notes'] ?? ''));

        if ($replySetId <= 0 || $candidateId <= 0) {
            Store::saveErrorLog('workflow', 'invalid_review_payload', 'Reply set and candidate IDs are required.', [
                'reply_set_id' => $replySetId,
                'candidate_id' => $candidateId,
                'action' => $action,
            ], 'warning', get_current_user_id());
            return ['ok' => false, 'error' => 'Reply set and candidate IDs are required.'];
        }

        $replySet = Store::getReplySet($replySetId);
        if ($replySet === []) {
            Store::saveErrorLog('workflow', 'reply_set_not_found', 'Reply set not found.', [
                'reply_set_id' => $replySetId,
                'candidate_id' => $candidateId,
            ], 'warning', get_current_user_id());
            return ['ok' => false, 'error' => 'Reply set not found.'];
        }

        if ($action === 'save' || $action === 'edit') {
            $ok = Store::updateReplyCandidate($candidateId, [
                'reply_text' => (string) ($payload['reply_text'] ?? ''),
                'edited_text' => (string) ($payload['reply_text'] ?? ''),
                'reviewer_notes' => $notes,
                'status' => 'edited',
            ]);
            if (!$ok) {
                Store::saveErrorLog('workflow', 'candidate_edit_failed', 'Unable to edit reply candidate.', [
                    'reply_set_id' => $replySetId,
                    'candidate_id' => $candidateId,
                ], 'warning', get_current_user_id());
                return ['ok' => false, 'error' => 'Unable to edit reply candidate.'];
            }

            Store::saveAudit('edit_candidate', 'reply_candidate', $candidateId, 'Reply candidate edited.', ['reply_set_id' => $replySetId], 'info', get_current_user_id());
            return ['ok' => true, 'reply_set_id' => $replySetId, 'candidate_id' => $candidateId, 'status' => 'edited'];
        }

        if ($action === 'copy') {
            Store::setCandidateStatus($candidateId, 'copied', get_current_user_id(), $notes);
            Store::saveAudit('copy_candidate', 'reply_candidate', $candidateId, 'Reply candidate copied.', ['reply_set_id' => $replySetId], 'info', get_current_user_id());
            return ['ok' => true, 'reply_set_id' => $replySetId, 'candidate_id' => $candidateId];
        }

        if ($action === 'regenerate') {
            $post = Store::getPost((int) ($replySet['post_id'] ?? 0));
            if ($post === []) {
                Store::saveErrorLog('workflow', 'post_not_found', 'Post not found for regeneration.', [
                    'reply_set_id' => $replySetId,
                ], 'warning', get_current_user_id());
                return ['ok' => false, 'error' => 'Post not found for regeneration.'];
            }

            return self::processSubmission([
                'post_text' => (string) ($post['post_text'] ?? ''),
                'source_url' => (string) ($post['source_url'] ?? ''),
                'context_text' => (string) ($post['context_text'] ?? ''),
                'desired_objective' => (string) ($post['desired_objective'] ?? ''),
                'author_handle' => (string) ($post['author_handle'] ?? ''),
                'author_name' => (string) ($post['author_name'] ?? ''),
                'persona_id' => (int) ($replySet['persona_id'] ?? 0),
                'platform' => (string) ($post['platform'] ?? 'x'),
                'external_post_id' => (string) ($post['external_post_id'] ?? ''),
                'language' => (string) ($post['language'] ?? 'en'),
            ]);
        }

        if ($action === 'publish') {
            $ok = Store::saveReplySet([
                'id' => $replySetId,
                'post_id' => (int) ($replySet['post_id'] ?? 0),
                'analysis_id' => (int) ($replySet['analysis_id'] ?? 0),
                'persona_id' => (int) ($replySet['persona_id'] ?? 0),
                'analysis_prompt_version_id' => (int) ($replySet['analysis_prompt_version_id'] ?? 0),
                'generation_prompt_version_id' => (int) ($replySet['generation_prompt_version_id'] ?? 0),
                'scoring_prompt_version_id' => (int) ($replySet['scoring_prompt_version_id'] ?? 0),
                'recommendation_prompt_version_id' => (int) ($replySet['recommendation_prompt_version_id'] ?? 0),
                'status' => 'publish_ready',
                'selected_candidate_id' => $candidateId,
                'reviewer_user_id' => get_current_user_id(),
                'reviewer_notes' => $notes,
                'browser_status' => 'ready',
                'rank_summary' => $replySet['rank_summary_json'] ?? [],
                'recommendations' => $replySet['recommendations_json'] ?? [],
            ]);
            Store::setCandidateStatus($candidateId, 'for_publishing', get_current_user_id(), $notes);
            Store::saveAudit(
                'queue_publish_candidate',
                'reply_candidate',
                $candidateId,
                'Reply candidate queued for browser publishing.',
                ['reply_set_id' => $replySetId],
                'info',
                get_current_user_id()
            );

            return ['ok' => $ok > 0, 'reply_set_id' => $replySetId, 'candidate_id' => $candidateId, 'status' => 'for_publishing'];
        }

        $status = $action === 'reject' ? 'rejected' : 'approved';
        Store::setCandidateStatus($candidateId, $status, get_current_user_id(), $notes);
        Store::saveReplySet([
            'id' => $replySetId,
            'post_id' => (int) ($replySet['post_id'] ?? 0),
            'analysis_id' => (int) ($replySet['analysis_id'] ?? 0),
            'persona_id' => (int) ($replySet['persona_id'] ?? 0),
            'analysis_prompt_version_id' => (int) ($replySet['analysis_prompt_version_id'] ?? 0),
            'generation_prompt_version_id' => (int) ($replySet['generation_prompt_version_id'] ?? 0),
            'scoring_prompt_version_id' => (int) ($replySet['scoring_prompt_version_id'] ?? 0),
            'recommendation_prompt_version_id' => (int) ($replySet['recommendation_prompt_version_id'] ?? 0),
            'status' => $status === 'approved' ? 'reviewed' : 'rejected',
            'selected_candidate_id' => $candidateId,
            'reviewer_user_id' => get_current_user_id(),
            'reviewer_notes' => $notes,
            'rank_summary' => $replySet['rank_summary_json'] ?? [],
            'recommendations' => $replySet['recommendations_json'] ?? [],
        ]);
        Store::saveAudit(
            $status . '_candidate',
            'reply_candidate',
            $candidateId,
            'Reply candidate reviewed.',
            ['reply_set_id' => $replySetId, 'status' => $status],
            'info',
            get_current_user_id()
        );

        return ['ok' => true, 'reply_set_id' => $replySetId, 'candidate_id' => $candidateId, 'status' => $status];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function startBrowserPublish(array $payload): array
    {
        $replySetId = (int) ($payload['reply_set_id'] ?? 0);
        $candidateId = (int) ($payload['candidate_id'] ?? 0);
        if ($replySetId <= 0 || $candidateId <= 0) {
            return ['ok' => false, 'error' => 'Reply set and candidate are required.'];
        }

        return BrowserAutomation::startPublishAndMonitor($replySetId, $candidateId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function recordPerformanceMetric(array $payload): array
    {
        $record = [
            'post_id' => (int) ($payload['post_id'] ?? 0),
            'reply_set_id' => (int) ($payload['reply_set_id'] ?? 0),
            'candidate_id' => (int) ($payload['candidate_id'] ?? 0),
            'impressions' => (int) ($payload['impressions'] ?? 0),
            'likes' => (int) ($payload['likes'] ?? 0),
            'replies_received' => (int) ($payload['replies_received'] ?? 0),
            'reposts' => (int) ($payload['reposts'] ?? 0),
            'bookmarks' => (int) ($payload['bookmarks'] ?? 0),
            'profile_visits' => (int) ($payload['profile_visits'] ?? 0),
            'follows' => (int) ($payload['follows'] ?? 0),
            'publication_datetime' => trim((string) ($payload['publication_datetime'] ?? '')),
            'measurement_datetime' => trim((string) ($payload['measurement_datetime'] ?? '')),
            'audience_category' => trim((string) ($payload['audience_category'] ?? '')),
            'notes' => trim((string) ($payload['notes'] ?? '')),
            'created_by' => get_current_user_id(),
        ];

        $id = Store::saveMetric($record);
        Store::saveAudit('record_metric', 'performance_metric', $id, 'Performance metric recorded.', [
            'post_id' => $record['post_id'],
            'reply_set_id' => $record['reply_set_id'],
            'candidate_id' => $record['candidate_id'],
        ], 'info', get_current_user_id());
        return ['ok' => true, 'metric_id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    public static function analyticsOverview(): array
    {
        return [
            'ok' => true,
            'analytics' => Store::analyticsSummary(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function seedDemo(): array
    {
        return ['ok' => true, 'result' => Store::seedDemoData()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function resetDemo(bool $seedDefaults = true): array
    {
        return Store::resetAll($seedDefaults);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function history(array $payload): array
    {
        $postId = (int) ($payload['post_id'] ?? 0);
        $analysisId = (int) ($payload['analysis_id'] ?? 0);

        if ($analysisId > 0) {
            return ['ok' => true, 'history' => Store::getAnalysisHistory($analysisId)];
        }

        if ($postId > 0) {
            return ['ok' => true, 'history' => Store::getPostHistory($postId)];
        }

        return ['ok' => false, 'error' => 'Post ID or analysis ID is required.'];
    }

    /**
     * @param array<string, mixed> $stageResult
     * @param array<string, mixed> $meta
     */
    private static function saveStageRequest(string $stage, array $stageResult, array $meta): int
    {
        $usage = (array) ($stageResult['usage'] ?? []);
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? max(0, $promptTokens + $completionTokens));
        $costEstimate = self::estimateCost($promptTokens, $completionTokens);

        return Store::saveAIRequest([
            'stage' => $stage,
            'post_id' => (int) ($meta['post_id'] ?? 0),
            'analysis_id' => (int) ($meta['analysis_id'] ?? 0),
            'reply_set_id' => (int) ($meta['reply_set_id'] ?? 0),
            'prompt_version_id' => (int) ($meta['prompt_version_id'] ?? 0),
            'persona_id' => (int) ($meta['persona_id'] ?? 0),
            'provider' => (string) ($stageResult['provider'] ?? 'mock'),
            'model' => (string) ($meta['model'] ?? 'gpt-4o-mini'),
            'request_json' => $meta['request_json'] ?? [],
            'response_json' => ['raw' => (string) ($stageResult['raw'] ?? ''), 'payload' => $stageResult['payload'] ?? []],
            'status' => !empty($stageResult['schema_valid']) ? 'ok' : 'error',
            'repair_attempted' => !empty($stageResult['repair_attempted']),
            'latency_ms' => (int) ($stageResult['latency_ms'] ?? 0),
            'error_category' => (string) ($stageResult['error_category'] ?? (!empty($stageResult['schema_valid']) ? '' : 'schema_validation')),
            'error_message' => !empty($stageResult['schema_valid']) ? '' : implode('; ', (array) ($stageResult['validation_errors'] ?? [])),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'cost_estimate' => $costEstimate,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeGenerationCandidates(array $candidates, int $maxReplyChars = 280): array
    {
        $normalized = [];
        foreach (array_values($candidates) as $index => $candidate) {
            $replyText = trim((string) ($candidate['reply_text'] ?? ''));
            $replyText = mb_substr($replyText, 0, max(80, $maxReplyChars));
            $normalized[] = [
                'candidate_index' => $index + 1,
                'approach_label' => (string) ($candidate['approach_label'] ?? ('Reply ' . ($index + 1))),
                'reply_text' => $replyText,
                'short_rationale' => (string) ($candidate['short_rationale'] ?? ''),
                'relevance_score' => (float) ($candidate['relevance_score'] ?? 0),
                'naturalness_score' => (float) ($candidate['naturalness_score'] ?? 0),
                'originality_score' => (float) ($candidate['originality_score'] ?? 0),
                'tone_match_score' => (float) ($candidate['tone_match_score'] ?? 0),
                'conversation_potential_score' => (float) ($candidate['conversation_potential_score'] ?? 0),
                'risk_score' => (float) ($candidate['risk_score'] ?? 0),
                'total_score' => (float) ($candidate['total_score'] ?? 0),
                'character_count' => (int) ($candidate['character_count'] ?? mb_strlen($replyText)),
                'status' => (string) ($candidate['status'] ?? 'draft'),
                'edited_text' => $candidate['edited_text'] ?? null,
                'reviewer_notes' => $candidate['reviewer_notes'] ?? null,
                'similarity_group' => (string) ($candidate['similarity_group'] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $generation
     * @param array<int, int> $rankedIndices
     * @return array<int, array<string, mixed>>
     */
    private static function rankCandidates(array $generation, array $rankedIndices): array
    {
        $ordered = [];

        foreach ($rankedIndices as $index) {
            $candidate = $generation[$index - 1] ?? null;
            if (!is_array($candidate)) {
                continue;
            }
            $ordered[] = $candidate;
        }

        foreach ($generation as $candidate) {
            $text = (string) ($candidate['reply_text'] ?? '');
            if ($text === '') {
                continue;
            }
            $exists = false;
            foreach ($ordered as $existing) {
                if ((string) ($existing['reply_text'] ?? '') === $text) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $ordered[] = $candidate;
            }
        }

        return array_slice($ordered, 0, 3);
    }

    /**
     * @param array<int, array<string, mixed>> $rankedCandidates
     * @return array<int, array<string, mixed>>
     */
    private static function candidatesForStorage(array $rankedCandidates): array
    {
        $storage = [];
        foreach (array_values($rankedCandidates) as $index => $candidate) {
            $text = (string) ($candidate['reply_text'] ?? '');
            $storage[] = [
                'candidate_index' => $index + 1,
                'approach_label' => (string) ($candidate['approach_label'] ?? 'Reply'),
                'reply_text' => $text,
                'short_rationale' => (string) ($candidate['short_rationale'] ?? ''),
                'relevance_score' => (float) ($candidate['relevance_score'] ?? 0),
                'naturalness_score' => (float) ($candidate['naturalness_score'] ?? 0),
                'originality_score' => (float) ($candidate['originality_score'] ?? 0),
                'tone_match_score' => (float) ($candidate['tone_match_score'] ?? 0),
                'conversation_potential_score' => (float) ($candidate['conversation_potential_score'] ?? 0),
                'risk_score' => (float) ($candidate['risk_score'] ?? 0),
                'total_score' => (float) ($candidate['total_score'] ?? 0),
                'character_count' => (int) ($candidate['character_count'] ?? mb_strlen($text)),
                'status' => (string) ($candidate['status'] ?? 'draft'),
                'edited_text' => $candidate['edited_text'] ?? null,
                'reviewer_notes' => $candidate['reviewer_notes'] ?? null,
                'similarity_group' => (string) ($candidate['similarity_group'] ?? ''),
            ];
        }

        return $storage;
    }

    private static function fallbackCandidate(int $index, string $topic): array
    {
        $map = [
            1 => 'Thanks for sharing. Add a bit more detail and I can help further.',
            2 => 'Share the key context and I will turn this into a tighter reply.',
            3 => 'If you want a cleaner version, send the missing detail and I will refine it.',
        ];

        $text = $map[$index] ?? $map[1];

        return [
            'candidate_index' => $index,
            'approach_label' => $topic !== '' ? ucfirst($topic) : 'Reply',
            'reply_text' => $text,
            'short_rationale' => 'Fallback candidate.',
            'relevance_score' => 0,
            'naturalness_score' => 0,
            'originality_score' => 0,
            'tone_match_score' => 0,
            'conversation_potential_score' => 0,
            'risk_score' => 0,
            'total_score' => 0,
            'character_count' => mb_strlen($text),
            'status' => 'draft',
            'edited_text' => null,
            'reviewer_notes' => null,
            'similarity_group' => '',
        ];
    }

    private static function estimateCost(int $promptTokens, int $completionTokens): float
    {
        $promptRate = 0.00015 / 1000;
        $completionRate = 0.00060 / 1000;
        return round(($promptTokens * $promptRate) + ($completionTokens * $completionRate), 6);
    }

    private static function topicHint(string $postText, string $objective): string
    {
        $subject = strtolower($postText . ' ' . $objective);
        return match (true) {
            str_contains($subject, 'pricing') || str_contains($subject, 'price') => 'pricing',
            str_contains($subject, 'launch') || str_contains($subject, 'announce') => 'announcement',
            str_contains($subject, 'support') || str_contains($subject, 'help') => 'support',
            str_contains($subject, 'recommend') || str_contains($subject, 'best') => 'recommendation',
            default => 'general',
        };
    }
}
