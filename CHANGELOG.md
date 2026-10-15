# Changelog

All notable changes to **CF7 Spam Checker Pro** are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows [SemVer](https://semver.org/).

## [4.1.0] — 2026-10-15

### Added
- **Multi-provider LLM gateway** (`src/Services/Llm/`): `ProviderCatalog` with 11 providers — DeepSeek, OpenAI (ChatGPT), Qwen (Alibaba DashScope), Anthropic (Claude), Google Gemini, Groq, Mistral AI, OpenRouter, xAI (Grok), plus `custom_openai` / `custom_anthropic` universal entries for any operator.
- Two universal protocol clients: `OpenAiChatClient` (`POST {base}/chat/completions`, Bearer auth; handles reasoning-model parameter quirks and OpenRouter attribution headers) and `AnthropicMessagesClient` (`POST {base}/v1/messages`, `x-api-key` + `anthropic-version` headers, system prompt as a top-level parameter, usage normalisation).
- Per-provider credentials: `llm.api_keys.*`, `llm.models.*` (free text with datalist suggestions), `llm.base_urls.*` overrides; all keys masked in UI and REST responses.
- Admin UI: provider selector with per-provider credential blocks, model datalists, base-URL overrides and provider notes.
- REST route `POST /cf7sec/v1/test/llm`; `/test/deepseek` kept as a backwards-compatible alias.
- Tests: protocol contract tests (request URL/headers/body shape for both protocols, intercepted HTTP) and a v4.0→v4.1 schema-migration test.

### Changed
- Settings schema v2: the single-provider `deepseek` section is replaced by the `llm` gateway section; stored v4.0 options migrate automatically (`Activator::migrateSettingsSchema`, idempotent, stamped via `internal.settings_schema`).
- `DeepSeek` service class removed in favour of `LlmGateway`; pipeline, LLM check, admin panel and notifications updated accordingly.

## [4.0.0] — 2026-10-15

Complete professional refactor of the legacy single-file plugin (`cf7_check.php`, 5 100+ lines) into a modular, production-grade WordPress plugin.

### Added

**Integrations**
- DeepSeek LLM spam analysis: `SPAM`/`CLEAN` one-word verdicts, model selection (`deepseek-chat`, `deepseek-reasoner`), configurable base URL/timeout, strict verdict parser, fail-open/fail-closed policies, optional auto-ban on SPAM verdict, optional geo enrichment of the prompt, built-in connection tester, viewable system prompt.
- AbuseIPDB reputation checks: abuse-confidence threshold, total-reports threshold, **per-category "Email Spam" report counting** (via verbose mode), country block/allow filter, DB-backed response cache with TTL, daily quota tracking from `x-ratelimit-remaining` headers, auto-ban of flagged IPs, optional community reporting of confirmed spammers (category 10 with evidence comment), on-demand IP lookup tool.

**Platform**
- Spam registry on custom tables: `{prefix}cf7sec_events` with per-check evidence, sanitized payloads, filters (verdict/type/date/text), pagination, evidence modal, CSV export, retention-driven purge.
- IP rules table: bans + whitelist + strike placeholders in one table, unique per IP, strike escalation to permanent bans, refusal to ban private/reserved ranges, bulk delete, CSV export.
- Geo cache table (`cf7sec_geo`) and daily aggregate counters (`cf7sec_daily`) powering the dashboard chart.
- REST API namespace `cf7sec/v1` (settings, stats, health, events, ip-rules, tests, import/export, logs, maintenance) — nonce + `manage_options` protected.
- Notifications: throttled admin e-mail alerts (bans, spam waves, integration errors) with test button.
- Tools page: settings export/import (JSON), manual maintenance, geo-cache clear, counters reset, requirements/health report, danger zone.
- Debug log viewer in the admin (rotating daily files under `wp-content/uploads/cf7sec/`, deny-all protected).
- Uninstall support: `uninstall.php` removes tables/options/transients/logs unless "Keep data on uninstall" is set.

**Admin UI**
- Rebuilt on a local Tailwind CSS design system with shadcn/ui-style tokens: cards, switches, badges, tabs, tables, toasts, modals, KPI grid, SVG charts.
- Light / dark / system theme with pre-paint bootstrap (no flash), `localStorage` persistence and OS auto-detection on first visit.
- Mobile-first responsive layout (full-bleed takeover of the wp-admin content area, collapsible/scrollable navigation).
- Zero external requests: no CDNs, no Google Fonts, inline SVG icons only.
- WordPress dashboard widget with daily summary; CF7 editor panel showing per-form protection status.

**i18n**
- Full translations: Russian (`ru_RU`), Simplified Chinese (`zh_CN`), German (`de_DE`) — `.pot` + `.po` + `.mo` shipped; language-name context strings included.
- All visitor-facing messages customizable per check, with placeholders.

**Engineering**
- PSR-4 autoloaded `CF7Sec\` namespace, 30+ classes, `declare(strict_types=1)` everywhere, PHP 8.3 features (readonly properties, enums, typed class constants, first-class callable syntax where appropriate), verified on PHP 8.3 and compatible through 8.5.
- Check pipeline architecture: `CheckInterface` + `CheckResult` value objects + ordered `SpamPipeline` with fail-open exception handling.
- Test suites: `tests/smoke.php` (73 assertions, no WP required) and `tests/wp-integration.php` (59 assertions inside real WordPress 7.1 + Contact Form 7 6.2, including live AbuseIPDB verification).
- Build tooling: Tailwind CLI config, i18n extraction/compilation scripts (pure Python), release packaging script.

### Changed
- Client IP resolution: configurable header modes (auto/REMOTE_ADDR-only/Cloudflare/XFF); `auto` trusts forwarded headers only from local proxies or Cloudflare ranges (spoofing-safe).
- Timing analysis: new monotonic client-side dwell timer (`cf7sec_age`) immune to clock skew and page caching; server render timestamp (`cf7sec_ts`) kept as fallback with configurable skew tolerance.
- SQLi/XSS signatures: context-aware patterns (SQL phrases, metacharacter requirements, system-catalog probes, encoded payload decoding) — significantly fewer false positives on natural prose.
- Bot UA detection: strong-evidence vs generic markers, browser-name false-positive suppression, admin-extensible keyword list.
- Rate limiting: sliding window (per-IP timestamp array in a transient) instead of a fixed counter.
- Ban model: strikes live in the DB (decay with retention), N-th ban of the same IP escalates to permanent.
- Storage: JSON files in `wp-content/cf7sec_logs/` replaced by indexed DB tables; legacy data migrated automatically.

### Fixed
- `_cf7a_timestamp` legacy field never reached validation under CF7 ≥ 5.9 (CF7 strips `_`-prefixed keys from `posted_data`) — the old time-check was silently dead; replaced with working field names.
- Old plugin counted "protection" stats into autoloaded options on every submission — counters now live in a dedicated table with atomic upserts.
- `file_get_contents`-based ban list had race conditions under concurrency — replaced with `$wpdb` operations and unique constraints.

### Migration from 3.x
- Settings (`cf7sec_options`) and `ban_list.json` are imported on activation; the JSON file is renamed `*.migrated`.
- Hidden field names changed (`cf7sec_ts`, `cf7sec_age`, `cf7sec_hp_*`) — no user action required.

## [3.5.3] and earlier
- Legacy single-file implementation (`cf7_check.php`) — see repository history.
