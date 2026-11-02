<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

/**
 * Chat page: 2/3 chat, 1/3 side panel (MCP status, usage, tools).
 *
 * @var CView $this
 * @var array $data
 */

// Pop-out window: no Zabbix menu. Only this page's layout changes; the user's own layout setting is not touched.
if ($data['popout'] && method_exists($this, 'enableLayoutModes') && method_exists($this, 'setLayoutMode')) {
	$this->enableLayoutModes();
	$this->setLayoutMode(ZBX_LAYOUT_KIOSKMODE);
}

$profile_select = (new CSelect('profile_id'))
	->setId('mcppilot-profile')
	->setFocusableElementId('mcppilot-profile-btn')
	->setAttribute('aria-label', _('LLM profile'));

foreach ($data['profiles'] as $profile) {
	// "Profile - model", so it is always clear which model answers.
	$profile_select->addOption(new CSelectOption($profile['id'],
		$profile['model'] !== '' ? $profile['name'].' - '.$profile['model'] : $profile['name']
	));
}

if ($data['default_profile'] !== '') {
	$profile_select->setValue($data['default_profile']);
}

$controls = (new CList())
	->addItem([
		new CLabel(_('LLM profile'), 'mcppilot-profile-btn'),
		(new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
		$profile_select
	])
	->addItem(
		(new CCheckBox('mcppilot_debug'))
			->setId('mcppilot-debug')
			->setChecked($data['debug_default'])
			->setLabel(_('Debug'))
	)
	->addItem(
		(new CSimpleButton(_('New chat')))
			->setId('mcppilot-new')
			->setTitle(_('Clear this conversation and start a new one'))
			->addClass(ZBX_STYLE_BTN_ALT)
	)
	->addItem(
		(new CSimpleButton(_('Download chat log')))
			->setId('mcppilot-export')
			->setTitle(_('Download this conversation with all model and MCP calls as a JSON file'))
			->addClass(ZBX_STYLE_BTN_ALT)
	)
	->addItem(
		$data['popout']
			? (new CSimpleButton(_('Pop in')))
				->setId('mcppilot-popin')
				->setTitle(_('Move the chat back into the main Zabbix window'))
				->addClass(ZBX_STYLE_BTN_ALT)
			: (new CSimpleButton(_('Pop out')))
				->setId('mcppilot-popout')
				->setTitle(_('Open the chat in its own window, to keep it while you use Zabbix'))
				->addClass(ZBX_STYLE_BTN_ALT)
	);

if ($data['can_configure']) {
	$controls->addItem(
		(new CSimpleButton(_('Configure')))
			->setId('mcppilot-configure')
			->addClass(ZBX_STYLE_BTN_ALT)
	);
}

$title = [
	(new CSpan(_('External module')))->addClass('mcppilot-badge'),
	(new CSpan('v'.$data['version']))->addClass('mcppilot-version'),
	(new CSpan(_('Not part of Zabbix · answers come from the Zabbix MCP server with your permissions')))
		->addClass('mcppilot-version')
];

// Chat column.
$chat = (new CDiv([
	(new CDiv())
		->setId('mcppilot-messages')
		->addClass('mcppilot-messages')
		->setAttribute('role', 'log')
		->setAttribute('aria-live', 'polite'),
	(new CDiv([
		(new CDiv(_('The chat is open in a separate window.')))->addClass('mcppilot-popped-text'),
		(new CDiv([
			(new CSimpleButton(_('Show window')))->setId('mcppilot-popped-show'),
			(new CSimpleButton(_('Pop in')))
				->setId('mcppilot-popped-popin')
				->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('mcppilot-popped-buttons')
	]))
		->setId('mcppilot-popped')
		->addClass('mcppilot-popped-note'),
	(new CDiv())
		->setId('mcppilot-starters')
		->addClass('mcppilot-starters'),
	(new CDiv([
		(new CTextArea('mcppilot_input'))
			->setId('mcppilot-input')
			->addClass('mcppilot-input')
			->setAttribute('rows', 3)
			->setAttribute('placeholder', _('Ask about your monitoring… (Enter to send, Shift+Enter for a new line)'))
			->setAttribute('maxlength', 8000),
		(new CDiv([
			(new CSimpleButton(_('Send')))->setId('mcppilot-send'),
			(new CSimpleButton(_('Stop')))
				->setId('mcppilot-stop')
				->addClass(ZBX_STYLE_BTN_ALT)
				->setEnabled(false)
		]))->addClass('mcppilot-composer-buttons')
	]))->addClass('mcppilot-composer')
]))->addClass('mcppilot-chat');

// Side panel.
$side = (new CDiv([
	(new CDiv([
		(new CTag('h4', true, [
			_('MCP server'),
			(new CSimpleButton('⟳'))
				->setId('mcppilot-refresh')
				->setTitle(_('Refresh'))
				->addClass(ZBX_STYLE_BTN_LINK)
				->addClass('mcppilot-refresh')
		]))->addClass('mcppilot-side-title'),
		(new CDiv(_('Checking…')))->setId('mcppilot-status')->addClass('mcppilot-status')
	]))->addClass('mcppilot-card'),
	(new CDiv([
		(new CTag('h4', true, _('Usage')))->addClass('mcppilot-side-title'),
		(new CDiv([
			(new CSpan(_('This question')))->addClass('mcppilot-usage-label'),
			(new CSpan('–'))->setId('mcppilot-usage-question')
		]))->addClass('mcppilot-usage-row'),
		(new CDiv([
			(new CSpan(_('This chat')))->addClass('mcppilot-usage-label'),
			(new CSpan('–'))->setId('mcppilot-usage-chat')
		]))->addClass('mcppilot-usage-row'),
		(new CDiv([
			(new CSpan(_('Context')))
				->addClass('mcppilot-usage-label')
				->setTitle(_('Size of the last request compared with the model\'s context window. Near the limit, start a new chat.')),
			(new CSpan('–'))->setId('mcppilot-context-text')
		]))->addClass('mcppilot-usage-row')
	]))->addClass('mcppilot-card'),
	(new CDiv([
		(new CTag('h4', true, [
			_('Tools'),
			(new CSpan(''))->setId('mcppilot-tools-count')->addClass('mcppilot-side-count')
		]))->addClass('mcppilot-side-title'),
		(new CDiv([
			(new CSpan('●'))->addClass('mcppilot-dot mcppilot-dot-enabled'), ' ', _('enabled'), ' ',
			(new CSpan('●'))->addClass('mcppilot-dot mcppilot-dot-disabled'), ' ', _('off in profile'), ' ',
			(new CSpan('●'))->addClass('mcppilot-dot mcppilot-dot-missing'), ' ', _('not exposed')
		]))->addClass('mcppilot-legend'),
		(new CDiv())->setId('mcppilot-tools')->addClass('mcppilot-tools')
	]))->addClass('mcppilot-card mcppilot-card-tools'),
	(new CDiv([
		'MCP Pilot Chat v'.$data['version'], BR(),
		_('External module, not part of Zabbix.'), BR(),
		_('Uses the Zabbix MCP server with your permissions.')
	]))->addClass('mcppilot-footer')
]))
	->setId('mcppilot-side')
	->addClass('mcppilot-side');

// Pop-out in a narrow window: one status line instead of the side panel, which "Details" opens.
$status_bar = $data['popout']
	? (new CDiv([
		(new CSpan('–'))
			->setId('mcppilot-bar-text')
			->addClass('mcppilot-bar-text'),
		(new CSimpleButton(_('Details')))
			->setId('mcppilot-bar-toggle')
			->addClass(ZBX_STYLE_BTN_LINK)
			->setAttribute('aria-expanded', 'false')
			->setAttribute('aria-controls', 'mcppilot-side')
	]))
		->setId('mcppilot-bar')
		->addClass('mcppilot-bar')
	: null;

// Raw output: CTag would HTML-escape the stylesheet.
echo '<style>'.$this->readJsFile('mcppilotchat.css.php', null, '/css').'</style>';

// Pop-out window only: let the Zabbix page fit a small window. These rules are not sent on any other page.
if ($data['popout']) {
	echo '<style>'.$this->readJsFile('mcppilotchat.popout.css.php', null, '/css').'</style>';
}

$body = [
	(new CDiv([$status_bar, $chat, $side]))
		->setId('mcppilot')
		->addClass('mcppilot-layout')
		->addClass($data['debug_default'] ? 'mcppilot-debug-on' : null)
		->addClass($data['popout'] ? 'mcppilot-popout' : null)
];

if (!$data['profiles']) {
	array_unshift($body, makeMessageBox(ZBX_STYLE_MSG_WARNING, [], $data['can_configure']
		? _('No LLM profile is configured yet. Click "Configure" to add one.')
		: _('No LLM profile is available to you. Ask a Super admin to configure MCP Pilot Chat.'),
		false
	));
}

(new CHtmlPage())
	->setTitle($data['popout'] ? _('MCP Pilot Chat (pop-out)') : _('MCP Pilot Chat'))
	->setTitleSubmenu([])
	->setControls((new CTag('nav', true, $controls))->setAttribute('aria-label', _('Content controls')))
	->addItem((new CDiv($title))->addClass('mcppilot-titlebar'))
	->addItem($body)
	->show();

$this->includeJsFile('mcppilotchat.view.js.php');

(new CScriptTag('mcppilot_chat.init('.json_encode([
	'profiles' => $data['profiles'],
	'default_profile' => $data['default_profile'],
	'debug' => $data['debug_default'],
	'username' => $data['username'],
	'csrf' => $data['csrf'],
	'version' => $data['version'],
	'popout' => $data['popout']
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).');'))
	->setOnDocumentReady()
	->show();
