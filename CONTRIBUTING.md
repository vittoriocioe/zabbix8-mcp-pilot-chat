# Contributing

Thanks for helping. Bug reports, test results with other models and providers, and pull requests are welcome.

This is a personal project maintained on a best-effort basis. Issues and pull requests are read when time
allows, but there is no commitment to respond, fix, merge or release by any date, or at all. See
[Support](README.md#support).

## Reporting a problem

Open an issue with:

- Zabbix frontend and MCP server versions (both are shown in the chat's **MCP server** card)
- The LLM provider and model (shown on every answer as "profile - model")
- What you asked, what you expected and what happened
- If you can, the **chat log**: click **Download chat log** and attach the JSON file

The chat log contains your questions, the model's answers and every tool result, so it can include host names,
problem names and other monitoring data. Look through it, and remove anything you do not want to publish. It
never contains the session ID or API keys, but check what you paste into the issue text.

**Never post API keys, Zabbix API tokens or session IDs** in issues, pull requests or logs.

Security problems: see [SECURITY.md](SECURITY.md) instead of opening a public issue.

## Testing other models and providers

The provider presets marked *untested* (Ollama, OpenAI, OpenRouter) follow each provider's documented
OpenAI-compatible API but have not been run against a real Zabbix. A report that one works (or does not), with
the model name and a chat log, helps a lot.

## Development

The module is plain PHP and JavaScript with no build step. The repository root is the module directory.

### Running the tests

The tests need no Zabbix installation. They start a mock Zabbix MCP server and a mock tool-calling LLM with PHP's
built-in web server.

Requirements: PHP 8.1+ with the `curl` extension, and for the browser tests Python 3 with
[Playwright](https://playwright.dev/python/) and Chromium (`pip install playwright && playwright install chromium`).

```bash
php tests/run.php              # backend: MCP client, trimming, loop, approvals, limits, prompts, presets
python3 tests/ui_test.py       # chat page in Chromium: progress, trace, approvals, links, reload, pop-out sizes
python3 tests/config_test.py   # configuration popup in Chromium: prompts, presets, validation, save
```

To also check the pop-out layout inside the real Zabbix theme CSS, point `ZBX_UI_DIR` to the `ui` folder of a
Zabbix 8.0 source tree or frontend installation:

```bash
ZBX_UI_DIR=/path/to/zabbix/ui python3 tests/ui_test.py
```

All three must pass before a pull request is merged. Screenshots of the browser tests are written to
`tests/screenshots/` (ignored by git).

### Design rules

These keep the module predictable. Please do not change them without an issue first:

- **The Zabbix MCP server is the only tool source.** The module never calls the Zabbix API itself to answer a
  question.
- **Pure AI.** The model does all the reasoning. The module may remove noise fields from tool results and format
  timestamps, but never adds, joins or derives data.
- **Secrets stay on the server.** The session ID is never sent to the browser or the LLM. API keys are
  write-only in the configuration popup and never sent back to the browser.
- **One backend request per step.** Each request performs one LLM call or one MCP call; the browser drives the
  loop.
- **Test prompt and loop changes on a small model.** A change to the built-in prompt or the loop can make small
  local models stop calling tools. Check it with a small model (e.g. Qwen3 0.6B) as well as a large one.

### Code style

Follow the Zabbix frontend style: tabs for indentation, lines up to 120 characters, `declare(strict_types = 0)`
and the license header at the top of every PHP file. The full design is in
[docs/specification.md](docs/specification.md).

### License

By contributing, you agree that your contribution is licensed under the
[GNU Affero General Public License v3.0](LICENSE), like the rest of the project.
