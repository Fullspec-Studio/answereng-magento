"""Tests for the local mock engine. Run: python3 -m unittest dev/mock-engine/test_mock_engine.py"""
import http.client
import json
import pathlib
import tempfile
import threading
import unittest

import mock_engine

KEY = "ae_test"
OTHER = "ae_other"


class MockEngineTest(unittest.TestCase):
    def setUp(self):
        self.server = mock_engine.make_server(("127.0.0.1", 0), store_cap=1)
        self.port = self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()

    def call(self, method, path, body=None, key=KEY):
        conn = http.client.HTTPConnection("127.0.0.1", self.port, timeout=5)
        headers = {"Content-Type": "application/json"}
        if key is not None:
            headers["x-api-key"] = key
        conn.request(method, path, None if body is None else json.dumps(body), headers)
        resp = conn.getresponse()
        raw = resp.read()
        return resp.status, (json.loads(raw) if raw else None), dict(resp.getheaders())

    def push(self, store_key="shop.example.com", products=None, key=KEY):
        return self.call("POST", "/catalog", {
            "store_key": store_key, "brand_names": ["Acme"], "competitors": [],
            "products": products if products is not None else [
                {"sku": "S1", "title": "Boot", "description": "", "price": 1.0, "category": "", "attributes": {}}]},
            key=key)

    def test_push_lists_and_carries_the_account_header(self):
        status, _, headers = self.push()
        self.assertEqual(200, status)
        self.assertRegex(headers["X-AnswerEng-Account"], r"^acct_[0-9a-f]{32}$")
        status, stores, _ = self.call("GET", "/stores")
        self.assertEqual([("shop.example.com", "active")], [(s["store_key"], s["status"]) for s in stores])

    def test_validation_store_cap_and_ownership(self):
        status, body, _ = self.push(products=[])
        self.assertEqual((422, "validation_failed"), (status, body["code"]))
        self.push()
        status, body, _ = self.push(store_key="second.example.com")
        self.assertEqual((403, "store_limit", 1), (status, body["code"], body["limit"]))
        status, body, _ = self.push(key=OTHER)
        self.assertEqual((403, "store_owned_elsewhere"), (status, body["code"]))

    def test_missing_key_is_a_gateway_403(self):
        status, body, _ = self.call("GET", "/stores", key=None)
        self.assertEqual((403, "invalid_key", "Forbidden"), (status, body["code"], body["error"]))

    def test_clear_delete_restore_lifecycle(self):
        self.push()
        status, body, _ = self.call("DELETE", "/stores/shop.example.com/catalog")
        self.assertEqual((200, 1), (status, body["products_deleted"]))
        status, body, _ = self.call("DELETE", "/stores/shop.example.com", {"confirm_store_key": "nope"})
        self.assertEqual((422, "confirmation_mismatch"), (status, body["code"]))
        status, body, _ = self.call("DELETE", "/stores/shop.example.com", {"confirm_store_key": "shop.example.com"})
        self.assertEqual((202, "pending_deletion"), (status, body["status"]))
        status, body, _ = self.push()
        self.assertEqual((409, "store_pending_deletion"), (status, body["code"]))
        status, body, _ = self.call("POST", "/stores/shop.example.com/restore")
        self.assertEqual((200, "active"), (status, body["status"]))

    def mode(self, name):
        self.assertEqual(200, self.call("POST", "/__mode", {"mode": name}, key=None)[0])

    def test_modes(self):
        self.push()

        self.mode("cold-504")
        self.assertEqual(504, self.push()[0])

        self.mode("throttle-429")
        status, body, headers = self.push()
        self.assertEqual((429, "throttled", "60"), (status, body["code"], headers["Retry-After"]))

        self.mode("missing-code")
        status, body, _ = self.push()
        self.assertEqual(403, status)
        self.assertNotIn("code", body)

        self.mode("run-in-progress")   # applies to clear and delete, like the real engine
        status, body, _ = self.call("DELETE", "/stores/shop.example.com/catalog")
        self.assertEqual((409, "run_in_progress"), (status, body["code"]))

        self.mode("purging")
        status, body, _ = self.call("POST", "/stores/shop.example.com/restore")
        self.assertEqual((409, "purge_in_progress"), (status, body["code"]))

        self.mode("normal")
        self.assertEqual(200, self.push()[0])

    def test_summary_serves_fixtures(self):
        self.push()
        status, body, _ = self.call("GET", "/stores/shop.example.com/summary")
        self.assertEqual((200, "shop.example.com"), (status, body["store_key"]))
        self.assertIsNotNone(body["latest_run"])
        self.mode("preview-running")
        self.assertEqual("running", self.call("GET", "/stores/shop.example.com/summary")[1]["preview"]["state"])
        self.mode("preview-complete")
        status, body, _ = self.call("GET", "/stores/shop.example.com/summary")
        self.assertEqual((200, "complete", None), (status, body["preview"]["state"], body["latest_run"]))
        self.mode("normal")
        status, body, _ = self.call("GET", "/stores/unknown.example.com/summary")
        self.assertEqual((404, "unknown_store"), (status, body["code"]))


    def fixes(self, status):
        status_code, body, _ = self.call("GET", f"/stores/shop.example.com/fixes?status={status}")
        self.assertEqual(200, status_code)
        return [f["fix_id"] for f in body]

    def test_fixes_suggested_then_applied_then_undo(self):
        self.push()
        self.assertEqual([118, 117], self.fixes("suggested"))
        status, body, _ = self.call("POST", "/stores/shop.example.com/fixes/118/applied")
        self.assertEqual(200, status)
        self.assertIsNotNone(body["applied_at"])
        self.assertEqual([117], self.fixes("suggested"))
        self.assertEqual([118], self.fixes("applied"))
        self.assertEqual([], self.fixes("rejected"))
        self.assertEqual(422, self.call("GET", "/stores/shop.example.com/fixes?status=bogus")[0])
        status, body, _ = self.call("DELETE", "/stores/shop.example.com/fixes/118/applied")
        self.assertEqual((200, None), (status, body["applied_at"]))
        self.assertEqual([118, 117], self.fixes("suggested"))

    def test_unknown_fix_is_404_unknown_store(self):
        self.push()
        status, body, _ = self.call("POST", "/stores/shop.example.com/fixes/999/applied")
        self.assertEqual((404, "unknown_store"), (status, body["code"]))

    def test_lost_response_still_applies(self):
        self.push()
        self.mode("lost-response")
        with self.assertRaises((http.client.RemoteDisconnected, ConnectionError)):
            self.call("POST", "/stores/shop.example.com/fixes/118/applied")
        self.mode("normal")
        self.assertEqual([118], self.fixes("applied"))


class PersistenceTest(unittest.TestCase):
    """With a state file, stores survive a restart (the default when run from the command line)."""

    def serve(self, state_file):
        server = mock_engine.make_server(("127.0.0.1", 0), store_cap=1, state_file=state_file)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        return server.server_address[1]

    def call(self, port, method, path, body=None):
        conn = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
        conn.request(method, path, None if body is None else json.dumps(body),
                     {"Content-Type": "application/json", "x-api-key": KEY})
        resp = conn.getresponse()
        raw = resp.read()
        return resp.status, (json.loads(raw) if raw else None)

    def test_stores_survive_a_restart(self):
        state_file = pathlib.Path(tempfile.mkdtemp()) / "state.json"
        first = self.serve(state_file)
        self.call(first, "POST", "/catalog", {
            "store_key": "localhost", "brand_names": ["Luma"], "competitors": [],
            "products": [{"sku": "S1", "title": "Tee", "description": "", "price": 1.0, "category": "",
                          "attributes": {}}]})
        self.call(first, "DELETE", "/stores/localhost", {"confirm_store_key": "localhost"})
        second = self.serve(state_file)
        status, stores = self.call(second, "GET", "/stores")
        self.assertEqual((200, [("localhost", "pending_deletion")]),
                         (status, [(s["store_key"], s["status"]) for s in stores]))

    def test_applied_survives_restart(self):
        state_file = pathlib.Path(tempfile.mkdtemp()) / "state.json"
        first = self.serve(state_file)
        self.call(first, "POST", "/catalog", {
            "store_key": "localhost", "brand_names": ["Luma"], "competitors": [],
            "products": [{"sku": "S1", "title": "Tee", "description": "", "price": 1.0, "category": "",
                          "attributes": {}}]})
        self.call(first, "POST", "/stores/localhost/fixes/118/applied")
        second = self.serve(state_file)
        status, fixes = self.call(second, "GET", "/stores/localhost/fixes?status=applied")
        self.assertEqual((200, [118]), (status, [f["fix_id"] for f in fixes]))

    def test_without_a_state_file_nothing_is_written(self):
        port = self.serve(None)
        self.assertEqual(404, self.call(port, "GET", "/stores/localhost/summary")[0])

if __name__ == "__main__":
    unittest.main()
