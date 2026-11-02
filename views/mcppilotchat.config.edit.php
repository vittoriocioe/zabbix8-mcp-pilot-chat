<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

/**
 * Configuration popup (Super admin only). The form is built by JavaScript from the data below.
 *
 * @var CView $this
 * @var array $data
 */

$body = (new CDiv())
	->setId('mcppilot-config')
	->addClass('mcppilot-config');

$output = [
	'header' => _('MCP Pilot Chat configuration'),
	'body' => '<style>'.$this->readJsFile('mcppilotchat.css.php', null, '/css').'</style>'.$body->toString(),
	'buttons' => [
		[
			'title' => _('Save'),
			'class' => 'js-submit',
			'keepOpen' => true,
			'isSubmit' => true
		]
	],
	'script_inline' => $this->readJsFile('mcppilotchat.config.edit.js.php').
		'mcppilot_config.init('.json_encode([
			'config' => $data['config'],
			'user_groups' => $data['user_groups'],
			'profile_defaults' => $data['profile_defaults'],
			'builtin_prompt' => $data['builtin_prompt'],
			'default_trim_fields' => $data['default_trim_fields'],
			'lean_trim_fields' => $data['lean_trim_fields'],
			'catalog' => $data['catalog'],
			'presets' => $data['presets'],
			'csrf' => $data['csrf']
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).');',
	'dialogue_class' => 'modal-popup-large'
];

echo json_encode($output);
