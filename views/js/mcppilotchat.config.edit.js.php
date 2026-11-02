<?php declare(strict_types = 0);
/*
** MCP Pilot Chat - external module for Zabbix 8.0.
** Not part of Zabbix and not supported by Zabbix SIA.
**
** Copyright (C) 2026 Vittorio Cioe
** SPDX-License-Identifier: AGPL-3.0-only
*/

/**
 * Configuration popup logic. Inline script (no <script> tags): returned in "script_inline".
 *
 * @var CView $this
 */
?>

window.mcppilot_config = new class {

	init({config, user_groups, profile_defaults, builtin_prompt, default_trim_fields, lean_trim_fields, catalog, presets,
			csrf}) {
		this.config = config;
		this.user_groups = user_groups;
		this.profile_defaults = profile_defaults;
		this.builtin_prompt = builtin_prompt;
		this.default_trim_fields = default_trim_fields;
		this.lean_trim_fields = lean_trim_fields;
		this.catalog = catalog;
		this.presets = presets ?? [];
		this.csrf = csrf;
		this.selected = config.profiles.length ? 0 : -1;

		// Inputs of required fields currently on screen, by key ("mcp.url", "profile.base_url", …).
		this.fields = {};

		this.overlay = overlays_stack.getById('mcppilotchat-config');
		this.dialogue = this.overlay.$dialogue[0];
		this.root = document.getElementById('mcppilot-config');

		this.overlay.$dialogue.$footer[0].querySelector('.js-submit').addEventListener('click', () => this.#save());

		this.#render();
	}

	// ---------------------------------------------------------------------------------------------------------
	// Layout
	// ---------------------------------------------------------------------------------------------------------

	#render() {
		this.root.innerHTML = '';

		const tabs = this.#el('div', 'mcppilot-cfg-tabs');
		this.panels = {};

		const tab_list = [['mcp', 'MCP server'], ['prompts', 'Prompts'], ['profiles', 'LLM profiles'],
			['access', 'Access & UI']];

		for (const [key, label] of tab_list) {
			const button = this.#el('button', '', label);
			button.type = 'button';
			button.dataset.tab = key;
			button.addEventListener('click', () => this.#showTab(key));
			tabs.append(button);

			this.panels[key] = this.#el('div', 'mcppilot-cfg-panel');
		}

		this.messages = this.#el('div');
		this.root.append(this.messages, tabs, ...Object.values(this.panels));

		this.#renderMcp();
		this.#renderPrompts();
		this.#renderProfiles();
		this.#renderAccess();
		this.#showTab(this.current_tab ?? 'mcp');
	}

	#showTab(key) {
		this.current_tab = key;

		// The profile editor shows the module default prompt, which may have changed on the Prompts tab.
		if (key === 'profiles' && this.panels.profiles.childElementCount > 0) {
			this.#renderProfiles();
		}

		for (const button of this.root.querySelectorAll('.mcppilot-cfg-tabs button')) {
			button.classList.toggle('mcppilot-active', button.dataset.tab === key);
		}

		for (const [name, panel] of Object.entries(this.panels)) {
			panel.hidden = name !== key;
		}
	}

	#renderMcp() {
		const panel = this.panels.mcp;
		const grid = this.#el('div', 'mcppilot-cfg-grid');

		this.#required('mcp.url', this.#text(grid, 'URL', this.config.mcp.url, (v) => this.config.mcp.url = v,
			'Streamable HTTP endpoint of the Zabbix MCP server, e.g. http://zabbix-mcp-server:8443/mcp'));
		this.#required('mcp.timeout',
			this.#number(grid, 'Timeout (s)', this.config.mcp.timeout, (v) => this.config.mcp.timeout = v, 3, 120)
		);

		this.#static(grid, 'Authentication',
			'Always the logged-in user\'s Zabbix session. There is no token to configure.');

		const test = this.#testButton(() => ({target: 'mcp', mcp: this.config.mcp}));
		this.#static(grid, '', test);

		panel.append(grid);
	}

	/**
	 * Module default prompt, used by every profile set to "Use the module default prompt".
	 * Text identical to the built-in prompt is stored as empty, so it keeps following module updates.
	 */
	#renderPrompts() {
		const panel = this.panels.prompts;
		const grid = this.#el('div', 'mcppilot-cfg-grid');

		const current = (this.config.prompts.system ?? '').trim() !== '' ? this.config.prompts.system : this.builtin_prompt;

		this.#promptEditor(grid, 'Module default system prompt', current,
			(v) => this.config.prompts.system = v.trim() === this.builtin_prompt.trim() ? '' : v,
			[['Reset to built-in', () => this.builtin_prompt]],
			'Used by every profile set to "Use the module default prompt". '
				+ 'Placeholders: {{default_limit}} and {{max_limit}} (the profile\'s default and maximum limit), {{date}}.'
		);

		panel.append(grid);
	}

	/**
	 * Prompt textarea with reset buttons and a live token estimate.
	 *
	 * @param {Array} resets  [label, () => text] pairs.
	 */
	#promptEditor(grid, label, value, onchange, resets, hint) {
		const rows = this.#textarea(grid, label, value, (v) => {
			onchange(v);
			update();
		}, hint);

		const area = rows[1].querySelector('textarea');
		area.style.minHeight = '220px';

		const bar = this.#el('div', 'mcppilot-cfg-prompt-bar');
		const estimate = this.#el('span', 'mcppilot-cfg-hint');

		const update = () => {
			// Rough estimate: about 4 characters per token for English text.
			estimate.textContent = '≈ ' + Math.ceil(area.value.length / 4) + ' tokens, read by the model on every call';
		};

		for (const [reset_label, getText] of resets) {
			bar.append(this.#button(reset_label, () => {
				area.value = getText();
				onchange(area.value);
				update();
			}));
		}

		bar.append(estimate);
		area.after(bar);
		update();

		return rows;
	}

	#renderProfiles() {
		const panel = this.panels.profiles;
		panel.innerHTML = '';

		const wrap = this.#el('div', 'mcppilot-cfg-profiles');
		const list = this.#el('div', 'mcppilot-cfg-list');
		const editor = this.#el('div', 'mcppilot-cfg-editor');

		this.config.profiles.forEach((profile, index) => {
			const item = this.#el('button', 'mcppilot-cfg-item', profile.name || profile.id || '(new profile)');
			item.type = 'button';
			item.classList.toggle('mcppilot-active', index === this.selected);
			item.addEventListener('click', () => {
				this.selected = index;
				this.#renderProfiles();
			});
			list.append(item);
		});

		const add = this.#button('Add', () => {
			const profile = structuredClone(this.profile_defaults);
			profile.id = this.#uniqueId('profile');
			profile.name = 'New profile';
			profile.trim_fields = {};
			profile.generic = {...this.profile_defaults.generic};
			this.config.profiles.push(profile);
			this.selected = this.config.profiles.length - 1;
			this.#renderProfiles();
		});

		list.append(add);

		if (this.selected >= 0) {
			const actions = this.#el('div');
			actions.style.display = 'flex';
			actions.style.gap = '4px';

			actions.append(
				this.#button('Duplicate', () => {
					const copy = structuredClone(this.config.profiles[this.selected]);
					copy.id = this.#uniqueId(copy.id);
					copy.name = copy.name + ' (copy)';
					copy.api_key = '';

					// A duplicated profile cannot reuse the stored key of the original.
					copy.has_api_key = false;
					this.config.profiles.push(copy);
					this.selected = this.config.profiles.length - 1;
					this.#renderProfiles();
				}),
				this.#button('Remove', () => {
					this.config.profiles.splice(this.selected, 1);
					this.selected = Math.min(this.selected, this.config.profiles.length - 1);
					this.#renderProfiles();
				})
			);

			list.append(actions);
			this.#renderProfileEditor(editor, this.config.profiles[this.selected]);
		}
		else {
			editor.append(this.#el('div', 'mcppilot-cfg-hint',
				'No profiles yet. Add one, e.g. an OpenAI-compatible endpoint such as http://demo-llm:8080/v1, '
					+ 'or start from a preset.'));
		}

		editor.prepend(this.#presetBar(this.selected >= 0 ? this.config.profiles[this.selected] : null));

		wrap.append(list, editor);
		panel.append(wrap);

		// Keep the "Default profile" list in sync with added, removed or renamed profiles.
		this.#renderAccess();
	}

	/**
	 * Provider presets: add a new profile from one, or apply one to the selected profile.
	 * Applying never changes the ID, name, API key, allowed tools, prompt, starter prompts or user groups.
	 */
	#presetBar(p) {
		const wrap = this.#el('div', 'mcppilot-cfg-preset');
		const select = this.#el('select');
		select.id = 'mcppilot-preset';

		for (const preset of this.presets) {
			const option = this.#el('option', '', preset.label + (preset.tested ? ' · tested' : ' · untested'));
			option.value = preset.id;
			select.append(option);
		}

		const note = this.#el('div', 'mcppilot-cfg-hint');
		const current = () => this.presets.find((x) => x.id === select.value) ?? null;
		const showNote = () => note.textContent = current()?.note ?? '';
		select.addEventListener('change', showNote);

		const add = this.#button('Add as new profile', () => {
			const preset = current();

			if (preset === null) {
				return;
			}

			const profile = structuredClone(this.profile_defaults);
			Object.assign(profile, structuredClone(preset.fields));
			profile.id = this.#uniqueId(preset.id);
			profile.name = preset.label;
			profile.starter_prompts = [...preset.starter_prompts];
			profile.tool_allowlist = [...preset.tools];
			profile.generic = {...this.profile_defaults.generic};
			this.config.profiles.push(profile);
			this.selected = this.config.profiles.length - 1;
			this.#renderProfiles();
		});
		add.id = 'mcppilot-preset-add';

		wrap.append(this.#el('span', 'mcppilot-cfg-preset-label', 'Preset'), select, add);

		if (p !== null) {
			const apply = this.#button('Apply to this profile', () => {
				const preset = current();

				if (preset === null || !window.confirm('Apply the "' + preset.label + '" preset to "'
						+ (p.name || p.id) + '"? This sets the URL, model, limits and timeouts. The ID, name, API key, '
						+ 'allowed tools, prompt, starter prompts and user groups are kept.')) {
					return;
				}

				for (const [key, value] of Object.entries(structuredClone(preset.fields))) {
					// A preset without a model (e.g. OpenAI) keeps the model already set.
					if (key === 'model' && value === '') {
						continue;
					}

					p[key] = value;
				}

				this.#renderProfiles();
			});
			apply.id = 'mcppilot-preset-apply';
			wrap.append(apply);
		}

		wrap.append(note);
		showNote();

		return wrap;
	}

	#renderProfileEditor(editor, p) {
		const grid = this.#el('div', 'mcppilot-cfg-grid');
		const refreshName = () => {
			const item = this.panels.profiles.querySelector('.mcppilot-cfg-item.mcppilot-active');

			if (item !== null) {
				item.textContent = p.name || p.id;
			}
		};

		this.#section(grid, 'Connection');
		this.#required('profile.id', this.#text(grid, 'ID', p.id, (v) => { p.id = v; this.#renderAccess(); },
			'Lowercase letters, digits, "-" and "_".'));
		this.#text(grid, 'Name', p.name, (v) => { p.name = v; refreshName(); this.#renderAccess(); });

		const generic_rows = [];
		let base_url_row = null;

		this.#select(grid, 'Adapter', p.adapter, [
			['openai', 'OpenAI-compatible Chat Completions'],
			['generic_http', 'Generic HTTP (template)']
		], (v) => {
			p.adapter = v;
			generic_rows.forEach((row) => row.forEach((el) => el.hidden = v !== 'generic_http'));

			// Generic HTTP may use the Endpoint URL instead of the Base URL.
			base_url_row[0].classList.toggle('form-label-asterisk', v === 'openai');
		});

		base_url_row = this.#required('profile.base_url', this.#text(grid, 'Base URL', p.base_url,
			(v) => p.base_url = v, 'For OpenAI-compatible APIs, up to /v1 (e.g. http://demo-llm:8080/v1).'));
		base_url_row[0].classList.toggle('form-label-asterisk', p.adapter === 'openai');

		const key_hint = p.has_api_key
			? 'A key is stored. Leave empty to keep it.'
			: 'Optional. Sent as "Authorization: Bearer …". Never shown again after saving.';
		this.#text(grid, 'API key', '', (v) => p.api_key = v, key_hint, 'password');

		if (p.has_api_key) {
			this.#checkbox(grid, '', 'Remove the stored key', false, (v) => p.clear_api_key = v ? 1 : 0);
		}

		this.#text(grid, 'Model', p.model, (v) => p.model = v);

		generic_rows.push(
			this.#required('profile.generic.url', this.#text(grid, 'Endpoint URL', p.generic.url,
				(v) => p.generic.url = v, 'Full URL. Empty = Base URL.'), false),
			this.#textarea(grid, 'Extra headers', p.generic.headers, (v) => p.generic.headers = v,
				'One "Name: value" per line.'),
			this.#required('profile.generic.request_template', this.#textarea(grid, 'Request template',
				p.generic.request_template, (v) => p.generic.request_template = v,
				'JSON with placeholders: {{model}} {{messages}} {{tools}} {{system_prompt}} {{last_user_message}} '
					+ '{{temperature}} {{max_tokens}}. Must contain {{tools}} to use MCP.')),
			this.#text(grid, 'Response text path', p.generic.response_text_path,
				(v) => p.generic.response_text_path = v, 'Dot path, e.g. choices.0.message.content'),
			this.#text(grid, 'Tool calls path', p.generic.response_tool_calls_path,
				(v) => p.generic.response_tool_calls_path = v,
				'Dot path to OpenAI-format tool calls, e.g. choices.0.message.tool_calls. Required for MCP.')
		);
		generic_rows.forEach((row) => row.forEach((el) => el.hidden = p.adapter !== 'generic_http'));

		this.#static(grid, '', this.#testButton(() => ({target: 'llm', profile: p})));

		this.#section(grid, 'Model behavior');
		const temperature = this.#number(grid, 'Temperature', p.temperature, (v) => p.temperature = v, 0, 2, 0.1);
		temperature[1].append(this.#el('div', 'mcppilot-cfg-hint',
			'Empty = not sent (the provider default). Required for providers that reject other values.'));
		this.#number(grid, 'Max tokens per answer', p.max_tokens, (v) => p.max_tokens = v, 16, 32768);
		this.#number(grid, 'Context size (tokens)', p.context_size, (v) => p.context_size = v, 512, 2000000);
		this.#number(grid, 'Max model calls per question', p.max_iterations, (v) => p.max_iterations = v, 1, 30);
		this.#number(grid, 'Timeout (s)', p.timeout, (v) => p.timeout = v, 5, 900);
		this.#register('profile.extra_params', this.#textarea(grid, 'Extra request parameters', p.extra_params,
			(v) => p.extra_params = v,
			'JSON merged into each request, e.g. {"chat_template_kwargs": {"enable_thinking": false}}'));

		this.#profilePrompt(grid, p);

		this.#textarea(grid, 'Starter prompts', p.starter_prompts.join('\n'),
			(v) => p.starter_prompts = v.split('\n').map((s) => s.trim()).filter(Boolean),
			'One per line. Shown as clickable suggestions.');

		this.#section(grid, 'Tools');
		this.#number(grid, 'Default limit', p.default_limit, (v) => p.default_limit = v, 1, 1000);
		const max_limit = this.#number(grid, 'Maximum limit', p.max_limit, (v) => p.max_limit = v === '' ? 0 : v, 0,
			1000);
		max_limit[1].append(this.#el('div', 'mcppilot-cfg-hint',
			'Highest "limit" the model may ask a tool for; larger requests are refused and the model is told to ask '
				+ 'for less. Large results fill the context. 0 = no maximum.'));

		const tools = this.#el('div', 'mcppilot-cfg-groups');
		const all = this.#checkboxElement('All tools exposed by the MCP server', p.tool_allowlist.length === 0);
		tools.append(all.label);

		const tool_boxes = [];

		for (const name of this.catalog) {
			const box = this.#checkboxElement(name, p.tool_allowlist.includes(name));
			tool_boxes.push([name, box.input]);
			tools.append(box.label);
		}

		const syncTools = () => {
			tool_boxes.forEach(([, input]) => input.disabled = all.input.checked);
			p.tool_allowlist = all.input.checked ? [] : tool_boxes.filter(([, i]) => i.checked).map(([n]) => n);
		};

		all.input.addEventListener('change', syncTools);
		tool_boxes.forEach(([, input]) => input.addEventListener('change', syncTools));
		tool_boxes.forEach(([, input]) => input.disabled = all.input.checked);

		this.#static(grid, 'Allowed tools', tools,
			'Tools the model may use with this profile. Fewer tools help small models.');

		this.#checkbox(grid, 'First step', 'Require a tool call on the first model call of each question',
			!!p.require_tool_first, (v) => p.require_tool_first = v ? 1 : 0);
		grid.lastElementChild.append(this.#el('div', 'mcppilot-cfg-hint',
			'For small models that answer without looking at Zabbix. The model still chooses the tool and its '
				+ 'arguments. Leave off for capable models. "Test connection" checks that the server supports it.'));

		this.#checkbox(grid, 'Write tools', 'Ask the user to approve every change', !!p.approval_required,
			(v) => p.approval_required = v ? 1 : 0);
		this.#checkbox(grid, 'Timestamps', 'Show Unix times as readable UTC dates', !!p.format_times,
			(v) => p.format_times = v ? 1 : 0);

		// Same idea for the kept fields: show the defaults, save them as "use defaults" when unchanged.
		const default_trim = JSON.stringify(this.default_trim_fields, null, 2);
		const trim_rows = this.#textarea(grid, 'Kept result fields',
			Object.keys(p.trim_fields).length ? JSON.stringify(p.trim_fields, null, 2) : default_trim,
			(v) => {
				const area = trim_rows[1].querySelector('textarea');

				try {
					const parsed = v.trim() === '' ? {} : JSON.parse(v);
					p.trim_fields = JSON.stringify(parsed) === JSON.stringify(this.default_trim_fields) ? {} : parsed;
					area.style.outline = '';
				}
				catch (e) {
					area.style.outline = '1px solid #e45959';
				}
			},
			'JSON: tool → kept fields ("triggers[].triggerid" for nested). Tools not listed keep their default '
				+ 'fields; tools without defaults are passed unchanged. '
				+ 'Only removes noise; never adds data.');
		const trim_area = trim_rows[1].querySelector('textarea');
		trim_area.style.minHeight = '120px';

		const trim_bar = this.#el('div', 'mcppilot-cfg-prompt-bar');
		const setTrim = (fields) => {
			trim_area.value = JSON.stringify(fields, null, 2);
			trim_area.dispatchEvent(new Event('input'));
		};
		trim_bar.append(
			this.#button('Lean preset for small models', () => setTrim(this.lean_trim_fields)),
			this.#button('Reset to defaults', () => setTrim(this.default_trim_fields))
		);
		trim_area.after(trim_bar);

		this.#section(grid, 'Access');
		this.#static(grid, 'User groups', this.#groupPicker(p.usrgrpids, (ids) => p.usrgrpids = ids),
			'None selected = everyone who can use the chat. Super admins always see every profile.');

		editor.innerHTML = '';
		editor.append(grid);
	}

	/**
	 * Profile prompt: either the module default prompt, or a custom one that starts as a copy of it.
	 */
	#profilePrompt(grid, p) {
		const module_default = () => (this.config.prompts.system ?? '').trim() !== ''
			? this.config.prompts.system
			: this.builtin_prompt;

		const wrap = this.#el('div');
		const mode = this.#el('div');
		const editor_grid = this.#el('div');

		for (const [value, text] of [['default', 'Use the module default prompt'], ['custom', 'Custom prompt']]) {
			const label = this.#el('label');
			const radio = this.#el('input');
			radio.type = 'radio';
			radio.name = 'mcppilot-prompt-mode';
			radio.value = value;
			radio.checked = p.prompt_mode === value;
			radio.addEventListener('change', () => {
				p.prompt_mode = value;

				if (value === 'custom' && p.system_prompt.trim() === '') {
					p.system_prompt = module_default();
				}

				renderEditor();
			});
			label.append(radio, ' ' + text);
			label.style.marginRight = '16px';
			mode.append(label);
		}

		const renderEditor = () => {
			editor_grid.innerHTML = '';

			if (p.prompt_mode === 'custom') {
				const sub = this.#el('div', 'mcppilot-cfg-inner');
				this.#promptEditor(sub, '', p.system_prompt, (v) => p.system_prompt = v, [
					['Reset to module default', module_default],
					['Reset to built-in', () => this.builtin_prompt]
				], 'Short prompts work better with small models.');
				editor_grid.append(sub);
			}
			else {
				const preview = this.#el('pre', 'mcppilot-cfg-preview', module_default());
				editor_grid.append(preview,
					this.#el('div', 'mcppilot-cfg-hint', 'Edit it on the Prompts tab. Placeholders: {{default_limit}}, {{max_limit}}, {{date}}.'));
			}
		};

		renderEditor();
		wrap.append(mode, editor_grid);
		this.#static(grid, 'System prompt', wrap);
	}

	#renderAccess() {
		const panel = this.panels.access;
		panel.innerHTML = '';

		const grid = this.#el('div', 'mcppilot-cfg-grid');

		this.#static(grid, 'Chat users',
			this.#groupPicker(this.config.access.usrgrpids, (ids) => this.config.access.usrgrpids = ids),
			'None selected = every logged-in user. Super admins always have access.');

		this.#select(grid, 'Default profile', this.config.ui.default_profile,
			this.config.profiles.map((p) => [p.id, p.name || p.id]),
			(v) => this.config.ui.default_profile = v);

		this.#checkbox(grid, 'Debug details', 'Show tool calls and model statistics by default',
			!!this.config.ui.debug_default, (v) => this.config.ui.debug_default = v ? 1 : 0);

		panel.append(grid);
	}

	// ---------------------------------------------------------------------------------------------------------
	// Save and test
	// ---------------------------------------------------------------------------------------------------------

	/**
	 * Checks required fields before saving (the server checks them again).
	 *
	 * @return {Array<{message: string, tab: string, field: string, profile?: number}>}
	 */
	#validate() {
		const errors = [];
		const is_url = (v) => /^https?:\/\/.+/i.test(String(v ?? '').trim());
		const timeout = this.config.mcp.timeout;

		if (!is_url(this.config.mcp.url)) {
			errors.push({tab: 'mcp', field: 'mcp.url',
				message: 'MCP server: URL is required and must start with http:// or https://.'});
		}

		if (timeout === '' || !Number.isInteger(Number(timeout)) || timeout < 3 || timeout > 120) {
			errors.push({tab: 'mcp', field: 'mcp.timeout',
				message: 'MCP server: Timeout must be a whole number from 3 to 120 seconds.'});
		}

		const ids = new Set();

		this.config.profiles.forEach((p, profile) => {
			const label = 'LLM profile "' + (p.name || p.id || profile + 1) + '": ';
			const add = (field, message) => errors.push({tab: 'profiles', profile, field, message: label + message});

			if (!/^[a-z0-9_-]+$/.test(p.id ?? '')) {
				add('profile.id', 'ID is required and may contain only lowercase letters, digits, "-" and "_".');
			}
			else if (ids.has(p.id)) {
				add('profile.id', 'ID "' + p.id + '" is used by another profile.');
			}

			ids.add(p.id);

			if (p.adapter === 'generic_http') {
				if (!is_url(p.generic.url || p.base_url)) {
					add('profile.generic.url', 'Endpoint URL (or Base URL) is required and must start with http:// '
						+ 'or https://.');
				}

				if ((p.generic.request_template ?? '').trim() === '') {
					add('profile.generic.request_template', 'Request template is required for the Generic HTTP adapter.');
				}
			}
			else if (!is_url(p.base_url)) {
				add('profile.base_url', 'Base URL is required and must start with http:// or https://.');
			}

			if ((p.extra_params ?? '').trim() !== '') {
				let extra = null;

				try {
					extra = JSON.parse(p.extra_params);
				}
				catch (e) {
					// Reported below.
				}

				if (extra === null || typeof extra !== 'object' || Array.isArray(extra)) {
					add('profile.extra_params', 'Extra request parameters must be a JSON object.');
				}
			}
		});

		return errors;
	}

	/**
	 * Shows validation errors: a message box, the tab (and profile) of the first error, highlighted fields.
	 */
	#showErrors(errors) {
		const first = errors[0];

		if (first.profile !== undefined) {
			this.selected = first.profile;
			this.#renderProfiles();
		}

		this.#showTab(first.tab);
		this.messages.append(makeMessageBox('bad', errors.map((e) => e.message), 'Cannot save configuration')[0]);

		for (const error of errors) {
			if (error.tab === first.tab && (error.profile === undefined || error.profile === this.selected)) {
				this.fields[error.field]?.classList.add('has-error');
			}
		}

		this.fields[first.field]?.focus();
	}

	async #save() {
		this.#clearMessages();

		const errors = this.#validate();

		if (errors.length) {
			this.#showErrors(errors);
			this.overlay.unsetLoading();

			return;
		}

		try {
			const response = await this.#post('mcppilotchat.config.update', {config: this.config},
				this.csrf.update);

			if (!response.ok) {
				this.#message('bad', response.error?.message || 'Cannot save configuration.');

				return;
			}

			overlayDialogueDestroy(this.overlay.dialogueid);
			this.dialogue.dispatchEvent(new CustomEvent('dialogue.submit', {detail: response}));
		}
		catch (e) {
			this.#message('bad', e.message);
		}
		finally {
			this.overlay.unsetLoading();
		}
	}

	#testButton(payload) {
		const wrap = this.#el('div');
		const result = this.#el('div', 'mcppilot-cfg-test');
		const button = this.#button('Test connection', async () => {
			button.disabled = true;
			result.className = 'mcppilot-cfg-test';
			result.textContent = 'Testing…';

			const started = performance.now();

			try {
				const response = await this.#post('mcppilotchat.connection.test', payload(), this.csrf.test);
				const r = response.result ?? {ok: false, message: response.error?.message || 'Unknown error.'};
				const elapsed = ((performance.now() - started) / 1000).toFixed(1);

				result.classList.add(r.ok ? 'color-positive' : 'color-negative');
				result.textContent = (r.ok ? '✓ ' : '✕ ') + r.message + ' (' + elapsed + ' s)';

				if (r.details?.models?.length) {
					result.textContent += ' Models: ' + r.details.models.slice(0, 5).join(', ');
				}
			}
			catch (e) {
				result.classList.add('color-negative');
				result.textContent = '✕ ' + e.message;
			}
			finally {
				button.disabled = false;
			}
		});

		wrap.append(button, result);

		return wrap;
	}

	async #post(action, data, token) {
		const url = new Curl('zabbix.php');
		url.setArgument('action', action);

		const http = await fetch(url.getUrl(), {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify({...data, _csrf_token: token})
		});

		const text = await http.text();

		try {
			return JSON.parse(text);
		}
		catch (e) {
			throw new Error('The server did not return JSON (HTTP ' + http.status + ').');
		}
	}

	#message(type, text) {
		this.messages.append(makeMessageBox(type, [], text)[0]);
	}

	#clearMessages() {
		this.messages.innerHTML = '';
	}

	// ---------------------------------------------------------------------------------------------------------
	// Form helpers. Each field helper returns [label, field] so rows can be shown or hidden.
	// ---------------------------------------------------------------------------------------------------------

	#el(tag, cls = '', text = null) {
		const el = document.createElement(tag);

		if (cls) {
			el.className = cls;
		}

		if (text !== null) {
			el.textContent = text;
		}

		return el;
	}

	#button(label, onclick) {
		const button = this.#el('button', 'btn-alt', label);
		button.type = 'button';
		button.addEventListener('click', onclick);

		return button;
	}

	#row(grid, label, field, hint = '') {
		const label_el = this.#el('label', '', label);
		const field_el = this.#el('div');
		field_el.append(field);

		if (hint) {
			field_el.append(this.#el('div', 'mcppilot-cfg-hint', hint));
		}

		grid.append(label_el, field_el);

		return [label_el, field_el];
	}

	/**
	 * Remembers the input of a row, to highlight it when it is invalid. The mark is cleared on edit.
	 */
	#register(key, row) {
		const input = row[1].querySelector('input, textarea, select');

		if (input !== null) {
			this.fields[key] = input;
			input.addEventListener('input', () => input.classList.remove('has-error'));
		}

		return row;
	}

	/**
	 * Required field: red asterisk (the Zabbix form style) and highlighting when invalid.
	 */
	#required(key, row, asterisk = true) {
		if (asterisk) {
			row[0].classList.add('form-label-asterisk');
		}

		return this.#register(key, row);
	}

	#section(grid, title) {
		grid.append(this.#el('div', 'mcppilot-cfg-section', title));
	}

	#static(grid, label, content, hint = '') {
		return this.#row(grid, label, typeof content === 'string' ? this.#el('div', '', content) : content, hint);
	}

	#text(grid, label, value, onchange, hint = '', type = 'text') {
		const input = this.#el('input');
		input.type = type;
		input.value = value ?? '';
		input.autocomplete = 'off';
		input.addEventListener('input', () => onchange(input.value));

		return this.#row(grid, label, input, hint);
	}

	#number(grid, label, value, onchange, min, max, step = 1) {
		const input = this.#el('input');
		input.type = 'number';
		input.min = min;
		input.max = max;
		input.step = step;
		input.value = value;
		input.style.maxWidth = '140px';
		input.addEventListener('input', () => onchange(input.value === '' ? '' : Number(input.value)));

		return this.#row(grid, label, input);
	}

	#textarea(grid, label, value, onchange, hint = '') {
		const textarea = this.#el('textarea');
		textarea.value = value ?? '';
		textarea.addEventListener('input', () => onchange(textarea.value));

		return this.#row(grid, label, textarea, hint);
	}

	#select(grid, label, value, options, onchange) {
		const select = this.#el('select');

		for (const [option_value, option_label] of options) {
			const option = this.#el('option', '', option_label);
			option.value = option_value;
			option.selected = option_value === value;
			select.append(option);
		}

		select.addEventListener('change', () => onchange(select.value));

		return this.#row(grid, label, select);
	}

	#checkboxElement(text, checked) {
		const label = this.#el('label');
		const input = this.#el('input');
		input.type = 'checkbox';
		input.checked = checked;
		label.append(input, ' ' + text);

		return {label, input};
	}

	#checkbox(grid, label, text, checked, onchange) {
		const box = this.#checkboxElement(text, checked);
		box.input.addEventListener('change', () => onchange(box.input.checked));

		return this.#row(grid, label, box.label);
	}

	#groupPicker(selected, onchange) {
		const wrap = this.#el('div', 'mcppilot-cfg-groups');
		const boxes = [];

		for (const group of this.user_groups) {
			const box = this.#checkboxElement(group.name, selected.includes(group.id));
			boxes.push([group.id, box.input]);
			box.input.addEventListener('change', () => {
				onchange(boxes.filter(([, input]) => input.checked).map(([id]) => id));
			});
			wrap.append(box.label);
		}

		return wrap;
	}

	#uniqueId(base) {
		base = String(base).toLowerCase().replace(/[^a-z0-9_-]/g, '') || 'profile';

		const ids = new Set(this.config.profiles.map((p) => p.id));
		let id = base;

		for (let n = 2; ids.has(id); n++) {
			id = base + '-' + n;
		}

		return id;
	}
};
