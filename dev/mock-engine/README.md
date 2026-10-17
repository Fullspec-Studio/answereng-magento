# Mock engine

`python3 dev/mock-engine/mock_engine.py --port 8895 --store-cap 1`

Binds 127.0.0.1 by default. For a Magento running in Docker (reaching it via
`host.docker.internal`), bind all interfaces: add `--host 0.0.0.0`.

Implements the connector API the module uses, from the frozen contract in
`Test/Fixtures`. Each API key is its own fake account. Switch failure modes:

    curl -s -X POST localhost:8895/__mode -d '{"mode":"store-limit"}'

Modes: normal, cold-504, slow (35 s), throttle-429 (Retry-After 60),
invalid-key, store-limit, product-limit, ownership, run-in-progress,
missing-code, purging, preview-running and preview-complete (the summary of a
store with products carries the instant-first-read `preview` block and no `latest_run`). Received catalogs land in `received/` (git-ignored).
Purges are not simulated.

Tests: `cd dev/mock-engine && python3 -m unittest test_mock_engine.py -v`
