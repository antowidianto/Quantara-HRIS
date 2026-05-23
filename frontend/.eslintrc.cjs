/* eslint-env node */
module.exports = {
  root: true,
  env: {
    browser: true,
    es2022: true,
    node: true,
  },
  extends: [
    'eslint:recommended',
    'plugin:vue/vue3-essential',
    // Prettier compat — disable ESLint rules yang konflik dengan
    // Prettier (mis. indentation, quotes, comma-dangle). Format murni
    // urusan Prettier; ESLint fokus ke logic/bug rules.
    // Harus terakhir di list extends.
    'prettier',
  ],
  parserOptions: {
    ecmaVersion: 'latest',
    sourceType: 'module',
  },
  rules: {
    // Komponen banyak yang single-word (mis. Login, Profile, Dashboard).
    // Tidak ingin paksa rename masal — disable saja.
    'vue/multi-word-component-names': 'off',
    // Banyak v-html legitimate untuk render HTML server-side (contract,
    // payslip). Akan ditinjau case-by-case di refactor.
    'vue/no-v-html': 'off',
    // Allow unused vars yang prefix _ (ergonomic untuk destructure).
    'no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
    // Console diperbolehkan tapi peringatkan supaya bisa di-clean sebelum prod.
    'no-console': ['warn', { allow: ['warn', 'error'] }],
    // Empty catch block adalah pattern legitimate untuk silent-fail
    // di banyak tempat (mis. localStorage quota, optional API).
    'no-empty': ['error', { allowEmptyCatch: true }],
    // Vue template parsing-error untuk literal '<' di string (mis. di
    // tooltip "<5 menit"). Downgrade ke warning supaya tidak block CI.
    'vue/no-parsing-error': ['warn', { 'invalid-first-character-of-tag-name': false }],
  },
  ignorePatterns: [
    'dist/',
    'node_modules/',
    'public/sw.js',
    'public/manifest.webmanifest',
  ],
}