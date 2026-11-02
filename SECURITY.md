# Security policy

## Reporting a vulnerability

Please do not open a public issue for security problems. Use GitHub's
[private vulnerability reporting](https://github.com/vittoriocioe/zabbix8-mcp-pilot-chat/security/advisories/new)
for this repository instead. Include the module version, what an attacker can do, and the steps to reproduce.

This is a personal project maintained on a best-effort basis. Reports are read and taken seriously, but there is
no guaranteed response time and no commitment to provide a fix or a release. If a report is confirmed, it will be
disclosed together with a fix or a workaround when one is available, so that users can protect themselves.

Vulnerabilities in Zabbix itself or in the Zabbix MCP server should be reported to Zabbix SIA, not here.

## Supported versions

Fixes, if any, are made only to the latest release.

## What the module protects

| Asset | Protection |
|---|---|
| Zabbix session ID | Used only server-side as the Bearer token for the MCP server. Never sent to the browser or the LLM. |
| LLM API keys | Stored in the module configuration in the Zabbix database (not encrypted). Write-only in the configuration popup, never sent back to the browser. Only Super admins can change them. |
| Conversation state | Kept by the browser, signed with an HMAC bound to the user's session, so it cannot be changed to inject tool calls or approvals. |
| Zabbix permissions | Every MCP call runs with the logged-in user's session and permissions, and appears in the Zabbix audit log under that user. |
| Write tools | Each call needs the user's approval unless a Super admin turns approval off for a profile. Deny write tools on the MCP server unless you need them. |

## Things to be aware of

- **Data sent to the LLM.** Tool results (problem, trigger and host names, and whatever else the allowed tools
  return) are sent to the configured LLM. With a cloud provider, this data leaves your network. Use a local model
  if that is not acceptable.
- **Prompt injection.** Tool results can contain text from monitored systems (problem names, operational data,
  host descriptions). A model can be influenced by that text. Keep write tools denied on the MCP server, or keep
  approval on, so that a manipulated model cannot change Zabbix without a person confirming it.
- **API keys in the database.** Anyone with access to the Zabbix database or its backups can read the stored
  keys. Use keys with the lowest privileges and spending limits your provider offers.
