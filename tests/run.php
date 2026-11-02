<?php declare(strict_types = 0);
/*
 * Backend tests for MCP Pilot Chat, without a Zabbix installation.
 *
 * Starts a mock MCP server and a mock LLM with "php -S", then drives the orchestrator through full
 * conversations exactly as the ChatStep action does.
 *
 * Usage: php tests/run.php
 *
 * Copyright (C) 2026 Vittorio Cioe
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace {
	// Minimal stand-ins for the Zabbix frontend classes used by the libraries under test.
	class CWebUser {
		public static array $data = ['sessionid' => 'sid-ok', 'userid' => '1', 'username' => 'jdoe'];
	}

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

namespace Tests {

	use CWebUser;
	use Modules\McpPilotChat\Lib\Adapters\LlmAdapter;
	use Modules\McpPilotChat\Lib\ChatState;
	use Modules\McpPilotChat\Lib\Config;
	use Modules\McpPilotChat\Lib\McpClient;
	use Modules\McpPilotChat\Lib\ModuleException;
	use Modules\McpPilotChat\Lib\Orchestrator;
	use Modules\McpPilotChat\Lib\Prompts;
	use Modules\McpPilotChat\Lib\ResultTrimmer;
	use Modules\McpPilotChat\Lib\ToolCatalog;

	$failures = 0;
	$checks = 0;

	function check(bool $condition, string $label): void {
		global $failures, $checks;

		$checks++;
		echo ($condition ? '  ok   ' : '  FAIL ').$label."\n";

		if (!$condition) {
			$failures++;
		}
	}

	function server(string $script, int $port) {
		$proc = proc_open(['php', '-S', '127.0.0.1:'.$port, $script], [1 => ['file', '/dev/null', 'w'],
			2 => ['file', '/dev/null', 'w']
		], $pipes);

		for ($i = 0; $i < 50; $i++) {
			if (@fsockopen('127.0.0.1', $port)) {
				return $proc;
			}

			usleep(100000);
		}

		throw new \RuntimeException('Cannot start '.$script);
	}

	$mcp_proc = server(__DIR__.'/mock_mcp.php', 18443);
	$llm_proc = server(__DIR__.'/mock_llm.php', 18080);

	$profile = Config::normalizeProfile([
		'id' => 'local-demo',
		'name' => 'Local demo',
		'base_url' => 'http://127.0.0.1:18080/v1',
		'model' => 'qwen3-0.6b',
		'max_iterations' => 4,
		'tool_allowlist' => ['problem_get', 'problem_acknowledge'],
		'extra_params' => '{"chat_template_kwargs": {"enable_thinking": false}}'
	]);

	/**
	 * Runs one user turn like the browser does: send, then step/approve until done.
	 */
	function turn(array $profile, string $mcp_url, ?array $sealed, string $message, string $approval = 'approve'): array {
		$events = [];
		$actions = [];
		$steps = [];
		$action = 'send';
		$response = null;

		for ($i = 0; $i < 20; $i++) {
			$state = $sealed === null ? ChatState::create($profile['id']) : ChatState::open($sealed['state'], $sealed['sig']);
			$mcp = new McpClient($mcp_url, CWebUser::$data['sessionid'], 10);
			$result = (new Orchestrator($profile, $mcp, LlmAdapter::create($profile), Prompts::template($profile, [])))
				->handle($action, $state, $action === 'send' ? $message : '');

			$sealed = ChatState::seal($result['state']);
			$response = $result['response'];
			$steps[] = $response;
			$events = array_merge($events, $response['events']);
			$actions[] = $action;

			if ($response['next'] === Orchestrator::NEXT_DONE) {
				break;
			}

			$action = $response['next'] === Orchestrator::NEXT_APPROVAL ? $approval : 'step';
		}

		return ['sealed' => $sealed, 'events' => $events, 'actions' => $actions, 'last' => $response,
			'first' => $steps[0], 'steps' => $steps
		];
	}

	try {
		echo "MCP client\n";

		$mcp = new McpClient('http://127.0.0.1:18443/mcp', 'sid-ok', 5);
		$init = $mcp->initialize('0.1.0');
		check(($init['result']['serverInfo']['name'] ?? '') === 'zabbix-mcp-server', 'initialize returns serverInfo');

		$tools = $mcp->listTools();
		check(count($tools) === 3, 'tools/list returns 3 tools');

		$sse = new McpClient('http://127.0.0.1:18443/mcp?sse=1', 'sid-ok', 5);
		$sse_tools = $sse->listTools();
		check(count($sse_tools) === 3, 'SSE response is parsed (skips a notification event)');

		$about = json_decode($mcp->readResource('zabbix://about')['text'], true);
		check(($about['user']['username'] ?? '') === 'jdoe', 'resources/read zabbix://about');

		try {
			(new McpClient('http://127.0.0.1:18443/mcp', 'sid-expired', 5))->listTools();
			check(false, 'expired session raises auth error');
		}
		catch (ModuleException $e) {
			check($e->getKind() === 'auth', 'expired session raises auth error ('.$e->getMessage().')');
		}

		try {
			(new McpClient('http://127.0.0.1:18999/mcp', 'sid-ok', 3))->listTools();
			check(false, 'unreachable server raises error');
		}
		catch (ModuleException $e) {
			check($e->getKind() === 'unreachable', 'unreachable server raises "unreachable"');
		}

		echo "\nTool catalog\n";

		$panel = ToolCatalog::panel($tools, ['problem_get']);
		$by_name = array_column($panel, 'state', 'name');
		check(count($panel) === 22, 'panel lists the 22 catalog tools');
		check($by_name['problem_get'] === 'enabled', 'problem_get: exposed and allowed = enabled (green)');
		check($by_name['host_get'] === 'disabled', 'host_get: exposed, not allowed = disabled (yellow)');
		check($by_name['item_get'] === 'missing', 'item_get: not exposed = missing (red)');
		check(ToolCatalog::typeOf($tools[2]) === 'write', 'problem_acknowledge detected as write from annotations');
		check(ToolCatalog::baseName('zbx_problem_get') === 'problem_get', 'ToolPrefix is stripped for matching');

		echo "\nResult trimming\n";

		$raw = $mcp->callTool('problem_get', ['limit' => 5])['text'];
		$trimmed = ResultTrimmer::trim('problem_get', $raw, [], true);
		$data = json_decode($trimmed['text'], true);
		check($trimmed['trimmed'] && !array_key_exists('ns', $data['results'][0]), 'noise field "ns" removed');
		check(($data['results'][0]['objectid'] ?? '') === '34081', 'objectid kept');
		check(($data['results'][0]['clock'] ?? '') === gmdate('Y-m-d H:i:s', 1791818694).' UTC', 'clock formatted as UTC');
		check(count($data['results'][0]) === 7, 'only the default fields kept, empty opdata dropped');
		check(($data['results'][1]['opdata'] ?? '') === 'Current utilization: 98.3 %', 'non-empty opdata kept');
		$lean = json_decode(ResultTrimmer::trim('problem_get', $raw, ResultTrimmer::LEAN_FIELDS, true)['text'], true);
		check(!isset($lean['results'][0]['opdata']) && isset($lean['results'][1]['opdata']),
			'lean fields: opdata kept only when not empty'
		);
		check(strlen($trimmed['text']) < strlen($raw), sprintf('smaller: %d -> %d bytes', strlen($raw),
			strlen($trimmed['text'])
		));

		$host_raw = json_encode(['results' => [['hostid' => '1', 'name' => 'ATM001', 'tls_accept' => '1',
			'triggers' => [['triggerid' => '34971', 'description' => 'High CPU', 'priority' => '2', 'value' => '1',
				'opdata' => 'x', 'state' => '0']]
		]], 'count' => 1]);
		$host = json_decode(ResultTrimmer::trim('host_get', $host_raw, [], false)['text'], true)['results'][0];
		check(!isset($host['tls_accept']) && !isset($host['triggers'][0]['state'])
			&& ($host['triggers'][0]['opdata'] ?? '') === 'x'
			&& $host['triggers'][0]['triggerid'] === '34971', 'nested triggers[] fields trimmed');

		echo "\nConversation: question -> tool call -> answer\n";

		$t = turn($profile, 'http://127.0.0.1:18443/mcp', null, 'Show me the current problems');
		$types = array_column($t['events'], 'type');
		check($types === ['llm', 'tool', 'llm', 'answer'], 'event sequence llm, tool, llm, answer ('.implode(', ', $types).')');
		check($t['actions'] === ['send', 'step', 'step'], 'three requests: send, step, step');
		check($t['events'][1]['name'] === 'problem_get' && $t['events'][1]['arguments'] === ['limit' => 5],
			'tool event has name and arguments'
		);
		check($t['events'][1]['bytes_trimmed'] < $t['events'][1]['bytes_raw'], 'tool event reports trimming');
		check(strpos($t['events'][3]['text'], '</think>') === false && strpos($t['events'][3]['text'], 'Let me think') === false,
			'leaked reasoning removed from the answer'
		);
		check($t['last']['prompt_tokens'] === 455, 'prompt tokens reported for the context meter');

		$last_request = json_decode(file_get_contents(sys_get_temp_dir().'/mcppilot_last_llm_request.json'), true);
		check(($last_request['chat_template_kwargs']['enable_thinking'] ?? null) === false, 'extra params merged into request');
		check($last_request['messages'][0]['role'] === 'system'
			&& strpos($last_request['messages'][0]['content'], 'Severities') !== false, 'system prompt sent first');
		check(array_column(array_column($last_request['tools'], 'function'), 'name') === ['problem_get', 'problem_acknowledge'],
			'only allowed tools offered (host_get filtered out)'
		);
		check(is_array($last_request['tools'][0]['function']['parameters']['properties']),
			'tool schema passed to the model'
		);
		$tool_msg = array_values(array_filter($last_request['messages'], fn($m) => $m['role'] === 'tool'))[0];
		check(strpos($tool_msg['content'], '"ns"') === false, 'model received the trimmed result');

		echo "\nConversation continues (history kept)\n";

		$t2 = turn($profile, 'http://127.0.0.1:18443/mcp', $t['sealed'], 'And now?');
		$state = ChatState::open($t2['sealed']['state'], $t2['sealed']['sig']);
		check(count(array_filter($state['messages'], fn($m) => $m['role'] === 'user')) === 2, 'two user messages in history');

		echo "\nWrite tool approval\n";

		$t3 = turn($profile, 'http://127.0.0.1:18443/mcp', null, 'Please acknowledge the network problem', 'approve');
		check(in_array('approve', $t3['actions'], true), 'loop paused for approval and continued after "approve"');
		$ack = array_values(array_filter($t3['events'], fn($e) => $e['type'] === 'tool'))[0];
		check($ack['name'] === 'problem_acknowledge' && empty($ack['error']), 'write tool executed after approval');

		$t4 = turn($profile, 'http://127.0.0.1:18443/mcp', null, 'Please acknowledge the network problem', 'deny');
		$denied = array_values(array_filter($t4['events'], fn($e) => $e['type'] === 'tool'))[0];
		check(!empty($denied['denied']), 'write tool not executed after "deny"');
		check(end($t4['events'])['type'] === 'answer', 'model still answers after a denial');

		echo "\nSafety limits\n";

		$t5 = turn($profile, 'http://127.0.0.1:18443/mcp', null, 'loop forever');
		$llm_calls = count(array_filter($t5['events'], fn($e) => $e['type'] === 'llm'));
		check($llm_calls === 4, 'stops at max_iterations (4 model calls)');
		check(end($t5['events'])['type'] === 'notice', 'limit notice shown');

		$restricted = Config::normalizeProfile(['tool_allowlist' => ['problem_get']] + $profile);
		$state = ChatState::create('x');
		$state['messages'][] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1',
			'type' => 'function', 'function' => ['name' => 'problem_acknowledge', 'arguments' => '{}']
		]]];
		$state['pending'][] = ['id' => 'c1', 'name' => 'problem_acknowledge', 'arguments' => [], 'arguments_raw' => '{}',
			'approved' => true
		];
		$r = (new Orchestrator($restricted, new McpClient('http://127.0.0.1:18443/mcp', 'sid-ok', 5),
			LlmAdapter::create($restricted), Prompts::BUILT_IN
		))->handle('step', $state);
		check(strpos($r['response']['events'][0]['error'] ?? '', 'not enabled') !== false,
			'tool outside the profile allow-list is refused'
		);

		echo "\nState integrity\n";

		$sealed = ChatState::seal(ChatState::create('x'));
		$tampered = str_replace('"pending":[]', '"pending":[{"id":"x","name":"problem_close","arguments":{},"approved":true}]',
			$sealed['state']
		);

		try {
			ChatState::open($tampered, $sealed['sig']);
			check(false, 'tampered state rejected');
		}
		catch (ModuleException $e) {
			check($e->getKind() === 'state', 'tampered state rejected (browser cannot inject approvals)');
		}

		CWebUser::$data['sessionid'] = 'another-session';

		try {
			ChatState::open($sealed['state'], $sealed['sig']);
			check(false, 'state from another session rejected');
		}
		catch (ModuleException $e) {
			check(true, 'state from another session rejected');
		}

		CWebUser::$data['sessionid'] = 'sid-ok';

		echo "\nLLM adapter test button\n";

		$test = LlmAdapter::create($profile)->test();
		check($test['ok'] && $test['details']['models'] === ['qwen3-0.6b'], 'OpenAI adapter test lists models');

		$bad = Config::normalizeProfile(['base_url' => 'http://127.0.0.1:18080/nope'] + $profile);

		try {
			LlmAdapter::create($bad)->send([['role' => 'user', 'content' => 'x']], []);
			check(false, 'HTTP error from LLM raises');
		}
		catch (ModuleException $e) {
			check($e->getKind() === 'llm' && strpos($e->getMessage(), 'HTTP 404') !== false, 'HTTP error from LLM raises "llm"');
		}

		echo "\nPrompts\n";

		$cfg = Config::normalize(['prompts' => ['system' => '']]);
		$p_default = Config::normalizeProfile(['id' => 'a']);
		check(Prompts::template($p_default, $cfg) === Prompts::BUILT_IN, 'empty module prompt = built-in prompt');

		$cfg = Config::normalize(['prompts' => ['system' => 'Module prompt {{default_limit}}']]);
		check(Prompts::system($p_default, $cfg) === 'Module prompt 10', 'profile in default mode uses module prompt');

		$p_custom = Config::normalizeProfile(['id' => 'b', 'prompt_mode' => 'custom', 'system_prompt' => 'Short.']);
		check(Prompts::template($p_custom, $cfg) === 'Short.', 'custom profile prompt overrides module prompt');

		$p_old = Config::normalizeProfile(['id' => 'c', 'system_prompt' => 'Old custom']);
		check($p_old['prompt_mode'] === 'custom', 'profile saved by v0.1.x with a prompt becomes "custom"');

		check(Config::normalize(['prompts' => ['system' => Prompts::BUILT_IN."\n"]])['prompts']['system'] === '',
			'module prompt equal to built-in is stored empty (follows updates)'
		);
		check(strpos(Prompts::BUILT_IN, 'NOT the total') !== false, 'built-in prompt explains count vs. total');

		echo "\nRequire a tool call on the first step\n";

		$t6 = turn($profile, 'http://127.0.0.1:18443/mcp', null, 'hello');
		check(array_column($t6['events'], 'type') === ['llm', 'answer'], 'off: small model greets without a tool');
		check($t6['events'][0]['tool_choice'] === 'auto', 'off: tool_choice "auto"');

		$forced = Config::normalizeProfile(['require_tool_first' => 1] + $profile);
		$t7 = turn($forced, 'http://127.0.0.1:18443/mcp', null, 'hello');
		$llm_events = array_values(array_filter($t7['events'], fn($e) => $e['type'] === 'llm'));
		check($llm_events[0]['tool_choice'] === 'required', 'on: first model call has tool_choice "required"');
		check(count($llm_events) === 2 && $llm_events[1]['tool_choice'] === 'auto', 'on: later model calls are "auto"');
		check(end($t7['events'])['type'] === 'answer', 'on: model still answers after the tool call');

		$probe = LlmAdapter::create($forced)->test();
		check($probe['ok'] && strpos($probe['message'], 'Forced tool calls work') !== false,
			'Test connection verifies forced tool calls ('.$probe['message'].')'
		);

		echo "\nTrace\n";

		$first_llm = $t['events'][0];
		check($first_llm['tools_offered'] === ['problem_get', 'problem_acknowledge'], 'trace lists the tools offered');
		check($first_llm['request'][0]['role'] === 'system' && end($first_llm['request'])['role'] === 'user',
			'trace contains the messages sent'
		);
		$tool_event = $t['events'][1];
		check(strpos($tool_event['trimmed'], '"note"') === false && strpos($tool_event['trimmed'], '"truncated"') !== false,
			'long "note" dropped, "truncated" kept'
		);

		echo "\nProvider limits\n";

		@unlink(sys_get_temp_dir().'/mcppilot_mock_ratelimit');
		$adapter = LlmAdapter::create($profile);

		try {
			$adapter->send([['role' => 'user', 'content' => 'ratelimit test']], []);
			check(false, '429 raises rate_limit');
		}
		catch (ModuleException $e) {
			check($e->getKind() === 'rate_limit', '429 raises "rate_limit"');
			check($e->getRetryAfter() === 2.0, 'Retry-After header used as wait time ('.$e->getRetryAfter().')');
			check(strpos($e->getMessage(), 'tokens per minute') !== false, 'message mentions tokens per minute');
		}

		$r = $adapter->send([['role' => 'user', 'content' => 'ratelimit test']], []);
		check($r['text'] !== '' || $r['tool_calls'], 'same request succeeds when sent again');

		$retry = new class(['name' => 'x']) extends LlmAdapter {
			public function send(array $messages, array $tools, array $options = []): array { return []; }
			public function test(): array { return []; }
			public function supportsTools(): bool { return true; }
			public static function wait(string $m): ?float { return self::retryAfter([], $m); }
		};
		check($retry::wait('Please try again in 1.74s. Need more') === 1.74, 'parses "try again in 1.74s"');
		check($retry::wait('Please try again in 1m2.5s.') === 62.5, 'parses "try again in 1m2.5s"');
		check($retry::wait('Please try again in 450ms.') === 0.45, 'parses "try again in 450ms"');
		check($retry::wait('Slow down.') === null, 'no wait time when none is given');

		try {
			$adapter->send([['role' => 'user', 'content' => 'toolarge']], []);
			check(false, '413 too large');
		}
		catch (ModuleException $e) {
			check($e->getKind() === 'too_large' && $e->getRetryAfter() === null
				&& strpos($e->getMessage(), 'needs 12000 tokens') !== false, '413 over the limit: "too_large", no retry');
		}

		echo "\nToken cut-off and reasoning\n";

		$t8 = turn($profile, 'http://127.0.0.1:18443/mcp', null, 'cutoff please');
		$notice = array_values(array_filter($t8['events'], fn($e) => $e['type'] === 'notice'))[0] ?? null;
		check($notice !== null && strpos($notice['text'], 'before it wrote any answer') !== false,
			'cut-off answer explained ('.($notice['text'] ?? '-').')'
		);
		check(strpos($notice['text'] ?? '', 'reasoning effort') !== false, 'reasoning models get the reasoning hint');
		check(strpos(end($t8['events'])['text'], 'ran out of tokens') !== false, 'answer says it ran out of tokens');
		check(strpos($t8['events'][0]['reasoning'] ?? '', 'consider severities') !== false, 'reasoning in the trace');

		$t9 = turn($profile, 'http://127.0.0.1:18443/mcp', null, 'Show me the current problems');
		$last_llm = array_values(array_filter($t9['events'], fn($e) => $e['type'] === 'llm'))[1];
		check(strpos($last_llm['reasoning'] ?? '', 'Let me think') !== false, 'leaked <think> text shown as reasoning');

		echo "\nGemini and Gemma\n";

		$t_gemini = turn($profile, 'http://127.0.0.1:18443/mcp', null, 'gemini: show problems');
		check(end($t_gemini['events'])['type'] === 'answer', 'Gemini thought signature sent back: second call succeeds');
		$state = ChatState::open($t_gemini['sealed']['state'], $t_gemini['sealed']['sig']);
		$assistant = array_values(array_filter($state['messages'], fn($m) => !empty($m['tool_calls'])))[0];
		check(($assistant['tool_calls'][0]['extra_content']['google']['thought_signature'] ?? '') === 'SIG123',
			'thought signature kept in the conversation history'
		);
		check(($assistant['tool_calls'][0]['provider_ref'] ?? '') === 'ref-42',
			'any other provider field of a tool call is sent back unchanged'
		);
		check(!array_key_exists('index', $assistant['tool_calls'][0]), 'streaming "index" field dropped');
		check(!array_key_exists('raw', $state['pending'][0] ?? []) && empty($state['pending']), 'raw call not kept in pending');

		$t_gemma = turn($profile, 'http://127.0.0.1:18443/mcp', null, 'gemma: show problems');
		$answer = end($t_gemma['events']);
		check($answer['type'] === 'answer' && $answer['text'] === 'There are **2** problems.',
			'Gemma <thought> block removed from the answer'
		);
		$llm2 = array_values(array_filter($t_gemma['events'], fn($e) => $e['type'] === 'llm'))[1];
		check(($llm2['reasoning'] ?? '') === 'The user wants problems. Two are listed.', 'Gemma reasoning shown in trace');

		@unlink(sys_get_temp_dir().'/mcppilot_mock_busy');

		try {
			LlmAdapter::create($profile)->send([['role' => 'user', 'content' => 'busy']], []);
			check(false, '503 high demand becomes a retryable error');
		}
		catch (ModuleException $e) {
			check($e->getKind() === 'rate_limit' && $e->getRetryAfter() === 5.0
				&& strpos($e->getMessage(), 'overloaded') !== false, '503 high demand becomes a retryable error (5 s)');
		}

		echo "\nMaximum limit\n";

		$capped = Config::normalizeProfile(['max_limit' => 3] + $profile);
		check($capped['default_limit'] === 3, 'default limit lowered to the maximum limit');
		$t_cap = turn($capped, 'http://127.0.0.1:18443/mcp', null, 'Show me the current problems');
		$tool_events = array_values(array_filter($t_cap['events'], fn($e) => $e['type'] === 'tool'));
		check(strpos($tool_events[0]['error'] ?? '', 'above the maximum of 3') !== false,
			'tool call with limit 5 over the maximum 3 refused ('.($tool_events[0]['error'] ?? '-').')'
		);
		check(end($t_cap['events'])['type'] === 'answer', 'model still answers after the refusal');
		$req = json_decode(file_get_contents(sys_get_temp_dir().'/mcppilot_last_llm_request.json'), true);
		check(strpos($req['messages'][0]['content'], 'default 3, maximum 3') !== false, 'prompt states the maximum');
		$t_nocap = turn(Config::normalizeProfile(['max_limit' => 0] + $profile), 'http://127.0.0.1:18443/mcp', null,
			'Show me the current problems'
		);
		check(empty(array_values(array_filter($t_nocap['events'], fn($e) => $e['type'] === 'tool'))[0]['error']),
			'max limit 0 = no maximum'
		);
		check(Prompts::fill('{{max_limit}}', ['default_limit' => 1, 'max_limit' => 0]) === 'none',
			'{{max_limit}} is "none" without a maximum'
		);

		echo "\nTruncated results (display)\n";

		$tool_ok = array_values(array_filter($t['events'], fn($e) => $e['type'] === 'tool'))[0];
		check($tool_ok['truncated'] === false, 'complete result: not truncated');
		$state = ChatState::create('x');
		$state['messages'][] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1',
			'type' => 'function', 'function' => ['name' => 'problem_get', 'arguments' => '{"limit":1}']
		]]];
		$state['pending'][] = ['id' => 'c1', 'name' => 'problem_get', 'arguments' => ['limit' => 1],
			'arguments_raw' => '{"limit":1}', 'approved' => false
		];
		$r = (new Orchestrator($profile, new McpClient('http://127.0.0.1:18443/mcp', 'sid-ok', 5),
			LlmAdapter::create($profile), Prompts::BUILT_IN
		))->handle('step', $state);
		$ev = $r['response']['events'][0];
		check($ev['truncated'] === true && $ev['limit'] === 1 && $ev['truncated_by'] === 'count',
			'truncated result flagged in the trace (limit 1, by count)'
		);
		$sent = end($r['state']['messages'])['content'];
		check(strpos($sent, '"truncated":true') !== false, 'the model still receives the result as before');
		check(!array_key_exists('rate_limit', $r['response']) && !array_key_exists('next_tokens', $r['response']),
			'no token budget in step responses'
		);

		$view = file_get_contents(dirname(__DIR__).'/views/mcppilotchat.view.php');
		check(strpos($view, "_('LLM profile')") !== false && strpos($view, 'mcppilot-usage-chat') !== false
			&& strpos($view, 'budget') === false && strpos($view, 'meter') === false,
			'chat page: Usage numbers, no token budget, no bars'
		);
		check(!array_key_exists('tpm_limit', Config::profileDefaults()), 'no "Tokens per minute" setting');

		echo "\nTemperature\n";

		$no_temp = Config::normalizeProfile(['temperature' => ''] + $profile);
		LlmAdapter::create($no_temp)->send([['role' => 'user', 'content' => 'x']], []);
		$req = json_decode(file_get_contents(sys_get_temp_dir().'/mcppilot_last_llm_request.json'), true);
		check(!array_key_exists('temperature', $req), 'empty temperature is not sent');
		LlmAdapter::create($profile)->send([['role' => 'user', 'content' => 'x']], []);
		$req = json_decode(file_get_contents(sys_get_temp_dir().'/mcppilot_last_llm_request.json'), true);
		check(($req['temperature'] ?? null) === 0.2, 'set temperature is sent');

		echo "\nConfiguration\n";

		$merged = Config::mergeSecrets(['profiles' => [['id' => 'local-demo', 'api_key' => '']]],
			['profiles' => [['id' => 'local-demo', 'api_key' => 'secret']]]
		);
		check($merged['profiles'][0]['api_key'] === 'secret', 'empty API key field keeps the stored key');

		$cleared = Config::mergeSecrets(['profiles' => [['id' => 'local-demo', 'api_key' => '', 'clear_api_key' => 1]]],
			['profiles' => [['id' => 'local-demo', 'api_key' => 'secret']]]
		);
		check($cleared['profiles'][0]['api_key'] === '', '"Remove the stored key" clears it');

		$public = Config::publicProfile(Config::normalizeProfile(['id' => 'a', 'api_key' => 'secret']));
		check($public['api_key'] === '' && $public['has_api_key'] === true, 'API key never sent to the browser');

		$manifest = json_decode(file_get_contents(dirname(__DIR__).'/manifest.json'), true);
		$config = Config::normalize($manifest['config']);
		check(count($config['profiles']) === 1 && $config['mcp']['url'] !== '', 'manifest default config normalizes');
		$demo = $config['profiles'][0];
		check($demo['name'] === 'Local demo' && $demo['default_limit'] === 5 && $demo['timeout'] === 600
			&& $demo['require_tool_first'] === 1 && $demo['tool_allowlist'] === ['problem_get']
			&& $demo['trim_fields'] === ResultTrimmer::LEAN_FIELDS && count($demo['starter_prompts']) === 3,
			'fresh-install demo profile: Local demo, limit 5, timeout 600, lean fields, tool call required'
		);

		echo "\nProvider presets\n";
		$presets = \Modules\McpPilotChat\Lib\Presets::forBrowser();
		$ids = array_column($presets, 'id');
		check(count($ids) === 7 && count(array_unique($ids)) === 7, 'seven presets with unique IDs');
		$all_ok = true;

		foreach ($presets as $preset) {
			$fields = $preset['fields'];
			$profile = Config::normalizeProfile(['id' => $preset['id'], 'name' => $preset['label']] + $fields);

			foreach ($fields as $key => $value) {
				if ($profile[$key] != $value) {
					$all_ok = false;
					echo "     {$preset['id']}.$key changed by normalize\n";
				}
			}

			$all_ok = $all_ok && preg_match('~^https?://~', $fields['base_url'])
				&& !array_key_exists('api_key', $fields) && !array_key_exists('tool_allowlist', $fields)
				&& !array_key_exists('system_prompt', $fields) && is_bool($preset['tested']) && $preset['note'] !== '';
		}

		check($all_ok, 'every preset survives normalize unchanged, has an http(s) URL and no key, tools or prompt');
		$starters_ok = true;

		foreach ($presets as $preset) {
			$count = count($preset['starter_prompts']);
			$starters_ok = $starters_ok && $count >= 3 && $count <= 4
				&& count(array_unique($preset['starter_prompts'])) === $count;
		}

		check($starters_ok, 'every preset has 3 or 4 different starter questions');
		check(array_column($presets, 'tested', 'id') === ['local-small' => true, 'ollama' => false, 'openai' => false,
			'claude' => true, 'gemini' => true, 'groq' => true, 'openrouter' => false], 'tested / untested labels'
		);
	}
	finally {
		proc_terminate($mcp_proc);
		proc_terminate($llm_proc);
	}

	echo "\n".($failures === 0 ? "ALL $checks CHECKS PASSED" : "$failures OF $checks CHECKS FAILED")."\n";
	exit($failures === 0 ? 0 : 1);
}
