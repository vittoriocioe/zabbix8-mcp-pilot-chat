/* MCP Pilot Chat · Copyright (C) 2026 Vittorio Cioe · SPDX-License-Identifier: AGPL-3.0-only */
/* MCP Pilot Chat - external module. Neutral colors so it follows every Zabbix theme. */

.mcppilot-titlebar {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 8px;
	font-size: 12px;
	opacity: .9;
}

.mcppilot-badge {
	display: inline-block;
	padding: 2px 8px;
	border: 1px solid currentColor;
	border-radius: 10px;
	font-size: 11px;
	opacity: .75;
}
.mcppilot-version {
	font-size: 11px;
	opacity: .6;
	font-variant-numeric: tabular-nums;
}

.mcppilot-layout {
	display: flex;
	gap: 12px;
	align-items: stretch;
	height: calc(100vh - 200px);
	min-height: 480px;
}
.mcppilot-chat {
	flex: 2 1 0;
	display: flex;
	flex-direction: column;
	min-width: 0;
	border: 1px solid rgba(128, 128, 128, .3);
	border-radius: 4px;
}
.mcppilot-side {
	flex: 1 1 0;
	display: flex;
	flex-direction: column;
	gap: 10px;
	min-width: 260px;
	overflow-y: auto;
}
@media (max-width: 900px) {
	.mcppilot-layout { flex-direction: column; height: auto; }
	.mcppilot-chat { height: 70vh; }
}

/* Messages */
.mcppilot-messages {
	flex: 1 1 auto;
	overflow-y: auto;
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}
.mcppilot-empty {
	margin: auto;
	text-align: center;
	opacity: .65;
	max-width: 420px;
	line-height: 1.6;
}
.mcppilot-msg {
	max-width: 88%;
	padding: 8px 12px;
	border-radius: 6px;
	line-height: 1.5;
	overflow-wrap: anywhere;
}
.mcppilot-msg-user {
	align-self: flex-end;
	background: rgba(64, 128, 224, .16);
	white-space: pre-wrap;
}
.mcppilot-msg-assistant {
	align-self: flex-start;
	background: rgba(128, 128, 128, .1);
	min-width: 280px;
}
.mcppilot-msg-meta {
	font-size: 11px;
	opacity: .6;
	margin-bottom: 4px;
}

/* Live status: thinking animation + timers */
.mcppilot-live {
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: 12px;
}
.mcppilot-live[hidden] { display: none; }
.mcppilot-dots {
	display: inline-flex;
	gap: 4px;
}
.mcppilot-dots span {
	width: 7px;
	height: 7px;
	border-radius: 50%;
	background: currentColor;
	opacity: .25;
	animation: mcppilot-bounce 1.2s infinite ease-in-out;
}
.mcppilot-dots span:nth-child(2) { animation-delay: .15s; }
.mcppilot-dots span:nth-child(3) { animation-delay: .3s; }
@keyframes mcppilot-bounce {
	0%, 80%, 100% { transform: translateY(0); opacity: .25; }
	40% { transform: translateY(-5px); opacity: 1; }
}
.mcppilot-live-label {
	background: linear-gradient(90deg, currentColor 0%, rgba(128, 128, 128, .5) 50%, currentColor 100%);
	background-size: 200% 100%;
	-webkit-background-clip: text;
	background-clip: text;
	-webkit-text-fill-color: transparent;
	animation: mcppilot-shimmer 2s linear infinite;
}
@keyframes mcppilot-shimmer {
	from { background-position: 200% 0; }
	to { background-position: 0 0; }
}
.mcppilot-timer {
	margin-left: auto;
	font-variant-numeric: tabular-nums;
	opacity: .75;
	white-space: nowrap;
}
.mcppilot-timer-step { opacity: .55; }

@media (prefers-reduced-motion: reduce) {
	.mcppilot-dots span, .mcppilot-live-label { animation: none; }
	.mcppilot-dots span { opacity: .6; }
	.mcppilot-live-label { -webkit-text-fill-color: currentColor; background: none; }
}

/* Completed summary under an answer */
.mcppilot-done {
	margin-top: 6px;
	font-size: 11px;
	opacity: .7;
	font-variant-numeric: tabular-nums;
}
.mcppilot-done.mcppilot-failed, .mcppilot-done.mcppilot-stopped { opacity: 1; }

/* Steps (debug details) */
.mcppilot-steps {
	display: none;
	margin: 6px 0;
	padding-left: 10px;
	border-left: 2px solid rgba(128, 128, 128, .35);
	font-size: 11px;
}
.mcppilot-debug-on .mcppilot-steps { display: block; }
.mcppilot-step {
	padding: 2px 0;
}
.mcppilot-step summary {
	cursor: pointer;
	list-style-position: outside;
}
.mcppilot-step-time {
	font-variant-numeric: tabular-nums;
	opacity: .7;
	margin-left: 6px;
}
.mcppilot-step pre, .mcppilot-answer pre {
	max-height: 240px;
	overflow: auto;
	margin: 4px 0;
	padding: 6px 8px;
	background: rgba(128, 128, 128, .12);
	border-radius: 3px;
	white-space: pre-wrap;
	word-break: break-all;
	font-size: 11px;
}
.mcppilot-tag {
	display: inline-block;
	padding: 0 5px;
	border-radius: 3px;
	background: rgba(128, 128, 128, .18);
	font-size: 10px;
	margin-right: 4px;
}

/* Answer body (rendered markdown) */
.mcppilot-answer p { margin: 0 0 6px; }
.mcppilot-answer ul, .mcppilot-answer ol { margin: 0 0 6px 18px; padding: 0; }
.mcppilot-answer li { margin: 2px 0; }
.mcppilot-answer h1, .mcppilot-answer h2, .mcppilot-answer h3 { font-size: 13px; margin: 6px 0 4px; }
.mcppilot-answer code {
	padding: 0 3px;
	background: rgba(128, 128, 128, .15);
	border-radius: 3px;
}
.mcppilot-answer table { border-collapse: collapse; margin: 4px 0 8px; }
.mcppilot-answer th, .mcppilot-answer td {
	border: 1px solid rgba(128, 128, 128, .35);
	padding: 2px 6px;
	text-align: left;
}

/* Approval card */
.mcppilot-approval {
	margin: 6px 0;
	padding: 8px;
	border: 1px solid rgba(224, 160, 32, .7);
	border-radius: 4px;
	background: rgba(224, 160, 32, .08);
}
.mcppilot-approval-buttons { margin-top: 6px; display: flex; gap: 6px; }

.mcppilot-error {
	margin-top: 6px;
	padding: 6px 8px;
	border-left: 3px solid #e45959;
	background: rgba(228, 89, 89, .1);
}

/* Starters + composer */
.mcppilot-starters {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	padding: 0 12px 6px;
}
.mcppilot-starters button {
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 11px;
}
.mcppilot-composer {
	display: flex;
	gap: 8px;
	padding: 8px 12px 12px;
	border-top: 1px solid rgba(128, 128, 128, .3);
}
.mcppilot-input {
	flex: 1 1 auto;
	resize: vertical;
	min-height: 48px;
	width: 100% !important;
	box-sizing: border-box;
}
.mcppilot-composer-buttons {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

/* Side panel */
.mcppilot-card {
	padding: 10px 12px;
	border: 1px solid rgba(128, 128, 128, .3);
	border-radius: 4px;
}
.mcppilot-side-title {
	margin: 0 0 8px;
	font-size: 12px;
	font-weight: bold;
	text-transform: uppercase;
	letter-spacing: .04em;
	opacity: .8;
}
.mcppilot-status { line-height: 1.5; font-size: 12px; }
.mcppilot-status-sub { font-size: 11px; opacity: .6; word-break: break-all; }

/* Title row: name left, small action or count right. */
.mcppilot-side-title { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.mcppilot-side-count { font-weight: normal; text-transform: none; letter-spacing: 0; }
.mcppilot-side-title .mcppilot-refresh { font-size: 14px; line-height: 1; padding: 0 2px; text-transform: none; }

/* Usage: plain numbers, label left, value right. */
.mcppilot-usage-row {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	font-size: 12px;
	line-height: 1.7;
	font-variant-numeric: tabular-nums;
}
.mcppilot-usage-label { opacity: .7; white-space: nowrap; }
.mcppilot-usage-row > span:last-child { text-align: right; }
.mcppilot-usage-row .mcppilot-warn-text { color: #e8b437; }

/* The tools card takes the remaining height; its list scrolls. */
.mcppilot-card-tools { flex: 1 1 auto; min-height: 160px; display: flex; flex-direction: column; }
.mcppilot-card-tools .mcppilot-tools { flex: 1 1 auto; min-height: 0; overflow-y: auto; }

/* A truncated MCP result: it cannot be paged. */
.mcppilot-truncated {
	display: inline-block;
	margin-left: 6px;
	padding: 0 5px;
	border-radius: 3px;
	font-size: 11px;
	background: rgba(232, 180, 55, .25);
}
.mcppilot-status-head { font-weight: bold; }
.mcppilot-legend { font-size: 11px; opacity: .8; margin-bottom: 6px; }
.mcppilot-tools { display: flex; flex-direction: column; gap: 2px; }
.mcppilot-tool {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 12px;
	padding: 1px 0;
}
.mcppilot-tool-name { flex: 1 1 auto; font-family: monospace; }
.mcppilot-tool-missing .mcppilot-tool-name { opacity: .55; }
.mcppilot-rw {
	font-size: 10px;
	padding: 0 4px;
	border-radius: 3px;
	border: 1px solid rgba(128, 128, 128, .5);
	opacity: .8;
}
.mcppilot-dot { font-size: 12px; }
.mcppilot-dot-enabled { color: #59db8f; }
.mcppilot-dot-disabled { color: #e8b437; }
.mcppilot-dot-missing { color: #e45959; }
.mcppilot-meter {
	height: 8px;
	border-radius: 4px;
	background: rgba(128, 128, 128, .2);
	overflow: hidden;
}
.mcppilot-meter-bar {
	height: 100%;
	width: 0;
	background: #59db8f;
	transition: width .3s ease;
}
.mcppilot-meter-bar.mcppilot-warn { background: #e8b437; }
.mcppilot-meter-bar.mcppilot-full { background: #e45959; }
.mcppilot-meter-text { font-size: 11px; margin-top: 4px; opacity: .8; font-variant-numeric: tabular-nums; }
.mcppilot-footer { font-size: 11px; opacity: .55; line-height: 1.6; padding: 0 2px; }

/* Configuration popup */
.mcppilot-cfg-tabs { display: flex; gap: 4px; margin-bottom: 10px; border-bottom: 1px solid rgba(128, 128, 128, .3); }
.mcppilot-cfg-tabs button {
	border: 0;
	background: none;
	padding: 6px 12px;
	cursor: pointer;
	color: inherit;
	border-bottom: 2px solid transparent;
}
.mcppilot-cfg-tabs button.mcppilot-active { border-bottom-color: currentColor; font-weight: bold; }
.mcppilot-cfg-panel[hidden] { display: none; }
.mcppilot-cfg-grid {
	display: grid;
	grid-template-columns: 190px 1fr;
	gap: 8px 12px;
	align-items: start;
}
.mcppilot-cfg-grid label { padding-top: 4px; text-align: right; }
.mcppilot-cfg-grid input[type=text], .mcppilot-cfg-grid input[type=password], .mcppilot-cfg-grid input[type=number],
.mcppilot-cfg-grid select, .mcppilot-cfg-grid textarea { width: 100%; box-sizing: border-box; }
.mcppilot-cfg-grid textarea { min-height: 60px; font-family: monospace; font-size: 11px; }
.mcppilot-cfg-hint { font-size: 11px; opacity: .65; margin-top: 2px; }
.mcppilot-cfg-profiles { display: flex; gap: 12px; }
.mcppilot-cfg-list { width: 180px; flex: 0 0 auto; display: flex; flex-direction: column; gap: 4px; }
.mcppilot-cfg-list .mcppilot-cfg-item {
	text-align: left;
	padding: 6px 8px;
	border: 1px solid rgba(128, 128, 128, .3);
	border-radius: 3px;
	background: none;
	color: inherit;
	cursor: pointer;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.mcppilot-cfg-list .mcppilot-cfg-item.mcppilot-active { border-color: currentColor; font-weight: bold; }
.mcppilot-cfg-editor { flex: 1 1 auto; min-width: 0; }
.mcppilot-cfg-test { margin-top: 4px; font-size: 11px; }
.mcppilot-cfg-groups { max-height: 220px; overflow: auto; border: 1px solid rgba(128, 128, 128, .3); padding: 6px; }
.mcppilot-cfg-groups label { display: block; text-align: left; padding: 1px 0; }
.mcppilot-cfg-section { grid-column: 1 / -1; font-weight: bold; margin-top: 8px; opacity: .8; }

.mcppilot-cfg-prompt-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 4px; }
.mcppilot-cfg-prompt-bar .mcppilot-cfg-hint { margin: 0 0 0 4px; }
.mcppilot-cfg-inner > label { display: none; }
.mcppilot-cfg-inner textarea { width: 100%; box-sizing: border-box; font-family: monospace; font-size: 11px; }
.mcppilot-cfg-preview {
	margin: 6px 0 2px;
	max-height: 160px;
	overflow: auto;
	padding: 6px 8px;
	white-space: pre-wrap;
	font-size: 11px;
	opacity: .75;
	background: rgba(128, 128, 128, .1);
	border-radius: 3px;
}

/*
 * Zabbix popups use "white-space: nowrap" and hide horizontal overflow in the dialogue body,
 * so long hints and texts would be cut on the right. Restore normal wrapping and let columns shrink.
 */
.mcppilot-config {
	white-space: normal;
	max-width: 100%;
	overflow-wrap: anywhere;
}
.mcppilot-config *, .mcppilot-config *::before, .mcppilot-config *::after { box-sizing: border-box; }
.mcppilot-config .mcppilot-cfg-grid { grid-template-columns: 170px minmax(0, 1fr); }
.mcppilot-config .mcppilot-cfg-grid > * { min-width: 0; }
.mcppilot-config .mcppilot-cfg-profiles { min-width: 0; }
.mcppilot-config .mcppilot-cfg-list { width: 160px; }
.mcppilot-config label, .mcppilot-config .mcppilot-cfg-hint, .mcppilot-config .mcppilot-cfg-test { white-space: normal; }
.mcppilot-config input[type=text], .mcppilot-config input[type=password], .mcppilot-config select,
.mcppilot-config textarea { max-width: 100%; }
.mcppilot-config .mcppilot-cfg-tabs { flex-wrap: wrap; }
.mcppilot-config .mcppilot-cfg-preset {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	padding: 8px 10px;
	margin-bottom: 10px;
	border: 1px solid rgba(128, 128, 128, .3);
	border-radius: 4px;
}
.mcppilot-config .mcppilot-cfg-preset select { max-width: 100%; }
.mcppilot-config .mcppilot-cfg-preset .mcppilot-cfg-hint { flex: 1 1 100%; margin: 0; }
.mcppilot-config .mcppilot-cfg-preset-label { font-weight: bold; }
/* Invalid required field (Zabbix styles text inputs with .has-error; this also covers number inputs). */
.mcppilot-config .has-error { border-color: #e33734 !important; }

.mcppilot-warning {
	margin: 6px 0;
	padding: 6px 8px;
	border-left: 3px solid #e8b437;
	background: rgba(232, 180, 55, .12);
	font-size: 12px;
}

/* Pop out: in the main window, a notice replaces the chat while it is open in its own window. */
.mcppilot-popped-note { display: none; }
.mcppilot-popped .mcppilot-popped-note {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 24px;
	text-align: center;
}
.mcppilot-popped-buttons { display: flex; gap: 8px; }
.mcppilot-popped .mcppilot-messages,
.mcppilot-popped .mcppilot-starters,
.mcppilot-popped .mcppilot-composer { display: none; }

/*
 * The pop-out window. The script sets the layout's height to fill the window. Wide windows keep the side panel;
 * narrow ones show one status line instead, and "Details" opens the side panel above the chat.
 */
.mcppilot-bar { display: none; }
.mcppilot-popout.mcppilot-layout { min-height: 0; }
@media (max-width: 899px) {
	.mcppilot-popout.mcppilot-layout { flex-direction: column; gap: 8px; }
	.mcppilot-popout .mcppilot-bar {
		display: flex;
		flex: 0 0 auto;
		align-items: center;
		gap: 8px;
		padding: 4px 8px;
		border: 1px solid rgba(128, 128, 128, .3);
		border-radius: 4px;
		font-size: 12px;
	}
	.mcppilot-popout .mcppilot-bar-text {
		flex: 1 1 auto;
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
	.mcppilot-popout .mcppilot-bar button { flex: 0 0 auto; }
	.mcppilot-popout .mcppilot-chat { flex: 1 1 auto; height: auto; min-height: 220px; }
	.mcppilot-popout .mcppilot-side { display: none; }
	.mcppilot-popout.mcppilot-details-open .mcppilot-side {
		display: flex;
		order: -1;
		flex: 0 1 auto;
		max-height: 45%;
		min-width: 0;
		overflow-y: auto;
	}
	.mcppilot-popout.mcppilot-details-open .mcppilot-bar { order: -2; }
	.mcppilot-popout .mcppilot-msg-assistant { min-width: 0; }
}
