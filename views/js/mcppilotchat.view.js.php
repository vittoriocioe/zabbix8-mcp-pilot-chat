<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

/**
 * Chat page logic: drives the step-by-step agent loop, renders messages, live timers and the side panel.
 *
 * @var CView $this
 */
?>
<script>
window.mcppilot_chat = new class {

	/** Automatic retries after a rate limit, and the longest provider wait time that is retried. */
	static MAX_RETRIES = 3;
	static MAX_RETRY_WAIT = 30;

	/** Name of the pop-out window, so the main window can find it again. */
	static POPUP_NAME = 'mcppilot_chat_window';

	/** Size of a new pop-out window when none was remembered, and the smallest size accepted. */
	static POPUP_SIZE = {width: 760, height: 900};
	static POPUP_MIN_SIZE = {width: 420, height: 480};

	init({profiles, default_profile, debug, username, csrf, version, popout = false}) {
		this.profiles = profiles;
		this.csrf = csrf;
		this.version = version;
		this.username = username;

		// popout: this window is the pop-out. popped: this is the main window and the chat is in the pop-out.
		this.popout = popout;
		this.popped = false;
		this.popup = null;

		this.root = document.getElementById('mcppilot');
		this.messages_el = document.getElementById('mcppilot-messages');
		this.starters_el = document.getElementById('mcppilot-starters');
		this.input = document.getElementById('mcppilot-input');
		this.send_btn = document.getElementById('mcppilot-send');
		this.stop_btn = document.getElementById('mcppilot-stop');
		this.profile_select = document.getElementById('mcppilot-profile');
		this.debug_box = document.getElementById('mcppilot-debug');

		this.profile_id = default_profile;
		this.state = '';
		this.sig = '';
		this.busy = false;
		this.abort = null;
		this.turns = [];
		this.current_turn = null;

		// IDs seen in tool results, used to turn IDs and host names in answers into links.
		this.known = {events: new Map(), hosts: new Map(), host_names: new Map(), triggers: new Set()};

		// Tokens sent and received, summed from the model calls' own reports (display only).
		this.usage = null;

		if (this.popout) {
			this.#initPopout();
		}

		this.#bindEvents(debug);
		this.#restoreChat();
		this.#renderEmpty();
		this.#renderStarters();

		if (!this.popout && this.#poppedFlag() === 'open') {
			this.#enterPopped(null);
		}
		else if (!this.popout) {
			// A pop-out that was closed while this page was not open: the chat is back here.
			this.#setPoppedFlag(null);
		}

		this.#updateControls();
		this.refreshStatus();
	}

	#bindEvents(debug) {
		this.send_btn.addEventListener('click', () => this.send(this.input.value));
		this.stop_btn.addEventListener('click', () => this.stop());

		this.input.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
				e.preventDefault();
				this.send(this.input.value);
			}
		});

		if (this.profile_select !== null) {
			this.profile_select.addEventListener('change', () => {
				const next = this.profile_select.value;

				if (this.reverting_profile || next === this.profile_id) {
					return;
				}

				// A new profile always starts a new chat: the conversation would otherwise be sent to another
				// model (e.g. a local-only chat to a cloud provider) and could exceed its limits.
				if (this.turns.length > 0
						&& !window.confirm('Switching the profile starts a new chat. The current conversation will '
							+ 'be cleared. Continue?')) {
					this.reverting_profile = true;
					this.profile_select.value = this.profile_id;
					this.reverting_profile = false;

					return;
				}

				this.profile_id = next;

				if (this.turns.length > 0) {
					this.newChat();
				}

				this.#renderStarters();
				this.refreshStatus();
				this.#saveChat();
			});
		}

		this.debug_box.addEventListener('change', () => {
			this.root.classList.toggle('mcppilot-debug-on', this.debug_box.checked);
		});
		this.root.classList.toggle('mcppilot-debug-on', debug);

		document.getElementById('mcppilot-new').addEventListener('click', () => this.newChat());
		document.getElementById('mcppilot-export').addEventListener('click', () => this.exportJson());

		document.getElementById('mcppilot-popout')?.addEventListener('click', () => this.popOut());
		document.getElementById('mcppilot-popin')?.addEventListener('click', () => this.popIn());
		document.getElementById('mcppilot-popped-show')?.addEventListener('click', () => this.showPopup());
		document.getElementById('mcppilot-popped-popin')?.addEventListener('click', () => this.popIn());

		// In the pop-out, Zabbix links in answers open in the main Zabbix window.
		if (this.popout) {
			this.messages_el.addEventListener('click', (e) => this.#openInMain(e));
			this.#initPopoutLayout();
		}
		document.getElementById('mcppilot-refresh').addEventListener('click', () => this.refreshStatus());

		const configure = document.getElementById('mcppilot-configure');

		if (configure !== null) {
			configure.addEventListener('click', () => {
				const overlay = PopUp('mcppilotchat.config.edit', {}, {
					dialogueid: 'mcppilotchat-config',
					dialogue_class: 'modal-popup-large'
				});

				overlay.$dialogue[0].addEventListener('dialogue.submit', () => location.reload(), {once: true});
			});
		}
	}

	// ---------------------------------------------------------------------------------------------------------
	// Conversation loop
	// ---------------------------------------------------------------------------------------------------------

	async send(text) {
		text = text.trim();

		if (text === '' || this.busy || this.popped || this.profile_id === '') {
			return;
		}

		this.input.value = '';
		this.#clearEmpty();
		this.#addUserMessage(text);

		const turn = this.#startTurn(text);

		try {
			let response = await this.#step({op: 'send', message: text}, turn);

			while (response.next === 'step' || response.next === 'approval') {
				if (turn.stopped) {
					break;
				}

				if (response.next === 'approval') {
					const approved = await this.#askApproval(turn, response.approval);

					if (turn.stopped) {
						break;
					}

					response = await this.#step({op: approved ? 'approve' : 'deny'}, turn);
				}
				else {
					response = await this.#step({op: 'step'}, turn);
				}
			}

			this.#finishTurn(turn, turn.stopped ? 'stopped' : 'done');
		}
		catch (error) {
			if (turn.stopped || error.name === 'AbortError') {
				this.#finishTurn(turn, 'stopped');
			}
			else {
				this.#finishTurn(turn, 'failed', error);
			}
		}
	}

	stop() {
		if (!this.busy || this.current_turn === null) {
			return;
		}

		this.current_turn.stopped = true;

		if (this.abort !== null) {
			this.abort.abort();
		}

		if (this.current_turn.approval_resolve) {
			this.current_turn.approval_resolve(false);
		}

		if (this.current_turn.wait_resolve) {
			this.current_turn.wait_resolve();
		}
	}

	newChat() {
		if (this.busy) {
			this.stop();
		}

		this.state = '';
		this.sig = '';
		this.turns = [];
		this.known = {events: new Map(), hosts: new Map(), host_names: new Map(), triggers: new Set()};
		this.messages_el.innerHTML = '';
		this.last_context = null;
		this.#clearSaved();
		this.#renderEmpty();
		this.#setContext(null, null);
		this.#setUsage(null);
		this.input.focus();
	}

	/**
	 * One loop step. When the LLM provider reports a short rate limit ("try again in 1.7 s"), waits with a
	 * visible countdown and sends the same step again. The failed request changed nothing on the server
	 * (the conversation state is only updated on success), so resending is safe.
	 */
	async #step(payload, turn) {
		for (let attempt = 0; ; attempt++) {
			try {
				return await this.#stepOnce(payload, turn);
			}
			catch (error) {
				const wait = error.retry_after;

				if (error.kind !== 'rate_limit' || typeof wait !== 'number' || wait > this.constructor.MAX_RETRY_WAIT
						|| attempt >= this.constructor.MAX_RETRIES || turn.stopped) {
					throw error;
				}

				// A little extra, because providers round their wait times down.
				const wait_ms = Math.ceil((wait + 0.5) * 1000);

				const reason = /overloaded/i.test(error.message) ? 'Model busy at ' : 'Rate limited by ';

				turn.wait_reason = reason === 'Model busy at ' ? 'Model busy' : 'Rate limited';
				this.#addStep(turn, '⏳', reason + this.#profileName(turn.profile_id)
					+ ', retry ' + (attempt + 1) + ' of ' + this.constructor.MAX_RETRIES + ' after '
					+ this.#seconds(wait_ms), null, [['Provider message', error.message]]);

				await this.#countdown(turn, wait_ms,
					(left) => turn.wait_reason + ', retrying in ' + Math.ceil(left / 1000) + ' s…'
				);

				if (turn.stopped) {
					throw Object.assign(new Error('Stopped.'), {name: 'AbortError'});
				}
			}
		}
	}

	#countdown(turn, ms, label) {
		return new Promise((resolve) => {
			const end = performance.now() + ms;

			const update = () => {
				const left = end - performance.now();

				if (left <= 0 || turn.stopped) {
					clearInterval(timer);
					turn.wait_resolve = null;
					this.#setLive(turn, 'Thinking…');
					resolve();

					return;
				}

				this.#setLive(turn, label(left));
			};

			const timer = setInterval(update, 200);
			turn.wait_resolve = () => {
				clearInterval(timer);
				resolve();
			};
			update();
		});
	}

	async #stepOnce(payload, turn) {
		const step_started = performance.now();

		turn.step_started = step_started;
		this.abort = new AbortController();

		const url = new Curl('zabbix.php');
		url.setArgument('action', 'mcppilotchat.step');

		let response;

		try {
			const http = await fetch(url.getUrl(), {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify({
					...payload,
					profile_id: this.profile_id,
					state: this.state,
					sig: this.sig,
					_csrf_token: this.csrf.step
				}),
				signal: this.abort.signal
			});

			const body = await http.text();

			try {
				response = JSON.parse(body);
			}
			catch (e) {
				throw new Error('The server did not return JSON (HTTP ' + http.status + '). '
					+ 'Your Zabbix session may have expired. Reload the page and log in again.');
			}
		}
		finally {
			this.abort = null;
		}

		if (!response.ok) {
			const error = new Error(response.error?.message || 'Unknown error.');
			error.kind = response.error?.kind || 'error';
			error.retry_after = response.error?.retry_after;
			throw error;
		}

		this.state = response.state;
		this.sig = response.sig;

		for (const event of response.events) {
			this.#renderEvent(turn, event);

			if (event.type === 'llm') {
				this.#addUsage(event.usage);
			}
		}

		this.#setContext(response.prompt_tokens, response.context_size);

		if (response.next === 'step') {
			this.#setLive(turn, turn.queue.length ? 'Calling ' + turn.queue[0] + '…' : 'Thinking…');
		}
		else if (response.next === 'approval') {
			this.#setLive(turn, 'Waiting for your approval…');
		}

		return response;
	}

	#askApproval(turn, approval) {
		return new Promise((resolve) => {
			const card = document.createElement('div');
			card.className = 'mcppilot-approval';

			const title = document.createElement('div');
			title.innerHTML = '<b>Approval required.</b> The model wants to run a tool that changes Zabbix:';

			const pre = document.createElement('pre');
			pre.textContent = approval.name + ' ' + JSON.stringify(approval.arguments ?? {}, null, 2);

			const buttons = document.createElement('div');
			buttons.className = 'mcppilot-approval-buttons';

			const approve = document.createElement('button');
			approve.type = 'button';
			approve.textContent = 'Approve';

			const deny = document.createElement('button');
			deny.type = 'button';
			deny.className = 'btn-alt';
			deny.textContent = 'Deny';

			buttons.append(approve, deny);
			card.append(title, pre, buttons);
			turn.body.insertBefore(card, turn.live);

			const done = (value) => {
				turn.approval_resolve = null;
				approve.disabled = true;
				deny.disabled = true;
				card.append(Object.assign(document.createElement('div'), {
					className: 'mcppilot-step',
					textContent: value ? '✓ Approved' : '✕ Denied'
				}));
				this.#setLive(turn, value ? 'Running ' + approval.name + '…' : 'Thinking…');
				resolve(value);
			};

			turn.approval_resolve = done;
			approve.addEventListener('click', () => done(true));
			deny.addEventListener('click', () => done(false));
			this.#scroll();
		});
	}

	// ---------------------------------------------------------------------------------------------------------
	// Turn rendering: thinking animation, live timers, steps and final summary
	// ---------------------------------------------------------------------------------------------------------

	/**
	 * Answer bubble and turn object, without timers. Used for new questions and for restored ones.
	 */
	#createTurn(question, profile_id, label = null) {
		const el = document.createElement('div');
		el.className = 'mcppilot-msg mcppilot-msg-assistant';

		const meta = document.createElement('div');
		meta.className = 'mcppilot-msg-meta';
		label = label ?? this.#profileLabel(profile_id);
		meta.textContent = label;

		const steps = document.createElement('div');
		steps.className = 'mcppilot-steps';

		const live = document.createElement('div');
		live.className = 'mcppilot-live';
		live.innerHTML = '<span class="mcppilot-dots" aria-hidden="true"><span></span><span></span><span></span></span>'
			+ '<span class="mcppilot-live-label"></span>'
			+ '<span class="mcppilot-timer"><span class="mcppilot-timer-step"></span> '
			+ '<span class="mcppilot-timer-total"></span></span>';

		const answer = document.createElement('div');
		answer.className = 'mcppilot-answer';

		el.append(meta, steps, live, answer);
		this.messages_el.append(el);

		return {
			question,
			profile_id,
			// "Profile - model" shown on the answer (label is the live status element).
			profile_label: label,
			el,
			body: el,
			steps,
			live,
			answer,
			label: live.querySelector('.mcppilot-live-label'),
			timer_total: live.querySelector('.mcppilot-timer-total'),
			timer_step: live.querySelector('.mcppilot-timer-step'),
			started: performance.now(),
			step_started: performance.now(),
			queue: [],
			events: [],
			llm_calls: 0,
			tool_calls: 0,
			stopped: false,
			approval_resolve: null,
			wait_resolve: null,
			ticker: null
		};
	}

	#startTurn(question) {
		const turn = this.#createTurn(question, this.profile_id);

		this.usage = this.usage ?? {question: {in: 0, out: 0}, chat: {in: 0, out: 0}, unknown: false};
		this.usage.question = {in: 0, out: 0};
		this.#setUsage(this.usage);

		this.#setLive(turn, 'Thinking…');
		this.#tick(turn);
		turn.ticker = setInterval(() => this.#tick(turn), 100);

		this.turns.push(turn);
		this.current_turn = turn;
		this.busy = true;
		this.#updateControls();
		this.#scroll();

		return turn;
	}

	#tick(turn) {
		const now = performance.now();

		turn.timer_total.textContent = this.#seconds(now - turn.started);
		turn.timer_step.textContent = turn.steps.childElementCount > 0
			? '(step ' + this.#seconds(now - turn.step_started) + ')'
			: '';
	}

	#setLive(turn, text) {
		turn.label.textContent = text;
	}

	#renderEvent(turn, event) {
		turn.events.push(event);

		switch (event.type) {
			case 'llm': {
				turn.llm_calls++;

				const calls = event.tool_calls.map((c) => c.name);
				turn.queue.push(...calls);

				const summary = 'Model call #' + event.n
					+ (calls.length ? ' → requested ' + calls.join(', ') : ' → answer')
					+ (event.tool_choice === 'required' ? ' (tool call required)' : '')
					+ ' · ' + this.#llmCost(event);

				const details = {
					model: event.model,
					finish_reason: event.finish_reason,
					usage: event.usage,
					timings: event.timings
				};

				if (event.text) {
					details.text = event.text;
				}

				const offered = event.tools_offered ?? [];

				const sections = [];

				if (event.reasoning) {
					sections.push(['Model reasoning (' + event.reasoning.length + ' characters)', event.reasoning]);
				}

				this.#addStep(turn, '🧠', summary + (event.reasoning ? ' · 💭 reasoning' : ''), event.duration_ms, [
					...sections,
					['Tools offered to the model', offered.length ? offered.join(', ') : '(none)'],
					['Messages sent (' + (event.request ?? []).length + ')', this.#formatRequest(event.request ?? [])],
					['Model statistics', details]
				]);
				break;
			}

			case 'tool': {
				turn.tool_calls++;

				const index = turn.queue.indexOf(event.name);

				if (index !== -1) {
					turn.queue.splice(index, 1);
				}

				const args = JSON.stringify(event.arguments ?? {});
				let summary = event.name + ' ' + args;
				const sections = [];

				if (event.denied) {
					summary += ' · denied by you';
				}
				else if (event.error && !event.raw) {
					summary += ' · ' + event.error;
				}
				else {
					summary += ' · ' + this.#bytes(event.bytes_raw)
						+ (event.trimmed !== null && event.trimmed !== undefined
							? ' → ' + this.#bytes(event.bytes_trimmed) + ' after trimming'
							: '');

					if (event.is_error) {
						summary += ' · error';
					}

					sections.push(['Result sent to the model', event.trimmed ?? event.raw]);

					if (event.trimmed !== null && event.trimmed !== undefined) {
						sections.push(['Raw MCP result', event.raw]);
					}

					this.#learnIds(event.name, event.raw);
				}

				const step = this.#addStep(turn, event.error || event.is_error ? '⚠️' : '🔧', summary, event.duration_ms,
					sections, event.error || event.is_error
				);

				// MCP results cannot be paged: make truncation visible, it explains the model's next query.
				if (event.truncated && step.querySelector('summary') !== null) {
					const badge = document.createElement('span');
					badge.className = 'mcppilot-truncated';
					badge.textContent = 'truncated' + (event.limit ? ' at ' + event.limit : '') + ', cannot be paged';
					badge.title = 'The MCP server returned only part of the results'
						+ (event.truncated_by ? ' (limit reached: ' + event.truncated_by + ')' : '') + '.';
					step.querySelector('summary').insertBefore(badge, step.querySelector('.mcppilot-step-time'));
				}
				break;
			}

			case 'notice':
				this.#addStep(turn, event.level === 'warning' ? '⚠️' : 'ℹ️', event.text, null, [],
					event.level === 'warning'
				);

				// Warnings matter even with debug off: show them under the answer too.
				if (event.level === 'warning') {
					const box = document.createElement('div');
					box.className = 'mcppilot-warning';
					box.textContent = event.text;
					turn.answer.before(box);
				}
				break;

			case 'answer':
				this.#renderAnswer(turn.answer, event.text);
				break;
		}

		this.#scroll();
	}

	#addStep(turn, icon, summary, duration_ms, sections, is_error = false) {
		const step = document.createElement(sections.length ? 'details' : 'div');
		step.className = 'mcppilot-step';

		const head = document.createElement(sections.length ? 'summary' : 'span');
		head.textContent = icon + ' ' + summary;

		if (is_error) {
			head.classList.add('color-negative');
		}

		if (duration_ms !== null && duration_ms !== undefined) {
			const time = document.createElement('span');
			time.className = 'mcppilot-step-time';
			time.textContent = this.#seconds(duration_ms);
			head.append(time);
		}

		step.append(head);

		for (const [title, content] of sections) {
			const label = document.createElement('div');
			label.textContent = title + ':';

			const pre = document.createElement('pre');
			pre.textContent = typeof content === 'string' ? this.#pretty(content) : JSON.stringify(content, null, 2);

			step.append(label, pre);
		}

		turn.steps.append(step);
		turn.step_started = performance.now();
		this.#tick(turn);

		return step;
	}

	/**
	 * "read 754 tokens in 80.0 s · wrote 34 in 36.4 s" from llama.cpp timings, else token counts only.
	 */
	#llmCost(event) {
		const t = event.timings;
		const usage = event.usage || {};

		if (t && t.prompt_n !== undefined && t.prompt_ms !== undefined && t.predicted_n !== undefined
				&& t.predicted_ms !== undefined) {
			const cached = usage.cached_tokens ? ' (+' + usage.cached_tokens + ' cached)' : '';

			return 'read ' + t.prompt_n + ' tokens' + cached + ' in ' + this.#seconds(t.prompt_ms)
				+ ' · wrote ' + t.predicted_n + ' in ' + this.#seconds(t.predicted_ms);
		}

		if (usage.prompt_tokens != null) {
			return usage.prompt_tokens + ' → ' + usage.completion_tokens + ' tokens'
				+ (usage.cached_tokens ? ' (' + usage.cached_tokens + ' cached)' : '');
		}

		return '';
	}

	#formatRequest(messages) {
		return messages.map((m) => {
			let text = '── ' + m.role + ' ──\n' + (m.content || '');

			if (m.tool_calls) {
				text += (m.content ? '\n' : '') + m.tool_calls.map((c) => '→ ' + c).join('\n');
			}

			return text;
		}).join('\n\n');
	}

	#finishTurn(turn, status, error = null) {
		clearInterval(turn.ticker);
		turn.duration_ms = performance.now() - turn.started;
		turn.status = status;

		if (status === 'failed') {
			turn.error = {message: error?.message || String(error), kind: error?.kind || 'error'};

			if (error?.kind === 'state') {
				this.state = '';
				this.sig = '';
			}
		}

		this.#renderSummary(turn);

		this.busy = false;
		this.current_turn = null;
		this.#updateControls();
		this.#scroll();
		this.input.focus();
		this.#saveChat();
	}

	/**
	 * Replaces the live status with the summary line: completed / stopped / failed, with the elapsed time.
	 */
	#renderSummary(turn) {
		turn.live.hidden = true;

		const done = document.createElement('div');
		done.className = 'mcppilot-done';

		const counts = turn.llm_calls + ' model call' + (turn.llm_calls === 1 ? '' : 's') + ' · '
			+ turn.tool_calls + ' tool call' + (turn.tool_calls === 1 ? '' : 's');

		if (turn.status === 'done') {
			done.textContent = '✓ Completed in ' + this.#seconds(turn.duration_ms) + ' · ' + counts;
		}
		else if (turn.status === 'stopped') {
			done.classList.add('mcppilot-stopped', 'color-warning');
			done.textContent = '■ Stopped after ' + this.#seconds(turn.duration_ms) + ' · ' + counts;
		}
		else {
			done.classList.add('mcppilot-failed', 'color-negative');
			done.textContent = '✕ Failed after ' + this.#seconds(turn.duration_ms) + ' · ' + counts;

			const box = document.createElement('div');
			box.className = 'mcppilot-error';
			box.textContent = turn.error?.message ?? '';
			turn.answer.append(box);
		}

		turn.el.append(done);
	}

	// ---------------------------------------------------------------------------------------------------------
	// The current chat survives page reloads: kept in sessionStorage (this browser tab only, cleared when the
	// tab is closed). "New chat" clears it.
	// ---------------------------------------------------------------------------------------------------------

	#storageKey() {
		return 'mcppilot.chat.' + this.username;
	}

	#saveChat() {
		const data = {
			v: 1,
			version: this.version,
			profile_id: this.profile_id,
			state: this.state,
			sig: this.sig,
			context: this.last_context ?? null,
			usage: this.usage,
			turns: this.turns.filter((t) => t.status).map((t) => ({
				question: t.question,
				profile_id: t.profile_id,
				label: t.profile_label,
				status: t.status,
				duration_ms: Math.round(t.duration_ms),
				llm_calls: t.llm_calls,
				tool_calls: t.tool_calls,
				error: t.error ?? null,
				events: t.events
			}))
		};

		try {
			sessionStorage.setItem(this.#storageKey(), JSON.stringify(data));
		}
		catch (e) {
			// Too big for the browser's quota: keep the conversation, drop the bulky debug payloads.
			try {
				for (const turn of data.turns) {
					turn.events = turn.events.map(({raw, trimmed, request, ...rest}) => rest);
				}

				sessionStorage.setItem(this.#storageKey(), JSON.stringify(data));
			}
			catch (e2) {
				this.#clearSaved();

				return;
			}
		}

		// The pop-out also keeps the conversation in the main window, so nothing is lost when it is closed.
		const opener = this.#opener();

		if (opener !== null) {
			try {
				opener.sessionStorage.setItem(this.#storageKey(), sessionStorage.getItem(this.#storageKey()));
			}
			catch (e) {
				// Main window storage full or unavailable: the pop-out still has the conversation.
			}
		}
	}

	#clearSaved() {
		try {
			sessionStorage.removeItem(this.#storageKey());
		}
		catch (e) {
			// Storage unavailable (e.g. disabled by the browser): nothing to clear.
		}

		const opener = this.#opener();

		if (opener !== null) {
			try {
				opener.sessionStorage.removeItem(this.#storageKey());
			}
			catch (e) {
				// Main window storage unavailable.
			}
		}
	}

	// ---------------------------------------------------------------------------------------------------------
	// Pop out: the chat in its own window, without the Zabbix menu. The main window keeps a copy of the
	// conversation (saved by the pop-out after every change) and a flag saying the chat is popped out.
	// ---------------------------------------------------------------------------------------------------------

	#poppedKey() {
		return 'mcppilot.popped.' + this.username;
	}

	#poppedFlag() {
		try {
			return sessionStorage.getItem(this.#poppedKey());
		}
		catch (e) {
			return null;
		}
	}

	#setPoppedFlag(value, storage = sessionStorage) {
		try {
			if (value === null) {
				storage.removeItem(this.#poppedKey());
			}
			else {
				storage.setItem(this.#poppedKey(), value);
			}
		}
		catch (e) {
			// Storage unavailable.
		}
	}

	/**
	 * The main Zabbix window of this pop-out, if it is still open (on any Zabbix page). Null otherwise.
	 */
	#opener() {
		if (!this.popout) {
			return null;
		}

		try {
			const opener = window.opener;

			// Reading its storage fails if it went to another site.
			if (opener && !opener.closed && opener.sessionStorage) {
				return opener;
			}
		}
		catch (e) {
			// Not the same origin any more.
		}

		return null;
	}

	#chatUrl(popout) {
		const url = new URL('zabbix.php', location.href);
		url.searchParams.set('action', 'mcppilotchat.view');

		if (popout) {
			url.searchParams.set('popout', '1');
		}

		return url.href;
	}

	#initPopout() {
		this.#setPoppedFlag(null);

		const opener = this.#opener();

		if (opener !== null) {
			// Take the conversation from the main window: it has the latest copy.
			try {
				const saved = opener.sessionStorage.getItem(this.#storageKey());

				if (saved !== null) {
					sessionStorage.setItem(this.#storageKey(), saved);
				}
			}
			catch (e) {
				// Keep whatever this window has.
			}

			this.#setPoppedFlag('open', opener.sessionStorage);
		}

		// Let the main chat page know about this window (also after the main page was reloaded), so it notices
		// when this window is closed.
		const register = () => {
			try {
				this.#opener()?.mcppilot_chat?.registerPopup?.(window);
			}
			catch (e) {
				// The main window shows another page.
			}
		};

		register();
		setInterval(register, 2000);

		window.addEventListener('pagehide', () => {
			const opener = this.#opener();

			if (opener !== null && !this.popping_in) {
				this.#setPoppedFlag('closed', opener.sessionStorage);
			}
		});
	}

	/**
	 * Pop-out window: the chat fills the window at any size, "Details" shows the side panel in narrow windows,
	 * and the window size is remembered for the next pop-out.
	 */
	#initPopoutLayout() {
		const toggle = document.getElementById('mcppilot-bar-toggle');

		toggle?.addEventListener('click', () => {
			const open = this.root.classList.toggle('mcppilot-details-open');
			toggle.setAttribute('aria-expanded', String(open));
			toggle.textContent = open ? 'Hide details' : 'Details';
		});

		let timer = null;

		const fit = () => {
			// The header can wrap to more lines in a narrow window, so measure where the chat starts.
			const top = this.root.getBoundingClientRect().top;
			const height = Math.max(320, Math.floor(window.innerHeight - top - 12));

			this.root.style.height = height + 'px';
		};

		// A chat scrolled to the end stays at the end when its area changes size (window resized, Details shown).
		this.stick_to_end = true;
		this.messages_el.addEventListener('scroll', () => {
			const el = this.messages_el;
			this.stick_to_end = el.scrollHeight - el.scrollTop - el.clientHeight < 8;
		}, {passive: true});

		if (window.ResizeObserver) {
			new ResizeObserver(() => {
				if (this.stick_to_end) {
					this.messages_el.scrollTop = this.messages_el.scrollHeight;
				}
			}).observe(this.messages_el);
		}

		window.addEventListener('resize', () => {
			fit();
			clearTimeout(timer);
			timer = setTimeout(() => this.#rememberPopupSize(), 400);
		});

		fit();
		// Again once fonts and the header controls have their final size.
		window.addEventListener('load', fit, {once: true});
		requestAnimationFrame(fit);
	}

	#rememberPopupSize() {
		try {
			localStorage.setItem('mcppilot.popout.size', JSON.stringify({
				width: window.innerWidth,
				height: window.innerHeight
			}));
		}
		catch (e) {
			// Storage unavailable: the next pop-out uses the default size.
		}
	}

	/**
	 * Size for a new pop-out: the last size the user gave it, within the screen, or the default.
	 */
	#popupSize() {
		const {POPUP_SIZE: def, POPUP_MIN_SIZE: min} = this.constructor;
		let size = null;

		try {
			size = JSON.parse(localStorage.getItem('mcppilot.popout.size') ?? 'null');
		}
		catch (e) {
			size = null;
		}

		const clamp = (value, low, high) => Math.round(Math.min(Math.max(value, low), Math.max(low, high)));
		const width = Number.isFinite(size?.width) ? size.width : def.width;
		const height = Number.isFinite(size?.height) ? size.height : def.height;

		return {
			width: clamp(width, min.width, screen.availWidth || def.width),
			height: clamp(height, min.height, screen.availHeight || def.height)
		};
	}

	/**
	 * Called by the pop-out window on the main chat page.
	 */
	registerPopup(popup) {
		if (this.popped && !this.popout) {
			this.popup = popup;
		}
	}

	popOut() {
		if (this.popout || this.popped || this.busy) {
			return;
		}

		this.#saveChat();
		this.#setPoppedFlag('open');

		const size = this.#popupSize();
		const popup = window.open(this.#chatUrl(true), this.constructor.POPUP_NAME,
			'popup,width=' + size.width + ',height=' + size.height
		);

		if (!popup) {
			this.#setPoppedFlag(null);
			window.alert('The browser blocked the pop-out window. Allow pop-ups for this Zabbix site and try again.');

			return;
		}

		this.#enterPopped(popup);
		popup.focus();
	}

	#enterPopped(popup) {
		this.popped = true;
		this.popup = popup;
		this.root.classList.add('mcppilot-popped');
		this.#updateControls();

		this.popup_watch = setInterval(() => {
			let gone;

			try {
				gone = this.popup !== null
					? this.popup.closed
					// Not registered yet (this page was reloaded): only the flag can say it was closed.
					: this.#poppedFlag() !== 'open';
			}
			catch (e) {
				gone = true;
			}

			if (gone) {
				this.#returnFromPopout();
			}
		}, 1000);
	}

	/**
	 * The chat comes back to this (main) window: reload it, which restores the conversation saved by the pop-out.
	 */
	#returnFromPopout() {
		clearInterval(this.popup_watch);
		this.#setPoppedFlag(null);
		location.reload();
	}

	showPopup() {
		const popup = this.#findPopup();

		if (popup === undefined) {
			return;
		}

		if (popup === null) {
			// It is not there any more.
			this.#returnFromPopout();

			return;
		}

		popup.focus();
	}

	/**
	 * The pop-out window: this.popup, or found by its name (needs a click, as it may open a new window).
	 * Returns null if it does not exist, undefined if that cannot be told (pop-ups blocked).
	 */
	#findPopup() {
		try {
			if (this.popup !== null && !this.popup.closed) {
				return this.popup;
			}
		}
		catch (e) {
			// Fall through.
		}

		const found = window.open('', this.constructor.POPUP_NAME);

		if (!found) {
			window.alert('The browser blocked opening the pop-out window. Allow pop-ups for this Zabbix site.');

			return undefined;
		}

		try {
			if (found.mcppilot_chat?.popout) {
				this.popup = found;

				return found;
			}
		}
		catch (e) {
			// Not a chat window.
		}

		// A new empty window was opened: there was no pop-out.
		found.close();

		return null;
	}

	popIn() {
		if (this.popout) {
			this.#popInFromPopout();

			return;
		}

		if (!this.popped) {
			return;
		}

		const popup = this.#findPopup();

		if (popup === undefined) {
			return;
		}

		if (popup !== null) {
			let busy = false;

			try {
				busy = popup.mcppilot_chat.busy;
			}
			catch (e) {
				// Not readable: treat as idle.
			}

			if (busy) {
				popup.focus();
				window.alert('An answer is still running in the pop-out window. Wait for it to finish, or stop it, '
					+ 'then pop in.');

				return;
			}

			try {
				popup.mcppilot_chat.popping_in = true;
			}
			catch (e) {
				// Not readable.
			}

			popup.close();
		}

		this.#returnFromPopout();
	}

	#popInFromPopout() {
		if (this.busy) {
			return;
		}

		this.#saveChat();

		const opener = this.#opener();

		if (opener === null) {
			// No main window any more: this window becomes the normal chat page.
			location.href = this.#chatUrl(false);

			return;
		}

		this.popping_in = true;
		this.#setPoppedFlag(null, opener.sessionStorage);

		try {
			const main_chat = opener.mcppilot_chat;

			if (main_chat?.popped) {
				clearInterval(main_chat.popup_watch);
				opener.location.reload();
			}
			else {
				opener.location.href = this.#chatUrl(false);
			}

			opener.focus();
		}
		catch (e) {
			opener.location.href = this.#chatUrl(false);
		}

		window.close();
	}

	/**
	 * Pop-out: a click on a Zabbix link in an answer opens it in the main window, not in a new tab here.
	 */
	#openInMain(e) {
		const link = e.target.closest('a[href]');

		if (link === null || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) {
			return;
		}

		let url;

		try {
			url = new URL(link.href, location.href);
		}
		catch (err) {
			return;
		}

		const opener = this.#opener();

		if (url.origin !== location.origin || opener === null) {
			return;
		}

		e.preventDefault();
		opener.location.href = url.href;
		opener.focus();
	}

	#restoreChat() {
		let data;

		try {
			data = JSON.parse(sessionStorage.getItem(this.#storageKey()) ?? 'null');
		}
		catch (e) {
			return;
		}

		if (!data || data.v !== 1 || !Array.isArray(data.turns) || data.turns.length === 0) {
			return;
		}

		if (this.profiles.some((p) => p.id === data.profile_id)) {
			this.profile_id = data.profile_id;

			if (this.profile_select !== null) {
				this.profile_select.value = data.profile_id;
			}
		}

		this.state = data.state ?? '';
		this.sig = data.sig ?? '';

		for (const saved of data.turns) {
			this.#addUserMessage(saved.question);

			const turn = this.#createTurn(saved.question, saved.profile_id,
				typeof saved.label === 'string' ? saved.label : null
			);

			for (const event of saved.events ?? []) {
				this.#renderEvent(turn, event);
			}

			turn.status = saved.status;
			turn.duration_ms = saved.duration_ms;
			turn.llm_calls = saved.llm_calls;
			turn.tool_calls = saved.tool_calls;
			turn.error = saved.error;
			this.#renderSummary(turn);
			this.turns.push(turn);
		}

		if (data.context) {
			this.#setContext(data.context.prompt_tokens, data.context.context_size);
		}

		this.#setUsage(data.usage ?? null);

		this.#scroll();
	}

	#addUserMessage(text) {
		const el = document.createElement('div');
		el.className = 'mcppilot-msg mcppilot-msg-user';
		el.textContent = text;
		this.messages_el.append(el);
		this.#scroll();
	}

	#renderEmpty() {
		if (this.messages_el.childElementCount > 0) {
			return;
		}

		const el = document.createElement('div');
		el.className = 'mcppilot-empty';
		el.textContent = 'Ask a question about your monitoring. The selected model answers by calling tools of the '
			+ 'Zabbix MCP server, using your own Zabbix permissions'
			+ (this.username ? ' (' + this.username + ')' : '') + '.';
		this.messages_el.append(el);
	}

	#clearEmpty() {
		const empty = this.messages_el.querySelector('.mcppilot-empty');

		if (empty !== null) {
			empty.remove();
		}
	}

	#renderStarters() {
		this.starters_el.innerHTML = '';

		const profile = this.profiles.find((p) => p.id === this.profile_id);

		for (const prompt of profile?.starter_prompts ?? []) {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'btn-alt';
			button.textContent = prompt;
			button.addEventListener('click', () => this.send(prompt));
			this.starters_el.append(button);
		}
	}

	#updateControls() {
		const no_profile = this.profile_id === '';

		this.send_btn.disabled = this.busy || no_profile || this.popped;
		this.stop_btn.disabled = !this.busy;
		this.input.disabled = no_profile || this.popped;

		// While the chat is popped out, this window must not change it.
		for (const id of ['mcppilot-new', 'mcppilot-export', 'mcppilot-popout']) {
			const button = document.getElementById(id);

			if (button !== null) {
				button.disabled = this.popped || (id === 'mcppilot-popout' && this.busy);
			}
		}

		const popin = document.getElementById('mcppilot-popin');

		if (popin !== null) {
			popin.disabled = this.busy;
		}

		for (const button of this.starters_el.querySelectorAll('button')) {
			button.disabled = this.busy;
		}

		if (this.profile_select !== null) {
			this.profile_select.toggleAttribute('disabled', this.busy || this.popped);
		}
	}

	#scroll() {
		this.messages_el.scrollTop = this.messages_el.scrollHeight;
	}

	// ---------------------------------------------------------------------------------------------------------
	// Side panel
	// ---------------------------------------------------------------------------------------------------------

	async refreshStatus() {
		const status_el = document.getElementById('mcppilot-status');
		const tools_el = document.getElementById('mcppilot-tools');

		status_el.textContent = 'Checking…';
		this.bar_status = null;
		this.#updateBar();

		const url = new Curl('zabbix.php');
		url.setArgument('action', 'mcppilotchat.status');
		url.setArgument('profile_id', this.profile_id);

		let data;

		try {
			const http = await fetch(url.getUrl());
			data = JSON.parse(await http.text());
		}
		catch (e) {
			status_el.innerHTML = '';
			status_el.append(this.#statusLine('●', 'color-negative', 'Status unavailable. Reload the page.'));
			this.bar_status = {connected: false, text: 'Status unavailable', tools: null};
			this.#updateBar();

			return;
		}

		const mcp = data.mcp;
		status_el.innerHTML = '';

		// Compact: one line of status, one small line with the server and its URL.
		const head = this.#statusLine('●', mcp.connected ? 'color-positive' : 'color-negative', '', true);
		const sub = document.createElement('div');
		sub.className = 'mcppilot-status-sub';

		if (mcp.connected) {
			const server = mcp.server ? [mcp.server.name, mcp.server.version].filter(Boolean).join(' ') : '';
			const about = mcp.about || {};
			const zabbix_version = about.version ?? about.zabbix_version ?? about.zabbix?.version ?? null;
			const identity = about.user?.username ?? about.username ?? about.identity ?? about.user ?? null;

			head.append(document.createTextNode([
				'Connected · ' + mcp.latency_ms + ' ms',
				zabbix_version ? 'Zabbix ' + zabbix_version : null,
				identity && typeof identity !== 'object' ? 'as ' + identity : null
			].filter(Boolean).join(' · ')));
			sub.textContent = [server, mcp.url].filter(Boolean).join(' · ');
		}
		else {
			head.append(document.createTextNode('Not connected'));
			sub.textContent = (mcp.error || 'Unknown error.') + ' · ' + mcp.url;
		}

		sub.title = mcp.url;
		status_el.append(head, sub);

		const count_el = document.getElementById('mcppilot-tools-count');

		if (count_el !== null) {
			count_el.textContent = mcp.connected ? mcp.exposed_count + ' exposed' : '';
		}

		// Demo safety: write tools this profile can use without approval.
		const profile = this.profiles.find((p) => p.id === this.profile_id);
		const writable = (data.tools ?? []).filter((t) => t.type === 'write' && t.state === 'enabled');

		if (writable.length && profile && !profile.approval_required) {
			const warning = this.#statusLine('⚠', 'color-warning', writable.length + ' write tool'
				+ (writable.length === 1 ? '' : 's') + ' can change Zabbix without approval in this profile.', true);
			warning.classList.add('mcppilot-write-warning');
			warning.title = writable.map((t) => t.name).join(', ');
			status_el.append(warning);
		}

		this.bar_status = {
			connected: mcp.connected,
			text: mcp.connected ? 'Connected' : 'Not connected',
			tools: mcp.connected ? (data.tools ?? []).filter((t) => t.state === 'enabled').length : null,
			warning: writable.length > 0 && profile && !profile.approval_required
		};
		this.#updateBar();

		tools_el.innerHTML = '';

		for (const tool of data.tools) {
			const row = document.createElement('div');
			row.className = 'mcppilot-tool mcppilot-tool-' + tool.state;
			row.title = tool.description;

			const dot = document.createElement('span');
			dot.className = 'mcppilot-dot mcppilot-dot-' + tool.state;
			dot.textContent = '●';

			const name = document.createElement('span');
			name.className = 'mcppilot-tool-name';
			name.textContent = tool.name;

			const rw = document.createElement('span');
			rw.className = 'mcppilot-rw';
			rw.textContent = tool.type === 'read' ? 'R' : 'W';
			rw.title = tool.type === 'read' ? 'Read-only' : 'Changes Zabbix';

			row.append(dot, name, rw);
			tools_el.append(row);
		}
	}

	#statusLine(icon, cls, text, head = false) {
		const line = document.createElement('div');

		if (head) {
			line.className = 'mcppilot-status-head';
		}

		if (icon !== '') {
			const dot = document.createElement('span');
			dot.className = cls;
			dot.textContent = icon + ' ';
			line.append(dot);
		}

		line.append(document.createTextNode(text));

		return line;
	}

	/**
	 * Context: size of the last request compared with the profile's context window (a plain number).
	 */
	#setContext(prompt_tokens, context_size) {
		const text = document.getElementById('mcppilot-context-text');

		if (prompt_tokens === null || prompt_tokens === undefined || !context_size) {
			if (prompt_tokens === null && context_size === null) {
				text.textContent = '–';
				text.classList.remove('mcppilot-warn-text');
			}

			return;
		}

		this.last_context = {prompt_tokens, context_size};

		const ratio = prompt_tokens / context_size;

		text.textContent = prompt_tokens.toLocaleString() + ' / ' + context_size.toLocaleString()
			+ (ratio >= .8 ? ' · start a new chat soon' : '');
		text.classList.toggle('mcppilot-warn-text', ratio >= .8);
	}

	/**
	 * Adds one model call's token counts to "this question" and "this chat".
	 */
	#addUsage(usage) {
		this.usage = this.usage ?? {question: {in: 0, out: 0}, chat: {in: 0, out: 0}, unknown: false};

		if (usage?.prompt_tokens == null && usage?.completion_tokens == null) {
			// The provider reported no counts (possible with the Generic HTTP adapter).
			this.usage.unknown = true;
		}

		for (const scope of ['question', 'chat']) {
			this.usage[scope].in += usage?.prompt_tokens ?? 0;
			this.usage[scope].out += usage?.completion_tokens ?? 0;
		}

		this.#setUsage(this.usage);
	}

	/**
	 * Usage card: tokens sent and received for this question and this chat, as plain numbers.
	 */
	#setUsage(usage) {
		const question = document.getElementById('mcppilot-usage-question');
		const chat = document.getElementById('mcppilot-usage-chat');

		this.usage = usage ?? null;

		if (question === null) {
			return;
		}

		const format = (u) => usage.unknown && u.in === 0 && u.out === 0
			? '–'
			: u.in.toLocaleString() + ' sent · ' + u.out.toLocaleString() + ' received';

		question.textContent = usage ? format(usage.question) : '–';
		chat.textContent = usage ? format(usage.chat) : '–';
		this.#updateBar();
	}

	/**
	 * Pop-out status line (narrow windows): MCP connection, tokens of this chat, tools enabled in this profile.
	 */
	#updateBar() {
		const bar = document.getElementById('mcppilot-bar-text');

		if (bar === null) {
			return;
		}

		bar.innerHTML = '';

		const status = this.bar_status ?? null;
		const dot = document.createElement('span');
		dot.className = status === null ? '' : (status.connected ? 'color-positive' : 'color-negative');
		dot.textContent = '● ';

		const usage = this.usage?.chat;
		const parts = [
			status === null ? 'Checking…' : status.text,
			usage && !(this.usage.unknown && usage.in === 0 && usage.out === 0)
				? usage.in.toLocaleString() + ' sent · ' + usage.out.toLocaleString() + ' received'
				: null,
			status?.tools != null ? status.tools + ' tool' + (status.tools === 1 ? '' : 's') : null,
			status?.warning ? '⚠ write tools without approval' : null
		];

		bar.append(dot, document.createTextNode(parts.filter(Boolean).join(' · ')));
		bar.title = bar.textContent;
	}

	// ---------------------------------------------------------------------------------------------------------
	// Export
	// ---------------------------------------------------------------------------------------------------------

	exportJson() {
		const data = {
			exported_by: 'MCP Pilot Chat v' + this.version,
			exported_at: new Date().toISOString(),
			user: this.username,
			usage: this.usage,
			turns: this.turns.map((t) => ({
				question: t.question,
				profile_id: t.profile_id,
				profile: t.profile_label,
				status: t.status ?? 'running',
				duration_ms: t.duration_ms !== undefined ? Math.round(t.duration_ms) : null,
				events: t.events,
				error: t.error ?? null
			}))
		};

		const blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json'});
		const link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = 'mcp-pilot-chat-log-' + new Date().toISOString().replace(/[:.]/g, '-') + '.json';
		document.body.append(link);
		link.click();
		link.remove();
		setTimeout(() => URL.revokeObjectURL(link.href), 1000);
	}

	// ---------------------------------------------------------------------------------------------------------
	// Answer rendering: small, safe Markdown subset + links for IDs seen in tool results
	// ---------------------------------------------------------------------------------------------------------

	#renderAnswer(container, markdown) {
		container.innerHTML = this.#markdown(markdown);
		this.#linkify(container);
	}

	#markdown(source) {
		const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');

		const inline = (s) => esc(s)
			.replace(/`([^`]+)`/g, '<code>$1</code>')
			.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
			.replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<i>$2</i>')
			.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

		const lines = source.replace(/\r\n?/g, '\n').split('\n');
		const out = [];
		let list = null;
		let paragraph = [];

		const flushParagraph = () => {
			if (paragraph.length) {
				out.push('<p>' + paragraph.map(inline).join('<br>') + '</p>');
				paragraph = [];
			}
		};

		const closeList = () => {
			if (list !== null) {
				out.push('</' + list + '>');
				list = null;
			}
		};

		for (let i = 0; i < lines.length; i++) {
			const line = lines[i];

			if (/^```/.test(line)) {
				flushParagraph();
				closeList();

				const code = [];

				for (i++; i < lines.length && !/^```/.test(lines[i]); i++) {
					code.push(lines[i]);
				}

				out.push('<pre><code>' + esc(code.join('\n')) + '</code></pre>');
				continue;
			}

			if (/^\s*\|.*\|\s*$/.test(line) && i + 1 < lines.length && /^\s*\|?\s*:?-{2,}/.test(lines[i + 1])) {
				flushParagraph();
				closeList();

				const cells = (row) => row.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim());
				const head = cells(line);
				let html = '<table><thead><tr>' + head.map((c) => '<th>' + inline(c) + '</th>').join('')
					+ '</tr></thead><tbody>';

				for (i += 2; i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i]); i++) {
					html += '<tr>' + cells(lines[i]).map((c) => '<td>' + inline(c) + '</td>').join('') + '</tr>';
				}

				i--;
				out.push(html + '</tbody></table>');
				continue;
			}

			const heading = line.match(/^(#{1,3})\s+(.*)$/);

			if (heading) {
				flushParagraph();
				closeList();
				out.push('<h3>' + inline(heading[2]) + '</h3>');
				continue;
			}

			const bullet = line.match(/^\s*[-*•]\s+(.*)$/);
			const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);

			if (bullet || numbered) {
				flushParagraph();

				const type = bullet ? 'ul' : 'ol';

				if (list !== type) {
					closeList();
					out.push('<' + type + '>');
					list = type;
				}

				out.push('<li>' + inline((bullet || numbered)[1]) + '</li>');
				continue;
			}

			if (line.trim() === '') {
				flushParagraph();
				closeList();
				continue;
			}

			closeList();
			paragraph.push(line);
		}

		flushParagraph();
		closeList();

		return out.join('');
	}

	#learnIds(tool_name, raw) {
		let data;

		try {
			data = JSON.parse(raw);
		}
		catch (e) {
			return;
		}

		for (const row of Array.isArray(data?.results) ? data.results : []) {
			if (row.eventid && row.objectid) {
				this.known.events.set(String(row.eventid), String(row.objectid));
			}

			if (row.hostid) {
				this.known.hosts.set(String(row.hostid), row.name ?? row.host ?? '');

				if (row.name) {
					this.known.host_names.set(String(row.name), String(row.hostid));
				}
			}

			if (row.triggerid) {
				this.known.triggers.add(String(row.triggerid));
			}

			for (const trigger of Array.isArray(row.triggers) ? row.triggers : []) {
				if (trigger.triggerid) {
					this.known.triggers.add(String(trigger.triggerid));
				}
			}
		}
	}

	#linkify(container) {
		const names = [...this.known.host_names.keys()].filter((n) => n.length >= 3)
			.sort((a, b) => b.length - a.length)
			.map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));

		// Group 2 never matches when no host names are known, so the replace() callback arguments stay stable.
		const pattern = new RegExp('\\b(\\d{2,20})\\b|(' + (names.length ? names.join('|') : '[^\\s\\S]') + ')', 'g');
		const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT);
		const nodes = [];

		while (walker.nextNode()) {
			if (!walker.currentNode.parentElement.closest('a, code, pre')) {
				nodes.push(walker.currentNode);
			}
		}

		for (const node of nodes) {
			const text = node.nodeValue;
			const fragment = document.createDocumentFragment();
			let last = 0;
			let changed = false;

			text.replace(pattern, (match, id, name, offset) => {
				const href = id !== undefined ? this.#idUrl(id) : this.#hostUrl(this.known.host_names.get(name));

				if (href === null) {
					return match;
				}

				fragment.append(document.createTextNode(text.slice(last, offset)));

				const link = document.createElement('a');
				link.href = href;
				link.target = '_blank';
				link.className = 'link-alt';
				link.textContent = match;
				fragment.append(link);

				last = offset + match.length;
				changed = true;

				return match;
			});

			if (changed) {
				fragment.append(document.createTextNode(text.slice(last)));
				node.replaceWith(fragment);
			}
		}
	}

	#idUrl(id) {
		if (this.known.events.has(id)) {
			const url = new Curl('tr_events.php');
			url.setArgument('triggerid', this.known.events.get(id));
			url.setArgument('eventid', id);

			return url.getUrl();
		}

		if (this.known.hosts.has(id)) {
			return this.#hostUrl(id);
		}

		if (this.known.triggers.has(id)) {
			const url = new Curl('zabbix.php');
			url.setArgument('action', 'problem.view');
			url.setArgument('filter_set', '1');
			url.setArgument('triggerids[]', id);

			return url.getUrl();
		}

		return null;
	}

	#hostUrl(hostid) {
		if (hostid === undefined) {
			return null;
		}

		const url = new Curl('zabbix.php');
		url.setArgument('action', 'problem.view');
		url.setArgument('filter_set', '1');
		url.setArgument('hostids[]', hostid);

		return url.getUrl();
	}

	// ---------------------------------------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------------------------------------

	#profileName(id) {
		return this.profiles.find((p) => p.id === id)?.name ?? id;
	}

	/**
	 * "Profile - model", as in the profile list (the model configured in the profile).
	 */
	#profileLabel(id) {
		const profile = this.profiles.find((p) => p.id === id);

		if (!profile) {
			return id;
		}

		return profile.model ? profile.name + ' - ' + profile.model : profile.name;
	}

	#seconds(ms) {
		return ms < 60000
			? (ms / 1000).toFixed(1) + ' s'
			: Math.floor(ms / 60000) + ' min ' + Math.floor((ms % 60000) / 1000) + ' s';
	}

	#bytes(n) {
		if (n === null || n === undefined) {
			return '';
		}

		return n < 1024 ? n + ' B' : (n / 1024).toFixed(1) + ' KB';
	}

	#pretty(text) {
		try {
			return JSON.stringify(JSON.parse(text), null, 2);
		}
		catch (e) {
			return text;
		}
	}
};
</script>
