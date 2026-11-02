<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

namespace Modules\McpPilotChat;

use APP,
	CMenuItem,
	CWebUser,
	Throwable;

use Modules\McpPilotChat\Lib\Access,
	Modules\McpPilotChat\Lib\Config;
use Zabbix\Core\CModule;

class Module extends CModule {

	public const MODULE_ID = 'mcp-pilot-chat';
	public const MENU_LABEL = 'MCP Pilot Chat (ext)';

	public function init(): void {
		try {
			if (!CWebUser::isLoggedIn() || CWebUser::isGuest()) {
				return;
			}

			// The module is not registered in the module manager yet during init(), so read its own config.
			if (!Access::canUse(Config::normalize($this->getConfig()))) {
				return;
			}

			$item = (new CMenuItem(_(self::MENU_LABEL)))
				->setAction('mcppilotchat.view');

			$menu = APP::Component()->get('menu.main');
			$monitoring = $menu->find(_('Monitoring'));

			if ($monitoring !== null) {
				$monitoring->getSubMenu()->add($item);
			}
			else {
				$menu->add($item);
			}
		}
		catch (Throwable $e) {
			// Never break the whole frontend because of this module.
		}
	}
}
