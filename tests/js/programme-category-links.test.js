const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/programme-category-links.js', 'utf8');

function destination(href) {
    let target = null;
    vm.runInNewContext(source, { URL, window: { location: { href, replace: value => { target = value; } } } });
    return target;
}

test('the reported graduate-school bookmark opens the masters filter and retains search', () => {
    const url = new URL(destination('https://piie.ac.ug/academic-programmes?q=Business&page=3#graduate_school'));
    assert.equal(url.searchParams.get('category'), 'postgraduate');
    assert.equal(url.searchParams.get('q'), 'Business');
    assert.equal(url.searchParams.has('page'), false);
    assert.equal(url.hash, '#catalogue');
});

test('explicit programme filters and other fragments are preserved', () => {
    for (const suffix of ['?category=diploma&level=PGD#graduate_school', '?level=Masters#graduate_school', '#catalogue', '']) {
        assert.equal(destination('https://piie.ac.ug/academic-programmes' + suffix), null);
    }
});
