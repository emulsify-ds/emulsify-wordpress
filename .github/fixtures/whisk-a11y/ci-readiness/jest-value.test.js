import { projectValue } from './jest-value.js';

it('executes an ESM import from project source', () => {
  expect(projectValue).toBe(42);
});
