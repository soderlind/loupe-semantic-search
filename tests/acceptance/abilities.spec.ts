import { test, expect } from '@playwright/test';
import { RETRIEVE_URL, SEMANTIC_QUERY, SEMANTIC_TITLE, adminApi, retrieveParams, setSetting } from './helpers/wp';

test.describe( 'J4 Abilities', () => {
	test.beforeAll( () => {
		setSetting( 'abilities', true );
	} );

	test( 'AC-11 retrieve returns passages with text and a URL', async () => {
		const { ctx, headers } = await adminApi();
		const res = await ctx.get( RETRIEVE_URL, { headers, params: retrieveParams( SEMANTIC_QUERY ) } );
		expect( res.status(), await res.text() ).toBe( 200 );
		const { passages } = await res.json();
		expect( passages.length ).toBeGreaterThan( 0 );
		const hit = passages.find( ( p: Record< string, string > ) => p.title === SEMANTIC_TITLE );
		expect( hit, JSON.stringify( passages.map( ( p: Record< string, string > ) => p.title ) ) ).toBeTruthy();
		expect( hit.text.length ).toBeGreaterThan( 0 );
		expect( hit.url ).toMatch( /^http:\/\/plugins\.local\/loupe\// );
		await ctx.dispose();
	} );

	test( 'AC-12 an anonymous visitor is denied', async ( { request } ) => {
		const res = await request.get( RETRIEVE_URL, { params: retrieveParams( SEMANTIC_QUERY ) } );
		expect( [ 401, 403 ] ).toContain( res.status() );
	} );
} );
