# Copyright (C) 2026 Vittorio Cioe
# SPDX-License-Identifier: AGPL-3.0-only
"""
Browser test of the configuration popup: real popup JavaScript and CSS with the module's real defaults.
The Zabbix overlay, Curl and fetch are simulated; saved data is captured instead of being posted.

Usage: python3 tests/config_test.py [screenshot_dir]
"""
import json
import os
import subprocess
import sys
import tempfile

from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
SHOTS = sys.argv[1] if len(sys.argv) > 1 else os.path.join(HERE, 'screenshots')
os.makedirs(SHOTS, exist_ok=True)

failures = []


def check(cond, label):
    print(('  ok   ' if cond else '  FAIL ') + label)
    if not cond:
        failures.append(label)


# Build the popup page with PHP so the data comes from the module's own classes.
BUILD = r'''
spl_autoload_register(function ($c) {
    $p = 'Modules\\McpPilotChat\\';
    if (strpos($c, $p) !== 0) return;
    $parts = explode('\\', substr($c, strlen($p)));
    $f = array_pop($parts).'.php';
    $d = implode('/', array_map('strtolower', $parts));
    require ($d ? $d.'/' : '').$f;
});
use Modules\McpPilotChat\Lib\{Config, Presets, Prompts, ResultTrimmer, ToolCatalog};
$m = json_decode(file_get_contents('manifest.json'), true);
$cfg = Config::normalize($m['config']);
$cfg['profiles'][0]['api_key'] = 'secret';
$cfg['profiles'] = array_map([Config::class, 'publicProfile'], $cfg['profiles']);
ob_start(); include 'views/js/mcppilotchat.config.edit.js.php'; $js = ob_get_clean();
echo json_encode(['js' => $js, 'css' => file_get_contents('views/css/mcppilotchat.css.php'), 'init' => [
    'config' => $cfg,
    'user_groups' => [['id' => '7', 'name' => 'Zabbix administrators'], ['id' => '13', 'name' => 'NOC operators']],
    'profile_defaults' => Config::profileDefaults(),
    'builtin_prompt' => Prompts::BUILT_IN,
    'default_trim_fields' => ResultTrimmer::DEFAULT_FIELDS,
    'lean_trim_fields' => ResultTrimmer::LEAN_FIELDS,
    'catalog' => array_keys(ToolCatalog::TOOLS),
    'presets' => Presets::forBrowser(),
    'csrf' => ['update' => 'csrf-update', 'test' => 'csrf-test']
]]);
'''

data = json.loads(subprocess.check_output(['php', '-r', BUILD], cwd=ROOT))
builtin = data['init']['builtin_prompt']

page_html = f'''<!doctype html><html><head><meta charset="utf-8">
<style>body{{font:12px Arial;margin:16px;width:960px}}{data['css']}</style></head>
<body>
<!-- Same constraints as a Zabbix popup body: fixed width, no wrapping, hidden horizontal overflow. -->
<div id="dialogue-body" style="width:900px;white-space:nowrap;overflow-x:hidden;overflow-y:auto">
<div id="mcppilot-config" class="mcppilot-config"></div></div>
<div id="footer"><button class="js-submit">Save</button></div>
<script>
window.posted = [];
class Curl {{ constructor(u) {{ this.u = new URL(u, 'http://x/'); }} setArgument(k, v) {{ this.u.searchParams.set(k, v); }}
    getUrl() {{ return this.u.search; }} }}
window.fetch = async (url, o) => {{
    const body = JSON.parse(o.body);
    posted.push({{url, body}});
    const result = url.includes('connection.test')
        ? {{ok: true, result: {{ok: true, message: 'Connected in 12 ms. Forced tool calls work (1.2 s).'}}}}
        : {{ok: true}};
    return {{status: 200, text: async () => JSON.stringify(result)}};
}};
window.makeMessageBox = (t, m, title) => [Object.assign(document.createElement('div'),
    {{className: 'msg-' + t, textContent: title + (m.length ? ': ' + m.join(' | ') : '')}})];
window.overlayDialogueDestroy = () => {{ window.destroyed = true; }};
const dialogue = document.createElement('div');
dialogue.addEventListener('dialogue.submit', () => window.submitted = true);
window.overlays_stack = {{getById: () => ({{dialogueid: 'x',
    $dialogue: Object.assign([dialogue], {{$footer: [document.getElementById('footer')]}}), unsetLoading() {{}}}})}};
</script>
<script>{data['js']}</script>
<script>mcppilot_config.init({json.dumps(data['init'])});</script>
</body></html>'''

with tempfile.NamedTemporaryFile('w', suffix='.html', delete=False) as f:
    f.write(page_html)
    page_file = f.name

with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={'width': 1000, 'height': 1000})
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.goto('file://' + page_file)

    visible = '.mcppilot-cfg-panel:not([hidden])'

    print('Prompts tab')
    page.click('button[data-tab=prompts]')
    area = page.locator(f'{visible} textarea')
    check(area.input_value() == builtin, 'module prompt prefilled with the built-in prompt (editable text)')
    check('tokens, read by the model on every call' in page.inner_text(visible), 'token estimate shown')
    area.fill('Short module prompt. Limit {{default_limit}}.')
    check(page.evaluate('mcppilot_config.config.prompts.system') == 'Short module prompt. Limit {{default_limit}}.',
          'editing the module prompt updates the config')
    page.click(f'{visible} >> text=Reset to built-in')
    check(area.input_value() == builtin and page.evaluate('mcppilot_config.config.prompts.system') == '',
          'reset to built-in (stored as empty, follows module updates)')
    area.fill('Module prompt v2.')
    page.screenshot(path=f'{SHOTS}/11-config-prompts.png', full_page=True)

    print('\nProfile prompt')
    page.click('button[data-tab=profiles]')
    check(page.is_checked(f'{visible} input[value=default]'), 'demo profile uses the module default prompt')
    check('Module prompt v2.' in page.inner_text(f'{visible} .mcppilot-cfg-preview'),
          'preview shows the current module prompt')
    page.check(f'{visible} input[value=custom]')
    custom = page.locator(f'{visible} .mcppilot-cfg-inner textarea')
    check(custom.input_value() == 'Module prompt v2.', 'custom prompt starts as a copy of the module prompt')
    custom.fill('You are a Zabbix assistant. Always call problem_get first.')
    page.click(f'{visible} >> text=Reset to module default')
    check(custom.input_value() == 'Module prompt v2.', 'reset to module default')
    page.click(f'{visible} >> text=Reset to built-in')
    check(custom.input_value() == builtin, 'reset to built-in from the profile')
    custom.fill('Short custom prompt.')

    print('\nSmall-model options')
    check(page.is_checked(f'{visible} >> text=Require a tool call on the first model call of each question'),
          'demo profile has "Require a tool call" on')
    page.click(f'{visible} >> text=Lean preset for small models')
    trim = json.loads(page.evaluate("""[...document.querySelectorAll('textarea')]
        .find((t) => t.nextElementSibling?.textContent.includes('Lean preset')).value"""))
    check(trim.get('problem_get') == ['eventid', 'name', 'severity', 'clock', 'opdata'], 'lean preset fills kept fields')
    page.click(f'{visible} >> text=Test connection')
    page.wait_for_selector('.mcppilot-cfg-test.color-positive')
    check('Forced tool calls work' in page.inner_text('.mcppilot-cfg-test.color-positive'), 'test result shown')
    page.screenshot(path=f'{SHOTS}/12-config-profile.png', full_page=True)

    print('\nLayout inside a Zabbix-like popup body')
    for tab in ('mcp', 'prompts', 'profiles', 'access'):
        page.click(f'button[data-tab={tab}]')
        overflow = page.evaluate('''() => {
            const body = document.getElementById('dialogue-body');
            const limit = body.getBoundingClientRect().right + 1;
            return [...body.querySelectorAll('*')]
                .filter((el) => el.offsetParent !== null && el.getBoundingClientRect().right > limit)
                .map((el) => el.tagName + '.' + el.className).slice(0, 5);
        }''')
        check(not overflow, f'nothing cut on the right on the "{tab}" tab' + (f': {overflow}' if overflow else ''))
    page.click('button[data-tab=profiles]')
    page.screenshot(path=f'{SHOTS}/13-config-popup-width.png', full_page=True)

    print('\nRequired fields')
    def label_of(text):
        return f'{visible} .mcppilot-cfg-grid > label:text-is("{text}")'
    page.click('button[data-tab=mcp]')
    check(page.get_attribute(label_of('URL'), 'class') == 'form-label-asterisk'
          and page.get_attribute(label_of('Timeout (s)'), 'class') == 'form-label-asterisk',
          'MCP URL and timeout marked with an asterisk')
    page.click('button[data-tab=profiles]')
    check('form-label-asterisk' in (page.get_attribute(label_of('ID'), 'class') or '')
          and 'form-label-asterisk' in (page.get_attribute(label_of('Base URL'), 'class') or ''),
          'profile ID and Base URL marked with an asterisk')
    check('form-label-asterisk' not in (page.get_attribute(label_of('Model'), 'class') or ''), 'optional fields unmarked')

    def number_input(text):
        return page.locator(label_of(text)).locator('xpath=following-sibling::div[1]//input')
    number_input('Maximum limit').fill('20')
    check('Tokens per minute' not in page.inner_text(visible) and 'refused' in page.inner_text(visible),
          'maximum-limit hint shown, no tokens per minute')

    posted_before = page.evaluate('posted.length')
    page.click('button[data-tab=mcp]')
    url_input = page.locator(label_of('URL')).locator('xpath=following-sibling::div[1]//input')
    good_url = url_input.input_value()
    url_input.fill('')
    page.click('button[data-tab=access]')
    page.click('.js-submit')
    check(page.evaluate('posted.length') == posted_before, 'empty MCP URL: nothing is saved')
    check(page.is_visible('.mcppilot-cfg-panel:not([hidden]) >> text=Streamable HTTP endpoint'),
          'empty MCP URL: the MCP server tab is shown')
    check('has-error' in url_input.get_attribute('class') and 'URL is required' in page.inner_text('.msg-bad'),
          'empty MCP URL: field highlighted and explained')
    url_input.fill(good_url)
    check('has-error' not in (url_input.get_attribute('class') or ''), 'highlight cleared when edited')

    timeout_input = page.locator(label_of('Timeout (s)')).locator('xpath=following-sibling::div[1]//input')
    timeout_input.fill('500')
    page.click('.js-submit')
    check('from 3 to 120' in page.inner_text('.msg-bad') and 'has-error' in timeout_input.get_attribute('class'),
          'MCP timeout out of range rejected')
    timeout_input.fill('15')

    page.click('button[data-tab=profiles]')
    base_input = page.locator(label_of('Base URL')).locator('xpath=following-sibling::div[1]//input')
    good_base = base_input.input_value()
    base_input.fill('demo-llm:8080')
    page.click('button[data-tab=mcp]')
    page.click('.js-submit')
    base_input = page.locator(label_of('Base URL')).locator('xpath=following-sibling::div[1]//input')
    check(page.evaluate('posted.length') == posted_before and 'Base URL is required' in page.inner_text('.msg-bad')
          and 'has-error' in base_input.get_attribute('class'),
          'profile Base URL without http(s): LLM profiles tab shown, field highlighted')
    page.screenshot(path=f'{SHOTS}/14-config-required.png', full_page=True)
    base_input.fill(good_base)

    print('\nSave')
    page.click('.js-submit')
    page.wait_for_function('window.submitted === true')
    saved = page.evaluate('posted[posted.length - 1].body')
    prof = saved['config']['profiles'][0]
    check(saved['config']['prompts']['system'] == 'Module prompt v2.', 'module prompt saved')
    check(prof['prompt_mode'] == 'custom' and prof['system_prompt'] == 'Short custom prompt.', 'profile prompt saved')
    check(prof['require_tool_first'] == 1, 'require tool call saved')
    check(prof['trim_fields']['problem_get'] == ['eventid', 'name', 'severity', 'clock', 'opdata'], 'lean fields saved')
    check('tpm_limit' not in prof and prof['max_limit'] == 20, 'maximum limit saved, no tokens per minute')
    check(prof['api_key'] == '', 'stored API key not sent back')
    check(saved['_csrf_token'] == 'csrf-update', 'CSRF token sent')

    print('\nProvider presets')
    page.click('button[data-tab=profiles]')
    labels = page.eval_on_selector_all('#mcppilot-preset option', 'o => o.map((x) => x.textContent)')
    check(len(labels) == 7 and all(l.endswith(' · tested') or l.endswith(' · untested') for l in labels),
          'seven presets, each marked tested or untested')
    check(labels[0] == 'Local small model (llama.cpp) · tested', 'local small model preset first')
    page.select_option('#mcppilot-preset', 'groq')
    check('8,000 tokens per minute' in page.inner_text('.mcppilot-cfg-preset'), 'preset note shown')

    # Apply to the existing profile: connection settings change, everything else is kept.
    before = page.evaluate('structuredClone(mcppilot_config.config.profiles[0])')
    dialogs = []
    page.once('dialog', lambda d: (dialogs.append(d.message), d.dismiss()))
    page.click('#mcppilot-preset-apply')
    check(dialogs and 'are kept' in dialogs[0], 'apply asks for confirmation')
    check(page.evaluate('mcppilot_config.config.profiles[0]') == before, 'cancelled: nothing changes')
    page.select_option('#mcppilot-preset', 'groq')
    page.once('dialog', lambda d: d.accept())
    page.click('#mcppilot-preset-apply')
    after = page.evaluate('mcppilot_config.config.profiles[0]')
    check(after['base_url'] == 'https://api.groq.com/openai/v1' and after['model'] == 'openai/gpt-oss-120b'
          and after['extra_params'] == '{"reasoning_effort": "low"}' and after['max_tokens'] == 1024,
          'apply sets URL, model and limits')
    kept = ['id', 'name', 'api_key', 'has_api_key', 'tool_allowlist', 'prompt_mode', 'system_prompt',
            'starter_prompts', 'user_groups', 'approval_required']
    check(all(after.get(k) == before.get(k) for k in kept),
          'apply keeps ID, name, API key, tools, prompt, starters, user groups and approval')

    page.select_option('#mcppilot-preset', 'openai')
    page.once('dialog', lambda d: d.accept())
    page.click('#mcppilot-preset-apply')
    check(page.evaluate('mcppilot_config.config.profiles[0].model') == 'openai/gpt-oss-120b',
          'a preset without a model keeps the model already set')

    page.select_option('#mcppilot-preset', 'local-small')
    page.once('dialog', lambda d: d.accept())
    page.click('#mcppilot-preset-apply')
    after = page.evaluate('mcppilot_config.config.profiles[0]')
    check(after['timeout'] == 600 and after['default_limit'] == 5 and after['require_tool_first'] == 1
          and after['trim_fields']['problem_get'] == ['eventid', 'name', 'severity', 'clock', 'opdata'],
          '"Local small model" applied: timeout 600, limit 5, lean fields, tool call required')

    page.select_option('#mcppilot-preset', 'claude')
    page.click('#mcppilot-preset-add')
    profiles = page.evaluate('mcppilot_config.config.profiles')
    new = profiles[-1]
    check(len(profiles) == 2 and new['id'] == 'claude' and new['name'] == 'Claude API (Anthropic)'
          and new['model'] == 'claude-haiku-5-5' and new['temperature'] == '' and new['api_key'] == '',
          'add as new profile: claude profile with the preset settings and no key')
    page.select_option('#mcppilot-preset', 'claude')
    page.click('#mcppilot-preset-add')
    check(page.evaluate('mcppilot_config.config.profiles[2].id') != 'claude', 'second add gets a unique ID')
    page.select_option('#mcppilot-preset', 'local-small')
    page.click('#mcppilot-preset-add')
    new = page.evaluate('mcppilot_config.config.profiles[3]')
    check(new['tool_allowlist'] == ['problem_get'] and len(new['starter_prompts']) == 3,
          'new profile from a preset gets its tools and starter prompts')
    page.select_option('#mcppilot-preset', 'groq')
    page.click('#mcppilot-preset-add')
    new = page.evaluate('mcppilot_config.config.profiles[4]')
    check(new['id'] == 'groq' and len(new['starter_prompts']) == 4
          and new['tool_allowlist'] == ['problem_get', 'host_get', 'hostgroup_get', 'maintenance_get'],
          'Groq preset adds its own starter questions and tools')
    page.screenshot(path=f'{SHOTS}/15-config-presets.png', full_page=True)
    page.click('.js-submit')
    page.wait_for_function('posted.length > 0 && posted[posted.length - 1].body.config.profiles.length === 5')
    saved = page.evaluate('posted[posted.length - 1].body')
    check(all(p['api_key'] == '' for p in saved['config']['profiles']), 'no API key in the saved data')

    check(not errors, 'no JavaScript errors' + (': ' + '; '.join(errors) if errors else ''))
    browser.close()

os.unlink(page_file)
print('\n' + ('ALL CONFIG CHECKS PASSED' if not failures else f'{len(failures)} CONFIG CHECKS FAILED'))
sys.exit(1 if failures else 0)
