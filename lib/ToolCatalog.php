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
 * Tools of the Zabbix 8.0 MCP server.
 *
 * The MCP server does not list denied tools, so this catalog is needed to show them as "not exposed".
 */
class ToolCatalog {

	public const STATE_ENABLED = 'enabled';		// exposed by MCP and allowed in the profile (green)
	public const STATE_DISABLED = 'disabled';	// exposed by MCP but not allowed in the profile (yellow)
	public const STATE_MISSING = 'missing';		// not exposed by the MCP server (red)

	public const TOOLS = [
		'hostgroup_get' => ['type' => 'read', 'method' => 'hostgroup.get'],
		'templategroup_get' => ['type' => 'read', 'method' => 'templategroup.get'],
		'host_get' => ['type' => 'read', 'method' => 'host.get'],
		'template_get' => ['type' => 'read', 'method' => 'template.get'],
		'item_get' => ['type' => 'read', 'method' => 'item.get'],
		'lld_get' => ['type' => 'read', 'method' => 'discoveryrule.get'],
		'interface_get' => ['type' => 'read', 'method' => 'hostinterface.get'],
		'macro_get' => ['type' => 'read', 'method' => 'usermacro.get'],
		'problem_get' => ['type' => 'read', 'method' => 'problem.get'],
		'maintenance_get' => ['type' => 'read', 'method' => 'maintenance.get'],
		'history_get' => ['type' => 'read', 'method' => 'history.get'],
		'trigger_get' => ['type' => 'read', 'method' => 'trigger.get'],
		'problem_acknowledge' => ['type' => 'write', 'method' => 'event.acknowledge'],
		'problem_unacknowledge' => ['type' => 'write', 'method' => 'event.acknowledge'],
		'problem_close' => ['type' => 'write', 'method' => 'event.acknowledge'],
		'problem_annotate' => ['type' => 'write', 'method' => 'event.acknowledge'],
		'problem_change_severity' => ['type' => 'write', 'method' => 'event.acknowledge'],
		'problem_suppress' => ['type' => 'write', 'method' => 'event.acknowledge'],
		'problem_unsuppress' => ['type' => 'write', 'method' => 'event.acknowledge'],
		'maintenance_create' => ['type' => 'write', 'method' => 'maintenance.create'],
		'maintenance_update' => ['type' => 'write', 'method' => 'maintenance.update'],
		'maintenance_delete' => ['type' => 'write', 'method' => 'maintenance.delete']
	];

	/**
	 * Read/write type of an exposed tool: MCP annotations first, then the catalog.
	 */
	public static function typeOf(array $tool): string {
		$annotations = $tool['annotations'] ?? [];

		if (is_array($annotations) && array_key_exists('readOnlyHint', $annotations)) {
			return $annotations['readOnlyHint'] ? 'read' : 'write';
		}

		$name = self::baseName((string) $tool['name']);

		return self::TOOLS[$name]['type'] ?? 'write';
	}

	/**
	 * Tool name without a server-side ToolPrefix, if the remainder is a known tool.
	 */
	public static function baseName(string $name): string {
		if (array_key_exists($name, self::TOOLS)) {
			return $name;
		}

		foreach (array_keys(self::TOOLS) as $known) {
			if (substr($name, -strlen($known)) === $known) {
				return $known;
			}
		}

		return $name;
	}

	/**
	 * An empty allow-list allows every exposed tool.
	 */
	public static function isAllowed(string $name, array $allowlist): bool {
		return !$allowlist || in_array($name, $allowlist, true) || in_array(self::baseName($name), $allowlist, true);
	}

	/**
	 * Merge the catalog with exposed tools into the side-panel list.
	 *
	 * @param array      $exposed    Tools from tools/list, or null if the MCP server is unreachable.
	 * @param array|null $allowlist  Profile allow-list.
	 */
	public static function panel(?array $exposed, array $allowlist): array {
		$rows = [];
		$exposed_by_base = [];

		foreach ($exposed ?? [] as $tool) {
			$exposed_by_base[self::baseName($tool['name'])] = $tool;
		}

		foreach (self::TOOLS as $name => $info) {
			$tool = $exposed_by_base[$name] ?? null;

			$rows[] = [
				'name' => $tool !== null ? $tool['name'] : $name,
				'type' => $tool !== null ? self::typeOf($tool) : $info['type'],
				'description' => $tool !== null ? (string) ($tool['description'] ?? '') : 'Zabbix API: '.$info['method'],
				'state' => $tool === null
					? self::STATE_MISSING
					: (self::isAllowed($tool['name'], $allowlist) ? self::STATE_ENABLED : self::STATE_DISABLED)
			];

			unset($exposed_by_base[$name]);
		}

		// Tools exposed by a newer MCP server that are not in the catalog yet.
		foreach ($exposed_by_base as $tool) {
			$rows[] = [
				'name' => $tool['name'],
				'type' => self::typeOf($tool),
				'description' => (string) ($tool['description'] ?? ''),
				'state' => self::isAllowed($tool['name'], $allowlist) ? self::STATE_ENABLED : self::STATE_DISABLED
			];
		}

		$order = [self::STATE_ENABLED => 0, self::STATE_DISABLED => 1, self::STATE_MISSING => 2];

		usort($rows, static function (array $a, array $b) use ($order): int {
			return [$order[$a['state']], $a['type'], $a['name']] <=> [$order[$b['state']], $b['type'], $b['name']];
		});

		return $rows;
	}
}
