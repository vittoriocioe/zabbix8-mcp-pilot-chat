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
 * Minimal cURL wrapper used for both the MCP server and LLM endpoints.
 */
class HttpClient {

	/**
	 * @return array{status: int, content_type: string, body: string, duration_ms: int, headers: array}
	 *
	 * @throws ModuleException on transport errors (connection refused, timeout, DNS).
	 */
	public static function request(string $method, string $url, array $headers = [], ?string $body = null,
			int $timeout = 30): array {
		if (!function_exists('curl_init')) {
			throw new ModuleException('The PHP cURL extension is required by MCP Pilot Chat.', 'config');
		}

		if (!preg_match('#^https?://#i', $url)) {
			throw new ModuleException(sprintf('Invalid URL "%s". Use http:// or https://.', $url), 'config');
		}

		$header_lines = [];

		foreach ($headers as $name => $value) {
			$header_lines[] = $name.': '.$value;
		}

		$ch = curl_init($url);

		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $header_lines,
			CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS
		]);

		// Response headers, lowercase names (needed for Retry-After on rate limits).
		$response_headers = [];
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$response_headers): int {
			$pos = strpos($line, ':');

			if ($pos !== false) {
				$response_headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
			}

			return strlen($line);
		});

		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}

		$start = microtime(true);
		$response = curl_exec($ch);
		$duration_ms = (int) round((microtime(true) - $start) * 1000);

		if ($response === false) {
			$error = curl_error($ch);
			$errno = curl_errno($ch);

			$kind = $errno == CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unreachable';

			throw new ModuleException(sprintf('%s (%s)', $error, $url), $kind);
		}

		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$content_type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

		return [
			'status' => $status,
			'content_type' => $content_type,
			'body' => (string) $response,
			'duration_ms' => $duration_ms,
			'headers' => $response_headers
		];
	}
}
