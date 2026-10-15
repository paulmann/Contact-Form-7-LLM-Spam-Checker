=== CF7 Spam Checker Pro ===
Contributors: mid1977
Donate link: https://deynekin.com/donate
Tags: contact form 7, spam, antispam, security, honeypot
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 4.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-layered spam protection for Contact Form 7: honeypot, timing analysis, rate limiting, SQLi/XSS/bot detection, AbuseIPDB reputation and AI checks via 11 LLM providers (DeepSeek, OpenAI, Qwen, Claude, Gemini, Groq, Mistral, OpenRouter, xAI, custom).

== Description ==

CF7 Spam Checker Pro protects your Contact Form 7 forms with a layered pipeline — no CAPTCHAs, no puzzles, zero friction for real visitors.

**Protection layers**

* Behavioural: client-measured fill time (cache-proof monotonic timer) + honeypot field
* Structural: SQL-injection, XSS, excessive links, keyword blacklist (admin-editable)
* Identity: bot user-agent detection with false-positive suppression, per-IP rate limiting with strike escalation and automatic bans
* Language: character-set validation for 22 languages on name fields, with friendly per-field errors
* Reputation: **AbuseIPDB** — abuse-confidence score, total report count and per-category "Email Spam" report counting, country block/allow lists, optional reporting of confirmed spammers back to the community
* AI: **LLM gateway** — the full submission digest (fields, IP, geo, browser, timing) is classified as SPAM or CLEAN by the provider of your choice: DeepSeek, OpenAI/ChatGPT, Qwen, Anthropic Claude, Google Gemini, Groq, Mistral, OpenRouter, xAI Grok, or any custom OpenAI-compatible / Anthropic-compatible endpoint (two universal protocols)

**Platform features**

* Spam registry: every verdict stored with full evidence, filters, CSV export, retention policy (GDPR)
* IP management: bans + whitelist, permanent-ban escalation, bulk operations, on-demand AbuseIPDB lookup
* Dashboard: KPI cards, 30-day traffic chart, system health, recent events
* Admin UI: modern responsive design, light/dark/system theme with persistence, fully local assets (no CDNs)
* Notifications: throttled e-mail alerts for bans, spam waves and integration errors
* Tools: settings export/import, debug log viewer, one-click maintenance, clean uninstall
* Translations: English, Russian, Simplified Chinese, German

**Privacy**

By default only blocked submissions are stored, for a configurable number of days. The optional AbuseIPDB integration transmits the submitter IP to abuseipdb.com; the optional DeepSeek integration transmits the submission content digest to api.deepseek.com. Both are off until you add an API key and enable them.

== Installation ==

1. Upload the plugin ZIP via *Plugins → Add New → Upload Plugin*, or unzip `contact-form-7-spam-checker` into `/wp-content/plugins/`.
2. Activate the plugin. Database tables are created automatically; v3.x settings and ban lists are migrated on activation.
3. Open **CF7 Security** in the admin menu and review *Settings → Protection*.
4. (Optional) Add your DeepSeek and AbuseIPDB keys under *Integrations* and press **Test connection**.

Requires Contact Form 7 5.7 or newer. PHP 8.3 – 8.5.

== Frequently Asked Questions ==

= Do I need to change my forms? =

No. Protection is injected automatically via hidden fields and the `wpcf7_spam` pipeline. No shortcodes or tags required.

= Will this slow down form submissions? =

Local checks run in microseconds. Remote checks (AbuseIPDB, DeepSeek) execute only when the submission is still clean, with configurable timeouts and fail-open behaviour, and AbuseIPDB responses are cached locally.

= What happens if DeepSeek or AbuseIPDB is down? =

You choose per integration: fail-open (allow, log the error — default) or fail-closed (block). Errors are visible in the Spam Registry and can trigger e-mail alerts.

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

== Changelog ==

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
* Migration: v3.x options and ban_list.json imported automatically on activation.

= 3.5.3 =
* Legacy single-file release (see repository history).

== Upgrade Notice ==

= 4.0.0 =
Major rewrite: PHP 8.3+, database-backed registry, DeepSeek AI and AbuseIPDB integrations, brand-new admin UI. Your v3 settings and bans are migrated automatically on activation.
