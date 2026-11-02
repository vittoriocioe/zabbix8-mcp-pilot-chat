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
	CControllerResponseData,
	CControllerResponseFatal,
	CCsrfTokenHelper,
	CWebUser;

use Modules\McpPilotChat\Lib\Access,
	Modules\McpPilotChat\Lib\Config;

/**
 * Chat page: Monitoring → MCP Pilot Chat (ext).
 */
class ChatView extends CController {

	private array $config = [];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		// popout=1: the chat in its own window (see "Pop out"), without the Zabbix menu.
		$ret = $this->validateInput(['popout' => 'in 0,1']);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		$this->config = Config::get();

		return Access::canUse($this->config);
	}

	protected function doAction(): void {
		$profiles = array_map([Config::class, 'publicProfile'], Access::profilesFor($this->config));
		$profile_ids = array_column($profiles, 'id');

		$default_profile = in_array($this->config['ui']['default_profile'], $profile_ids, true)
			? $this->config['ui']['default_profile']
			: ($profile_ids[0] ?? '');

		$module = Config::getModule();

		$response = new CControllerResponseData([
			'version' => $module->getVersion(),
			'profiles' => $profiles,
			'default_profile' => $default_profile,
			'debug_default' => (bool) $this->config['ui']['debug_default'],
			'mcp_configured' => $this->config['mcp']['url'] !== '',
			'can_configure' => Access::canConfigure(),
			'username' => CWebUser::$data['username'],
			'popout' => $this->getInput('popout', 0) == 1,
			'csrf' => [
				'step' => CCsrfTokenHelper::get('mcppilotchat.step')
			]
		]);

		$response->setTitle(_('MCP Pilot Chat'));
		$this->setResponse($response);
	}
}
