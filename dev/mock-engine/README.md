# Mock engine

`python3 dev/mock-engine/mock_engine.py --port 8895 --store-cap 1`

Stores are kept in `dev/mock-engine/state.json` (git-ignored), so they survive a
restart. Add `--fresh` to start with no stores, or `--state-file ''` to keep them
in memory only.

Binds 127.0.0.1 by default. For a Magento running in Docker (reaching it via
`host.docker.internal`), bind all interfaces: add `--host 0.0.0.0`.

Implements the connector API the module uses, from the frozen contract in
`Test/Fixtures`. Each API key is its own fake account. Switch failure modes:

    curl -s -X POST localhost:8895/__mode -d '{"mode":"store-limit"}'

Modes: normal, cold-504, slow (35 s), throttle-429 (Retry-After 60),
invalid-key, store-limit, product-limit, ownership, run-in-progress,
missing-code, purging, lost-response (a POST/DELETE `.../applied` is applied and saved, then the connection is dropped with no response), preview-running and preview-complete (the summary of a
store with products carries the instant-first-read `preview` block and no `latest_run`). Received catalogs land in `received/` (git-ignored).
Purges are not simulated.

Fixes: `GET /stores/{key}/fixes?status=suggested|applied` (default `suggested`; the other engine statuses return `[]`,
anything else is 422) serves `Test/Fixtures/read_api/fixes.json`, split by the store's applied set.
`POST|DELETE /stores/{key}/fixes/{fix_id}/applied` marks or unmarks a fix (200 with the fix; re-marking keeps the original
`applied_at`; an unknown fix is 404 `unknown_store`). Applied state is kept per store in the state file.

Tests: `cd dev/mock-engine && python3 -m unittest test_mock_engine.py -v`
