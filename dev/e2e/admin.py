"""Tiny admin HTTP client for the local Magento (port 8894). Keeps one cookie jar per admin user.

    from admin import Admin
    a = Admin('admin', 'admin123!local'); a.login()
    html = a.config(store=1)                   # AnswerEng config page at a scope
    a.save_config({'store': {...}}, store=1)   # groups[...] POST
    a.action(html, 'syncnow', {'store': 1})    # POST the panel's action URL with the page's form key
"""
import html as htmllib
import http.cookiejar
import os
import re
import sys
import urllib.parse
import urllib.request

BASE = 'http://localhost:8894'
JARS = os.path.join(os.path.dirname(os.path.abspath(__file__)), '.jars')   # git-ignored


class LocalPolicy(http.cookiejar.DefaultCookiePolicy):
    """Accept domain=localhost cookies (the stdlib rejects dotless domains)."""
    def set_ok_domain(self, cookie, request):
        return True

    def return_ok_domain(self, cookie, request):
        return True

    def domain_return_ok(self, domain, request):
        return True


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Admin:
    def __init__(self, user, password, jar=None):
        os.makedirs(JARS, exist_ok=True)
        self.user, self.password = user, password
        self.jar = http.cookiejar.LWPCookieJar(os.path.join(JARS, (jar or user) + '.txt'), policy=LocalPolicy())
        try:
            self.jar.load(ignore_discard=True)
        except OSError:
            pass
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.raw = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
        self.form_key = None

    def _req(self, url, data=None, follow=True, method=None):
        if not url.startswith('http'):
            url = BASE + url
        body = None if data is None else urllib.parse.urlencode(data, doseq=True).encode()
        req = urllib.request.Request(url, data=body, method=method)
        try:
            resp = (self.opener if follow else self.raw).open(req, timeout=120)
            code, text, loc = resp.status, resp.read().decode('utf-8', 'replace'), resp.headers.get('Location')
        except urllib.error.HTTPError as e:
            code, text, loc = e.code, e.read().decode('utf-8', 'replace'), e.headers.get('Location')
        self.jar.save(ignore_discard=True)
        m = re.search(r"FORM_KEY = '([^']+)'", text) or re.search(r'name="form_key"[^>]*value="([^"]+)"', text)
        if m:
            self.form_key = m.group(1)
        return code, text, loc

    def login(self):
        code, text, _ = self._req('/admin/')
        if 'login[username]' not in text:
            return text
        m = re.search(r'name="form_key"[^>]*value="([^"]+)"', text)
        code, text, _ = self._req('/admin/', {'login[username]': self.user, 'login[password]': self.password,
                                              'form_key': m.group(1)})
        if 'login[username]' in text:
            raise SystemExit('login failed for ' + self.user + ': ' + ' | '.join(messages(text)))
        return text

    def config(self, store=None, website=None, extra=''):
        path = '/admin/admin/system_config/edit/section/answereng/'
        if store is not None:
            path += 'store/%s/' % store
        if website is not None:
            path += 'website/%s/' % website
        code, text, _ = self._req(path + extra)
        if 'login[username]' in text:
            self.login()
            code, text, _ = self._req(path + extra)
        self.last_code = code
        return text

    def status(self, store=None, extra=''):
        path = '/admin/answereng/status/index/'
        if store is not None:
            path += 'store/%s/' % store
        code, text, _ = self._req(path + extra)
        if 'login[username]' in text:
            self.login()
            code, text, _ = self._req(path + extra)
        self.last_code = code
        return text

    def save_config(self, groups, store=None, website=None, page=None):
        page = page or self.config(store=store, website=website)
        action = htmllib.unescape(re.search(r'<form[^>]+action="([^"]+)"[^>]+id="config-edit-form"', page).group(1))
        data = {'form_key': self.form_key}
        for group, fields in groups.items():
            for field, value in fields.items():
                if value is None:   # inherit
                    data['groups[%s][fields][%s][inherit]' % (group, field)] = '1'
                else:
                    data['groups[%s][fields][%s][value]' % (group, field)] = value
        code, text, _ = self._req(action, data)
        return messages(text), text

    def action_url(self, page, name):
        for m in re.finditer(r'data-answereng-action="([^"]*)"', page):
            url = htmllib.unescape(m.group(1))
            if '/answereng/action/%s/' % name in url:
                return url
        # Not rendered (hidden by ACL): build it from the backend url pattern with the page's key-less route
        return BASE + '/admin/answereng/action/%s/' % name

    def post(self, url, fields, form_key=True, follow=True):
        data = dict(fields)
        if form_key:
            data['form_key'] = self.form_key
        return self._req(url, data, follow=follow)

    def get(self, url, follow=True):
        return self._req(url, follow=follow)


def messages(text):
    out = []
    for m in re.finditer(r'<div[^>]*data-ui-id="messages-message-(\w+)"[^>]*>(.*?)</div>', text, re.S):
        out.append(m.group(1) + ': ' + re.sub(r'\s+', ' ', htmllib.unescape(re.sub(r'<[^>]+>', '', m.group(2)))).strip())
    return out


def panel_text(page):
    m = re.search(r'<div class="answereng-panel".*?(?=<!-- /answereng-panel -->)', page, re.S)
    chunk = m.group(0) if m else ''
    chunk = re.sub(r'<script.*?</script>', '', chunk, flags=re.S)
    chunk = re.sub(r'<(br|p|tr|div|summary|details)[^>]*>', '\n', chunk)
    chunk = re.sub(r'<[^>]+>', ' ', chunk)
    return re.sub(r'[ \t]+', ' ', re.sub(r'\n\s*\n+', '\n', htmllib.unescape(chunk))).strip()
