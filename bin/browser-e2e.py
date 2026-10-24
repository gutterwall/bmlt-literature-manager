#!/usr/bin/env python3
"""Browser checks for bin/e2e.sh in headless Chrome, driven over the DevTools protocol (standard library only).

  browser-e2e.py setup  <site> <bmlt root url>
      An administrator saves the Root Server URL, tests the connection and chooses Areas on BMLT / Areas, then opens
      every Literature admin screen.
  browser-e2e.py orders <site> <items.json> <mail folder> <label>
      A visitor orders through the public form (meeting search, quantities, live totals and special-order flags,
      review with a changed quantity, confirmation); the emails arrive at the SMTP sink; a worker restricted to
      Riverside sees only Riverside's orders and marks one Ready, emailing the requester; an unrestricted worker sees
      every order.
"""
import base64, email, email.policy, glob, json, os, re, socket, struct, subprocess, sys, tempfile, time, urllib.request
from urllib.parse import urlparse

PORT = int(os.environ.get('CHROME_DEBUG_PORT', '9335'))


class WS:
    def __init__(self, url):
        u = urlparse(url)
        self.s = socket.create_connection((u.hostname, u.port))
        key = base64.b64encode(os.urandom(16)).decode()
        self.s.sendall((f'GET {u.path} HTTP/1.1\r\nHost: {u.hostname}:{u.port}\r\nUpgrade: websocket\r\n'
                        f'Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n').encode())
        head = b''
        while b'\r\n\r\n' not in head:
            head += self.s.recv(1)
        assert b' 101 ' in head.split(b'\r\n')[0], head

    def _read(self, n):
        out = b''
        while len(out) < n:
            chunk = self.s.recv(n - len(out))
            if not chunk:
                raise ConnectionError('closed')
            out += chunk
        return out

    def send(self, text):
        data = text.encode()
        mask = os.urandom(4)
        header = bytes([0x81])
        n = len(data)
        if n < 126:
            header += bytes([0x80 | n])
        elif n < 65536:
            header += bytes([0x80 | 126]) + struct.pack('>H', n)
        else:
            header += bytes([0x80 | 127]) + struct.pack('>Q', n)
        self.s.sendall(header + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(data)))

    def recv(self):
        message = b''
        while True:
            b1, b2 = self._read(2)
            n = b2 & 0x7f
            if n == 126:
                n = struct.unpack('>H', self._read(2))[0]
            elif n == 127:
                n = struct.unpack('>Q', self._read(8))[0]
            payload = self._read(n)
            if (b1 & 0x0f) == 9:
                continue
            message += payload
            if b1 & 0x80:
                return message.decode()


class Browser:
    """Headless Chrome with a fresh profile; collects console errors, exceptions and every request URL."""

    def __init__(self):
        self.profile = tempfile.mkdtemp(prefix='lit-chrome-')
        self.proc = subprocess.Popen([os.environ.get('CHROME', 'google-chrome'), '--headless=new', f'--remote-debugging-port={PORT}',
                                      f'--user-data-dir={self.profile}', '--no-first-run', '--no-default-browser-check', '--disable-gpu',
                                      '--lang=en-US', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        pages = []
        for i in range(180):
            try:
                pages = [t for t in json.load(urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json/list', timeout=5)) if t['type'] == 'page']
                if pages:
                    break
                if i >= 8:
                    urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{PORT}/json/new?about:blank', method='PUT'), timeout=5).read()
            except Exception:
                pass
            time.sleep(0.25)
        if not pages:
            self.close()
            sys.exit('Chrome did not start')
        self.ws = WS(pages[0]['webSocketDebuggerUrl'])
        self.n = 0
        self.errors, self.requests = [], []
        for domain in ('Page', 'Runtime', 'Network', 'Log'):
            self.call(domain + '.enable')

    def _event(self, msg):
        m, p = msg.get('method'), msg.get('params', {})
        if m == 'Runtime.exceptionThrown':
            self.errors.append('exception: ' + p['exceptionDetails'].get('text', '') + ' ' + json.dumps(p['exceptionDetails'].get('exception', {}).get('description', ''))[:300])
        elif m == 'Runtime.consoleAPICalled' and p.get('type') in ('error', 'assert'):
            self.errors.append('console: ' + ' '.join(str(a.get('value', a.get('description', ''))) for a in p.get('args', [])))
        elif m == 'Log.entryAdded' and p['entry'].get('level') == 'error' and 'favicon' not in p['entry'].get('url', ''):
            self.errors.append('log: ' + p['entry'].get('text', '') + ' ' + p['entry'].get('url', ''))
        elif m == 'Network.requestWillBeSent':
            self.requests.append(p['request']['url'])

    def call(self, method, **params):
        self.n += 1
        self.ws.send(json.dumps({'id': self.n, 'method': method, 'params': params}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get('id') == self.n:
                if 'error' in msg:
                    raise RuntimeError(f'{method}: {msg["error"]}')
                return msg.get('result', {})
            self._event(msg)

    def js(self, expression):
        r = self.call('Runtime.evaluate', expression=expression, returnByValue=True, awaitPromise=True)
        return r.get('result', {}).get('value')

    def wait(self, check, timeout=30):
        end = time.time() + timeout
        while time.time() < end:
            try:
                if self.js(check):
                    return
            except Exception:
                pass
            time.sleep(0.25)
        raise TimeoutError(check + '\n' + (self.js('document.body ? document.body.innerText.slice(0, 1500) : ""') or ''))

    def go(self, url):
        # Mark the current page: readyState is already "complete" there, so without the mark the wait could pass
        # before the new page has replaced it.
        self.js('window.__e2eLeaving = true')
        self.call('Page.navigate', url=url)
        self.wait('!window.__e2eLeaving && document.readyState === "complete"')

    def login(self, site, user, password):
        self.call('Network.clearBrowserCookies')
        self.go(site + '/wp-login.php')
        self.wait('!!document.getElementById("wp-submit")')
        # Submit from a jQuery ready callback, so it runs after WordPress's own: user-profile.js, which wp-login.php
        # loads, sets up its form there, and its beforeunload handler throws if the page is left before that.
        self.js('(function () { var submit = function () {'
                f'document.getElementById("user_login").value={json.dumps(user)};'
                f'document.getElementById("user_pass").value={json.dumps(password)};'
                'document.getElementById("wp-submit").click(); };'
                'window.jQuery ? jQuery(submit) : submit(); })(); true')
        time.sleep(0.5)
        self.wait('document.readyState === "complete" && !location.href.includes("wp-login.php")')

    def close(self):
        try:
            self.proc.terminate()
            self.proc.wait(10)
        except Exception:
            pass
        subprocess.run(['rm', '-rf', self.profile], stderr=subprocess.DEVNULL)



def ok(text):
    print('  ok   ' + text)


def fail(text):
    print('  FAIL ' + text)
    sys.exit(1)


def critical(b, where):
    text = b.js('document.body ? document.body.innerText : ""') or ''
    for bad in ('There has been a critical error', 'Fatal error', 'Warning:', 'Notice:', 'Deprecated:'):
        if bad in text:
            fail(f'{where}: "{bad}" on the page: ' + text[:300])


def js_str(value):
    return json.dumps(value)


def setup(site, bmlt_root):
    b = Browser()
    try:
        b.login(site, 'admin', 'Admin-pass-1')
        b.go(site + '/wp-admin/admin.php?page=bmlt-lit-bmlt')
        b.js('const r = document.getElementById("bmlt-lit-root"); r.value = ' + js_str(bmlt_root)
             + '; r.form.querySelector("[type=submit]").click(); true')
        b.wait('document.body.innerText.includes("BMLT settings saved")')
        ok('Root Server URL saved through the BMLT / Areas form')
        b.js('document.querySelector(".bmlt-lit-test-button").click(); true')
        b.wait('/Connection successful/.test(document.body.innerText) && /Service bodies detected\\D*8/.test(document.body.innerText)', 30)
        ok('Test BMLT Connection: successful, 8 service bodies')
        b.go(site + '/wp-admin/admin.php?page=bmlt-lit-bmlt')
        b.js('["101","102","103"].forEach(v => { document.querySelector(`input[name="areas[]"][value="${v}"]`).checked = true; });'
             ' document.querySelector(`input[name="areas[]"]`).form.querySelector("[type=submit]").click(); true')
        b.wait('document.body.innerText.includes("Saved 3 Area(s). 22 meetings loaded from BMLT.")')
        ok('Riverside, Hillcrest and Lakeside selected: 22 meetings loaded (sub-Area included, unpublished left out)')
        for page in ('bmlt-lit', 'bmlt-lit-orders', 'bmlt-lit-items', 'bmlt-lit-inventory', 'bmlt-lit-import', 'bmlt-lit-smtp',
                     'bmlt-lit-settings', 'bmlt-lit-settings&tab=areas', 'bmlt-lit-settings&tab=templates',
                     'bmlt-lit-settings&tab=permissions', 'bmlt-lit-status'):
            b.go(site + '/wp-admin/admin.php?page=' + page)
            b.wait('!!document.querySelector(".wrap h1")')
            critical(b, page)
        ok('every Literature admin screen opens without errors')
        if b.errors:
            fail('browser errors: ' + '; '.join(b.errors[:5]))
        ok('no JavaScript errors')
    finally:
        b.close()


def money(text):
    return re.sub(r'[^0-9.]', '', text or '')


def mails(folder):
    out = []
    for f in sorted(glob.glob(folder + '/*.eml')):
        m = email.message_from_bytes(open(f, 'rb').read(), policy=email.policy.default)
        body = ''
        for part in m.walk():
            if part.get_content_type() in ('text/plain', 'text/html'):
                body += part.get_content()
        out.append({'to': m.get_all('X-Envelope-To') or [], 'subject': str(m['subject']), 'body': body})
    return out


def wait_mail(folder, check, what, timeout=20):
    end = time.time() + timeout
    while time.time() < end:
        for m in mails(folder):
            if check(m):
                return m
        time.sleep(0.25)
    fail(f'no email {what}; got: ' + '; '.join(f"{m['to']} {m['subject']}" for m in mails(folder)))


def item_sel(item_id, inner=''):
    return js_str(f'.bmlt-lit-item[data-item-id="{item_id}"] {inner}'.strip())


def set_value(b, selector_js, value, event='input'):
    b.js('(() => { const e = document.querySelector(' + selector_js + '); e.value = ' + js_str(str(value))
         + '; e.dispatchEvent(new Event("' + event + '", {bubbles: true})); return true; })()')


def place_order(b, site, meeting_search, meeting_id, lines, name, phone, mail):
    """lines: (item id, quantity typed) or (item id, quantity, text to search for, then press + quantity times)."""
    b.call('Network.clearBrowserCookies')
    b.go(site + '/order-literature/')
    b.wait('!!document.querySelector(".bmlt-lit-cat-tab") && !document.querySelector(".bmlt-lit-meeting-search-wrap").hidden')
    set_value(b, js_str('.bmlt-lit-meeting-search'), meeting_search)
    b.wait('!!document.querySelector(\'.bmlt-lit-meeting-select option[value="' + str(meeting_id) + '"]\')')
    set_value(b, js_str('.bmlt-lit-meeting-select'), meeting_id, 'change')
    for line in lines:
        if len(line) == 3:
            item_id, qty, search = line
            set_value(b, js_str('.bmlt-lit-item-search'), search)
            b.wait('!document.querySelector(' + item_sel(item_id) + ').hidden')
            for _ in range(qty):
                b.js('document.querySelector(' + item_sel(item_id, '.bmlt-lit-qty__btn[data-step="1"]') + ').click(); true')
            set_value(b, js_str('.bmlt-lit-item-search'), '')
        else:
            item_id, qty = line
            set_value(b, item_sel(item_id, '.bmlt-lit-qty__input'), qty)
    for field, value in (('name', name), ('phone', phone), ('email', mail), ('notes', 'Side door, please.\nThanks')):
        b.js('document.querySelector("form[data-bmlt-lit-order-form] [name=bmlt_lit_' + field + ']").value = ' + js_str(value) + '; true')


def submit_and_confirm(b):
    time.sleep(1.2)  # The form refuses submissions faster than a person (minimum fill-in time).
    b.js('document.querySelector(".bmlt-lit-submit").click(); true')
    b.wait('!!document.querySelector(".bmlt-lit-review__title")')
    critical(b, 'review')


def orders(site, items_json, folder, label):
    items = json.load(open(items_json))
    a, bb = items['a'], items['b']
    pa, pb = float(a['price']), float(bb['price'])
    b = Browser()
    try:
        # 1. A visitor orders 7 of item A (5 in stock) and 1 of item B (none in stock) for a Riverside meeting.
        place_order(b, site, 'Just for', 1001, [(a['id'], 7), (bb['id'], 1, bb['sku'])], 'E2E Visitor', '555-0142', 'e2e-visitor@example.org')
        info = b.js('document.querySelector(".bmlt-lit-area-info").innerText')
        if 'Riverside Area' not in info or 'Pick up at the Riverside ASC.' not in info:
            fail('Area information after choosing the meeting: ' + repr(info))
        avail = b.js('JSON.parse(document.querySelector(".bmlt-lit-data").textContent).stock["101"]["' + str(a['id']) + '"][1]')
        flag = b.js('document.querySelector(' + item_sel(a['id'], '.bmlt-lit-item__flag') + ').innerText')
        if 'Special Order' not in flag or f'{7 - avail} of 7' not in flag:
            fail(f'flag for 7 with {avail} available: {flag!r}')
        total = money(b.js('document.querySelector(".bmlt-lit-sum-total").innerText'))
        if total != f'{7 * pa + pb:.2f}':
            fail(f'live total {total}, expected {7 * pa + pb:.2f}')
        if b.js('document.querySelector(".bmlt-lit-special-warning").hidden'):
            fail('no special-order warning')
        ok(f'{label}: meeting search, Riverside details, "Special Order {7 - avail} of 7" ({avail} available), live total {total}, special-order warning')

        submit_and_confirm(b)
        rtotal = money(b.js('document.querySelector(".bmlt-lit-review .bmlt-lit-totals__total dd").innerText'))
        if rtotal != f'{7 * pa + pb:.2f}':
            fail(f'review total {rtotal}')
        names = b.js('[...document.querySelectorAll(".bmlt-lit-review__items tbody tr strong")].map(e => e.innerText)')
        if names != [a['name'], bb['name']]:
            fail(f'review lines {names}')
        set_value(b, js_str(f'#bmlt-lit-rq-{a["id"]}'), 3)
        if b.js('document.querySelector(".bmlt-lit-review__stale").hidden'):
            fail('no "update totals" notice after changing a quantity')
        b.js('document.querySelector(".bmlt-lit-review__refresh").click(); true')
        want = f'{3 * pa + pb:.2f}'
        b.wait('!!document.querySelector(".bmlt-lit-review__title") && document.querySelector(".bmlt-lit-review .bmlt-lit-totals__total dd").innerText.replace(/[^0-9.]/g, "") === "' + want + '"')
        ok(f'{label}: review priced on the server ({rtotal}); quantity changed on the review page and re-priced ({want})')
        b.js('document.querySelector("button[value=confirm]").click(); true')
        b.wait('!!document.getElementById("bmlt-lit-confirmation")')
        number = b.js('document.querySelector(".bmlt-lit-confirmation__number strong").innerText')
        if not re.match(r'^LIT-\d{4}-\d{6}$', number or ''):
            fail(f'order number {number!r}')
        if 'THIS ORDER CONTAINS SPECIAL ORDER ITEMS' not in b.js('document.body.innerText'):
            fail('no special-order notice on the confirmation')
        ok(f'{label}: confirmation {number} with the special-order notice')

        worker = wait_mail(folder, lambda m: number in m['subject'] and 'riverside-lit@example.org' in m['to'], "to Riverside's workers")
        if 'riverside-special@example.org' not in worker['to'] or 'SPECIAL ORDER' not in worker['subject']:
            fail(f'worker email {worker["to"]} {worker["subject"]}')
        if 'hillcrest-lit@example.org' in worker['to']:
            fail("another Area's workers got the order")
        if 'E2E Visitor' not in worker['body'] or 'SPECIAL ORDER NOTICE' not in worker['body']:
            fail('worker email body')
        wait_mail(folder, lambda m: number in m['subject'] and m['to'] == ['e2e-visitor@example.org'], 'to the requester')
        ok(f"{label}: emails through the plugin's SMTP setting: Riverside workers + special-order recipient (\"SPECIAL ORDER\" subject), and the requester")

        # 2. A Hillcrest order, which the Riverside worker must not see.
        place_order(b, site, 'Hilltop', 2001, [(a['id'], 1)], 'Hill Person', '555-0143', 'hill@example.org')
        submit_and_confirm(b)
        b.js('document.querySelector("button[value=confirm]").click(); true')
        b.wait('!!document.getElementById("bmlt-lit-confirmation")')
        hill = b.js('document.querySelector(".bmlt-lit-confirmation__number strong").innerText')

        # 3. The worker restricted to Riverside.
        b.login(site, 'riverside', 'River-pass-1')
        b.go(site + '/literature-workers/')
        links = '[...document.querySelectorAll(".bmlt-lit-link-button")]'
        b.wait(links + '.some(e => e.innerText === "' + number + '")')
        if hill in b.js(links + '.map(e => e.innerText)'):
            fail('a worker restricted to Riverside sees a Hillcrest order')
        b.js(links + '.find(e => e.innerText === "' + number + '").click(); true')
        b.wait('[...document.querySelectorAll("button")].some(e => e.innerText === "Mark Ready")')
        b.js('document.querySelector("input[id^=bmlt-lit-notify-]").checked = true;'
             ' [...document.querySelectorAll("button")].find(e => e.innerText === "Mark Ready").click(); true')
        b.wait('document.body.innerText.includes("Requester notified by email.")')
        wait_mail(folder, lambda m: number in m['subject'] and 'ready' in m['subject'].lower() and m['to'] == ['e2e-visitor@example.org'], 'saying the order is ready')
        ok(f"{label}: the Riverside worker sees only Riverside's orders, marks {number} Ready and the requester is emailed")
        b.js('document.querySelector(\'.bmlt-lit-tab[data-tab="inventory"]\').click(); true')
        b.wait('document.querySelector(\'[data-panel="inventory"]\').innerText.includes(' + js_str(a['name']) + ')')
        ok(f"{label}: the Inventory tab lists Riverside's stock")

        # 4. An unrestricted worker sees both; visitors are asked to log in.
        b.login(site, 'worker', 'Worker-pass-1')
        b.go(site + '/literature-workers/')
        b.wait(links + '.some(e => e.innerText === "' + hill + '")')
        ok(f"{label}: an unrestricted worker sees every Area's orders")
        b.call('Network.clearBrowserCookies')
        b.go(site + '/literature-workers/')
        b.wait('document.body.innerText.includes("Please log in to access the literature tools.")')
        ok(f'{label}: visitors are asked to log in on the workers page')
        if b.errors:
            fail('browser errors: ' + '; '.join(b.errors[:5]))
        ok(f'{label}: no JavaScript errors or failed requests')
    finally:
        b.close()


if len(sys.argv) >= 4 and sys.argv[1] == 'setup':
    setup(sys.argv[2], sys.argv[3])
elif len(sys.argv) >= 6 and sys.argv[1] == 'orders':
    orders(*sys.argv[2:6])
else:
    sys.exit(__doc__)
