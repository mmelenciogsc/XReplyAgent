<?php

declare(strict_types=1);

namespace XReplyAgent\AI;

final class OpenAIProvider implements ProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint,
        private readonly string $model,
        private readonly float $temperature = 0.2
    ) {
    }

    public function name(): string
    {
        return 'openai';
    }

    public static function readKey(string $path): string
    {
        $path = trim($path);
        if ($path === '' || !is_readable($path)) {
            return '';
        }

        $raw = file_get_contents($path);
        return is_string($raw) ? trim($raw) : '';
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function complete(array $request): array
    {
        $payload = $this->buildPayload($request);
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];

        $response = wp_remote_post(
            $this->endpoint,
            [
                'timeout' => 45,
                'headers' => $headers,
                'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'error' => $response->get_error_message(),
                'content' => '',
                'usage' => [],
            ];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return [
                'ok' => false,
                'error' => 'Invalid JSON response from OpenAI.',
                'content' => '',
                'usage' => [],
                'raw' => wp_remote_retrieve_body($response),
            ];
        }

        $content = $this->extractContent($body);

        return [
            'ok' => trim($content) !== '',
            'content' => $content,
            'usage' => is_array($body['usage'] ?? null) ? $body['usage'] : [],
            'raw' => $body,
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function buildPayload(array $request): array
    {
        $messages = (array) ($request['messages'] ?? []);
        if ($messages === [] && isset($request['input_text'])) {
            $messages = [
                ['role' => 'user', 'content' => (string) $request['input_text']],
            ];
        }

        $payload = [
            'model' => $this->model,
            'temperature' => $this->temperature,
        ];

        $endpoint = strtolower($this->endpoint);
        if (str_contains($endpoint, '/responses')) {
            $payload['input'] = $messages;
            if (!empty($request['schema']) && is_array($request['schema'])) {
                $payload['text'] = [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => (string) ($request['schema_name'] ?? 'xreplyagent_stage'),
                        'strict' => true,
                        'schema' => $request['schema'],
                    ],
                ];
            }

            return $payload;
        }

        $payload['messages'] = $messages;
        if (!empty($request['schema']) && is_array($request['schema'])) {
            $payload['response_format'] = [
                'type' => 'json_object',
            ];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractContent(array $body): string
    {
        if (isset($body['output_text']) && is_string($body['output_text'])) {
            return $body['output_text'];
        }

        if (isset($body['output']) && is_array($body['output'])) {
            foreach ($body['output'] as $item) {
                if (!is_array($item) || !isset($item['content']) || !is_array($item['content'])) {
                    continue;
                }

                foreach ($item['content'] as $content) {
                    if (is_array($content) && isset($content['text']) && is_string($content['text'])) {
                        return $content['text'];
                    }
                }
            }
        }

        if (isset($body['choices'][0]['message']['content']) && is_string($body['choices'][0]['message']['content'])) {
            return $body['choices'][0]['message']['content'];
        }

        return '';
    }
}
