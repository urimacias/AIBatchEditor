# AIBatchEditor

An AI-assisted **batch editor** for MediaWiki 1.43+. Select many pages at once,
run AI operations on them (wikilinks, spellcheck, formatting, writing style,
templates, or custom instructions), **preview each change as a diff**, and approve
before saving. Every save goes through MediaWiki's normal edit pipeline, so edits
are attributed, logged, taggable, and revertible.

**Current version:** 0.10.0

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
   $wgAIBatchEditorConcurrency = 3;
   $wgAIBatchEditorTemperature = 0.1;
   $wgAIBatchEditorTemplateSourceWiki = 'https://es.wikipedia.org';
   // Optional debug: $wgAIBatchEditorPromptPreview = true;
   ```

   Store the API key in the environment (e.g. `.env` for Docker), not in git. The special page shows a warning until both URL and key are set, and a privacy notice when the LLM is configured.

3. Visit `Special:Version`, then `Special:AIBatchEditor`.

## Workflow

1. **Validate pages** — by title list or category (with optional prefix filter).
2. **Choose operation** — wikilinks, spellcheck, formatting, style, templates, or custom.
3. **AI instructions** — optional batch-wide directions sent to the model.
4. **Preview prompt** (optional) — inspect the system/user messages before drafting.
5. **Draft** — server-side batch run with progress polling (`aibatcheditorbatchstart` / `aibatcheditorbatchstatus`).
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

Prompts enforce strict instruction compliance and discourage inventing facts.
Lower temperature (default `0.1`) improves literal instruction following.

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
| `$wgAIBatchEditorConcurrency` | `3` | Pages processed per server batch status poll |
| `$wgAIBatchEditorStubMode` | `false` | Deterministic AI stub for automated browser tests only |
| `$wgAIBatchEditorEnabledOperations` | all six | Toggle operations |
| `$wgAIBatchEditorTemplateSourceWiki` | `https://es.wikipedia.org` | Default remote wiki for template references (HTTPS only) |
| `$wgAIBatchEditorTemplateSourceAllowHosts` | es/en.wikipedia.org, mediawiki.org | Allowed hosts for `templatesource` overrides |
| `$wgAIBatchEditorOperationProfiles` | see extension.json | Per-operation/profile LLM hints (localized in the UI via i18n) |

## APIs

| Module | Mode | Purpose |
| --- | --- | --- |
| `aibatcheditorlist` | read | Validate and list pages; returns rate-limit status |
| `aibatcheditorpreview` | read | Build LLM prompts for one page without calling the AI |
| `aibatcheditorbatchstart` | read | Start a server-side batch; returns `batchId` |
| `aibatcheditorbatchstatus` | read | Poll batch progress; processes pages server-side |
| `aibatcheditorprocess` | read | Run AI on one or more pages in a single request |
| `aibatcheditordiff` | read | Render preview diff |
| `aibatcheditorsave` | write | Save approved edits (requires `draftToken` per edit) |

The browser UI uses **batch start + status polling**. Each changed page in process/batch responses includes `draftToken` and optional `warnings`.

Save `edits` JSON objects must include `title`, `revid`, `proposed`, and `draftToken`.

When `$wgAIBatchEditorPromptPreview` is enabled, process/batch responses include `promptSystem` and `promptUser` per page.

## Logging

Batch actions are logged to the `aibatcheditor` Monolog channel (list, process,
save). Save logs include operation, profile, and per-edit audit fields (title,
base revid, proposed SHA-256, status, new revid). Configure your wiki's logging
to capture this channel for audit trails.

## Tests

Install MediaWiki **dev dependencies** once (`composer install --dev` from the wiki root).

### PHPUnit

From the MediaWiki root with the extension mounted:

```bash
chmod +x extensions/AIBatchEditor/tests/run-phpunit.sh
./extensions/AIBatchEditor/tests/run-phpunit.sh
```

**81 PHPUnit tests** (35 unit + 46 integration).

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