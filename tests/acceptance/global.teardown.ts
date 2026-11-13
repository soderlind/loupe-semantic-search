import { test as teardown } from '@playwright/test';
import fs from 'node:fs';
import { RATE_LIMIT_STASH, setSetting } from './helpers/wp';

teardown( 'restore the per-visitor rate limit', () => {
	if ( fs.existsSync( RATE_LIMIT_STASH ) ) {
		setSetting( 'rate_limit', Number( fs.readFileSync( RATE_LIMIT_STASH, 'utf8' ) ) );
		fs.unlinkSync( RATE_LIMIT_STASH );
	}
} );
