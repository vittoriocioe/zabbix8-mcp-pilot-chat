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
	Throwable;

use Modules\McpPilotChat\Lib\Access,
	Modules\McpPilotChat\Lib\Config,
	Modules\McpPilotChat\Lib\JsonResponseTrait;

/**
 * Saves the configuration from the popup (Super admin only).
 *
 * POST JSON: {config: {...}, _csrf_token}
 */
class ConfigUpdate extends CController {

	use JsonResponseTrait;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput(['config' => 'required|array']);

		if (!$ret) {
			$this->respondInvalidInput();
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return Access::canConfigure();
	}

	protected function doAction(): void {
		$submitted = $this->getInput('config');
		$errors = self::validate($submitted);

		if ($errors) {
			$this->respondJson(['ok' => false, 'error' => ['message' => implode("\n", $errors), 'kind' => 'input']]);

			return;
		}

		try {
			$config = Config::normalize(Config::mergeSecrets($submitted, Config::get()));

			if (Config::findProfile($config, $config['ui']['default_profile']) === null) {
				$config['ui']['default_profile'] = $config['profiles'][0]['id'] ?? '';
			}

			Config::save($config);

			$this->respondJson(['ok' => true, 'message' => _('Configuration saved.')]);
		}
		catch (Throwable $e) {
			$this->respondError('Cannot save configuration: '.$e->getMessage());
		}
	}

	private static function validate(array $config): array {
		$errors = [];

		$url = trim((string) ($config['mcp']['url'] ?? ''));

		if ($url === '' || !preg_match('#^https?://.+#i', $url)) {
			$errors[] = _('MCP server URL must start with http:// or https://.');
		}

		$timeout = $config['mcp']['timeout'] ?? '';

		if (!is_numeric($timeout) || (int) $timeout != $timeout || $timeout < 3 || $timeout > 120) {
			$errors[] = _('MCP server timeout must be a whole number from 3 to 120 seconds.');
		}

		$ids = [];

		foreach (is_array($config['profiles'] ?? null) ? $config['profiles'] : [] as $i => $profile) {
			$name = trim((string) ($profile['name'] ?? '')) ?: (string) ($profile['id'] ?? '');
			$label = $name !== '' ? _s('LLM profile "%1$s"', $name) : _s('LLM profile %1$d', $i + 1);
			$id = (string) ($profile['id'] ?? '');

			if (!preg_match('/^[a-z0-9_-]+$/', $id)) {
				$errors[] = $label.': '._('ID may contain only lowercase letters, digits, "-" and "_".');
			}
			elseif (array_key_exists($id, $ids)) {
				$errors[] = $label.': '._s('ID "%1$s" is used more than once.', $id);
			}

			$ids[$id] = true;

			$adapter = (string) ($profile['adapter'] ?? 'openai');
			$base_url = trim((string) ($profile['base_url'] ?? ''));
			$generic_url = trim((string) ($profile['generic']['url'] ?? ''));

			if ($adapter === 'openai' && !preg_match('#^https?://.+#i', $base_url)) {
				$errors[] = $label.': '._('Base URL must start with http:// or https://.');
			}

			if ($adapter === 'generic_http') {
				if (!preg_match('#^https?://.+#i', $generic_url !== '' ? $generic_url : $base_url)) {
					$errors[] = $label.': '._('Endpoint URL must start with http:// or https://.');
				}

				if (trim((string) ($profile['generic']['request_template'] ?? '')) === '') {
					$errors[] = $label.': '._('Request template is required for the Generic HTTP adapter.');
				}
			}

			$extra = trim((string) ($profile['extra_params'] ?? ''));

			if ($extra !== '' && !is_array(json_decode($extra, true))) {
				$errors[] = $label.': '._('Extra request parameters must be a JSON object.');
			}
		}

		return $errors;
	}
}
