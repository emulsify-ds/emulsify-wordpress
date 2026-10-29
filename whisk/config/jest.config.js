export default {
  // Resolve project tests and coverage from the child-theme root.
  rootDir: '..',
  coverageDirectory: '<rootDir>/.coverage',
  testEnvironment: 'node',
  // Keep native ESM imports instead of converting them to CommonJS.
  transform: {},
  coverageProvider: 'v8',
  collectCoverageFrom: [
    '**/*.{js,mjs,cjs,jsx}',
    '!**/{node_modules,config,dist,.out,.coverage}/**',
    '!**/*.{test,spec,stories}.{js,mjs,cjs,jsx}',
    '!**/__tests__/**',
  ],
  testMatch: [
    '<rootDir>/src/**/*.test.{js,cjs,mjs}',
    '<rootDir>/components/**/*.test.{js,cjs,mjs}',
    '<rootDir>/config/**/*.test.{js,cjs,mjs}',
  ],
};
