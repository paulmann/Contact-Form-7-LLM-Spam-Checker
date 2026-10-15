<?php
/**
 * Static guard: every literal settings path read in src/ must exist in
 * Settings::DEFAULTS. Catches the whole class of "code reads a settings
 * path that no longer exists" defects (found B1: four `deepseek.*` reads
 * surviving the v4.1 schema rename).
 *
 * Run:  php tests/settings-paths.php   (exit 0 = OK)
 */

declare(strict_types=1);

require __DIR__ . '/wp-stubs.php';

$root  = dirname(__DIR__);
$defs  = (new ReflectionClass(\CF7Sec\Settings::class))->getConstant('DEFAULTS');

/** Flattens the settings tree into paths like "checks.rate_limit.enabled". */
function cf7sec_flatten(array $node, string $prefix = ''): array
{
    $out = [];
    foreach ($node as $key => $value) {
        $path  = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        $out[] = $path;
        if (is_array($value) && !array_is_list($value)) {
            $out = array_merge($out, cf7sec_flatten($value, $path));
        }
    }

    return $out;
}

$defined = array_flip(cf7sec_flatten($defs));
$unknown = [];
$checked = 0;

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $src = (string) file_get_contents($file->getPathname());
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());

    $patterns = [
        '/->get\(\s*\'([A-Za-z0-9_.]+)\'\s*[,)]/'    => false,
        '/->isEnabled\(\s*\'([A-Za-z0-9_]+)\'\s*\)/' => true,
    ];

    foreach ($patterns as $regex => $isCheckToggle) {
        if (!preg_match_all($regex, $src, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($matches[1] as [$path, $offset]) {
            $line = substr_count(substr($src, 0, $offset), "\n") + 1;
            if ($isCheckToggle) {
                $path = 'checks.' . $path . '.enabled';
            }
            $checked++;
            if (!isset($defined[$path])) {
                $unknown[] = sprintf('%s:%d -> %s', $rel, $line, $path);
            }
        }
    }
}

printf("settings paths checked: %d\n", $checked);
if ($unknown !== []) {
    fwrite(STDERR, "Unknown settings paths:\n  " . implode("\n  ", $unknown) . "\n");
    exit(1);
}
echo "OK: every literal settings path exists in Settings::DEFAULTS\n";
