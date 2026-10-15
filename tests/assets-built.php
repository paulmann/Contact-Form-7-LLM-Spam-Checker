<?php
/**
 * Guard: compiled admin assets exist and every design-system class used in
 * the server-rendered markup has a rule in the compiled CSS (found M1:
 * c-country-filter / c-save-btn / cf7sec-panel without rules).
 *
 * Run:  php tests/assets-built.php   (exit 0 = OK)
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$fail = [];

foreach (['assets/dist/admin.css', 'assets/dist/admin.js'] as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path) || filesize($path) < 1024) {
        $fail[] = "missing or too small: {$rel} (run: npm install tailwindcss@3 --no-save && npm run build)";
    }
}

$css = is_file($root . '/assets/dist/admin.css')
    ? (string) file_get_contents($root . '/assets/dist/admin.css')
    : '';

// Design-system classes used in markup must have a rule in the compiled CSS.
$used = [];
$classAttr = '/class=(["\'])(.*?)\1/s';
$validName = '/^(c-|cf7sec-)[a-z0-9]+(-[a-z0-9]+)*$/i';

foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)
) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $src = (string) file_get_contents($file->getPathname());
    if (!preg_match_all($classAttr, $src, $m)) {
        continue;
    }
    foreach ($m[2] as $list) {
        // Drop PHP string escapes, then split on whitespace. Fragments that
        // end in a concatenation point (`c-badge-` + PHP) are not literals
        // and are filtered out by the name pattern below.
        $clean = str_replace("\\'", '', $list);
        $clean = str_replace('\\"', '', $clean);
        $tokens = preg_split('/\s+/', trim($clean)) ?: [];
        foreach ($tokens as $class) {
            if (preg_match($validName, $class)) {
                $used[$class] = true;
            }
        }
    }
}
foreach (array_keys($used) as $class) {
    if (!str_contains($css, '.' . $class)) {
        $fail[] = "class used in markup but absent from admin.css: {$class}";
    }
}

if ($fail !== []) {
    fwrite(STDERR, implode("\n", $fail) . "\n");
    exit(1);
}
echo 'OK: assets present and every design-system class is compiled (' . count($used) . " classes checked)\n";
