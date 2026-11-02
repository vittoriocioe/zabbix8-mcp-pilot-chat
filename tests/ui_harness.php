<?php declare(strict_types = 0);
/*
 * UI test harness (php -S router). Serves the chat page with the module's real CSS and JavaScript and
 * answers the module's AJAX actions with the real orchestrator, MCP client and adapters.
 *
 * Only the Zabbix frontend shell is simulated: page chrome, Curl/PopUp helpers and CController plumbing.
 * The page markup mirrors views/mcppilotchat.view.php (same element IDs and classes).
 *
 * Environment: MCP_URL, LLM_URL.
 *
 * Copyright (C) 2026 Vittorio Cioe
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace {
	class CWebUser {
		public static array $data = ['sessionid' => 'sid-ok', 'userid' => '1', 'username' => 'jdoe'];
	}

	abstract class CController {}

	spl_autoload_register(static function (string $class): void {
		$prefix = 'Modules\\McpPilotChat\\';

		if (strpos($class, $prefix) !== 0) {
			return;
		}

		$parts = explode('\\', substr($class, strlen($prefix)));
		$file = array_pop($parts).'.php';
		$dir = implode('/', array_map('strtolower', $parts));
		$path = dirname(__DIR__).'/'.($dir !== '' ? $dir.'/' : '').$file;

		if (is_file($path)) {
			require $path;
		}
	});
}

namespace Harness {

	use Modules\McpPilotChat\Actions\StatusGet;
	use Modules\McpPilotChat\Lib\Adapters\LlmAdapter;
	use Modules\McpPilotChat\Lib\ChatState;
	use Modules\McpPilotChat\Lib\Config;
	use Modules\McpPilotChat\Lib\McpClient;
	use Modules\McpPilotChat\Lib\ModuleException;
	use Modules\McpPilotChat\Lib\Orchestrator;
	use Modules\McpPilotChat\Lib\Prompts;

	$root = dirname(__DIR__);
	$manifest = json_decode(file_get_contents($root.'/manifest.json'), true);
	$config = $manifest['config'];
	$config['mcp']['url'] = getenv('MCP_URL');
	$config['profiles'][0]['base_url'] = getenv('LLM_URL');
	$config['profiles'][0]['tool_allowlist'] = ['problem_get', 'problem_acknowledge'];
	$config['profiles'][0]['starter_prompts'][] = 'Please acknowledge the network problem';
	$config['profiles'][] = ['id' => 'cloud', 'name' => 'Cloud model (example)', 'base_url' => getenv('LLM_URL'),
		'model' => 'big', 'tool_allowlist' => []
	];
	// Write tools allowed and run without approval (for the side-panel warning).
	$config['profiles'][] = ['id' => 'risky', 'name' => 'No approval', 'base_url' => getenv('LLM_URL'),
		'model' => 'risky', 'tool_allowlist' => [], 'approval_required' => 0
	];
	$config = Config::normalize($config);

	$action = $_GET['action'] ?? '';

	// Another Zabbix page (a link target from an answer): no chat on it.
	if (basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) === 'tr_events.php') {
		echo '<!doctype html><html><head><title>Event details</title></head><body>Event details</body></html>';
		exit;
	}

	if ($action === 'mcppilotchat.status') {
		header('Content-Type: application/json');
		$profile = Config::findProfile($config, $_GET['profile_id'] ?? '');
		echo json_encode(StatusGet::status($config['mcp'], \CWebUser::$data['sessionid'],
			$profile['tool_allowlist'] ?? [], $manifest['version']
		));
		exit;
	}

	if ($action === 'mcppilotchat.step') {
		header('Content-Type: application/json');
		// Same merge as CController::getJsonInput(): URL parameters ($_REQUEST) win over JSON fields.
		$input = $_REQUEST + (json_decode(file_get_contents('php://input'), true) ?: []);

		if (!in_array($input['op'] ?? null, ['send', 'step', 'approve', 'deny'], true)) {
			echo json_encode(['ok' => false, 'error' => ['message' => 'Invalid request.', 'kind' => 'input']]);
			exit;
		}

		try {
			if (($input['_csrf_token'] ?? '') !== 'csrf-step') {
				throw new ModuleException('CSRF token missing.', 'input');
			}

			$profile = Config::findProfile($config, $input['profile_id']);
			$state = ($input['state'] ?? '') !== ''
				? ChatState::open($input['state'], $input['sig'] ?? '')
				: ChatState::create($profile['id']);

			$mcp = new McpClient($config['mcp']['url'], \CWebUser::$data['sessionid'], $config['mcp']['timeout']);
			$result = (new Orchestrator($profile, $mcp, LlmAdapter::create($profile), Prompts::template($profile, $config)))
				->handle($input['op'], $state, trim($input['message'] ?? ''));

			echo json_encode(['ok' => true] + ChatState::seal($result['state']) + $result['response'],
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
		}
		catch (ModuleException $e) {
			$error = ['message' => $e->getMessage(), 'kind' => $e->getKind()];

			if ($e->getRetryAfter() !== null) {
				$error['retry_after'] = $e->getRetryAfter();
			}

			echo json_encode(['ok' => false, 'error' => $error]);
		}

		exit;
	}

	$profiles = array_map([Config::class, 'publicProfile'], $config['profiles']);
	$css = file_get_contents($root.'/views/css/mcppilotchat.css.php');
	$popout_css = file_get_contents($root.'/views/css/mcppilotchat.popout.css.php');

	// Optional: the real Zabbix theme (ZBX_UI_DIR = a Zabbix "ui" folder), to test the layout inside Zabbix's CSS.
	$zbx_ui = getenv('ZBX_UI_DIR') ?: '';
	$zbx_theme = $zbx_ui !== '' ? $zbx_ui.'/assets/styles/'.(isset($_GET['dark']) ? 'dark-theme.css' : 'blue-theme.css') : '';
	$zbx_css = $zbx_theme !== '' && is_file($zbx_theme) ? file_get_contents($zbx_theme) : '';

	ob_start();
	include $root.'/views/js/mcppilotchat.view.js.php';
	$js = ob_get_clean();

	$init = json_encode([
		'profiles' => $profiles,
		'default_profile' => $profiles[0]['id'],
		'debug' => true,
		'username' => 'jdoe',
		'csrf' => ['step' => 'csrf-step'],
		'version' => $manifest['version'],
		'popout' => ($_GET['popout'] ?? '') === '1'
	], JSON_UNESCAPED_SLASHES);
	$popout = ($_GET['popout'] ?? '') === '1';

	$options = '';

	foreach ($profiles as $p) {
		$options .= '<option value="'.htmlspecialchars($p['id']).'">'
			.htmlspecialchars($p['model'] !== '' ? $p['name'].' - '.$p['model'] : $p['name']).'</option>';
	}

	?><!doctype html>
<html><head><meta charset="utf-8"><title><?= $popout ? 'MCP Pilot Chat (pop-out)' : 'MCP Pilot Chat harness' ?></title>
<?php if ($zbx_css !== ''): ?>
<style><?= $zbx_css ?></style>
<?php else: ?>
<style>
	body { font: 12px Arial, sans-serif; margin: 0; background: #fff; color: #1f2c33; }
	body.dark { background: #2b2b2b; color: #f2f2f2; }
	header .header-title { display: flex; gap: 10px; align-items: center; padding: 10px 16px; }
	header h1 { font-size: 18px; margin: 0; }
	header .header-controls { margin-left: auto; }
	header ul { display: flex; gap: 10px; align-items: center; list-style: none; margin: 0; padding: 0; }
	.sidebar-nav-toggle { display: none; }
	main { padding: 0 16px 16px; }
	button { font: inherit; padding: 4px 10px; cursor: pointer; }
	.color-positive { color: #2a9c58; } .color-negative { color: #d64e4e; } .color-warning { color: #cf8a00; }
	.link-alt { color: inherit; text-decoration: underline dotted; }
</style>
<?php endif ?>
<style><?= $css ?></style>
<?php if ($popout): ?><style><?= $popout_css ?></style><?php endif ?>
</head>
<body class="<?= isset($_GET['dark']) ? 'dark' : '' ?>">
<!-- Same structure as a Zabbix page in the kiosk layout (no sidebar): header, .wrapper > main + footer. -->
<header><div class="header-title">
	<nav class="sidebar-nav-toggle"></nav>
	<div class="overflow-ellipsis"><h1 id="page-title-general"><?= $popout ? 'MCP Pilot Chat (pop-out)' : 'MCP Pilot Chat' ?></h1></div>
	<div class="header-controls"><nav aria-label="Content controls"><ul>
		<li><label for="mcppilot-profile">LLM profile</label> <select id="mcppilot-profile"><?= $options ?></select>
			<!-- Built like the list of a Zabbix z-select dropdown, to check that pop-out styles do not reach it. -->
			<z-select id="zselect-probe" hidden><button type="button" class="focusable">x</button><ul class="list"><li>One</li><li>Two</li><li>Three</li></ul></z-select></li>
		<li><label><input type="checkbox" id="mcppilot-debug" checked> Debug</label></li>
		<li><button id="mcppilot-new" class="btn-alt" title="Clear this conversation and start a new one">New chat</button></li>
		<li><button id="mcppilot-export" class="btn-alt" title="Download this conversation with all model and MCP calls as a JSON file">Download chat log</button></li>
		<li><?php if ($popout): ?><button id="mcppilot-popin" class="btn-alt">Pop in</button><?php else: ?><button id="mcppilot-popout" class="btn-alt">Pop out</button><?php endif ?></li>
		<li><button id="mcppilot-configure" class="btn-alt">Configure</button></li>
	</ul></nav></div>
</div></header>
<div class="wrapper">
<main>
	<div class="mcppilot-titlebar"><span class="mcppilot-badge">External module</span><span class="mcppilot-version">v<?= $manifest['version'] ?></span><span class="mcppilot-version">Not part of Zabbix · answers come from the Zabbix MCP server with your permissions</span></div>
	<div id="mcppilot" class="mcppilot-layout mcppilot-debug-on<?= $popout ? ' mcppilot-popout' : '' ?>">
		<?php if ($popout): ?><div id="mcppilot-bar" class="mcppilot-bar"><span id="mcppilot-bar-text" class="mcppilot-bar-text">–</span><button type="button" id="mcppilot-bar-toggle" class="btn-link" aria-expanded="false" aria-controls="mcppilot-side">Details</button></div><?php endif ?>
		<div class="mcppilot-chat">
			<div id="mcppilot-messages" class="mcppilot-messages" role="log" aria-live="polite"></div>
			<div id="mcppilot-popped" class="mcppilot-popped-note"><div class="mcppilot-popped-text">The chat is open in a separate window.</div><div class="mcppilot-popped-buttons"><button type="button" id="mcppilot-popped-show">Show window</button><button type="button" id="mcppilot-popped-popin" class="btn-alt">Pop in</button></div></div>
			<div id="mcppilot-starters" class="mcppilot-starters"></div>
			<div class="mcppilot-composer">
				<textarea id="mcppilot-input" class="mcppilot-input" rows="3" maxlength="8000" placeholder="Ask about your monitoring… (Enter to send, Shift+Enter for a new line)"></textarea>
				<div class="mcppilot-composer-buttons"><button type="button" id="mcppilot-send">Send</button><button type="button" id="mcppilot-stop" class="btn-alt" disabled>Stop</button></div>
			</div>
		</div>
		<div id="mcppilot-side" class="mcppilot-side">
			<div class="mcppilot-card"><h4 class="mcppilot-side-title">MCP server<button type="button" id="mcppilot-refresh" class="btn-link mcppilot-refresh" title="Refresh">⟳</button></h4><div id="mcppilot-status" class="mcppilot-status">Checking…</div></div>
			<div class="mcppilot-card"><h4 class="mcppilot-side-title">Usage</h4><div class="mcppilot-usage-row"><span class="mcppilot-usage-label">This question</span><span id="mcppilot-usage-question">–</span></div><div class="mcppilot-usage-row"><span class="mcppilot-usage-label">This chat</span><span id="mcppilot-usage-chat">–</span></div><div class="mcppilot-usage-row"><span class="mcppilot-usage-label">Context</span><span id="mcppilot-context-text">–</span></div></div>
			<div class="mcppilot-card mcppilot-card-tools"><h4 class="mcppilot-side-title">Tools<span id="mcppilot-tools-count" class="mcppilot-side-count"></span></h4><div class="mcppilot-legend"><span class="mcppilot-dot mcppilot-dot-enabled">●</span> enabled <span class="mcppilot-dot mcppilot-dot-disabled">●</span> off in profile <span class="mcppilot-dot mcppilot-dot-missing">●</span> not exposed</div><div id="mcppilot-tools" class="mcppilot-tools"></div></div>
			<div class="mcppilot-footer">MCP Pilot Chat v<?= $manifest['version'] ?><br>External module, not part of Zabbix.<br>Uses the Zabbix MCP server with your permissions.</div>
		</div>
	</div>
</main>
<footer>Zabbix 8.0 © 2001–2026, Zabbix SIA</footer>
</div>
<script>
	// Zabbix frontend helpers used by the module.
	class Curl {
		constructor(url) { this.url = new URL(url, location.href); }
		setArgument(k, v) { this.url.searchParams.set(k, v); }
		getUrl() { return this.url.pathname.replace(/^\//, '') + this.url.search; }
	}
	window.PopUp = () => ({$dialogue: [document.createElement('div')]});
</script>
<?= $js ?>
<script>document.addEventListener('DOMContentLoaded', () => mcppilot_chat.init(<?= $init ?>));</script>
</body></html>
<?php
}
