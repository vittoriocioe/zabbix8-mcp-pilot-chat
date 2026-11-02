<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

namespace Modules\McpPilotChat\Actions;

use CController,
	CWebUser,
	Throwable;

use Modules\McpPilotChat\Lib\Access,
	Modules\McpPilotChat\Lib\Adapters\LlmAdapter,
	Modules\McpPilotChat\Lib\Config,
	Modules\McpPilotChat\Lib\JsonResponseTrait;

/**
 * "Test" buttons of the configuration popup (Super admin only). Tests unsaved form values.
 *
 * POST JSON: {target: mcp|llm, mcp?: {url, timeout}, profile?: {...}, _csrf_token}
 */
class ConnectionTest extends CController {

	use JsonResponseTrait;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'target' => 'required|in mcp,llm',
			'mcp' => 'array',
			'profile' => 'array'
		]);

		if (!$ret) {
			$this->respondInvalidInput();
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return Access::canConfigure();
	}

	protected function doAction(): void {
		try {
			if ($this->getInput('target') === 'mcp') {
				$this->testMcp();
			}
			else {
				$this->testLlm();
			}
		}
		catch (Throwable $e) {
			$this->respondJson(['ok' => true, 'result' => ['ok' => false, 'message' => $e->getMessage()]]);
		}
	}

	private function testMcp(): void {
		$mcp = Config::normalize(['mcp' => $this->getInput('mcp', [])])['mcp'];
		$status = StatusGet::status($mcp, CWebUser::$data['sessionid'], [], Config::getModule()->getVersion());

		if ($status['mcp']['connected']) {
			$identity = $status['mcp']['about']['user']['username'] ?? $status['mcp']['about']['identity'] ?? null;

			$message = sprintf(_('Connected in %d ms. %d tools exposed.'), $status['mcp']['latency_ms'],
				$status['mcp']['exposed_count']
			);

			if (is_string($identity)) {
				$message .= ' '._s('Authenticated as %1$s.', $identity);
			}

			$result = ['ok' => true, 'message' => $message, 'details' => $status['mcp']];
		}
		else {
			$result = ['ok' => false, 'message' => $status['mcp']['error'], 'details' => $status['mcp']];
		}

		$this->respondJson(['ok' => true, 'result' => $result]);
	}

	private function testLlm(): void {
		$submitted = $this->getInput('profile', []);
		$merged = Config::mergeSecrets(['profiles' => [$submitted]], Config::get());
		$profile = Config::normalizeProfile($merged['profiles'][0] + ['id' => 'test']);

		$adapter = LlmAdapter::create($profile);
		$result = $adapter->test();

		if ($result['ok'] && !$adapter->supportsTools()) {
			$result['ok'] = false;
			$result['message'] .= ' '._('But tool calling is not configured, so this profile cannot use the Zabbix MCP server.');
		}

		$this->respondJson(['ok' => true, 'result' => $result]);
	}
}
