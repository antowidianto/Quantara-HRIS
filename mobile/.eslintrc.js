/**
 * ESLint config — HRIS Mobile.
 * Extends @react-native/eslint-config (sudah include react, react-native, prettier).
 * Override ringan sesuai konvensi codebase ini.
 */
module.exports = {
  root: true,
  extends: '@react-native',
  parser: '@typescript-eslint/parser',
  plugins: ['@typescript-eslint'],
  rules: {
    'react-native/no-inline-styles': 'off', // banyak inline color dari theme
    'react-hooks/exhaustive-deps': 'warn',
    '@typescript-eslint/no-unused-vars': [
      'warn',
      { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
    ],
    'no-console': ['warn', { allow: ['warn', 'error'] }],
    'prettier/prettier': 'off', // pakai prettier separate config
  },
  ignorePatterns: ['node_modules/', 'android/', 'ios/', 'metro.config.js', 'babel.config.js'],
};