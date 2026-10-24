const assert = require('node:assert/strict');
const test = require('node:test');
const { validateThemeJsonStyles } = require('./theme-json-style-contract.cjs');

function fixture() {
  return {
    settings: {
      color: { palette: [{ slug: 'brand', color: '#123456' }] },
      typography: {
        fontSizes: [{ slug: 'large', size: '2rem' }],
        fontFamilies: [{ slug: 'primary', fontFamily: 'sans-serif' }],
      },
      spacing: { spacingSizes: [{ slug: '4', size: '1rem' }] },
    },
    styles: {
      color: { text: 'var(--wp--preset--color--brand)' },
      typography: {
        fontSize: 'var(--wp--preset--font-size--large)',
        fontFamily: 'var(--wp--preset--font-family--primary)',
      },
      spacing: { padding: { top: 'var(--wp--preset--spacing--4)' } },
    },
  };
}

test('accepts defined color, font size, font family and spacing presets', () => {
  assert.deepEqual(validateThemeJsonStyles(fixture()), []);
});

test('rejects undefined references for every required preset type', () => {
  for (const type of ['color', 'font-size', 'font-family', 'spacing']) {
    const theme = fixture();
    theme.styles.elements = { link: { ':hover': { color: { text: `var(--wp--preset--${type}--missing)` } } } };
    assert.deepEqual(validateThemeJsonStyles(theme), [
      `styles.elements.link.:hover.color.text references undefined preset --wp--preset--${type}--missing.`,
    ]);
  }
});

test('checks every reference in composite CSS values, including fallback values', () => {
  const theme = fixture();
  theme.styles.spacing.margin = 'calc(var( --wp--preset--spacing--4) + var(--wp--preset--spacing--missing, var(--wp--preset--spacing--absent)))';
  assert.equal(validateThemeJsonStyles(theme).length, 2);
});

test('rejects malformed WordPress custom-property prefixes', () => {
  const theme = fixture();
  theme.styles.color.text = 'var(---wp--preset--color--brand)';
  assert.match(validateThemeJsonStyles(theme)[0], /malformed ---wp/);
});

test('rejects objects and arrays in scalar style properties at every nesting level', () => {
  for (const invalid of [{ text: '#fff' }, []]) {
    const theme = fixture();
    theme.styles.blocks = {
      'core/button': { border: { color: invalid } },
      'core/quote': {
        elements: { cite: { typography: { fontSize: invalid } } },
        border: { bottom: { width: invalid } },
        spacing: { margin: { top: invalid } },
      },
    };
    const errors = validateThemeJsonStyles(theme);
    assert.equal(errors.length, 4);
    assert.ok(errors.every((error) => error.includes('must be a single style value')));
  }
});

test('accepts legitimate nested border, spacing, background and element containers', () => {
  const theme = fixture();
  theme.styles.blocks = {
    'core/button': {
      border: { color: '#fff', top: { color: '#000', width: '1px', style: 'solid' }, radius: { topLeft: '2px', bottomRight: '3px' } },
      background: { backgroundImage: { url: 'https://example.org/image.png', source: 'file' } },
      spacing: { margin: { bottom: '0' }, padding: '1rem', blockGap: { top: '1rem', left: '2rem' } },
      elements: { link: { ':focus': { outline: { color: '#000', width: '2px' } } } },
      variations: { shadow: { shadow: '0 1px 2px #000', color: { text: '#000' } } },
    },
  };
  assert.deepEqual(validateThemeJsonStyles(theme), []);
});

test('accepts block-specific presets only in their block scope', () => {
  const theme = fixture();
  theme.settings.blocks = { 'core/button': { color: { palette: [{ slug: 'button', color: '#000' }] } } };
  theme.styles.blocks = { 'core/button': { color: { text: 'var(--wp--preset--color--button)' } } };
  assert.deepEqual(validateThemeJsonStyles(theme), []);
  theme.styles.color.text = 'var(--wp--preset--color--button)';
  assert.equal(validateThemeJsonStyles(theme).length, 1);
});

test('leaves settings metadata and custom variables outside the style-value contract', () => {
  const theme = fixture();
  theme.settings.custom = { example: 'var(--wp--preset--color--missing)' };
  theme.styles.color.background = 'var(--project-background, #fff)';
  assert.deepEqual(validateThemeJsonStyles(theme), []);
});
