// Runs INSIDE the wiki container: node /tmp/pw/client-cycle.js <status>
// Real browser edit of PerfSite, then observes SDU's reload mechanism. Prints key<TAB>value lines.
const { chromium } = require('playwright');
const status = process.argv[2];
const BASE = 'http://127.0.0.1:8080';
(async () => {
	const browser = await chromium.launch( {
		executablePath: '/usr/bin/chromium',
		args: [ '--no-sandbox', '--disable-dev-shm-usage' ]
	} );
	const page = await ( await browser.newContext() ).newPage();
	const api = {}; let navs = 0; const t = {};
	page.on( 'request', ( r ) => {
		const u = r.url();
		if ( u.includes( '/api.php' ) ) {
			const q = new URL( u ).searchParams;
			let action = q.get( 'action' );
			if ( !action && r.postData() ) { action = new URLSearchParams( r.postData() ).get( 'action' ); }
			api[ action || 'other' ] = ( api[ action || 'other' ] || 0 ) + 1;
		}
		if ( r.isNavigationRequest() && r.frame() === page.mainFrame() && u.includes( 'PerfSite' ) ) { navs++; }
	} );
	await page.goto( `${BASE}/index.php?title=PerfSite&action=edit` );
	const text = `{{#set:Perf Status=${status}}}{{#set:Perf Derived={{#show:PerfSite|?Perf Status}}}}` +
		`{{#set:Semantic Dependency=PerfSite}}{{#set:Semantic Dependency=Perf Part of::PerfSite}}\n` +
		`{{#ask:[[Perf Part of::PerfSite]]|?Perf Value|limit=50}}\n`;
	await page.fill( '#wpTextbox1', text );
	navs = 0; // count only navigations after the save click
	t.click = Date.now();
	await Promise.all( [ page.waitForNavigation(), page.click( '#wpSave' ) ] );
	t.landed = Date.now();
	const hadMarker = ( await page.locator( '.sdu-reload-pending' ).count() ) > 0;
	// done (client view) = no marker/dialog left and stable for 1.5s. Children freshness is reported separately.
	let done = false, timeout = false;
	const deadline = Date.now() + 90000;
	while ( Date.now() < deadline ) {
		await page.waitForTimeout( 250 ).catch( () => {} );
		try {
			const st = await page.evaluate( ( s ) => ( {
				marker: !!document.querySelector( '.sdu-reload-pending' ),
				dialog: !!document.querySelector( '.sdu-reload-dialog' ),
				fresh: ( document.body.innerText.split( s ).length - 1 )
			} ), status );
			if ( !st.marker && !st.dialog ) {
				await page.waitForTimeout( 1500 );
				const again = await page.evaluate(
					() => !document.querySelector( '.sdu-reload-pending' ) &&
						!document.querySelector( '.sdu-reload-dialog' )
				).catch( () => false );
				if ( again ) {
					done = true;
					break;
				}
			}
		} catch ( e ) { /* navigation in flight */ }
	}
	t.done = Date.now();
	if ( !done ) { timeout = true; }
	const fresh = await page.evaluate( ( s ) => document.body.innerText.split( s ).length - 1, status ).catch( () => -1 );
	const out = {
		client_save_to_landed_ms: t.landed - t.click,
		client_save_to_done_ms: t.done - t.click,
		client_done: done ? 1 : 0,
		client_fresh_children: fresh,
		client_had_marker: hadMarker ? 1 : 0,
		client_navigations_after_save: navs,
		client_api_purge: api.purge || 0,
		client_api_status_polls: api.sduselfupdatestatus || 0,
		client_api_other: Object.entries( api )
			.filter( ( [ k ] ) => ![ 'purge', 'sduselfupdatestatus' ].includes( k ) )
			.reduce( ( a, [ , v ] ) => a + v, 0 )
	};
	for ( const [ k, v ] of Object.entries( out ) ) { console.log( `${k}\t${v}` ); }
	await browser.close();
} )();
