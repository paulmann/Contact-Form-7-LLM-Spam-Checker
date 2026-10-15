# Changelog

All notable changes to **CF7 Spam Checker Pro** are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows [SemVer](https://semver.org/).

> **Naming.** Up to 4.1.1 the plugin was called *CF7 Spam Checker Pro*. From 4.2.0 the
> WordPress.org display name is **Spam Checker Pro for Contact Form 7** and the text
> domain/slug is `spam-checker-for-contact-form-7`; neither may begin with another
> product's term (directory guideline 17). Internal identifiers — the `cf7sec/v1` REST
> namespace, `cf7sec_*` options and tables, the `CF7Sec\` class prefix, the CSS classes
> and `CF7SEC_*` constants — are unchanged, so existing installations keep their data
> and API surface.

## [4.2.0] — 2026-10-15

Audit fixes plus WordPress.org submission readiness. No architectural change; the REST
contract, option names, table names and class prefixes are untouched.

### Fixed
- **S1:** the spam-registry CSV export passes every cell through the new pure
  `EventsRepository::csvCell()`, which prefixes values starting with `=`, `+`, `-`, `@`,
  tab, CR or LF with `'`. Submitter-controlled columns (`reasons`, `user_agent`,
  `referrer`, `payload`) could otherwise be evaluated as formulas by Excel/LibreOffice
  when the administrator opens the export (OWASP CSV-injection guidance). Covered by a
  new smoke group.
- **S2:** the per-IP rate-limit counter is no longer a `get_transient()` → PHP increment →
  `set_transient()` read-modify-write. `wp_cache_incr()` is used when a persistent object
  cache is present; otherwise one atomic `UPDATE … option_value = option_value + 1` on the
  option row that backs the counter. The window is owned by the storage (the transient
  expires by itself), so concurrent submissions no longer lose counts and the configured
  threshold fires when it should.
- **S3:** `BanManager::ban()` takes its escalation decision from
  `IpRuleRepository::bumpBannedCount()`, a single atomic `banned_count = banned_count + 1`
  statement that returns the value the storage produced (MySQL `LAST_INSERT_ID(expr)`,
  with a read-back fallback). Two close-together bans can no longer both decide on the
  same stale count and escalate one ban too late.
- **S4:** `RestController::authorize()` now verifies `X-WP-Nonce` (or a `_wpnonce`
  parameter) explicitly before `current_user_can()`. Defence in depth on sites with a
  non-standard authentication layer; `admin.js` already sent the header, so no client
  behaviour changed.
- **P1:** `Activator::maybeUpgrade()` — the path a regular plugin update actually runs —
  now also calls `migrateLegacyData()` and schedules `cf7sec_daily_cleanup` via the new
  `Activator::scheduleCleanup()`. Previously both only happened on activation, so updating
  from v3.x left the ban list un-imported and the retention job unscheduled.
  Reproduced and locked by the new `tests/upgrade-legacy.php`, including idempotency.
- **P2:** `cf7sec_events` gains `KEY verdict (verdict)` — the registry's verdict filter was
  a full table scan. `CF7SEC_DB_VERSION` is bumped 400 → 410 so `dbDelta` reaches
  existing installations through `maybeUpgrade()`.
- **P3:** the default validation language had three different literals (`'russian'` in
  `Settings::sanitize()`, `'russian'` in the settings view, `'russian'` in
  `LanguageValidator`, against `'english'` in `Settings::DEFAULTS`). All of them now read
  `Languages::DEFAULT_LANG`, which `Settings::DEFAULTS` also uses as its single source.
- **P4:** the remaining hard-coded English strings in `admin.js` (`items`, `bans`,
  `Delete all`, `IP is required`, `Remove`, the reset confirmation and its button label)
  are served through `CFG.i18n` with a `t(key, fallback)` lookup, so they reach the
  translation catalogue and are translated in ru_RU/de_DE/zh_CN instead of always
  rendering in English.
- **P5:** removed the leftover `unset($today)` (and its now-unused `$today` assignment) at
  the end of `DashboardWidget::render()`.
- **L1:** `readme.txt` no longer describes the AI layer as DeepSeek-only — installation,
  FAQ and the "slow submissions" answer now say "LLM gateway (11 providers)".

### Added
- **P6:** the keyword filter is split into pure, testable functions —
  `KeywordSpamCheck::matches(string $haystack, array $keywords): array` (case-insensitive
  substring matching, multi-word phrases, matched tokens returned in list order) and a
  normalising `KeywordSpamCheck::parseList()` (trim, drop empties, case-insensitive
  de-duplication, ignore tokens shorter than `MIN_KEYWORD_LENGTH` = 4). `run()` behaviour
  is unchanged: the first match still stops the check. New smoke group **"Keyword
  matcher"**, driven by tokens read at runtime from the owner-maintained pattern bank
  `docs/spam-patterns.md` plus synthetic benign text; the default keyword list in
  `Settings::DEFAULTS` is untouched.
- Settings hint for the keyword field now names the pattern bank and the owner's
  replenishment process.
- `assets-wporg/` — the WordPress.org catalogue set: `banner-772x250.png`,
  `banner-1544x500.png`, `icon-128x128.png`, `icon-256x256.png` and
  `screenshot-1.png` … `screenshot-9.png` (lowercase, numbered to match the readme).
  Reproducible via `python tools/make-wporg-assets.py`.
- `LICENSE.txt` is now the unmodified canonical GPL-2.0 text from gnu.org; the previous
  file carried a project-specific preamble, which made GitHub report the licence as
  "Other/NOASSERTION".

### Changed
- **W2:** display name is **Spam Checker Pro for Contact Form 7** (no leading foreign
  trademark, no portmanteau) in the plugin header, admin titles, footer, e-mails,
  README and readme.txt.
- **W3:** text domain and slug are `spam-checker-for-contact-form-7`;
  `languages/*.po|.mo|.pot` renamed to match and POT/PO/MO regenerated. The i18n tools and
  `tools/build-release.sh` now locate the bootstrap by content instead of hardcoding the
  plugin file name.
- **W4:** the privacy disclosure in `readme.txt`, `README.md` and `README.ru.md` now names
  every destination: the local database, the opt-in AbuseIPDB endpoint (submitter IP) and
  the opt-in LLM provider actually selected — all 11 catalogue entries with their host
  names plus custom endpoints — and states that both integrations are off until an API key
  is entered.
- **W5:** readme.txt short description shortened to 148 characters (directory limit is
  150), `== Screenshots ==` section added and matched to the numbered assets, changelog
  entries added for 4.1.1 and 4.2.0, and the readable sources linked in both Description
  and FAQ.
- **W7:** the release ZIP folder is the new slug and `LICENSE.txt` ships inside it.
- **W8:** version 4.2.0 agreed across the plugin header, `CF7SEC_VERSION`, `readme.txt`
  (Stable tag + changelog + upgrade notice), `package.json` and this changelog.
- `package.json` and CI now also run `tests/upgrade-legacy.php`.

## [4.1.1] — 2026-10-15

Patch release: defect audit fixes, no new features.

### Fixed
- **B1 (blocker):** the LLM check was dead code under the v2 settings schema — `LlmCheck::enabled()`, the fail-open/fail-closed policy and `SpamPipeline`'s `run_when_flagged` still read the removed `deepseek.*` paths. All four reads now use `llm.*`; guarded by a smoke regression test and by the new static guard `tests/settings-paths.php`, which fails the build on any literal settings path missing from `Settings::DEFAULTS`.
- **B2 (blocker):** `Activator::maybeUpgrade()` built its `Settings` snapshot *before* running the schema migration and then wrote that stale snapshot back, wiping the migrated LLM API key and `llm.enabled` on 4.0 → 4.1 upgrades. Migration now runs first on the raw option; version stamping is extracted into `Activator::stampVersions()`; a leftover `deepseek` section is stripped even when the schema stamp is already current. Reproduced and locked by `tests/upgrade-4.0-to-4.1.php`.
- **B3:** locked the committed-assets invariant with `tests/assets-built.php` (presence of `assets/dist/*` + every design-system class used in markup must exist in the compiled CSS); `.gitignore` scopes the release-dir rule to `/dist/`; `tools/build-release.sh` and `npm run build:js` create `assets/dist/` before copying.
- **M1:** `c-country-filter`, `c-save-btn` and `cf7sec-panel` had no CSS rules; the CF7 editor panel is rebuilt on the design system (no inline styles, theme-aware via a scoped token block) and `[hidden]` now reliably wins over `.c-btn` display utilities (the top-bar save button was visible when it should not be).
- **M2:** `AbuseIpCheck` docblock claimed per-category report counting was impossible — the implementation does count category-10 reports from verbose mode; documentation now matches the code and readme.txt FAQ.
- **M3:** removed the `Update URI` header — with no custom updater present it only disabled wordpress.org update checks. Updates come from the wp.org catalog.
- **M5:** `LanguageValidator` no longer reads `$_POST` directly; values come from `WPCF7_Submission::get_posted_data()` (array-safe), with a sanitised `$_POST` fallback only outside submissions.
- **M10:** honeypot field names are now HMAC-salted with `wp_salt('auth')` (12-char suffix) instead of plain `md5(form_id)`, so the trap name is unguessable across installations.
- **M11/L5:** debug log file names carry a per-installation suffix (nginx-safe, `.htaccess` covers Apache only; documented in readme.txt FAQ and the Diagnostics card); rotation keeps timestamped copies instead of overwriting `.1`.
- **M12:** documented the shared-proxy spoofing risk of `ip_header_mode=auto` in ARCHITECTURE and in the admin hint; smoke tests pin `Ip::firstFromList()` behaviour on spoofed lists.
- **L4:** CF7 older than 5.7 now detaches the protection hooks and shows an admin notice instead of silently half-working.
- **L6:** the AbuseIPDB "Test connection" no longer force-refreshes the cache (saves daily quota); UI hint updated.
- **L7:** `%minutes%` is computed only when the message template actually contains the placeholder.
- **L8:** mbstring polyfills accept mixed input like the native functions, preventing TypeErrors on non-string values when mbstring is absent.
- **L1/L2/L3/L9/L10:** cross-platform `python` runner for npm i18n scripts; documentation links fixed (`docs/preview/admin-ui-preview.html`); ARCHITECTURE references the `llm.*` schema; KPI tone classes are literal and compiled; `SECURITY.md` and `CONTRIBUTING.md` added.

### Added
- Guard suite wired into CI (`.github/workflows/ci.yml`, PHP 8.3/8.4/8.5 matrix): `php -l`, smoke, `settings-paths`, `assets-built`, `upgrade-4.0-to-4.1`, `docs-links`, advisory PHPCS (`phpcs.xml` + `composer.json`).
- `.gitattributes` enforcing LF for text/shell and binary marks for png/mo/zip (M4).
- i18n: `Project-Id-Version` is parsed from `CF7SEC_VERSION`; POT/PO/MO regenerated (396 strings, 100 % for ru/zh/de).

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
