<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

namespace Modules\McpPilotChat\Lib\Adapters;

use Modules\McpPilotChat\Lib\HttpClient;

/**
 * Template-based adapter for custom endpoints.
 *
 * The request template is JSON with unquoted placeholders, each replaced by a JSON-encoded value:
 *   {{model}} {{messages}} {{tools}} {{tool_choice}} {{system_prompt}} {{last_user_message}} {{temperature}}
 *   {{max_tokens}}
 *
 * Example: {"model": {{model}}, "messages": {{messages}}, "tools": {{tools}}}
 *
 * Response paths use dot notation, e.g. "choices.0.message.content" and "choices.0.message.tool_calls".
 * Tool calls must be in OpenAI format. Without {{tools}} and a tool-calls path the profile cannot use MCP.
 */
class GenericHttpAdapter extends LlmAdapter {

	public function supportsTools(): bool {
		$generic = $this->profile['generic'];

		return strpos($generic['request_template'], '{{tools}}') !== false
			&& trim($generic['response_tool_calls_path']) !== '';
	}

	public function send(array $messages, array $tools, array $options = []): array {
		$generic = $this->profile['generic'];

		$last_user = '';
		$system = '';

		foreach ($messages as $message) {
			if ($message['role'] === 'user') {
				$last_user = (string) $message['content'];
			}
			elseif ($message['role'] === 'system') {
				$system = (string) $message['content'];
			}
		}

		$values = [
			'model' => $this->profile['model'],
			'messages' => $messages,
			'tools' => $tools,
			'tool_choice' => $options['tool_choice'] ?? 'auto',
			'system_prompt' => $system,
			'last_user_message' => $last_user,
			'temperature' => $this->profile['temperature'] === '' ? null : $this->profile['temperature'],
			'max_tokens' => $this->profile['max_tokens']
		];

		$body = $generic['request_template'];

		foreach ($values as $name => $value) {
			$body = str_replace('{{'.$name.'}}', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				$body
			);
		}

		if (json_decode($body) === null) {
			throw $this->fail('the request template does not produce valid JSON.');
		}

		$http = HttpClient::request('POST', $this->url(), ['Content-Type' => 'application/json'] + $this->headers(),
			$body, $this->profile['timeout']
		);

		if ($http['status'] < 200 || $http['status'] >= 300) {
			throw $this->httpError($http);
		}

		$data = json_decode($http['body'], true);

		if (!is_array($data)) {
			throw $this->fail('the response is not JSON.');
		}

		$text = self::path($data, $generic['response_text_path']);
		$tool_calls = trim($generic['response_tool_calls_path']) !== ''
			? self::path($data, $generic['response_tool_calls_path'])
			: [];

		return [
			'text' => self::stripThinking(is_scalar($text) ? (string) $text : ''),
			'reasoning' => self::reasoningOf([], is_scalar($text) ? (string) $text : ''),
			'tool_calls' => self::normalizeToolCalls($tool_calls),
			'finish_reason' => '',
			'usage' => ['prompt_tokens' => null, 'completion_tokens' => null, 'cached_tokens' => null],
			'timings' => null,
			'model' => $this->profile['model'],
			'duration_ms' => $http['duration_ms']
		];
	}

	public function test(): array {
		try {
			$response = $this->send([['role' => 'user', 'content' => 'Reply with OK.']], []);

			return [
				'ok' => true,
				'message' => sprintf('Connected in %d ms.', $response['duration_ms']),
				'details' => [
					'reply' => mb_substr($response['text'], 0, 200),
					'tool_calling' => $this->supportsTools()
				]
			];
		}
		catch (\Throwable $e) {
			return ['ok' => false, 'message' => $e->getMessage(), 'details' => []];
		}
	}

	private function url(): string {
		$url = trim($this->profile['generic']['url']);

		return $url !== '' ? $url : $this->profile['base_url'];
	}

	private function headers(): array {
		$headers = $this->apiKeyHeaders();

		foreach (preg_split('/\r\n|\r|\n/', $this->profile['generic']['headers']) as $line) {
			$pos = strpos($line, ':');

			if ($pos !== false && trim(substr($line, 0, $pos)) !== '') {
				$headers[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
			}
		}

		return $headers;
	}

	private static function path(array $data, string $path) {
		$path = trim($path);

		if ($path === '') {
			return null;
		}

		$value = $data;

		foreach (explode('.', $path) as $key) {
			if (!is_array($value) || !array_key_exists($key, $value)) {
				return null;
			}

			$value = $value[$key];
		}

		return $value;
	}
}
