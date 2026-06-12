import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import prettier from 'eslint-config-prettier';
import globals from 'globals';

export default tseslint.config(
    {
        // generated/vendored trees and untracked reference checkouts ESLint should never walk
        ignores: [
            'public/**',
            'vendor/**',
            'storage/**',
            'bootstrap/cache/**',
            'ddnet/**',
            'ddnet-web/**',
            'ddnet-scripts/**',
            'teeworlds/**',
        ],
    },
    {
        // the front-end bundle: browser globals + TypeScript rules
        files: ['resources/assets/js/**/*.ts'],
        extends: [js.configs.recommended, ...tseslint.configs.recommended],
        languageOptions: {
            globals: { ...globals.browser },
        },
    },
    {
        // root config files run in Node (vite.config.js, this file)
        files: ['*.js'],
        extends: [js.configs.recommended],
        languageOptions: {
            globals: { ...globals.node },
        },
    },
    {
        // vitest tests run in Node but exercise browser/Web APIs (DecompressionStream, etc.)
        files: ['tests/js/**/*.ts'],
        extends: [js.configs.recommended, ...tseslint.configs.recommended],
        languageOptions: {
            globals: { ...globals.node, ...globals.browser },
        },
    },
    // turn off rules that would fight Prettier (must stay last)
    prettier,
);
