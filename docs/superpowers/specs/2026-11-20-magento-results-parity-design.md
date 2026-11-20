# Magento results parity with WooCommerce — design

Date: 2026-11-20. Status: approved in conversation with the owner; builder check done (answers below).
Repo: `answereng-magento` (module `Fullspec_AnswerEng`). Builds on branch `feat/status-page`
(PR #4: the status page at Catalog › AnswerEng › Status & AI Visibility, "Out of sync", the
test-engine note). Branch from `feat/status-page` until PR #4 merges, then rebase on `main`.

## Intent

A Magento store owner should never have to leave the Magento admin to see AnswerEng results or
act on them. Today the Magento status page shows only the headline visibility figure and a link
to app.answereng.io. The WooCommerce plugin already shows the full picture inside WordPress:
the trend, per-engine results, share of voice, and, most valuable to the owner, the drafted
fixes with a copy button and "Mark as applied". The owner's words: "Let's make the Magento plugin
just as rich as the WooCommerce plugin. All of those things that are in the WooCommerce plugin
should also be in the Magento plugin."

The owner singled out the drafted fixes (recommendations) as the most valuable thing to have
on the plugin screen.

## Goals

1. Everything the WooCommerce results page shows appears on the Magento status page for a
   store view, with the same behavior and wording, adapted to Magento conventions.
2. The drafted fixes are prominent: reachable from the top of the page, open by default.
3. The WooCommerce settings-page extras that apply to Magento come across: the "What data
   leaves this site" disclosure, the "No API key yet?" onboarding, and an auto-sync switch.
4. The Magento module's frozen engine contract (`Test/Fixtures`) matches the engine's current
   `main`.

## Non-goals

- The one-time sign-in link from Magento to app.answereng.io (needs an engine endpoint; a
  separate project).
- Linking a fix to its product's edit page or applying a fix automatically. Fixes don't carry a
  SKU, and WooCommerce doesn't do this either.
- "Rate it on WordPress.org" (WordPress-specific; there's no Magento Marketplace listing yet).
- Charts on the "All Store Views" overview. It stays a table.
- Any engine or dashboard change.

## Anti-goals (a failure even if it technically works)

- Fixes buried below sync, activity or the danger zone.
- Anything changes the store's catalog on the merchant's behalf. Drafts are suggestions only.
- An engine outage blanks the page. The existing stale-snapshot fallback must cover the new
  sections, as it does on WooCommerce.
- The page fires engine requests per section or per page view beyond the existing cache rules.
- A look that departs from the current page: native Magento admin controls and type, lightly
  branded with AnswerEng green `#294c3b` and lime `#e7edb6` (the PR #3 polish).

## Constraints

- Follow `CLAUDE.md` and the module's load-bearing conventions: core logic in plain PHP behind
  ports, thin Magento adapters; branch only on the engine's `code`, never its `error` text;
  never log the API key or raw response bodies; `Test/Fixtures` is copied from the engine,
  never hand-edited; SQL uses Clock timestamps.
- Magento 2.4.x admin: form keys and ACL on every action, `HttpPostActionInterface` for state
  changes, no inline scripts beyond the existing `data-mage-init` pattern.
- The page renders server-side like today (template + view model). No front-end framework.
- WooCommerce is the reference for behavior and copy: `answereng-woocommerce/admin/class-results-page.php`
  (render order, hero, trend, engine breakdown, share of voice, fixes, applied list, waking notice,
  error notices) and `answereng-woocommerce/includes/class-settings.php` (data disclosure,
  onboarding, auto-sync). When this doc and WooCommerce disagree, this doc wins.

## Decided (by the owner)

- **Layout A: one page, results first**, Magento-style collapsible sections. Order:
  1. Header: mark, title, store key, sync pill, **Refresh** button, Settings link. Under the header,
     when there are suggested fixes: "N drafted fixes to review →", an in-page link to the fixes.
  2. **AI visibility**: the big figure, the change since the last run, the 95% band, and the weekly
     trend chart (WooCommerce `render_hero`, `render_trend`).
  3. **Per AI engine** and **Share of voice** side by side, stacking on narrow screens
     (`render_engine_breakdown`, `render_share_of_voice`).
  4. **Drafted fixes**, open by default (`render_fixes`): per fix the priority, kind, the buyer
     prompt it addresses and its engine, "Why you're missing" reasons, the draft with **Copy draft**
     and **Mark as applied**. Below, a collapsed **Applied (N)** list, each with **Undo**
     (`render_applied_list`). The disclaimer that drafts change nothing until applied by hand.
  5. **Sync** (status, last sync, product count, Sync now, Test connection), **Activity** and
     **Danger zone**: today's content, collapsed by default, at the bottom. Exception, see
     "Alerts stay on top" below.
- The existing preview states (first AI read running/complete), "Out of sync", "Not synced yet",
  "no run yet", pending deletion, disabled and the test-engine note keep working and keep their
  current wording. Where WooCommerce shows only the preview or an empty state, Magento does the
  same and skips sections 2–4.
- Settings page additions: the "What data leaves this site" disclosure, the onboarding line for
  people without a key, and an auto-sync switch.
- Not doing the items listed under Non-goals.

## Decided by Claude (the owner can override)

- **Alerts stay on top.** Anything that needs action stays directly under the header, above
  AI visibility: the sync state message (failed, blocked, retrying, paused), the pending-deletion
  banner with **Cancel deletion**, and the "Out of sync" / "Not synced yet" messages. The Sync
  section opens by default whenever the view isn't healthy (pill other than Synced) or there are
  no results to show, so **Sync now** is one glance away from the message that asks for it. When
  everything is fine, Sync, Activity and Danger zone stay collapsed. Alerts are one or two lines,
  so they don't push the fixes out of reach (the jump link stays above them).
- **"Open full results in AnswerEng →"** stays, with its sign-in hint, at the end of the AI
  visibility section, as today; the test-engine note keeps replacing it for non-production
  engines. The dashboard still has more than the plugin (filters, rejected/refused fixes), and
  the one-time sign-in link will later attach to this same link.

- **Permission:** marking a fix applied (and undo) needs a new ACL resource
  `Fullspec_AnswerEng::fixes` ("Mark drafted fixes applied") under `Fullspec_AnswerEng::status`.
  Viewing and copying drafts need only `::status`. Without `::fixes` the Mark/Undo buttons are
  hidden and the action controller refuses.
- **Refresh** replaces today's "Check again": it drops the cached summary *and* fixes for the
  view and reloads the page (the `RefreshSummary` action, renamed in the UI, same ACL `::status`).
- **Caching:** summary, suggested fixes and applied fixes share the existing per-connection
  fingerprint cache (15 minutes; 60 seconds while there's no completed run). The persisted
  fallback snapshot stores fixes and applied fixes alongside the summary, and the stale banner
  covers all of them. A failed applied-list fetch never blocks the page; the Applied section is
  then hidden (WooCommerce behavior).
- **Bounded wait before stale results.** Today each GET may take two 15-second attempts
  (`EngineClient::READ_TIMEOUT`, one retry), so three sequential reads could hold the page about
  90 seconds before falling back, past many hosts' request timeouts. Instead, the page's engine
  reads share one budget of 20 seconds total:
  - Reads run in order: summary, then suggested fixes, then applied fixes.
  - Each attempt's timeout is the smaller of 15 seconds and the budget left; the retry runs only
    if at least 5 seconds remain.
  - A transient failure (no response, timeout, 5xx, 429) on any read stops the remaining reads
    for that page view. The page then renders from the snapshot (stale banner) for everything
    the snapshot holds, or the existing "Results unavailable" state if there is none.
  - The budget applies to page rendering only. Sync, clear and delete keep their own timeouts.
  - Test against a *hanging* engine (the mock's `slow` mode, which sleeps 35 seconds), not just a
    stopped mock: the page must finish within the budget plus rendering time and show the stale
    results.
- **Mark/undo** use `POST` and `DELETE /stores/{store_key}/fixes/{fix_id}/applied` (idempotent
  on the engine, so trying again is always safe). After any attempt, the outcome decides the
  message:
  - **Success:** drop the cached suggested and applied lists for the view, and redirect back
    with a success message.
  - **The engine answered with an error** (a 4xx with a `code`): the engine refused the change,
    so nothing changed. Show a message for that code, and keep the caches.
  - **No confirmation** (transport failure, timeout or 5xx): the engine may have committed the
    change before the response was lost, so never claim nothing changed. Say "Couldn't confirm
    that change. AnswerEng didn't respond in time. Refresh in a moment to see whether it was
    applied." Drop the cached suggested and applied lists for the view, so the next successful
    read shows the real state. The fallback snapshot stays (it is labeled stale if used).
  - Test a committed write followed by a lost response: add a mock mode that applies the change
    and then closes the connection without answering. The next page load after the engine
    recovers must show the fix as applied.
- **Fix counts** in the jump link and the Applied header come from the fetched lists.
- **Engine labels and colors** follow WooCommerce's `ENGINE_META` (e.g. Perplexity, Claude,
  Llama, Mistral), with the same fallback for unknown engines. A fix's `engine` field is the
  provider (`perplexity`, `bedrock`), not the model, so fixes show provider names: "Perplexity",
  and "Amazon Bedrock" for `bedrock` (never the raw word). Naming the model on fixes needs an
  engine change and is out of scope.
- **Snapshot size:** the fallback snapshot now holds fix lists too; widen its column (currently
  `text`, about 64 KB) in `db_schema.xml` as needed.
- **Share of voice:** the store's own row is always the brand green; competitors are gray;
  footnote that shares don't sum to 100 because answers can name several brands; a link to the
  Competitors setting when there's no share-of-voice data.
- **Trend:** server-rendered inline SVG with the confidence band, shown once two completed runs
  exist (WooCommerce's footnote otherwise). No chart library.
- **Copy draft** uses the Clipboard API from the existing `panel-actions.js` module, with a
  "Copied" confirmation and a fallback that selects the text when the API is unavailable.
- **Auto-sync** switch, matching WooCommerce: `answereng/store/auto_sync`, Yes/No, default Yes,
  settable at default, website and store view scope. When No for a view, only *change-triggered*
  syncs stop: `answereng_sync_dirty` doesn't pick the view up for product or settings changes.
  Still running for that view: the nightly `answereng_reconcile` (so changes, including settings
  saves, reach the engine overnight, as WooCommerce's "otherwise the catalog syncs nightly"),
  retries of a failed sync (`state = 'retrying'`), Sync now and `bin/magento answereng:sync`.
  The status page's sync section says "Auto-sync is off. Your catalog syncs nightly, or when you
  click Sync now." for that view. Default Yes means existing installs behave exactly as today.
- **Data disclosure** sits in a collapsed group on the settings page, listing what Magento
  actually sends, verified against `CatalogBuilder` and the catalog push (SKU, name, short
  description or the cleaned full description, price, category, the configured attributes, brand
  and competitor names) and what is never sent (customer data, orders and payments, product
  images, analytics/telemetry). It names the configured engine URL as the destination, as
  WooCommerce does.
- **Onboarding** line in the API key field's comment: "No API key yet? Get one at answereng.io.
  The free tier needs no card, and your key arrives by email."
- **Contract sync first:** copy `tests/fixtures` from `answereng-engine` `origin/main` (currently
  973b3c5) into `Test/Fixtures` with its `CONTRACT.sha256`. This adds `fix_applied.json` and the
  newer `fixes.json` fields (`prompt_id`, `applied_at`) and refreshes the summary fixtures. Fix
  any test the refresh breaks before starting feature work.
- **Mock engine** (`dev/mock-engine`) serves `GET /stores/{key}/fixes?status=…` and
  `POST/DELETE …/fixes/{id}/applied` from the fixtures, keeping applied state per store in its
  state file, so the local stack exercises every section.
- **Sections** use native `<details>`/`<summary>` (as the Danger zone does today), styled to match.

## Left to the builder

- Class and file layout (e.g. a `FixesProvider` beside `SummaryProvider`, new `EngineClient`
  methods, view-model fields, template partials), following the existing patterns.
- Exact CSS, spacing and responsive breakpoints within the current panel look.
- Unit, Db and template-level tests in the existing style; extending `dev/e2e` and
  `docs/integration-checklist.md` with rows for the new sections, the actions and their ACL.
- Wording not given above: start from WooCommerce's copy; keep it short.

## Done means

- A synced store view with a completed run shows all five parts, in that order, against the
  mock engine on the local stack, with no console errors, at desktop and phone widths.
- Copy draft, Mark as applied and Undo work end to end against the mock; their ACL is enforced
  for a role without `::fixes`; GET and missing/invalid form keys are refused like the other
  actions.
- With the mock stopped, the page falls back to the snapshot with the stale banner, including
  fixes.
- Auto-sync off stops cron syncs for that view only.
- Unit, Db and mock tests pass; phpstan clean; phpcs adds no errors; `setup:di:compile` passes.
- Design doc §7 (admin surface) and README updated.
