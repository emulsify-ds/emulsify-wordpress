#!/usr/bin/env node

// Browser regressions use the same installed consumer tooling and Chrome as
// the WordPress audit. Every fixture below is unstyled HTML served locally.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');
const { auditRenderedPages, wcagTags, disabledRules } = require('./rendered-a11y.cjs');

test('rendered accessibility gate rejects HTTP, content, axe, pagination, and menu regressions', { timeout: 60000 }, async () => {
  const themeDir = process.env.EMULSIFY_A11Y_THEME || path.resolve(__dirname, '../../whisk');
  const outputDir = fs.mkdtempSync(path.join(os.tmpdir(), 'emulsify-rendered-a11y-test-'));
  const clean = `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Accessibility fixture</title></head>
<body><a class="skip-link screen-reader-text" href="#content">Skip to content</a>
<header><nav aria-label="Primary navigation"><ul><li><a href="/clean" aria-current="page">Current page</a></li><li><a href="/other">Other page</a></li></ul></nav></header>
<main id="content" tabindex="-1"><h1>Fixture ready</h1><p>Expected content</p>
<nav aria-label="Pagination"><ul><li><a href="?paged=1">1</a></li><li><span aria-current="page">2</span></li><li><span class="dots">…</span></li></ul></nav>
</main></body></html>`;
  const pages = {
    '/clean': clean,
    '/http-error': clean,
    '/missing-content': clean.replace('Expected content', 'Different content'),
    '/axe-violation': clean.replace('</main>', '<img src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2240%22 height=%2240%22/%3E"></main>'),
    '/bad-pagination': clean.replace('<a href="?paged=1">', '<a href="?paged=1" aria-current="page">'),
    '/bad-menu': clean.replace('<ul><li><a href="/clean"', '<ul><li aria-current="page"><a href="/clean"'),
  };
  const server = http.createServer((request, response) => {
    response.writeHead(request.url === '/http-error' ? 500 : 200, { 'Content-Type': 'text/html; charset=utf-8' });
    response.end(pages[request.url] || clean);
  });
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });
  const baseUrl = `http://127.0.0.1:${server.address().port}`;
  try {
    const routes = Object.keys(pages).map((url) => ({
      name: url.slice(1),
      url: baseUrl + url,
      expectedText: ['Fixture ready', 'Expected content'],
      paginationPage: 2,
      currentMenuHref: `${baseUrl}/clean`,
    }));
    await assert.rejects(auditRenderedPages({ themeDir, routes, outputDir }), (error) => {
      assert(error instanceof AggregateError);
      assert.equal(error.errors.length, 5, 'Every failing route must be reported, including routes after the first failure.');
      return true;
    });
    const reports = Object.fromEntries(routes.map(({ name }) => {
      assert(fs.readFileSync(path.join(outputDir, `${name}.html`), 'utf8').includes('Fixture ready'));
      return [name, JSON.parse(fs.readFileSync(path.join(outputDir, `${name}.axe.json`), 'utf8'))];
    }));
    assert.equal(reports.clean.error, undefined);
    assert.equal(reports.clean.axe.violations.length, 0);
    assert.deepEqual(reports.clean.wcagTags, wcagTags);
    assert.deepEqual(disabledRules, ['target-size']);
    assert.deepEqual(reports.clean.disabledRules, ['target-size']);
    assert.equal(reports.clean.rendered.screenReaderText[0].position, 'static', 'Evidence must reflect actual computed styles without injecting screen-reader CSS.');
    assert.match(reports['http-error'].error, /HTTP 500/);
    assert.match(reports['missing-content'].error, /missing expected rendered content/);
    assert(reports['axe-violation'].axe.violations.some(({ id }) => id === 'image-alt'));
    assert.match(reports['axe-violation'].error, /accessibility violations: image-alt/);
    assert.match(reports['bad-pagination'].error, /exactly one current pagination item/);
    assert.match(reports['bad-menu'].error, /exactly one current primary-menu item/);
  } finally {
    await new Promise((resolve) => server.close(resolve));
    fs.rmSync(outputDir, { recursive: true, force: true });
  }
});
