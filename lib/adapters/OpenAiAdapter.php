<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

namespace Modules\McpPilotChat\Lib\Adapters;

use Modules\McpPilotChat\Lib\Config,
	Modules\McpPilotChat\Lib\HttpClient;

/**
 * OpenAI-compatible Chat Completions with tool calling.
 *
 * Works with llama.cpp, Ollama, vLLM, LM Studio, LiteLLM, OpenAI, Groq, Mistral and similar.
 */
class OpenAiAdapter extends LlmAdapter {

	public function supportsTools(): bool {
		return true;
	}

	public function send(array $messages, array $tools, array $options = []): array {
		$body = [
			'model' => $this->profile['model'],
			'messages' => $messages,
			'max_tokens' => $this->profile['max_tokens'],
			'stream' => false
		];

		// Empty temperature = not sent (some providers reject values other than their default).
		if ($this->profile['temperature'] !== '') {
			$body['temperature'] = $this->profile['temperature'];
		}

		if ($tools) {
			$body['tools'] = $tools;
			$body['tool_choice'] = $options['tool_choice'] ?? 'auto';
		}

		$body = array_replace_recursive($body, Config::extraParams($this->profile));

		$http = HttpClient::request('POST', $this->profile['base_url'].'/chat/completions',
			['Content-Type' => 'application/json'] + $this->apiKeyHeaders(),
			json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			$this->profile['timeout']
		);

		if ($http['status'] < 200 || $http['status'] >= 300) {
			throw $this->httpError($http);
		}

		$data = json_decode($http['body'], true);

		if (!is_array($data) || !isset($data['choices'][0]['message'])) {
			throw $this->fail('unexpected response: '.self::errorExcerpt($http['body']));
		}

		$choice = $data['choices'][0];
		$message = $choice['message'];
		$usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

		$content = (string) ($message['content'] ?? '');

		return [
			'text' => self::stripThinking($content),
			'reasoning' => self::reasoningOf($message, $content),
			'tool_calls' => self::normalizeToolCalls($message['tool_calls'] ?? []),
			'finish_reason' => (string) ($choice['finish_reason'] ?? ''),
			'usage' => [
				'prompt_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
				'completion_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
				'cached_tokens' => isset($usage['prompt_tokens_details']['cached_tokens'])
					? (int) $usage['prompt_tokens_details']['cached_tokens']
					: null
			],
			'timings' => is_array($data['timings'] ?? null) ? $data['timings'] : null,
			'model' => (string) ($data['model'] ?? $this->profile['model']),
			'duration_ms' => $http['duration_ms']
		];
	}

	/**
	 * Checks that the server honors tool_choice "required", used by "Require a tool call on the first step".
	 */
	private function testForcedToolCall(array $result): array {
		$probe_tool = [
			'type' => 'function',
			'function' => [
				'name' => 'get_status',
				'description' => 'Returns the status of the monitoring system.',
				'parameters' => ['type' => 'object', 'properties' => new \stdClass()]
			]
		];

		try {
			$response = $this->send([['role' => 'user', 'content' => 'Hello!']], [$probe_tool],
				['tool_choice' => 'required']
			);

			if ($response['tool_calls']) {
				$result['message'] .= sprintf(' Forced tool calls work (%.1f s).', $response['duration_ms'] / 1000);
			}
			else {
				$result['ok'] = false;
				$result['message'] .= ' But the server ignored tool_choice "required": the model answered without a '.
					'tool. Turn off "Require a tool call on the first step" for this profile.';
			}
		}
		catch (\Throwable $e) {
			$result['ok'] = false;
			$result['message'] .= ' But forcing a tool call failed: '.$e->getMessage();
		}

		return $result;
	}

	public function test(): array {
		$http = HttpClient::request('GET', $this->profile['base_url'].'/models', $this->apiKeyHeaders(), null,
			min(20, $this->profile['timeout'])
		);

		if ($http['status'] >= 200 && $http['status'] < 300) {
			$data = json_decode($http['body'], true);
			$models = array_values(array_filter(array_map(static fn($m) => is_array($m) ? ($m['id'] ?? null) : null,
				is_array($data['data'] ?? null) ? $data['data'] : []
			)));

			$result = [
				'ok' => true,
				'message' => sprintf('Connected in %d ms.', $http['duration_ms']),
				'details' => ['models' => array_slice($models, 0, 20)]
			];

			if ($this->profile['require_tool_first']) {
				$result = $this->testForcedToolCall($result);
			}

			return $result;
		}

		return [
			'ok' => false,
			'message' => sprintf('HTTP %d: %s', $http['status'], self::errorExcerpt($http['body'])),
			'details' => []
		];
	}
}
