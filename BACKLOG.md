# BACKLOG

Open findings from `CODE_REVIEW_2026_05_16.md`. Pre-tag smoke gate
fails until this file has no open `- [ ]` items.

This plugin lives on **GitHub** (Webikon/wc-bacs-paybysquare), not the
platobnebrany GitLab subgroup — use `gh issue` for cross-team tracking
if needed.

## Critical
- [x] `src/class-plugin.php:612–618` — base64-decoded API response written to disk without PNG magic-byte validation. Fix: assert `substr($raw, 0, 4) === "\x89PNG"` before `file_put_contents()`.
