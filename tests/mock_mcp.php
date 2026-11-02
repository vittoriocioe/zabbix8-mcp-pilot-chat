<?php
/*
 * Mock Zabbix MCP server for tests (php -S). Accepts Bearer "sid-ok" only.
 * Query string ?sse=1 answers as text/event-stream like a Streamable HTTP server may do.
 *
 * Copyright (C) 2026 Vittorio Cioe
 * SPDX-License-Identifier: AGPL-3.0-only
 */

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if ($auth === '') {
	http_response_code(401);
	exit;
}

if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/event-stream') === false) {
	http_response_code(400);
	exit;
}

$request = json_decode(file_get_contents('php://input'), true);
$id = $request['id'];

function reply(array $message): void {
	if (isset($_GET['sse'])) {
		header('Content-Type: text/event-stream');
		echo "event: message\n";
		echo 'data: '.json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => []])."\n\n";
		echo 'data: '.json_encode($message)."\n\n";
	}
	else {
		header('Content-Type: application/json');
		echo json_encode($message);
	}

	exit;
}

if ($auth !== 'Bearer sid-ok') {
	reply(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Not authorized.',
		'data' => 'Session terminated, re-login, please.'
	]]);
}

$tools = [
	[
		'name' => 'problem_get',
		'description' => 'List current problems.',
		'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']],
			'additionalProperties' => false
		],
		'annotations' => ['readOnlyHint' => true]
	],
	[
		'name' => 'host_get',
		'description' => 'List hosts.',
		'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
		'annotations' => ['readOnlyHint' => true]
	],
	[
		'name' => 'problem_acknowledge',
		'description' => 'Acknowledge problems.',
		'inputSchema' => ['type' => 'object', 'properties' => ['eventids' => ['type' => 'array',
			'items' => ['type' => 'string']
		]]],
		'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false]
	]
];

switch ($request['method']) {
	case 'initialize':
		reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
			'protocolVersion' => '2025-06-18',
			'serverInfo' => ['name' => 'zabbix-mcp-server', 'version' => '8.0.0'],
			'capabilities' => ['tools' => new stdClass()]
		]]);

	case 'tools/list':
		reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $tools]]);

	case 'resources/read':
		reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['contents' => [[
			'uri' => 'zabbix://about',
			'text' => json_encode(['version' => '8.0.0', 'user' => ['username' => 'jdoe']])
		]]]]);

	case 'tools/call':
		$name = $request['params']['name'];

		if ($name === 'problem_get') {
			$results = [
				['eventid' => '57419025', 'source' => '0', 'object' => '0', 'objectid' => '34081', 'clock' => '1791818694',
					'ns' => '619727352', 'r_eventid' => '0', 'name' => 'Network connection is down', 'acknowledged' => '0',
					'severity' => '4', 'suppressed' => '0', 'opdata' => ''],
				['eventid' => '57419027', 'source' => '0', 'object' => '0', 'objectid' => '35026', 'clock' => '1791818695',
					'ns' => '466631604', 'r_eventid' => '0', 'name' => 'High CPU utilization', 'acknowledged' => '0',
					'severity' => '2', 'suppressed' => '0', 'opdata' => 'Current utilization: 98.3 %']
			];
			// Like the real server: at most "limit" results, and a truncated result says so.
			$limit = $request['params']['arguments']['limit'] ?? 100;
			$envelope = ['count' => min(count($results), $limit), 'truncated' => count($results) > $limit,
				'limit' => $limit
			];

			if ($envelope['truncated']) {
				$envelope += ['truncated_by' => 'count', 'note' => 'The result count limit was reached.'];
			}

			$text = json_encode(['results' => array_slice($results, 0, $limit)] + $envelope);

			reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => $text]]]]);
		}

		if ($name === 'problem_acknowledge') {
			reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text',
				'text' => json_encode(['eventids' => $request['params']['arguments']['eventids'] ?? []])
			]]]]);
		}

		reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['isError' => true,
			'content' => [['type' => 'text', 'text' => 'Unknown tool.']]
		]]);

	default:
		reply(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]);
}
