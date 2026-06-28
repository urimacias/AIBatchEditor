# AIBatchEditor

An AI-assisted **batch editor** for MediaWiki 1.43+. Select many pages at once,
run AI operations on them (wikilinks, spellcheck, formatting, writing style,
templates, or custom instructions), **preview each change as a diff**, and approve
before saving. Every save goes through MediaWiki's normal edit pipeline, so edits
are attributed, logged, taggable, and revertible.

**Current version:** 1.0.0

**Documentation site:** [GitHub Pages](https://urimacias.github.io/AIBatchEditor/)

## Requirements

- MediaWiki >= 1.43.0
- PHP 8.1+ (required by MediaWiki 1.43; OpenSSL extension enabled)
- A Grok-compatible chat completions API (xAI Grok by default)
- JavaScript enabled in the browser
- Outbound HTTPS from the wiki server to the LLM endpoint (and to allowed remote wikis for the `templates` operation)

## Compatibility

The only formal dependency is **MediaWiki >= 1.43.0** (`extension.json`). The UI uses
core **Vue** (`createMwApp`) and **Codex** (`CodexModule`), which are not available on
MediaWiki 1.42 or older. Tested on MediaWiki **1.43 LTS through 1.45+**.

### Wikis that can use this extension

| Environment | Supported? | Notes |
| --- | --- | --- |
| Self-hosted wiki (Docker, VPS, local) | Yes | Administrator installs the extension and configures the LLM |
| cPanel / shared hosting (PHP-FPM) | Yes | Requires persistent object cache — see [Troubleshooting](#troubleshooting) |
| Private, personal, or corporate wikis | Yes | Same as above |
| Wiki farms (Miraheze, ShoutWiki, etc.) | Maybe | Only if custom extensions and outbound HTTPS to the LLM are allowed |
| Wikimedia production wikis (Wikipedia, Commons, …) | No | Third-party extension; not part of the WMF deployment |

### Operational prerequisites

1. `$wgAIBatchEditorApiUrl` and `$wgAIBatchEditorApiKey` configured (server-side only).
2. Users need the **`aibatchedit`** right (granted to `sysop` by default).
3. Users still need normal **`edit`** permission on each page they save.
4. For **`templates`**: server outbound HTTPS to hosts in `$wgAIBatchEditorTemplateSourceAllowHosts`.

### Page and content limits

Only pages that meet all of the following are processed:

- Exist and are **not redirects**
- Main content model is **`wikitext`** (not JSON, CSS, or other models)
- User can **read** and **edit** the page
- Category batch lists: **content namespaces** only
- Wikitext size within `$wgAIBatchEditorMaxPageSize` (default 2 097 152 bytes / 2 MiB; `0` = no limit)

Does **not** apply to typical non-wikitext system pages, file description pages using other models, or pages the user cannot edit.

## Installation

1. Copy this `AIBatchEditor/` directory into your wiki's `extensions/` folder.
2. Add to `LocalSettings.php`:

   ```php
   wfLoadExtension( 'AIBatchEditor' );

   $wgAIBatchEditorApiUrl = 'https://api.x.ai/v1/chat/completions';
   $wgAIBatchEditorApiKey = getenv( 'XAI_API_KEY' ) ?: '';
   $wgAIBatchEditorModel = 'grok-4.3';
   $wgAIBatchEditorDefaultProfile = 'balanced';
   $wgAIBatchEditorMaxBatch = 50;
   $wgAIBatchEditorRateLimitPerHour = 100;
   $wgAIBatchEditorConcurrency = 1;   // shared hosting: one page per advance request
   $wgAIBatchEditorRequestTimeout = 90;
   $wgAIBatchEditorTemperature = 0.1;
   $wgAIBatchEditorTemplateSourceWiki = 'https://es.wikipedia.org';
   // Optional debug: $wgAIBatchEditorPromptPreview = true;
   ```

   Store the API key in the environment, not in git:
   - **Docker:** set `XAI_API_KEY` in `.env` (loaded via `env_file` in `compose.yml`).
   - **cPanel / bare metal:** place `XAI_API_KEY=…` in `$IP/.env` at the wiki root. AIBatchEditor loads this file automatically on registration (`includes/EnvFile.php`).

   The special page shows a warning until both URL and key are set, and a privacy notice when the LLM is configured.

3. Visit `Special:Version`, then `Special:AIBatchEditor`.

### Shared hosting / cPanel

If your wiki runs on **cPanel or PHP-FPM without memcached**, add a persistent object cache to `LocalSettings.php` (in addition to the extension block above):

```php
$wgMainCacheType = CACHE_DB;  // uses the mw_objectcache table
```

Without this, **Redactar / Draft** fails immediately with `batch-not-found` because batch progress is stored in MediaWiki object cache and the default per-request memory cache is not shared between HTTP requests. See [Troubleshooting](#troubleshooting).

## Troubleshooting

### Redactar fails with `batch-not-found`

**Symptom:** Clicking **Redactar** (Draft) fails right away. The UI may show `batch-not-found` or an untranslated `⧼batch-not-found⧽`.

**Cause:** Server-side batch runs store progress in MediaWiki **object cache** (`BatchRunService`). The UI calls `aibatcheditorbatchstart`, then polls `aibatcheditorbatchstatus` every ~800ms. On cPanel / PHP-FPM without memcached, the default cache is **per-request memory** — the start and status requests often hit different PHP workers, so the batch ID cannot be found.

**Fix:** Enable a **persistent** object cache in `LocalSettings.php`:

```php
# Preferred on cPanel when APCu is enabled in Select PHP Version:
$wgMainCacheType = CACHE_ACCEL;
$wgMainStash = CACHE_ACCEL;

# Fallback when APCu/memcached are unavailable:
$wgMainCacheType = CACHE_DB;   // uses mw_objectcache
```

Alternatives: `CACHE_MEMCACHED` with `$wgMemCachedServers`, or Redis if your host provides it.

**WikiHistoria production:** APCu + OPcache enabled in cPanel (PHP 8.4); `CACHE_ACCEL` for main and stash caches.

**Verify:** The `objectcache` table exists (e.g. `mw_objectcache`). After enabling `CACHE_DB`, Redactar should show a progress bar instead of failing instantly.

### Batch fails with `http` or `⧼http⧽`

**Symptom:** A batch run (especially large ones) stops with an error icon and `http`, `⧼http⧽`, or a message like “The request to the wiki server failed.”

**Cause:** Each **advance** request (`aibatcheditorbatchadvance`) processes up to `$wgAIBatchEditorConcurrency` pages synchronously, and each page can take up to `$wgAIBatchEditorRequestTimeout` seconds (default 120) for the LLM call. If PHP-FPM, nginx, or the browser times out before that request finishes, `mw.Api` reports a transport `http` error. (Status polls are read-only and should stay fast.)

**Fix:**

```php
# Process one page per poll (recommended on shared hosting):
$wgAIBatchEditorConcurrency = 1;

# Optional: lower LLM timeout if pages are small
$wgAIBatchEditorRequestTimeout = 90;
```

Also raise PHP `max_execution_time` and your reverse-proxy read timeout above the LLM timeout. On cPanel, check **MultiPHP INI Editor** and any nginx/Apache proxy limits.

**Verify:** A single-page Redactar should complete without error; a 50-page batch should advance steadily (one page per poll when concurrency is 1).

## Workflow

1. **Validate pages** — by title list, category, or template transclusion (with optional title prefix filter).
2. **Choose operation** — wikilinks, spellcheck, formatting, style, templates, or custom.
3. **AI instructions** — optional batch-wide directions sent to the model.
4. **Preview prompt** (optional) — inspect the system/user messages before drafting.
5. **Draft** — server-side batch run: `aibatcheditorbatchstart`, then `aibatcheditorbatchadvance` (LLM work) with `aibatcheditorbatchstatus` polling for progress. **Cancel batch** stops remaining pages while keeping finished results.
6. **Review diffs** — lazy-loaded per page; per-page overrides and re-draft supported.
7. **Approve & save** — confirm dialogs for bulk approve, unreviewed diffs, and risky proposals; saves require server-issued draft tokens.

After a successful save, each row links to the new revision and page history.

## Safety and review gates

| Feature | Behavior |
| --- | --- |
| Privacy notice | Shown on the special page when the LLM is configured |
| Rate-limit notice | Draft button disabled when the batch exceeds remaining hourly quota |
| Lazy diffs | Diffs load on demand (not automatically) |
| Proposal warnings | Server flags major deletions, near-empty output, large growth, removed headings |
| Diff-reviewed gate | Browser confirms approve/save if diffs were not previewed |
| Draft tokens | Each changed page gets an HMAC token; save rejects stale or tampered proposals |
| Post-save links | Revision and history links after successful save |
| Audit logging | Process/save logs include operation, profile, per-edit hashes and revision IDs |

## AI capabilities and limits

The extension sends **one page's wikitext at a time** to the LLM, plus your
instructions and (for the `templates` operation) reference template definitions
fetched from allowed remote wikis.

**The AI can:**

- Edit wikitext (links, spelling, structure, tone, templates)
- Follow batch-wide or per-page instructions (custom operation requires instructions)
- Clone or adapt templates when you name references (e.g. `Plantilla:Ficha` from es.wikipedia.org)

**The AI cannot:**

- Search the web or call external APIs (weather, news, geocoding, etc.)
- Read other pages on your local wiki as context
- Share context between pages in a batch (each LLM call is isolated)
- Edit non-wikitext content or process redirects / missing pages

If you need factual data from outside the page (e.g. historical weather), **provide
it in the instructions** or add it to the wikitext yourself. Otherwise the model
may invent generic text.

Prompts use a **surgical-edit** system message: minimal changes, no invented
facts, return unchanged wikitext when the task is already satisfied. Lower
temperature (default `0.1`) improves literal instruction following.

## Permissions

- **`aibatchedit`** — required for the special page and all APIs. Granted to
  `sysop` by default.

## Configuration

| Setting | Default | Purpose |
| --- | --- | --- |
| `$wgAIBatchEditorApiUrl` | `''` | LLM endpoint URL (server-side only) |
| `$wgAIBatchEditorApiKey` | `''` | LLM API key (server-side only) |
| `$wgAIBatchEditorModel` | `grok-2-latest` | Model identifier |
| `$wgAIBatchEditorMaxBatch` | `50` | Max pages per batch |
| `$wgAIBatchEditorMaxPageSize` | `2097152` | Max wikitext bytes per page for AI (`0` = no limit) |
| `$wgAIBatchEditorMaxInstructionsLength` | `8192` | Max bytes for AI instruction text |
| `$wgAIBatchEditorRequestTimeout` | `120` | LLM HTTP timeout in seconds |
| `$wgAIBatchEditorTemperature` | `0.1` | LLM sampling temperature (0.0–1.0); lower = stricter instruction following |
| `$wgAIBatchEditorPromptPreview` | `false` | Debug flag: expose built prompts in UI/API (enable only for troubleshooting) |
| `$wgAIBatchEditorRateLimitPerHour` | `100` | AI requests per user per hour |
| `$wgAIBatchEditorConcurrency` | `1` | Pages processed per `aibatcheditorbatchadvance` request |
| `$wgAIBatchEditorStubMode` | `false` | Deterministic AI stub for automated browser tests only |
| `$wgAIBatchEditorEnabledOperations` | all six | Toggle operations |
| `$wgAIBatchEditorTemplateSourceWiki` | `https://es.wikipedia.org` | Default remote wiki for template references (HTTPS only) |
| `$wgAIBatchEditorTemplateSourceAllowHosts` | es/en.wikipedia.org, mediawiki.org | Allowed hosts for `templatesource` overrides |
| `$wgAIBatchEditorOperationProfiles` | see extension.json | Per-operation profile intensity (UI help text + LLM prompt) |
| `$wgAIBatchEditorSystemPromptAppend` | `[]` | Extra bullet points appended to every LLM system prompt for wiki-wide policy (server-side only) |

### LLM system prompt

Each AI request sends a structured system message (prompt version **3**) built by
`PromptFactory`:

| Section | Purpose |
| --- | --- |
| **ROLE** | MediaWiki wikitext editor + wiki content language |
| **OUTPUT CONTRACT** | Full wikitext only; minimal edit; fidelity; no invention; unchanged if satisfied |
| **PRIORITY** | Instruction hierarchy |
| **TASK — Operation** | One-line goal (what kind of edit) |
| **TASK — Profile** | Intensity only — how much to change within scope |
| **SCOPE** | What may change per operation (boundaries, not intensity) |
| **WIKI-SPECIFIC RULES** | From `$wgAIBatchEditorSystemPromptAppend` when set |
| **Template references** | Fetched wikitext for the `templates` operation |

`$wgAIBatchEditorOperationProfiles` defines profile help text in the UI and
intensity strings in the prompt. The **Custom** operation hides the profile
dropdown and always uses **balanced** intensity; scope is controlled via AI
instructions.

The user message contains only `=== INPUT ===` and the page wikitext.

**North star:** make the smallest change that completes the task; copy everything
else exactly; invent nothing.

Precedence (highest first): **editor instructions** → **operation + profile** →
**wiki-specific rules** (`SystemPromptAppend`) → built-in contract and scope in
`PromptFactory`.

Process logs include `promptVersion` for audit and regression analysis.

#### Wiki-wide append

Set `$wgAIBatchEditorSystemPromptAppend` in `LocalSettings.php` to add wiki-wide
policy bullets. Built-in output contract and scope rules always remain; this
setting cannot remove or replace them.

```php
$wgAIBatchEditorSystemPromptAppend = [
    'This wiki documents family history; never invent names or dates.',
    'Prefer [[Plantilla:Persona]] for biography pages.',
];
```

Inspect the composed prompt with `$wgAIBatchEditorPromptPreview = true` and **Preview prompt** on the special page.

## APIs

| Module | Mode | Purpose |
| --- | --- | --- |
| `aibatcheditorlist` | read | Validate and list pages (titles, category, or template); returns rate-limit status |
| `aibatcheditorpreview` | read | Build LLM prompts for one page without calling the AI |
| `aibatcheditorbatchstart` | read | Start a server-side batch; returns `batchId` |
| `aibatcheditorbatchadvance` | read | Process the next chunk of a batch (LLM work; may be long-running) |
| `aibatcheditorbatchstatus` | read | Read batch progress from object cache (fast; no LLM calls) |
| `aibatcheditorbatchcancel` | read | Cancel a running batch; clears pending pages |
| `aibatcheditordiff` | read | Render preview diff |
| `aibatcheditorrefreshdrafttokens` | write | Refresh `draftToken` values before save (title, revid, proposed) |
| `aibatcheditorsave` | write | Save approved edits (requires `draftToken` per edit) |

The browser UI uses **batch start**, then **`batchadvance`** (sequential LLM work) with **`batchstatus`** polling for progress. Each changed page in batch responses includes `draftToken` and optional `warnings`.

Before saving, the UI calls **`aibatcheditorrefreshdrafttokens`** automatically, then **`aibatcheditorsave`**. Refresh re-issues tokens for the current user and base revision; if a page changed during the LLM run, refresh returns `conflict` for that page.

Save `edits` JSON objects must include `title`, `revid`, `proposed`, and `draftToken`. Refresh `edits` omit `draftToken`.

Save may return distinct draft-token errors (`draft-token-content-mismatch`, `draft-token-bad-signature`, `draft-token-expired`, etc.) in addition to the generic `invalid-draft-token`.

When `$wgAIBatchEditorPromptPreview` is enabled, batch responses include `promptSystem` and `promptUser` per page.

## Logging

Batch actions are logged to the `aibatcheditor` Monolog channel (list, process,
save). Process logs include `promptVersion` (currently `3`). Save logs include
operation, profile, and per-edit audit fields (title, base revid, proposed
SHA-256, status, new revid). Configure your wiki's logging to capture this
channel for audit trails.

## Tests

Install MediaWiki **dev dependencies** once (`composer install --dev` from the wiki root).

### PHPUnit

From the MediaWiki root with the extension mounted:

```bash
composer install --dev
chmod +x extensions/AIBatchEditor/tests/run-phpunit.sh
./extensions/AIBatchEditor/tests/run-phpunit.sh
```

The runner uses MediaWiki's `tests/phpunit/phpunit.php` bootstrap (required for extension tests).

**100 PHPUnit tests** (53 unit + 47 integration).

### E2E (Playwright)

Requires Node.js, a running wiki, and a sysop account:

```bash
export MW_E2E_USER=Admin
export MW_E2E_PASSWORD='your-sysop-password'
export AIBATCHEDITOR_E2E_STUB=1   # enables $wgAIBatchEditorStubMode on the wiki

./extensions/AIBatchEditor/tests/run-e2e.sh
```

Set `$wgAIBatchEditorStubMode = getenv( 'AIBATCHEDITOR_E2E_STUB' ) === '1';` in
`LocalSettings.php` when running browser tests.

See `tests/QA-CHECKLIST.md` for manual QA steps before release.

## Release notes

### 1.0.0 (2026-06-26)

First stable release. Highlights since the beta line:

- Template transclusion page selection (third input mode alongside titles and category)
- Remote template reference fetch with improved error handling and User-Agent
- Cancel batch button and `aibatcheditorbatchcancel` API
- MediaWiki 1.45 compatibility (`wfTransactionalTimeLimit` for batch polling)
- 100 PHPUnit tests (53 unit + 47 integration)

## License

This extension is free software licensed under the
[GNU General Public License](https://es.wikipedia.org/wiki/GNU_General_Public_License)
(GPL-2.0-or-later).

You may redistribute and modify it under the terms of the GNU General Public
License version 2 or, at your option, any later version published by the Free
Software Foundation.

- Full license text: [COPYING](COPYING)
- Summary: [LICENSE](LICENSE)
- Official GPL-2.0: [gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)