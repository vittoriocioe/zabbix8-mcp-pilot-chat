/* MCP Pilot Chat · Copyright (C) 2026 Vittorio Cioe · SPDX-License-Identifier: AGPL-3.0-only */

/*
 * Pop-out window only (included by the chat view when popout=1). Adjusts the Zabbix page around the chat so it
 * fits a small window: no minimum page width, a header whose controls wrap, no page heading (the window title
 * names the page) and no page footer.
 */
:root { --min-content-size: 0px; }

header { height: auto; min-height: var(--header-size); }
header .header-title { height: auto; min-height: 45px; padding: 4px 10px; flex-wrap: wrap; }
header .header-title > .overflow-ellipsis { display: none; }
header .header-controls { flex-shrink: 1; min-width: 0; margin-left: 0; white-space: normal; }
/* Only the header's own row of controls (child selectors): lists inside the controls, such as the options of the
   profile dropdown, keep their Zabbix styles. */
header .header-controls > nav > ul { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; text-align: left; }
header .header-controls > nav > ul > li { padding: 0; }
header .header-controls z-select { max-width: 100%; }

.wrapper > footer { display: none; }

@media (max-width: 899px) {
	.mcppilot-titlebar { display: none; }
}
