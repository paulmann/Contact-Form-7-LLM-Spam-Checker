# Contributing

Thanks for helping make Spam Checker Pro for Contact Form 7 better!

## Ground rules

- Be respectful; this project follows the [WordPress Code of Conduct](https://make.wordpress.org/handbook/community-code-of-conduct/).
- One concern per pull request; keep the diff reviewable.
- The plugin targets **PHP 8.3 – 8.5** and **WordPress 6.0+**: no syntax or
  functions newer than PHP 8.3, no WordPress APIs newer than 6.0.
- Every behaviour change ships with a test:
  pure logic → `tests/smoke.php`; WordPress-dependent → `tests/wp-integration.php`;
  new invariant → a small guard script in `tests/` (see `settings-paths.php`).

## Getting started

```bash
git clone https://github.com/paulmann/Contact-Form-7-LLM-Spam-Checker.git
cd Contact-Form-7-Spam-Checker
npm install tailwindcss@3 --no-save   # only if you touch assets/src
composer install                      # only if you run PHPCS locally
```

Run the guard suite (no WordPress needed):

```bash
npm test
```

## Code style

- `declare(strict_types=1);` at the top of every PHP file.
- 4 spaces, LF line endings, UTF-8 without BOM (enforced by `.editorconfig`
  and `.gitattributes`).
- PSR-4: one class per file under `src/`, namespace `CF7Sec\…`.
- Text domain `spam-checker-for-contact-form-7` for **every** user-facing string;
  escape on output (`esc_html`, `esc_attr`, `esc_url`), sanitise on input.
- `$wpdb->prepare()` for every query; no direct superglobal reads in new code
  (see `LanguageValidator::postedValue()` for the sanctioned pattern).
- PHPCS configuration: `phpcs.xml` (advisory in CI until the baseline is clean).

## Translations

Never edit `.po`/`.mo` by hand in a PR — they are generated:

```bash
npm run i18n:extract   # rebuild the POT from source
# add your strings to tools/i18n-<locale>.py (or start a new dictionary)
npm run i18n:build     # rebuild .po/.mo
```

New locales need an entry in `tools/make-i18n.py` (`LANGS`) and the correct
`Plural-Forms` header.

## Assets

`assets/dist/` is **committed** (production must not build). When you change
`assets/src/`, run `npm run build` and include the rebuilt files.

## Pull request checklist

- [ ] `npm test` green (smoke + all guard scripts)
- [ ] `php -l` clean on changed files
- [ ] `tests/wp-integration.php` run against a local WP + CF7 when touching
      pipeline/storage/REST code
- [ ] New/changed user-facing strings added to all dictionaries in `tools/`
- [ ] CHANGELOG.md updated (Keep a Changelog format)
- [ ] No secrets, keys or tokens anywhere in the diff
