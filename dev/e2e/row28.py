"""Checklist row 28: every answereng/action/* route rejects GET and POSTs without a valid form key."""
import re
from admin import Admin
a = Admin("admin", "admin123!local")
p = a.status(store=1)
fields = {"store": 1, "identity_id": 1, "confirm_key": "localhost", "acknowledge": "1"}
for name in ("syncnow", "testconnection", "clear", "delete", "cancel", "forget"):
    url = a.action_url(p, name)
    c, t, l = a.get(url + "?store=1&identity_id=1&confirm_key=localhost&acknowledge=1", follow=False)
    title = re.findall(r"<title>([^<]*)", t)
    c2, t2, l2 = a.post(url, fields, form_key=False, follow=False)
    c3, t3, l3 = a.post(url, dict(fields, form_key="wrongkey123"), form_key=False, follow=False)
    print("%-15s GET %s %s %s | POST no form_key %s %s | bad form_key %s %s" % (name, c, l, title, c2, l2, c3, l3))
