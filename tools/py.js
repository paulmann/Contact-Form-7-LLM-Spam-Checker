#!/usr/bin/env node
/**
 * Cross-platform Python runner: spawns `python3` (POSIX) or `python`
 * (Windows), whichever exists first. Used by the npm i18n scripts (L1).
 *
 * Usage: node tools/py.js <script.py> [args...]
 */
'use strict';

const { spawnSync } = require('child_process');

const [script, ...args] = process.argv.slice(2);
if (!script) {
    console.error('usage: node tools/py.js <script.py> [args...]');
    process.exit(2);
}

for (const bin of ['python3', 'python']) {
    const result = spawnSync(bin, [script, ...args], { stdio: 'inherit' });
    if (!result.error) {
        process.exit(result.status === null ? 1 : result.status);
    }
    if (result.error.code !== 'ENOENT') {
        console.error(result.error.message);
        process.exit(1);
    }
}

console.error('Neither python3 nor python found in PATH.');
process.exit(1);
