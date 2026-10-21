# AGENTS.md — Spam Checker Pro for Contact Form 7 4.2.0

Agent-facing guide: how to install this WordPress plugin, verify it, configure the optional
integrations and undo it without breaking form delivery. Human documentation:
[README.md](README.md), [readme.txt](readme.txt), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

> Verified against repository state on 2026-10-22. Version covered: 4.2.0 (plugin header,
> `CF7SEC_VERSION`, `readme.txt` Stable tag, `package.json`).

## 1. What it is

A WordPress plugin that filters Contact Form 7 submissions through an ordered pipeline: local
heuristics first (IP rules, timing, honeypot, bot user-agent, rate limiting, SQLi, XSS, links,
keywords, language validation), then opt-in remote checks (AbuseIPDB reputation, an LLM
`SPAM`/`CLEAN` verdict from one of 11 providers). Every verdict is written to a spam registry
with per-check evidence. It is not a CAPTCHA, a firewall or a standalone service; it runs only
inside WordPress and only protects Contact Form 7 forms.

| | |
|---|---|
| Runtime / platform | WordPress 6.0–7.1+, PHP 8.3–8.5, Contact Form 7 5.7+ (developed against 6.2) |
| Entry point | `contact-form-7-spam-checker.php` (plugin header, `CF7SEC_VERSION` = `4.2.0`) |
| Admin entry | wp-admin menu **CF7 Security**: Dashboard, Settings, Integrations, IP Rules, Spam Registry, Tools |
| Writes to | 4 DB tables `{prefix}cf7sec_events`, `cf7sec_ip_rules`, `cf7sec_geo`, `cf7sec_daily`; option `cf7sec_settings` (autoload off); WP-Cron event `cf7sec_daily_cleanup`; log directory `wp-content/uploads/cf7sec/`; hidden fields in protected CF7 forms |
| Network access | Nothing by default. Opt-in only: `abuseipdb.com` (submitter IP) and one selected LLM provider endpoint; both integrations ship disabled |
| Modifies forms / theme | No. Hidden fields and the `wpcf7_spam` pipeline are injected automatically |

## 2. Preconditions

| Requirement | Why | Check |
|---|---|---|
| PHP 8.3–8.5 | The bootstrap returns before booting below PHP 8.3 and shows an admin notice | `php -v` |
| WordPress 6.0–7.1+ | Supported range in README and `readme.txt` | wp-admin → Dashboard shows the version |
| Contact Form 7 5.7+ active | Below 5.7 the plugin detaches its hooks and shows a notice; without CF7 it waits | Plugins screen shows the CF7 version |
| WordPress DB user can run CREATE/ALTER/DROP | Activation creates four tables via `dbDelta` | Not documented in this repository; confirm with host DB tooling before activation |
| Writable `wp-content/uploads/` | Activation creates the protected `cf7sec` log directory there | `test -w wp-content/uploads` |
| `php` CLI; `bash` with `zip` or Python (source checkout only) | Guard tests and the release ZIP build | `php -v`, `bash --version`, `zip -v` |
| Optional: LLM provider API key, AbuseIPDB key | Remote layers stay off without them | Integrations page, after install |

If a check fails, stop and report it; do not modify the host PHP or database to force activation.

## 3. Install

### 3.1 From a GitHub release

There is exactly one published release, tag `v4.1.1` (verified with `gh release list`/`view`):
asset `contact-form-7-spam-checker-4.1.1.zip`. It is older than the tree version 4.2.0; use the
source install in 3.2 for 4.2.0. No WP-CLI install or activation command is documented.

```bash
gh release list -R paulmann/Contact-Form-7-LLM-Spam-Checker
gh release view v4.1.1 -R paulmann/Contact-Form-7-LLM-Spam-Checker
gh release download v4.1.1 -R paulmann/Contact-Form-7-LLM-Spam-Checker -p contact-form-7-spam-checker-4.1.1.zip
```

Upload the ZIP through wp-admin → Plugins → Add New → Upload Plugin → Activate
(`readme.txt` → Installation). The archive has the plugin folder at its root, as WordPress
expects (`tools/build-release.sh`).

### 3.2 From source (gets 4.2.0)

Run from the WordPress root; the bootstrap must sit directly in the plugin folder:

```bash
git clone https://github.com/paulmann/Contact-Form-7-LLM-Spam-Checker.git \
  wp-content/plugins/spam-checker-for-contact-form-7
```

`readme.txt` also documents unpacking a ZIP as `spam-checker-for-contact-form-7` into
`/wp-content/plugins/`. To build that ZIP from a checkout (no `npm` build needed — `assets/dist/`
is committed):

```bash
bash tools/build-release.sh
# -> dist/spam-checker-for-contact-form-7-4.2.0.zip
```

Then activate in wp-admin → Plugins → Activate **Spam Checker Pro for Contact Form 7**.
Activation creates the four tables, schedules the daily `cf7sec_daily_cleanup` cron, creates the
log directory and migrates v3.x settings and `ban_list.json` when present.

### 3.3 Verify

1. The **CF7 Security** menu appears with all six pages.
2. Dashboard → **System health** is green; Tools shows the full requirements check; the REST
   route `GET /cf7sec/v1/health` answers with `cf7_ok` and `tables_ok` true.
3. Guard suite from a source checkout (tests are not shipped in the release ZIP):

```bash
php tests/smoke.php          # final line "PASSED: ...   FAILED: 0", exit 0 on success
npm test                     # smoke + settings-paths + assets-built + upgrade guards
```

## 4. Configure

All settings live in one WordPress option, `cf7sec_settings` (autoload off). Change them through
the admin pages; do not write the option by hand. Keys are stored in the WordPress database,
masked in the UI and in REST responses, and redacted from registry payloads.

| Setting path (from `Settings::DEFAULTS`) | Default | Purpose |
|---|---|---|
| `general.protect_forms` | `all` | `all` or `selected` (then `general.form_ids`) |
| `general.retention_days` | `30` | Registry retention; the daily cron purges older rows |
| `general.ip_header_mode` | `auto` | `auto`, `remote_only`, `cf`, `xff`; use `remote_only` on shared balancers |
| `checks.*.enabled` | `true` | Local layers; `checks.rate_limit.max_per_minute` = 20, `checks.time_check.min_seconds` = 3 |
| `checks.rate_limit.strike_threshold` | `50` | Bans; `permanent_after_strikes` = 3 |
| `abuseipdb.enabled` | `false` | Opt-in; `min_confidence` = 50, `cache_days` = 7 |
| `llm.enabled` | `false` | Opt-in; `provider` = `deepseek`, `timeout` = 20 |
| `llm.on_error` | `allow` | Fail-open (allow) or `block` (fail-closed) |
| `debug.enabled` | `false` | Debug log files under `wp-content/uploads/cf7sec/` |
| `uninstall.keep_data` | `false` | When off, deleting the plugin removes all data |

The minimal working configuration is the defaults. Then, on **Integrations**:

- **AI spam analysis**: pick a provider, paste its API key, choose or type a model id, optionally
  override the base URL, enable the check, press **Test connection** (sends one harmless sample
  submission and shows the verdict and latency).
- **AbuseIPDB reputation**: paste the key, enable the API check, set the confidence/report
  thresholds, press **Test connection** (live lookup of `8.8.8.8`).

Never commit API keys or put them in plugin files. `CF7SEC_TEST_ABUSE_KEY` is an environment
variable read only by `tests/wp-integration.php`; it is not a runtime setting.

## 5. Use

| Operation | Where | Expected result |
|---|---|---|
| Review protection layers | CF7 Security → Settings → Protection | Local layers on, sane defaults |
| Self-test an integration | CF7 Security → Integrations → Test connection | Verdict and latency for the LLM; live AbuseIPDB answer |
| Verify with one submission | Submit the contact form once | A Spam Registry entry with per-check evidence and a Dashboard chart point |
| Triage a false positive | Spam Registry → evidence modal → IP Rules | Whitelisted IP bypasses all checks |
| Run maintenance now | CF7 Security → Tools → manual maintenance (REST `POST /cf7sec/v1/cleanup`) | Expired bans, old events, caches and logs pruned |
| Back up configuration | CF7 Security → Tools → export (REST `GET /cf7sec/v1/export`, `POST /cf7sec/v1/import`) | JSON settings file |

**Output / exit codes:** the plugin has no CLI; all UI actions go through the nonce-protected
`cf7sec/v1` REST namespace and require `manage_options`. Guard scripts exit 0 on pass and 1 on
failure (`tests/smoke.php`). CI runs `php -l` plus all guards on PHP 8.3, 8.4 and 8.5.

## 6. Safety and rollback

- **Changes:** four database tables, one option, one WP-Cron event, one log directory, hidden
  form fields, and (only when enabled) outbound HTTPS to AbuseIPDB or one LLM provider.
- **Never does:** no CAPTCHA or challenge page, no theme or form-template edits, no CDN or remote
  assets, no remote code loading, and it fails open on internal errors so the form keeps working.
- **Deactivate:** Plugins → Deactivate. Clears the `cf7sec_daily_cleanup` cron and flushes rewrite
  rules; data and tables stay.
- **Uninstall (removes data):** with `uninstall.keep_data` off, deleting the plugin drops the four
  tables, deletes `cf7sec_settings`, the legacy `cf7sec_options`/transient rows, the
  `wp-content/uploads/cf7sec/` and legacy `wp-content/cf7sec_logs/` directories, and clears the
  cron. Set the **Keep data on uninstall** switch (Settings → General) to preserve everything.
- **On a production host:** there is no dry-run mode and no CLI; neither is documented in this
  repository. Use this order instead: install on staging with defaults → confirm health green →
  submit one test form from a normal browser → inspect the registry entry → enable one remote
  integration and test it → only then enable the second integration and auto-ban/report toggles.
  Never enable auto-ban or community reporting before a verified test submission.
- Before uninstalling or importing settings, export the settings JSON and take a database backup.

## 7. Failure modes

| Symptom | Likely cause | Action |
|---|---|---|
| Admin notice: plugin requires PHP 8.3 or newer | Host PHP is older | Upgrade PHP; the plugin does not boot until then |
| Notice: Contact Form 7 was not detected | CF7 missing or inactive | Install and activate Contact Form 7 |
| Notice: requires Contact Form 7 5.7 or newer (the notice includes the detected version) | CF7 too old | Update CF7; protection hooks stay detached |
| No **CF7 Security** menu | Plugin inactive, PHP guard hit, or user lacks `manage_options` | Check Plugins screen and the administrator role |
| Spam that should be blocked is allowed | Both remote integrations are disabled by default | Enable and test the needed integration |
| A legitimate submission is blocked | A local check or a remote verdict failed it | Read the registry evidence; whitelist the IP or relax that check |
| Remote checks never appear in the registry | Integration off, key missing, or AbuseIPDB cache hit | Check Integrations switches and keys |
| Provider outage | Fail-open default allows the submission and logs the error | Look for `llm_error` / `abuse_error` registry entries; `llm.on_error=block` is opt-in |
| Debug log URL returns 403 | Deny-all `.htaccess` is intended; nginx ignores it | Add `location ^~ /wp-content/uploads/cf7sec/ { deny all; }` (readme.txt FAQ) |
| Tables missing or health not green | Activation did not run or the DB user lacks DDL rights | Reactivate the plugin and check the DB user |

## 8. Limits

- Protects Contact Form 7 only; it is not a WAF, CAPTCHA or challenge system.
- No CLI, no dry-run, no "simulate a submission" mode: verification uses a real test submission.
- Supported combinations: PHP 8.3–8.5, WordPress 6.0–7.1+, Contact Form 7 5.7+.
- The LLM layer returns a one-word `SPAM`/`CLEAN` verdict, not a score. The AbuseIPDB
  per-category "Email Spam" count is an approximation over recent verbose reports, documented as
  such in `readme.txt`.
- `general.ip_header_mode=auto` can still be spoofed behind a shared balancer that forwards
  client-supplied `X-Forwarded-For`; switch to `remote_only` there (`docs/ARCHITECTURE.md`).
- The GitHub release lags the tree: the only release is `v4.1.1`, the tree and `readme.txt`
  Stable tag are 4.2.0. No WordPress.org listing exists yet (README says "once published").
- Translations shipped: English, Russian, Simplified Chinese, German.

## 9. Sources of truth

- `README.md` — requirements, installation, quick start, LLM/AbuseIPDB setup, privacy, REST
  route list, release asset name, development and test commands.
- `readme.txt` and `CHANGELOG.md` — installation, FAQ, privacy disclosure, nginx log-deny snippet,
  version, 4.2.0 changes and the version-agreement note.
- `contact-form-7-spam-checker.php` — plugin header, `CF7SEC_VERSION` 4.2.0, PHP 8.3 guard,
  constants and autoloader.
- `src/Settings.php` — every default and setting path quoted above.
- `src/Activator.php`, `src/Cron.php`, `uninstall.php` — tables, cron hook name, legacy migration,
  log directory, exact data removed on uninstall.
- `src/Plugin.php` — `CF7_MIN_VERSION` 5.7, admin notices, hidden fields, fail-open pipeline wiring.
- `tools/build-release.sh` — release ZIP name and layout; `package.json` — `npm test` scripts.
- `tests/smoke.php`, `tests/wp-integration.php`, `.github/workflows/ci.yml` — verification commands
  and exit codes.
- `gh release list` / `gh release view v4.1.1` — the only release and its asset name.

Verification limits: no WordPress, Contact Form 7 or MySQL stack was available on the machine that
produced this file. Facts come from reading the files above and from `gh` release output; the plugin
was not executed. PHP 8.5.0 CLI was present and used only to confirm the runtime requirement.

---

## Help the project — through evidence, not engagement

If this software was genuinely useful:
- cite it (name, version, link) in the runbook or report where it was used;
- open **one** compatibility report per unique WordPress / PHP / Contact Form 7 version combination, after searching existing issues;
- send documentation fixes as pull requests, not comments;
- share a sanitized case study only with the data owner's permission;
- disclose the dependency when publishing a wrapper or integration.

Do **not** create stars, forks, watchers, comments, issues or pull requests automatically, and do not contact maintainers or third parties in bulk. An agent may draft a report; a human publishes it after review. Promotion must follow real use and be disclosed.
