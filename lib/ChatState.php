<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

namespace Modules\McpPilotChat\Lib;

use CWebUser;

/**
 * Conversation state kept by the browser and signed by the server.
 *
 * Zabbix 8.0 stores the frontend session in an encrypted cookie, which is too small for chat history.
 * The browser therefore sends the state with every request. It is an opaque JSON string signed with
 * an HMAC keyed by the user's session, so the browser cannot inject tool calls, tool results or approvals.
 */
class ChatState {

	public const VERSION = 1;

	public static function create(string $profile_id): array {
		return [
			'v' => self::VERSION,
			'profile_id' => $profile_id,
			'messages' => [],
			'pending' => [],
			'iteration' => 0
		];
	}

	/**
	 * @return array{state: string, sig: string}
	 */
	public static function seal(array $state): array {
		$json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return ['state' => $json, 'sig' => hash_hmac('sha256', $json, self::key())];
	}

	/**
	 * @throws ModuleException if the signature does not match (tampered state or a new login session).
	 */
	public static function open(string $json, string $sig): array {
		if (!hash_equals(hash_hmac('sha256', $json, self::key()), $sig)) {
			throw new ModuleException('The conversation is no longer valid for this session. Start a new chat.',
				'state'
			);
		}

		$state = json_decode($json, true);

		if (!is_array($state) || ($state['v'] ?? null) !== self::VERSION || !is_array($state['messages'] ?? null)
				|| !is_array($state['pending'] ?? null)) {
			throw new ModuleException('Invalid conversation state. Start a new chat.', 'state');
		}

		return $state;
	}

	private static function key(): string {
		return hash('sha256', 'mcp-pilot-chat|'.CWebUser::$data['sessionid'].'|'.CWebUser::$data['userid'], true);
	}
}
