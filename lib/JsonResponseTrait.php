<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

namespace Modules\McpPilotChat\Lib;

use CControllerResponseData;

/**
 * JSON responses for module actions using the "layout.json" layout.
 */
trait JsonResponseTrait {

	protected function respondJson(array $data): void {
		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				| JSON_INVALID_UTF8_SUBSTITUTE
			)
		]));
	}

	protected function respondError(string $message, string $kind = 'error', ?float $retry_after = null): void {
		$error = ['message' => $message, 'kind' => $kind];

		if ($retry_after !== null) {
			$error['retry_after'] = $retry_after;
		}

		$this->respondJson(['ok' => false, 'error' => $error]);
	}

	protected function respondInvalidInput(): void {
		$this->respondError('Invalid request.', 'input');
	}
}
