# AIBatchEditor — Manual QA Checklist

Run through this list on `http://localhost:8080` (or your staging wiki) after code changes.
Log in as a user with the `aibatchedit` right (default: `sysop`).

## Setup

- [ ] `Special:Version` shows **AIBatchEditor 0.9.1** (or newer).
- [ ] `$wgAIBatchEditorApiUrl`, `$wgAIBatchEditorApiKey`, and `$wgAIBatchEditorModel` are set (special page shows a warning until URL and key are configured).
- [ ] Hard-refresh `Special:AIBatchEditor` (`Cmd+Shift+R`) after JS changes.

## Page selection

- [ ] Paste a title list → **Validate** returns exists/editable metadata.
- [ ] Pick a category (with optional prefix) → pages load; truncation notice appears when over `$wgAIBatchEditorMaxBatch`.
- [ ] Invalid or missing titles show clear errors without breaking the UI.
- [ ] Pages over `$wgAIBatchEditorMaxPageSize` are skipped at validation with a size notice.

## Operations & profiles

- [ ] Each enabled operation appears in the **Operation** dropdown (including **Templates**).
- [ ] **Profile** help text shows the configured profile instruction (from `$wgAIBatchEditorOperationProfiles`).
- [ ] **AI instructions** are sent to the model (verify with a distinctive instruction).
- [ ] **Custom** operation requires instructions before **Draft** runs.
- [ ] **Templates** operation requires template names before **Draft** runs.
- [ ] Template source wiki field accepts allowed hosts only (default: es.wikipedia.org).
- [ ] **Edit summary** is required before save (not sent to the LLM).

## Prompt preview (optional: `$wgAIBatchEditorPromptPreview = true`)

- [ ] **Preview prompt** button builds system/user messages for the first valid page (only when preview is enabled).
- [ ] Prompt preview shows mandatory editor instructions when provided.
- [ ] After **Draft**, changed pages show prompt blocks in results (if preview enabled).
- [ ] With the default (`false`), preview UI and API prompt fields are hidden.

## Draft (AI)

- [ ] **Draft** processes pages with visible progress.
- [ ] Unchanged pages show status **omitted** (no diff).
- [ ] Changed pages show a diff preview.
- [ ] Per-page instruction override works.
- [ ] **Re-draft** re-runs a single page.
- [ ] **Retry failed pages** retries all failed AI pages in one click.
- [ ] Rate limit error appears after exceeding `$wgAIBatchEditorRateLimitPerHour`.

## Review & save

- [ ] Approve individual pages and **Approve all changes**.
- [ ] Save without summary → validation error.
- [ ] Save approved edits → pages updated in wiki history with your summary.
- [ ] Saved edits carry the `aibatcheditor` change tag (Recent Changes filter).

## Permissions & API

- [ ] Anonymous users cannot access APIs (`permissiondenied`).
- [ ] Users without `aibatchedit` cannot open the special page.
- [ ] `aibatcheditorsave` requires a valid CSRF token (write mode).
- [ ] `aibatcheditorpreview` returns prompts without consuming rate limit or calling the LLM.

## Automated tests

From the MediaWiki root (with dev dependencies installed):

```bash
./extensions/AIBatchEditor/tests/run-phpunit.sh
```

Or individually:

```bash
composer phpunit -- extensions/AIBatchEditor/tests/phpunit/unit
composer phpunit -- extensions/AIBatchEditor/tests/phpunit/integration
```

Expected: **59 tests** (22 unit + 37 integration).