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
	Modules\McpPilotChat\Lib\Config,
	Modules\McpPilotChat\Lib\JsonResponseTrait,
	Modules\McpPilotChat\Lib\McpClient,
	Modules\McpPilotChat\Lib\ModuleException,
	Modules\McpPilotChat\Lib\ToolCatalog;

/**
 * Side panel data: MCP server status, identity, and the tool list for the selected profile.
 */
class StatusGet extends CController {

	use JsonResponseTrait;

	private array $config = [];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput(['profile_id' => 'string']);

		if (!$ret) {
			$this->respondInvalidInput();
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		$this->config = Config::get();

		return Access::canUse($this->config);
	}

	protected function doAction(): void {
		$profile = Config::findProfile($this->config, $this->getInput('profile_id', ''));
		$allowlist = $profile !== null && Access::canUseProfile($profile) ? $profile['tool_allowlist'] : [];

		$this->respondJson(self::status($this->config['mcp'], CWebUser::$data['sessionid'], $allowlist,
			Config::getModule()->getVersion()
		));
	}

	/**
	 * Also used by the connection test in the configuration popup.
	 */
	public static function status(array $mcp_config, string $bearer, array $allowlist, string $version): array {
		$status = [
			'ok' => true,
			'mcp' => [
				'url' => $mcp_config['url'],
				'connected' => false,
				'latency_ms' => null,
				'server' => null,
				'protocol' => null,
				'about' => null,
				'error' => null,
				'error_kind' => null
			],
			'tools' => null
		];

		if ($mcp_config['url'] === '') {
			$status['mcp']['error'] = 'The Zabbix MCP server URL is not configured.';
			$status['mcp']['error_kind'] = 'config';
			$status['tools'] = ToolCatalog::panel(null, $allowlist);

			return $status;
		}

		$client = new McpClient($mcp_config['url'], $bearer, $mcp_config['timeout']);
		$exposed = null;

		try {
			$init = $client->initialize($version);
			$status['mcp']['connected'] = true;
			$status['mcp']['latency_ms'] = $init['duration_ms'];
			$status['mcp']['server'] = $init['result']['serverInfo'] ?? null;
			$status['mcp']['protocol'] = $init['result']['protocolVersion'] ?? null;

			$exposed = $client->listTools();

			try {
				$about = $client->readResource('zabbix://about');
				$decoded = json_decode($about['text'], true);
				$status['mcp']['about'] = is_array($decoded) ? $decoded : ['text' => $about['text']];
			}
			catch (Throwable $e) {
				// The about resource is informational only.
			}
		}
		catch (ModuleException $e) {
			$status['mcp']['connected'] = false;
			$status['mcp']['error'] = $e->getMessage();
			$status['mcp']['error_kind'] = $e->getKind();
		}
		catch (Throwable $e) {
			$status['mcp']['connected'] = false;
			$status['mcp']['error'] = $e->getMessage();
			$status['mcp']['error_kind'] = 'error';
		}

		$status['tools'] = ToolCatalog::panel($exposed, $allowlist);
		$status['mcp']['exposed_count'] = $exposed !== null ? count($exposed) : null;

		return $status;
	}
}
