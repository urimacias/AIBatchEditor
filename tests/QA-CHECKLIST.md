# AIBatchEditor — Manual QA Checklist

Run through this list on `http://localhost:8080` (or your staging wiki) after code changes.
Log in as a user with the `aibatchedit` right (default: `sysop`).

## Setup

- [ ] `Special:Version` shows **AIBatchEditor 0.8.0** (or newer).
- [ ] `$wgAIBatchEditorApiUrl`, `$wgAIBatchEditorApiKey`, and `$wgAIBatchEditorModel` are set.
- [ ] Hard-refresh `Special:AIBatchEditor` (`Cmd+Shift+R`) after JS changes.

## Page selection

- [ ] Paste a title list → **Validar** returns exists/editable metadata.
- [ ] Pick a category (with optional prefix) → pages load; truncation notice appears when over `$wgAIBatchEditorMaxBatch`.
- [ ] Invalid or missing titles show clear errors without breaking the UI.
- [ ] Pages over `$wgAIBatchEditorMaxPageSize` are skipped at validation with a size notice.

## Operations & profiles

- [ ] Each enabled operation appears in the **Operación** dropdown.
- [ ] **Perfil** help text shows the configured profile instruction (from `$wgAIBatchEditorOperationProfiles`).
- [ ] **Instrucciones para la IA** are sent to the model (verify with a distinctive instruction).
- [ ] **Custom** operation requires instructions before **Redactar** runs.
- [ ] **Resumen de edición** is required before save (not sent to the LLM).

## Redactar (AI draft)

- [ ] **Redactar** processes pages with visible progress.
- [ ] Unchanged pages show status **omitted** (no diff).
- [ ] Changed pages show a diff preview.
- [ ] Per-page instruction override works.
- [ ] **Volver a redactar** re-runs a single page.
- [ ] **Reintentar páginas con error** retries all failed AI pages in one click.
- [ ] Rate limit error appears after exceeding `$wgAIBatchEditorRateLimitPerHour`.

## Review & save

- [ ] Approve individual pages and **Aprobar todos los cambios**.
- [ ] Save without summary → validation error.
- [ ] Save approved edits → pages updated in wiki history with your summary.
- [ ] Saved edits carry the `aibatcheditor` change tag (Recent Changes filter).

## Permissions & API

- [ ] Anonymous users cannot access APIs (`permissiondenied`).
- [ ] Users without `aibatchedit` cannot open the special page.
- [ ] `aibatcheditorsave` requires a valid CSRF token (write mode).

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