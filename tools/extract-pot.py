#!/usr/bin/env python3
"""Extract translatable strings from the plugin PHP sources into a POT file.

Handles: __(), _e(), esc_html__(), esc_attr__(), esc_html_e(), esc_attr_e(),
translate_with_gettext_context() — single- and double-quoted PHP strings,
multi-line calls, and translator comments.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOMAIN = 'contact-form-7-spam-checker'


def plugin_version():
    """Read CF7SEC_VERSION from the plugin bootstrap (single source)."""
    src = open(os.path.join(ROOT, DOMAIN + '.php'), encoding='utf-8').read()
    m = re.search(r"define\('CF7SEC_VERSION', '([^']+)'\)", src)
    return m.group(1) if m else '0.0.0'

FUNC_PATTERNS = [
    # (regex, group_msg, group_context)
    (re.compile(r'\btranslate_with_gettext_context\s*\(\s*(\'(?:[^\'\\]|\\.)*\'|"(?:[^"\\]|\\.)*")\s*,\s*(\'(?:[^\'\\]|\\.)*\'|"(?:[^"\\]|\\.)*")\s*,\s*[\'"]' + DOMAIN + r'[\'"]', re.S), 1, 2),
    (re.compile(r'\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(\s*(\'(?:[^\'\\]|\\.)*\'|"(?:[^"\\]|\\.)*")\s*(?:,\s*[\'"]' + DOMAIN + r'[\'"])?', re.S), 1, None),
]

TRANSLATOR_COMMENT = re.compile(r'/\*\s*translators?:\s*(.*?)\*/', re.I | re.S)


def php_unquote(s: str) -> str:
    q = s[0]
    body = s[1:-1]
    if q == "'":
        return body.replace("\\'", "'").replace('\\\\', '\\')
    # double quotes: handle common escapes
    return (body.replace('\\"', '"').replace("\\'", "'")
                .replace('\\n', '\n').replace('\\t', '\t')
                .replace('\\\\', '\\'))


def escape_pot(s: str) -> str:
    out = []
    for ch in s:
        if ch == '\\':
            out.append('\\\\')
        elif ch == '"':
            out.append('\\"')
        elif ch == '\n':
            out.append('\\n')
        elif ch == '\t':
            out.append('\\t')
        else:
            out.append(ch)
    return ''.join(out)


def main():
    entries = {}  # (msg, context) -> {refs: [(file, line)], comment}
    for dirpath, dirnames, filenames in os.walk(os.path.join(ROOT, 'src')):
        dirnames[:] = [d for d in dirnames if d != 'node_modules']
        for fn in sorted(filenames):
            if not fn.endswith('.php'):
                continue
            path = os.path.join(dirpath, fn)
            rel = os.path.relpath(path, ROOT)
            src = open(path, encoding='utf-8').read()
            for pattern, msg_group, ctx_group in FUNC_PATTERNS:
                for m in pattern.finditer(src):
                    msg = php_unquote(m.group(msg_group))
                    ctx = php_unquote(m.group(ctx_group)) if ctx_group else None
                    if not msg:
                        continue
                    line = src[:m.start()].count('\n') + 1
                    key = (msg, ctx)
                    e = entries.setdefault(key, {'refs': [], 'comment': None})
                    if (rel, line) not in e['refs']:
                        e['refs'].append((rel, line))
                    if e['comment'] is None:
                        preceding = src[max(0, m.start() - 400):m.start()]
                        cm = None
                        for c in TRANSLATOR_COMMENT.finditer(preceding):
                            cm = c
                        if cm and cm.end() > len(preceding) - 200:
                            e['comment'] = ' '.join(cm.group(1).split())

    # also scan main plugin file + uninstall
    # Language names are passed to translate_with_gettext_context() via a
    # variable (Languages::REGEX), so add them explicitly with their context.
    lang_src = os.path.join(ROOT, 'src', 'Languages.php')
    if os.path.isfile(lang_src):
        lang_text = open(lang_src, encoding='utf-8').read()
        for m in re.finditer(r"'name'\s*=>\s*'([A-Za-z]+)'", lang_text):
            name = m.group(1)
            line = lang_text[:m.start()].count('\n') + 1
            key = (name, 'language name')
            e = entries.setdefault(key, {'refs': [], 'comment': None})
            if ('src/Languages.php', line) not in e['refs']:
                e['refs'].append(('src/Languages.php', line))

    header = {
        'Project-Id-Version': 'CF7 Spam Checker Pro ' + plugin_version(),
        'Report-Msgid-Bugs-To': 'https://github.com/paulmann/Contact-Form-7-Spam-Checker/issues',
        'MIME-Version': '1.0',
        'Content-Type': 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding': '8bit',
        'Domain Path': '/languages',
        'X-Generator': 'cf7sec-pot-extractor 1.0',
    }
    out = []
    out.append('# Copyright (C) 2026 Mikhail Deynekin\n# This file is distributed under the GPL-2.0-or-later license.\n')
    out.append('msgid ""\nmsgstr ""\n')
    for k, v in header.items():
        out.append(f'"{k}: {v}\\n"\n')
    out.append('\n')

    for (msg, ctx), e in sorted(entries.items(), key=lambda kv: (kv[0][1] or '', kv[0][0])):
        if e['comment']:
            out.append(f"#. translators: {e['comment']}\n")
        for ref_file, ref_line in e['refs'][:6]:
            out.append(f"#: {ref_file}:{ref_line}\n")
        if ctx:
            out.append(f'msgctxt "{escape_pot(ctx)}"\n')
        out.append(f'msgid "{escape_pot(msg)}"\nmsgstr ""\n\n')

    pot_path = os.path.join(ROOT, 'languages', f'{DOMAIN}.pot')
    os.makedirs(os.path.dirname(pot_path), exist_ok=True)
    with open(pot_path, 'w', encoding='utf-8') as f:
        f.write(''.join(out))

    print(f'Extracted {len(entries)} unique strings -> {pot_path}')


if __name__ == '__main__':
    main()
