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
 * System prompt resolution.
 *
 * Three levels:
 *   1. BUILT_IN               - shipped with the module.
 *   2. Module default prompt  - config['prompts']['system']; empty = built-in.
 *   3. Profile prompt         - used when the profile's prompt_mode is "custom".
 *
 * The prompt only changes once a day (date placeholder), so LLM servers with prompt caching can reuse it.
 */
class Prompts {

	public const MODE_DEFAULT = 'default';
	public const MODE_CUSTOM = 'custom';

	public const BUILT_IN = <<<'PROMPT'
You are a Zabbix monitoring assistant inside the Zabbix frontend.
Before answering any question about problems, hosts, triggers or maintenance, ALWAYS call a tool.
Use only data returned by the tools. Never invent hosts, problems or values.
If the tools return nothing relevant, say so.

Zabbix facts:
- Severities: 0 Not classified, 1 Information, 2 Warning, 3 Average, 4 High, 5 Disaster.
- A problem's objectid is its trigger ID.
- To find the host of a problem, use host_get and match the trigger ID with triggers[].triggerid,
  or filter problem_get by hostids.
- A trigger with value 1 is currently in problem state.

Tool usage:
- Always pass a limit (default {{default_limit}}, maximum {{max_limit}}) and the narrowest filters available.
- Results cannot be paged. If a result is truncated, narrow the query instead of repeating it.
- "count" in a tool result is the number of results returned, NOT the total.
  If "truncated" is true, there are more: say "showing the latest N" or "at least N".

Answers:
- Be brief. Group identical problems and count them. Mention severities, worst first.
- Refer to problems by event ID and to hosts by name.

Current date: {{date}} (UTC).
PROMPT;

	/**
	 * Module default prompt: the configured one, or the built-in prompt if none is configured.
	 */
	public static function moduleDefault(array $config): string {
		$prompt = (string) ($config['prompts']['system'] ?? '');

		return trim($prompt) !== '' ? $prompt : self::BUILT_IN;
	}

	/**
	 * Prompt template a profile uses, before placeholders are filled.
	 */
	public static function template(array $profile, array $config): string {
		if ($profile['prompt_mode'] === self::MODE_CUSTOM && trim($profile['system_prompt']) !== '') {
			return $profile['system_prompt'];
		}

		return self::moduleDefault($config);
	}

	public static function system(array $profile, array $config): string {
		return self::fill(self::template($profile, $config), $profile);
	}

	/**
	 * Fill the placeholders of a prompt template: {{default_limit}}, {{max_limit}}, {{date}}.
	 */
	public static function fill(string $template, array $profile): string {
		return strtr($template, [
			'{{default_limit}}' => (string) $profile['default_limit'],
			'{{max_limit}}' => ($profile['max_limit'] ?? 0) > 0 ? (string) $profile['max_limit'] : 'none',
			'{{date}}' => gmdate('Y-m-d')
		]);
	}
}
