=== Spam Checker Pro for Contact Form 7 ===
Contributors: deynekin
Donate link: https://deynekin.com/donate
Tags: contact form 7, spam, antispam, security, honeypot
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 4.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-layered spam protection for Contact Form 7: honeypot, timing, rate limiting, SQLi/XSS checks, AbuseIPDB and AI analysis. Zero friction.

== Description ==

Spam Checker Pro for Contact Form 7 protects your Contact Form 7 forms with a layered pipeline — no CAPTCHAs, no puzzles, zero friction for real visitors.

**Protection layers**

* Behavioural: client-measured fill time (cache-proof monotonic timer) + honeypot field
* Structural: SQL-injection, XSS, excessive links, keyword blacklist (admin-editable)
* Identity: bot user-agent detection with false-positive suppression, per-IP rate limiting with strike escalation and automatic bans
* Language: character-set validation for 22 languages on name fields, with friendly per-field errors
* Reputation: **AbuseIPDB** — abuse-confidence score, total report count and per-category "Email Spam" report counting, country block/allow lists, optional reporting of confirmed spammers back to the community
* AI: **LLM gateway (11 providers)** — the full submission digest (fields, IP, geo, browser, timing) is classified as SPAM or CLEAN by the provider of your choice: DeepSeek, OpenAI/ChatGPT, Qwen, Anthropic Claude, Google Gemini, Groq, Mistral, OpenRouter, xAI Grok, or any custom OpenAI-compatible / Anthropic-compatible endpoint (two universal protocols)

**Platform features**

* Spam registry: every verdict stored with full evidence, filters, CSV export, retention policy (GDPR)
* IP management: bans + whitelist, permanent-ban escalation, bulk operations, on-demand AbuseIPDB lookup
* Dashboard: KPI cards, 30-day traffic chart, system health, recent events
* Admin UI: modern responsive design, light/dark/system theme with persistence, fully local assets (no CDNs)
* Notifications: throttled e-mail alerts for bans, spam waves and integration errors
* Tools: settings export/import, debug log viewer, one-click maintenance, clean uninstall
* Translations: English, Russian, Simplified Chinese, German

**Source code**

This plugin is developed in the open. The human-readable sources — including `assets/src/input.css` and `assets/src/admin.js`, the readable sources for the compiled stylesheet and script shipped in the ZIP — are published at:
https://github.com/paulmann/Contact-Form-7-LLM-Spam-Checker

**Privacy**

Nothing leaves your site until you opt in. Both integrations ship **disabled**; without an API key no data is ever transmitted.

* **On your site (default).** Only blocked submissions are stored, in your own database, for a configurable number of days. Uninstalling removes everything unless you enable "Keep data on uninstall".
* **AbuseIPDB (opt-in).** Active only after you paste an API key and enable the integration. The submitter IP address is then sent to abuseipdb.com to read its abuse-confidence score and report counts. If you also enable community reporting, the IP address of a confirmed spammer is submitted back to AbuseIPDB.
* **LLM analysis (opt-in).** Active only after you paste an API key for one provider and enable it. The submission digest — all form field values, the submitter IP address, country/ISP when available, user agent, referrer, human dwell time and the results of the local checks — is then sent to the single provider you selected:
  * api.openai.com — OpenAI / ChatGPT
  * api.anthropic.com — Anthropic Claude
  * generativelanguage.googleapis.com — Google Gemini
  * api.deepseek.com — DeepSeek
  * api.groq.com — Groq
  * api.mistral.ai — Mistral AI
  * openrouter.ai — OpenRouter
  * api.x.ai — xAI Grok
  * dashscope.aliyuncs.com and dashscope-intl.aliyuncs.com — Qwen (Alibaba DashScope)
  * your own custom endpoint, if you pick one of the two universal protocols and supply a base URL (for example a self-hosted vLLM, Ollama or an internal proxy)
* **Debug logs** are off by default. When enabled they are written to `wp-content/uploads/cf7sec/`, protected by a deny-all `.htaccess`, an `index.php` guard and a per-installation random suffix in every file name.

== Installation ==

1. Upload the plugin ZIP via *Plugins → Add New → Upload Plugin*, or unzip `spam-checker-for-contact-form-7` into `/wp-content/plugins/`.
2. Activate the plugin. Database tables are created automatically; v3.x settings and ban lists are migrated on activation — and also on a regular plugin update.
3. Open **CF7 Security** in the admin menu and review *Settings → Protection*.
4. (Optional) Add your LLM gateway and AbuseIPDB keys under *Integrations* and press **Test connection**.

Requires Contact Form 7 5.7 or newer. PHP 8.3 – 8.5.

== Frequently Asked Questions ==

= Do I need to change my forms? =

No. Protection is injected automatically via hidden fields and the `wpcf7_spam` pipeline. No shortcodes or tags required.

= Will this slow down form submissions? =

Local checks run in microseconds. Remote checks (AbuseIPDB, the LLM gateway) execute only when the submission is still clean, with configurable timeouts and fail-open behaviour, and AbuseIPDB responses are cached locally.

= What happens if my LLM provider or AbuseIPDB is down? =

You choose per integration: fail-open (allow, log the error — default) or fail-closed (block). Errors are visible in the Spam Registry and can trigger e-mail alerts.

= Which LLM providers are supported? =

Eleven out of the box — DeepSeek, OpenAI (ChatGPT), Qwen (Alibaba DashScope), Anthropic Claude, Google Gemini, Groq, Mistral AI, OpenRouter and xAI Grok, plus two "custom" entries that speak the OpenAI-compatible chat-completions protocol or the Anthropic Messages protocol, so any operator (including a self-hosted model server) can be wired up with a base URL and a model id.

= How does the "Email Spam" category threshold work? =

The AbuseIPDB Check API returns lifetime totals plus the most recent individual reports with their categories (verbose mode). The plugin counts category-10 ("Email Spam") entries among the recent reports and compares against your threshold.

= Are the debug logs web-accessible? =

By default no: they live in `wp-content/uploads/cf7sec/` protected by a deny-all
`.htaccess` (Apache), an `index.php` guard, and — since 4.1.1 — a per-installation
random suffix in every file name, which also covers nginx. On nginx we additionally
recommend denying the location in your server config:
`location ^~ /wp-content/uploads/cf7sec/ { deny all; }`

= Where is data stored? =

Four custom tables (`{prefix}cf7sec_events`, `cf7sec_ip_rules`, `cf7sec_geo`, `cf7sec_daily`) plus one options entry. Debug logs (opt-in) live in `wp-content/uploads/cf7sec/` with deny-all protection. Uninstalling removes everything unless you enable "Keep data on uninstall".

= Which languages are supported for name validation? =

Russian, English, Spanish, French, German, Chinese, Japanese, Korean, Arabic, Hindi, Portuguese, Italian, Turkish, Dutch, Polish, Swedish, Vietnamese, Greek, Hebrew, Thai, Ukrainian, Czech.

= Where is the source code? =

The full sources — including the readable, unminified sources for the compiled CSS and JavaScript shipped in the ZIP — are published at https://github.com/paulmann/Contact-Form-7-LLM-Spam-Checker.

== Screenshots ==

1. Dashboard (light): KPI cards, the 30-day traffic chart and system health.
2. Dashboard (dark): the same view with the dark theme applied.
3. Spam registry: every verdict with its evidence, plus filters, paging and CSV export.
4. Settings → Protection: the individual checks, the keyword blacklist and the language options.
5. Integrations (light): the LLM gateway with per-provider credentials and the AbuseIPDB block.
6. Integrations (dark): the same page in dark mode.
7. Settings → Protection on a narrow viewport: the responsive, mobile-first layout.
8. Dashboard on a narrow viewport.
9. Integrations on a narrow viewport.

== Changelog ==

= 4.2.0 =
* Prepared the free version for the WordPress.org directory: the display name and slug no longer begin with another product's term, the text domain matches the new slug, and `LICENSE.txt` is the unmodified GPL-2.0 text.
* Updated the readme: a short description within the directory limit, a Screenshots section, a link to the readable sources and an accurate opt-in privacy disclosure naming all 11 LLM provider endpoints.
* Fixed: the CSV export of the spam registry now neutralises cells that spreadsheet applications would treat as formulas (OWASP CSV-injection guidance) — the export is opened by an administrator, so submitter-controlled columns must not be interpreted as active content.
* Fixed: the per-IP rate-limit counter is incremented by the storage layer instead of a read-modify-write in PHP, so simultaneous submissions no longer lose counts and the configured threshold fires when it should.
* Fixed: escalation to a permanent ban now reads the ban counter from the same atomic statement that increments it, so two close-together bans can no longer escalate one ban too late.
* Fixed: a regular plugin update (not only activation) now imports v3.x settings and ban lists and schedules the daily retention job — previously both were skipped and the registry could grow without bound.
* Added the `verdict` index on `cf7sec_events` (the registry filter was scanning the whole table).
* Security: every REST route now verifies `X-WP-Nonce` explicitly before the capability check, on top of core's cookie-authentication handling.
* Consistency: the default validation language comes from a single constant instead of three different literals; leftover dead code removed; the remaining hard-coded English strings in `admin.js` moved into the translation catalogue.
* Keyword filter: matching and list normalisation extracted into pure, testable functions; the keyword list is trimmed, de-duplicated and ignores tokens shorter than 4 characters. The pattern bank is maintained by the project owner in a separate, reviewed process.

= 4.1.1 =
* Fixed (blocker): the LLM check was dead code under the v2 settings schema — `LlmCheck::enabled()`, the fail-open/fail-closed policy and `run_when_flagged` still read the removed `deepseek.*` paths.
* Fixed (blocker): `Activator::maybeUpgrade()` built its settings snapshot before the schema migration and wrote that stale snapshot back, wiping the migrated LLM API key and `llm.enabled` on 4.0 → 4.1 upgrades.
* Fixed: `c-country-filter`, `c-save-btn` and `cf7sec-panel` had no CSS rules; the CF7 editor panel is rebuilt on the design system and `[hidden]` now reliably wins over the button display utilities.
* Fixed: language validation reads Contact Form 7 submission data instead of `$_POST`, the honeypot field name is salted per installation, debug log file names carry a per-installation suffix, per-category report counting is documented correctly and the shared-proxy spoofing risk of `ip_header_mode=auto` is documented.
* Fixed: Contact Form 7 older than 5.7 now detaches the protection hooks and shows an admin notice instead of silently half-working.
* Added a guard suite wired into CI: `php -l`, smoke tests, settings-path guard, compiled-asset guard, upgrade guard and documentation-link guard.

= 4.1.0 =
* NEW: multi-provider LLM gateway — DeepSeek, OpenAI (ChatGPT), Qwen (DashScope), Anthropic Claude, Google Gemini, Groq, Mistral, OpenRouter and xAI Grok out of the box, plus two universal protocols (OpenAI-compatible chat completions and Anthropic Messages) for any other operator via custom base URL + model.
* Per-provider API keys (masked in UI), free-text model ids with suggestions, base-URL overrides for proxies and regional endpoints.
* Settings schema migration: v4.0 `deepseek` options are converted to the new `llm` section automatically on upgrade.
* REST: `/cf7sec/v1/test/llm` (the old `/test/deepseek` route kept as an alias).

= 4.0.0 =
* Complete rewrite for PHP 8.3–8.5: modular PSR-4 architecture (30+ classes), strict types, readonly properties, enums.
* NEW: DeepSeek LLM spam classification (SPAM/CLEAN verdicts, model selection, fail-open/closed policies, connection tester).
* NEW: AbuseIPDB integration (confidence + total-reports + Email-Spam-category thresholds, DB cache, country filter, auto-ban, community reporting of confirmed spammers, live quota tracking).
* NEW: Spam registry in custom DB tables with evidence modal, filters, CSV export and retention policy (replaces JSON files).
* NEW: Admin UI rebuilt on a local Tailwind/shadcn-style design system with light/dark/system theme, mobile-first responsive layout, zero CDN dependencies.
* NEW: REST API (cf7sec/v1) for all admin operations with nonce + capability protection.
* NEW: i18n — English, Russian, Simplified Chinese, German (POT + PO + MO shipped).
* NEW: Tools — settings export/import, maintenance runner, debug log viewer, health diagnostics, IP lookup.
* NEW: Notifications — throttled admin e-mail alerts.
* Improved: cache-proof client-side timing (monotonic dwell time), proxy-aware IP resolution modes, strike escalation to permanent bans, refined SQLi/XSS signatures with fewer false positives.
* Fixed: legacy `_cf7a_timestamp` was invisible to CF7 ≥ 5.9 (underscore-prefixed POST keys are stripped) — replaced with working `cf7sec_ts`/`cf7sec_age` fields.
* Migration: v3.x options and ban_list.json imported automatically.

= 3.5.3 =
* Legacy single-file release (see repository history).

== Upgrade Notice ==

= 4.2.0 =
Fixes silent counter loss under concurrent submissions, a stale ban-escalation decision, a skipped v3.x import on regular updates, a missing registry index and spreadsheet-formula injection in the CSV export. Renames the display name and text domain for the WordPress.org directory; your settings, bans, tables and REST namespace are unaffected. Requires PHP 8.3+.

= 4.1.1 =
Defect-audit fixes: the LLM check no longer reads removed option paths, and 4.0 → 4.1 upgrades keep the LLM API key. Recommended for everyone on 4.0.x or 4.1.0.

= 4.0.0 =
Major rewrite: PHP 8.3+, database-backed registry, DeepSeek AI and AbuseIPDB integrations, brand-new admin UI. Your v3 settings and bans are migrated automatically on activation.
