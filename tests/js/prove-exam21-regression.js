/**
 * PROVES THE EXAM 21 REGRESSION TESTS ARE REAL.
 *
 * Takes the current `public/js/academic-editor.js`, reinstates the pre-fix
 * `syncAll()` — the one that read the vendor getter and wrote it back into the
 * VENDOR INSTANCE instead of into `area.value`, and that skipped any field not
 * flagged `data-piie-editor-ready` — then runs the suite against it.
 *
 * A regression test that passes against the broken code is not a regression test.
 * This exits non-zero unless the suite FAILS, which is the correct outcome here.
 *
 * Run: node tests/js/prove-exam21-regression.js
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const real = path.join(__dirname, '..', '..', 'public', 'js', 'academic-editor.js');
const suite = path.join(__dirname, 'academic-editor-bridge.test.js');

const EOL = fs.readFileSync(real, 'utf8').includes('\r\n') ? '\r\n' : '\n';
const lines = fs.readFileSync(real, 'utf8').split(/\r?\n/);

const syncAt = lines.findIndex((l) => l.includes('syncAll: function (root)'));
if (syncAt === -1) { throw new Error('syncAll not found in the real module'); }

let start = syncAt;
while (start > 0 && !lines[start - 1].trim().startsWith('/**')) { start--; }
start -= 1;

let end = syncAt;
while (end < lines.length && lines[end].trim() !== '},') { end++; }

// The pre-fix implementation, verbatim in behaviour: it reads the getter and writes
// it back into the vendor instance, so `area.value` is never assigned.
const PRE_FIX = [
    '        syncAll: function (root) {',
    '            var scope = root || document;',
    '',
    '            Array.prototype.forEach.call(',
    '                scope.querySelectorAll(\'textarea[data-piie-editor][data-piie-editor-ready="1"]\'),',
    '                function (area) {',
    '                    try { $(area).summernote(\'code\', $(area).summernote(\'code\')); } catch (e) { /* no-op */ }',
    '                }',
    '            );',
    '        },'
].join(EOL);

const broken = lines.slice(0, start).concat(PRE_FIX.split(EOL), lines.slice(end + 1)).join(EOL);

const tmp = path.join(os.tmpdir(), 'piie-academic-editor-prefix.js');
fs.writeFileSync(tmp, broken, 'utf8');

console.log('Reverted syncAll() to the pre-fix implementation.');
console.log('Running the suite against it — a FAILURE here is the correct result.\n');

let failed = false;
let output = '';

try {
    output = execFileSync(process.execPath, [suite], {
        encoding: 'utf8',
        env: Object.assign({}, process.env, { PIIE_EDITOR_JS: tmp }),
        stdio: ['ignore', 'pipe', 'pipe']
    });
} catch (e) {
    failed = true;
    output = String(e.stdout || '') + String(e.stderr || '');
}

const failing = output.split(/\r?\n/).filter((l) => l.indexOf('[FAIL]') !== -1);
const summary = (output.match(/passed: \d+\s+failed: \d+/) || ['(no summary)'])[0];

console.log('Failures against the pre-fix code:');
failing.forEach((l) => console.log('  ' + l.trim()));
console.log('\n' + summary);

if (!failed && failing.length === 0) {
    console.log('\nPROBLEM: the suite PASSED against the broken code.');
    console.log('These assertions do not actually detect the defect they claim to.');
    process.exit(1);
}

console.log('\nConfirmed: the suite fails against the pre-fix implementation.');
process.exit(0);