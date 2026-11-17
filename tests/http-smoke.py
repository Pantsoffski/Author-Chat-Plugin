"""HTTP smoke tests restricted to the existing localhost Author Chat WP 6.9/PHP 8.2 test container."""
import http.cookiejar
import json
import re
import secrets
import subprocess
import urllib.error
import urllib.parse
import urllib.request

CONTAINER = 'wp-env-authorchat-compat-f9dafc91-tests-cli-1'
result = subprocess.run(['docker', 'exec', CONTAINER, 'wp', 'eval-file', 'wp-content/author-chat-test-harness/http-fixture.php'], capture_output=True, text=True, encoding='utf-8', check=True)
fixture = json.loads(result.stdout)
base = fixture['base']
assert base == 'http://localhost:8929'

def request(client, path, data=None):
    payload = urllib.parse.urlencode(data).encode() if data is not None else None
    try:
        response = client.open(base + path, data=payload, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read().decode('utf-8')

def client_for(name):
    client = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    user = fixture['users'][name]
    assert request(client, '/wp-login.php')[0] == 200
    status, page = request(client, '/wp-login.php', {'log':user['login'], 'pwd':user['password'], 'wp-submit':'Log In', 'redirect_to':base+'/wp-admin/', 'testcookie':'1'})
    assert status == 200 and 'login_error' not in page, 'Local login failed'
    status, page = request(client, '/wp-admin/index.php')
    match = re.search(r'var acppauthorchat_ajax\s*=\s*(\{[^;]+\});', page)
    assert status == 200 and match, 'Authenticated dashboard is missing AJAX configuration'
    assert 'PHP Fatal' not in page and 'Warning:' not in page
    return client, json.loads(match.group(1))['nonce']

clients = {name:client_for(name) for name in fixture['users']}

def ajax(name, operation, expected=200, **fields):
    client, nonce = clients[name]
    data = dict(action='acppauthorchat', function=operation, nonce=nonce)
    data.update(fields)
    status, body = request(client, '/wp-admin/admin-ajax.php', data)
    assert status == expected, f'{operation}: expected HTTP {expected}, got {status}: {body[:160]}'
    return json.loads(body)

room = ajax('owner','addRoom', request_id=secrets.token_hex(16))['chat_room_id']
ajax('owner','addUser',room_id=room,user_id=fixture['users']['member']['id'])
for label, (message, expected) in fixture['cases']['valid'].items():
    reply = ajax('member','send',message=message,room_pressed_button_id=room)
    assert reply['content'] == expected, f'HTTP sanitizer mismatch: {label}'
    assert int(reply['user_id']) == fixture['users']['member']['id']
history = ajax('member','initiate',room_pressed_button_id=room,after_id='0')
assert history['msg'] == [case[1] for case in fixture['cases']['valid'].values()]
print('PASS: HTTP login/dashboard, authenticated AJAX, persisted sanitization and all valid payloads')
for message in ['<script>alert(1)</script>', '<svg onload=alert(1)></svg>', '<img src=x onerror=alert(1)>', '<b> </b>', 'x'*1001]:
    ajax('member','send',expected=400,message=message,room_pressed_button_id=room)
ajax('member','send',expected=403,nonce='invalid',message='blocked',room_pressed_button_id=room)
ajax('member','send',expected=400,room_pressed_button_id=room,**{'message[]':'array'})
for operation in ('send','initiate'):
    ajax('outsider',operation,expected=403,message='blocked',room_pressed_button_id=room)
ajax('member','addUser',expected=403,room_id=room,user_id=fixture['users']['outsider']['id'])
assert ajax('owner','initiate',room_pressed_button_id=room,after_id='0')['msg'] == history['msg']
ajax('owner','removeUser',room_id=room,user_id=fixture['users']['member']['id'])
ajax('member','initiate',expected=403,room_pressed_button_id=room)
ajax('member','send',expected=403,message='revoked',room_pressed_button_id=room)
print('PASS: HTTP nonce, invalid input, private-room isolation (including another administrator), ownership and revocation')
for name, expected in [('owner',200), ('member',403)]:
    status, page = request(clients[name][0], '/wp-admin/admin.php?page=acppauthorchat-settings')
    assert status == expected, f'Settings access failed: {name}'
    if status == 200:
        assert 'author_chat_settings_group' in page and 'page=acset' not in page
status, plugins = request(clients['owner'][0], '/wp-admin/plugins.php')
assert status == 200 and 'admin.php?page=acppauthorchat-settings' in plugins
status, script = request(clients['owner'][0], '/wp-content/plugins/author-chat/acppauthorchat_chat.js')
assert status == 200 and 'Author Chat  v2.1.1' in script
anonymous = urllib.request.build_opener(urllib.request.ProxyHandler({}))
status, body = request(anonymous, '/wp-admin/admin-ajax.php', {'action':'acppauthorchat','function':'send','message':'blocked'})
assert status == 400 and body.strip() == '0'
print('PASS: HTTP settings slug, settings capability, plugin action link, JS 2.1.1 asset and anonymous AJAX denial')
