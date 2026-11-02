<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

namespace Modules\McpPilotChat\Lib;

/**
 * Client for the Zabbix MCP server (Streamable HTTP transport, JSON-RPC 2.0).
 *
 * The Zabbix MCP server is stateless, so every request stands on its own and carries the
 * Bearer credential (here: the logged-in user's frontend session ID).
 */
class McpClient {

	public const PROTOCOL_VERSION = '2025-06-18';

	private string $url;
	private string $bearer;
	private int $timeout;
	private int $next_id = 1;

	public function __construct(string $url, string $bearer, int $timeout = 15) {
		$this->url = $url;
		$this->bearer = $bearer;
		$this->timeout = $timeout;
	}

	public function initialize(string $client_version): array {
		return $this->call('initialize', [
			'protocolVersion' => self::PROTOCOL_VERSION,
			'capabilities' => new \stdClass(),
			'clientInfo' => ['name' => 'mcp-pilot-chat', 'version' => $client_version]
		]);
	}

	/**
	 * @return array  List of tool definitions: name, title, description, inputSchema, annotations.
	 */
	public function listTools(): array {
		$tools = [];
		$cursor = null;

		// Follow pagination if the server ever uses it; the Zabbix MCP server returns all tools at once.
		for ($page = 0; $page < 10; $page++) {
			$params = $cursor !== null ? ['cursor' => $cursor] : new \stdClass();
			$result = $this->call('tools/list', $params)['result'];

			foreach ($result['tools'] ?? [] as $tool) {
				if (is_array($tool) && array_key_exists('name', $tool)) {
					$tools[] = $tool;
				}
			}

			$cursor = $result['nextCursor'] ?? null;

			if ($cursor === null || $cursor === '') {
				break;
			}
		}

		return $tools;
	}

	/**
	 * @return array{text: string, is_error: bool, raw: array, duration_ms: int}
	 */
	public function callTool(string $name, array $arguments): array {
		$response = $this->call('tools/call', [
			'name' => $name,
			'arguments' => $arguments ?: new \stdClass()
		]);

		$result = $response['result'];
		$texts = [];

		foreach ($result['content'] ?? [] as $item) {
			if (is_array($item) && ($item['type'] ?? '') === 'text') {
				$texts[] = (string) $item['text'];
			}
		}

		if (!$texts && array_key_exists('structuredContent', $result)) {
			$texts[] = json_encode($result['structuredContent'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		$text = implode("\n", $texts);
		$is_error = !empty($result['isError']);

		if ($is_error && self::looksLikeAuthError($text)) {
			throw new ModuleException('Your Zabbix session has expired. Please log in again.', 'auth');
		}

		return [
			'text' => $text,
			'is_error' => $is_error,
			'raw' => $result,
			'duration_ms' => $response['duration_ms']
		];
	}

	public function readResource(string $uri): array {
		$response = $this->call('resources/read', ['uri' => $uri]);
		$texts = [];

		foreach ($response['result']['contents'] ?? [] as $item) {
			if (is_array($item) && array_key_exists('text', $item)) {
				$texts[] = (string) $item['text'];
			}
		}

		return [
			'text' => implode("\n", $texts),
			'duration_ms' => $response['duration_ms']
		];
	}

	/**
	 * @return array{result: array, duration_ms: int}
	 */
	private function call(string $method, $params): array {
		$id = $this->next_id++;

		$http = HttpClient::request('POST', $this->url, [
			'Content-Type' => 'application/json',
			'Accept' => 'application/json, text/event-stream',
			'Authorization' => 'Bearer '.$this->bearer
		], json_encode([
			'jsonrpc' => '2.0',
			'id' => $id,
			'method' => $method,
			'params' => $params
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->timeout);

		switch ($http['status']) {
			case 200:
				break;

			case 401:
				throw new ModuleException('The MCP server rejected the request: no or invalid credentials.', 'auth');

			case 403:
				throw new ModuleException(
					'The MCP server does not allow connections from this frontend. Add its address to AllowedIP.',
					'forbidden'
				);

			case 400:
				throw new ModuleException('The MCP server rejected the request (HTTP 400): '.self::excerpt($http['body']),
					'protocol'
				);

			default:
				throw new ModuleException(sprintf('Unexpected HTTP %d from the MCP server: %s', $http['status'],
					self::excerpt($http['body'])
				), 'protocol');
		}

		$message = self::decodeMessage($http['body'], $http['content_type'], $id);

		if (array_key_exists('error', $message)) {
			$error_text = (string) ($message['error']['message'] ?? 'Unknown error');

			if (isset($message['error']['data']) && is_string($message['error']['data'])) {
				$error_text .= ' '.$message['error']['data'];
			}

			if (self::looksLikeAuthError($error_text)) {
				throw new ModuleException('Your Zabbix session has expired. Please log in again.', 'auth');
			}

			throw new ModuleException('MCP error: '.$error_text, 'protocol');
		}

		if (!array_key_exists('result', $message) || !is_array($message['result'])) {
			throw new ModuleException('The MCP server returned no result.', 'protocol');
		}

		return ['result' => $message['result'], 'duration_ms' => $http['duration_ms']];
	}

	/**
	 * The server may answer with plain JSON or with a single-response SSE stream.
	 */
	private static function decodeMessage(string $body, string $content_type, int $id): array {
		if (stripos($content_type, 'text/event-stream') !== false) {
			$candidates = [];
			$data = [];

			foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
				if ($line === '') {
					if ($data) {
						$candidates[] = implode("\n", $data);
						$data = [];
					}

					continue;
				}

				if (strpos($line, 'data:') === 0) {
					$data[] = ltrim(substr($line, 5));
				}
			}

			if ($data) {
				$candidates[] = implode("\n", $data);
			}

			foreach ($candidates as $candidate) {
				$message = json_decode($candidate, true);

				if (is_array($message) && ($message['id'] ?? null) == $id
						&& (array_key_exists('result', $message) || array_key_exists('error', $message))) {
					return $message;
				}
			}

			throw new ModuleException('No matching response in the MCP event stream.', 'protocol');
		}

		$message = json_decode($body, true);

		if (!is_array($message)) {
			throw new ModuleException('Invalid JSON from the MCP server: '.self::excerpt($body), 'protocol');
		}

		return $message;
	}

	private static function looksLikeAuthError(string $text): bool {
		return (bool) preg_match('/not authori[sz]ed|session terminated|re-login|session.*expired/i', $text);
	}

	private static function excerpt(string $text): string {
		$text = trim(preg_replace('/\s+/', ' ', $text));

		return mb_strlen($text) > 200 ? mb_substr($text, 0, 200).'…' : $text;
	}
}
