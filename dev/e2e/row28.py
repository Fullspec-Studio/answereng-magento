"""Checklist row 28: every answereng/action/* route rejects GET and POSTs without a valid form key.

Also the fixapplied ACL check (checklist 2026-11-20): a role without ::fixes (aeconfig) is denied a valid-form-key POST.
Run dev/e2e/acl-users.php first if the roles or users are stale.
"""
import re
from admin import Admin
a = Admin("admin", "admin123!local")
p = a.status(store=1)
fields = {"store": 1, "identity_id": 1, "confirm_key": "localhost", "acknowledge": "1", "fix_id": 1, "op": "mark"}
for name in ("syncnow", "testconnection", "clear", "delete", "cancel", "forget", "fixapplied"):
    url = a.action_url(p, name)
    c, t, l = a.get(url + "?store=1&identity_id=1&confirm_key=localhost&acknowledge=1", follow=False)
    title = re.findall(r"<title>([^<]*)", t)
    c2, t2, l2 = a.post(url, fields, form_key=False, follow=False)
    c3, t3, l3 = a.post(url, dict(fields, form_key="wrongkey123"), form_key=False, follow=False)
    print("%-15s GET %s %s %s | POST no form_key %s %s | bad form_key %s %s" % (name, c, l, title, c2, l2, c3, l3))

# fixapplied ACL: aeconfig has no ::fixes, aesync has it (unknown fix id -> the engine's 404, shown as a message)
for user, password in (("aeconfig", "Conf1234!local"), ("aesync", "Sync1234!local")):
    r = Admin(user, password)
    r.login()
    page = r.status(store=1)
    url = r.action_url(page, "fixapplied")
    c, t, l = r.post(url, {"store": 1, "fix_id": 999999, "op": "mark"}, follow=False)
    title = re.findall(r"<title>([^<]*)", t)
    print("%-15s as %-9s valid form_key POST %s %s %s | mark buttons on page: %s" % ("fixapplied", user, c, l, title, "data-answereng-action" in page and "fixapplied" in page))
