import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import { ADMIN_STATE } from './helpers/wp';

export const BASE_URL = 'http://plugins.local/loupe/';

// The Local site is already running, so there is no webServer. Tests share one
// WordPress site and toggle its settings, so they run serially.
export default defineConfig( {
	testDir: __dirname,
	fullyParallel: false,
	workers: 1,
	retries: 0,
	timeout: 60_000,
	reporter: [ [ 'list' ] ],
	outputDir: path.join( __dirname, '../../test-results' ),
	use: {
		baseURL: BASE_URL,
		locale: 'nb-NO',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{ name: 'setup', testMatch: /auth\.setup\.ts/, teardown: 'teardown' },
		{ name: 'teardown', testMatch: /global\.teardown\.ts/ },
		{
			name: 'admin',
			testMatch: /admin\.spec\.ts/,
			dependencies: [ 'setup' ],
			use: { ...devices[ 'Desktop Chrome' ], storageState: ADMIN_STATE },
		},
		{
			name: 'search',
			testMatch: /(frontend|block|abilities|toggles|exclusion)\.spec\.ts/,
			dependencies: [ 'admin' ],
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
