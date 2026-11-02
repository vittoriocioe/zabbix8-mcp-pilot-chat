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
 * Provider presets for LLM profiles: known-good settings to start from.
 *
 * A preset never contains an API key. "tested" means it was used against a real Zabbix 8.0 lab during development;
 * the others follow the provider's documented OpenAI-compatible API and are untested.
 *
 * Fields (all optional except label): the profile fields to set. "trim" is "default" (the module's default kept
 * fields) or "lean" (the lean preset for small models). An empty "model" keeps the profile's model when applied.
 * "starter_prompts" and "tools" are only used when a new profile is added from the preset.
 */
class Presets {

	/** Fields a preset may set on a profile; everything else (ID, name, API key, tools, prompt, access) is kept. */
	public const FIELDS = ['adapter', 'base_url', 'model', 'temperature', 'max_tokens', 'context_size', 'max_iterations',
		'default_limit', 'max_limit', 'timeout', 'extra_params', 'require_tool_first'
	];

	public static function all(): array {
		return [
			[
				'id' => 'local-small',
				'label' => 'Local small model (llama.cpp)',
				'tested' => true,
				'note' => 'For a small model on a small machine, like the demo Qwen3 0.6B: few, short results and '
					.'a long timeout. The web server timeout must be at least as long as the profile timeout.',
				'base_url' => 'http://demo-llm:8080/v1',
				'model' => 'qwen3-0.6b',
				'temperature' => 0.2,
				'max_tokens' => 512,
				'context_size' => 4096,
				'max_iterations' => 6,
				'default_limit' => 5,
				'max_limit' => 10,
				'timeout' => 600,
				'extra_params' => '',
				'require_tool_first' => 1,
				'trim' => 'lean',
				'starter_prompts' => [
					'Show current High and Disaster problems',
					'Show the 5 latest problems',
					'Are there unacknowledged Disaster problems?'
				],
				// Only when added as a new profile: one tool is easiest for a small model.
				'tools' => ['problem_get']
			],
			[
				'id' => 'ollama',
				'label' => 'Ollama',
				'tested' => false,
				'note' => 'Replace the host with the machine running Ollama, and pick a model that supports tool '
					.'calling (e.g. a Qwen3 or Llama 3.1 model).',
				'base_url' => 'http://ollama:11434/v1',
				'model' => '',
				'temperature' => '',
				'max_tokens' => 1024,
				'context_size' => 8192,
				'max_iterations' => 8,
				'default_limit' => 10,
				'max_limit' => 25,
				'timeout' => 300,
				'extra_params' => '',
				'require_tool_first' => 0,
				'trim' => 'default',
				'starter_prompts' => [
					'Show current High and Disaster problems',
					'Show the 5 latest problems',
					'Show problems on hosts in the Linux servers group'
				]
			],
			[
				'id' => 'openai',
				'label' => 'OpenAI',
				'tested' => false,
				'note' => 'Enter the API key, then use Test connection to see the models available to it.',
				'base_url' => 'https://api.openai.com/v1',
				'model' => '',
				'temperature' => '',
				'max_tokens' => 2048,
				'context_size' => 128000,
				'max_iterations' => 8,
				'default_limit' => 10,
				'max_limit' => 50,
				'timeout' => 120,
				'extra_params' => '',
				'require_tool_first' => 0,
				'trim' => 'default',
				'starter_prompts' => [
					'Summarize the current problems by severity, worst first',
					'Which problems are not acknowledged yet?',
					'Show problems on hosts in the Linux servers group',
					'Is any maintenance active right now?'
				]
			],
			[
				'id' => 'claude',
				'label' => 'Claude API (Anthropic)',
				'tested' => true,
				'note' => 'Needs an API key from console.anthropic.com (separate from a Claude.ai subscription). '
					.'Temperature must stay empty. Test connection lists no models for this provider; the chat works.',
				'base_url' => 'https://api.anthropic.com/v1',
				'model' => 'claude-haiku-5-5',
				'temperature' => '',
				'max_tokens' => 2048,
				'context_size' => 100000,
				'max_iterations' => 8,
				'default_limit' => 10,
				'max_limit' => 50,
				'timeout' => 120,
				'extra_params' => '',
				'require_tool_first' => 0,
				'trim' => 'default',
				'starter_prompts' => [
					'Summarize the current problems by severity, worst first',
					'Which hosts have High or Disaster problems?',
					'Show problems on hosts in the Linux servers group',
					'Is any maintenance active right now?'
				]
			],
			[
				'id' => 'gemini',
				'label' => 'Google Gemini',
				'tested' => true,
				'note' => 'API key from Google AI Studio. Free-tier models are often busy (retried automatically), '
					.'and free-tier prompts may be used by Google.',
				'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
				'model' => 'gemini-flash-lite-latest',
				'temperature' => '',
				'max_tokens' => 2048,
				'context_size' => 1000000,
				'max_iterations' => 8,
				'default_limit' => 10,
				'max_limit' => 20,
				'timeout' => 120,
				'extra_params' => '',
				'require_tool_first' => 0,
				'trim' => 'default',
				'starter_prompts' => [
					'Summarize the current problems by severity, worst first',
					'Which problems are not acknowledged yet?',
					'Show problems on hosts in the Linux servers group',
					'Is any maintenance active right now?'
				]
			],
			[
				'id' => 'groq',
				'label' => 'Groq',
				'tested' => true,
				'note' => 'Free tier: 8,000 tokens per minute. Allow only the tools you need and leave about a minute '
					.'between questions; rate limits are retried automatically.',
				'base_url' => 'https://api.groq.com/openai/v1',
				'model' => 'openai/gpt-oss-120b',
				'temperature' => '',
				'max_tokens' => 1024,
				'context_size' => 131072,
				'max_iterations' => 6,
				'default_limit' => 10,
				'max_limit' => 20,
				'timeout' => 60,
				'extra_params' => '{"reasoning_effort": "low"}',
				'require_tool_first' => 0,
				'trim' => 'lean',
				'starter_prompts' => [
					'Show current High and Disaster problems',
					'Summarize the 10 latest problems by severity',
					'Show problems on hosts in the Linux servers group',
					'Is any maintenance active right now?'
				],
				// Only when added as a new profile: fewer tool definitions leave more of the per-minute budget.
				'tools' => ['problem_get', 'host_get', 'hostgroup_get', 'maintenance_get']
			],
			[
				'id' => 'openrouter',
				'label' => 'OpenRouter',
				'tested' => false,
				'note' => 'One key for many providers. Enter the model ID exactly as listed on openrouter.ai '
					.'(e.g. "provider/model"), and choose one that supports tool calling.',
				'base_url' => 'https://openrouter.ai/api/v1',
				'model' => '',
				'temperature' => '',
				'max_tokens' => 2048,
				'context_size' => 32768,
				'max_iterations' => 8,
				'default_limit' => 10,
				'max_limit' => 25,
				'timeout' => 120,
				'extra_params' => '',
				'require_tool_first' => 0,
				'trim' => 'default',
				'starter_prompts' => [
					'Summarize the current problems by severity, worst first',
					'Which problems are not acknowledged yet?',
					'Show problems on hosts in the Linux servers group',
					'Is any maintenance active right now?'
				]
			]
		];
	}

	/**
	 * The presets as the configuration popup uses them.
	 */
	public static function forBrowser(): array {
		return array_map(static fn(array $p) => [
			'id' => $p['id'],
			'label' => $p['label'],
			'tested' => $p['tested'],
			'note' => $p['note'],
			'fields' => self::profileFields($p),
			'starter_prompts' => $p['starter_prompts'] ?? [],
			'tools' => $p['tools'] ?? []
		], self::all());
	}

	/**
	 * The profile fields a preset sets, ready to merge into a profile (trim_fields resolved).
	 */
	public static function profileFields(array $preset): array {
		$fields = ['adapter' => 'openai'] + array_intersect_key($preset, array_flip(self::FIELDS));
		$fields['trim_fields'] = ($preset['trim'] ?? 'default') === 'lean' ? ResultTrimmer::LEAN_FIELDS : [];

		return $fields;
	}
}
