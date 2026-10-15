#!/usr/bin/env python3
"""Build .po/.mo translation files from the POT + per-language dictionaries.

Pure-Python MO compiler (no gettext tools required).

Usage:  python3 tools/make-i18n.py
"""
import array
import importlib.util
import os
import re
import struct
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOMAIN = 'contact-form-7-spam-checker'
LANG_DIR = os.path.join(ROOT, 'languages')

LANGS = {
    'ru_RU': ('i18n-ru.py', 'ru', 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);'),
    'zh_CN': ('i18n-zh.py', 'zh', 'nplurals=1; plural=0;'),
    'de_DE': ('i18n-de.py', 'de', 'nplurals=2; plural=(n != 1);'),
}

def plugin_version():
    src = open(os.path.join(ROOT, DOMAIN + '.php'), encoding='utf-8').read()
    m = re.search(r"define\('CF7SEC_VERSION', '([^']+)'\)", src)
    return m.group(1) if m else '0.0.0'


TEAM = {
    'ru': 'Russian Team',
    'zh': 'Chinese (Simplified) Team',
    'de': 'German Team',
}


def load_dict(path):
    spec = importlib.util.spec_from_file_location('mod', path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod.TRANSLATIONS, getattr(mod, 'LANGUAGE_NAMES', {})


def parse_pot(path):
    """Yield (msgctxt, msgid, refs, translator_comment) tuples."""
    text = open(path, encoding='utf-8').read()
    entries = []
    for block in text.split('\n\n'):
        block = block.strip()
        if not block or 'msgid' not in block:
            continue
        m_id = re.search(r'msgid "((?:[^"\\]|\\.)*)"', block)
        if not m_id:
            continue
        msgid = unescape(m_id.group(1))
        if msgid == '':
            continue  # header
        m_ctx = re.search(r'msgctxt "((?:[^"\\]|\\.)*)"', block)
        ctx = unescape(m_ctx.group(1)) if m_ctx else None
        refs = re.findall(r'^#: (.+)$', block, re.M)
        comment = re.search(r'^#\.\s*translators:\s*(.+)$', block, re.M)
        entries.append((ctx, msgid, refs, comment.group(1) if comment else None))
    return entries


def unescape(s):
    return (s.replace('\\n', '\n').replace('\\t', '\t')
             .replace('\\"', '"').replace('\\\\', '\\'))


def escape(s):
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


def compile_mo(entries, path):
    """entries: list of (msg_bytes, msgstr_bytes) sorted by msg_bytes."""
    entries = sorted(entries, key=lambda kv: kv[0])
    keystart = 7 * 4 + 16 * len(entries)
    valuestart = keystart + sum(len(k) + 1 for k, _ in entries)
    koffsets, voffsets = [], []
    o = keystart
    for k, _ in entries:
        koffsets.append((len(k), o))
        o += len(k) + 1
    o = valuestart
    for _, v in entries:
        voffsets.append((len(v), o))
        o += len(v) + 1

    output = array.array('i')
    output.append(struct.unpack('<i', b'\xde\x12\x04\x95')[0])  # magic
    output.append(0)                                            # revision
    output.append(len(entries))
    output.append(7 * 4)      # offset of table with keys
    output.append(7 * 4 + len(entries) * 8)  # offset of table with values
    output.append(0)          # hash size (unused)
    output.append(keystart)   # hash addr — must equal the string area start
    for l, off in koffsets:
        output.append(l)
        output.append(off)
    for l, off in voffsets:
        output.append(l)
        output.append(off)
    data = output.tobytes()
    for k, v in entries:
        data += k + b'\0'
    for k, v in entries:
        data += v + b'\0'
    with open(path, 'wb') as f:
        f.write(data)


def main():
    pot_path = os.path.join(LANG_DIR, f'{DOMAIN}.pot')
    pot = parse_pot(pot_path)
    print(f'POT entries: {len(pot)}')

    exit_code = 0
    for locale, (dictfile, short, plurals) in LANGS.items():
        trans, lang_names = load_dict(os.path.join(ROOT, 'tools', dictfile))
        po_lines = []
        po_lines.append(f'''# Translation of CF7 Spam Checker Pro in {locale.replace("_", " ")}
# This file is distributed under the same license as the CF7 Spam Checker Pro package.
msgid ""
msgstr ""
"Project-Id-Version: CF7 Spam Checker Pro {plugin_version()}\\n"
"Report-Msgid-Bugs-To: https://github.com/paulmann/Contact-Form-7-LLM-Spam-Checker/issues\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Language: {short}\\n"
"Plural-Forms: {plurals}\\n"
"X-Generator: cf7sec-i18n-builder 1.0\\n"
''')
        mo_entries = [(b'', b'')]
        missing = []
        for ctx, msgid, refs, trcomment in pot:
            if ctx == 'language name':
                msgstr = lang_names.get(msgid, msgid)
            else:
                msgstr = trans.get(msgid)
            if msgstr is None:
                missing.append(msgid)
                msgstr = ''
            for ref in refs[:4]:
                po_lines.append(f'#: {ref}')
            if trcomment:
                po_lines.append(f'#. translators: {trcomment}')
            if ctx:
                po_lines.append(f'msgctxt "{escape(ctx)}"')
            po_lines.append(f'msgid "{escape(msgid)}"')
            po_lines.append(f'msgstr "{escape(msgstr)}"')
            po_lines.append('')

            key = (f'{ctx}\x04{msgid}' if ctx else msgid).encode('utf-8')
            mo_entries.append((key, msgstr.encode('utf-8')))

        po_path = os.path.join(LANG_DIR, f'{DOMAIN}-{locale}.po')
        mo_path = os.path.join(LANG_DIR, f'{DOMAIN}-{locale}.mo')
        with open(po_path, 'w', encoding='utf-8') as f:
            f.write('\n'.join(po_lines))

        # The MO header entry (empty msgid) must carry the metadata block.
        header_msgstr = '\n'.join([
            f'Project-Id-Version: CF7 Spam Checker Pro {plugin_version()}',
            'Report-Msgid-Bugs-To: https://github.com/paulmann/Contact-Form-7-LLM-Spam-Checker/issues',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            f'Language: {short}',
            f'Plural-Forms: {plurals}',
            'X-Generator: cf7sec-i18n-builder 1.0',
            '',
        ])
        mo_entries[0] = (b'', header_msgstr.encode('utf-8'))
        compile_mo(mo_entries, mo_path)

        status = 'COMPLETE' if not missing else f'MISSING {len(missing)}'
        print(f'{locale}: {len(pot) - len(missing)}/{len(pot)} translated [{status}]')
        if missing:
            exit_code = 1
            for m in missing[:15]:
                print('   missing:', repr(m[:90]))

    # sanity: re-read MO files
    for locale in LANGS:
        mo_path = os.path.join(LANG_DIR, f'{DOMAIN}-{locale}.mo')
        data = open(mo_path, 'rb').read()
        magic = struct.unpack('<I', data[:4])[0]
        assert magic == 0x950412DE, f'bad magic in {mo_path}'
        print(f'{mo_path}: {len(data)} bytes, magic OK')

    sys.exit(exit_code)


if __name__ == '__main__':
    main()
