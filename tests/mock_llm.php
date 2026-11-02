<?php
/*
 * Mock OpenAI-compatible LLM for tests (php -S). Behaves like a small tool-calling model:
 * - user asks something      -> tool call problem_get {"limit": 5}
 * - user says "acknowledge"  -> tool call problem_acknowledge
 * - last message is a tool   -> final answer (with leaked "</think>" reasoning, like Qwen3)
 * - user says "loop"         -> always calls problem_get again (to test the iteration limit)
 * - user says "hello"        -> answers directly, unless tool_choice is "required" (like a small model)
 * - user says "ratelimit"    -> every other model call fails once with HTTP 429 "try again in 1.2s"
 * - user says "toolarge"     -> HTTP 413, request larger than the per-minute limit
 * - user says "cutoff"       -> finish_reason "length" with only reasoning and no answer
 * - user says "busy"         -> first model call fails once with HTTP 503 "high demand" (Gemini)
 * - user says "gemini"       -> tool call with a thought signature; HTTP 400 if it is not sent back
 * - user says "gemma"        -> answer with reasoning in <thought> tags
 * - user says "tiny"         -> problem_get with limit 1 (a truncated MCP result)
 *
 * Copyright (C) 2026 Vittorio Cioe
 * SPDX-License-Identifier: AGPL-3.0-only
 */

header('Content-Type: application/json');

// Optional artificial latency to make the UI timers visible in tests.
usleep((int) getenv('MOCK_DELAY_MS') * 1000);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/v1/models') {
	echo json_encode(['data' => [['id' => 'qwen3-0.6b']]]);
	exit;
}

if ($path !== '/v1/chat/completions') {
	http_response_code(404);
	echo json_encode(['error' => ['message' => 'not found']]);
	exit;
}

$request = json_decode(file_get_contents('php://input'), true);
$messages = $request['messages'];
$last = end($messages);

$first_user = '';

foreach ($messages as $m) {
	if ($m['role'] === 'user') {
		$first_user = $m['content'];
	}
}

// Record the last request for assertions.
file_put_contents(sys_get_temp_dir().'/mcppilot_last_llm_request.json', json_encode($request));

$tool_names = array_map(fn($t) => $t['function']['name'], $request['tools'] ?? []);

function toolCall(string $name, array $args): array {
	return ['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => '',
		'tool_calls' => [['id' => 'call_'.mt_rand(), 'type' => 'function', 'function' => ['name' => $name,
			'arguments' => json_encode($args)
		]]]
	]]], 'usage' => ['prompt_tokens' => 151, 'completion_tokens' => 21, 'prompt_tokens_details' => ['cached_tokens' => 148]],
		'model' => 'qwen3-0.6b', 'timings' => ['prompt_n' => 3, 'prompt_ms' => 469.8, 'predicted_n' => 21,
			'predicted_ms' => 6360.7
		]];
}

$required = ($request['tool_choice'] ?? 'auto') === 'required';

// Groq-style rate limit: every other model call is rejected once with "try again in 1.2s".
if (stripos($first_user, 'ratelimit') !== false) {
	$flag = sys_get_temp_dir().'/mcppilot_mock_ratelimit';

	if (!file_exists($flag)) {
		touch($flag);
		http_response_code(429);
		header('Retry-After: 2');
		echo json_encode(['error' => ['message' => 'Rate limit reached for model `openai/gpt-oss-20b` in organization '
			.'`org_test` service tier `on_demand` on tokens per minute (TPM): Limit 8000, Used 5103, Requested 3129. '
			.'Please try again in 1.2s. Need more tokens? Upgrade to Dev Tier today.', 'type' => 'tokens',
			'code' => 'rate_limit_exceeded'
		]]);
		exit;
	}

	unlink($flag);
}

// Overloaded provider (Gemini "high demand"): the first model call fails once with HTTP 503.
if (stripos($first_user, 'busy') !== false) {
	$flag = sys_get_temp_dir().'/mcppilot_mock_busy';

	if (!file_exists($flag)) {
		touch($flag);
		http_response_code(503);
		echo json_encode([['error' => ['code' => 503, 'message' => 'This model is currently experiencing high demand. '
			.'Spikes in demand are usually temporary. Please try again later.', 'status' => 'UNAVAILABLE'
		]]]);
		exit;
	}

	unlink($flag);
}

// Gemini 3 style: tool calls carry a thought signature that must come back in the next request.
// Gemma style: reasoning inside <thought> tags in the answer text.
if (stripos($first_user, 'gemini') !== false || stripos($first_user, 'gemma') !== false) {
	if ($last['role'] === 'user') {
		$call = toolCall('problem_get', ['limit' => 5]);
		$call['choices'][0]['message']['tool_calls'][0]['extra_content'] = ['google' => ['thought_signature' => 'SIG123']];
		// Any other provider-specific field must come back too (generic preservation).
		$call['choices'][0]['message']['tool_calls'][0]['provider_ref'] = 'ref-42';
		$call['choices'][0]['message']['tool_calls'][0]['index'] = 0;
		echo json_encode($call);
		exit;
	}

	$assistant = null;

	foreach ($messages as $m) {
		if ($m['role'] === 'assistant' && !empty($m['tool_calls'])) {
			$assistant = $m;
		}
	}

	if (stripos($first_user, 'gemini') !== false
			&& ($assistant['tool_calls'][0]['extra_content']['google']['thought_signature'] ?? null) !== 'SIG123') {
		http_response_code(400);
		echo json_encode([['error' => ['code' => 400, 'message' => 'Function call is missing a thought_signature in '
			.'functionCall parts.', 'status' => 'INVALID_ARGUMENT'
		]]]);
		exit;
	}

	echo json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant',
		'content' => "<thought>The user wants problems. Two are listed.</thought>There are **2** problems."
	]]], 'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 30], 'model' => 'gemma-4-26b-a4b-it']);
	exit;
}

// A single request larger than the per-minute limit: retrying cannot help.
if (stripos($first_user, 'toolarge') !== false) {
	http_response_code(413);
	echo json_encode(['error' => ['message' => 'Request too large for model `openai/gpt-oss-20b` in organization '
		.'`org_test` service tier `on_demand` on tokens per minute (TPM): Limit 8000, Requested 12000, please reduce '
		.'your message size and try again.', 'type' => 'tokens'
	]]);
	exit;
}

// Reasoning model that spends the whole token budget thinking (gpt-oss with a low max_tokens).
if (stripos($first_user, 'cutoff') !== false) {
	echo json_encode(['choices' => [['finish_reason' => 'length', 'message' => ['role' => 'assistant',
		'content' => '', 'reasoning' => 'The user asks about problems. I should consider severities and group them...'
	]]], 'usage' => ['prompt_tokens' => 2814, 'completion_tokens' => 512], 'model' => 'openai/gpt-oss-20b']);
	exit;
}

// Like a small model: greets back without looking at Zabbix, unless a tool call is required.
if ($last['role'] === 'user' && stripos($first_user, 'hello') !== false) {
	if (!$required) {
		echo json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant',
			'content' => 'Hello! No issues found.'
		]]], 'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 5], 'model' => 'qwen3-0.6b']);
		exit;
	}

	echo json_encode(toolCall($tool_names[0], in_array('problem_get', $tool_names, true) ? ['limit' => 5] : []));
	exit;
}

// Asks for a single result, so the MCP result is truncated.
if ($last['role'] === 'user' && stripos($first_user, 'tiny') !== false) {
	echo json_encode(toolCall('problem_get', ['limit' => 1]));
	exit;
}

if ($last['role'] === 'user' || stripos($first_user, 'loop') !== false) {
	if (stripos($first_user, 'acknowledge') !== false && $last['role'] === 'user') {
		echo json_encode(toolCall('problem_acknowledge', ['eventids' => ['57419025']]));
		exit;
	}

	echo json_encode(toolCall('problem_get', ['limit' => 5]));
	exit;
}

$tool_content = $last['content'];

echo json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant',
	'content' => "Let me think.\n</think>\n\nThere are **2** problems:\n- Network connection is down (High), event 57419025\n"
		.'- High CPU utilization (Warning). Tool said: '.substr($tool_content, 0, 40)
]]], 'usage' => ['prompt_tokens' => 455, 'completion_tokens' => 51], 'model' => 'qwen3-0.6b']);
