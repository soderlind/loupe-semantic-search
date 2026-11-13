import { test, expect, type Page } from '@playwright/test';
import { SEMANTIC_QUERY, SEMANTIC_TITLE, clearSearchCache, restTitles, setSetting, wp } from './helpers/wp';

let pageId = 0;
let pageUrl = '';

async function searchInBlock( page: Page, query: string ) {
	const form = page.getByRole( 'main' ).getByRole( 'search' );
	await form.getByRole( 'searchbox' ).fill( query );
	await form.getByRole( 'button', { name: 'Søk' } ).click();
	await expect( page.getByRole( 'main' ).getByRole( 'status' ) ).not.toBeEmpty();
}

test.describe( 'J3 Loupe Search Block and REST', () => {
	test.beforeAll( () => {
		setSetting( 'hybrid_rest', true );
		clearSearchCache();
		pageId = Number(
			wp( 'post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Akseptansetest søkeblokk',
				'--post_content=<!-- wp:loupe-search/search {"showFacets":false} /-->', '--porcelain' )
		);
		pageUrl = wp( 'post', 'url', String( pageId ) );
	} );

	test.afterAll( () => {
		if ( pageId ) {
			wp( 'post', 'delete', String( pageId ), '--force' );
		}
	} );

	test( 'AC-08 the block shows a semantic-only match', async ( { page } ) => {
		await page.goto( pageUrl );
		await searchInBlock( page, SEMANTIC_QUERY );
		await expect( page.getByRole( 'link', { name: SEMANTIC_TITLE } ) ).toBeVisible();
	} );

	test( 'AC-09 semantic:false returns keyword results only', async ( { request } ) => {
		const titles = await restTitles( request, { q: SEMANTIC_QUERY, semantic: false } );
		expect( titles ).not.toContain( SEMANTIC_TITLE );
	} );

	test( 'AC-10 a title sort returns keyword results only', async ( { page } ) => {
		await page.goto( pageUrl );
		await page.getByRole( 'main' ).getByLabel( 'Sorter etter' ).selectOption( { label: 'Tittel A–Z' } );
		await searchInBlock( page, SEMANTIC_QUERY );
		await expect( page.getByRole( 'link', { name: SEMANTIC_TITLE } ) ).toHaveCount( 0 );
	} );
} );
