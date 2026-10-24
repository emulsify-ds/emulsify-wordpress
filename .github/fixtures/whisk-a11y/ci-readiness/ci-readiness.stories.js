import { renderTwig } from '@emulsify/core/storybook';

import template from './ci-readiness.twig';

const render = renderTwig(template);

export default {
  title: 'CI/Readiness fixture',
};

export const Default = {
  render: (args) => render({ ...args, link_text: '' }),
  // Anchor this CI-only readiness delay to Pa11y injection: navigation's
  // network-idle wait must not consume the delay before pa11y.wait begins.
  play: async ({ canvasElement, args }) => {
    await new Promise((resolve) => {
      const waitForPa11y = () => {
        if (window.__pa11y) {
          setTimeout(resolve, 750);
        } else {
          requestAnimationFrame(waitForPa11y);
        }
      };
      waitForPa11y();
    });
    canvasElement.querySelector('a').textContent = args.link_text;
  },
  args: {
    title: 'CI accessibility readiness',
    summary:
      'This component gives the component-agnostic starter one real story during continuous integration.',
    url: 'https://www.emulsify.info',
    link_text: 'Visit the Emulsify documentation',
  },
};
