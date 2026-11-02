# Installation

MCP Pilot Chat needs three things: the **Zabbix MCP server**, the **module** in the Zabbix frontend, and an
**LLM** with tool calling. This guide covers the first two and the settings that connect them. Choosing and
configuring the LLM is covered in [llm-providers.md](llm-providers.md). A complete working lab with Podman is
in [lab-podman.md](lab-podman.md).

## Contents

- [1. Zabbix MCP server](#1-zabbix-mcp-server)
- [2. Install the module](#2-install-the-module)
- [3. Enable and configure](#3-enable-and-configure)
- [Network](#network)
- [Web server timeout](#web-server-timeout)
- [Upgrading](#upgrading)
- [Uninstalling](#uninstalling)
- [Troubleshooting](#troubleshooting)

## 1. Zabbix MCP server

MCP Pilot Chat uses the official Zabbix MCP server (Streamable HTTP transport) as its only tool source. Use the
same Zabbix version as the frontend. Refer to the Zabbix documentation for all of its options; these are the ones
that matter for the module, as used with the official container image:

| Setting | Value |
|---|---|
| `ZBX_FRONTEND_URL` | The frontend's API URL as the MCP server reaches it, ending with `api_jsonrpc.php`, e.g. `http://zabbix-frontend:8080/api_jsonrpc.php` |
| `ZBX_ALLOWEDIP` | The address of the Zabbix frontend (IP, subnet or DNS name). Requests from other addresses get HTTP 403. |
| `ZBX_ALLOWTOOL_<n>` / `ZBX_DENYTOOL_<n>` | Which tools are exposed. Rules are numbered from 0, with no gaps or duplicates; the first matching rule wins. |

A read-only setup that exposes five tools and denies everything else, including all write tools:

```ini
ZBX_ALLOWTOOL_0=problem_get
ZBX_ALLOWTOOL_1=trigger_get
ZBX_ALLOWTOOL_2=host_get
ZBX_ALLOWTOOL_3=hostgroup_get
ZBX_ALLOWTOOL_4=maintenance_get
ZBX_DENYTOOL_5=*
```

Expose `item_get` and `history_get` only if the model should see metric values: Zabbix denies them by default
because item keys and history can contain sensitive data. Expose write tools (acknowledge, close, maintenance
changes) only if you need them; the module then asks the user to approve each call.

Example with Docker (use your network and frontend name):

```bash
docker run -d --name zabbix-mcp-server --network zabbix-net \
  -e ZBX_FRONTEND_URL=http://zabbix-frontend:8080/api_jsonrpc.php \
  -e ZBX_ALLOWEDIP=zabbix-frontend \
  -e ZBX_ALLOWTOOL_0=problem_get -e ZBX_ALLOWTOOL_1=trigger_get -e ZBX_ALLOWTOOL_2=host_get \
  -e ZBX_ALLOWTOOL_3=hostgroup_get -e ZBX_ALLOWTOOL_4=maintenance_get -e ZBX_DENYTOOL_5='*' \
  zabbix/zabbix-mcp-server:<version>
```

The server listens on port 8443 with the endpoint `/mcp`. Its log should end with a line like
`MCP endpoint available at http://0.0.0.0:8443/mcp, 5 tools exposed`.

**No token is needed for the module.** MCP Pilot Chat sends the logged-in user's frontend session ID as the
Bearer token, so each call runs with that user's permissions. To check this by hand, see
[lab-podman.md](lab-podman.md#verify-session-authentication).

## 2. Install the module

The repository root is the module folder. It goes into the `modules` folder of the Zabbix frontend: the folder
next to `zabbix.php` (in the official container images, `/usr/share/zabbix/modules`). If you are not sure where
the frontend is installed:

```bash
find / -name zabbix.php -not -path '*/proc/*' 2>/dev/null
```

### Frontend installed from packages or source

```bash
cd <frontend>/modules
git clone https://github.com/vittoriocioe/zabbix8-mcp-pilot-chat.git mcp-pilot-chat
```

Or download `mcp-pilot-chat-<version>.zip` from the
[releases](https://github.com/vittoriocioe/zabbix8-mcp-pilot-chat/releases) and unpack it there, so that
`<frontend>/modules/mcp-pilot-chat/manifest.json` exists. The release archive contains only the files Zabbix
needs, plus the README, license and changelog. The web server user needs read access:
`chmod -R a+rX mcp-pilot-chat`.

In a cloned repository, the `docs/`, `tests/`, `deploy/` and `.github/` folders are not used at runtime and can be
removed.

### Containerized frontend

Keep the module on the host and mount it into the frontend container, read-only:

```bash
# Docker
docker run ... -v /opt/zabbix/modules/mcp-pilot-chat:/usr/share/zabbix/modules/mcp-pilot-chat:ro ...
```

```ini
# Podman Quadlet, in the [Container] section of the frontend's .container file
Volume=/opt/zabbix/modules/mcp-pilot-chat:/usr/share/zabbix/modules/mcp-pilot-chat:ro,Z
```

Recreate or restart the frontend container after adding the mount, then check it:

```bash
docker exec <frontend-container> ls /usr/share/zabbix/modules/mcp-pilot-chat
```

## 3. Enable and configure

1. In Zabbix, open **Administration → General → Modules**, click **Scan directory**, and enable
   **MCP Pilot Chat**.
2. Open **Monitoring → MCP Pilot Chat (ext)** and click **Configure**. Only Super admins can configure the module.
3. **MCP server** tab: the URL as the frontend reaches it, e.g. `http://zabbix-mcp-server:8443/mcp`.
   Click **Test connection**.
4. **LLM profiles** tab: choose a **preset** for your provider and click **Add as new profile**, or edit the
   example profile *Local demo*. Enter the API key if needed and click **Test connection**. Details per provider:
   [llm-providers.md](llm-providers.md).
5. **Access & UI** tab: the user groups that may use the chat (none selected = all users; Super admins always
   can), the default profile and whether debug details are shown by default.
6. Click **Save**. The configuration is stored in the Zabbix database.

Each profile can also be limited to some user groups, for example to offer a cloud model only to some teams.

## Network

The **frontend** (the PHP side, not the user's browser) connects to both the MCP server and the LLM. Use addresses
that work from the frontend: in containers, the container names on the shared network, not `localhost`.

The **MCP server** connects back to the frontend's API (`ZBX_FRONTEND_URL`).

The browser only talks to the Zabbix frontend.

## Web server timeout

Each chat step is one HTTP request from the browser that waits for one LLM call. A slow model (a small model on a
small CPU can take minutes for one call) needs a web server timeout **at least as long as the profile's
Timeout**. Otherwise the step fails with HTTP 504 from the web server instead of an answer.

| Web server | Setting |
|---|---|
| nginx with PHP-FPM (official `zabbix-web-nginx-*` images) | `fastcgi_read_timeout` |
| Apache with PHP-FPM | `ProxyTimeout` (or `Timeout`) |

Check the current value in a container:

```bash
docker exec <frontend-container> grep -r fastcgi_read_timeout /etc/nginx
```

Either raise the web server timeout or lower the profile's **Timeout**. The example profile and the
*Local small model* preset use 600 s; cloud presets use 60–120 s.

PHP's `max_execution_time` (`ZBX_MAXEXECUTIONTIME` in the official images, 300 s by default) does not need
raising on Linux, where time spent waiting for the LLM's reply does not count towards it.

## Upgrading

Replace the module files with the new version and reload the chat page. The configuration is stored in the Zabbix
database and is kept. For a cloned repository:

```bash
cd <frontend>/modules/mcp-pilot-chat && git pull
```

For an unpacked archive, delete the old files first so no removed file stays behind:

```bash
cd <frontend>/modules && rm -rf mcp-pilot-chat/* && unzip -o mcp-pilot-chat-<version>.zip && chmod -R a+rX mcp-pilot-chat
```

New defaults in a release apply to fresh installs only. Your stored profiles keep their values; apply a preset
to a profile to pick up new recommended settings. Read the [changelog](../CHANGELOG.md) before upgrading.

## Uninstalling

Disable **MCP Pilot Chat** in **Administration → General → Modules**, then remove the module folder (or the
container mount).

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| No **MCP Pilot Chat (ext)** in the Monitoring menu | Module not enabled, or the user is not in an allowed group | Enable it in *Administration → General → Modules*; check *Configure → Access & UI* |
| "The MCP server does not allow connections from this frontend" (HTTP 403) | The frontend's address is not in `ZBX_ALLOWEDIP` | Add the frontend's IP, subnet or DNS name |
| "The MCP server rejected the request: no or invalid credentials" (HTTP 401) | The session ID was not accepted | Check `ZBX_FRONTEND_URL` on the MCP server; log out and in again |
| "Your Zabbix session has expired" | The frontend session ended | Log in again |
| MCP server card shows a connection error | Wrong URL, or the frontend cannot reach the server | Use the URL as seen from the frontend (container name, port 8443, path `/mcp`) |
| MCP server exits with "index N is used by both …" | Duplicate tool-rule number | Number the rules from 0 with no gaps or duplicates |
| A tool shows red ("not exposed") in the Tools card | Denied on the MCP server | Allow it with a `ZBX_ALLOWTOOL_<n>` rule |
| A tool shows yellow ("off in profile") | Not allowed in this profile | *Configure → LLM profiles → Allowed tools* |
| A step fails with HTTP 504 | Web server timeout shorter than the LLM call | See [Web server timeout](#web-server-timeout) |
| "… timed out" in the chat | The LLM took longer than the profile's Timeout | Raise the Timeout (and the web server timeout), or use a faster model |
| "The conversation is no longer valid for this session. Start a new chat." | A chat restored after logging in again | Click **New chat** |
| LLM errors (rate limit, overloaded, key rejected, request too large) | Provider limits | See [llm-providers.md](llm-providers.md#troubleshooting) |
| **Pop out** says the browser blocked the window | Pop-ups are blocked for the Zabbix site | Allow pop-ups for the Zabbix site |

The chat's trace shows the exact error of each failed step. **Download chat log** saves it, for a bug report.
