# AIBatchEditor

An AI-assisted **batch editor** for MediaWiki 1.43+. Select many pages at once,
run AI operations on them (wikilinks, spellcheck, formatting, writing style, or
custom instructions), **preview each change as a diff**, and approve before
saving. Every save goes through MediaWiki's normal edit pipeline, so edits are
attributed, logged, taggable, and revertible.

**Current version:** 0.8.0

**Documentation site:** [GitHub Pages](https://YOUR_USERNAME.github.io/AIBatchEditor/) (enable from the `docs/` folder after publishing the repo).

## Requirements

- MediaWiki >= 1.43.0
- A Grok-compatible chat completions API (xAI Grok by default)

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
   ```

   Store the API key in the environment (e.g. `.env` for Docker), not in git.

3. Visit `Special:Version`, then `Special:AIBatchEditor`.

## Workflow

1. **Validate pages** — by title list or category (with optional prefix filter).
2. **Choose operation** — wikilinks, spellcheck, formatting, style, or custom.
3. **AI instructions** — optional batch-wide directions sent to the model.
4. **Redactar** — AI drafts run in parallel (configurable concurrency).
5. **Review diffs** — per-page overrides and re-draft supported.
6. **Approve & save** — uses your edit summary; tagged `aibatcheditor`.

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
| `$wgAIBatchEditorMaxPageSize` | `51200` | Max wikitext bytes per page for AI (`0` = no limit) |
| `$wgAIBatchEditorRequestTimeout` | `120` | LLM HTTP timeout in seconds |
| `$wgAIBatchEditorRateLimitPerHour` | `100` | AI requests per user per hour |
| `$wgAIBatchEditorConcurrency` | `3` | Parallel AI calls in the browser |
| `$wgAIBatchEditorEnabledOperations` | all + custom | Toggle operations |
| `$wgAIBatchEditorOperationProfiles` | see extension.json | Per-operation/profile LLM hints (shown under **Perfil** in the UI) |

## APIs

| Module | Mode | Purpose |
| --- | --- | --- |
| `aibatcheditorlist` | read | Validate and list pages |
| `aibatcheditorprocess` | read | Run AI on page(s) |
| `aibatcheditordiff` | read | Render preview diff |
| `aibatcheditorsave` | write | Save approved edits |

## Logging

Batch actions are logged to the `aibatcheditor` Monolog channel (list, process,
save). Configure your wiki's logging to capture this channel for audit trails.

## Tests

Install MediaWiki **dev dependencies** once (`composer install --dev` from the wiki root).

From the MediaWiki root with the extension mounted:

```bash
chmod +x extensions/AIBatchEditor/tests/run-phpunit.sh
./extensions/AIBatchEditor/tests/run-phpunit.sh
```

Or run suites individually:

```bash
composer phpunit -- extensions/AIBatchEditor/tests/phpunit/unit
composer phpunit -- extensions/AIBatchEditor/tests/phpunit/integration
```

See `tests/QA-CHECKLIST.md` for manual QA steps before release.

## GitHub Pages

This repo includes a static site in `docs/`. After pushing to GitHub:

1. Open **Settings → Pages**
2. Source: **Deploy from a branch**
3. Branch: `main` / folder: **`/docs`**
4. Save — the site will be at `https://YOUR_USERNAME.github.io/AIBatchEditor/`

Replace `YOUR_USERNAME` in `docs/index.html` and this README with your GitHub username or org.

## License

GPL-2.0-or-later