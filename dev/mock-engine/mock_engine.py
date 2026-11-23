"""Local stand-in for the AnswerEng engine connector API (spec §11).

Serves the frozen contract from Test/Fixtures: error bodies from
error_codes.json and summaries from read_api/. Each API key maps to a stable
fake account (acct_ + sha256(key)[:32]), so two keys simulate two
workspaces. Failure modes are switched with POST /__mode {"mode": "..."}.

Run:  python3 dev/mock-engine/mock_engine.py --port 8895 --store-cap 1  [--fresh]
"""
import argparse
import datetime
import hashlib
import json
import os
import pathlib
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, unquote, urlsplit

ROOT = pathlib.Path(__file__).resolve().parents[2]
FIXTURES = ROOT / "Test" / "Fixtures"
FIX_STATUSES = ("suggested", "approved", "rejected", "refused", "applied")
RECEIVED = pathlib.Path(__file__).resolve().parent / "received"
DEFAULT_STATE_FILE = pathlib.Path(__file__).resolve().parent / "state.json"
ERRORS = json.loads((FIXTURES / "error_codes.json").read_text())
MODES = {"normal", "cold-504", "slow", "throttle-429", "invalid-key", "store-limit", "product-limit",
         "ownership", "run-in-progress", "missing-code", "purging", "preview-running", "preview-complete", "lost-response"}


def account_for(key):
    return "acct_" + hashlib.sha256(key.encode()).hexdigest()[:32]


def err(code, **extra):
    body = dict(ERRORS["lambda"][code])
    body.update(extra)
    return body


class State:
    """Stores and ids; with a state file they are loaded at start and saved after every change."""

    def __init__(self, store_cap, state_file=None):
        self.store_cap = store_cap
        self.mode = "normal"
        self.stores = {}   # key -> {"owner", "products", "status", "purge_after", "id"}
        self.next_id = 1
        self.state_file = pathlib.Path(state_file) if state_file else None
        self.lock = threading.Lock()
        if self.state_file and self.state_file.exists():
            saved = json.loads(self.state_file.read_text())
            self.stores, self.next_id = saved["stores"], saved["next_id"]

    def save(self):
        if not self.state_file:
            return
        with self.lock:
            tmp = self.state_file.with_suffix(".tmp")
            tmp.write_text(json.dumps({"stores": self.stores, "next_id": self.next_id}))
            os.replace(tmp, self.state_file)


def make_server(address, store_cap=1, state_file=None):
    state = State(store_cap, state_file)

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, fmt, *args):  # keep test output quiet
            pass

        def _send(self, status, body, account=None, headers=None):
            raw = b"" if body is None else json.dumps(body).encode()
            if self.command in ("POST", "DELETE"):
                state.save()  # before answering, so a client that restarts us next sees the change
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(raw)))
            if account:
                self.send_header("X-AnswerEng-Account", account)
            for name, value in (headers or {}).items():
                self.send_header(name, value)
            self.end_headers()
            self.wfile.write(raw)

        def _body(self):
            length = int(self.headers.get("Content-Length") or 0)
            if not length:
                return None
            try:
                return json.loads(self.rfile.read(length))
            except ValueError:
                return None

        def _route(self, method):
            path = self.path.split("?")[0]
            body = self._body()
            if path == "/__mode" and method == "POST":
                mode = (body or {}).get("mode", "normal")
                if mode not in MODES:
                    return self._send(400, {"error": "unknown mode", "modes": sorted(MODES)})
                state.mode = mode
                return self._send(200, {"mode": mode})

            key = self.headers.get("x-api-key")
            if not key or state.mode == "invalid-key":
                return self._send(403, ERRORS["gateway"]["INVALID_API_KEY"])
            acct = account_for(key)
            mode = state.mode
            if mode == "cold-504":
                return self._send(504, None)
            if mode == "slow":
                time.sleep(35)
            if mode == "throttle-429":
                return self._send(429, ERRORS["gateway"]["THROTTLED"], headers={"Retry-After": "60"})
            if mode == "missing-code":
                return self._send(403, {"error": "refused"}, acct)

            parts = [unquote(p) for p in path.strip("/").split("/")]
            if parts == ["catalog"] and method == "POST":
                return self._push(body, acct)
            if parts == ["stores"] and method == "GET":
                return self._send(200, [
                    {"store_key": k, "latest_run_at": None, "status": v["status"], "purge_after": v["purge_after"]}
                    for k, v in sorted(state.stores.items()) if v["owner"] == acct], acct)
            if len(parts) >= 2 and parts[0] == "stores":
                store_key = parts[1]
                store = state.stores.get(store_key)
                if store is None or store["owner"] != acct:
                    return self._send(404, err("unknown_store"), acct)
                rest = parts[2:]
                if rest == ["summary"] and method == "GET":
                    if store["status"] != "active":
                        return self._send(409, err("store_pending_deletion", purge_after=store["purge_after"]), acct)
                    name = "summary.json" if store["products"] else "summary_no_runs.json"
                    if mode in ("preview-running", "preview-complete") and store["products"]:
                        name = f"summary_{mode.replace('-', '_')}.json"
                    summary = json.loads((FIXTURES / "read_api" / name).read_text())
                    summary["store_key"] = store_key
                    if "suggested_fixes" in summary:
                        applied = store.get("applied", {})
                        summary["suggested_fixes"] = sum(1 for f in all_fixes()
                                                         if str(f["fix_id"]) not in applied)
                    return self._send(200, summary, acct)
                if rest == ["fixes"] and method == "GET":
                    return self._fixes(store, path_query(self.path).get("status", "suggested"), acct)
                if len(rest) == 3 and rest[0] == "fixes" and rest[2] == "applied" and method in ("POST", "DELETE"):
                    return self._applied(store, rest[1], method, acct)
                if rest == ["catalog"] and method == "DELETE":
                    if mode == "run-in-progress":
                        return self._send(409, err("run_in_progress"), acct)
                    if store["status"] != "active":
                        return self._send(409, err("store_pending_deletion", purge_after=store["purge_after"]), acct)
                    deleted, store["products"] = len(store["products"]), []
                    return self._send(200, {"store_key": store_key, "products_deleted": deleted}, acct)
                if rest == [] and method == "DELETE":
                    if (body or {}).get("confirm_store_key") != store_key:
                        return self._send(422, err("confirmation_mismatch"), acct)
                    if mode == "run-in-progress":
                        return self._send(409, err("run_in_progress"), acct)
                    if store["status"] == "active":
                        store["status"] = "pending_deletion"
                        store["purge_after"] = (datetime.datetime.now(datetime.timezone.utc)
                                                + datetime.timedelta(days=7)).isoformat()
                    return self._send(202, {"store_key": store_key, "status": "pending_deletion",
                                            "purge_after": store["purge_after"]}, acct)
                if rest == ["restore"] and method == "POST":
                    if mode == "purging":
                        return self._send(409, err("purge_in_progress"), acct)
                    store["status"], store["purge_after"] = "active", None
                    return self._send(200, {"store_key": store_key, "status": "active"}, acct)
            return self._send(404, err("invalid_request", error="unknown resource"), acct)

        def _fixes(self, store, status, acct):
            if status not in FIX_STATUSES:
                return self._send(422, err("invalid_request",
                                           error=f"invalid status; expected one of {FIX_STATUSES}"), acct)
            applied = store.setdefault("applied", {})
            out = []
            for fix in all_fixes():
                when = applied.get(str(fix["fix_id"]))
                if status == "suggested" and when is None:
                    out.append(dict(fix, applied_at=None))
                elif status == "applied" and when is not None:
                    out.append(dict(fix, applied_at=when))
            return self._send(200, out, acct)

        def _applied(self, store, fix_id, method, acct):
            fix = next((f for f in all_fixes() if str(f["fix_id"]) == fix_id), None)
            if fix is None:
                return self._send(404, err("unknown_store"), acct)
            if fix["status"] == "refused":
                return self._send(409, err("invalid_request", error="fix was refused"), acct)
            applied = store.setdefault("applied", {})
            if method == "POST":   # re-marking keeps the original timestamp, as the engine does
                applied.setdefault(fix_id, datetime.datetime.now(datetime.timezone.utc).isoformat())
            else:
                applied.pop(fix_id, None)
            when = applied.get(fix_id)
            if state.mode == "lost-response":
                state.save()   # the change lands, the answer never does
                self.close_connection = True
                return None
            return self._send(200, dict(fix, applied_at=when), acct)

        def _push(self, body, acct):
            errors = validate(body)
            if errors:
                return self._send(422, err("validation_failed", errors=errors))
            mode = state.mode
            if mode == "product-limit":
                return self._send(403, err("product_limit"), acct)
            if mode == "store-limit":
                return self._send(403, err("store_limit"), acct)
            if mode == "ownership":
                return self._send(403, err("store_owned_elsewhere"), acct)
            key = body["store_key"]
            store = state.stores.get(key)
            if store is not None and store["owner"] != acct:
                return self._send(403, err("store_owned_elsewhere"), acct)
            if store is None:
                owned = sum(1 for s in state.stores.values() if s["owner"] == acct)
                if owned >= state.store_cap:
                    return self._send(403, err("store_limit", limit=state.store_cap,
                                               error=f"plan allows {state.store_cap} store(s)"), acct)
                store = state.stores[key] = {"owner": acct, "products": [], "status": "active",
                                             "purge_after": None, "id": state.next_id, "applied": {}}
                state.next_id += 1
            if store["status"] != "active":
                return self._send(409, err("store_pending_deletion", purge_after=store["purge_after"]), acct)
            store["products"] = body["products"]
            RECEIVED.mkdir(exist_ok=True)
            stamp = datetime.datetime.now().strftime("%Y%m%dT%H%M%S%f")
            (RECEIVED / f"{stamp}-{key.replace('/', '_')}.json").write_text(json.dumps(body, indent=1))
            return self._send(200, {"store_id": store["id"]}, acct)

        def do_GET(self):
            self._route("GET")

        def do_POST(self):
            self._route("POST")

        def do_DELETE(self):
            self._route("DELETE")

    return ThreadingHTTPServer(address, Handler)


def all_fixes():
    return json.loads((FIXTURES / "read_api" / "fixes.json").read_text())


def path_query(raw):
    return {k: v[-1] for k, v in parse_qs(urlsplit(raw).query).items()}


def validate(body):
    """A pydantic-shaped subset of the engine's CatalogPush validation."""
    errors = []

    def bad(loc, typ, msg):
        errors.append({"type": typ, "loc": loc, "msg": msg, "input": None})

    if not isinstance(body, dict):
        bad([], "dict_type", "Input should be a valid dictionary")
        return errors
    if not isinstance(body.get("store_key"), str):
        bad(["store_key"], "string_type", "Input should be a valid string")
    brands = body.get("brand_names")
    if not isinstance(brands, list) or not brands:
        bad(["brand_names"], "too_short", "List should have at least 1 item")
    products = body.get("products")
    if not isinstance(products, list) or not products:
        bad(["products"], "too_short", "List should have at least 1 item")
        return errors
    for i, p in enumerate(products):
        for field in ("sku", "title"):
            if not isinstance(p.get(field), str):
                bad(["products", i, field], "string_type", "Input should be a valid string")
        if p.get("price") is not None and not isinstance(p.get("price"), (int, float)):
            bad(["products", i, "price"], "float_type", "Input should be a valid number")
        if not isinstance(p.get("attributes", {}), dict):
            bad(["products", i, "attributes"], "dict_type", "Input should be a valid dictionary")
    return errors


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default="127.0.0.1", help="bind address; use 0.0.0.0 so Docker (host.docker.internal) can reach it")
    parser.add_argument("--port", type=int, default=8895)
    parser.add_argument("--store-cap", type=int, default=1)
    parser.add_argument("--state-file", default=str(DEFAULT_STATE_FILE),
                        help="where stores are kept across restarts; pass an empty string to keep them in memory only")
    parser.add_argument("--fresh", action="store_true", help="start with no stores (deletes the state file)")
    args = parser.parse_args()
    if args.fresh and args.state_file:
        pathlib.Path(args.state_file).unlink(missing_ok=True)
    server = make_server((args.host, args.port), store_cap=args.store_cap, state_file=args.state_file or None)
    print(f"mock engine on http://{args.host}:{args.port} (store cap {args.store_cap}, "
          f"state {args.state_file or 'in memory'})")
    server.serve_forever()
