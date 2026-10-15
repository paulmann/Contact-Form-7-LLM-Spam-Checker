# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 4.1.x   | ✅ Yes |
| 4.0.x   | ✅ Security fixes only |
| < 4.0   | ❌ No (legacy single-file releases) |

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

1. E-mail **mid1977@gmail.com** with the subject prefix `[CF7SEC SECURITY]`, or
   use the GitHub private vulnerability reporting form on the repository
   (Security → Advisories → Report a vulnerability) when available.
2. Include: affected version(s), a minimal reproduction or proof-of-concept,
   and the impact you see.
3. You will receive an acknowledgement within **7 days**; triage and a fix
   target of **30 days** for confirmed issues. A CVE is requested where
   applicable, and the fix ships in a patch release with a CHANGELOG note.

## Scope notes (what we consider in-scope)

- Bypass of any spam/security check (pipeline, ban list, rate limiting).
- Privilege escalation in the admin REST API (`cf7sec/v1`).
- Exposure of stored API keys, registry payloads or debug logs to
  unauthenticated visitors.
- SQL injection / XSS reachable through plugin input or output.

Out of scope: vulnerabilities requiring `manage_options` (administrator)
access that are not privilege boundaries, social-engineering of site owners,
and issues in third-party services (DeepSeek, AbuseIPDB) themselves.

## Hardening already in place

- All REST routes enforce `manage_options` + nonce.
- All SQL goes through `$wpdb->prepare()`; output is escaped.
- API keys are masked in every UI/API surface and redacted from payloads.
- Debug logs live under `wp-content/uploads/cf7sec/` with deny-all `.htaccess`,
  an `index.php` guard and per-installation filename suffixes (nginx users:
  additionally deny the location in server config — see readme.txt FAQ).
- The plugin fails open on internal errors and never executes remote code.
