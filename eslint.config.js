import js from '@eslint/js';
import stylistic from '@stylistic/eslint-plugin';
import prettier from 'eslint-config-prettier/flat';
import importPlugin from 'eslint-plugin-import';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import typescript from 'typescript-eslint';

const controlStatements = [
    'if',
    'return',
    'for',
    'while',
    'do',
    'switch',
    'try',
    'throw',
];
const paddingAroundControl = [
    ...controlStatements.flatMap((stmt) => [
        { blankLine: 'always', prev: '*', next: stmt },
        { blankLine: 'always', prev: stmt, next: '*' },
    ]),
];

/** @type {import('eslint').Linter.Config[]} */
export default [
    js.configs.recommended,
    reactHooks.configs.flat['recommended-latest'],
    ...typescript.configs.recommended,
    {
        ...react.configs.flat.recommended,
        ...react.configs.flat['jsx-runtime'], // Required for React 17+
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',
            'react/no-unescaped-entities': 'off',
        },
        settings: {
            react: {
                version: 'detect',
            },
        },
    },
    {
        plugins: {
            import: importPlugin,
        },
        settings: {
            'import/resolver': {
                typescript: {
                    alwaysTryTypes: true,
                    project: './tsconfig.json',
                },
                node: true,
            },
        },
        rules: {
            '@typescript-eslint/no-explicit-any': 'off',
            '@typescript-eslint/consistent-type-imports': [
                'error',
                {
                    prefer: 'type-imports',
                    fixStyle: 'separate-type-imports',
                },
            ],
            'import/order': [
                'error',
                {
                    groups: [
                        'builtin',
                        'external',
                        'internal',
                        'parent',
                        'sibling',
                        'index',
                    ],
                    alphabetize: {
                        order: 'asc',
                        caseInsensitive: true,
                    },
                },
            ],
            'import/consistent-type-specifier-style': [
                'error',
                'prefer-top-level',
            ],
        },
    },
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            '@stylistic/brace-style': [
                'error',
                '1tbs',
                { allowSingleLine: false },
            ],
            '@stylistic/padding-line-between-statements': [
                'error',
                ...paddingAroundControl,
            ],
        },
    },
    {
        // Dates and numbers are formatted in one place, `lib/local-date`: the
        // server renders without knowing the reader's locale or time zone, and
        // a page that formats them itself fails to hydrate (React error 418).
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: ['resources/js/lib/local-date.ts'],
        rules: {
            'no-restricted-properties': [
                'error',
                ...[
                    'toLocaleString',
                    'toLocaleDateString',
                    'toLocaleTimeString',
                ].map((property) => ({
                    property,
                    message:
                        'Format dates and numbers with resources/js/lib/local-date (useReader, formatLocalDate, formatLocalNumber, or the LocalDate and LocalNumber components) so the server and browser renders match.',
                })),
                ...['DateTimeFormat', 'NumberFormat', 'RelativeTimeFormat'].map(
                    (property) => ({
                        object: 'Intl',
                        property,
                        message:
                            'Format dates and numbers with resources/js/lib/local-date so the server and browser renders match.',
                    }),
                ),
            ],
        },
    },
    {
        ignores: [
            'vendor',
            'node_modules',
            'public',
            'backend/build',
            'backend/target',
            'backend/.wrangler/**',
            'target',
            'bootstrap/ssr',
            '.claude/worktrees/**',
            '.worktrees/**',
            '.agents/**',
            '.claude/skills/**',
            '.cursor/**',
            '.gemini/**',
            '.github/skills/**',
            '.pi/**',
            'tailwind.config.js',
            'vite.config.ts',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
            // Template fixture bundles are content under test, not shipped code.
            'tests/Fixtures/**',
            // Template payloads are reviewed content validated by
            // TemplateCatalog (no inline script, no on*=, size caps),
            // not app code; app JS style rules do not apply to them.
            'resources/templates/**',
        ],
    },
    {
        files: ['*.mjs', '**/*.mjs'],
        languageOptions: {
            globals: {
                ...globals.node,
            },
        },
    },
    prettier, // Turn off all rules that might conflict with Prettier
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            curly: ['error', 'all'],
            '@stylistic/brace-style': [
                'error',
                '1tbs',
                { allowSingleLine: false },
            ],
        },
    },
];
