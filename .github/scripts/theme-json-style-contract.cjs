const fs = require('node:fs');
const path = require('node:path');

const PRESET_PATHS = {
  color: ['color', 'palette'],
  'font-size': ['typography', 'fontSizes'],
  'font-family': ['typography', 'fontFamilies'],
  spacing: ['spacing', 'spacingSizes'],
  gradient: ['color', 'gradients'],
  shadow: ['shadow', 'presets'],
  'aspect-ratio': ['dimensions', 'aspectRatios'],
};

// Scalar leaves from WordPress 6.8 WP_Theme_JSON's style property mappings.
// Radius, spacing, block gap and background image also accept nested objects.
const SCALAR_PATHS = [
  'background.backgroundPosition', 'background.backgroundRepeat',
  'background.backgroundSize', 'background.backgroundAttachment',
  'background.backgroundImage.url',
  'color.background', 'color.text', 'color.gradient',
  'dimensions.aspectRatio', 'dimensions.minHeight', 'filter.duotone',
  'layout.contentSize', 'layout.wideSize',
  ...['color', 'style', 'width'].flatMap((property) => [
    `border.${property}`,
    ...['top', 'right', 'bottom', 'left'].map((side) => `border.${side}.${property}`),
  ]),
  ...['topLeft', 'topRight', 'bottomLeft', 'bottomRight'].map((corner) => `border.radius.${corner}`),
  ...['color', 'offset', 'style', 'width'].map((property) => `outline.${property}`),
  ...['margin', 'padding'].flatMap((property) =>
    ['top', 'right', 'bottom', 'left'].map((side) => `spacing.${property}.${side}`)),
  'spacing.blockGap.top', 'spacing.blockGap.left', 'shadow',
  ...['fontFamily', 'fontSize', 'fontStyle', 'fontWeight', 'letterSpacing',
    'lineHeight', 'textAlign', 'textColumns', 'textDecoration', 'textTransform',
    'writingMode'].map((property) => `typography.${property}`),
];

function validateThemeJsonStyles(theme) {
  const errors = [];

  function visit(value, segments, settings, stylePath = []) {
    const location = segments.join('.');
    if (value !== null && typeof value === 'object') {
      if (SCALAR_PATHS.includes(stylePath.join('.'))) {
        errors.push(`${location} must be a single style value, not an object or array.`);
      }
      for (const [key, child] of Object.entries(value)) {
        const childSettings = segments.at(-1) === 'blocks'
          ? [theme.settings?.blocks?.[key], ...settings]
          : settings;
        const startsStyle = ['blocks', 'elements', 'variations'].includes(stylePath.at(-1)) || key.startsWith(':');
        visit(child, [...segments, key], childSettings, startsStyle ? [] : [...stylePath, key]);
      }
      return;
    }
    if (typeof value !== 'string') return;
    if (value.includes('---wp')) errors.push(`${location} uses the malformed ---wp custom-property prefix.`);
    for (const match of value.matchAll(/var\(\s*--wp--preset--([a-z-]+?)--([A-Za-z0-9_-]+)/g)) {
      const [, type, slug] = match;
      const presetPath = PRESET_PATHS[type];
      const defined = presetPath && settings.some((scope) => {
        const presets = presetPath.reduce((entry, key) => entry?.[key], scope);
        return Array.isArray(presets) && presets.some((preset) => String(preset.slug) === slug);
      });
      if (!defined) errors.push(`${location} references undefined preset --wp--preset--${type}--${slug}.`);
    }
  }

  visit(theme.styles || {}, ['styles'], [theme.settings]);
  return errors;
}

if (require.main === module) {
  const filename = path.resolve(process.argv[2] || path.join(__dirname, '../../theme.json'));
  const errors = validateThemeJsonStyles(JSON.parse(fs.readFileSync(filename, 'utf8')));
  if (errors.length) {
    console.error(errors.join('\n'));
    process.exitCode = 1;
  }
  else console.log('theme.json style values and preset references are valid.');
}

module.exports = { validateThemeJsonStyles };
