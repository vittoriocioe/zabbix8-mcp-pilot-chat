# Changelog

## 0.1.8

The prompt, the agent loop and result trimming are unchanged from 0.1.7.

- **Pop-out fits any window size.** In 0.1.7 a narrow pop-out was wider than its window: controls, answers and
  the question were cut off on the right, because the Zabbix page keeps a minimum width of 1200 px. In the pop-out
  only, the page now has no minimum width, the header controls wrap onto a second row, and the page heading and
  footer are left out (the window title names the page). The chat fills the window height at any size.
- **Status line in narrow pop-outs** (below 900 px): one line with the MCP connection, the tokens of this chat and
  the number of tools, instead of the side panel. **Details** opens the full side panel above the chat. Wider
  pop-outs keep the side panel.
- **The pop-out remembers its size.** The next *Pop out* opens at the size you last gave the window (kept in this
  browser); the first one opens at 760 × 900.
- **Starter questions for every provider preset** (added with *Add as new profile*): one-tool questions for
  Ollama, two-step questions such as *Show problems on hosts in the Linux servers group* for the larger models,
  and a host question for Claude. *Apply to this profile* still keeps a profile's own starter questions.
- Long words and IDs in messages wrap instead of widening the chat.
- **Documentation:** a *Support* section states that this is a personal project maintained on a best-effort
  basis, without a commitment to fixes or updates. The same note is in the contributing and security guides.

Upgrading: nothing to do. To get the new starter questions in an existing profile, copy them from
[docs/llm-providers.md](docs/llm-providers.md#starter-questions) into the profile's *Starter prompts*.

## 0.1.7

The prompt, the agent loop and result trimming are unchanged from 0.1.6.

- **Pop out:** a *Pop out* button opens the chat in its own window, without the Zabbix menu, so it stays open
  while you use Zabbix in the main window. The conversation moves into the new window; Zabbix links in answers
  open in the main window. The main chat page shows "The chat is open in a separate window" with *Show window*
  and *Pop in*. **Pop in** (in either window) moves the chat back; it waits while an answer is running.
  Closing the pop-out also brings the chat back, and after every change the pop-out saves the conversation in the
  main window too, so nothing is lost. If the main window was closed, *Pop in* turns the pop-out into the normal
  chat page. Opening the pop-out changes only that window: the user's own layout setting is not touched.
- **Provider presets** in *Configure → LLM profiles*: Local small model (llama.cpp), Ollama, OpenAI, Claude API,
  Google Gemini, Groq and OpenRouter, each marked *tested* or *untested*. **Add as new profile**, or **Apply to
  this profile** (asks first). Applying sets the URL, model, limits, timeouts and kept result fields; it never
  changes the ID, name, API key, allowed tools, prompt, starter prompts, user groups or write-tool approval.
  Presets contain no API keys.
- **Demo defaults for fresh installs:** the demo profile is now *Local demo* with default limit 5, maximum limit
  10, timeout 600 s, lean result fields, *Require a tool call* on and three one-tool starter prompts. Existing
  installations keep their stored configuration; apply the *Local small model* preset to get the same values.
- **"Export JSON" is now "Download chat log"** (same JSON content: questions, answers, every model and MCP call,
  profile, model and usage). The file is named `mcp-pilot-chat-log-<date>.json`.
- Automatic retries after a rate limit now accept provider waits up to **30 s** (was 20 s), e.g. Groq's
  "try again in 21 s".
- Fixed: after a page reload, the restored answers were labelled "[object Object]" instead of
  "profile - model", and the chat log had an empty profile for each answer.

- **Published as open source** under the AGPL-3.0 license. The documentation is reorganized for new users:
  [installation](docs/installation.md), [LLM providers](docs/llm-providers.md) and the
  [Podman lab example](docs/lab-podman.md) (which replaces `docs/deployment.md`), plus
  [contributing](CONTRIBUTING.md) and [security](SECURITY.md) guides.

Upgrading: the web server timeout must be at least as long as the profile timeout (600 s for the
*Local small model* preset); see [docs/installation.md](docs/installation.md#web-server-timeout).

## 0.1.6

Display changes only, on top of 0.1.4: the prompt, the agent loop, retries and result trimming are unchanged.
(0.1.5 changed the built-in prompt and the loop; it made the 0.6B demo model stop calling tools and was
withdrawn. 0.1.6 starts again from 0.1.4.)

- **Usage** card in the side panel, plain numbers: tokens sent and received for this question and this chat, and
  the context size of the last request (with "start a new chat soon" above 80%). Replaces the Context meter.
- **Removed: token budget** (the side-panel card, the waiting before model calls, the *Tokens per minute* profile
  field and the reading of rate-limit headers). Automatic retries after HTTP 429/503 stay as in 0.1.4.
- **Compact MCP server card:** one status line (connected, latency, Zabbix version, identity) and one small line
  (server and URL); Refresh is a ⟳ icon. The tool count moves to the Tools title, and the Tools card takes the
  remaining height of the side panel.
- **Truncated MCP results are marked** in the trace: "truncated at 10, cannot be paged".
- **"Profile - model"** in the profile list and on every answer.
- **Warning in the MCP card** when the profile can run write tools without approval.
- Export JSON includes the profile and model of each answer and the token usage.

Upgrading from 0.1.4 or 0.1.5: the stored configuration is kept (*Tokens per minute* is dropped; settings added by
0.1.5 are ignored). If you edited the module default prompt while testing, check it on the Prompts tab.

## 0.1.4

- **Token budget:** the chat waits *before* a model call that would exceed the provider's tokens-per-minute
  limit ("Waiting for Groq token budget… 12 s"), instead of failing with HTTP 429. The budget comes from the
  provider's rate-limit headers (`x-ratelimit-*`: OpenAI, Groq, Mistral, OpenRouter…; `anthropic-ratelimit-*`)
  or, for providers that send none (Gemini), from the new profile field **Tokens per minute** and the tokens
  used in the last minute. New **Token budget** meter in the side panel.
- **Maximum limit** per profile (default 50, 0 = none): a tool call asking for more results is refused and the
  model is told the maximum, so it asks again with less. The built-in prompt states it (`{{max_limit}}`).
- **Operational data kept:** `opdata` ("Current utilization: 98.3 %") is now a default and lean result field
  for problem_get, trigger_get and host_get triggers, and is dropped when empty.
- **Tool calls are sent back exactly as the provider returned them**, with any extra fields it attached
  (Gemini 3 thought signatures, provider ids). Fixes HTTP 400 "Function call is missing a thought_signature"
  and works the same way for other providers.
- **Automatic retry** when the LLM provider is rate limited (HTTP 429, e.g. Groq "try again in 1.7s") or
  overloaded (HTTP 503, e.g. Gemini "high demand"): live countdown ("Rate limited, retrying in 2 s…" /
  "Model busy, retrying in 5 s…"), up to 3 retries for waits up to 20 s, each listed in the trace.
- **Readable provider errors:** a request larger than a per-minute limit (HTTP 413) explains the numbers and
  what to change; a rejected API key is named as such.
- **Out of tokens:** when the model stops at "Max tokens per answer" (`finish_reason: length`), a warning
  explains it instead of "(The model returned an empty answer.)".
- **Model reasoning in the trace** when the provider returns it (Groq/gpt-oss `reasoning`, `reasoning_content`,
  `<think>` and Gemma's `<thought>` blocks). Reasoning blocks are removed from the answer.
- **Switching profile starts a new chat** (after confirmation), so a conversation is never sent to another model.
- **Temperature is optional:** empty = not sent, for providers that reject other values.
- **Required fields marked** with a red asterisk (MCP server URL and timeout; profile ID, Base URL, and for
  Generic HTTP the request template) and checked before saving: the popup opens the tab and profile with the
  problem and highlights the field. The server checks the MCP timeout too.
- The chat page's profile picker is now labelled **LLM profile**.

Upgrading: the stored configuration is kept. Existing profiles get *Maximum limit* 50 and *Tokens per minute*
0 (learn from headers). Custom *Kept result fields* are not changed: add `opdata` there if you want it.

## 0.1.3

- Fixed: texts in the configuration popup were cut on the right. Zabbix popups disable line wrapping and hide
  horizontal overflow; the popup now restores wrapping and its columns shrink to the popup width.

## 0.1.2

- **Editable system prompts on two levels:** module default prompt (new *Prompts* tab) and per-profile
  *Use the module default prompt* / *Custom prompt*, with *Reset to built-in* / *Reset to module default*
  and a token estimate. Profiles saved by 0.1.x with a prompt become *Custom*.
- **Improved built-in prompt:** always call a tool before answering about Zabbix data; `count` is not the total;
  report truncated results as "showing the latest N".
- **Require a tool call on the first step** (per profile, off by default, on in the demo profile):
  `tool_choice: "required"` on the first model call of each question. *Test connection* verifies support.
- **Richer trace:** tools offered, messages sent, and *read N tokens in X s · wrote M in Y s* per model call.
- **Lean preset** for kept result fields; the long `note` of truncated results is no longer sent to the model.
- **The current chat survives page reloads** (sessionStorage, same tab). *New chat* clears it.

Upgrading an existing installation: the stored configuration is kept. To use the new options for the demo
profile, open *Configure → LLM profiles* and enable *Require a tool call on the first model call of each
question*, and optionally click *Lean preset for small models*.

## 0.1.1

- Fixed "Invalid request." on every chat message: the step field is now `op` (Zabbix routes on `action`).

## 0.1.0

- First version.
