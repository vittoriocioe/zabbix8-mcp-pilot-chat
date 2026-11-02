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
 * Removes noise fields from MCP read-tool results before they reach the model.
 *
 * "Pure AI" rule: fields can only be removed (and timestamps formatted); nothing is added or derived.
 */
class ResultTrimmer {

	public const DEFAULT_FIELDS = [
		'problem_get' => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged', 'suppressed', 'opdata'],
		'trigger_get' => ['triggerid', 'description', 'priority', 'value', 'lastchange', 'opdata'],
		'host_get' => ['hostid', 'host', 'name', 'status', 'maintenance_status', 'triggers[].triggerid',
			'triggers[].description', 'triggers[].priority', 'triggers[].value', 'triggers[].opdata'
		],
		'hostgroup_get' => ['groupid', 'name'],
		'maintenance_get' => ['maintenanceid', 'name', 'active_since', 'active_till']
	];

	/**
	 * Fewer fields for small models with a small context: enough to list and summarize, roughly half the tokens.
	 * Offered as a preset in the configuration popup.
	 */
	public const LEAN_FIELDS = [
		'problem_get' => ['eventid', 'name', 'severity', 'clock', 'opdata'],
		'trigger_get' => ['triggerid', 'description', 'priority', 'value', 'opdata'],
		'host_get' => ['hostid', 'name', 'triggers[].triggerid', 'triggers[].description', 'triggers[].priority',
			'triggers[].value', 'triggers[].opdata'
		],
		'hostgroup_get' => ['groupid', 'name'],
		'maintenance_get' => ['maintenanceid', 'name', 'active_since', 'active_till']
	];

	/**
	 * Envelope fields of the MCP read-tool response format that are kept. The long "note" and "truncated_by"
	 * are dropped: the system prompt explains what "truncated" means.
	 */
	private const ENVELOPE = ['count', 'truncated', 'limit'];

	private const TIME_FIELDS = ['clock', 'r_clock', 'lastchange', 'active_since', 'active_till'];

	/**
	 * Kept fields that are dropped when empty. Operational data ("Current utilization: 98.3 %") is valuable
	 * when a trigger defines it, and most triggers do not.
	 */
	private const DROP_IF_EMPTY = ['opdata'];

	/**
	 * @return array{text: string, trimmed: bool}
	 */
	public static function trim(string $tool_name, string $text, array $custom_fields, bool $format_times): array {
		$base = ToolCatalog::baseName($tool_name);
		$fields = $custom_fields[$tool_name] ?? $custom_fields[$base] ?? self::DEFAULT_FIELDS[$base] ?? null;

		$data = json_decode($text, true);

		if (!is_array($data) || !array_key_exists('results', $data) || !is_array($data['results'])) {
			return ['text' => $text, 'trimmed' => false];
		}

		$spec = $fields !== null ? self::buildSpec($fields) : null;
		$results = [];

		foreach ($data['results'] as $row) {
			if (!is_array($row)) {
				$results[] = $row;
				continue;
			}

			$row = $spec !== null ? self::apply($row, $spec) : $row;
			$results[] = $format_times ? self::formatTimes($row) : $row;
		}

		$output = ['results' => $results] + array_intersect_key($data, array_flip(self::ENVELOPE));

		return [
			'text' => json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			'trimmed' => true
		];
	}

	/**
	 * ["a", "b[].c", "b[].d"] => ['a' => true, 'b' => ['c' => true, 'd' => true]]
	 */
	private static function buildSpec(array $fields): array {
		$spec = [];

		foreach ($fields as $path) {
			$parts = explode('.', str_replace('[]', '', $path));
			$node = &$spec;

			foreach ($parts as $i => $part) {
				if ($i === count($parts) - 1) {
					if (!isset($node[$part]) || !is_array($node[$part])) {
						$node[$part] = true;
					}
				}
				else {
					if (!isset($node[$part]) || !is_array($node[$part])) {
						$node[$part] = [];
					}

					$node = &$node[$part];
				}
			}

			unset($node);
		}

		return $spec;
	}

	private static function apply(array $row, array $spec): array {
		$result = [];

		foreach ($spec as $key => $sub) {
			if (!array_key_exists($key, $row)) {
				continue;
			}

			$value = $row[$key];

			if (in_array($key, self::DROP_IF_EMPTY, true) && ($value === '' || $value === null)) {
				continue;
			}

			if ($sub === true || !is_array($value)) {
				$result[$key] = $value;
			}
			elseif (array_is_list($value)) {
				$result[$key] = array_map(static fn($item) => is_array($item) ? self::apply($item, $sub) : $item,
					$value
				);
			}
			else {
				$result[$key] = self::apply($value, $sub);
			}
		}

		return $result;
	}

	private static function formatTimes(array $row): array {
		foreach ($row as $key => $value) {
			if (is_array($value)) {
				$row[$key] = self::formatTimes($value);
			}
			elseif (in_array($key, self::TIME_FIELDS, true) && is_numeric($value) && (int) $value > 0) {
				$row[$key] = gmdate('Y-m-d H:i:s', (int) $value).' UTC';
			}
		}

		return $row;
	}
}
