# Architecture — Spam Checker Pro for Contact Form 7 v4

> For the Russian summary see the bottom of this document / [README.ru.md](../README.ru.md).

## 1. Goals & constraints

- **PHP 8.3 – 8.5** runtime: strict types, readonly properties, enums, typed class constants; no 8.4-only syntax (e.g. no property hooks) so one codebase runs on both.
- **WordPress 6.0 – 7.1+**, Contact Form 7 5.7+ (developed against CF7 6.2).
- **Zero external runtime assets** — no CDNs; Tailwind output is compiled at build time and committed to `assets/dist`. The build (`npm run build`, i.e. `mkdir -p assets/dist` + tailwind + JS copy) is **mandatory before cutting a release**; CI enforces presence and class coverage via `tests/assets-built.php`.
- **Fail-open safety** — the plugin must never break form delivery; all unexpected errors are caught, logged and reported.

## 2. Component map

```
                         ┌────────────────────────────┐
   CF7 submission ──────▶│  SpamPipeline (wpcf7_spam) │
                         └────────────┬───────────────┘
        ┌─────────────────────────────┼───────────────────────────────┐
        ▼                             ▼                               ▼
  local checks (9)             AbuseIpCheck                     LlmCheck
  TimeCheck HoneypotCheck      └▶ AbuseIpDb service             └▶ DeepSeek service
  IpBanCheck BotAgentCheck        └▶ GeoCache (DB)                 └▶ LlmPrompt digest
  RateLimitCheck SqlInj…          └▶ country filter
  XssCheck LinkSpam Keyword…
        │                             │                               │
        └──────────────┬──────────────┴───────────────┬───────────────┘
                       ▼                              ▼
              CheckResult (value object)      SubmissionContext
                       │
        ┌──────────────┼──────────────────────┬─────────────────┐
        ▼              ▼                      ▼                 ▼
  EventsRepository  DailyStats          BanManager          Mailer/Logger
  (spam registry)   (counters)          (strikes/bans)      (alerts/debug)
```

### Composition root

`Plugin` (singleton via `cf7sec()`) constructs all services once per request and
registers hooks in `boot()`. No service locator, no globals beyond WordPress itself.

### Key classes

| Class | Responsibility |
|---|---|
| `Plugin` | composition root, hook wiring, frontend hidden fields + inline JS |
| `Settings` | schema (DEFAULTS), dotted access, sanitisation, secret masking |
| `Activator` | dbDelta tables, option seeding, v3 migration, log dir, cron |
| `Cron` | daily maintenance: purge bans/events/geo/logs |
| `SpamPipeline` | ordering, short-circuiting, verdict aggregation, side effects |
| `SubmissionContext` | immutable snapshot of one submission (+lazy geo) |
| `CheckResult` / `CheckStatus` | per-check outcome value object (pass/fail/skip/error) |
| `Checks\*` | 11 self-contained checks, one file each |
| `Patterns` | SQLi/XSS/bot-UA signature registries with human descriptions |
| `LlmPrompt` | digest builder for the LLM (fields, IP, geo, UA, timing, local verdicts) |
| `LanguageValidator` | `wpcf7_validate_*` field-level language errors |
| `Services\Llm\LlmGateway` | provider resolution, verdict parser, self-test |
| `Services\Llm\ProviderCatalog` | 11 providers × 2 universal protocols (OpenAI-compatible, Anthropic Messages) |
| `Services\Llm\OpenAiChatClient` / `AnthropicMessagesClient` | protocol clients (Bearer / x-api-key auth) |
| `Services\AbuseIpDb` | check/report client, cache-first, quota tracking |
| `Services\BanManager` | ban/whitelist/strike/escalation policy |
| `Services\Mailer` | throttled notifications |
| `Services\Logger` | rotating daily debug files |
| `Storage\*` | table repositories (events, ip_rules, geo, daily) |
| `Support\Ip` | proxy/CDN-aware client IP resolution |
| `Admin\*` | menu, assets, page renderer, REST controller, views |

## 3. Data model

All tables use the WP prefix (default `wp_`), InnoDB, and are created with `dbDelta`
(idempotent upgrades via `internal.db_version`).

### `{prefix}cf7sec_events` — spam registry
| column | type | notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| created_at | DATETIME | UTC, indexed |
| form_id | BIGINT UNSIGNED | indexed |
| ip | VARCHAR(45) | IPv4/IPv6, indexed |
| country | CHAR(2) | when known |
| event_type | VARCHAR(32) | `spam_blocked`, `clean`, `llm_error`, `abuse_error`, `ip_banned`, `language_invalid`, `internal_error` |
| verdict | VARCHAR(16) | `blocked` / `allowed` / `error` / `info` |
| reasons | TEXT (JSON) | human-readable fail reasons |
| checks | LONGTEXT (JSON) | every `CheckResult::toArray()` of the run |
| payload | LONGTEXT (JSON) | sanitized posted data (secrets redacted, ≤64 KB) |
| user_agent, referrer | VARCHAR | |

Retention: daily cron deletes rows older than `general.retention_days`.

### `{prefix}cf7sec_ip_rules` — bans, whitelist, strikes
| column | notes |
|---|---|
| ip | UNIQUE — one row per IP |
| list_type | `ban` \| `whitelist` \| `strike` |
| reason, source | provenance (`manual`, `auto`, `abuseipdb`, `llm`, `migration`…) |
| strikes | spam-strike counter (decays with retention) |
| banned_count | escalation counter: N-th ban → permanent |
| created_at / expires_at | `expires_at NULL` = permanent (only meaningful for `ban`) |

Invariant: **only `list_type='ban'` rows block traffic.** `strike` rows are
bookkeeping placeholders and must never be interpreted as bans (regression-tested).

### `{prefix}cf7sec_geo` — AbuseIPDB cache
PK on `ip`; stores score, totals, per-category recent Email-Spam count, country,
ISP, usage type, Tor/whitelist flags, `checked_at` for TTL.

### `{prefix}cf7sec_daily` — aggregate counters
PK on date `d`; atomic upsert (`ON DUPLICATE KEY UPDATE`) for processed/blocked/
llm/abuse/ban counters. Powers dashboard chart without scanning the registry.

Rate-limit windows use **transients** (`cf7sec_rl_<ip-hash>`, 120 s TTL) —
ephemeral by design, never in autoloaded options.

## 4. Pipeline semantics

1. Whitelisted IP → allow immediately (no logging unless `log_verdicts=all`).
2. Local checks run in fixed order; **all** of them execute even after a fail so
   the registry shows the full picture (they are pure-CPU cheap).
3. Remote checks (AbuseIPDB → DeepSeek) run only if nothing failed yet, unless
   `deepseek.run_when_flagged=true`.
4. First fail determines the visitor-facing message (`messages.*` template via
   `wpcf7_display_message`).
5. Side effects on block: registry insert, `blocked++`, strike++, optional
   auto-bans (abuseipdb/llm), optional AbuseIPDB community report, throttled
   e-mail notification.
6. Any `Throwable` → fail-open, `internal_error` registry entry, debug log.

### Client-IP trust model (known risk, documented)

In `general.ip_header_mode = auto` (default) forwarded headers are trusted only
when `REMOTE_ADDR` is a private/loopback address or a published Cloudflare
range. On shared hosting where all traffic arrives from a shared balancer that
does **not** strip client-supplied forwarded headers, a client can still spoof
`X-Forwarded-For` (the `xff` mode is fully client-controlled by design).
Recommendation for such hosts: switch to `remote_only` — the admin UI states
this in the field hint, and `tests/smoke.php` pins the documented behaviour of
`Ip::firstFromList()` on spoofed lists.

### Timing analysis (why it is cache-proof)

Legacy v3 read `_cf7a_timestamp` from `posted_data` — but CF7 ≥ 5.9 strips
`_`-prefixed POST keys, so the check was silently dead. v4 uses:

- `cf7sec_age` — seconds between page load and submit, measured **client-side**
  with `Date.now()` deltas at load and submit time (monotonic for the session,
  immune to clock skew and to cached HTML), stamped into the hidden field by an
  inline script attached to `contact-form-7`;
- `cf7sec_ts` — server render timestamp fallback (JS disabled) with a
  configurable skew tolerance.

## 5. Settings schema (option `cf7sec_settings`, autoload off)

`Settings::DEFAULTS` is the single source of truth (see `src/Settings.php`):
`general`, `notifications`, `checks.{time_check,honeypot,ip_rules,rate_limit,
sql_injection,xss,bot_ua,link_spam,keyword_spam,language}`, `abuseipdb`,
`deepseek`, `messages`, `debug`, `uninstall`, `internal`.

Sanitisation is allow-list based: unknown keys are dropped, numbers clamped,
enums whitelisted, URLs scheme-checked, secrets passed through an alphanumeric
filter. Masked secrets (`••••…abcd`) round-trip safely — the UI never receives
raw keys on GET, and posting a mask back keeps the stored value.

## 6. Admin UI

- Server-rendered PHP views (`Admin/Views/pages/*.php`) + a shared shell
  (`PageRenderer`) + component helpers (`Views/Ui`) producing semantic
  `c-*` classes styled by the compiled Tailwind design system.
- `assets/dist/admin.js` (vanilla ES2017, ~50 KB) implements: theme manager,
  tabs, switch controls, dirty-tracking settings forms (dotted `name` paths →
  nested JSON → REST), toasts, modals, SVG chart, paginated tables, CSV/JSON
  downloads via authenticated fetch.
- Theme: pre-paint inline bootstrap reads `localStorage('cf7sec-theme')`
  (`light|dark|system`), applies `.cf7sec-dark` on `<html>` before first paint;
  the toggle cycles states; `prefers-color-scheme` is watched live.
- Tokens: shadcn/ui-style HSL custom properties prefixed `--cf7sec-*`, scoped
  to `.cf7sec-app` — wp-admin chrome is untouched.

## 7. Security posture

- Every REST route: `permission_callback` (`manage_options`) + core nonce check.
- All output escaped (`esc_html`, `esc_attr`, JSON through `wp_json_encode`);
  JS re-escapes every dynamic value before `innerHTML`.
- SQL: 100 % `$wpdb->prepare` (verified by integration tests); LIKE patterns
  escaped with `esc_like`.
- Secrets: stored in options (standard WP practice), masked in every UI/API
  surface, redacted from registry payloads (`pass|pwd|secret|token|card|cvv`).
- IP spoofing: forwarded headers trusted only from private REMOTE_ADDR or
  Cloudflare ranges (mode configurable); private-range bans refused.
- Debug logs: `wp-content/uploads/cf7sec/` with deny-all `.htaccess` + index.php.
- Outbound HTTP: `wp_remote_*` with explicit timeouts only; no eval, no
  dynamic includes, no remote code loading.

## 8. i18n pipeline

```
src/**/*.php ──(tools/extract-pot.py)──▶ languages/*.pot
tools/i18n-{ru,zh,de}.py ──(tools/make-i18n.py)──▶ languages/*-{locale}.{po,mo}
```

The MO compiler is pure Python (verified against WordPress' own `MO` reader —
magic, offsets, header entry, context entries `\x04`-joined). 385 strings,
100 % translated for ru_RU / zh_CN / de_DE.

## 9. Testing strategy

| suite | runner | scope |
|---|---|---|
| `tests/smoke.php` | plain `php8.3` (WP stubs) | pure logic: settings sanitisation, IP utils, all local checks, verdict parser, prompt builder — 73 assertions |
| `tests/wp-integration.php` | `wp eval-file` inside real WP 7.1 + CF7 6.2 + MariaDB | activation, full submission pipeline, bans/escalation/whitelist, REST endpoints, live AbuseIPDB call (optional env key), page rendering, v3 migration, maintenance — 59 assertions |

## 10. Build & release

```
tools/build-release.sh → dist/spam-checker-for-contact-form-7.zip
```
The ZIP contains runtime files only (`*.php` root, `src/`, `assets/dist/`,
`languages/`, `readme.txt`, `README*.md`, `CHANGELOG.md`, `LICENSE.txt`,
docs). Dev files (`tests/`, `tools/`, `assets/src/`, `package.json`,
`tailwind.config.js`, configs) stay in the repository but out of the release.

---

## Резюме (Russian)

- **Модульная архитектура**: PSR-4 пространство `CF7Sec\`, 30+ классов, строгая типизация, PHP 8.3–8.5.
- **Конвейер проверок**: 9 локальных (баны, тайминги, ловушка, боты, rate-limit, SQLi, XSS, ссылки, слова) → AbuseIPDB (3 порога + фильтр стран) → LLM-шлюз (SPAM/CLEAN, 11 провайдеров). Внешние API вызываются только для «чистых» отправок.
- **Хранилище**: 4 таблицы БД (реестр спама, IP-правила, гео-кэш, суточные счётчики); JSON-файлы v3 мигрируются при активации.
- **Безопасность**: nonce + capability на всех REST-маршрутах, prepare() на всех SQL, экранирование вывода, маскирование ключей, отказ банить частные диапазоны, fail-open при сбоях.
- **UI**: локальная сборка Tailwind (без CDN), токены в стиле shadcn/ui, светлая/тёмная/системная тема, mobile-first, REST-driven таблицы и формы.
- **i18n**: EN/RU/ZH/DE — 385 строк, собственный компилятор .mo, проверенный парсером WordPress.
- **Тесты**: 73 smoke-проверки без WP + 59 интеграционных в реальных WordPress 7.1 + CF7 6.2 (включая живой вызов AbuseIPDB).
