<?php

declare(strict_types=1);

namespace XReplyAgent\Storage;

use XReplyAgent\Support\Redactor;
use XReplyAgent\Support\Schema;
use XReplyAgent\Support\Settings;
use XReplyAgent\Support\Similarity;

final class Store
{
    /**
     * @return array<string, string>
     */
    public static function tables(): array
    {
        return Schema::tables();
    }

    /**
     * @return array<string, int>
     */
    public static function summary(): array
    {
        global $wpdb;
        $tables = self::tables();
        $replySetCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['reply_sets']}");
        $approvedCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['reply_candidates']} WHERE status = %s", 'approved'));
        $publishedCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['reply_candidates']} WHERE status = %s", 'published'));

        return [
            'posts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['posts']}"),
            'analyses' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['analyses']}"),
            'reply_sets' => $replySetCount,
            'reply_candidates' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['reply_candidates']}"),
            'approved_candidates' => $approvedCount,
            'published_candidates' => $publishedCount,
            'personas' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['personas']}"),
            'prompts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['prompt_versions']}"),
            'metrics' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['performance_metrics']}"),
            'ai_requests' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['ai_requests']}"),
            'browser_jobs' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['browser_jobs']}"),
            'audit_events' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['audit_log']}"),
            'error_events' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['error_log']}"),
            'conversations' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['posts']}"),
            'runs' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['ai_requests']}"),
            'draft_publications' => max(0, $replySetCount - $approvedCount - $publishedCount),
            'published_publications' => $publishedCount,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function listPosts(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        $tables = self::tables();
        $limit = max(1, min(250, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT p.*, a.id AS analysis_row_id, a.main_topic, a.tone, a.sentiment, '
            . 'a.likely_intent, a.persona_id, a.prompt_version_id, rs.id AS reply_set_id, '
            . 'rs.status AS reply_set_status, rs.selected_candidate_id, '
            . 'COALESCE(rc.candidate_count, 0) AS candidate_count '
            . "FROM {$tables['posts']} p "
            . "LEFT JOIN {$tables['analyses']} a ON a.post_id = p.id "
            . "LEFT JOIN {$tables['reply_sets']} rs ON rs.post_id = p.id "
            . 'LEFT JOIN ( '
            . "SELECT reply_set_id, COUNT(*) AS candidate_count "
            . "FROM {$tables['reply_candidates']} "
            . 'GROUP BY reply_set_id'
            . ') rc ON rc.reply_set_id = rs.id';

        $where = [];
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(p.post_text LIKE %s OR p.author_handle LIKE %s OR p.author_name LIKE %s OR p.source_url LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'p.status = %s';
            $params[] = $status;
        }

        $tone = trim((string) ($filters['tone'] ?? ''));
        if ($tone !== '') {
            $where[] = 'a.tone = %s';
            $params[] = $tone;
        }

        $intent = trim((string) ($filters['intent'] ?? ''));
        if ($intent !== '') {
            $where[] = 'a.likely_intent = %s';
            $params[] = $intent;
        }

        $persona_id = (int) ($filters['persona_id'] ?? 0);
        if ($persona_id > 0) {
            $where[] = 'a.persona_id = %d';
            $params[] = $persona_id;
        }

        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '') {
            $where[] = 'p.created_at >= %s';
            $params[] = $from;
        }

        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '') {
            $where[] = 'p.created_at <= %s';
            $params[] = $to;
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY p.id DESC LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;

        $prepared = $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($prepared, ARRAY_A);

        return self::hydrateRows(is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPost(int $post_id): array
    {
        global $wpdb;
        $tables = self::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['posts']} WHERE id = %d LIMIT 1", $post_id),
            ARRAY_A
        );

        return is_array($row) ? self::hydrateRow($row) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function latestPost(): array
    {
        $rows = self::listPosts([], 1, 0);
        return $rows[0] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function findPostByHash(string $content_hash): array
    {
        global $wpdb;
        $tables = self::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['posts']} WHERE content_hash = %s ORDER BY id DESC LIMIT 1", $content_hash),
            ARRAY_A
        );

        return is_array($row) ? self::hydrateRow($row) : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function savePost(array $data): int
    {
        global $wpdb;
        $tables = self::tables();
        $id = (int) ($data['id'] ?? 0);
        $postText = trim((string) ($data['post_text'] ?? ''));
        $sourceUrl = trim((string) ($data['source_url'] ?? ''));
        $contentHash = hash('sha256', Similarity::normalize($postText) . '|' . strtolower($sourceUrl));
        $duplicateOf = (int) ($data['duplicate_of_post_id'] ?? 0);

        if ($duplicateOf <= 0) {
            $existing = self::findPostByHash($contentHash);
            $duplicateOf = (int) ($existing['id'] ?? 0);
            if ($duplicateOf === $id) {
                $duplicateOf = 0;
            }
        }

        $record = [
            'platform' => sanitize_text_field((string) ($data['platform'] ?? 'x')),
            'external_post_id' => sanitize_text_field((string) ($data['external_post_id'] ?? '')),
            'source_url' => $sourceUrl !== '' ? esc_url_raw($sourceUrl) : null,
            'content_hash' => $contentHash,
            'post_text' => $postText,
            'context_text' => trim((string) ($data['context_text'] ?? '')) !== '' ? wp_kses_post((string) $data['context_text']) : null,
            'desired_objective' => sanitize_text_field((string) ($data['desired_objective'] ?? '')),
            'author_handle' => sanitize_text_field((string) ($data['author_handle'] ?? '')),
            'author_name' => sanitize_text_field((string) ($data['author_name'] ?? '')),
            'language' => sanitize_text_field((string) ($data['language'] ?? 'en')),
            'status' => sanitize_text_field((string) ($data['status'] ?? 'draft')),
            'duplicate_of_post_id' => $duplicateOf,
            'metadata_json' => self::jsonOrNull($data['metadata_json'] ?? null),
            'created_by' => (int) ($data['created_by'] ?? get_current_user_id()),
            'updated_at' => current_time('mysql'),
        ];

        if ($id > 0) {
            $wpdb->update(
                $tables['posts'],
                $record,
                ['id' => $id],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'],
                ['%d']
            );

            return $id;
        }

        $wpdb->insert(
            $tables['posts'],
            $record + ['created_at' => current_time('mysql')],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function updatePost(int $post_id, array $data): bool
    {
        if ($post_id <= 0) {
            return false;
        }

        $data['id'] = $post_id;
        return self::savePost($data) === $post_id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listAnalyses(int $limit = 50): array
    {
        global $wpdb;
        $tables = self::tables();
        $limit = max(1, min(250, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.*, p.post_text, p.source_url, p.status AS post_status, p.created_at AS post_created_at, per.name AS persona_name
                 FROM {$tables['analyses']} a
                 LEFT JOIN {$tables['posts']} p ON p.id = a.post_id
                 LEFT JOIN {$tables['personas']} per ON per.id = a.persona_id
                 ORDER BY a.id DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getAnalysis(int $analysis_id): array
    {
        global $wpdb;
        $tables = self::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['analyses']} WHERE id = %d LIMIT 1", $analysis_id),
            ARRAY_A
        );

        return is_array($row) ? self::hydrateJsonFields($row, [
            'secondary_topics_json',
            'important_context_json',
            'conversation_angles_json',
            'factual_claims_requiring_caution_json',
            'safety_or_reputation_risks_json',
            'raw_output_json',
        ]) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getAnalysisHistory(int $analysis_id): array
    {
        $analysis = self::getAnalysis($analysis_id);
        if ($analysis === []) {
            return [];
        }

        return [
            [
                'type' => 'analysis',
                'id' => (int) ($analysis['id'] ?? 0),
                'post_id' => (int) ($analysis['post_id'] ?? 0),
                'persona_id' => (int) ($analysis['persona_id'] ?? 0),
                'main_topic' => (string) ($analysis['main_topic'] ?? ''),
                'tone' => (string) ($analysis['tone'] ?? ''),
                'created_at' => (string) ($analysis['created_at'] ?? ''),
                'updated_at' => (string) ($analysis['updated_at'] ?? ''),
                'payload' => $analysis,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function saveAnalysis(array $data): int
    {
        global $wpdb;
        $tables = self::tables();
        $id = (int) ($data['id'] ?? 0);
        $record = [
            'post_id' => (int) ($data['post_id'] ?? 0),
            'persona_id' => (int) ($data['persona_id'] ?? 0),
            'prompt_version_id' => (int) ($data['prompt_version_id'] ?? 0),
            'ai_request_id' => (int) ($data['ai_request_id'] ?? 0),
            'main_topic' => sanitize_text_field((string) ($data['main_topic'] ?? '')),
            'secondary_topics_json' => self::jsonOrNull($data['secondary_topics'] ?? null),
            'tone' => sanitize_text_field((string) ($data['tone'] ?? '')),
            'sentiment' => sanitize_text_field((string) ($data['sentiment'] ?? '')),
            'likely_intent' => sanitize_text_field((string) ($data['likely_intent'] ?? '')),
            'important_context_json' => self::jsonOrNull($data['important_context'] ?? null),
            'conversation_angles_json' => self::jsonOrNull($data['conversation_angles'] ?? null),
            'factual_claims_requiring_caution_json' => self::jsonOrNull($data['factual_claims_requiring_caution'] ?? null),
            'safety_or_reputation_risks_json' => self::jsonOrNull($data['safety_or_reputation_risks'] ?? null),
            'recommended_reply_approach' => wp_kses_post((string) ($data['recommended_reply_approach'] ?? '')),
            'confidence_notes' => wp_kses_post((string) ($data['confidence_notes'] ?? '')),
            'raw_output_json' => self::jsonOrNull($data['raw_output'] ?? null),
            'repair_attempted' => !empty($data['repair_attempted']) ? 1 : 0,
            'updated_at' => current_time('mysql'),
        ];

        if ($id > 0) {
            $wpdb->update(
                $tables['analyses'],
                $record,
                ['id' => $id],
                ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'],
                ['%d']
            );

            return $id;
        }

        $wpdb->insert(
            $tables['analyses'],
            $record + ['created_at' => current_time('mysql')],
            ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function saveReplySet(array $data): int
    {
        global $wpdb;
        $tables = self::tables();
        $id = (int) ($data['id'] ?? 0);
        $existing = $id > 0 ? self::getReplySet($id) : [];
        $source = array_merge(
            [
                'post_id' => (int) ($existing['post_id'] ?? 0),
                'analysis_id' => (int) ($existing['analysis_id'] ?? 0),
                'persona_id' => (int) ($existing['persona_id'] ?? 0),
                'analysis_prompt_version_id' => (int) ($existing['analysis_prompt_version_id'] ?? 0),
                'generation_prompt_version_id' => (int) ($existing['generation_prompt_version_id'] ?? 0),
                'scoring_prompt_version_id' => (int) ($existing['scoring_prompt_version_id'] ?? 0),
                'recommendation_prompt_version_id' => (int) ($existing['recommendation_prompt_version_id'] ?? 0),
                'ai_request_id' => (int) ($existing['ai_request_id'] ?? 0),
                'rank_summary' => $existing['rank_summary_json'] ?? null,
                'recommendations' => $existing['recommendations_json'] ?? null,
                'status' => (string) ($existing['status'] ?? 'generated'),
                'selected_candidate_id' => (int) ($existing['selected_candidate_id'] ?? 0),
                'reviewer_user_id' => (int) ($existing['reviewer_user_id'] ?? 0),
                'reviewer_notes' => (string) ($existing['reviewer_notes'] ?? ''),
                'published_at' => (string) ($existing['published_at'] ?? ''),
                'published_url' => (string) ($existing['published_url'] ?? ''),
                'publish_job_id' => (int) ($existing['publish_job_id'] ?? 0),
                'monitoring_job_id' => (int) ($existing['monitoring_job_id'] ?? 0),
                'browser_status' => (string) ($existing['browser_status'] ?? 'idle'),
            ],
            $data
        );
        $record = [
            'post_id' => (int) ($source['post_id'] ?? 0),
            'analysis_id' => (int) ($source['analysis_id'] ?? 0),
            'persona_id' => (int) ($source['persona_id'] ?? 0),
            'analysis_prompt_version_id' => (int) ($source['analysis_prompt_version_id'] ?? 0),
            'generation_prompt_version_id' => (int) ($source['generation_prompt_version_id'] ?? 0),
            'scoring_prompt_version_id' => (int) ($source['scoring_prompt_version_id'] ?? 0),
            'recommendation_prompt_version_id' => (int) ($source['recommendation_prompt_version_id'] ?? 0),
            'ai_request_id' => (int) ($source['ai_request_id'] ?? 0),
            'rank_summary_json' => self::jsonOrNull($source['rank_summary'] ?? null),
            'recommendations_json' => self::jsonOrNull($source['recommendations'] ?? null),
            'status' => sanitize_text_field((string) ($source['status'] ?? 'generated')),
            'selected_candidate_id' => (int) ($source['selected_candidate_id'] ?? 0),
            'reviewer_user_id' => (int) ($source['reviewer_user_id'] ?? 0),
            'reviewer_notes' => wp_kses_post((string) ($source['reviewer_notes'] ?? '')),
            'published_at' => !empty($source['published_at']) ? sanitize_text_field((string) $source['published_at']) : null,
            'published_url' => !empty($source['published_url']) ? esc_url_raw((string) $source['published_url']) : null,
            'publish_job_id' => (int) ($source['publish_job_id'] ?? 0),
            'monitoring_job_id' => (int) ($source['monitoring_job_id'] ?? 0),
            'browser_status' => sanitize_text_field((string) ($source['browser_status'] ?? 'idle')),
            'updated_at' => current_time('mysql'),
        ];

        if ($id > 0) {
            $wpdb->update(
                $tables['reply_sets'],
                $record,
                ['id' => $id],
                ['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s'],
                ['%d']
            );

            return $id;
        }

        $wpdb->insert(
            $tables['reply_sets'],
            $record + ['created_at' => current_time('mysql')],
            ['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getPostHistory(int $post_id): array
    {
        $post = self::getPost($post_id);
        if ($post === []) {
            return [];
        }

        $analysis = [];
        foreach (self::listAnalyses(100) as $analysisRow) {
            if ((int) ($analysisRow['post_id'] ?? 0) === $post_id) {
                $analysis = $analysisRow;
                break;
            }
        }

        $replySet = [];
        foreach (self::listReplySets([], 100) as $replySetRow) {
            if ((int) ($replySetRow['post_id'] ?? 0) === $post_id) {
                $replySet = $replySetRow;
                break;
            }
        }

        return [
            [
                'type' => 'post',
                'id' => (int) ($post['id'] ?? 0),
                'status' => (string) ($post['status'] ?? ''),
                'created_at' => (string) ($post['created_at'] ?? ''),
                'updated_at' => (string) ($post['updated_at'] ?? ''),
                'payload' => $post,
            ],
            [
                'type' => 'analysis',
                'payload' => $analysis,
            ],
            [
                'type' => 'reply_set',
                'payload' => $replySet,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getReplySet(int $reply_set_id): array
    {
        global $wpdb;
        $tables = self::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['reply_sets']} WHERE id = %d LIMIT 1", $reply_set_id),
            ARRAY_A
        );

        if (!is_array($row)) {
            return [];
        }

        return self::hydrateJsonFields($row, ['rank_summary_json', 'recommendations_json']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function latestReplySets(int $limit = 10): array
    {
        return self::listReplySets([], $limit);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function listReplySets(array $filters = [], int $limit = 50): array
    {
        global $wpdb;
        $tables = self::tables();
        $limit = max(1, min(250, $limit));

        $sql = 'SELECT rs.*, p.post_text, p.source_url, a.main_topic, a.tone, a.sentiment, '
            . 'a.likely_intent, per.name AS persona_name, per.tone AS persona_tone, '
            . 'COALESCE(rc.candidate_count, 0) AS candidate_count '
            . "FROM {$tables['reply_sets']} rs "
            . "LEFT JOIN {$tables['posts']} p ON p.id = rs.post_id "
            . "LEFT JOIN {$tables['analyses']} a ON a.id = rs.analysis_id "
            . "LEFT JOIN {$tables['personas']} per ON per.id = rs.persona_id "
            . 'LEFT JOIN ( '
            . "SELECT reply_set_id, COUNT(*) AS candidate_count "
            . "FROM {$tables['reply_candidates']} "
            . 'GROUP BY reply_set_id'
            . ') rc ON rc.reply_set_id = rs.id';

        $where = [];
        $params = [];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'rs.status = %s';
            $params[] = $status;
        }

        $persona_id = (int) ($filters['persona_id'] ?? 0);
        if ($persona_id > 0) {
            $where[] = 'rs.persona_id = %d';
            $params[] = $persona_id;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(p.post_text LIKE %s OR a.main_topic LIKE %s OR per.name LIKE %s)';
            array_push($params, $like, $like, $like);
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY rs.id DESC LIMIT %d';
        $params[] = $limit;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return self::hydrateRows(is_array($rows) ? $rows : []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listReplyCandidates(int $reply_set_id): array
    {
        global $wpdb;
        $tables = self::tables();
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$tables['reply_candidates']} WHERE reply_set_id = %d ORDER BY candidate_index ASC, id ASC", $reply_set_id),
            ARRAY_A
        );

        return self::hydrateRows(is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getReplyCandidate(int $candidate_id): array
    {
        global $wpdb;
        $tables = self::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['reply_candidates']} WHERE id = %d LIMIT 1", $candidate_id),
            ARRAY_A
        );

        return is_array($row) ? self::hydrateRow($row) : [];
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, int>
     */
    public static function saveReplyCandidates(int $reply_set_id, array $candidates): array
    {
        global $wpdb;
        $tables = self::tables();
        $ids = [];

        foreach ($candidates as $candidate) {
            $candidateId = (int) ($candidate['id'] ?? 0);
            $record = [
                'reply_set_id' => $reply_set_id,
                'candidate_index' => (int) ($candidate['candidate_index'] ?? 0),
                'approach_label' => sanitize_text_field((string) ($candidate['approach_label'] ?? '')),
                'reply_text' => wp_kses_post((string) ($candidate['reply_text'] ?? '')),
                'short_rationale' => wp_kses_post((string) ($candidate['short_rationale'] ?? '')),
                'relevance_score' => (float) ($candidate['relevance_score'] ?? 0),
                'naturalness_score' => (float) ($candidate['naturalness_score'] ?? 0),
                'originality_score' => (float) ($candidate['originality_score'] ?? 0),
                'tone_match_score' => (float) ($candidate['tone_match_score'] ?? 0),
                'conversation_potential_score' => (float) ($candidate['conversation_potential_score'] ?? 0),
                'risk_score' => (float) ($candidate['risk_score'] ?? 0),
                'total_score' => (float) ($candidate['total_score'] ?? 0),
                'character_count' => (int) ($candidate['character_count'] ?? mb_strlen((string) ($candidate['reply_text'] ?? ''))),
                'status' => sanitize_text_field((string) ($candidate['status'] ?? 'draft')),
                'edited_text' => isset($candidate['edited_text']) ? wp_kses_post((string) $candidate['edited_text']) : null,
                'reviewer_notes' => isset($candidate['reviewer_notes']) ? wp_kses_post((string) $candidate['reviewer_notes']) : null,
                'similarity_group' => sanitize_text_field((string) ($candidate['similarity_group'] ?? '')),
                'updated_at' => current_time('mysql'),
            ];

            if ($candidateId > 0) {
                $wpdb->update(
                    $tables['reply_candidates'],
                    $record,
                    ['id' => $candidateId],
                    ['%d', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%d', '%s', '%s', '%s', '%s', '%s'],
                    ['%d']
                );
                $ids[] = $candidateId;
                continue;
            }

            $wpdb->insert(
                $tables['reply_candidates'],
                $record + ['created_at' => current_time('mysql')],
                ['%d', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%d', '%s', '%s', '%s', '%s', '%s']
            );
            $ids[] = (int) $wpdb->insert_id;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function updateReplyCandidate(int $candidate_id, array $data): bool
    {
        global $wpdb;
        $tables = self::tables();
        if ($candidate_id <= 0) {
            return false;
        }

        $record = [];
        $formats = [];
        if (array_key_exists('reply_text', $data)) {
            $record['reply_text'] = wp_kses_post((string) $data['reply_text']);
            $formats[] = '%s';
        }
        if (array_key_exists('edited_text', $data)) {
            $record['edited_text'] = wp_kses_post((string) $data['edited_text']);
            $formats[] = '%s';
        }
        if (array_key_exists('reviewer_notes', $data)) {
            $record['reviewer_notes'] = wp_kses_post((string) $data['reviewer_notes']);
            $formats[] = '%s';
        }
        if (array_key_exists('status', $data)) {
            $record['status'] = sanitize_text_field((string) $data['status']);
            $formats[] = '%s';
        }
        if (array_key_exists('approach_label', $data)) {
            $record['approach_label'] = sanitize_text_field((string) $data['approach_label']);
            $formats[] = '%s';
        }

        $record['updated_at'] = current_time('mysql');
        $formats[] = '%s';

        $result = $wpdb->update($tables['reply_candidates'], $record, ['id' => $candidate_id], $formats, ['%d']);
        return $result !== false;
    }

    public static function setCandidateStatus(int $candidate_id, string $status, int $user_id = 0, string $notes = ''): bool
    {
        $ok = self::updateReplyCandidate($candidate_id, [
            'status' => $status,
            'reviewer_notes' => $notes,
        ]);

        if ($ok) {
            self::saveAudit('candidate_status', 'reply_candidate', $candidate_id, 'Candidate status updated.', [
                'status' => $status,
                'actor_id' => $user_id,
            ], 'info', $user_id);
        }

        return $ok;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listPersonas(int $limit = 50): array
    {
        global $wpdb;
        $tables = self::tables();
        $limit = max(1, min(250, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$tables['personas']} ORDER BY active DESC, id DESC LIMIT %d", $limit),
            ARRAY_A
        );

        return self::hydrateRows(is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPersona(int $persona_id): array
    {
        global $wpdb;
        $tables = self::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['personas']} WHERE id = %d LIMIT 1", $persona_id),
            ARRAY_A
        );

        return is_array($row) ? self::hydrateJsonFields($row, ['guardrails_json']) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function activePersona(): array
    {
        global $wpdb;
        $tables = self::tables();
        $row = $wpdb->get_row("SELECT * FROM {$tables['personas']} ORDER BY active DESC, id DESC LIMIT 1", ARRAY_A);
        if (!is_array($row)) {
            return self::defaultPersona();
        }

        return self::hydrateJsonFields($row, ['guardrails_json']);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function savePersona(array $data): int
    {
        global $wpdb;
        $tables = self::tables();
        $id = (int) ($data['id'] ?? 0);
        $active = !empty($data['active']) ? 1 : 0;
        if ($active === 1) {
            $wpdb->query("UPDATE {$tables['personas']} SET active = 0");
        }

        $record = [
            'slug' => sanitize_title((string) ($data['slug'] ?? '')),
            'version' => sanitize_text_field((string) ($data['version'] ?? '1.0.0')),
            'name' => sanitize_text_field((string) ($data['name'] ?? '')),
            'description' => wp_kses_post((string) ($data['description'] ?? '')),
            'tone' => sanitize_text_field((string) ($data['tone'] ?? 'measured')),
            'voice' => sanitize_text_field((string) ($data['voice'] ?? 'deadpan')),
            'instructions' => wp_kses_post((string) ($data['instructions'] ?? '')),
            'guardrails_json' => self::jsonOrNull($data['guardrails_json'] ?? null),
            'active' => $active,
            'updated_at' => current_time('mysql'),
        ];

        if ($id > 0) {
            $wpdb->update(
                $tables['personas'],
                $record,
                ['id' => $id],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'],
                ['%d']
            );

            return $id;
        }

        $wpdb->insert(
            $tables['personas'],
            $record + ['created_at' => current_time('mysql')],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    public static function setPersonaActive(int $persona_id): bool
    {
        if ($persona_id <= 0) {
            return false;
        }

        global $wpdb;
        $tables = self::tables();
        $wpdb->query("UPDATE {$tables['personas']} SET active = 0");
        $result = $wpdb->update($tables['personas'], ['active' => 1, 'updated_at' => current_time('mysql')], ['id' => $persona_id], ['%d', '%s'], ['%d']);
        return $result !== false;
    }

    public static function duplicatePersona(int $persona_id): int
    {
        $persona = self::getPersona($persona_id);
        if ($persona === []) {
            return 0;
        }

        $persona['slug'] = sanitize_title((string) ($persona['slug'] ?? 'persona')) . '-copy';
        $persona['name'] = (string) ($persona['name'] ?? 'Persona') . ' Copy';
        $persona['active'] = 0;
        unset($persona['id'], $persona['created_at'], $persona['updated_at']);

        return self::savePersona($persona);
    }

    public static function deletePersona(int $persona_id): bool
    {
        global $wpdb;
        $tables = self::tables();
        return $wpdb->delete($tables['personas'], ['id' => $persona_id], ['%d']) !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listPrompts(int $limit = 50): array
    {
        global $wpdb;
        $tables = self::tables();
        $limit = max(1, min(250, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$tables['prompt_versions']} ORDER BY active DESC, stage ASC, id DESC LIMIT %d", $limit),
            ARRAY_A
        );

        return self::hydrateRows(is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPromptVersion(int $prompt_id): array
    {
        global $wpdb;
        $tables = self::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['prompt_versions']} WHERE id = %d LIMIT 1", $prompt_id),
            ARRAY_A
        );

        return is_array($row) ? self::hydrateJsonFields($row, ['response_schema']) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function activePrompt(string $stage): array
    {
        global $wpdb;
        $tables = self::tables();
        $stage = sanitize_key($stage);
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['prompt_versions']} WHERE stage = %s ORDER BY active DESC, id DESC LIMIT 1", $stage),
            ARRAY_A
        );

        if (!is_array($row)) {
            return self::defaultPrompt($stage);
        }

        return self::hydrateJsonFields($row, ['response_schema']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function activePrompts(): array
    {
        $stages = ['analysis', 'generation', 'scoring', 'recommendations'];
        $bundle = [];
        foreach ($stages as $stage) {
            $bundle[$stage] = self::activePrompt($stage);
        }

        return $bundle;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function savePromptVersion(array $data): int
    {
        global $wpdb;
        $tables = self::tables();
        $id = (int) ($data['id'] ?? 0);
        $stage = sanitize_key((string) ($data['stage'] ?? 'analysis'));
        $active = !empty($data['active']) ? 1 : 0;
        if ($active === 1) {
            $wpdb->query($wpdb->prepare("UPDATE {$tables['prompt_versions']} SET active = 0 WHERE stage = %s", $stage));
        }

        $record = [
            'stage' => $stage,
            'slug' => sanitize_title((string) ($data['slug'] ?? '')),
            'version' => sanitize_text_field((string) ($data['version'] ?? '1.0.0')),
            'name' => sanitize_text_field((string) ($data['name'] ?? '')),
            'description' => wp_kses_post((string) ($data['description'] ?? '')),
            'system_prompt' => wp_kses_post((string) ($data['system_prompt'] ?? '')),
            'user_prompt' => wp_kses_post((string) ($data['user_prompt'] ?? '')),
            'response_schema' => self::jsonOrNull($data['response_schema'] ?? null),
            'active' => $active,
            'draft' => !empty($data['draft']) ? 1 : 0,
            'updated_at' => current_time('mysql'),
        ];

        if ($id > 0) {
            $wpdb->update(
                $tables['prompt_versions'],
                $record,
                ['id' => $id],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s'],
                ['%d']
            );

            return $id;
        }

        $wpdb->insert(
            $tables['prompt_versions'],
            $record + ['created_at' => current_time('mysql')],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    public static function setPromptActive(int $prompt_id): bool
    {
        $prompt = self::getPromptVersion($prompt_id);
        if ($prompt === []) {
            return false;
        }

        return self::savePromptVersion($prompt + ['id' => $prompt_id, 'active' => 1]) > 0;
    }

    public static function duplicatePromptVersion(int $prompt_id): int
    {
        $prompt = self::getPromptVersion($prompt_id);
        if ($prompt === []) {
            return 0;
        }

        $prompt['slug'] = sanitize_title((string) ($prompt['slug'] ?? 'prompt')) . '-copy';
        $prompt['name'] = (string) ($prompt['name'] ?? 'Prompt') . ' Copy';
        $prompt['active'] = 0;
        unset($prompt['id'], $prompt['created_at'], $prompt['updated_at']);

        return self::savePromptVersion($prompt);
    }

    public static function deletePromptVersion(int $prompt_id): bool
    {
        global $wpdb;
        $tables = self::tables();
        return $wpdb->delete($tables['prompt_versions'], ['id' => $prompt_id], ['%d']) !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listMetrics(int $limit = 50): array
    {
        global $wpdb;
        $tables = self::tables();
        $limit = max(1, min(250, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$tables['performance_metrics']} ORDER BY measurement_datetime DESC, id DESC LIMIT %d", $limit),
            ARRAY_A
        );

        return self::hydrateRows(is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function saveMetric(array $data): int
    {
        global $wpdb;
        $tables = self::tables();
        $id = (int) ($data['id'] ?? 0);
        $record = [
            'post_id' => (int) ($data['post_id'] ?? 0),
            'reply_set_id' => (int) ($data['reply_set_id'] ?? 0),
            'candidate_id' => (int) ($data['candidate_id'] ?? 0),
            'impressions' => (int) ($data['impressions'] ?? 0),
            'likes' => (int) ($data['likes'] ?? 0),
            'replies_received' => (int) ($data['replies_received'] ?? 0),
            'reposts' => (int) ($data['reposts'] ?? 0),
            'bookmarks' => (int) ($data['bookmarks'] ?? 0),
            'profile_visits' => (int) ($data['profile_visits'] ?? 0),
            'follows' => (int) ($data['follows'] ?? 0),
            'publication_datetime' => !empty($data['publication_datetime']) ? sanitize_text_field((string) $data['publication_datetime']) : null,
            'measurement_datetime' => !empty($data['measurement_datetime']) ? sanitize_text_field((string) $data['measurement_datetime']) : current_time('mysql'),
            'audience_category' => sanitize_text_field((string) ($data['audience_category'] ?? '')),
            'notes' => wp_kses_post((string) ($data['notes'] ?? '')),
            'created_by' => (int) ($data['created_by'] ?? get_current_user_id()),
            'updated_at' => current_time('mysql'),
        ];

        if ($id > 0) {
            $wpdb->update(
                $tables['performance_metrics'],
                $record,
                ['id' => $id],
                ['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s'],
                ['%d']
            );

            return $id;
        }

        $wpdb->insert(
            $tables['performance_metrics'],
            $record + ['created_at' => current_time('mysql')],
            ['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<string, mixed>
     */
    public static function metricsSummary(): array
    {
        $metrics = self::listMetrics(500);
        $totals = [
            'impressions' => 0,
            'likes' => 0,
            'replies_received' => 0,
            'reposts' => 0,
            'bookmarks' => 0,
            'profile_visits' => 0,
            'follows' => 0,
        ];

        foreach ($metrics as $metric) {
            foreach (array_keys($totals) as $field) {
                $totals[$field] += (int) ($metric[$field] ?? 0);
            }
        }

        return [
            'count' => count($metrics),
            'totals' => $totals,
            'sample_size_warning' => count($metrics) < 5 ? 'Sample size is still small; treat trends cautiously.' : '',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listAudit(int $limit = 50): array
    {
        global $wpdb;
        $tables = self::tables();
        $limit = max(1, min(250, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$tables['audit_log']} ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );

        return self::hydrateRows(is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function saveAudit(string $action, string $object_type, int $object_id, string $message, array $context = [], string $severity = 'info', int $actor_id = 0): int
    {
        global $wpdb;
        $tables = self::tables();
        $wpdb->insert(
            $tables['audit_log'],
            [
                'actor_id' => $actor_id,
                'action' => sanitize_key($action),
                'object_type' => sanitize_key($object_type),
                'object_id' => $object_id,
                'severity' => sanitize_key($severity),
                'message' => wp_kses_post($message),
                'context_json' => self::jsonOrNull(Redactor::clean($context)),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listErrors(int $limit = 50): array
    {
        global $wpdb;
        $tables = self::tables();
        $limit = max(1, min(250, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$tables['error_log']} ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );

        return self::hydrateRows(is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function saveError(string $source, string $error_code, string $message, array $context = [], string $severity = 'error', int $actor_id = 0): int
    {
        global $wpdb;
        $tables = self::tables();
        $wpdb->insert(
            $tables['error_log'],
            [
                'actor_id' => $actor_id,
                'source' => sanitize_text_field($source),
                'error_code' => sanitize_text_field($error_code),
                'severity' => sanitize_key($severity),
                'message' => wp_kses_post($message),
                'context_json' => self::jsonOrNull(Redactor::clean($context)),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listAIRequests(int $limit = 50): array
    {
        global $wpdb;
        $tables = self::tables();
        $limit = max(1, min(250, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$tables['ai_requests']} ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );

        return self::hydrateRows(is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function saveAIRequest(array $data): int
    {
        global $wpdb;
        $tables = self::tables();
        $wpdb->insert(
            $tables['ai_requests'],
            [
                'stage' => sanitize_key((string) ($data['stage'] ?? '')),
                'post_id' => (int) ($data['post_id'] ?? 0),
                'analysis_id' => (int) ($data['analysis_id'] ?? 0),
                'reply_set_id' => (int) ($data['reply_set_id'] ?? 0),
                'prompt_version_id' => (int) ($data['prompt_version_id'] ?? 0),
                'persona_id' => (int) ($data['persona_id'] ?? 0),
                'provider' => sanitize_text_field((string) ($data['provider'] ?? 'mock')),
                'model' => sanitize_text_field((string) ($data['model'] ?? '')),
                'request_json' => self::jsonOrNull(Redactor::clean($data['request_json'] ?? null)),
                'response_json' => self::jsonOrNull(Redactor::clean($data['response_json'] ?? null)),
                'status' => sanitize_text_field((string) ($data['status'] ?? 'ok')),
                'repair_attempted' => !empty($data['repair_attempted']) ? 1 : 0,
                'latency_ms' => (int) ($data['latency_ms'] ?? 0),
                'error_message' => wp_kses_post((string) Redactor::clean((string) ($data['error_message'] ?? ''))),
                'error_category' => sanitize_text_field((string) ($data['error_category'] ?? '')),
                'prompt_tokens' => (int) ($data['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($data['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($data['total_tokens'] ?? 0),
                'cost_estimate' => (float) ($data['cost_estimate'] ?? 0),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<string, mixed>
     */
    public static function analyticsSummary(): array
    {
        $posts = self::listPosts([], 500);
        $analyses = self::listAnalyses(500);
        $replySets = self::listReplySets([], 500);
        $metrics = self::listMetrics(500);
        $candidates = [];
        foreach ($replySets as $replySet) {
            $candidates = array_merge($candidates, self::listReplyCandidates((int) ($replySet['id'] ?? 0)));
        }

        $topicCounts = [];
        $toneCounts = [];
        $personaCounts = [];
        foreach ($analyses as $analysis) {
            $topic = (string) ($analysis['main_topic'] ?? 'Unknown');
            $tone = (string) ($analysis['tone'] ?? 'Unknown');
            $persona = (string) ($analysis['persona_name'] ?? ('Persona #' . (string) ($analysis['persona_id'] ?? 0)));
            $topicCounts[$topic] = ($topicCounts[$topic] ?? 0) + 1;
            $toneCounts[$tone] = ($toneCounts[$tone] ?? 0) + 1;
            $personaCounts[$persona] = ($personaCounts[$persona] ?? 0) + 1;
        }

        usort($candidates, static fn (array $left, array $right): int => (float) ($right['total_score'] ?? 0) <=> (float) ($left['total_score'] ?? 0));
        $topCandidate = $candidates[0] ?? [];

        return [
            'posts' => count($posts),
            'analyses' => count($analyses),
            'reply_sets' => count($replySets),
            'candidates' => count($candidates),
            'metrics_count' => count($metrics),
            'top_topics' => self::topEntries($topicCounts),
            'top_tones' => self::topEntries($toneCounts),
            'top_personas' => self::topEntries($personaCounts),
            'engagement' => self::metricsSummary(),
            'top_candidate' => $topCandidate,
            'sample_size_warning' => count($metrics) < 5 ? 'Sample size is still small; keep recommendations cautious.' : '',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function exportRows(string $type): array
    {
        return match (sanitize_key($type)) {
            'posts' => self::listPosts([], 1000),
            'analyses' => self::listAnalyses(1000),
            'reply_sets' => self::listReplySets([], 1000),
            'reply_candidates' => self::exportReplyCandidates(),
            'personas' => self::listPersonas(1000),
            'prompts' => self::listPrompts(1000),
            'metrics' => self::listMetrics(1000),
            'ai_requests' => self::listAIRequests(1000),
            'errors' => self::listErrors(1000),
            default => self::listAudit(1000),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function seedDefaults(): array
    {
        $seeded = [
            'personas' => 0,
            'prompts' => 0,
        ];

        if (count(self::listPersonas(1)) === 0) {
            $defaults = self::defaultPersonas();
            foreach ($defaults as $persona) {
                self::savePersona($persona);
            }
            $seeded['personas'] = count($defaults);
        }

        if (count(self::listPrompts(1)) === 0) {
            $defaults = self::defaultPrompts();
            foreach ($defaults as $prompt) {
                self::savePromptVersion($prompt);
            }
            $seeded['prompts'] = count($defaults);
        }

        return $seeded;
    }

    /**
     * @return array<string, mixed>
     */
    public static function seedDemoData(): array
    {
        self::seedDefaults();
        $persona = self::activePersona();
        $prompts = self::activePrompts();
        $starterRows = self::starterContentRows();
        $latestPost = self::latestPost();
        $legacyStarterPost = self::findLegacyStarterSeedPost();
        $seeded = [
            'posts' => 0,
            'analyses' => 0,
            'reply_sets' => 0,
            'reply_candidates' => 0,
            'metrics' => 0,
            'replaced' => false,
        ];

        if ($legacyStarterPost !== []) {
            $firstBlueprint = array_shift($starterRows);
            if (is_array($firstBlueprint)) {
                $existingReplySetId = (int) ($legacyStarterPost['reply_set_id'] ?? 0);
                $existingAnalysisId = (int) ($legacyStarterPost['analysis_row_id'] ?? 0);
                $existingMetric = self::latestMetricForPost((int) ($legacyStarterPost['id'] ?? 0));
                $existingCandidateIds = [];
                if ($existingReplySetId > 0) {
                    $existingCandidateIds = array_map(
                        static fn (array $candidate): int => (int) ($candidate['id'] ?? 0),
                        self::listReplyCandidates($existingReplySetId)
                    );
                }

                $result = self::applyStarterBlueprint($firstBlueprint, [
                    'post_id' => (int) ($legacyStarterPost['id'] ?? 0),
                    'analysis_id' => $existingAnalysisId,
                    'reply_set_id' => $existingReplySetId,
                    'candidate_ids' => $existingCandidateIds,
                    'metric_id' => (int) ($existingMetric['id'] ?? 0),
                    'persona_id' => (int) ($persona['id'] ?? 0),
                    'analysis_prompt_version_id' => (int) ($prompts['analysis']['id'] ?? 0),
                    'generation_prompt_version_id' => (int) ($prompts['generation']['id'] ?? 0),
                    'scoring_prompt_version_id' => (int) ($prompts['scoring']['id'] ?? 0),
                    'recommendation_prompt_version_id' => (int) ($prompts['recommendations']['id'] ?? 0),
                ]);
                $seeded['posts'] += (int) ($result['post_id'] ?? 0) > 0 ? 1 : 0;
                $seeded['analyses'] += (int) ($result['analysis_id'] ?? 0) > 0 ? 1 : 0;
                $seeded['reply_sets'] += (int) ($result['reply_set_id'] ?? 0) > 0 ? 1 : 0;
                $seeded['reply_candidates'] += count((array) ($result['candidate_ids'] ?? []));
                $seeded['metrics'] += (int) ($result['metric_id'] ?? 0) > 0 ? 1 : 0;
                $seeded['replaced'] = true;
            }
        }

        foreach ($starterRows as $blueprint) {
            $contentHash = hash(
                'sha256',
                Similarity::normalize((string) ($blueprint['post']['post_text'] ?? '')) . '|' . strtolower((string) ($blueprint['post']['source_url'] ?? ''))
            );
            if (self::findPostByHash($contentHash) !== []) {
                continue;
            }

            $result = self::applyStarterBlueprint($blueprint);
            $seeded['posts'] += (int) ($result['post_id'] ?? 0) > 0 ? 1 : 0;
            $seeded['analyses'] += (int) ($result['analysis_id'] ?? 0) > 0 ? 1 : 0;
            $seeded['reply_sets'] += (int) ($result['reply_set_id'] ?? 0) > 0 ? 1 : 0;
            $seeded['reply_candidates'] += count((array) ($result['candidate_ids'] ?? []));
            $seeded['metrics'] += (int) ($result['metric_id'] ?? 0) > 0 ? 1 : 0;
        }

        if ($seeded['posts'] === 0 && $seeded['replaced'] === false) {
            return ['ok' => true, 'seeded' => false];
        }

        self::saveAudit(
            'seed',
            'system',
            0,
            'Live starter content seeded.',
            [
                'posts' => $seeded['posts'],
                'analyses' => $seeded['analyses'],
                'reply_sets' => $seeded['reply_sets'],
                'reply_candidates' => $seeded['reply_candidates'],
                'metrics' => $seeded['metrics'],
            ],
            'info',
            get_current_user_id()
        );

        return [
            'ok' => true,
            'seeded' => true,
            'starter_content' => $seeded,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function starterContentRows(): array
    {
        return [
            [
                'post' => [
                    'platform' => 'x',
                    'external_post_id' => '2079303214118150593',
                    'source_url' => 'https://x.com/AITWebHosting/status/2079303214118150593',
                    'post_text' => 'AIT AI Workforce Solutions helps local service businesses capture inquiries, answer FAQs, support service requests, and respond faster with AI.',
                    'context_text' => 'Real X starter content about AI workforce operations for local businesses.',
                    'desired_objective' => 'invite practical discussion',
                    'author_handle' => 'AITWebHosting',
                    'author_name' => 'AIT AI Workforce Solutions',
                    'language' => 'en',
                    'status' => 'reviewed',
                    'metadata_json' => [
                        'topic' => 'business ai transformation',
                        'source' => 'x search result',
                    ],
                ],
                'analysis' => [
                    'main_topic' => 'business ai transformation',
                    'secondary_topics' => ['workforce automation', 'customer intake', 'service requests'],
                    'tone' => 'measured',
                    'sentiment' => 'positive',
                    'likely_intent' => 'signal_process_improvement',
                    'important_context' => ['Real X starter content sourced from a live search result.'],
                    'conversation_angles' => ['Ask which workflow changed first', 'Call out operational value', 'Discuss intake versus support'],
                    'factual_claims_requiring_caution' => ['Avoid claiming measurable gains without evidence.'],
                    'safety_or_reputation_risks' => ['Avoid overpromising automation coverage.'],
                    'recommended_reply_approach' => 'Acknowledge the operational shift, then ask which workflow is moving first.',
                    'confidence_notes' => 'Live starter content derived from a real X post.',
                ],
                'reply_set' => [
                    'status' => 'review_queue',
                    'rank_summary' => [
                        'ranked_candidate_indices' => [1, 2, 3],
                        'top_choice_index' => 1,
                        'ranking_notes' => ['Live starter content.'],
                    ],
                    'recommendations' => [
                        'summary' => 'Lead with the workflow shift, then ask which operational area changed first.',
                        'sample_size_warning' => 'Live starter content.',
                        'recommended_next_actions' => ['Review the reply', 'Track performance after publication'],
                        'persona_adjustments' => ['Keep it concrete and measured'],
                        'prompt_adjustment_notes' => ['Use a workflow-specific angle for operational posts'],
                        'confidence_notes' => 'Live starter content.',
                    ],
                ],
                'candidates' => [
                    [
                        'candidate_index' => 1,
                        'approach_label' => 'Useful Observation',
                        'reply_text' => 'That is the real shift. Which workflow changed first: intake, support, or follow-up?',
                        'short_rationale' => 'Connects the post to the operational change.',
                        'relevance_score' => 90,
                        'naturalness_score' => 87,
                        'originality_score' => 83,
                        'tone_match_score' => 91,
                        'conversation_potential_score' => 88,
                        'risk_score' => 7,
                        'total_score' => 432,
                        'character_count' => 91,
                        'status' => 'approved',
                    ],
                    [
                        'candidate_index' => 2,
                        'approach_label' => 'Thoughtful Question',
                        'reply_text' => 'Which part of the busywork stack usually breaks first when the volume picks up?',
                        'short_rationale' => 'Invites a specific operational answer.',
                        'relevance_score' => 86,
                        'naturalness_score' => 85,
                        'originality_score' => 84,
                        'tone_match_score' => 89,
                        'conversation_potential_score' => 87,
                        'risk_score' => 6,
                        'total_score' => 425,
                        'character_count' => 85,
                        'status' => 'draft',
                    ],
                    [
                        'candidate_index' => 3,
                        'approach_label' => 'Measured Counterpoint',
                        'reply_text' => 'The strongest AI wins usually come from removing repetition, not adding another layer of software.',
                        'short_rationale' => 'Keeps the point practical and grounded.',
                        'relevance_score' => 84,
                        'naturalness_score' => 84,
                        'originality_score' => 85,
                        'tone_match_score' => 88,
                        'conversation_potential_score' => 85,
                        'risk_score' => 8,
                        'total_score' => 418,
                        'character_count' => 100,
                        'status' => 'draft',
                    ],
                ],
                'metric' => [
                    'impressions' => 1980,
                    'likes' => 154,
                    'replies_received' => 16,
                    'reposts' => 18,
                    'bookmarks' => 31,
                    'profile_visits' => 54,
                    'follows' => 12,
                    'audience_category' => 'local service businesses',
                    'notes' => 'Live starter metric for business AI transformation.',
                ],
            ],
            [
                'post' => [
                    'platform' => 'x',
                    'external_post_id' => '2070217033635995921',
                    'source_url' => 'https://x.com/Pai3Ai/status/2070217033635995921',
                    'post_text' => 'Local AI processing changes the equation entirely.',
                    'context_text' => 'Real X starter content about on-device or local inference.',
                    'desired_objective' => 'invite expert discussion',
                    'author_handle' => 'Pai3Ai',
                    'author_name' => 'PAI3',
                    'language' => 'en',
                    'status' => 'reviewed',
                    'metadata_json' => [
                        'topic' => 'local ai processing',
                        'source' => 'x search result',
                    ],
                ],
                'analysis' => [
                    'main_topic' => 'local ai processing',
                    'secondary_topics' => ['edge inference', 'privacy', 'latency'],
                    'tone' => 'measured',
                    'sentiment' => 'neutral',
                    'likely_intent' => 'signal_product_direction',
                    'important_context' => ['Real X starter content sourced from a live search result.'],
                    'conversation_angles' => ['Discuss latency and privacy', 'Ask what stays local', 'Compare cloud versus on-device'],
                    'factual_claims_requiring_caution' => ['Avoid technical claims without implementation details.'],
                    'safety_or_reputation_risks' => ['Avoid suggesting every workload should move on-device.'],
                    'recommended_reply_approach' => 'Acknowledge the local-processing tradeoff, then ask which workloads are likely to stay on-device.',
                    'confidence_notes' => 'Live starter content derived from a real X post.',
                ],
                'reply_set' => [
                    'status' => 'review_queue',
                    'rank_summary' => [
                        'ranked_candidate_indices' => [1, 2, 3],
                        'top_choice_index' => 1,
                        'ranking_notes' => ['Live starter content.'],
                    ],
                    'recommendations' => [
                        'summary' => 'Lead with the latency and privacy tradeoff, then ask what stays local.',
                        'sample_size_warning' => 'Live starter content.',
                        'recommended_next_actions' => ['Review the reply', 'Track performance after publication'],
                        'persona_adjustments' => ['Stay technical but concise'],
                        'prompt_adjustment_notes' => ['Use a local-inference angle for edge topics'],
                        'confidence_notes' => 'Live starter content.',
                    ],
                ],
                'candidates' => [
                    [
                        'candidate_index' => 1,
                        'approach_label' => 'Useful Observation',
                        'reply_text' => 'Local processing changes the latency and privacy tradeoff in a useful way. Which workloads stay on-device?',
                        'short_rationale' => 'Frames the product value cleanly.',
                        'relevance_score' => 89,
                        'naturalness_score' => 88,
                        'originality_score' => 84,
                        'tone_match_score' => 90,
                        'conversation_potential_score' => 87,
                        'risk_score' => 7,
                        'total_score' => 431,
                        'character_count' => 114,
                        'status' => 'approved',
                    ],
                    [
                        'candidate_index' => 2,
                        'approach_label' => 'Thoughtful Question',
                        'reply_text' => 'What feels most different once inference is local instead of cloud-first?',
                        'short_rationale' => 'Keeps the conversation technical without overreaching.',
                        'relevance_score' => 85,
                        'naturalness_score' => 86,
                        'originality_score' => 83,
                        'tone_match_score' => 88,
                        'conversation_potential_score' => 86,
                        'risk_score' => 6,
                        'total_score' => 422,
                        'character_count' => 81,
                        'status' => 'draft',
                    ],
                    [
                        'candidate_index' => 3,
                        'approach_label' => 'Measured Counterpoint',
                        'reply_text' => 'The interesting part is less the model and more the control the local stack gives back.',
                        'short_rationale' => 'Adds perspective without sounding promotional.',
                        'relevance_score' => 84,
                        'naturalness_score' => 84,
                        'originality_score' => 85,
                        'tone_match_score' => 87,
                        'conversation_potential_score' => 84,
                        'risk_score' => 8,
                        'total_score' => 416,
                        'character_count' => 94,
                        'status' => 'draft',
                    ],
                ],
                'metric' => [
                    'impressions' => 1420,
                    'likes' => 103,
                    'replies_received' => 11,
                    'reposts' => 12,
                    'bookmarks' => 27,
                    'profile_visits' => 39,
                    'follows' => 8,
                    'audience_category' => 'local ai processing',
                    'notes' => 'Live starter metric for local AI processing.',
                ],
            ],
            [
                'post' => [
                    'platform' => 'x',
                    'external_post_id' => '2080271119932895496',
                    'source_url' => 'https://x.com/polsia/status/2080271119932895496',
                    'post_text' => "Local service businesses don't need one more AI tool. They need the whole busywork stack — booking, follow-ups, reviews, customer Q&A ...",
                    'context_text' => 'Real X starter content about local service workflow automation.',
                    'desired_objective' => 'invite practical discussion',
                    'author_handle' => 'polsia',
                    'author_name' => 'Polsia',
                    'language' => 'en',
                    'status' => 'reviewed',
                    'metadata_json' => [
                        'topic' => 'local service workflow automation',
                        'source' => 'x search result',
                    ],
                ],
                'analysis' => [
                    'main_topic' => 'local service workflow automation',
                    'secondary_topics' => ['booking', 'follow-ups', 'reviews', 'customer q&a'],
                    'tone' => 'measured',
                    'sentiment' => 'neutral',
                    'likely_intent' => 'signal_process_improvement',
                    'important_context' => ['Real X starter content sourced from a live search result.'],
                    'conversation_angles' => ['Ask which workflow breaks first', 'Compare software versus process', 'Highlight the admin layer'],
                    'factual_claims_requiring_caution' => ['Avoid claiming business outcomes without proof'],
                    'safety_or_reputation_risks' => ['Avoid implying all businesses need the same stack.'],
                    'recommended_reply_approach' => 'Acknowledge the workflow stack idea, then ask which process usually needs the most cleanup.',
                    'confidence_notes' => 'Live starter content derived from a real X post.',
                ],
                'reply_set' => [
                    'status' => 'review_queue',
                    'rank_summary' => [
                        'ranked_candidate_indices' => [1, 2, 3],
                        'top_choice_index' => 1,
                        'ranking_notes' => ['Live starter content.'],
                    ],
                    'recommendations' => [
                        'summary' => 'Lead with the workflow stack framing, then ask where the process breaks first.',
                        'sample_size_warning' => 'Live starter content.',
                        'recommended_next_actions' => ['Review the reply', 'Track performance after publication'],
                        'persona_adjustments' => ['Keep it practical and grounded'],
                        'prompt_adjustment_notes' => ['Use an operations angle for service-business posts'],
                        'confidence_notes' => 'Live starter content.',
                    ],
                ],
                'candidates' => [
                    [
                        'candidate_index' => 1,
                        'approach_label' => 'Useful Observation',
                        'reply_text' => 'That’s the right frame. Which workflow usually moves the needle first: booking, follow-ups, or reviews?',
                        'short_rationale' => 'Connects the post to a practical decision point.',
                        'relevance_score' => 91,
                        'naturalness_score' => 88,
                        'originality_score' => 84,
                        'tone_match_score' => 90,
                        'conversation_potential_score' => 88,
                        'risk_score' => 7,
                        'total_score' => 434,
                        'character_count' => 113,
                        'status' => 'approved',
                    ],
                    [
                        'candidate_index' => 2,
                        'approach_label' => 'Thoughtful Question',
                        'reply_text' => 'Where does the busywork stack usually break first in the day-to-day flow?',
                        'short_rationale' => 'Invites a concrete operational answer.',
                        'relevance_score' => 86,
                        'naturalness_score' => 86,
                        'originality_score' => 83,
                        'tone_match_score' => 89,
                        'conversation_potential_score' => 87,
                        'risk_score' => 6,
                        'total_score' => 425,
                        'character_count' => 81,
                        'status' => 'draft',
                    ],
                    [
                        'candidate_index' => 3,
                        'approach_label' => 'Measured Counterpoint',
                        'reply_text' => 'The value is usually in removing the admin layer, not adding another tool for the team to juggle.',
                        'short_rationale' => 'Keeps the reply useful and business-oriented.',
                        'relevance_score' => 84,
                        'naturalness_score' => 84,
                        'originality_score' => 85,
                        'tone_match_score' => 88,
                        'conversation_potential_score' => 85,
                        'risk_score' => 8,
                        'total_score' => 418,
                        'character_count' => 108,
                        'status' => 'draft',
                    ],
                ],
                'metric' => [
                    'impressions' => 2310,
                    'likes' => 178,
                    'replies_received' => 21,
                    'reposts' => 24,
                    'bookmarks' => 42,
                    'profile_visits' => 71,
                    'follows' => 15,
                    'audience_category' => 'local service businesses',
                    'notes' => 'Live starter metric for local service workflow automation.',
                ],
            ],
            [
                'post' => [
                    'platform' => 'x',
                    'external_post_id' => '2058571909391118699',
                    'source_url' => 'https://x.com/Muktaak62859256/status/2058571909391118699',
                    'post_text' => "Local SEO is evolving into AEO. Google AI, ChatGPT, and Gemini are no longer just ranking pages; they're choosing answers.",
                    'context_text' => 'Real X starter content about answer engine optimization.',
                    'desired_objective' => 'invite thoughtful engagement',
                    'author_handle' => 'Muktaak62859256',
                    'author_name' => 'Muktaak',
                    'language' => 'en',
                    'status' => 'reviewed',
                    'metadata_json' => [
                        'topic' => 'search visibility and answer engines',
                        'source' => 'x search result',
                    ],
                ],
                'analysis' => [
                    'main_topic' => 'search visibility and answer engines',
                    'secondary_topics' => ['Google AI', 'ChatGPT', 'Gemini', 'answer selection'],
                    'tone' => 'measured',
                    'sentiment' => 'neutral',
                    'likely_intent' => 'share_framework',
                    'important_context' => ['Real X starter content sourced from a live search result.'],
                    'conversation_angles' => ['Ask how answer visibility is measured', 'Compare rankings and answer selection', 'Mention entity coverage'],
                    'factual_claims_requiring_caution' => ['Avoid claiming ranking gains without evidence.'],
                    'safety_or_reputation_risks' => ['Avoid implying answer engines replace all search traffic.'],
                    'recommended_reply_approach' => 'Acknowledge the shift from rankings to answer selection, then ask how it is being measured.',
                    'confidence_notes' => 'Live starter content derived from a real X post.',
                ],
                'reply_set' => [
                    'status' => 'review_queue',
                    'rank_summary' => [
                        'ranked_candidate_indices' => [1, 2, 3],
                        'top_choice_index' => 1,
                        'ranking_notes' => ['Live starter content.'],
                    ],
                    'recommendations' => [
                        'summary' => 'Lead with the shift in search behavior, then ask how answer visibility is being measured.',
                        'sample_size_warning' => 'Live starter content.',
                        'recommended_next_actions' => ['Review the reply', 'Track performance after publication'],
                        'persona_adjustments' => ['Stay specific and measured'],
                        'prompt_adjustment_notes' => ['Use a visibility angle for search posts'],
                        'confidence_notes' => 'Live starter content.',
                    ],
                ],
                'candidates' => [
                    [
                        'candidate_index' => 1,
                        'approach_label' => 'Useful Observation',
                        'reply_text' => 'That shift is real. Are you optimizing for answer selection as much as rankings now?',
                        'short_rationale' => 'Connects the post to the core change in search behavior.',
                        'relevance_score' => 91,
                        'naturalness_score' => 88,
                        'originality_score' => 84,
                        'tone_match_score' => 90,
                        'conversation_potential_score' => 88,
                        'risk_score' => 7,
                        'total_score' => 434,
                        'character_count' => 93,
                        'status' => 'approved',
                    ],
                    [
                        'candidate_index' => 2,
                        'approach_label' => 'Thoughtful Question',
                        'reply_text' => 'How are you tracking when AI surfaces the brand instead of the page?',
                        'short_rationale' => 'Invites a concrete measurement answer.',
                        'relevance_score' => 86,
                        'naturalness_score' => 86,
                        'originality_score' => 83,
                        'tone_match_score' => 89,
                        'conversation_potential_score' => 87,
                        'risk_score' => 6,
                        'total_score' => 427,
                        'character_count' => 79,
                        'status' => 'draft',
                    ],
                    [
                        'candidate_index' => 3,
                        'approach_label' => 'Measured Counterpoint',
                        'reply_text' => 'Feels like the play is moving from keywords to clarity and entity coverage.',
                        'short_rationale' => 'Adds perspective without sounding promotional.',
                        'relevance_score' => 84,
                        'naturalness_score' => 84,
                        'originality_score' => 85,
                        'tone_match_score' => 88,
                        'conversation_potential_score' => 85,
                        'risk_score' => 8,
                        'total_score' => 418,
                        'character_count' => 84,
                        'status' => 'draft',
                    ],
                ],
                'metric' => [
                    'impressions' => 2640,
                    'likes' => 201,
                    'replies_received' => 24,
                    'reposts' => 29,
                    'bookmarks' => 46,
                    'profile_visits' => 76,
                    'follows' => 18,
                    'audience_category' => 'search visibility',
                    'notes' => 'Live starter metric for answer engine optimization.',
                ],
            ],
            [
                'post' => [
                    'platform' => 'x',
                    'external_post_id' => '2075074969214534085',
                    'source_url' => 'https://x.com/Dialmenow/status/2075074969214534085',
                    'post_text' => 'Want more local customers? Make the business easier to find with Local SEO, AEO, and GEO.',
                    'context_text' => 'Real X starter content about local search discovery and answer engine visibility.',
                    'desired_objective' => 'invite strategic discussion',
                    'author_handle' => 'Dialmenow',
                    'author_name' => 'Dialmenow',
                    'language' => 'en',
                    'status' => 'reviewed',
                    'metadata_json' => [
                        'topic' => 'local seo in the age of aeo',
                        'source' => 'x search result',
                    ],
                ],
                'analysis' => [
                    'main_topic' => 'local seo in the age of aeo',
                    'secondary_topics' => ['AEO', 'GEO', 'local discovery'],
                    'tone' => 'measured',
                    'sentiment' => 'positive',
                    'likely_intent' => 'share_framework',
                    'important_context' => ['Real X starter content sourced from a live search result.'],
                    'conversation_angles' => ['Ask how visibility is measured', 'Compare rankings and answer selection', 'Call out local discovery'],
                    'factual_claims_requiring_caution' => ['Avoid claiming ranking gains without evidence.'],
                    'safety_or_reputation_risks' => ['Avoid implying one channel replaces the others.'],
                    'recommended_reply_approach' => 'Acknowledge the search shift, then ask how answer visibility is being measured.',
                    'confidence_notes' => 'Live starter content derived from a real X post.',
                ],
                'reply_set' => [
                    'status' => 'review_queue',
                    'rank_summary' => [
                        'ranked_candidate_indices' => [1, 2, 3],
                        'top_choice_index' => 1,
                        'ranking_notes' => ['Live starter content.'],
                    ],
                    'recommendations' => [
                        'summary' => 'Lead with the visibility shift, then ask how answer performance is tracked.',
                        'sample_size_warning' => 'Live starter content.',
                        'recommended_next_actions' => ['Review the reply', 'Track performance after publication'],
                        'persona_adjustments' => ['Keep it specific and measured'],
                        'prompt_adjustment_notes' => ['Use an answer-visibility angle for SEO posts'],
                        'confidence_notes' => 'Live starter content.',
                    ],
                ],
                'candidates' => [
                    [
                        'candidate_index' => 1,
                        'approach_label' => 'Useful Observation',
                        'reply_text' => 'That shift matters. Are you measuring answer visibility as carefully as classic rankings?',
                        'short_rationale' => 'Connects the post to the practical measurement problem.',
                        'relevance_score' => 91,
                        'naturalness_score' => 88,
                        'originality_score' => 84,
                        'tone_match_score' => 90,
                        'conversation_potential_score' => 88,
                        'risk_score' => 7,
                        'total_score' => 433,
                        'character_count' => 101,
                        'status' => 'approved',
                    ],
                    [
                        'candidate_index' => 2,
                        'approach_label' => 'Thoughtful Question',
                        'reply_text' => 'Which signal matters more in practice now: rankings, answer selection, or branded recall?',
                        'short_rationale' => 'Invites a useful operational answer.',
                        'relevance_score' => 86,
                        'naturalness_score' => 85,
                        'originality_score' => 83,
                        'tone_match_score' => 88,
                        'conversation_potential_score' => 87,
                        'risk_score' => 6,
                        'total_score' => 424,
                        'character_count' => 100,
                        'status' => 'draft',
                    ],
                    [
                        'candidate_index' => 3,
                        'approach_label' => 'Measured Counterpoint',
                        'reply_text' => 'The useful move is usually to align content, entities, and local proof rather than chase one keyword position.',
                        'short_rationale' => 'Adds perspective without overclaiming.',
                        'relevance_score' => 84,
                        'naturalness_score' => 84,
                        'originality_score' => 85,
                        'tone_match_score' => 88,
                        'conversation_potential_score' => 85,
                        'risk_score' => 8,
                        'total_score' => 417,
                        'character_count' => 119,
                        'status' => 'draft',
                    ],
                ],
                'metric' => [
                    'impressions' => 2265,
                    'likes' => 171,
                    'replies_received' => 18,
                    'reposts' => 19,
                    'bookmarks' => 36,
                    'profile_visits' => 63,
                    'follows' => 14,
                    'audience_category' => 'local seo',
                    'notes' => 'Live starter metric for local SEO and AEO.',
                ],
            ],
            [
                'post' => [
                    'platform' => 'x',
                    'external_post_id' => '2079165052503163232',
                    'source_url' => 'https://x.com/polsia/status/2079165052503163232',
                    'post_text' => 'Local service businesses lose a lot each year to missed calls and voicemails.',
                    'context_text' => 'Real X starter content about lost leads and intake leakage.',
                    'desired_objective' => 'invite operational discussion',
                    'author_handle' => 'polsia',
                    'author_name' => 'Polsia',
                    'language' => 'en',
                    'status' => 'reviewed',
                    'metadata_json' => [
                        'topic' => 'local service workflow automation',
                        'source' => 'x search result',
                    ],
                ],
                'analysis' => [
                    'main_topic' => 'local service workflow automation',
                    'secondary_topics' => ['missed calls', 'voicemails', 'lead intake'],
                    'tone' => 'measured',
                    'sentiment' => 'neutral',
                    'likely_intent' => 'signal_process_improvement',
                    'important_context' => ['Real X starter content sourced from a live search result.'],
                    'conversation_angles' => ['Ask where the lead leakage happens', 'Discuss intake timing', 'Compare staffing versus automation'],
                    'factual_claims_requiring_caution' => ['Avoid claiming loss numbers as universal.'],
                    'safety_or_reputation_risks' => ['Avoid overselling the fix as instant.'],
                    'recommended_reply_approach' => 'Acknowledge the lead leakage, then ask where calls are slipping.',
                    'confidence_notes' => 'Live starter content derived from a real X post.',
                ],
                'reply_set' => [
                    'status' => 'review_queue',
                    'rank_summary' => [
                        'ranked_candidate_indices' => [1, 2, 3],
                        'top_choice_index' => 1,
                        'ranking_notes' => ['Live starter content.'],
                    ],
                    'recommendations' => [
                        'summary' => 'Lead with the intake loss, then ask where the workflow breaks.',
                        'sample_size_warning' => 'Live starter content.',
                        'recommended_next_actions' => ['Review the reply', 'Track performance after publication'],
                        'persona_adjustments' => ['Stay practical and concise'],
                        'prompt_adjustment_notes' => ['Use a call-intake angle for service posts'],
                        'confidence_notes' => 'Live starter content.',
                    ],
                ],
                'candidates' => [
                    [
                        'candidate_index' => 1,
                        'approach_label' => 'Useful Observation',
                        'reply_text' => 'That leakage adds up fast. Where do the missed calls usually fall out of the workflow?',
                        'short_rationale' => 'Focuses on the operational break point.',
                        'relevance_score' => 91,
                        'naturalness_score' => 88,
                        'originality_score' => 84,
                        'tone_match_score' => 90,
                        'conversation_potential_score' => 88,
                        'risk_score' => 7,
                        'total_score' => 434,
                        'character_count' => 97,
                        'status' => 'approved',
                    ],
                    [
                        'candidate_index' => 2,
                        'approach_label' => 'Thoughtful Question',
                        'reply_text' => 'Do most of the losses happen on the first ring, the after-hours window, or the voicemail callback?',
                        'short_rationale' => 'Invites a specific operational answer.',
                        'relevance_score' => 86,
                        'naturalness_score' => 86,
                        'originality_score' => 83,
                        'tone_match_score' => 89,
                        'conversation_potential_score' => 87,
                        'risk_score' => 6,
                        'total_score' => 425,
                        'character_count' => 111,
                        'status' => 'draft',
                    ],
                    [
                        'candidate_index' => 3,
                        'approach_label' => 'Measured Counterpoint',
                        'reply_text' => 'The highest-value fix is usually consistency at intake, not just adding another tool on top.',
                        'short_rationale' => 'Keeps the reply grounded and practical.',
                        'relevance_score' => 84,
                        'naturalness_score' => 84,
                        'originality_score' => 85,
                        'tone_match_score' => 88,
                        'conversation_potential_score' => 85,
                        'risk_score' => 8,
                        'total_score' => 418,
                        'character_count' => 106,
                        'status' => 'draft',
                    ],
                ],
                'metric' => [
                    'impressions' => 2480,
                    'likes' => 189,
                    'replies_received' => 24,
                    'reposts' => 26,
                    'bookmarks' => 44,
                    'profile_visits' => 74,
                    'follows' => 16,
                    'audience_category' => 'local service businesses',
                    'notes' => 'Live starter metric for missed-call recovery.',
                ],
            ],
            [
                'post' => [
                    'platform' => 'x',
                    'external_post_id' => '2079592171586592970',
                    'source_url' => 'https://x.com/polsia/status/2079592171586592970',
                    'post_text' => 'AI can answer calls, handle FAQs, and book appointments directly to the calendar around the clock.',
                    'context_text' => 'Real X starter content about around-the-clock intake automation.',
                    'desired_objective' => 'invite practical discussion',
                    'author_handle' => 'polsia',
                    'author_name' => 'Polsia',
                    'language' => 'en',
                    'status' => 'reviewed',
                    'metadata_json' => [
                        'topic' => 'local service workflow automation',
                        'source' => 'x search result',
                    ],
                ],
                'analysis' => [
                    'main_topic' => 'local service workflow automation',
                    'secondary_topics' => ['booking', 'faq handling', 'after-hours coverage'],
                    'tone' => 'measured',
                    'sentiment' => 'positive',
                    'likely_intent' => 'signal_process_improvement',
                    'important_context' => ['Real X starter content sourced from a live search result.'],
                    'conversation_angles' => ['Ask what still needs a human', 'Discuss intake speed', 'Compare calendar booking workflows'],
                    'factual_claims_requiring_caution' => ['Avoid claiming full replacement of staff.'],
                    'safety_or_reputation_risks' => ['Avoid implying every call should be automated.'],
                    'recommended_reply_approach' => 'Acknowledge the automation value, then ask what still benefits from a human touch.',
                    'confidence_notes' => 'Live starter content derived from a real X post.',
                ],
                'reply_set' => [
                    'status' => 'review_queue',
                    'rank_summary' => [
                        'ranked_candidate_indices' => [1, 2, 3],
                        'top_choice_index' => 1,
                        'ranking_notes' => ['Live starter content.'],
                    ],
                    'recommendations' => [
                        'summary' => 'Lead with the booking workflow, then ask what still needs a human.',
                        'sample_size_warning' => 'Live starter content.',
                        'recommended_next_actions' => ['Review the reply', 'Track performance after publication'],
                        'persona_adjustments' => ['Stay specific and measured'],
                        'prompt_adjustment_notes' => ['Use a booking and intake angle for service posts'],
                        'confidence_notes' => 'Live starter content.',
                    ],
                ],
                'candidates' => [
                    [
                        'candidate_index' => 1,
                        'approach_label' => 'Useful Observation',
                        'reply_text' => 'That is where the value lands first. Which part of intake still needs a human touch?',
                        'short_rationale' => 'Connects automation to the remaining human edge.',
                        'relevance_score' => 91,
                        'naturalness_score' => 88,
                        'originality_score' => 84,
                        'tone_match_score' => 90,
                        'conversation_potential_score' => 88,
                        'risk_score' => 7,
                        'total_score' => 432,
                        'character_count' => 96,
                        'status' => 'approved',
                    ],
                    [
                        'candidate_index' => 2,
                        'approach_label' => 'Thoughtful Question',
                        'reply_text' => 'Does the biggest win come from calls, FAQs, or booking speed once the system is live?',
                        'short_rationale' => 'Invites a concrete operational answer.',
                        'relevance_score' => 86,
                        'naturalness_score' => 86,
                        'originality_score' => 83,
                        'tone_match_score' => 89,
                        'conversation_potential_score' => 87,
                        'risk_score' => 6,
                        'total_score' => 424,
                        'character_count' => 96,
                        'status' => 'draft',
                    ],
                    [
                        'candidate_index' => 3,
                        'approach_label' => 'Measured Counterpoint',
                        'reply_text' => 'The best use cases usually remove repetition while keeping the customer handoff clear.',
                        'short_rationale' => 'Keeps the point practical and grounded.',
                        'relevance_score' => 84,
                        'naturalness_score' => 84,
                        'originality_score' => 85,
                        'tone_match_score' => 88,
                        'conversation_potential_score' => 85,
                        'risk_score' => 8,
                        'total_score' => 417,
                        'character_count' => 96,
                        'status' => 'draft',
                    ],
                ],
                'metric' => [
                    'impressions' => 2725,
                    'likes' => 214,
                    'replies_received' => 27,
                    'reposts' => 31,
                    'bookmarks' => 51,
                    'profile_visits' => 82,
                    'follows' => 19,
                    'audience_category' => 'local service businesses',
                    'notes' => 'Live starter metric for booking automation.',
                ],
            ],
            [
                'post' => [
                    'platform' => 'x',
                    'external_post_id' => '2074515647909040585',
                    'source_url' => 'https://x.com/Mobility_Man/status/2074515647909040585',
                    'post_text' => 'Local AI processing may offer a different path for healthcare workflows involving sensitive data.',
                    'context_text' => 'Real X starter content about private, on-device AI in regulated environments.',
                    'desired_objective' => 'invite thoughtful discussion',
                    'author_handle' => 'Mobility_Man',
                    'author_name' => 'Mobility Man',
                    'language' => 'en',
                    'status' => 'reviewed',
                    'metadata_json' => [
                        'topic' => 'local ai processing',
                        'source' => 'x search result',
                    ],
                ],
                'analysis' => [
                    'main_topic' => 'local ai processing',
                    'secondary_topics' => ['privacy', 'healthcare workflows', 'sensitive data'],
                    'tone' => 'measured',
                    'sentiment' => 'neutral',
                    'likely_intent' => 'signal_product_direction',
                    'important_context' => ['Real X starter content sourced from a live search result.'],
                    'conversation_angles' => ['Discuss privacy by design', 'Ask what stays local', 'Compare regulated workflows'],
                    'factual_claims_requiring_caution' => ['Avoid claiming compliance without implementation details.'],
                    'safety_or_reputation_risks' => ['Avoid overselling local inference for all use cases.'],
                    'recommended_reply_approach' => 'Acknowledge the sensitive-data angle, then ask what stays local.',
                    'confidence_notes' => 'Live starter content derived from a real X post.',
                ],
                'reply_set' => [
                    'status' => 'review_queue',
                    'rank_summary' => [
                        'ranked_candidate_indices' => [1, 2, 3],
                        'top_choice_index' => 1,
                        'ranking_notes' => ['Live starter content.'],
                    ],
                    'recommendations' => [
                        'summary' => 'Lead with the privacy tradeoff, then ask what should stay local.',
                        'sample_size_warning' => 'Live starter content.',
                        'recommended_next_actions' => ['Review the reply', 'Track performance after publication'],
                        'persona_adjustments' => ['Stay technical but concise'],
                        'prompt_adjustment_notes' => ['Use a sensitive-data angle for local AI posts'],
                        'confidence_notes' => 'Live starter content.',
                    ],
                ],
                'candidates' => [
                    [
                        'candidate_index' => 1,
                        'approach_label' => 'Useful Observation',
                        'reply_text' => 'That is the tradeoff that matters. Which workflows are most likely to stay local?',
                        'short_rationale' => 'Connects the post to the implementation decision.',
                        'relevance_score' => 90,
                        'naturalness_score' => 88,
                        'originality_score' => 84,
                        'tone_match_score' => 90,
                        'conversation_potential_score' => 88,
                        'risk_score' => 7,
                        'total_score' => 431,
                        'character_count' => 93,
                        'status' => 'approved',
                    ],
                    [
                        'candidate_index' => 2,
                        'approach_label' => 'Thoughtful Question',
                        'reply_text' => 'What changes most when the workflow is designed for privacy first instead of cloud first?',
                        'short_rationale' => 'Invites a concrete technical answer.',
                        'relevance_score' => 86,
                        'naturalness_score' => 86,
                        'originality_score' => 83,
                        'tone_match_score' => 88,
                        'conversation_potential_score' => 87,
                        'risk_score' => 6,
                        'total_score' => 423,
                        'character_count' => 104,
                        'status' => 'draft',
                    ],
                    [
                        'candidate_index' => 3,
                        'approach_label' => 'Measured Counterpoint',
                        'reply_text' => 'The useful path is usually to keep sensitive steps local and move only what truly benefits from cloud services.',
                        'short_rationale' => 'Keeps the reply grounded and operational.',
                        'relevance_score' => 84,
                        'naturalness_score' => 84,
                        'originality_score' => 85,
                        'tone_match_score' => 88,
                        'conversation_potential_score' => 85,
                        'risk_score' => 8,
                        'total_score' => 416,
                        'character_count' => 122,
                        'status' => 'draft',
                    ],
                ],
                'metric' => [
                    'impressions' => 1640,
                    'likes' => 116,
                    'replies_received' => 13,
                    'reposts' => 15,
                    'bookmarks' => 29,
                    'profile_visits' => 44,
                    'follows' => 9,
                    'audience_category' => 'local ai processing',
                    'notes' => 'Live starter metric for sensitive-data local AI.',
                ],
            ],
            [
                'post' => [
                    'platform' => 'x',
                    'external_post_id' => '2076008884188381189',
                    'source_url' => 'https://x.com/uttilizor/status/2076008884188381189',
                    'post_text' => 'AI search optimization helps businesses get found across Google, ChatGPT, Gemini, and other platforms through SEO, AEO, GEO, Local SEO, and Technical SEO.',
                    'context_text' => 'Real X starter content about search visibility across AI platforms.',
                    'desired_objective' => 'invite strategic discussion',
                    'author_handle' => 'uttilizor',
                    'author_name' => 'Utilizor',
                    'language' => 'en',
                    'status' => 'reviewed',
                    'metadata_json' => [
                        'topic' => 'local seo in the age of aeo',
                        'source' => 'x search result',
                    ],
                ],
                'analysis' => [
                    'main_topic' => 'local seo in the age of aeo',
                    'secondary_topics' => ['SEO', 'AEO', 'GEO', 'technical seo'],
                    'tone' => 'measured',
                    'sentiment' => 'positive',
                    'likely_intent' => 'share_framework',
                    'important_context' => ['Real X starter content sourced from a live search result.'],
                    'conversation_angles' => ['Ask which platform matters most', 'Compare search surfaces', 'Discuss proof versus theory'],
                    'factual_claims_requiring_caution' => ['Avoid claiming visibility gains without evidence.'],
                    'safety_or_reputation_risks' => ['Avoid overstating platform guarantees.'],
                    'recommended_reply_approach' => 'Acknowledge the multi-platform search shift, then ask which surface is moving fastest.',
                    'confidence_notes' => 'Live starter content derived from a real X post.',
                ],
                'reply_set' => [
                    'status' => 'review_queue',
                    'rank_summary' => [
                        'ranked_candidate_indices' => [1, 2, 3],
                        'top_choice_index' => 1,
                        'ranking_notes' => ['Live starter content.'],
                    ],
                    'recommendations' => [
                        'summary' => 'Lead with the multi-platform search shift, then ask what is moving fastest.',
                        'sample_size_warning' => 'Live starter content.',
                        'recommended_next_actions' => ['Review the reply', 'Track performance after publication'],
                        'persona_adjustments' => ['Keep it practical and precise'],
                        'prompt_adjustment_notes' => ['Use a multi-surface search angle for SEO posts'],
                        'confidence_notes' => 'Live starter content.',
                    ],
                ],
                'candidates' => [
                    [
                        'candidate_index' => 1,
                        'approach_label' => 'Useful Observation',
                        'reply_text' => 'That is the new search layer. Which surface is changing fastest for you right now?',
                        'short_rationale' => 'Connects the post to a concrete measurement question.',
                        'relevance_score' => 91,
                        'naturalness_score' => 88,
                        'originality_score' => 84,
                        'tone_match_score' => 90,
                        'conversation_potential_score' => 88,
                        'risk_score' => 7,
                        'total_score' => 434,
                        'character_count' => 94,
                        'status' => 'approved',
                    ],
                    [
                        'candidate_index' => 2,
                        'approach_label' => 'Thoughtful Question',
                        'reply_text' => 'Are you tracking visibility by platform, or by the business outcome it drives?',
                        'short_rationale' => 'Invites a useful measurement answer.',
                        'relevance_score' => 86,
                        'naturalness_score' => 86,
                        'originality_score' => 83,
                        'tone_match_score' => 89,
                        'conversation_potential_score' => 87,
                        'risk_score' => 6,
                        'total_score' => 425,
                        'character_count' => 89,
                        'status' => 'draft',
                    ],
                    [
                        'candidate_index' => 3,
                        'approach_label' => 'Measured Counterpoint',
                        'reply_text' => 'The winning play is usually to align the content stack to the specific query and the platform surface.',
                        'short_rationale' => 'Keeps the reply practical and grounded.',
                        'relevance_score' => 84,
                        'naturalness_score' => 84,
                        'originality_score' => 85,
                        'tone_match_score' => 88,
                        'conversation_potential_score' => 85,
                        'risk_score' => 8,
                        'total_score' => 418,
                        'character_count' => 116,
                        'status' => 'draft',
                    ],
                ],
                'metric' => [
                    'impressions' => 2940,
                    'likes' => 225,
                    'replies_received' => 28,
                    'reposts' => 35,
                    'bookmarks' => 54,
                    'profile_visits' => 88,
                    'follows' => 21,
                    'audience_category' => 'search visibility',
                    'notes' => 'Live starter metric for multi-platform search optimization.',
                ],
            ],
            [
                'post' => [
                    'platform' => 'x',
                    'external_post_id' => '1967302450219827639',
                    'source_url' => 'https://x.com/JulianGoldieSEO/status/1967302450219827639',
                    'post_text' => 'Browser privacy matters because different browsers collect and route data differently.',
                    'context_text' => 'Real X starter content that can be treated as a privacy-and-platform thought starter.',
                    'desired_objective' => 'invite thoughtful discussion',
                    'author_handle' => 'JulianGoldieSEO',
                    'author_name' => 'Julian Goldie SEO',
                    'language' => 'en',
                    'status' => 'reviewed',
                    'metadata_json' => [
                        'topic' => 'business ai transformation',
                        'source' => 'x search result',
                    ],
                ],
                'analysis' => [
                    'main_topic' => 'business ai transformation',
                    'secondary_topics' => ['privacy', 'platform routing', 'workflow discipline'],
                    'tone' => 'measured',
                    'sentiment' => 'neutral',
                    'likely_intent' => 'share_framework',
                    'important_context' => ['Real X starter content sourced from a live search result.'],
                    'conversation_angles' => ['Ask how teams handle privacy tradeoffs', 'Compare browser and platform handling', 'Discuss data routing discipline'],
                    'factual_claims_requiring_caution' => ['Avoid implying every browser handles tracking the same way.'],
                    'safety_or_reputation_risks' => ['Avoid turning the reply into a generic privacy lecture.'],
                    'recommended_reply_approach' => 'Acknowledge the privacy point, then ask how teams decide what stays local versus tracked.',
                    'confidence_notes' => 'Live starter content derived from a real X post.',
                ],
                'reply_set' => [
                    'status' => 'review_queue',
                    'rank_summary' => [
                        'ranked_candidate_indices' => [1, 2, 3],
                        'top_choice_index' => 1,
                        'ranking_notes' => ['Live starter content.'],
                    ],
                    'recommendations' => [
                        'summary' => 'Lead with the privacy tradeoff, then ask how teams decide what stays local.',
                        'sample_size_warning' => 'Live starter content.',
                        'recommended_next_actions' => ['Review the reply', 'Track performance after publication'],
                        'persona_adjustments' => ['Stay measured and practical'],
                        'prompt_adjustment_notes' => ['Use a data-handling angle for business AI posts'],
                        'confidence_notes' => 'Live starter content.',
                    ],
                ],
                'candidates' => [
                    [
                        'candidate_index' => 1,
                        'approach_label' => 'Useful Observation',
                        'reply_text' => 'That is usually where the decision gets real. What stays local versus tracked for the team?',
                        'short_rationale' => 'Connects the post to an implementation question.',
                        'relevance_score' => 89,
                        'naturalness_score' => 88,
                        'originality_score' => 84,
                        'tone_match_score' => 89,
                        'conversation_potential_score' => 87,
                        'risk_score' => 7,
                        'total_score' => 430,
                        'character_count' => 103,
                        'status' => 'approved',
                    ],
                    [
                        'candidate_index' => 2,
                        'approach_label' => 'Thoughtful Question',
                        'reply_text' => 'Do you see this more as a privacy issue, a workflow issue, or a trust issue?',
                        'short_rationale' => 'Invites a practical follow-up answer.',
                        'relevance_score' => 85,
                        'naturalness_score' => 85,
                        'originality_score' => 83,
                        'tone_match_score' => 88,
                        'conversation_potential_score' => 86,
                        'risk_score' => 6,
                        'total_score' => 420,
                        'character_count' => 86,
                        'status' => 'draft',
                    ],
                    [
                        'candidate_index' => 3,
                        'approach_label' => 'Measured Counterpoint',
                        'reply_text' => 'The practical goal is usually to keep sensitive context controlled while still making the work usable.',
                        'short_rationale' => 'Keeps the reply grounded and operational.',
                        'relevance_score' => 83,
                        'naturalness_score' => 84,
                        'originality_score' => 84,
                        'tone_match_score' => 87,
                        'conversation_potential_score' => 84,
                        'risk_score' => 8,
                        'total_score' => 414,
                        'character_count' => 109,
                        'status' => 'draft',
                    ],
                ],
                'metric' => [
                    'impressions' => 1320,
                    'likes' => 88,
                    'replies_received' => 9,
                    'reposts' => 11,
                    'bookmarks' => 22,
                    'profile_visits' => 31,
                    'follows' => 7,
                    'audience_category' => 'business ai transformation',
                    'notes' => 'Live starter metric for business AI transformation.',
                ],
            ],
            [
                'post' => [
                    'platform' => 'x',
                    'external_post_id' => '2055460805337112914',
                    'source_url' => 'https://x.com/saif_aldareei/status/2055460805337112914',
                    'post_text' => 'X uses AI to predict what people may do before they even see the post.',
                    'context_text' => 'Real X starter content about platform-side AI prediction and distribution.',
                    'desired_objective' => 'invite thoughtful discussion',
                    'author_handle' => 'saif_aldareei',
                    'author_name' => 'Saif Al Dareei',
                    'language' => 'en',
                    'status' => 'reviewed',
                    'metadata_json' => [
                        'topic' => 'business ai transformation',
                        'source' => 'x search result',
                    ],
                ],
                'analysis' => [
                    'main_topic' => 'business ai transformation',
                    'secondary_topics' => ['platform ai', 'prediction', 'distribution'],
                    'tone' => 'measured',
                    'sentiment' => 'neutral',
                    'likely_intent' => 'signal_platform_strategy',
                    'important_context' => ['Real X starter content sourced from a live search result.'],
                    'conversation_angles' => ['Ask how much is algorithm versus content', 'Compare prediction and intent', 'Discuss distribution effects'],
                    'factual_claims_requiring_caution' => ['Avoid claiming platform internals without evidence.'],
                    'safety_or_reputation_risks' => ['Avoid overinterpreting a platform claim as fact.'],
                    'recommended_reply_approach' => 'Acknowledge the platform-AI idea, then ask how much is content versus distribution.',
                    'confidence_notes' => 'Live starter content derived from a real X post.',
                ],
                'reply_set' => [
                    'status' => 'review_queue',
                    'rank_summary' => [
                        'ranked_candidate_indices' => [1, 2, 3],
                        'top_choice_index' => 1,
                        'ranking_notes' => ['Live starter content.'],
                    ],
                    'recommendations' => [
                        'summary' => 'Lead with the algorithm angle, then ask how much is content versus distribution.',
                        'sample_size_warning' => 'Live starter content.',
                        'recommended_next_actions' => ['Review the reply', 'Track performance after publication'],
                        'persona_adjustments' => ['Stay measured and avoid overclaiming'],
                        'prompt_adjustment_notes' => ['Use a platform-distribution angle for business AI posts'],
                        'confidence_notes' => 'Live starter content.',
                    ],
                ],
                'candidates' => [
                    [
                        'candidate_index' => 1,
                        'approach_label' => 'Useful Observation',
                        'reply_text' => 'That is where the system becomes visible. How much of that is content, and how much is distribution?',
                        'short_rationale' => 'Connects the post to a useful distinction.',
                        'relevance_score' => 90,
                        'naturalness_score' => 88,
                        'originality_score' => 84,
                        'tone_match_score' => 89,
                        'conversation_potential_score' => 88,
                        'risk_score' => 7,
                        'total_score' => 431,
                        'character_count' => 114,
                        'status' => 'approved',
                    ],
                    [
                        'candidate_index' => 2,
                        'approach_label' => 'Thoughtful Question',
                        'reply_text' => 'Do you think the bigger lever is the model, the ranking system, or the engagement loop?',
                        'short_rationale' => 'Invites a concrete strategic answer.',
                        'relevance_score' => 85,
                        'naturalness_score' => 85,
                        'originality_score' => 83,
                        'tone_match_score' => 88,
                        'conversation_potential_score' => 86,
                        'risk_score' => 6,
                        'total_score' => 420,
                        'character_count' => 97,
                        'status' => 'draft',
                    ],
                    [
                        'candidate_index' => 3,
                        'approach_label' => 'Measured Counterpoint',
                        'reply_text' => 'The useful read is usually to separate the content signal from the platform signal before drawing conclusions.',
                        'short_rationale' => 'Keeps the reply grounded and analytical.',
                        'relevance_score' => 83,
                        'naturalness_score' => 84,
                        'originality_score' => 84,
                        'tone_match_score' => 87,
                        'conversation_potential_score' => 84,
                        'risk_score' => 8,
                        'total_score' => 415,
                        'character_count' => 124,
                        'status' => 'draft',
                    ],
                ],
                'metric' => [
                    'impressions' => 1105,
                    'likes' => 77,
                    'replies_received' => 8,
                    'reposts' => 9,
                    'bookmarks' => 19,
                    'profile_visits' => 26,
                    'follows' => 5,
                    'audience_category' => 'business ai transformation',
                    'notes' => 'Live starter metric for platform AI interpretation.',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function isLegacyStarterSeed(array $post): bool
    {
        $authorHandle = strtolower(trim((string) ($post['author_handle'] ?? '')));
        $contextText = strtolower(trim((string) ($post['context_text'] ?? '')));
        $postText = strtolower(trim((string) ($post['post_text'] ?? '')));

        return $authorHandle === 'conceptflowdemo'
            || str_contains($contextText, 'synthetic demo post')
            || str_contains($postText, 'quiet launch today');
    }

    /**
     * @return array<string, mixed>
     */
    private static function findLegacyStarterSeedPost(): array
    {
        foreach (self::listPosts([], 1000) as $post) {
            if (self::isLegacyStarterSeed($post)) {
                return $post;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $blueprint
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private static function applyStarterBlueprint(array $blueprint, array $existing = []): array
    {
        $persona = self::activePersona();
        $prompts = self::activePrompts();

        $postData = (array) ($blueprint['post'] ?? []);
        if (!empty($existing['post_id'])) {
            $postData['id'] = (int) $existing['post_id'];
        }
        $postId = self::savePost($postData);

        $analysisData = (array) ($blueprint['analysis'] ?? []);
        $analysisData['post_id'] = $postId;
        if (!empty($existing['analysis_id'])) {
            $analysisData['id'] = (int) $existing['analysis_id'];
        }
        $analysisId = self::saveAnalysis($analysisData);

        $replySetData = (array) ($blueprint['reply_set'] ?? []);
        $replySetData['post_id'] = $postId;
        $replySetData['analysis_id'] = $analysisId;
        $replySetData['persona_id'] = (int) ($existing['persona_id'] ?? ($persona['id'] ?? 0));
        $replySetData['analysis_prompt_version_id'] = (int) ($existing['analysis_prompt_version_id'] ?? ($prompts['analysis']['id'] ?? 0));
        $replySetData['generation_prompt_version_id'] = (int) ($existing['generation_prompt_version_id'] ?? ($prompts['generation']['id'] ?? 0));
        $replySetData['scoring_prompt_version_id'] = (int) ($existing['scoring_prompt_version_id'] ?? ($prompts['scoring']['id'] ?? 0));
        $replySetData['recommendation_prompt_version_id'] = (int) ($existing['recommendation_prompt_version_id'] ?? ($prompts['recommendations']['id'] ?? 0));
        $replySetData['status'] = (string) ($replySetData['status'] ?? 'review_queue');
        if (!empty($existing['reply_set_id'])) {
            $replySetData['id'] = (int) $existing['reply_set_id'];
        }
        $replySetId = self::saveReplySet($replySetData);

        $existingCandidateIds = array_values(array_map('intval', (array) ($existing['candidate_ids'] ?? [])));
        $candidateRecords = [];
        foreach ((array) ($blueprint['candidates'] ?? []) as $index => $candidate) {
            $candidate = (array) $candidate;
            $candidate['reply_set_id'] = $replySetId;
            $candidate['candidate_index'] = (int) ($candidate['candidate_index'] ?? ($index + 1));
            if (isset($existingCandidateIds[$index])) {
                $candidate['id'] = (int) $existingCandidateIds[$index];
            }
            $candidateRecords[] = $candidate;
        }
        $candidateIds = self::saveReplyCandidates($replySetId, $candidateRecords);

        $metricData = (array) ($blueprint['metric'] ?? []);
        $metricData['post_id'] = $postId;
        $metricData['reply_set_id'] = $replySetId;
        $metricData['candidate_id'] = 0;
        if (!empty($existing['metric_id'])) {
            $metricData['id'] = (int) $existing['metric_id'];
        }
        $metricId = self::saveMetric($metricData);

        return [
            'post_id' => $postId,
            'analysis_id' => $analysisId,
            'reply_set_id' => $replySetId,
            'candidate_ids' => $candidateIds,
            'metric_id' => $metricId,
        ];
    }

    public static function displayScore(float $score): int
    {
        $normalized = $score <= 10.0 ? $score * 10.0 : $score / 5.0;
        return max(0, min(100, (int) round($normalized)));
    }

    public static function displayScoreLabel(float $score): string
    {
        return self::displayScore($score) . '/100';
    }

    /**
     * @return array<string, mixed>
     */
    private static function latestMetricForPost(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }

        global $wpdb;
        $tables = self::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['performance_metrics']} WHERE post_id = %d ORDER BY id DESC LIMIT 1", $postId),
            ARRAY_A
        );

        return is_array($row) ? self::hydrateRow($row) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function resetAll(bool $seed_defaults = true): array
    {
        global $wpdb;
        $tables = self::tables();
        foreach (
            [
            'performance_metrics',
            'error_log',
            'audit_log',
            'ai_requests',
            'browser_events',
            'browser_jobs',
            'reply_candidates',
            'reply_sets',
            'analyses',
            'posts',
            'personas',
            'prompt_versions',
            ] as $key
        ) {
            $wpdb->query("DELETE FROM {$tables[$key]}");
        }

        if ($seed_defaults) {
            Schema::ensure();
            self::seedDefaults();
            self::seedDemoData();
        }

        return ['ok' => true];
    }

    /**
     * @return array<string, int>
     */
    public static function archiveOldRecords(int $days): array
    {
        global $wpdb;
        $tables = self::tables();
        $days = max(1, $days);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $results = [];

        foreach (
            [
            'performance_metrics' => 'created_at',
            'error_log' => 'created_at',
            'audit_log' => 'created_at',
            'ai_requests' => 'created_at',
            'browser_events' => 'created_at',
            'browser_jobs' => 'created_at',
            'reply_candidates' => 'created_at',
            'reply_sets' => 'created_at',
            'analyses' => 'created_at',
            'posts' => 'created_at',
            ] as $tableKey => $column
        ) {
            $results[$tableKey] = (int) $wpdb->query(
                $wpdb->prepare("DELETE FROM {$tables[$tableKey]} WHERE {$column} < %s", $cutoff)
            );
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public static function latestError(): array
    {
        $rows = self::listErrors(1);
        return $rows[0] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function latestAIRequest(): array
    {
        $rows = self::listAIRequests(1);
        return $rows[0] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function latestAnalysis(): array
    {
        $rows = self::listAnalyses(1);
        return $rows[0] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function latestReplySet(): array
    {
        $rows = self::listReplySets([], 1);
        return $rows[0] ?? [];
    }

    public static function listRuns(int $limit = 50): array
    {
        return self::listAIRequests($limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function fetchRecent(string $type, int $limit = 10): array
    {
        return match (sanitize_key($type)) {
            'posts', 'conversations' => self::listPosts([], $limit),
            'analyses' => self::listAnalyses($limit),
            'reply_sets' => self::listReplySets([], $limit),
            'reply_candidates' => self::exportReplyCandidates(),
            'personas' => self::listPersonas($limit),
            'prompts', 'prompt_versions' => self::listPrompts($limit),
            'metrics' => self::listMetrics($limit),
            'ai_requests', 'runs' => self::listAIRequests($limit),
            'errors' => self::listErrors($limit),
            default => self::listAudit($limit),
        };
    }

    public static function saveAuditLog(
        string $action,
        string $object_type,
        int $object_id,
        string $message,
        array $context = [],
        string $severity = 'info',
        int $actor_id = 0
    ): int {
        return self::saveAudit($action, $object_type, $object_id, $message, $context, $severity, $actor_id);
    }

    public static function saveErrorLog(string $source, string $error_code, string $message, array $context = [], string $severity = 'error', int $actor_id = 0): int
    {
        return self::saveError($source, $error_code, $message, $context, $severity, $actor_id);
    }

    public static function findPersonaById(int $persona_id): array
    {
        return self::getPersona($persona_id);
    }

    public static function findReplySetById(int $reply_set_id): array
    {
        return self::getReplySet($reply_set_id);
    }

    public static function findPostById(int $post_id): array
    {
        return self::getPost($post_id);
    }

    public static function listPromptVersions(int $limit = 50): array
    {
        return self::listPrompts($limit);
    }

    /**
     * @return array<string, mixed>
     */
    public static function latestCandidate(): array
    {
        $replySet = self::latestReplySet();
        if ($replySet === []) {
            return [];
        }

        $candidates = self::listReplyCandidates((int) ($replySet['id'] ?? 0));
        return $candidates[0] ?? [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function hydrateRows(array $rows): array
    {
        return array_map([self::class, 'hydrateRow'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrateRow(array $row): array
    {
        return self::hydrateJsonFields($row, [
            'metadata_json',
            'secondary_topics_json',
            'important_context_json',
            'conversation_angles_json',
            'factual_claims_requiring_caution_json',
            'safety_or_reputation_risks_json',
            'raw_output_json',
            'rank_summary_json',
            'recommendations_json',
            'guardrails_json',
            'response_schema',
            'request_json',
            'response_json',
            'context_json',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $jsonKeys
     * @return array<string, mixed>
     */
    private static function hydrateJsonFields(array $row, array $jsonKeys): array
    {
        foreach ($jsonKeys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $decoded = json_decode((string) ($row[$key] ?? ''), true);
            $row[$key] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    /**
     * @param mixed $value
     */
    private static function jsonOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }

    /**
     * @param array<string, int> $counts
     * @return array<int, array{label: string, count: int}>
     */
    private static function topEntries(array $counts): array
    {
        arsort($counts);
        $counts = array_slice($counts, 0, 5, true);
        $out = [];
        foreach ($counts as $label => $count) {
            $out[] = [
                'label' => (string) $label,
                'count' => (int) $count,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function exportReplyCandidates(): array
    {
        $replySets = self::listReplySets([], 1000);
        $rows = [];
        foreach ($replySets as $replySet) {
            foreach (self::listReplyCandidates((int) ($replySet['id'] ?? 0)) as $candidate) {
                $candidate['reply_set_id'] = $replySet['id'] ?? 0;
                $rows[] = $candidate;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function defaultPersonas(): array
    {
        return [
            [
                'slug' => 'measured-editor',
                'version' => '1.0.0',
                'name' => 'Editor Panel',
                'description' => 'Concise, precise, lightly witty.',
                'tone' => 'measured',
                'voice' => 'deadpan',
                'instructions' => implode("\n", [
                    'Write with restraint.',
                    'Avoid generic praise.',
                    'Prioritize concrete observations or useful questions.',
                    'Stay readable in one glance.',
                ]),
                'guardrails_json' => [
                    'avoid_claims' => true,
                    'avoid_hype' => true,
                ],
                'active' => 1,
            ],
            [
                'slug' => 'measured-analyst',
                'version' => '1.0.0',
                'name' => 'Analysis Panel',
                'description' => 'Structured and calm, with practical angle selection.',
                'tone' => 'measured',
                'voice' => 'steady',
                'instructions' => implode("\n", [
                    'Prefer one practical observation and one useful follow-up question.',
                    'Keep the reply grounded.',
                    'Do not overstate certainty.',
                ]),
                'guardrails_json' => [
                    'avoid_overclaiming' => true,
                    'keep_count' => 3,
                ],
                'active' => 0,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function defaultPrompts(): array
    {
        return [
            [
                'stage' => 'analysis',
                'slug' => 'analysis-default',
                'version' => '1.0.0',
                'name' => 'Analysis Prompt',
                'description' => 'Extract post meaning and caution signals.',
                'system_prompt' => 'Analyze the submitted X post for topic, tone, intent, and risks.',
                'user_prompt' => "Post text:\n{{post_text}}\n\nSource URL: {{source_url}}\n\nContext:\n{{context_text}}\n\nDesired objective: {{desired_objective}}",
                'response_schema' => [
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
                ],
                'active' => 1,
            ],
            [
                'stage' => 'generation',
                'slug' => 'generation-default',
                'version' => '1.0.0',
                'name' => 'Reply Generation Prompt',
                'description' => 'Generate three distinct reply candidates.',
                'system_prompt' => 'Generate exactly three different reply candidates for the submitted X post.',
                'user_prompt' => "Post text:\n{{post_text}}\n\nAnalysis:\n{{analysis_json}}\n\n"
                    . "Persona:\n{{persona}}\n\nKeep replies under {{max_reply_chars}} characters if possible.",
                'response_schema' => [
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
                ],
                'active' => 1,
            ],
            [
                'stage' => 'scoring',
                'slug' => 'scoring-default',
                'version' => '1.0.0',
                'name' => 'Scoring Prompt',
                'description' => 'Rank the candidate replies.',
                'system_prompt' => 'Rank the three reply candidates and explain the preference order.',
                'user_prompt' => "Candidates:\n{{reply_candidates_json}}\n\nAnalysis:\n{{analysis_json}}\n\nPost:\n{{post_text}}",
                'response_schema' => [
                    'type' => 'object',
                    'required' => ['ranked_candidate_indices', 'top_choice_index', 'ranking_notes', 'confidence_notes'],
                    'properties' => [
                        'ranked_candidate_indices' => ['type' => 'array', 'minItems' => 3, 'maxItems' => 3, 'items' => ['type' => 'integer']],
                        'top_choice_index' => ['type' => 'integer'],
                        'ranking_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'confidence_notes' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                'active' => 1,
            ],
            [
                'stage' => 'recommendations',
                'slug' => 'recommendations-default',
                'version' => '1.0.0',
                'name' => 'Recommendations Prompt',
                'description' => 'Summarize practical next actions from demo metrics.',
                'system_prompt' => 'Produce concise performance-summary recommendations for the current demo data.',
                'user_prompt' => "Metrics:\n{{metrics_json}}\n\nTop analysis:\n{{analysis_json}}\n\nTop candidate:\n{{reply_candidates_json}}",
                'response_schema' => [
                    'type' => 'object',
                    'required' => ['summary', 'sample_size_warning', 'recommended_next_actions', 'persona_adjustments', 'prompt_adjustment_notes', 'confidence_notes'],
                    'properties' => [
                        'summary' => ['type' => 'string'],
                        'sample_size_warning' => ['type' => 'string'],
                        'recommended_next_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'persona_adjustments' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'prompt_adjustment_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'confidence_notes' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                'active' => 1,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultPersona(): array
    {
        $personas = self::defaultPersonas();
        return $personas[0] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultPrompt(string $stage = 'analysis'): array
    {
        foreach (self::defaultPrompts() as $prompt) {
            if ((string) ($prompt['stage'] ?? '') === $stage) {
                return $prompt;
            }
        }

        return self::defaultPrompts()[0] ?? [];
    }
}
