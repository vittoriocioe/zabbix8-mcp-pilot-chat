# LLM providers and models

MCP Pilot Chat works with any OpenAI-compatible Chat Completions API that supports **tool calling**. This page
lists the provider presets, the providers and models that were tested, and how to get good results from small
local models.

## Contents

- [Provider presets](#provider-presets)
- [Starter questions](#starter-questions)
- [Tested providers](#tested-providers)
- [What to expect from different models](#what-to-expect-from-different-models)
- [Small local models](#small-local-models)
- [Profile settings](#profile-settings)
- [Privacy](#privacy)
- [Troubleshooting](#troubleshooting)

## Provider presets

In **Configure → LLM profiles**, the **Preset** list offers known settings for common providers:

- **Add as new profile** creates a profile with the preset's settings and no API key. Enter the key, then click
  **Test connection**.
- **Apply to this profile** (after a confirmation) sets the connection and model settings of the selected profile:
  base URL, model, temperature, max tokens, context size, max model calls, default and maximum limit, timeout,
  extra parameters, *Require a tool call* and the kept result fields. It never changes the profile's ID, name,
  API key, allowed tools, prompt, starter prompts, user groups or write-tool approval.

| Preset | Base URL | Model | Status |
|---|---|---|---|
| Local small model (llama.cpp) | `http://demo-llm:8080/v1` | `qwen3-0.6b` | tested |
| Ollama | `http://ollama:11434/v1` | (choose one with tool calling) | untested |
| OpenAI | `https://api.openai.com/v1` | (choose one) | untested |
| Claude API (Anthropic) | `https://api.anthropic.com/v1` | `claude-haiku-5-5` | tested |
| Google Gemini | `https://generativelanguage.googleapis.com/v1beta/openai` | `gemini-flash-lite-latest` | tested |
| Groq | `https://api.groq.com/openai/v1` | `openai/gpt-oss-120b` | tested |
| OpenRouter | `https://openrouter.ai/api/v1` | (provider/model) | untested |

*Tested* means used with a real Zabbix 8.0 lab during development. *Untested* presets follow the provider's
documented OpenAI-compatible API; reports are welcome ([CONTRIBUTING.md](../CONTRIBUTING.md)).

Replace host names like `demo-llm` and `ollama` with the address of your server, as the Zabbix frontend reaches it.
Presets contain no API keys.

## Starter questions

Each preset brings starter questions, shown as buttons under the chat. They are added with
**Add as new profile**; **Apply to this profile** keeps the profile's own questions. To use them in an existing
profile, copy the lines into its *Starter prompts* field (one per line).

The questions match what the preset's model can do: one tool call for small models, two steps for larger ones.
*Linux servers* is a host group that exists in a default Zabbix installation; change it to one of yours.

| Preset | Starter questions |
|---|---|
| Local small model | Show current High and Disaster problems<br>Show the 5 latest problems<br>Are there unacknowledged Disaster problems? |
| Ollama | Show current High and Disaster problems<br>Show the 5 latest problems<br>Show problems on hosts in the Linux servers group |
| Groq | Show current High and Disaster problems<br>Summarize the 10 latest problems by severity<br>Show problems on hosts in the Linux servers group<br>Is any maintenance active right now? |
| Claude API | Summarize the current problems by severity, worst first<br>Which hosts have High or Disaster problems?<br>Show problems on hosts in the Linux servers group<br>Is any maintenance active right now? |
| OpenAI, Google Gemini, OpenRouter | Summarize the current problems by severity, worst first<br>Which problems are not acknowledged yet?<br>Show problems on hosts in the Linux servers group<br>Is any maintenance active right now? |

*Which hosts have High or Disaster problems?* is the hardest one: the MCP server returns problems without hosts,
so the model has to match trigger IDs with `host_get` (see [known limitations](../README.md#known-limitations)).
It worked with Claude Haiku in testing.

## Tested providers

| Provider | Notes |
|---|---|
| **llama.cpp** (local) | Start `llama-server` with `--jinja`: tool calling needs the model's chat template. For Qwen3, `--chat-template-kwargs '{"enable_thinking":false}'` turns off the long reasoning text (much faster on a CPU). A complete example: [lab-podman.md](lab-podman.md#2-local-llm-llamacpp--qwen3-06b). |
| **Claude API** (paid; an API key from the Anthropic console, separate from a Claude.ai subscription) | Uses Anthropic's OpenAI-compatible endpoint. Leave **Temperature** empty. Keep *Require a tool call* off. **Test connection** cannot list models for this provider; the chat works. |
| **Groq** (free tier available) | The free tier allows about 8,000 tokens per minute per model, and one question with several tool definitions can use most of it. Allow only the tools you need, and leave about a minute between questions. Max tokens 1024 and extra parameters `{"reasoning_effort": "low"}` keep the reasoning short. Rate limits (HTTP 429) are retried automatically when the wait is up to 30 s. |
| **Google Gemini** (free tier available) | Free-tier models are often busy (HTTP 503, retried automatically) and free-tier prompts may be used by Google to improve its products. Gemini 3 tool calls carry "thought signatures"; the module sends them back unchanged, as required. |

To see the models available to a key (Test connection shows them too, where the provider supports it):

```bash
curl -s <BASE_URL>/models -H "Authorization: Bearer <API_KEY>"
```

Copy the model ID exactly. For Gemini, leave out the `models/` prefix.

**Enter API keys only in the profile's API key field.** Never paste them into the chat, tickets or logs.

## What to expect from different models

The module behaves the same way with every model; the quality of the answers comes from the model.

| Model | Result in testing |
|---|---|
| Claude Haiku | The best results, including answers that need several tool calls. About half a US cent per question. |
| gpt-oss-120b (Groq) | Fast and accurate for questions that need one or two tool calls. Limited by the free tier's tokens per minute. |
| Gemini Flash-Lite | Worked, but the free tier was often busy. |
| Qwen3 0.6B (local, CPU) | Calls a tool and lists the results reliably. Miscounts, cannot compare severities or combine tools, and may copy an earlier answer from the conversation. |

Typical questions and what they need:

| Question | Tool calls | Small model |
|---|---|---|
| *Show current High and Disaster problems* | `problem_get` with severities | ✅ |
| *Show the 5 latest problems* | `problem_get` with a limit | ✅ |
| *Are there unacknowledged Disaster problems?* | `problem_get` with filters | ✅ |
| *Which hosts have the most problems?* | `problem_get` + `host_get`, then matching | ❌ |
| *Is anything in maintenance on the web servers?* | `hostgroup_get` + `maintenance_get` | ❌ |

## Small local models

A model of about 1B parameters or less can demonstrate the tool chain, with all data staying on your network. To
get the most out of it, use the **Local small model** preset, which sets:

- **One tool** (when added as a new profile): `problem_get`. Each tool definition costs context and attention.
- **Default limit 5, maximum limit 10:** short results that fit a 4k context.
- **Lean result fields:** only the fields needed to list and summarize, about half the tokens.
- **Require a tool call on the first step:** small models otherwise often answer without looking at Zabbix.
  **Test connection** checks that the server supports it.
- **Timeout 600 s:** on a small CPU one model call can take minutes. The web server timeout must be at least as
  long: see [installation.md](installation.md#web-server-timeout).
- **Starter prompts** that each need one tool call.

Other tips:

- **Start a new chat for each question.** A small model tends to repeat its earlier answer when the conversation
  already contains one.
- Ask simple, direct questions, like the starter prompts.
- Keep the module's default system prompt. Changes to the prompt affect small models a lot: test them before
  relying on them.

Measured on a heavily loaded lab VM (3.6 GB RAM, one CPU thread for the model), Qwen3 0.6B Q4_K_M with llama.cpp:

| Step | Tokens | Time |
|---|---|---|
| Tool call, first request | 151 prompt / 21 completion | ~14.5 s |
| Tool call, prompt cached | 3 new prompt / 21 completion | ~7 s |
| Answer from 5 trimmed problems | 452 prompt / 51 completion | ~64 s |

A complete question took about 1–1.5 minutes.

## Profile settings

The main settings of a profile (all fields: [specification.md](specification.md#llm-profiles)):

| Setting | Meaning |
|---|---|
| Base URL | The API up to `/v1` (OpenAI-compatible), as the frontend reaches it |
| API key | Sent as `Authorization: Bearer …`. Write-only: never shown again after saving |
| Model | The model ID, exactly as the provider lists it |
| Temperature | Empty = not sent. Some providers accept only their default |
| Max tokens per answer | Upper limit for each model reply |
| Context size | The model's context window, for the usage display |
| Max model calls per question | Stops runaway loops |
| Default limit / Maximum limit | The `limit` the model is told to use for tools, and the most it may ask for |
| Timeout | For each model call |
| Extra request parameters | JSON merged into each request, e.g. `{"reasoning_effort": "low"}` |
| Allowed tools | The MCP tools this profile may use (fewer helps small models and per-minute limits) |
| Require a tool call | Forces a tool call on the first model call of each question |
| Kept result fields | Fields kept per tool; everything else is removed before the result reaches the model |
| Write tools | Ask the user to approve every change (on by default) |

## Privacy

Tool results are sent to the configured LLM: problem, trigger and host names, operational data, and whatever else
the allowed tools return. With a cloud provider this data leaves your network, and the provider's terms apply
(some free tiers may use prompts to improve their products). Use a local model when the data must stay on your
network, and restrict cloud profiles to the user groups that may use them.

## Troubleshooting

| Error in the chat | Cause | Fix |
|---|---|---|
| "Rate limited, retrying in N s…", or "rate limit reached" | Provider rate limit (HTTP 429) | Retried automatically up to 3 times for waits up to 30 s. With Groq's free tier, allow fewer tools and wait between questions. |
| "Model busy, retrying in N s…", or "the model is overloaded at the provider" | Provider busy (HTTP 503) | Retried automatically; try again later or use another model |
| "this request needs N tokens, but the provider allows only M" | The request is larger than the provider's per-minute limit | Allow fewer tools, lower the default limit, use lean result fields, or start a new chat |
| "the API key was rejected" | Wrong, revoked or missing key | Enter the key again in the profile |
| "No answer: the model ran out of tokens" | A reasoning model used all *Max tokens* for thinking | Raise *Max tokens*, or lower the reasoning effort with extra parameters |
| HTTP 400 "temperature is deprecated for this model" | The model accepts only its default temperature | Leave **Temperature** empty |
| The model answers without calling a tool | Typical of small models | Turn on *Require a tool call*; start a new chat |
| Answers start with reasoning text and `</think>` | The model's thinking mode is on | For Qwen3 on llama.cpp: `--chat-template-kwargs '{"enable_thinking":false}'` |
