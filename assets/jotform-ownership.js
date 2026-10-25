( function ( window ) {
	'use strict';

	var config = window.ghcaAcdJotformOwnershipConfig || {};
	var allowedIds = Array.isArray( config.formIds ) ? config.formIds.map( String ) : [];

	async function getClaim( formId ) {
		if ( ! config.ajaxUrl || ! config.nonce || ! /^[0-9]{6,32}$/.test( String( formId ) ) ) {
			throw new Error( 'ownership_claim_unavailable' );
		}

		var body = new URLSearchParams();
		body.set( 'action', 'ghca_acd_jotform_ownership_claim' );
		body.set( 'nonce', config.nonce );
		body.set( 'form_id', String( formId ) );

		var response = await window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} );
		var payload = await response.json();
		if ( ! response.ok || ! payload || ! payload.success || ! payload.data || ! payload.data.claim ) {
			throw new Error( 'ownership_claim_failed' );
		}
		return payload.data;
	}

	window.ghcaAcdJotformOwnership = Object.freeze( { getClaim: getClaim } );

	function iframeFormId( iframe ) {
		var url;
		try { url = new URL( iframe.src, window.location.href ); } catch ( error ) { return ''; }
		if ( url.protocol !== 'https:' || [ 'form.jotform.com', 'www.jotform.com', 'eu.jotform.com' ].indexOf( url.hostname.toLowerCase() ) < 0 ) return '';
		var match = url.pathname.match( /(?:^|\/)([0-9]{6,32})(?:\/|$)/ );
		return match && allowedIds.indexOf( match[1] ) >= 0 ? match[1] : '';
	}

	function bindIframe( iframe ) {
		if ( iframe.dataset.ghcaOwnershipBound || iframe.dataset.ghcaOwnershipPending ) return;
		var formId = iframeFormId( iframe );
		if ( ! formId ) return;
		iframe.dataset.ghcaOwnershipPending = '1';
		getClaim( formId ).then( function (claim) {
			if ( ! claim || ! /^[1-9][0-9]*$/.test( String( claim.userId ) ) || ! claim.claim ) return;
			if ( iframeFormId( iframe ) !== formId ) return;
			var next = new URL( iframe.src, window.location.href );
			/* Replace both values together: partial or stale prefill is never trusted. */
			next.searchParams.set( 'user_id', String( claim.userId ) );
			next.searchParams.set( 'ghca_ownership_claim', String( claim.claim ) );
			iframe.dataset.ghcaOwnershipBound = '1';
			if ( claim.displayName && ! iframe.previousElementSibling?.matches( '.ghca-acd__ownership-link' ) ) {
				var notice = document.createElement( 'p' );
				notice.className = 'ghca-acd__ownership-link';
				notice.textContent = 'This submission will be linked to ' + String( claim.displayName ) + '.';
				iframe.parentNode.insertBefore( notice, iframe );
			}
			iframe.src = next.href;
		} ).catch( function () {} ).finally( function () { delete iframe.dataset.ghcaOwnershipPending; } );
	}

	function bindAll( root ) {
		var target = root || document;
		if ( target && target.nodeType === 1 && target.matches && target.matches( 'iframe' ) ) bindIframe( target );
		if ( ! target || 'function' !== typeof target.querySelectorAll ) return;
		target.querySelectorAll( 'iframe[src]' ).forEach( bindIframe );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', function () { bindAll( document ); } );
	else bindAll( document );
	new MutationObserver( function (changes) { changes.forEach( function (change) { change.addedNodes.forEach( bindAll ); } ); } ).observe( document.documentElement, { childList: true, subtree: true } );
}( window ) );
