const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { tooling } = require('./rendered-a11y.cjs');

// Use local responses when activating players: verify browser behavior without
// relying on provider availability, tracking, autoplay policies, or live videos.
async function verifyMediaEmbeds({ themeDir, url, outputDir }) {
  const { puppeteer } = tooling(themeDir);
  const browser = await puppeteer.launch({ headless: true });
  const evidence = [];
  try {
    for (const poster of ['neutral', 'provider']) {
      for (const index of [0, 1]) {
        const page = await browser.newPage();
        const requests = [];
        const thumbnails = [];
        let activated = false;
        try {
          await page.setJavaScriptEnabled(false);
          await page.setRequestInterception(true);
          page.on('request', (request) => {
            const target = new URL(request.url());
            if (['www.youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com'].includes(target.hostname)) {
              requests.push({ url: target.href, activated, referer: request.headers().referer });
              return request.respond({ status: 200, contentType: 'text/html', body: '<!doctype html><html lang="en"><title>Fixture player</title><body>Fixture player</body></html>' });
            }
            if (['i.ytimg.com', 'i.vimeocdn.com'].includes(target.hostname)) {
              thumbnails.push({ url: target.href, referer: request.headers().referer });
              return request.respond({ status: 200, contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9WQAAAAASUVORK5CYII=', 'base64') });
            }
            return request.continue();
          });
          const route = new URL(url);
          route.searchParams.set('media_poster', poster);
          const response = await page.goto(route.href, { waitUntil: 'networkidle0' });
          assert(response.ok());
          const frames = await page.$$('iframe[srcdoc]');
          assert.equal(frames.length, 2, 'Classic and block embeds must both use poster documents.');
          const frame = await frames[index].contentFrame();
          const document = await frame.evaluate(() => ({
            language: document.documentElement.lang,
            title: document.title,
            scripts: document.scripts.length,
            link: document.querySelector('a').href,
            referrer: document.querySelector('a').referrerPolicy,
            height: innerHeight,
            images: [...document.images].map((image) => ({ referrer: image.referrerPolicy, alt: image.alt })),
            hiddenTitle: document.querySelector('.emulsify-oembed-visually-hidden')?.textContent,
          }));
          assert.equal(document.scripts, 0);
          assert(document.language && document.title && document.hiddenTitle);
          assert.equal(document.referrer, 'strict-origin-when-cross-origin');
          assert.equal(requests.length, 0, 'No player request is allowed before activation.');
          if (poster === 'neutral') assert.equal(thumbnails.length, 0, 'Neutral mode must not request provider thumbnails.');
          if (poster === 'provider' && index === 0) assert(thumbnails.length > 0, 'Provider mode must render the YouTube thumbnail.');
          for (const thumbnail of thumbnails) assert(!thumbnail.referer, 'Thumbnail requests must not disclose a referrer.');
          for (const image of document.images) assert(image.alt === '' && image.referrer === 'no-referrer');
          let focused = false;
          for (let count = 0; count < 30 && !focused; count++) {
            await page.keyboard.press('Tab');
            focused = await frame.evaluate(() => document.activeElement?.matches('a.emulsify-oembed-poster'));
          }
          assert(focused, 'Tab must reach the native play link inside the frame.');
          const styles = await frame.evaluate(() => {
            const link = document.querySelector('a');
            const icon = document.querySelector('.emulsify-oembed-poster__play').getBoundingClientRect();
            const label = document.querySelector('.emulsify-oembed-poster__label').getBoundingClientRect();
            const ring = getComputedStyle(link, '::after');
            return { focused: link.matches(':focus-visible'), outline: ring.outlineStyle, content: ring.content, icon: { width: icon.width, top: icon.top, bottom: icon.bottom }, label: { top: label.top, bottom: label.bottom }, height: innerHeight };
          });
          assert(styles.focused && styles.outline !== 'none' && styles.content !== 'none', 'Keyboard focus must draw the ring above the thumbnail.');
          assert(styles.icon.top >= 0 && styles.label.bottom <= styles.height, 'Controls must fit inside the frame.');
          if (styles.height <= 160) assert(styles.icon.width <= 50, 'Short frames need a 3rem control plus its borders.');
          await page.screenshot({ path: path.join(outputDir, `media-${poster}-${index}-focus.png`) });
          fs.writeFileSync(path.join(outputDir, `media-${poster}-${index}.html`), await frame.content());
          activated = true;
          await Promise.all([frame.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.keyboard.press('Enter')]);
          const player = new URL(frame.url());
          assert.equal(player.href, document.link, 'The play link must navigate the same iframe.');
          assert.equal(player.searchParams.get('autoplay'), '1');
          assert.equal(player.hostname, index === 0 ? 'www.youtube-nocookie.com' : 'player.vimeo.com');
          if (index === 1) {
            assert.equal(player.searchParams.get('h'), 'fixturehash');
            assert.equal(player.searchParams.get('dnt'), '1');
          }
          assert.equal(requests.length, 1);
          assert(requests[0].activated && requests[0].referer === new URL(url).origin + '/', 'Activation must send only the containing origin as referrer.');
          const client = await page.createCDPSession();
          const history = await client.send('Page.getNavigationHistory');
          assert(history.currentIndex > 0);
          await client.send('Page.navigateToHistoryEntry', { entryId: history.entries[history.currentIndex - 1].id });
          await frame.waitForSelector('a.emulsify-oembed-poster');
          assert.equal(frame.url(), 'about:srcdoc', 'Back must restore the poster document.');
          evidence.push({ poster, index, document, styles, requests, thumbnails, back: frame.url() });
        } finally {
          await page.close();
        }
      }
    }
  } finally {
    fs.writeFileSync(path.join(outputDir, 'media-browser.json'), JSON.stringify(evidence, null, 2) + '\n');
    await browser.close();
  }
  console.log('Media posters passed JavaScript-free keyboard activation, privacy, network/referrer, compact focus, and Back-history checks.');
}

module.exports = { verifyMediaEmbeds };
