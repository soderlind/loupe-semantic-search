import { test as setup, expect } from '@playwright/test';
import fs from 'node:fs';
import { ADMIN_STATE, RATE_LIMIT_STASH, getSettings, setSetting } from './helpers/wp';

// Local's one-click admin login; no passwords.
setup( 'log in as the Local one-click admin', async ( { page } ) => {
	await page.goto( 'http://plugins.local/wp-admin/?localwp_auto_login=1' );
	await page.goto( 'wp-admin/' );
	await expect( page.locator( '#wpadminbar' ) ).toContainText( 'per' );
	await page.context().storageState( { path: ADMIN_STATE } );
} );

// Rate limiting is out of scope; a run makes more query embeddings per minute than the default allows.
setup( 'pause the per-visitor rate limit', () => {
	if ( ! fs.existsSync( RATE_LIMIT_STASH ) ) {
		fs.writeFileSync( RATE_LIMIT_STASH, String( getSettings().rate_limit ) );
	}
	setSetting( 'rate_limit', 0 );
} );
