<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

namespace Modules\McpPilotChat\Lib;

use CWebUser,
	DB;

/**
 * Who may use the chat, configure the module and select which profile.
 */
class Access {

	private static ?array $user_groupids = null;

	public static function isSuperAdmin(): bool {
		return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
	}

	public static function canConfigure(): bool {
		return CWebUser::isLoggedIn() && !CWebUser::isGuest() && self::isSuperAdmin();
	}

	/**
	 * Super admins can always use the chat. Others need to be in one of the configured user groups;
	 * an empty list means every logged-in (non-guest) user.
	 */
	public static function canUse(array $config): bool {
		if (!CWebUser::isLoggedIn() || CWebUser::isGuest()) {
			return false;
		}

		if (self::isSuperAdmin()) {
			return true;
		}

		return self::inGroups($config['access']['usrgrpids']);
	}

	public static function canUseProfile(array $profile): bool {
		return self::isSuperAdmin() || self::inGroups($profile['usrgrpids']);
	}

	/**
	 * Profiles the current user may select.
	 */
	public static function profilesFor(array $config): array {
		return array_values(array_filter($config['profiles'], [self::class, 'canUseProfile']));
	}

	private static function inGroups(array $usrgrpids): bool {
		if (!$usrgrpids) {
			return true;
		}

		return (bool) array_intersect($usrgrpids, self::getUserGroupids());
	}

	private static function getUserGroupids(): array {
		if (self::$user_groupids === null) {
			$rows = DB::select('users_groups', [
				'output' => ['usrgrpid'],
				'filter' => ['userid' => CWebUser::$data['userid']]
			]);

			self::$user_groupids = array_map('strval', array_column($rows, 'usrgrpid'));
		}

		return self::$user_groupids;
	}
}
