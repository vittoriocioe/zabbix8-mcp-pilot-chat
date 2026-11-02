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
	CWebUser,
	Throwable;

use Modules\McpPilotChat\Lib\Access,
	Modules\McpPilotChat\Lib\Adapters\LlmAdapter,
	Modules\McpPilotChat\Lib\ChatState,
	Modules\McpPilotChat\Lib\Config,
	Modules\McpPilotChat\Lib\JsonResponseTrait,
	Modules\McpPilotChat\Lib\McpClient,
	Modules\McpPilotChat\Lib\ModuleException,
	Modules\McpPilotChat\Lib\Orchestrator,
	Modules\McpPilotChat\Lib\Prompts;

/**
 * One step of the agent loop (one LLM call or one MCP tool call).
 *
 * POST JSON: {op: send|step|approve|deny, profile_id, message?, state?, sig?, _csrf_token}
 *
 * The field is "op", not "action": Zabbix merges URL parameters over JSON input, and "action" in the URL
 * is the route name (mcppilotchat.step).
 */
class ChatStep extends CController {

	use JsonResponseTrait;

	private const MAX_MESSAGE_LENGTH = 8000;

	private array $config = [];

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'op' => 'required|in send,step,approve,deny',
			'profile_id' => 'required|string',
			'message' => 'string',
			'state' => 'string',
			'sig' => 'string'
		]);

		if ($ret && $this->getInput('op') === 'send') {
			$message = trim($this->getInput('message', ''));
			$ret = $message !== '' && mb_strlen($message) <= self::MAX_MESSAGE_LENGTH;
		}

		if (!$ret) {
			$this->respondInvalidInput();
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		$this->config = Config::get();

		return Access::canUse($this->config);
	}

	protected function doAction(): void {
		try {
			$profile = Config::findProfile($this->config, $this->getInput('profile_id'));

			if ($profile === null || !Access::canUseProfile($profile)) {
				throw new ModuleException('The selected profile does not exist or is not available to you.', 'config');
			}

			if ($this->config['mcp']['url'] === '') {
				throw new ModuleException('The Zabbix MCP server URL is not configured.', 'config');
			}

			$state = $this->hasInput('state') && $this->getInput('state') !== ''
				? ChatState::open($this->getInput('state'), $this->getInput('sig', ''))
				: ChatState::create($profile['id']);

			$state['profile_id'] = $profile['id'];

			$mcp = new McpClient($this->config['mcp']['url'], CWebUser::$data['sessionid'],
				$this->config['mcp']['timeout']
			);

			$orchestrator = new Orchestrator($profile, $mcp, LlmAdapter::create($profile),
				Prompts::template($profile, $this->config)
			);
			$result = $orchestrator->handle($this->getInput('op'), $state, trim($this->getInput('message', '')));

			$this->respondJson(['ok' => true] + ChatState::seal($result['state']) + $result['response']);
		}
		catch (ModuleException $e) {
			$this->respondError($e->getMessage(), $e->getKind(), $e->getRetryAfter());
		}
		catch (Throwable $e) {
			$this->respondError('Unexpected error: '.$e->getMessage(), 'error');
		}
	}
}
