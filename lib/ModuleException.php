<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

namespace Modules\McpPilotChat\Lib;

use Exception;

/**
 * Error with a machine-readable kind, shown to the user in the chat or status panel.
 *
 * Kinds: config, unreachable, timeout, auth, forbidden, protocol, llm, rate_limit, too_large, tool, state, limit.
 */
class ModuleException extends Exception {

	private string $kind;

	/** Seconds to wait before retrying, for kind "rate_limit". */
	private ?float $retry_after;

	public function __construct(string $message, string $kind = 'error', ?float $retry_after = null) {
		parent::__construct($message);

		$this->kind = $kind;
		$this->retry_after = $retry_after;
	}

	public function getKind(): string {
		return $this->kind;
	}

	public function getRetryAfter(): ?float {
		return $this->retry_after;
	}
}
