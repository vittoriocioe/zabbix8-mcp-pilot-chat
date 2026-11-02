<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

namespace Modules\McpPilotChat\Lib;

use APP,
	RuntimeException;

use Modules\McpPilotChat\Module;

/**
 * Module configuration: loading, normalization, saving and masking of secrets.
 *
 * Stored through CModule::setConfig(), i.e. the module.update API (Super admin only).
 */
class Config {

	public const ADAPTERS = ['openai', 'generic_http'];

	public static function defaults(): array {
		return [
			'mcp' => [
				'url' => 'http://zabbix-mcp-server:8443/mcp',
				'timeout' => 15
			],
			'access' => [
				'usrgrpids' => []
			],
			'ui' => [
				'debug_default' => 1,
				'default_profile' => ''
			],
			'prompts' => [
				// Module default system prompt. Empty = the built-in prompt (follows module updates).
				'system' => ''
			],
			'profiles' => []
		];
	}

	public static function profileDefaults(): array {
		return [
			'id' => '',
			'name' => '',
			'adapter' => 'openai',
			'base_url' => '',
			'api_key' => '',
			'model' => '',
			'prompt_mode' => Prompts::MODE_DEFAULT,
			'system_prompt' => '',
			'require_tool_first' => 0,
			'temperature' => 0.2,
			'max_tokens' => 512,
			'context_size' => 8192,
			'max_iterations' => 8,
			'default_limit' => 10,
			// Highest "limit" the model may ask a tool for; 0 = no maximum.
			'max_limit' => 50,
			'timeout' => 120,
			'tool_allowlist' => [],
			'trim_fields' => [],
			'format_times' => 1,
			'extra_params' => '',
			'approval_required' => 1,
			'starter_prompts' => [],
			'usrgrpids' => [],
			'generic' => [
				'url' => '',
				'headers' => '',
				'request_template' => '',
				'response_text_path' => '',
				'response_tool_calls_path' => ''
			]
		];
	}

	/**
	 * Current configuration of the registered module.
	 */
	public static function get(): array {
		return self::normalize(self::getModule()->getConfig());
	}

	public static function save(array $config): void {
		self::getModule()->setConfig(self::normalize($config));
	}

	public static function getModule() {
		$module = APP::ModuleManager()->getModule(Module::MODULE_ID);

		if ($module === null) {
			throw new RuntimeException('MCP Pilot Chat module is not enabled.');
		}

		return $module;
	}

	public static function normalize(array $config): array {
		$defaults = self::defaults();

		$result = [
			'mcp' => array_replace($defaults['mcp'], is_array($config['mcp'] ?? null) ? $config['mcp'] : []),
			'access' => array_replace($defaults['access'],
				is_array($config['access'] ?? null) ? $config['access'] : []
			),
			'ui' => array_replace($defaults['ui'], is_array($config['ui'] ?? null) ? $config['ui'] : []),
			'prompts' => array_replace($defaults['prompts'],
				is_array($config['prompts'] ?? null) ? $config['prompts'] : []
			),
			'profiles' => []
		];

		// A prompt identical to the built-in one is stored as empty, so it keeps following module updates.
		$result['prompts']['system'] = (string) $result['prompts']['system'];

		if (trim($result['prompts']['system']) === trim(Prompts::BUILT_IN)) {
			$result['prompts']['system'] = '';
		}

		$result['mcp']['url'] = trim((string) $result['mcp']['url']);
		$result['mcp']['timeout'] = self::clampInt($result['mcp']['timeout'], 3, 120, 15);
		$result['access']['usrgrpids'] = self::idList($result['access']['usrgrpids']);
		$result['ui']['debug_default'] = (int) (bool) $result['ui']['debug_default'];
		$result['ui']['default_profile'] = (string) $result['ui']['default_profile'];

		$seen = [];

		foreach (is_array($config['profiles'] ?? null) ? $config['profiles'] : [] as $profile) {
			if (!is_array($profile)) {
				continue;
			}

			$profile = self::normalizeProfile($profile);

			if ($profile['id'] === '' || array_key_exists($profile['id'], $seen)) {
				continue;
			}

			$seen[$profile['id']] = true;
			$result['profiles'][] = $profile;
		}

		return $result;
	}

	public static function normalizeProfile(array $profile): array {
		$defaults = self::profileDefaults();
		$p = array_replace($defaults, array_intersect_key($profile, $defaults));

		// Profiles saved before prompt modes existed: a non-empty prompt means a custom prompt.
		if (!array_key_exists('prompt_mode', $profile)) {
			$p['prompt_mode'] = trim((string) $p['system_prompt']) !== '' ? Prompts::MODE_CUSTOM : Prompts::MODE_DEFAULT;
		}

		$p['id'] = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string) $p['id'])));
		$p['name'] = trim((string) $p['name']);
		$p['name'] = $p['name'] === '' ? $p['id'] : $p['name'];
		$p['adapter'] = in_array($p['adapter'], self::ADAPTERS, true) ? $p['adapter'] : 'openai';
		$p['base_url'] = rtrim(trim((string) $p['base_url']), '/');
		$p['api_key'] = (string) $p['api_key'];
		$p['model'] = trim((string) $p['model']);
		$p['prompt_mode'] = $p['prompt_mode'] === Prompts::MODE_CUSTOM ? Prompts::MODE_CUSTOM : Prompts::MODE_DEFAULT;
		$p['system_prompt'] = (string) $p['system_prompt'];
		$p['require_tool_first'] = (int) (bool) $p['require_tool_first'];
		// Empty = do not send a temperature.
		$p['temperature'] = $p['temperature'] === '' || $p['temperature'] === null
			? ''
			: max(0.0, min(2.0, (float) $p['temperature']));
		$p['max_tokens'] = self::clampInt($p['max_tokens'], 16, 32768, 512);
		$p['context_size'] = self::clampInt($p['context_size'], 512, 2000000, 8192);
		$p['max_iterations'] = self::clampInt($p['max_iterations'], 1, 30, 8);
		$p['default_limit'] = self::clampInt($p['default_limit'], 1, 1000, 10);
		$p['max_limit'] = self::clampInt($p['max_limit'], 0, 1000, 50);

		if ($p['max_limit'] > 0 && $p['default_limit'] > $p['max_limit']) {
			$p['default_limit'] = $p['max_limit'];
		}
		$p['timeout'] = self::clampInt($p['timeout'], 5, 900, 120);
		$p['tool_allowlist'] = self::stringList($p['tool_allowlist']);
		$p['trim_fields'] = self::trimFields($p['trim_fields']);
		$p['format_times'] = (int) (bool) $p['format_times'];
		$p['extra_params'] = trim((string) $p['extra_params']);
		$p['approval_required'] = (int) (bool) $p['approval_required'];
		$p['starter_prompts'] = array_slice(self::stringList($p['starter_prompts'], false), 0, 10);
		$p['usrgrpids'] = self::idList($p['usrgrpids']);
		$p['generic'] = array_replace($defaults['generic'],
			array_map('strval', array_intersect_key(is_array($p['generic']) ? $p['generic'] : [], $defaults['generic']))
		);

		return $p;
	}

	/**
	 * Decoded extra request parameters of a profile (JSON object), or [] if empty or invalid.
	 */
	public static function extraParams(array $profile): array {
		if ($profile['extra_params'] === '') {
			return [];
		}

		$decoded = json_decode($profile['extra_params'], true);

		return is_array($decoded) ? $decoded : [];
	}

	public static function findProfile(array $config, string $profile_id): ?array {
		foreach ($config['profiles'] as $profile) {
			if ($profile['id'] === $profile_id) {
				return $profile;
			}
		}

		return null;
	}

	/**
	 * Profile data that is safe to send to the browser.
	 */
	public static function publicProfile(array $profile): array {
		$public = $profile;
		$public['has_api_key'] = $profile['api_key'] !== '';
		$public['api_key'] = '';

		return $public;
	}

	/**
	 * Merge a submitted configuration with the stored one: empty secret fields keep the stored value.
	 */
	public static function mergeSecrets(array $submitted, array $stored): array {
		foreach ($submitted['profiles'] ?? [] as $i => $profile) {
			if (!is_array($profile)) {
				continue;
			}

			$keep = ($profile['api_key'] ?? '') === '' && empty($profile['clear_api_key']);

			if ($keep) {
				$old = self::findProfile($stored, (string) ($profile['id'] ?? ''));
				$submitted['profiles'][$i]['api_key'] = $old !== null ? $old['api_key'] : '';
			}

			unset($submitted['profiles'][$i]['clear_api_key'], $submitted['profiles'][$i]['has_api_key']);
		}

		return $submitted;
	}

	private static function clampInt($value, int $min, int $max, int $default): int {
		if (!is_numeric($value)) {
			return $default;
		}

		return max($min, min($max, (int) $value));
	}

	private static function idList($value): array {
		if (!is_array($value)) {
			return [];
		}

		return array_values(array_unique(array_filter(array_map('strval', $value), 'ctype_digit')));
	}

	private static function stringList($value, bool $unique = true): array {
		if (is_string($value)) {
			$value = preg_split('/\r\n|\r|\n|,/', $value);
		}

		if (!is_array($value)) {
			return [];
		}

		$list = array_values(array_filter(array_map(static fn($v) => trim((string) $v), $value),
			static fn($v) => $v !== ''
		));

		return $unique ? array_values(array_unique($list)) : $list;
	}

	/**
	 * Trim field map: tool name => list of kept field paths (e.g. "triggers[].triggerid").
	 */
	private static function trimFields($value): array {
		if (is_string($value)) {
			$value = json_decode($value, true);
		}

		if (!is_array($value)) {
			return [];
		}

		$result = [];

		foreach ($value as $tool => $fields) {
			$tool = trim((string) $tool);
			$fields = self::stringList($fields);

			if ($tool !== '' && $fields) {
				$result[$tool] = $fields;
			}
		}

		return $result;
	}
}
