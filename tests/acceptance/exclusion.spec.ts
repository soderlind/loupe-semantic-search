import { test, expect } from '@playwright/test';
import { RETRIEVE_URL, SEMANTIC_QUERY, SEMANTIC_TITLE, adminApi, clearSearchCache, frontEndTitles, postIdByTitle, restTitles, retrieveParams, wp } from './helpers/wp';

test.describe( 'J6 Exclusion', () => {
	let postId = 0;

	test.beforeAll( () => {
		postId = postIdByTitle( SEMANTIC_TITLE );
		expect( postId ).toBeGreaterThan( 0 );
		wp( 'post', 'meta', 'update', String( postId ), '_loupe_search_exclude', '1' );
		clearSearchCache();
	} );

	test.afterAll( () => {
		if ( postId ) {
			wp( 'post', 'meta', 'delete', String( postId ), '_loupe_search_exclude' );
			clearSearchCache();
		}
	} );

	test( 'AC-14 an excluded post is never returned by semantic search', async ( { page, request } ) => {
		const front = await frontEndTitles( page, SEMANTIC_QUERY );
		expect( front, 'front-end /?s=' ).not.toContain( SEMANTIC_TITLE );

		const rest = await restTitles( request, { q: SEMANTIC_QUERY } );
		expect( rest, 'REST POST /search' ).not.toContain( SEMANTIC_TITLE );

		const { ctx, headers } = await adminApi();
		const res = await ctx.get( RETRIEVE_URL, { headers, params: retrieveParams( SEMANTIC_QUERY, 20 ) } );
		expect( res.status() ).toBe( 200 );
		const titles = ( await res.json() ).passages.map( ( p: Record< string, string > ) => p.title );
		expect( titles, 'loupe-semantic/retrieve' ).not.toContain( SEMANTIC_TITLE );
		await ctx.dispose();
	} );
} );
