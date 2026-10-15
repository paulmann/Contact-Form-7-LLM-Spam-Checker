<?php
/**
 * Guard: relative documentation links (docs/**, preview/**) resolve.
 *
 * Run:  php tests/docs-links.php   (exit 0 = OK)
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$bad  = [];
foreach (['README.md', 'README.ru.md', 'docs/ARCHITECTURE.md', 'readme.txt'] as $doc) {
    $text = (string) @file_get_contents($root . '/' . $doc);
    preg_match_all('/(?:docs\/[\w\/.\-]+\.(?:png|md|html))|(?:preview\/[\w\/.\-]+\.html)/', $text, $m);
    foreach (array_unique($m[0]) as $rel) {
        if (!file_exists($root . '/' . $rel)) {
            $bad[] = "{$doc} -> {$rel}";
        }
    }
}
if ($bad !== []) {
    fwrite(STDERR, "Broken doc links:\n  " . implode("\n  ", $bad) . "\n");
    exit(1);
}
echo "OK: no broken documentation links\n";
