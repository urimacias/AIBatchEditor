# AIBatchEditor — Manual QA Checklist

Run through this list on `http://localhost:8080` (or your staging wiki) after code changes.
Log in as a user with the `aibatchedit` right (default: `sysop`).

## Setup

- [ ] `Special:Version` shows **AIBatchEditor 1.0.0** (or newer).
- [ ] `$wgAIBatchEditorApiUrl`, `$wgAIBatchEditorApiKey`, and `$wgAIBatchEditorModel` are set (special page shows a warning until URL and key are configured).
- [ ] Privacy notice appears when the LLM is configured.
- [ ] Hard-refresh `Special:AIBatchEditor` (`Cmd+Shift+R`) after JS changes.

## Page selection

- [ ] Paste a title list → **Validate** returns exists/editable metadata.
- [ ] Pick a category (with optional prefix) → pages load; truncation notice appears when over `$wgAIBatchEditorMaxBatch`.
- [ ] Pick **Template** mode → enter a template name (e.g. `Ficha` or `{{Plantilla:Ficha}}`) → only transcluding pages load; optional prefix filter works.
- [ ] Missing template name → clear `template-page-not-found` error.
- [ ] Invalid or missing titles show clear errors without breaking the UI.
- [ ] Pages over `$wgAIBatchEditorMaxPageSize` are skipped at validation with a size notice.
- [ ] Rate-limit quota (`used` / `remaining`) updates after validate.

## Operations & profiles

- [ ] Each enabled operation appears in the **Operation** dropdown (including **Templates**).
- [ ] **Profile** help text comes from `$wgAIBatchEditorOperationProfiles` (Spanish UI if overridden in LocalSettings).
- [ ] **Custom** operation hides profile dropdown and shows balanced-intensity notice.
- [ ] **AI instructions** are sent to the model (verify with a distinctive instruction).
- [ ] **Custom** operation requires instructions before **Draft** runs.
- [ ] **Templates** operation requires template names before **Draft** runs.
- [ ] Template source wiki field accepts allowed hosts only (default: es.wikipedia.org).
- [ ] **Edit summary** is required before save (not sent to the LLM).
- [ ] Draft notice shows page count vs. remaining hourly quota; **Draft** disabled when insufficient.

## Prompt preview (optional: `$wgAIBatchEditorPromptPreview = true`)

- [ ] **Preview prompt** button builds system/user messages for the first valid page (only when preview is enabled).
- [ ] Prompt preview shows mandatory editor instructions when provided.
- [ ] After **Draft**, changed pages show prompt blocks in results (if preview enabled).
- [ ] With the default (`false`), preview UI and API prompt fields are hidden.

## Draft (server-side batch)

- [ ] **Draft** shows progress bar; pages move from pending → processing → result.
- [ ] Unchanged pages show status **omitted** (no diff).
- [ ] Changed pages show status **changed** with lazy **Preview diff** button.
- [ ] Per-page instruction override works.
- [ ] **Re-draft** re-runs a single page.
- [ ] **Retry failed pages** retries all failed AI pages in one click.
- [ ] **Cancel batch** stops polling, marks pending pages cancelled, keeps finished results.
- [ ] Rate limit error appears after exceeding `$wgAIBatchEditorRateLimitPerHour`.
- [ ] Risky proposals show warning messages (e.g. major deletion test).

## Review & save

- [ ] **Preview diff** must be clicked to load diff HTML (not auto-loaded).
- [ ] Approve without viewing diff → confirmation dialog.
- [ ] **Approve all changes** → confirmation dialog.
- [ ] Approve page with warnings → extra confirmation.
- [ ] Save without viewing approved diffs → confirmation dialog.
- [ ] Save without summary → validation error.
- [ ] Save approved edits → pages updated in wiki history with your summary.
- [ ] Post-save panel shows revision and history links.
- [ ] Saved edits carry the `aibatcheditor` change tag (Recent Changes filter).
- [ ] Edit conflict (page changed meanwhile) shows clear error; re-draft recovers.
- [ ] Save after a long LLM batch succeeds without re-running the batch (stale tokens recovered on save).
- [ ] Multi-page batch: Network tab shows one `aibatcheditorbatchadvance` per page (not only `batchstatus` polls).
- [ ] `batchstatus` responses are small (~1 KB); full wikitext only in `batchadvance` responses.
- [ ] Page edited externally during LLM wait shows conflict on save (not a generic draft-token error).
- [ ] `~/mwHistoria/cache/aibatcheditor.log` records `process` and `save` events during a batch run.

## Permissions & API

- [ ] Anonymous users cannot access APIs (`permissiondenied`).
- [ ] Users without `aibatchedit` cannot open the special page.
- [ ] `aibatcheditorsave` requires a valid CSRF token (write mode).
- [ ] `aibatcheditorsave` rejects edits without a `draftToken` field in the JSON payload.
- [ ] `aibatcheditorsave` recovers from invalid token when revid still matches (check log for `recovered: true`).
- [ ] `aibatcheditorrefreshdrafttokens` issues fresh tokens when revid still matches.
- [ ] `aibatcheditorrefreshdrafttokens` returns `conflict` when the live revision changed.
- [ ] `aibatcheditorbatchstatus` omits `original` / `proposed` wikitext (lightweight progress).
- [ ] `aibatcheditorpreview` returns prompts without consuming rate limit or calling the LLM.
- [ ] `aibatcheditorbatchstatus` rejects batches owned by another user.
- [ ] `aibatcheditorbatchcancel` rejects batches owned by another user.

## Automated tests

From the MediaWiki root (install dev dependencies once, then run the suite):

```bash
composer install --dev
./extensions/AIBatchEditor/tests/run-phpunit.sh
```

Expected: **110+ tests** (unit + integration; includes draft-token refresh and diagnostics).

E2E (requires Node.js and sysop credentials):

```bash
export MW_E2E_USER=Admin
export MW_E2E_PASSWORD='your-password'
export AIBATCHEDITOR_E2E_STUB=1
./extensions/AIBatchEditor/tests/run-e2e.sh
```

Expected: **1 Playwright test** (validate → draft → diff → approve → save).