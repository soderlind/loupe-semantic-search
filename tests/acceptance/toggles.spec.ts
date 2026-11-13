import { test, expect } from '@playwright/test';
import { SEMANTIC_QUERY, SEMANTIC_TITLE, clearSearchCache, frontEndTitles, putSettings, setSetting } from './helpers/wp';

test.describe( 'J5 Toggles', () => {
	let before: Record< string, unknown >;

	test.beforeAll( () => {
		before = setSetting( 'hybrid_frontend', false );
		clearSearchCache();
	} );

	test.afterAll( () => {
		putSettings( before );
		clearSearchCache();
	} );

	test( 'AC-13 with front-end hybrid off, /?s= is keyword-only', async ( { page } ) => {
		const titles = await frontEndTitles( page, SEMANTIC_QUERY );
		expect( titles ).not.toContain( SEMANTIC_TITLE );
	} );
} );
