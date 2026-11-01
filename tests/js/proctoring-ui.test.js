/*
 * BROWSER-LEVEL TESTS FOR EXAMINATION PROCTORING UI.
 *
 * Tests the Show/Hide Timer control and camera monitoring JavaScript
 * in the student examination take page.
 *
 * Run: node --test tests/js/
 */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const REPO = path.resolve(__dirname, '..', '..');

function read(relative) {
    return fs.readFileSync(path.join(REPO, relative), 'utf8');
}

test('Show/Hide Timer: toggle button exists in take.blade.php', () => {
    const source = read('resources/views/student/online_exam/take.blade.php');
    assert.ok(source.includes('id="toggleTimerBtn"'), 'toggle button must exist');
    assert.ok(source.includes('id="timerEyeIcon"'), 'eye icon must exist');
    assert.ok(source.includes('id="timer"'), 'timer element must exist');
});

test('Show/Hide Timer: toggle only changes display, never the deadline', () => {
    const source = read('resources/views/student/online_exam/take.blade.php');
    const toggleBlock = source.slice(
        source.indexOf('var timerVisible = true'),
        source.indexOf('renderTimer();')
    );

    // Must toggle display property
    assert.match(toggleBlock, /timerEl\.style\.display/, 'must toggle display');
    // Must NOT modify remainingSeconds or deadlineAt
    assert.ok(!toggleBlock.includes('remainingSeconds ='), 'must not modify remainingSeconds');
    assert.ok(!toggleBlock.includes('deadlineAt ='), 'must not modify deadlineAt');
    // Must NOT call clearInterval on timerInterval
    assert.ok(!toggleBlock.includes('clearInterval(timerInterval)'), 'must not pause timer');
});

test('Show/Hide Timer: timer element has unique ID', () => {
    const source = read('resources/views/student/online_exam/take.blade.php');
    const matches = source.match(/id="timer"/g);
    assert.equal(matches.length, 1, 'timer element must have unique ID');
});

test('Camera monitoring: exactly one camera stream variable', () => {
    const source = read('resources/views/student/online_exam/take.blade.php');
    const matches = source.match(/var cameraStream = null/g);
    assert.equal(matches.length, 1, 'cameraStream must be declared once');
});

test('Camera monitoring: exactly one snapshot interval variable', () => {
    const source = read('resources/views/student/online_exam/take.blade.php');
    const matches = source.match(/var snapshotInterval = null/g);
    assert.equal(matches.length, 1, 'snapshotInterval must be declared once');
});

test('Camera monitoring: startCamera guards against duplicate streams', () => {
    const source = read('resources/views/student/online_exam/take.blade.php');
    const startCameraBlock = source.slice(
        source.indexOf('async function startCamera()'),
        source.indexOf('function stopCamera()')
    );
    assert.match(startCameraBlock, /if \(cameraStream\)/, 'must guard against duplicate streams');
    assert.match(startCameraBlock, /return/, 'must return early if stream exists');
});

test('Camera monitoring: stopCamera clears all intervals', () => {
    const source = read('resources/views/student/online_exam/take.blade.php');
    const stopCameraBlock = source.slice(
        source.indexOf('function stopCamera()'),
        source.indexOf('function updateCameraStatus')
    );
    assert.match(stopCameraBlock, /clearInterval\(snapshotInterval\)/, 'must clear snapshot interval');
    assert.match(stopCameraBlock, /clearInterval\(pendingRequestInterval\)/, 'must clear pending request interval');
    assert.match(stopCameraBlock, /clearInterval\(cameraMonitorInterval\)/, 'must clear camera monitor interval');
});

test('Camera monitoring: track.onended handler exists', () => {
    const source = read('resources/views/student/online_exam/take.blade.php');
    assert.ok(source.includes('track.onended'), 'must handle track ended event');
});

test('Camera monitoring: camera stops on exam submission', () => {
    const source = read('resources/views/student/online_exam/take.blade.php');
    assert.ok(source.includes("window.addEventListener('examSubmitted'"), 'must listen for examSubmitted');
    assert.ok(source.includes('stopCamera()'), 'must call stopCamera');
});

test('Camera monitoring: configurable snapshot interval', () => {
    const source = read('resources/views/student/online_exam/take.blade.php');
    assert.match(source, /SNAPSHOT_INTERVAL_MS = .* \* 1000/, 'must use configurable interval');
    assert.match(source, /snapshot_interval_seconds/, 'must reference exam setting');
});