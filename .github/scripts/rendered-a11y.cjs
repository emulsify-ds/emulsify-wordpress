#!/usr/bin/env node

// Audit rendered WordPress pages with the generated theme's existing Core
// dependencies. Project styling owns target size; every other tagged rule runs.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');

const wcagTags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const disabledRules = ['target-size'];

function tooling(themeDir) {
  const requireTheme = createRequire(path.join(path.resolve(themeDir), 'package.json'));
  const requireCore = createRequire(requireTheme.resolve('@emulsify/core/package.json'));
  // Puppeteer is Pa11y's dependency, including when npm nests it under Pa11y.
  const requirePa11y = createRequire(requireCore.resolve('pa11y'));
  return {
    puppeteer: requirePa11y('puppeteer'),
    axePath: requireCore.resolve('axe-core/axe.min.js'),
  };
}

function validateRoutes(routes) {
  assert(Array.isArray(routes) && routes.length > 0, 'Provide at least one rendered accessibility route.');
  const names = new Set();
  for (const route of routes) {
    assert(/^[a-z0-9][a-z0-9_-]*$/i.test(route.name), 'Each route needs a filename-safe name.');
    assert(!names.has(route.name), `Duplicate accessibility route name: ${route.name}`);
    names.add(route.name);
    assert(['http:', 'https:'].includes(new URL(route.url).protocol), `${route.name}: expected an HTTP URL.`);
    const texts = Array.isArray(route.expectedText) ? route.expectedText : [route.expectedText];
    assert(texts.length > 0 && texts.every((text) => typeof text === 'string' && text.trim()), `${route.name}: provide expected rendered content.`);
  }
}

async function renderedEvidence(page, selector) {
  return page.evaluate((contentSelector) => {
    const currentItems = (container) => Array.from(container?.querySelectorAll('[aria-current="page"]') || [], (element) => ({
      text: element.textContent.trim(),
      href: element.href || null,
      tag: element.tagName.toLowerCase(),
    }));
    const styles = (element) => {
      const computed = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return {
        text: element.textContent.trim(),
        className: element.className,
        position: computed.position,
        display: computed.display,
        visibility: computed.visibility,
        opacity: computed.opacity,
        width: computed.width,
        height: computed.height,
        overflow: computed.overflow,
        clip: computed.clip,
        clipPath: computed.clipPath,
        whiteSpace: computed.whiteSpace,
        rect: { width: rect.width, height: rect.height },
      };
    };
    const pagination = document.querySelector('nav[aria-label="Pagination"]');
    return {
      selector: contentSelector,
      content: document.querySelector(contentSelector)?.textContent ?? null,
      pagination: {
        exists: Boolean(pagination),
        current: currentItems(pagination),
        dots: Array.from(pagination?.querySelectorAll('.dots') || [], (element) => ({
          text: element.textContent.trim(),
          interactive: element.matches('a, button, input, select, textarea, [href], [role="button"], [role="link"], [tabindex]') || Boolean(element.closest('a, button')),
          ariaCurrent: element.getAttribute('aria-current'),
        })),
      },
      primaryMenuCurrent: currentItems(document.querySelector('nav[aria-label="Primary navigation"]')),
      screenReaderText: Array.from(document.querySelectorAll('.screen-reader-text'), styles),
      skipLinks: Array.from(document.querySelectorAll('a[href="#content"]'), styles),
      stylesheets: Array.from(document.styleSheets, (sheet) => {
        let ruleCount = null;
        try { ruleCount = sheet.cssRules.length; } catch {}
        return { href: sheet.href, disabled: sheet.disabled, media: sheet.media.mediaText, ruleCount };
      }),
    };
  }, selector);
}

function assertEvidence(route, report) {
  const evidence = report.rendered;
  assert.notEqual(evidence.content, null, `${route.name}: missing content selector ${evidence.selector}.`);
  const normalized = evidence.content.replace(/\s+/g, ' ');
  for (const text of Array.isArray(route.expectedText) ? route.expectedText : [route.expectedText]) {
    assert(normalized.includes(text.replace(/\s+/g, ' ')), `${route.name}: missing expected rendered content ${JSON.stringify(text)}.`);
  }
  if (route.paginationPage !== undefined) {
    assert(evidence.pagination.exists, `${route.name}: missing pagination navigation.`);
    assert.equal(evidence.pagination.current.length, 1, `${route.name}: expected exactly one current pagination item.`);
    assert.equal(evidence.pagination.current[0].text, String(route.paginationPage), `${route.name}: pagination must mark page ${route.paginationPage}.`);
    assert(evidence.pagination.dots.length > 0, `${route.name}: long pagination must contain an ellipsis.`);
    for (const dot of evidence.pagination.dots) {
      assert(dot.text === '…' && !dot.interactive && dot.ariaCurrent === null, `${route.name}: pagination ellipsis must be literal, noninteractive text without aria-current.`);
    }
  }
  if (route.currentMenuHref) {
    assert.equal(evidence.primaryMenuCurrent.length, 1, `${route.name}: expected exactly one current primary-menu item.`);
    assert.equal(evidence.primaryMenuCurrent[0].tag, 'a', `${route.name}: current primary-menu item must be a link.`);
    assert.equal(evidence.primaryMenuCurrent[0].href, new URL(route.currentMenuHref, route.url).href, `${route.name}: primary menu must mark the expected item.`);
  }
  assert.equal(report.browserErrors.length, 0, `${route.name}: browser errors: ${report.browserErrors.join('; ')}`);
  assert.equal(report.axe.violations.length, 0, `${route.name}: accessibility violations: ${report.axe.violations.map(({ id }) => id).join(', ')}`);
}

async function auditRenderedPages({ themeDir, routes, outputDir }) {
  validateRoutes(routes);
  assert(outputDir, 'Provide an accessibility artifact output directory.');
  fs.mkdirSync(outputDir, { recursive: true });
  const { puppeteer, axePath } = tooling(themeDir);
  const browser = await puppeteer.launch({ headless: true });
  const reports = [];
  const failures = [];
  try {
    for (const route of routes) {
      const page = await browser.newPage();
      const report = { name: route.name, requestedUrl: route.url, wcagTags, disabledRules, browserErrors: [], axe: null };
      let html = '';
      reports.push(report);
      page.on('pageerror', (error) => report.browserErrors.push(error.message));
      try {
        await page.setViewport({ width: 1280, height: 900 });
        const response = await page.goto(route.url, { waitUntil: 'networkidle0', timeout: 30000 });
        html = await page.content();
        report.status = response?.status() ?? null;
        report.finalUrl = page.url();
        assert(response?.ok(), `${route.name}: navigation returned HTTP ${report.status ?? 'no response'}.`);
        report.rendered = await renderedEvidence(page, route.selector || 'main');
        await page.addScriptTag({ path: axePath });
        report.axe = await page.evaluate((tags) => axe.run(document, {
          runOnly: { type: 'tag', values: tags },
          rules: { 'target-size': { enabled: false } },
        }), wcagTags);
        for (const violation of report.axe.violations) {
          console.error(`${route.name}: ${violation.id} (${violation.impact}) ${violation.helpUrl}`);
          for (const node of violation.nodes) console.error(`  ${node.target.join(' ')}: ${node.failureSummary}`);
        }
        assertEvidence(route, report);
        console.log(`${route.name}: ${report.axe.passes.length} rules passed, 0 violations, ${report.axe.incomplete.length} manual review items.`);
      } catch (cause) {
        report.error = cause.message;
        failures.push(new Error(`${route.name}: ${cause.message}`, { cause }));
      } finally {
        if (!html) {
          try { html = await page.content(); } catch (error) { report.snapshotError = error.message; }
        }
        fs.writeFileSync(path.join(outputDir, `${route.name}.html`), html);
        fs.writeFileSync(path.join(outputDir, `${route.name}.axe.json`), `${JSON.stringify(report, null, 2)}\n`);
        await page.close();
      }
    }
  } finally {
    await browser.close();
  }
  if (failures.length) throw new AggregateError(failures, `Rendered accessibility checks failed for ${failures.length} route(s). See ${outputDir}.`);
  return reports;
}

if (require.main === module) {
  const [themeDir, routesFile, outputDir] = process.argv.slice(2);
  if (!themeDir || !routesFile || !outputDir) {
    console.error('Usage: rendered-a11y.cjs <generated-theme-dir> <routes.json> <output-dir>');
    process.exitCode = 1;
  } else {
    auditRenderedPages({ themeDir, routes: JSON.parse(fs.readFileSync(routesFile, 'utf8')), outputDir }).catch((error) => {
      console.error(error);
      process.exitCode = 1;
    });
  }
}

module.exports = { auditRenderedPages, wcagTags, disabledRules };
