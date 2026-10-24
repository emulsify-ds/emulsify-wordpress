#!/usr/bin/env node

import { pathToFileURL } from 'node:url';

import {
  applyProjectA11yConfig,
  lintReportAndExit,
  resolvePa11yStoryIds,
  resolveProjectA11yConfig,
  startStorybookServer,
} from '../../node_modules/@emulsify/core/scripts/a11y.js';

const { default: a11yConfig } = await import(
  pathToFileURL(resolveProjectA11yConfig()).href
);
applyProjectA11yConfig(a11yConfig);

const storyIds = resolvePa11yStoryIds();

if (storyIds.length === 0) {
  throw new Error(
    'No Storybook stories were discovered; accessibility checks did not run.',
  );
}

const server = await startStorybookServer();

try {
  await lintReportAndExit(storyIds, {
    baseUrl: `${server.baseUrl}/iframe.html`,
  });
} finally {
  await server.close();
}
