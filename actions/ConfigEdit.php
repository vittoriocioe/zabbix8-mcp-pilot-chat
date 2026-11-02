<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

namespace Modules\McpPilotChat\Actions;

use API,
	CController,
	CControllerResponseData,
	CCsrfTokenHelper;

use Modules\McpPilotChat\Lib\Access,
	Modules\McpPilotChat\Lib\Config,
	Modules\McpPilotChat\Lib\Presets,
	Modules\McpPilotChat\Lib\Prompts,
	Modules\McpPilotChat\Lib\ResultTrimmer,
	Modules\McpPilotChat\Lib\ToolCatalog;

/**
 * Configuration popup (Super admin only).
 */
class ConfigEdit extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return Access::canConfigure();
	}

	protected function doAction(): void {
		$config = Config::get();
		$config['profiles'] = array_map([Config::class, 'publicProfile'], $config['profiles']);

		$user_groups = API::UserGroup()->get([
			'output' => ['usrgrpid', 'name'],
			'sortfield' => 'name'
		]);

		$this->setResponse(new CControllerResponseData([
			'config' => $config,
			'user_groups' => array_map(static fn(array $g) => ['id' => $g['usrgrpid'], 'name' => $g['name']],
				$user_groups
			),
			'profile_defaults' => Config::profileDefaults(),
			'builtin_prompt' => Prompts::BUILT_IN,
			'default_trim_fields' => ResultTrimmer::DEFAULT_FIELDS,
			'lean_trim_fields' => ResultTrimmer::LEAN_FIELDS,
			'catalog' => array_keys(ToolCatalog::TOOLS),
			'presets' => Presets::forBrowser(),
			'csrf' => [
				'update' => CCsrfTokenHelper::get('mcppilotchat.config.update'),
				'test' => CCsrfTokenHelper::get('mcppilotchat.connection.test')
			]
		]));
	}
}
