<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

namespace Modules\McpPilotChat\Lib\Adapters;

use Modules\McpPilotChat\Lib\ModuleException;

/**
 * Common base of LLM adapters.
 *
 * Internal message format is the OpenAI Chat Completions format:
 *   ['role' => 'system'|'user'|'assistant'|'tool', 'content' => string,
 *    'tool_calls' => [['id', 'type' => 'function', 'function' => ['name', 'arguments' => JSON string]]],
 *    'tool_call_id' => string]
 *
 * send() returns a normalized response:
 *   [
 *     'text' => string,
 *     'reasoning' => string|null,  // the model's thinking, when the provider returns it
 *     'tool_calls' => [['id' => string, 'name' => string, 'arguments' => array|null, 'arguments_raw' => string,
 *                       'raw' => array]],  // raw: the provider's call as received, echoed back in the history
 *     'finish_reason' => string,
 *     'usage' => ['prompt_tokens' => int|null, 'completion_tokens' => int|null, 'cached_tokens' => int|null],
 *     'timings' => array|null,
 *     'model' => string,
 *     'duration_ms' => int
 *   ]
 */
abstract class LlmAdapter {

	/** Tags some models use to put their reasoning into the answer text (Qwen: think, Gemma: thought). */
	private const THINKING_TAGS = ['think', 'thought'];

	/** Wait before retrying when a provider is overloaded and gives no wait time. */
	private const OVERLOAD_RETRY_WAIT = 5.0;

	protected array $profile;

	public function __construct(array $profile) {
		$this->profile = $profile;
	}

	public static function create(array $profile): self {
		switch ($profile['adapter']) {
			case 'generic_http':
				return new GenericHttpAdapter($profile);

			case 'openai':
			default:
				return new OpenAiAdapter($profile);
		}
	}

	/**
	 * @param array $options  Optional: 'tool_choice' => 'auto'|'required'.
	 */
	abstract public function send(array $messages, array $tools, array $options = []): array;

	/**
	 * @return array{ok: bool, message: string, details: array}
	 */
	abstract public function test(): array;

	/**
	 * Whether this profile can pass tools to the model and receive tool calls.
	 * MCP Pilot Chat always works through Zabbix MCP, so profiles without tool support cannot be used.
	 */
	abstract public function supportsTools(): bool;

	protected function apiKeyHeaders(): array {
		return $this->profile['api_key'] !== '' ? ['Authorization' => 'Bearer '.$this->profile['api_key']] : [];
	}

	/**
	 * Normalize OpenAI-style tool calls.
	 */
	protected static function normalizeToolCalls($tool_calls): array {
		$result = [];

		foreach (is_array($tool_calls) ? $tool_calls : [] as $i => $call) {
			if (!is_array($call)) {
				continue;
			}

			$function = is_array($call['function'] ?? null) ? $call['function'] : $call;
			$name = (string) ($function['name'] ?? '');

			if ($name === '') {
				continue;
			}

			$arguments_raw = $function['arguments'] ?? '{}';

			if (is_array($arguments_raw)) {
				$arguments = $arguments_raw;
				$arguments_raw = json_encode($arguments_raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
			else {
				$arguments_raw = trim((string) $arguments_raw);
				$arguments = $arguments_raw === '' ? [] : json_decode($arguments_raw, true);
				$arguments = is_array($arguments) ? $arguments : null;
			}

			$id = (string) ($call['id'] ?? '');

			$id = $id !== '' ? $id : 'call_'.bin2hex(random_bytes(6)).'_'.$i;

			/*
			 * The provider's tool call as received, to be sent back unchanged in the conversation history.
			 * Providers may attach data they need to see again (Gemini 3: "extra_content.google.thought_signature",
			 * others: signatures or ids of their own); keeping the whole object works for all of them.
			 * Only the OpenAI essentials are made well-formed; the streaming-only "index" is dropped.
			 */
			if (is_array($call['function'] ?? null)) {
				$raw = $call;
				unset($raw['index']);
				$raw['function']['arguments'] = $arguments_raw;
			}
			else {
				$raw = ['function' => ['name' => $name, 'arguments' => $arguments_raw]];
			}

			$raw = ['id' => $id, 'type' => $call['type'] ?? 'function'] + $raw;

			$result[] = [
				'id' => $id,
				'name' => $name,
				'arguments' => $arguments,
				'arguments_raw' => $arguments_raw,
				'raw' => $raw
			];
		}

		return $result;
	}

	/**
	 * Remove leaked reasoning ("<think>...</think>") that some local models emit in the content.
	 */
	protected static function stripThinking(string $text): string {
		foreach (self::THINKING_TAGS as $tag) {
			$text = preg_replace('#<'.$tag.'>.*?</'.$tag.'>#s', '', $text);

			$pos = strpos($text, '</'.$tag.'>');

			if ($pos !== false) {
				$text = substr($text, $pos + strlen('</'.$tag.'>'));
			}

			// An opening tag that is never closed: the answer was cut off while thinking.
			$pos = strpos($text, '<'.$tag.'>');

			if ($pos !== false) {
				$text = substr($text, 0, $pos);
			}
		}

		return trim($text);
	}

	/**
	 * The model's reasoning, if any: a separate field (Groq "reasoning", vLLM/DeepSeek/llama.cpp
	 * "reasoning_content") or a "<think>…</think>" block leaked into the content.
	 */
	protected static function reasoningOf(array $message, string $content): ?string {
		foreach (['reasoning', 'reasoning_content'] as $field) {
			if (isset($message[$field]) && is_string($message[$field]) && trim($message[$field]) !== '') {
				return trim($message[$field]);
			}
		}

		foreach (self::THINKING_TAGS as $tag) {
			if (preg_match('#<'.$tag.'>(.*?)(?:</'.$tag.'>|$)#s', $content, $m) && trim($m[1]) !== '') {
				return trim($m[1]);
			}

			$pos = strpos($content, '</'.$tag.'>');

			if ($pos !== false && trim(substr($content, 0, $pos)) !== '') {
				return trim(substr($content, 0, $pos));
			}
		}

		return null;
	}

	protected static function errorExcerpt(string $body): string {
		$decoded = json_decode($body, true);

		if (is_array($decoded)) {
			$message = $decoded['error']['message'] ?? $decoded['error'] ?? $decoded['message'] ?? null;

			if (is_string($message)) {
				$body = $message;
			}
		}

		$body = trim(preg_replace('/\s+/', ' ', $body));

		return mb_strlen($body) > 300 ? mb_substr($body, 0, 300).'…' : $body;
	}

	/**
	 * Turns a non-2xx HTTP response into a readable error.
	 *
	 * 429 (and 413 that only says "try again in …") becomes kind "rate_limit" with the wait time, so the
	 * browser can retry by itself. A request bigger than a per-minute limit can never succeed: "too_large".
	 */
	protected function httpError(array $http): ModuleException {
		$detail = self::errorExcerpt($http['body']);
		$name = $this->profile['name'];

		if ($http['status'] == 429 || $http['status'] == 413) {
			$limit = null;
			$requested = null;

			if (preg_match('/Limit\s+(\d+)/i', $detail, $m)) {
				$limit = (int) $m[1];
			}

			if (preg_match('/Requested\s+(\d+)/i', $detail, $m)) {
				$requested = (int) $m[1];
			}

			$per_minute = stripos($detail, 'per minute') !== false || stripos($detail, 'TPM') !== false;

			if ($limit !== null && $requested !== null && $requested > $limit) {
				return new ModuleException(sprintf('%s: this request needs %d tokens, but the provider allows only %d '.
					'tokens per minute for this model. Start a new chat, allow fewer tools, use the lean result fields '.
					'or a lower "Max tokens per answer". (HTTP %d)', $name, $requested, $limit, $http['status']
				), 'too_large');
			}

			$wait = self::retryAfter($http['headers'] ?? [], $detail);

			return new ModuleException(sprintf('%s: rate limit reached%s%s. (HTTP %d)', $name,
				$per_minute ? ' (tokens per minute)' : '',
				$wait !== null ? sprintf(', the provider asks to wait %.1f s', $wait) : '',
				$http['status']
			), 'rate_limit', $wait);
		}

		// Provider overloaded ("This model is currently experiencing high demand"): usually short, retry.
		if ($http['status'] == 503 || $http['status'] == 529) {
			$wait = self::retryAfter($http['headers'] ?? [], $detail) ?? self::OVERLOAD_RETRY_WAIT;

			return new ModuleException(sprintf('%s: the model is overloaded at the provider right now, the request '.
				'was not processed. Try again shortly or choose another model. (HTTP %d: %s)', $name, $http['status'],
				$detail
			), 'rate_limit', $wait);
		}

		if ($http['status'] == 401 || $http['status'] == 403) {
			return new ModuleException(sprintf('%s: the API key was rejected (HTTP %d): %s', $name, $http['status'],
				$detail
			), 'llm');
		}

		return $this->fail(sprintf('HTTP %d: %s', $http['status'], $detail));
	}

	/**
	 * Wait time from a Retry-After header or from messages like "try again in 1.74s" / "in 1m2.5s" / "in 450ms".
	 */
	protected static function retryAfter(array $headers, string $message): ?float {
		if (isset($headers['retry-after']) && is_numeric($headers['retry-after'])) {
			return max(0.0, (float) $headers['retry-after']);
		}

		if (preg_match('/try again in\s+(?:(\d+)m)?\s*(\d+(?:\.\d+)?)\s*(ms|s)\b/i', $message, $m)) {
			$seconds = (float) $m[2];

			if (strtolower($m[3]) === 'ms') {
				$seconds /= 1000;
			}

			return (int) $m[1] * 60 + $seconds;
		}

		return null;
	}

	protected function fail(string $message): ModuleException {
		return new ModuleException(sprintf('%s: %s', $this->profile['name'], $message), 'llm');
	}
}
