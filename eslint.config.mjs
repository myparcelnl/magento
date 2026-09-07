import {FlatCompat} from '@eslint/eslintrc';
import js from '@eslint/js';
import stylistic from '@stylistic/eslint-plugin';

/* The @myparcel-dev/eslint-config-* packages are eslintrc-only, so FlatCompat resolves
   their `extends` chains. -esnext pulls in -es6 and the base config, which is where
   env.browser and the bulk of the rules come from.
   -prettier is deliberately absent: it pins eslint-plugin-prettier@4, whose rule calls
   context.getSourceCode() and throws on ESLint 9+. See INT-1855. */
const compat = new FlatCompat({baseDirectory: import.meta.dirname});

/** Renamed rather than carried over verbatim by @stylistic. */
const RENAMED = {'func-call-spacing': 'function-call-spacing'};

/* ESLint deprecated its formatting rules and deletes them in v11; @stylistic is where they
   live now. Re-point every preset rule @stylistic carries, keeping the same options so the
   enforced style is unchanged. Membership in stylistic.rules is the test: it holds only
   formatting rules. Renaming them also renames them in eslint-disable comments. */
const toStylistic = (rules = {}) => {
  return Object.entries(rules).reduce((out, [name, value]) => {
    const target = RENAMED[name] ?? name;

    if (target in stylistic.rules) {
      out[name] = 'off';
      out[`@stylistic/${target}`] = value;
    }

    return out;
  }, {});
};

/* The shared config disables these recommended correctness rules. */
const RECOMMENDED_CORRECTNESS = {
  'constructor-super': 'warn',
  'getter-return': 'warn',
  'no-class-assign': 'warn',
  'no-control-regex': 'warn',
  'no-fallthrough': 'warn',
  'no-misleading-character-class': 'warn',
  'no-obj-calls': 'warn',
  'no-octal': 'warn',
  'no-prototype-builtins': 'warn',
  'no-redeclare': 'warn',
  'no-self-assign': 'warn',
  'no-shadow-restricted-names': 'warn',
  'no-sparse-arrays': 'warn',
  'no-this-before-super': 'warn',
  'no-unsafe-finally': 'warn',
  'no-unsafe-negation': 'warn',
  'require-yield': 'warn',
  'use-isnan': 'warn',
};

const block = (files, config, overrides = {}) => {
  return compat.config(config).map((entry) => ({
    ...entry,
    files,
    plugins: {...entry.plugins, '@stylistic': stylistic},
    rules: {
      ...entry.rules,
      ...toStylistic(entry.rules),
      ...RECOMMENDED_CORRECTNESS,
      ...overrides,
    },
  }));
};

export default [
  {ignores: ['.yarn/**', 'vendor/**', 'view/**/web/js/vendor/**']},

  js.configs.recommended,

  // Magento AMD modules: RequireJS `define`, browser, and our two page globals.
  ...block(
    ['view/**/*.js'],
    {
      extends: ['@myparcel-dev/eslint-config-esnext'],
      env: {amd: true},
      globals: {MyParcel: 'writable', MyParcelConfig: 'writable'},
    },
    {'max-params': 'off', 'max-lines-per-function': 'off', 'no-underscore-dangle': 'off'},
  ),

  // Node CommonJS tooling.
  ...block(
    ['release.config.js', 'private/**/*.js'],
    {
      extends: ['@myparcel-dev/eslint-config-node', '@myparcel-dev/eslint-config-esnext'],
      parserOptions: {sourceType: 'script'},
    },
    {'no-console': 'off', '@stylistic/max-len': 'off'},
  ),

  // This config file itself: Node, but ESM.
  ...block(['eslint.config.mjs'], {
    extends: ['@myparcel-dev/eslint-config-node', '@myparcel-dev/eslint-config-esnext'],
    parserOptions: {sourceType: 'module'},
  }),
];
