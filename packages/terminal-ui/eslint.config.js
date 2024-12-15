import { ESLint } from 'eslint';

export default {
	parser: '@typescript-eslint/parser',
	plugins: ['@typescript-eslint', 'react', 'react-hooks', 'import', 'jsx-a11y', 'prettier'],
	extends: [
		'eslint:recommended', // Base ESLint recommended rules
		'plugin:react/recommended', // React-specific rules
		'plugin:react-hooks/recommended', // React hooks best practices
		'plugin:@typescript-eslint/recommended', // TypeScript-specific rules
		'plugin:jsx-a11y/recommended', // Accessibility rules
		'plugin:prettier/recommended', // Integrates Prettier with ESLint
	],
	rules: {
		// Prettier integration
		'prettier/prettier': [
			'error',
			{
				useTabs: true,
				tabWidth: 2,
				singleQuote: true,
				trailingComma: 'es5',
				printWidth: 100,
				endOfLine: 'lf',
				plugins: ['prettier-plugin-tailwindcss'],
			},
		],

		// React-specific rules
		'react/react-in-jsx-scope': 'off', // Not needed for React 17+
		'react/prop-types': 'off', // Disable PropTypes for TypeScript projects
		'react/jsx-props-no-spreading': 'off', // Allow prop spreading
		'react/function-component-definition': [
			'error',
			{
				namedComponents: 'arrow-function',
				unnamedComponents: 'arrow-function',
			},
		],

		// React Hooks
		'react-hooks/rules-of-hooks': 'error', // Validates Hooks rules
		'react-hooks/exhaustive-deps': 'warn', // Validates effect dependencies

		// TypeScript rules
		'@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }], // Ignore unused vars with `_`
		'@typescript-eslint/no-explicit-any': 'off', // Allow `any` type
		'@typescript-eslint/explicit-function-return-type': 'off', // Disable mandatory return types

		// Import order and organization
		'import/order': [
			'error',
			{
				groups: ['builtin', 'external', 'internal', ['parent', 'sibling', 'index'], 'type'],
				pathGroups: [
					{
						pattern: 'react',
						group: 'external',
						position: 'before',
					},
					{
						pattern: '@wcpos/**',
						group: 'external',
						position: 'after',
					},
				],
				pathGroupsExcludedImportTypes: ['react'],
				alphabetize: { order: 'asc', caseInsensitive: true },
				'newlines-between': 'always',
			},
		],

		// Accessibility
		'jsx-a11y/no-static-element-interactions': 'off',
		'jsx-a11y/click-events-have-key-events': 'off',

		// Miscellaneous
		'no-console': 'warn', // Allow console logs with a warning
		'no-debugger': 'error', // Disallow debugger statements
	},
	settings: {
		react: {
			version: 'detect', // Automatically detect React version
		},
	},
	ignorePatterns: ['dist/', 'node_modules/'], // Ignore build artifacts
};
