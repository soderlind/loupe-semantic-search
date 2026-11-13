import { test, expect } from '@playwright/test';
import { SEMANTIC_QUERY, SEMANTIC_TITLE, clearSearchCache, frontEndTitles, setSetting } from './helpers/wp';

test.describe( 'J2 Front-end search', () => {
	test.beforeAll( () => {
		setSetting( 'hybrid_frontend', true );
		clearSearchCache();
	} );

	const cases: Array< [ string, string, string ] > = [
		[ 'AC-03', 'billig ferie', 'Rimelige sommerturer uten fly' ],
		[ 'AC-04', 'vondt i kneet', 'Når kneet verker etter joggeturen' ],
		[ 'AC-05', 'PC-en er treg', 'Datamaskinen er treg: hva kan jeg gjøre?' ],
		[ 'AC-06', SEMANTIC_QUERY, SEMANTIC_TITLE ],
	];

	for ( const [ id, query, title ] of cases ) {
		test( `${ id } "${ query }" finds "${ title }" in the top 5`, async ( { page } ) => {
			const titles = await frontEndTitles( page, query );
			expect( titles.slice( 0, 5 ) ).toContain( title );
		} );
	}

	test( 'AC-07 an exact keyword match stays first ("fårikål")', async ( { page } ) => {
		const titles = await frontEndTitles( page, 'fårikål' );
		expect( titles[ 0 ] ).toBe( 'Fårikål slik bestemor lagde den' );
	} );
} );
