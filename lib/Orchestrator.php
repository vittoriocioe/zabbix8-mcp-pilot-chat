<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

namespace Modules\McpPilotChat\Lib;

use Modules\McpPilotChat\Lib\Adapters\LlmAdapter;

/**
 * Step-based agent loop: every call performs at most ONE LLM call or ONE MCP tool call.
 *
 * The browser keeps requesting the next step while the response says "next": "step".
 */
class Orchestrator {

	public const NEXT_STEP = 'step';
	public const NEXT_APPROVAL = 'approval';
	public const NEXT_DONE = 'done';

	private array $profile;
	private McpClient $mcp;
	private LlmAdapter $llm;

	/** Events of this step, shown in the chat and the debug block. */
	private array $events = [];

	/** @var array|null  Exposed tools, loaded once per request. */
	private ?array $tools = null;

	private ?int $prompt_tokens = null;

	/** System prompt template of the profile (see Prompts::template()). */
	private string $system_prompt;

	/** Longest message content copied into the debug trace of a model call. */
	private const TRACE_CONTENT_LIMIT = 2000;

	public function __construct(array $profile, McpClient $mcp, LlmAdapter $llm, string $system_prompt) {
		$this->profile = $profile;
		$this->mcp = $mcp;
		$this->llm = $llm;
		$this->system_prompt = $system_prompt;
	}

	/**
	 * @param string $action  send | step | approve | deny
	 */
	public function handle(string $action, array $state, string $message = ''): array {
		switch ($action) {
			case 'send':
				$this->cancelPending($state, 'Cancelled: the user sent a new message.');
				$state['messages'][] = ['role' => 'user', 'content' => $message];
				$state['iteration'] = 0;
				$next = $this->step($state);
				break;

			case 'approve':
				if ($state['pending']) {
					$state['pending'][0]['approved'] = true;
				}
				$next = $this->step($state);
				break;

			case 'deny':
				if ($state['pending']) {
					$call = array_shift($state['pending']);
					$this->addToolMessage($state, $call, 'The user denied this action. Do not retry it.');
					$this->events[] = [
						'type' => 'tool',
						'name' => $call['name'],
						'arguments' => $call['arguments'],
						'denied' => true,
						'error' => 'Denied by the user.'
					];
				}
				$next = self::NEXT_STEP;
				break;

			case 'step':
			default:
				$next = $this->step($state);
		}

		$response = [
			'next' => $next,
			'events' => $this->events,
			'iteration' => $state['iteration'],
			'prompt_tokens' => $this->prompt_tokens,
			'context_size' => $this->profile['context_size']
		];

		if ($next === self::NEXT_APPROVAL) {
			$response['approval'] = [
				'name' => $state['pending'][0]['name'],
				'arguments' => $state['pending'][0]['arguments']
			];
		}

		return ['state' => $state, 'response' => $response];
	}

	private function step(array &$state): string {
		if ($state['pending']) {
			return $this->runPendingTool($state);
		}

		$last = end($state['messages']);

		if ($last === false || !in_array($last['role'], ['user', 'tool'], true)) {
			return self::NEXT_DONE;
		}

		return $this->runLlm($state);
	}

	private function runLlm(array &$state): string {
		if ($state['iteration'] >= $this->profile['max_iterations']) {
			$text = sprintf('Stopped after %d model calls (the limit of this profile).', $state['iteration']);
			$state['messages'][] = ['role' => 'assistant', 'content' => $text];
			$this->events[] = ['type' => 'notice', 'level' => 'warning', 'text' => $text];

			return self::NEXT_DONE;
		}

		if (!$this->llm->supportsTools()) {
			throw new ModuleException('This profile cannot call tools, but MCP Pilot Chat always works through '.
				'the Zabbix MCP server. Configure tool calling for this profile.', 'config'
			);
		}

		$system = Prompts::fill($this->system_prompt, $this->profile);
		$messages = array_merge([['role' => 'system', 'content' => $system]], $state['messages']);
		$tools = $this->llmTools();

		// "Require a tool call on the first step": the model must look at Zabbix data before its first answer.
		// Only the first model call of a question, otherwise the model could never answer.
		$options = [];

		if ($this->profile['require_tool_first'] && $state['iteration'] == 0 && $tools) {
			$options['tool_choice'] = 'required';
		}

		$response = $this->llm->send($messages, $tools, $options);
		$state['iteration']++;
		$this->prompt_tokens = $response['usage']['prompt_tokens'];

		$this->events[] = [
			'type' => 'llm',
			'n' => $state['iteration'],
			'model' => $response['model'],
			'finish_reason' => $response['finish_reason'],
			'usage' => $response['usage'],
			'timings' => $response['timings'],
			'duration_ms' => $response['duration_ms'],
			'tool_calls' => array_map(static fn(array $c) => ['name' => $c['name'], 'arguments' => $c['arguments_raw']],
				$response['tool_calls']
			),
			'text' => $response['tool_calls'] ? $response['text'] : '',
			'reasoning' => $response['reasoning'] ?? null,
			'tools_offered' => array_map(static fn(array $t) => $t['function']['name'], $tools),
			'tool_choice' => $options['tool_choice'] ?? 'auto',
			'request' => self::traceMessages($messages)
		];

		if ($response['tool_calls']) {
			$state['messages'][] = [
				'role' => 'assistant',
				'content' => $response['text'],
				// The provider's own tool-call objects, unchanged (see LlmAdapter::normalizeToolCalls()).
				'tool_calls' => array_map(static fn(array $c) => $c['raw'] ?? [
					'id' => $c['id'],
					'type' => 'function',
					'function' => ['name' => $c['name'], 'arguments' => $c['arguments_raw']]
				], $response['tool_calls'])
			];

			foreach ($response['tool_calls'] as $call) {
				unset($call['raw']);
				$state['pending'][] = $call + ['approved' => false];
			}

			return self::NEXT_STEP;
		}

		$cut_off = $response['finish_reason'] === 'length';

		if ($cut_off) {
			$this->events[] = ['type' => 'notice', 'level' => 'warning', 'text' => sprintf(
				'The model reached "Max tokens per answer" (%d)%s. Raise it in the profile%s.',
				$this->profile['max_tokens'],
				$response['text'] === '' ? ' before it wrote any answer' : ' and the answer was cut off',
				$response['reasoning'] !== null ? ', or lower the reasoning effort (its thinking counts too)' : ''
			)];
		}

		$text = $response['text'] !== ''
			? $response['text']
			: ($cut_off
				? '(No answer: the model ran out of tokens. See the notice above.)'
				: '(The model returned an empty answer.)');
		$state['messages'][] = ['role' => 'assistant', 'content' => $text];
		$this->events[] = ['type' => 'answer', 'text' => $text];

		return self::NEXT_DONE;
	}

	private function runPendingTool(array &$state): string {
		$call = $state['pending'][0];
		$tool = $this->findTool($call['name']);

		$event = [
			'type' => 'tool',
			'name' => $call['name'],
			'arguments' => $call['arguments'] ?? $call['arguments_raw']
		];

		$error = null;

		if ($call['arguments'] === null) {
			$error = 'Invalid tool arguments: not a JSON object.';
		}
		elseif ($tool === null) {
			$error = sprintf('Tool "%s" is not exposed by the Zabbix MCP server.', $call['name']);
		}
		elseif (!ToolCatalog::isAllowed($tool['name'], $this->profile['tool_allowlist'])) {
			$error = sprintf('Tool "%s" is not enabled for this profile.', $call['name']);
		}
		elseif ($this->profile['max_limit'] > 0 && isset($call['arguments']['limit'])
				&& is_numeric($call['arguments']['limit'])
				&& (int) $call['arguments']['limit'] > $this->profile['max_limit']) {
			// Large results fill the context and the provider's token budget; the model has to ask for less.
			$error = sprintf('"limit" %d is above the maximum of %d allowed by this chat. Call the tool again with '.
				'"limit" %d or lower.', (int) $call['arguments']['limit'], $this->profile['max_limit'],
				$this->profile['max_limit']
			);
		}

		if ($error === null && ToolCatalog::typeOf($tool) === 'write' && $this->profile['approval_required']
				&& empty($call['approved'])) {
			return self::NEXT_APPROVAL;
		}

		array_shift($state['pending']);

		if ($error !== null) {
			$this->addToolMessage($state, $call, 'ERROR: '.$error);
			$this->events[] = $event + ['error' => $error];

			return self::NEXT_STEP;
		}

		$result = $this->mcp->callTool($tool['name'], $call['arguments']);
		$trimmed = $result['is_error']
			? ['text' => $result['text'], 'trimmed' => false]
			: ResultTrimmer::trim($tool['name'], $result['text'], $this->profile['trim_fields'],
				(bool) $this->profile['format_times']
			);

		$content = $result['is_error'] ? 'ERROR: '.$result['text'] : $trimmed['text'];
		$this->addToolMessage($state, $call, $content);

		$this->events[] = $event + [
			'duration_ms' => $result['duration_ms'],
			'is_error' => $result['is_error'],
			'error' => $result['is_error'] ? $result['text'] : null,
			'bytes_raw' => strlen($result['text']),
			'bytes_trimmed' => strlen($content),
			'raw' => $result['text'],
			'trimmed' => $trimmed['trimmed'] ? $trimmed['text'] : null
		] + self::truncation($result['text']);

		return self::NEXT_STEP;
	}

	/**
	 * "truncated" and "limit" of an MCP read-tool result, for the trace: such results cannot be paged.
	 * Display only; the model receives the result as before.
	 */
	private static function truncation(string $text): array {
		$data = json_decode($text, true);

		if (!is_array($data) || empty($data['truncated'])) {
			return ['truncated' => false];
		}

		return [
			'truncated' => true,
			'truncated_by' => isset($data['truncated_by']) ? (string) $data['truncated_by'] : null,
			'limit' => isset($data['limit']) ? (int) $data['limit'] : null,
			'count' => isset($data['count']) ? (int) $data['count'] : null
		];
	}

	/**
	 * Messages sent to the model, for the debug trace (long contents shortened).
	 */
	private static function traceMessages(array $messages): array {
		$trace = [];

		foreach ($messages as $message) {
			$content = (string) ($message['content'] ?? '');

			if (mb_strlen($content) > self::TRACE_CONTENT_LIMIT) {
				$content = mb_substr($content, 0, self::TRACE_CONTENT_LIMIT).
					sprintf('… [%d more characters]', mb_strlen($content) - self::TRACE_CONTENT_LIMIT);
			}

			$entry = ['role' => $message['role'], 'content' => $content];

			if (!empty($message['tool_calls'])) {
				$entry['tool_calls'] = array_map(static fn(array $c) => $c['function']['name'].' '.$c['function']['arguments'],
					$message['tool_calls']
				);
			}

			$trace[] = $entry;
		}

		return $trace;
	}

	private function cancelPending(array &$state, string $reason): void {
		while ($state['pending']) {
			$this->addToolMessage($state, array_shift($state['pending']), $reason);
		}
	}

	private function addToolMessage(array &$state, array $call, string $content): void {
		$state['messages'][] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => $content];
	}

	private function exposedTools(): array {
		if ($this->tools === null) {
			$this->tools = $this->mcp->listTools();
		}

		return $this->tools;
	}

	private function findTool(string $name): ?array {
		foreach ($this->exposedTools() as $tool) {
			if ($tool['name'] === $name) {
				return $tool;
			}
		}

		return null;
	}

	/**
	 * Exposed and allowed tools in OpenAI format, in server order (stable for prompt caching).
	 */
	private function llmTools(): array {
		$tools = [];

		foreach ($this->exposedTools() as $tool) {
			if (!ToolCatalog::isAllowed($tool['name'], $this->profile['tool_allowlist'])) {
				continue;
			}

			$schema = is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [];
			$schema += ['type' => 'object'];

			$tools[] = [
				'type' => 'function',
				'function' => [
					'name' => $tool['name'],
					'description' => (string) ($tool['description'] ?? ''),
					'parameters' => self::fixSchema($schema)
				]
			];
		}

		return $tools;
	}

	/**
	 * JSON objects decoded as PHP arrays lose their "object-ness" when empty; restore them.
	 */
	private static function fixSchema($schema) {
		if (!is_array($schema)) {
			return $schema;
		}

		foreach ($schema as $key => $value) {
			if (in_array($key, ['properties', 'patternProperties', '$defs', 'definitions'], true)) {
				if ($value === []) {
					$schema[$key] = new \stdClass();
					continue;
				}

				if (is_array($value)) {
					foreach ($value as $prop => $sub) {
						$schema[$key][$prop] = $sub === [] ? new \stdClass() : self::fixSchema($sub);
					}
					continue;
				}
			}

			if (is_array($value)) {
				$schema[$key] = self::fixSchema($value);
			}
		}

		if (($schema['type'] ?? null) === 'object' && !array_key_exists('properties', $schema)) {
			$schema['properties'] = new \stdClass();
		}

		return $schema;
	}
}
