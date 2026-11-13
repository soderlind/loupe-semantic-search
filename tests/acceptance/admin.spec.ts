import { test, expect } from '@playwright/test';
import { TARGET, getSettings, putSettings, wp } from './helpers/wp';

const SETTINGS_URL = 'wp-admin/options-general.php?page=loupe-search&tab=semantic';

test.describe( 'J1 Admin settings', () => {
	test( 'AC-01 a model chosen on the first save survives a reload', async ( { page } ) => {
		// Given: no model saved. Clearing provider/model this way keeps existing vectors.
		putSettings( { ...getSettings(), provider: '', model: '' } );

		await page.goto( SETTINGS_URL );
		const model = page.getByLabel( 'Modell', { exact: true } );
		await model.selectOption( TARGET );
		await page.getByRole( 'button', { name: 'Lagre endringene' } ).click();
		await expect( page.getByText( 'Innstillinger lagret.' ) ).toBeVisible();
		await expect( page ).toHaveURL( /[?&]tab=semantic\b/ );

		await page.reload();
		await expect( page.getByLabel( 'Modell', { exact: true } ) ).toHaveValue( TARGET );
	} );

	test( 'AC-02 the status table shows full coverage after the backfill', async ( { page } ) => {
		test.setTimeout( 600_000 );
		// Given: the model is saved and the backfill has run to completion.
		const settings = getSettings();
		if ( `${ settings.provider }::${ settings.model }` !== TARGET ) {
			putSettings( { ...settings, provider: 'openai', model: 'text-embedding-3-small' } );
		}
		wp( 'loupe-semantic', 'backfill' );
		wp( 'ai', 'embeddings', 'sync', 'run', '--retry-failed' );

		await page.goto( SETTINGS_URL );
		await expect( page.getByRole( 'heading', { name: 'Indeksstatus' } ) ).toBeVisible();
		const rows = page.getByRole( 'table' ).filter( { hasText: 'Embeddet' } ).getByRole( 'row' );
		await expect( rows ).not.toHaveCount( 1 );
		const bodyRows = await rows.all();
		for ( const row of bodyRows.slice( 1 ) ) {
			const cells = await row.getByRole( 'cell' ).allInnerTexts();
			expect( Number( cells[ 2 ] ), `indexable count for ${ cells[ 0 ] }` ).toBeGreaterThan( 0 );
			expect( cells[ 1 ], `embedded vs indexable for ${ cells[ 0 ] }` ).toBe( cells[ 2 ] );
		}
	} );
} );
