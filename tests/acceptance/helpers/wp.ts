import { execFileSync } from 'node:child_process';
import os from 'node:os';
import path from 'node:path';
import { request as apiRequest, type APIRequestContext, type Page } from '@playwright/test';

const WP_ROOT = process.env.LSS_WP_ROOT ?? path.join( os.homedir(), 'Sites/plugins/app/public' );
const WRAPPER = process.env.LSS_WP_CLI ?? path.join( os.homedir(), '.agents/skills/wp-cli-local/scripts/wp' );
export const SITE_URL = 'http://plugins.local/loupe/';
export const ADMIN_STATE = path.join( __dirname, '../.auth/admin.json' );
export const RATE_LIMIT_STASH = path.join( __dirname, '../.auth/rate-limit.txt' );

export const TARGET = 'openai::text-embedding-3-small';
// "søvnløshet" has no keyword hits at all; only the semantic path can find the target.
export const SEMANTIC_QUERY = 'søvnløshet';
export const SEMANTIC_TITLE = 'Bedre søvn med faste rutiner';

/** Run WP-CLI on the loupe subsite and return stdout without the wrapper's banner line. */
export function wp( ...args: string[] ): string {
	const out = execFileSync( 'bash', [ WRAPPER, `--url=${ SITE_URL }`, ...args ], {
		cwd: WP_ROOT,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		timeout: 300_000,
	} );
	return out
		.split( '\n' )
		.filter( ( line ) => ! line.startsWith( '[plugins] wp ' ) )
		.join( '\n' )
		.trim();
}

export function getSettings(): Record< string, unknown > {
	return JSON.parse( wp( 'option', 'get', 'loupe_semantic_settings', '--format=json' ) );
}

/** Write settings without running the sanitize callback or the target-change hooks. */
export function putSettings( settings: Record< string, unknown > ): void {
	const json = JSON.stringify( settings ).replace( /'/g, "\\'" );
	wp( 'eval', `remove_all_filters("sanitize_option_loupe_semantic_settings"); remove_all_actions("update_option_loupe_semantic_settings"); update_option("loupe_semantic_settings", json_decode('${ json }', true));` );
}

export function setSetting( key: string, value: unknown ): Record< string, unknown > {
	const before = getSettings();
	putSettings( { ...before, [ key ]: value } );
	return before;
}

export function clearSearchCache(): void {
	wp( 'eval', 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'\\\\_transient\\\\_%loupe\\\\_search\\\\_cache\\\\_%\'"); wp_cache_flush();' );
}

export function postIdByTitle( title: string ): number {
	const id = wp( 'eval', `$p = get_posts(["title" => ${ JSON.stringify( title ) }, "post_type" => "any", "numberposts" => 1, "fields" => "ids"]); echo $p ? $p[0] : 0;` );
	return Number( id );
}

/** Titles of the front-end `/?s=` results, in order. */
export async function frontEndTitles( page: Page, query: string ): Promise< string[] > {
	await page.goto( `?s=${ encodeURIComponent( query ) }` );
	const titles = page.getByRole( 'main' ).getByRole( 'heading', { level: 2 } ).getByRole( 'link' );
	return ( await titles.allInnerTexts() ).map( ( t ) => t.trim() );
}

/** Titles from REST `POST /loupe-search/v1/search`. */
export async function restTitles( request: APIRequestContext, payload: Record< string, unknown > ): Promise< string[] > {
	const res = await request.post( `${ SITE_URL }wp-json/loupe-search/v1/search`, { data: payload } );
	if ( ! res.ok() ) {
		throw new Error( `REST search failed: ${ res.status() } ${ await res.text() }` );
	}
	const body = await res.json();
	return ( body.hits ?? [] ).map( ( h: Record< string, string > ) => ( h.post_title ?? h.title ?? '' ).replace( /<[^>]+>/g, '' ) );
}

export const RETRIEVE_URL = `${ SITE_URL }wp-json/wp-abilities/v1/abilities/loupe-semantic/retrieve/run`;

/** An authenticated admin API context with a REST nonce. */
export async function adminApi() {
	const ctx = await apiRequest.newContext( { storageState: ADMIN_STATE } );
	const nonce = ( await ( await ctx.get( `${ SITE_URL }wp-admin/admin-ajax.php?action=rest-nonce` ) ).text() ).trim();
	return { ctx, headers: { 'X-WP-Nonce': nonce } };
}

export function retrieveParams( query: string, limit = 5 ) {
	return { 'input[query]': query, 'input[limit]': String( limit ) };
}
