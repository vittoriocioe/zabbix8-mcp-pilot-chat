# Copyright (C) 2026 Vittorio Cioe
# SPDX-License-Identifier: AGPL-3.0-only
"""
Browser test of the chat page: real module CSS/JS and backend, mock MCP server and mock LLM.

Checks the UX requirements: thinking animation, live elapsed timer while waiting, completion time,
debug steps, approval card, stop button, deep links, side panel.

Usage: python3 tests/ui_test.py [screenshot_dir]
"""
import json
import os
import socket
import subprocess
import sys
import time

from playwright.sync_api import Error as PlaywrightError, sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
SHOTS = sys.argv[1] if len(sys.argv) > 1 else os.path.join(HERE, 'screenshots')
os.makedirs(SHOTS, exist_ok=True)

failures = []


def check(cond, label):
    print(('  ok   ' if cond else '  FAIL ') + label)
    if not cond:
        failures.append(label)


def click_and_close(page, selector):
    """Click a button that closes its own window; the window may be gone before the click returns."""
    try:
        page.click(selector, no_wait_after=True)
    except PlaywrightError:
        if not page.is_closed():
            raise


def serve(port, script, env=None):
    proc = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', script], env={**os.environ, **(env or {})},
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(50):
        try:
            socket.create_connection(('127.0.0.1', port), 0.2).close()
            return proc
        except OSError:
            time.sleep(0.1)
    raise RuntimeError(script)


procs = [
    serve(28443, os.path.join(HERE, 'mock_mcp.php')),
    # PHP_CLI_SERVER_WORKERS lets the slow mock LLM and the status calls run in parallel.
    serve(28080, os.path.join(HERE, 'mock_llm.php'), {'MOCK_DELAY_MS': '1500', 'PHP_CLI_SERVER_WORKERS': '4'}),
    serve(28090, os.path.join(HERE, 'ui_harness.php'), {
        'MCP_URL': 'http://127.0.0.1:28443/mcp',
        'LLM_URL': 'http://127.0.0.1:28080/v1',
        'PHP_CLI_SERVER_WORKERS': '4',
        # Optional: a Zabbix "ui" folder, to test the pop-out layout inside the real Zabbix theme CSS.
        'ZBX_UI_DIR': os.environ.get('ZBX_UI_DIR', '')
    })
]

try:
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page(viewport={'width': 1400, 'height': 900})
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))

        print('Page and side panel')
        page.goto('http://127.0.0.1:28090/')
        page.wait_for_selector('#mcppilot-status .mcppilot-status-head')
        status = page.inner_text('#mcppilot-status')
        check('Connected' in status, 'MCP status shows Connected')
        check('as jdoe' in status, 'status shows the identity from zabbix://about')
        check(page.locator('#mcppilot-status > div').count() == 2, 'MCP status is compact: two lines')
        check(page.inner_text('#mcppilot-tools-count') == '3 exposed', 'tool count shown in the Tools title')
        check('Zabbix 8.0.0' in status, 'status shows the Zabbix version')
        check(page.locator('.mcppilot-tool-enabled').count() == 2, '2 tools green (allowed in profile)')
        check(page.locator('.mcppilot-tool-disabled').count() == 1, '1 tool yellow (host_get, off in profile)')
        check(page.locator('.mcppilot-tool-missing').count() == 19, '19 tools red (not exposed)')
        check(page.locator('.mcppilot-starters button').count() == 4, 'starter prompts shown (3 demo + 1 test)')
        page.screenshot(path=f'{SHOTS}/1-empty.png')

        print('\nAsking a question: thinking animation and live timer')
        page.click('.mcppilot-starters button >> nth=0')
        page.wait_for_selector('.mcppilot-live .mcppilot-dots')
        check(page.is_visible('.mcppilot-live'), 'thinking animation visible while waiting')
        check(page.inner_text('.mcppilot-live-label') == 'Thinking…', 'status label "Thinking…"')
        t1 = page.inner_text('.mcppilot-timer-total')
        time.sleep(0.6)
        t2 = page.inner_text('.mcppilot-timer-total')
        check(t1 != t2 and t2.endswith(' s'), f'elapsed timer runs while waiting ({t1} -> {t2})')
        check(page.is_disabled('#mcppilot-send') and not page.is_disabled('#mcppilot-stop'),
              'Send disabled and Stop enabled while busy')
        page.screenshot(path=f'{SHOTS}/2-thinking.png')

        page.wait_for_selector('.mcppilot-step >> text=problem_get', timeout=15000)
        label = page.inner_text('.mcppilot-live-label')
        check(label in ('Thinking…', 'Calling problem_get…'), f'label follows the loop ("{label}")')
        check('step' in page.inner_text('.mcppilot-timer-step'), 'per-step timer shown after the first step')
        page.screenshot(path=f'{SHOTS}/3-tool-step.png')

        page.wait_for_selector('.mcppilot-done', timeout=20000)
        done = page.inner_text('.mcppilot-done')
        check(done.startswith('✓ Completed in ') and '2 model calls · 1 tool call' in done,
              f'completion time and counts shown ("{done}")')
        check(not page.is_visible('.mcppilot-live'), 'animation hidden after completion')
        answer = page.inner_text('.mcppilot-answer')
        check('There are 2 problems' in answer and '</think>' not in answer, 'answer rendered without reasoning')
        check(page.locator('.mcppilot-answer b').count() >= 1, 'markdown bold rendered')
        link = page.locator('.mcppilot-answer a', has_text='57419025').first
        check('tr_events.php' in link.get_attribute('href') and 'triggerid=34081' in link.get_attribute('href'),
              'event ID linked to the event page')
        steps = page.locator('.mcppilot-msg-assistant >> nth=0').locator('.mcppilot-step')
        check(steps.count() == 3, f'3 debug steps (model, tool, model): {steps.count()}')
        check('KB' in steps.nth(1).inner_text() or ' B' in steps.nth(1).inner_text(), 'tool step shows sizes')
        check(page.inner_text('#mcppilot-context-text') == '455 / 4,096', 'context shown as a number')
        check(page.inner_text('#mcppilot-usage-question') == '606 sent · 72 received'
              and page.inner_text('#mcppilot-usage-chat') == '606 sent · 72 received',
              'usage: this question and this chat, as numbers')
        check(page.inner_text('.mcppilot-msg-meta') == 'Local demo - qwen3-0.6b',
              'answer labelled "profile - model"')
        steps.nth(1).locator('summary').click()
        page.screenshot(path=f'{SHOTS}/4-answer.png', full_page=True)

        first_step = steps.nth(0).locator('summary').inner_text()
        check('read 3 tokens (+148 cached) in 0.5 s · wrote 21 in 6.4 s' in first_step,
              f'model call shows read/write split ("{first_step}")')
        steps.nth(0).locator('summary').click()
        first_text = steps.nth(0).inner_text()
        check('Tools offered to the model:' in first_text and 'problem_get, problem_acknowledge' in first_text,
              'trace shows the tools offered')
        check('Messages sent (2):' in first_text and '── system ──' in first_text, 'trace shows the messages sent')

        print('\nChat survives reload')
        page.reload()
        page.wait_for_selector('.mcppilot-done')
        check(page.locator('.mcppilot-msg-user').count() == 1 and page.locator('.mcppilot-msg-assistant').count() == 1,
              'question and answer restored after reload')
        check(page.inner_text('.mcppilot-done').startswith('✓ Completed in '), 'completion line restored')
        check(page.inner_text('.mcppilot-msg-meta') == 'Local demo - qwen3-0.6b', '"profile - model" label restored')
        check(page.locator('.mcppilot-msg-assistant .mcppilot-step').count() == 3, 'debug steps restored')
        check(page.locator('.mcppilot-answer a', has_text='57419025').count() >= 1, 'links restored')
        check(page.inner_text('#mcppilot-context-text') == '455 / 4,096', 'context restored')
        check(page.inner_text('#mcppilot-usage-chat') == '606 sent · 72 received', 'usage restored')
        check(page.get_attribute('#mcppilot-new', 'title') == 'Clear this conversation and start a new one',
              'New chat explains that it resets')
        steps = page.locator('.mcppilot-msg-assistant >> nth=0').locator('.mcppilot-step')
        steps.nth(1).locator('summary').click()

        print('\nDebug toggle')
        page.uncheck('#mcppilot-debug')
        check(not page.is_visible('.mcppilot-steps >> nth=0'), 'debug off hides the steps')
        check(page.is_visible('.mcppilot-done'), 'completion line stays visible with debug off')
        page.screenshot(path=f'{SHOTS}/5-debug-off.png')
        page.check('#mcppilot-debug')

        print('\nApproval of a write tool')
        page.click('.mcppilot-starters button >> nth=2')
        page.wait_for_selector('.mcppilot-approval', timeout=15000)
        check(page.inner_text('.mcppilot-msg-assistant >> nth=1 >> .mcppilot-live-label')
              == 'Waiting for your approval…', 'label asks for approval')
        check('problem_acknowledge' in page.inner_text('.mcppilot-approval'), 'approval card shows the tool')
        page.screenshot(path=f'{SHOTS}/6-approval.png')
        page.click('.mcppilot-approval button:has-text("Approve")')
        page.wait_for_selector('.mcppilot-msg-assistant >> nth=1 >> .mcppilot-done', timeout=20000)
        check('Completed' in page.inner_text('.mcppilot-msg-assistant >> nth=1 >> .mcppilot-done'),
              'turn completes after approval')

        print('\nStop button')
        page.fill('#mcppilot-input', 'loop forever')
        page.press('#mcppilot-input', 'Enter')
        page.wait_for_selector('.mcppilot-msg-assistant >> nth=2 >> .mcppilot-live')
        time.sleep(0.8)
        page.click('#mcppilot-stop')
        page.wait_for_selector('.mcppilot-msg-assistant >> nth=2 >> .mcppilot-done', timeout=10000)
        stopped = page.inner_text('.mcppilot-msg-assistant >> nth=2 >> .mcppilot-done')
        check(stopped.startswith('■ Stopped after'), f'stop shows elapsed time ("{stopped}")')
        check(not page.is_disabled('#mcppilot-send'), 'chat usable again after stop')

        print('\nRate limit: automatic retry with countdown')
        page.fill('#mcppilot-input', 'ratelimit check')
        page.press('#mcppilot-input', 'Enter')
        turn = page.locator('.mcppilot-msg-assistant').last
        turn.locator('.mcppilot-live-label', has_text='Rate limited, retrying in').wait_for(timeout=15000)
        check(True, 'live status shows the retry countdown')
        page.screenshot(path=f'{SHOTS}/14-rate-limit-countdown.png')
        turn.locator('.mcppilot-done').wait_for(timeout=40000)
        check(turn.locator('.mcppilot-done').inner_text().startswith('✓ Completed'),
              'question completes after automatic retries')
        retries = turn.locator('.mcppilot-step', has_text='Rate limited by')
        check(retries.count() >= 2, f'each retry is listed in the trace ({retries.count()})')
        check('retry 1 of 3 after' in retries.first.inner_text(), 'retry step shows attempt and wait')

        print('\nModel busy (HTTP 503): automatic retry')
        page.fill('#mcppilot-input', 'busy gemma: show problems')
        page.press('#mcppilot-input', 'Enter')
        turn = page.locator('.mcppilot-msg-assistant').last
        turn.locator('.mcppilot-live-label', has_text='Model busy, retrying in').wait_for(timeout=15000)
        check(True, 'live status shows "Model busy, retrying in …"')
        turn.locator('.mcppilot-done').wait_for(timeout=40000)
        check(turn.locator('.mcppilot-done').inner_text().startswith('✓ Completed'), 'question completes after the 503')
        check(turn.locator('.mcppilot-step', has_text='Model busy at').count() >= 1, 'busy retry listed in the trace')
        answer = turn.locator('.mcppilot-answer').inner_text()
        check('There are 2 problems.' in answer and 'thought' not in answer.lower(),
              'Gemma answer shown without its <thought> block')

        print('\nRequest too large: no retry, readable error')
        page.fill('#mcppilot-input', 'toolarge')
        page.press('#mcppilot-input', 'Enter')
        turn = page.locator('.mcppilot-msg-assistant').last
        turn.locator('.mcppilot-done').wait_for(timeout=15000)
        err = turn.locator('.mcppilot-error').inner_text()
        check('needs 12000 tokens' in err and 'only 8000' in err, 'too-large error explains the limit')
        check(turn.locator('.mcppilot-step', has_text='Rate limited by').count() == 0, 'too-large is not retried')

        print('\nModel ran out of tokens')
        page.fill('#mcppilot-input', 'cutoff')
        page.press('#mcppilot-input', 'Enter')
        turn = page.locator('.mcppilot-msg-assistant').last
        turn.locator('.mcppilot-done').wait_for(timeout=15000)
        check('before it wrote any answer' in turn.locator('.mcppilot-warning').inner_text(),
              'cut-off warning visible next to the answer')
        check('💭 reasoning' in turn.locator('.mcppilot-step').first.inner_text(), 'reasoning marked in the trace')
        turn.locator('.mcppilot-step summary').first.click()
        check('consider severities' in turn.locator('.mcppilot-step').first.inner_text(), 'reasoning text viewable')
        page.screenshot(path=f'{SHOTS}/15-cutoff.png', full_page=True)

        print('\nError handling')
        page.evaluate("mcppilot_chat.state = 'tampered'; mcppilot_chat.sig = 'x'")
        page.fill('#mcppilot-input', 'hello')
        page.click('#mcppilot-send')
        page.locator('.mcppilot-msg-assistant').last.locator('.mcppilot-done').wait_for(timeout=10000)
        check('Failed after' in page.locator('.mcppilot-msg-assistant').last.locator('.mcppilot-done').inner_text(),
              'failure shows elapsed time')
        check('Start a new chat' in page.locator('.mcppilot-msg-assistant').last.locator('.mcppilot-error').inner_text(),
              'error message shown in the chat')
        page.screenshot(path=f'{SHOTS}/7-history.png', full_page=True)

        print('\nProfile switch starts a new chat')
        before = page.locator('.mcppilot-msg').count()
        page.once('dialog', lambda d: d.dismiss())
        page.select_option('#mcppilot-profile', 'cloud')
        check(page.locator('.mcppilot-msg').count() == before and page.input_value('#mcppilot-profile') == 'local-demo',
              'cancel keeps the chat and the profile')
        messages = []
        page.once('dialog', lambda d: (messages.append(d.message), d.accept()))
        page.select_option('#mcppilot-profile', 'cloud')
        check(messages and 'starts a new chat' in messages[0], 'confirmation explains the new chat')
        check(page.locator('.mcppilot-msg').count() == 0 and page.evaluate('mcppilot_chat.profile_id') == 'cloud',
              'accept switches profile and clears the chat')
        page.select_option('#mcppilot-profile', 'local-demo')
        check(page.evaluate('mcppilot_chat.profile_id') == 'local-demo', 'empty chat switches without asking')

        print('\nLabels, usage, truncated results, write-tool warning')
        check(page.inner_text('label[for=mcppilot-profile]') == 'LLM profile', 'profile picker labelled "LLM profile"')
        check(page.locator('#mcppilot-profile option[value=local-demo]').inner_text()
              == 'Local demo - qwen3-0.6b', 'profile list shows "profile - model"')
        check(page.locator('#mcppilot-budget').count() == 0 and page.locator('.mcppilot-meter').count() == 0,
              'no token budget and no bars in the side panel')
        page.fill('#mcppilot-input', 'tiny: show problems')
        page.press('#mcppilot-input', 'Enter')
        turn = page.locator('.mcppilot-msg-assistant').last
        turn.locator('.mcppilot-done').wait_for(timeout=20000)
        badge = turn.locator('.mcppilot-truncated')
        check(badge.count() == 1 and badge.inner_text() == 'truncated at 1, cannot be paged',
              'truncated MCP result marked in the trace')
        check(page.inner_text('#mcppilot-usage-question') == '606 sent · 72 received'
              and page.inner_text('#mcppilot-usage-chat') == '606 sent · 72 received',
              'usage after the first question of a new chat')
        page.fill('#mcppilot-input', 'And now?')
        page.press('#mcppilot-input', 'Enter')
        page.locator('.mcppilot-msg-assistant').last.locator('.mcppilot-done').wait_for(timeout=20000)
        check(page.inner_text('#mcppilot-usage-question') == '606 sent · 72 received'
              and page.inner_text('#mcppilot-usage-chat') == '1,212 sent · 144 received',
              'a new question resets "this question", the chat keeps counting'
              + f" ({page.inner_text('#mcppilot-usage-question')} / {page.inner_text('#mcppilot-usage-chat')})")
        page.screenshot(path=f'{SHOTS}/16-truncated-usage.png', full_page=True)
        page.click('#mcppilot-new')
        check(page.inner_text('#mcppilot-usage-chat') == '–' and page.inner_text('#mcppilot-context-text') == '–',
              'New chat resets usage and context')

        page.select_option('#mcppilot-profile', 'risky')
        page.wait_for_selector('.mcppilot-write-warning')
        check('1 write tool can change Zabbix without approval' in page.inner_text('.mcppilot-write-warning'),
              'warning when write tools run without approval')
        page.select_option('#mcppilot-profile', 'local-demo')
        page.wait_for_function("!document.querySelector('.mcppilot-write-warning')"
                               " && document.querySelector('#mcppilot-status .mcppilot-status-head')")
        check(page.locator('.mcppilot-write-warning').count() == 0, 'no warning when approval is on')

        print('\nDownload chat log and new chat')
        check(page.inner_text('#mcppilot-export') == 'Download chat log', 'button labelled "Download chat log"')
        check('model and MCP calls' in page.get_attribute('#mcppilot-export', 'title'), 'download button has a tooltip')
        with page.expect_download() as download_info:
            page.click('#mcppilot-export')
        check(download_info.value.suggested_filename.startswith('mcp-pilot-chat-log-')
              and download_info.value.suggested_filename.endswith('.json'), 'chat log downloads as JSON')
        page.click('#mcppilot-new')
        check(page.locator('.mcppilot-msg').count() == 0 and page.is_visible('.mcppilot-empty'), 'new chat clears')
        page.reload()
        page.wait_for_selector('#mcppilot-status .mcppilot-status-head')
        check(page.locator('.mcppilot-msg').count() == 0, 'new chat also clears the saved chat (stays empty on reload)')

        print('\nPop out and pop in')
        ctx = page.context
        main_url = 'http://127.0.0.1:28090/'
        saved_turns = "(JSON.parse(sessionStorage.getItem('mcppilot.chat.jdoe') ?? 'null')?.turns ?? []).length"
        page.click('.mcppilot-starters button >> nth=0')
        page.wait_for_selector('.mcppilot-done', timeout=20000)
        msgs = page.locator('.mcppilot-msg').count()

        def pop_out():
            with page.expect_popup() as popup_info:
                page.click('#mcppilot-popout')
            pop = popup_info.value
            pop.on('pageerror', lambda e: errors.append('pop-out: ' + str(e)))
            pop.wait_for_selector('#mcppilot-status .mcppilot-status-head', state='attached')
            return pop

        pop = pop_out()
        check('popout=1' in pop.url and pop.title() == 'MCP Pilot Chat (pop-out)', 'pop-out window opens')
        check(pop.locator('.mcppilot-msg').count() == msgs, 'conversation moved into the pop-out')
        check(pop.inner_text('.mcppilot-msg-meta >> nth=0') == 'Local demo - qwen3-0.6b',
              'moved answer keeps its "profile - model" label')
        check(pop.locator('#mcppilot-popin').count() == 1 and pop.locator('#mcppilot-popout').count() == 0,
              'pop-out has Pop in, not Pop out')
        check(page.is_visible('#mcppilot-popped') and not page.is_visible('#mcppilot-input'),
              'main window shows "open in a separate window" instead of the chat')
        check(page.is_disabled('#mcppilot-new') and page.is_disabled('#mcppilot-send'),
              'main window cannot change the chat while popped out')
        pop.screenshot(path=f'{SHOTS}/10-popout.png')
        page.screenshot(path=f'{SHOTS}/11-popped-main.png')

        pop.click('.mcppilot-starters button >> nth=1')
        pop.wait_for_function("document.querySelectorAll('.mcppilot-done').length === 2", timeout=20000)
        check(page.evaluate(saved_turns) == 2, 'pop-out saves each turn into the main window too')
        with pop.expect_download() as download_info:
            pop.click('#mcppilot-export')
        log = json.load(open(download_info.value.path()))
        check(len(log['turns']) == 2 and all(t['profile'] == 'Local demo - qwen3-0.6b' for t in log['turns']),
              'chat log names the profile and model of each answer')

        pages_before = len(ctx.pages)
        link = pop.locator('.mcppilot-messages a.link-alt').first
        href = link.get_attribute('href')
        link.click()
        page.wait_for_url(lambda u: u.endswith(href), timeout=10000)
        check(len(ctx.pages) == pages_before, 'Zabbix link in the pop-out opens in the main window, no new tab')

        with page.expect_navigation():
            click_and_close(pop, '#mcppilot-popin')
        page.wait_for_selector('#mcppilot-status .mcppilot-status-head')
        check(pop.is_closed(), 'Pop in closes the pop-out')
        check(page.locator('.mcppilot-msg').count() == msgs * 2, 'Pop in: conversation back in the main window')
        check(not page.is_visible('#mcppilot-popped') and page.is_enabled('#mcppilot-input'), 'main chat usable again')
        check('action=mcppilotchat.view' in page.url, 'main window back on the chat page')

        pop = pop_out()
        page.reload()
        page.wait_for_selector('#mcppilot.mcppilot-popped')
        check(page.is_visible('#mcppilot-popped'), 'main chat page reloaded: still knows the chat is popped out')
        time.sleep(2.5)  # The pop-out registers itself with the reloaded main page.
        with page.expect_navigation():
            pop.close(run_before_unload=True)
        page.wait_for_selector('#mcppilot-status .mcppilot-status-head')
        check(page.locator('.mcppilot-msg').count() == msgs * 2 and not page.is_visible('#mcppilot-popped'),
              'closing the pop-out brings the chat back')

        pop = pop_out()
        with page.expect_navigation():
            page.click('#mcppilot-popped-popin')
        page.wait_for_selector('#mcppilot-status .mcppilot-status-head')
        check(pop.is_closed() and page.locator('.mcppilot-msg').count() == msgs * 2,
              'Pop in from the main window closes the pop-out and restores the chat')

        pop = pop_out()
        pop.click('.mcppilot-starters button >> nth=0')
        pop.wait_for_selector('.mcppilot-live:not([hidden])')
        check(pop.is_disabled('#mcppilot-popin'), 'Pop in disabled while an answer is running')
        dialogs = []
        page.once('dialog', lambda d: (dialogs.append(d.message), d.accept()))
        page.click('#mcppilot-popped-popin')
        check(dialogs and 'still running' in dialogs[0] and not pop.is_closed(),
              'Pop in from the main window waits for a running answer')
        pop.wait_for_function("document.querySelectorAll('.mcppilot-done').length === 3", timeout=20000)
        with page.expect_navigation():
            pop.close(run_before_unload=True)
        page.wait_for_selector('#mcppilot-status .mcppilot-status-head')
        check(page.locator('.mcppilot-msg').count() == msgs * 3, 'answer finished in the pop-out kept in the main window')

        # The main window is gone: Pop in turns the pop-out into the normal chat page.
        ctx2 = browser.new_context()
        main2 = ctx2.new_page()
        main2.goto(main_url)
        main2.wait_for_selector('#mcppilot-status .mcppilot-status-head')
        with main2.expect_popup() as popup_info:
            main2.click('#mcppilot-popout')
        pop = popup_info.value
        pop.wait_for_selector('#mcppilot-status .mcppilot-status-head', state='attached')
        n2 = pop.locator('.mcppilot-msg').count()
        main2.close()
        with pop.expect_navigation():
            pop.click('#mcppilot-popin')
        pop.wait_for_selector('#mcppilot-status .mcppilot-status-head', state='attached')
        check('popout' not in pop.url and pop.locator('#mcppilot-popout').count() == 1
              and pop.locator('.mcppilot-msg').count() == n2, 'without the main window, Pop in makes this the chat page')
        ctx2.close()
        page.click('#mcppilot-new')

        print('\nPop-out window size')
        page.evaluate('''() => {
            window.opened = [];
            const original = window.open;
            window.open = (url, name, features) => { opened.push(features ?? ''); return original.call(window, url, name, features); };
            localStorage.removeItem('mcppilot.popout.size');
        }''')
        pop = pop_out()
        check(page.evaluate('opened[opened.length - 1]') == 'popup,width=760,height=900', 'first pop-out: 760 × 900')
        pop.evaluate("window.dispatchEvent(new Event('resize'))")
        pop.wait_for_function("localStorage.getItem('mcppilot.popout.size') !== null", timeout=5000)
        stored = json.loads(pop.evaluate("localStorage.getItem('mcppilot.popout.size')"))
        check(stored == {'width': pop.evaluate('innerWidth'), 'height': pop.evaluate('innerHeight')},
              'resizing the pop-out remembers its size')
        with page.expect_navigation():
            click_and_close(pop, '#mcppilot-popin')
        page.wait_for_selector('#mcppilot-status .mcppilot-status-head')
        for value, expected, label in [
            ({'width': 600, 'height': 700}, 'popup,width=600,height=700', 'remembered size used'),
            ({'width': 100, 'height': 100}, 'popup,width=420,height=480', 'too small: raised to 420 × 480'),
            ({'width': 99999, 'height': 99999}, None, 'too large: limited to the screen'),
            ('broken', 'popup,width=760,height=900', 'unreadable value: default size')
        ]:
            page.evaluate('''([v]) => {
                localStorage.setItem('mcppilot.popout.size', typeof v === 'string' ? v : JSON.stringify(v));
                window.opened = [];
                const original = window.open;
                window.open = (url, name, features) => { opened.push(features); return null; };
            }''', [value])
            page.once('dialog', lambda d: d.accept())
            page.click('#mcppilot-popout')
            features = page.evaluate('opened[0]')
            if expected is None:
                screen = page.evaluate('[screen.availWidth, screen.availHeight]')
                expected = f'popup,width={screen[0]},height={screen[1]}'
            check(features == expected, f'{label} ({features})')
        page.reload()
        page.wait_for_selector('#mcppilot-status .mcppilot-status-head')
        check(not page.is_visible('#mcppilot-popped'), 'a blocked pop-out leaves the chat in the main window')

        print('\nPop-out layout in small and large windows'
              + (' (Zabbix theme CSS)' if os.environ.get('ZBX_UI_DIR') else ' (no Zabbix CSS: set ZBX_UI_DIR)'))
        overflow_js = '''() => {
            const limit = document.documentElement.clientWidth + 1;
            return [...document.querySelectorAll('body *')]
                .filter((el) => el.getClientRects().length > 0 && el.getBoundingClientRect().right > limit)
                .map((el) => el.tagName + (el.id ? '#' + el.id : '') + '.' + el.className).slice(0, 5);
        }'''
        probe_js = '''() => {
            const list = document.querySelector('#zselect-probe .list');
            const item = list.children[1];
            const ls = getComputedStyle(list), is = getComputedStyle(item);
            return [ls.display, ls.flexWrap, ls.textAlign, is.display, is.padding];
        }'''
        ref = browser.new_page(viewport={'width': 1400, 'height': 900})
        ref.goto(main_url)
        ref.wait_for_selector('#mcppilot-status .mcppilot-status-head')
        probe_main = ref.evaluate(probe_js)
        ref.close()

        for width in (500, 700, 1000):
            win = browser.new_page(viewport={'width': width, 'height': 800})
            win.on('pageerror', lambda e: errors.append(f'{width}px: ' + str(e)))
            win.goto(main_url + '?popout=1')
            win.wait_for_selector('#mcppilot-status .mcppilot-status-head', state='attached')
            win.click('.mcppilot-starters button >> nth=0')
            win.wait_for_selector('.mcppilot-done', timeout=20000)
            narrow = width < 900

            out = win.evaluate(overflow_js)
            check(not out and win.evaluate('document.documentElement.scrollWidth <= innerWidth'),
                  f'{width} px: nothing sticks out on the right' + (f': {out}' if out else ''))
            box = win.locator('#mcppilot-popin').bounding_box()
            check(box is not None and box['x'] + box['width'] <= width and win.is_visible('#mcppilot-popin'),
                  f'{width} px: Pop in visible')
            bubble = win.locator('.mcppilot-msg-user').bounding_box()
            check(bubble['x'] >= 0 and bubble['x'] + bubble['width'] <= width, f'{width} px: question bubble inside the window')
            composer = win.locator('#mcppilot-send').bounding_box()
            check(composer['y'] + composer['height'] <= 800, f'{width} px: Send button inside the window')
            layout = win.locator('#mcppilot').bounding_box()
            check(layout['y'] + layout['height'] >= 800 - 40, f'{width} px: the chat fills the window height')
            check(not win.is_visible('h1#page-title-general'), f'{width} px: no page heading (the window title names it)')
            if os.environ.get('ZBX_UI_DIR'):
                # Meaningful only with Zabbix's own dropdown styles loaded.
                probe = win.evaluate(probe_js)
                check(probe == probe_main, f'{width} px: dropdown option lists keep their Zabbix style ({probe} / {probe_main})')
            at_end = '(() => { const m = document.getElementById("mcppilot-messages"); return m.scrollHeight - m.scrollTop - m.clientHeight < 8; })()'
            win.evaluate('document.getElementById("mcppilot-messages").scrollTop = 1e9')

            if narrow:
                check(win.is_visible('#mcppilot-bar') and not win.is_visible('#mcppilot-side'),
                      f'{width} px: status line instead of the side panel')
                bar = win.inner_text('#mcppilot-bar-text')
                check('Connected' in bar and 'sent' in bar and 'tool' in bar, f'{width} px: status line: {bar}')
                win.screenshot(path=f'{SHOTS}/17-popout-{width}.png')
                win.click('#mcppilot-bar-toggle')
                win.wait_for_timeout(100)
                check(win.evaluate(at_end), f'{width} px: opening Details keeps the chat scrolled to the end')
                check(win.is_visible('#mcppilot-side') and win.get_attribute('#mcppilot-bar-toggle', 'aria-expanded') == 'true'
                      and win.inner_text('#mcppilot-bar-toggle') == 'Hide details', f'{width} px: Details opens the side panel')
                composer = win.locator('#mcppilot-send').bounding_box()
                check(composer['y'] + composer['height'] <= 800 and not win.evaluate(overflow_js),
                      f'{width} px: with details open, the chat still fits')
                win.screenshot(path=f'{SHOTS}/17-popout-{width}-details.png')
                win.click('#mcppilot-bar-toggle')
                check(not win.is_visible('#mcppilot-side'), f'{width} px: Hide details closes it')
            else:
                check(not win.is_visible('#mcppilot-bar') and win.is_visible('#mcppilot-side'),
                      f'{width} px: side panel shown, no status line')
                win.screenshot(path=f'{SHOTS}/17-popout-{width}.png')

            win.evaluate('document.getElementById("mcppilot-messages").scrollTop = 1e9')
            win.set_viewport_size({'width': width, 'height': 600})
            win.wait_for_timeout(150)
            check(win.evaluate(at_end), f'{width} px: a smaller window keeps the chat scrolled to the end')
            layout = win.locator('#mcppilot').bounding_box()
            check(abs(layout['y'] + layout['height'] - 600) <= 40, f'{width} px: follows a smaller window height')
            win.close()

        main_page = browser.new_page(viewport={'width': 1400, 'height': 900})
        main_page.goto(main_url)
        main_page.wait_for_selector('#mcppilot-status .mcppilot-status-head')
        check(main_page.locator('#mcppilot-bar').count() == 0 and main_page.is_visible('h1#page-title-general'),
              'main chat page unchanged: no status line, heading shown')
        main_page.close()

        print('\nDark theme')
        page.goto('http://127.0.0.1:28090/?dark=1')
        page.wait_for_selector('#mcppilot-status .mcppilot-status-head')
        page.click('.mcppilot-starters button >> nth=0')
        page.wait_for_selector('.mcppilot-step', timeout=15000)
        page.screenshot(path=f'{SHOTS}/8-dark-thinking.png')
        page.wait_for_selector('.mcppilot-done', timeout=20000)
        page.screenshot(path=f'{SHOTS}/9-dark-answer.png')

        check(not errors, 'no JavaScript errors' + (': ' + '; '.join(errors) if errors else ''))
        browser.close()
finally:
    for proc in procs:
        proc.terminate()

print('\n' + ('ALL UI CHECKS PASSED' if not failures else f'{len(failures)} UI CHECKS FAILED'))
sys.exit(1 if failures else 0)
