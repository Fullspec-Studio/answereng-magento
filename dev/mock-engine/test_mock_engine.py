"""Tests for the local mock engine. Run: python3 -m unittest dev/mock-engine/test_mock_engine.py"""
import http.client
import json
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


if __name__ == "__main__":
    unittest.main()
