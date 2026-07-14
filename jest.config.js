module.exports = {
	transform: {
		'^.+\\.vue$': '@vue/vue2-jest',
		'^.+\\.js$': 'babel-jest',
		'^.+\\.ts$': 'ts-jest',
		'.+\\.(css|styl|less|sass|scss|png|jpg|ttf|woff|woff2)$': 'jest-transform-stub',
	},
	moduleFileExtensions: ['js', 'json', 'vue', 'ts'],
	testEnvironment: 'jest-environment-jsdom',
	// Pre-existing misconfiguration fixed with connector-catalog-ui: without
	// a testMatch, jest hoovered up the Playwright suites (tests/e2e/**) and
	// the vitest suites (tests/vitest/**) and failed on every single one —
	// those belong to their own runners (`npm run test:e2e`,
	// `npm run test:unit`). Jest-owned specs live under tests/jest/**; the
	// app's frontend unit tests are vitest, so an empty match set is a pass.
	testMatch: ['<rootDir>/tests/jest/**/*.spec.js', '<rootDir>/tests/jest/**/*.spec.ts'],
	passWithNoTests: true,
	moduleNameMapper: {
		'^@/(.*)$': '<rootDir>/src/$1',
	},
	coveragePathIgnorePatterns: [
		'index.js',
		'index.ts',
	],
	coverageDirectory: '<rootDir>/coverage-frontend/',
}
