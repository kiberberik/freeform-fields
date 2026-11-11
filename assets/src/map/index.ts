/**
 * Map field editor.
 *
 * Two providers share one interface: OpenStreetMap (Leaflet + Nominatim, no key needed)
 * and Google Maps (Maps JavaScript API + Geocoder, used when an API key is configured).
 * The form stores address, lat, lng, zoom and address parts like ACF's Google Map field.
 */
import { __ } from '@wordpress/i18n';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import './style.scss';

const cfg = window.wpffMap || { provider: 'osm' };
const instances = new WeakMap();

/* ---------------------------------------------------------------------
 * Providers
 * ------------------------------------------------------------------ */

const pin = L.divIcon( {
	className: 'wpff-map__pin',
	html: '<span></span>',
	iconSize: [ 26, 26 ],
	iconAnchor: [ 13, 26 ],
} );

function osmPlace( item ) {
	const a = item.address || {};
	return {
		address: item.display_name || '',
		lat: Number( item.lat ),
		lng: Number( item.lon ),
		place_id: item.place_id ? String( item.place_id ) : '',
		name: item.name || '',
		street_number: a.house_number || '',
		street_name: a.road || '',
		city: a.city || a.town || a.village || a.hamlet || '',
		state: a.state || '',
		post_code: a.postcode || '',
		country: a.country || '',
		country_short: ( a.country_code || '' ).toUpperCase(),
	};
}

const osm = {
	ready: () => Promise.resolve(),
	create( canvas, center, zoom, onPick, onZoom ) {
		const map = L.map( canvas, { scrollWheelZoom: false } ).setView(
			center,
			zoom
		);
		L.tileLayer( cfg.tiles.url, {
			attribution: cfg.tiles.attribution,
			maxZoom: 19,
		} ).addTo( map );
		let marker = null;
		map.on( 'click', ( e ) => onPick( e.latlng.lat, e.latlng.lng ) );
		map.on( 'zoomend', () => onZoom( map.getZoom() ) );
		// Maps inside closed tabs, accordions or the block sidebar start at size 0.
		new window.ResizeObserver( () => map.invalidateSize() ).observe(
			canvas
		);
		return {
			setMarker( lat, lng, pan ) {
				if ( ! marker ) {
					marker = L.marker( [ lat, lng ], {
						icon: pin,
						draggable: true,
						keyboard: true,
						title: __( 'Selected location', 'freeform-fields' ),
					} ).addTo( map );
					marker.on( 'dragend', () => {
						const p = marker.getLatLng();
						onPick( p.lat, p.lng );
					} );
				} else {
					marker.setLatLng( [ lat, lng ] );
				}
				if ( pan ) {
					map.setView( [ lat, lng ], Math.max( map.getZoom(), 14 ) );
				}
			},
			removeMarker() {
				if ( marker ) {
					map.removeLayer( marker );
					marker = null;
				}
			},
			getZoom: () => map.getZoom(),
		};
	},
	async search( query ) {
		const url = new URL( cfg.geocoderUrl + '/search' );
		url.search = new URLSearchParams( {
			q: query,
			format: 'jsonv2',
			addressdetails: 1,
			limit: 5,
			'accept-language': cfg.language || 'en',
		} );
		const res = await window.fetch( url );
		if ( ! res.ok ) {
			throw new Error( res.statusText );
		}
		return ( await res.json() ).map( osmPlace );
	},
	async reverse( lat, lng ) {
		const url = new URL( cfg.geocoderUrl + '/reverse' );
		url.search = new URLSearchParams( {
			lat,
			lon: lng,
			format: 'jsonv2',
			addressdetails: 1,
			'accept-language': cfg.language || 'en',
		} );
		const res = await window.fetch( url );
		const data = res.ok ? await res.json() : null;
		return data && ! data.error ? osmPlace( data ) : null;
	},
};

let googleLoading = null;

function googlePlace( result ) {
	const part = ( type, short ) => {
		const c = ( result.address_components || [] ).find( ( x ) =>
			x.types.includes( type )
		);
		if ( ! c ) {
			return '';
		}
		return short ? c.short_name : c.long_name;
	};
	const loc = result.geometry.location;
	return {
		address: result.formatted_address || '',
		lat: loc.lat(),
		lng: loc.lng(),
		place_id: result.place_id || '',
		street_number: part( 'street_number' ),
		street_name: part( 'route' ),
		street_name_short: part( 'route', true ),
		city: part( 'locality' ) || part( 'postal_town' ),
		state: part( 'administrative_area_level_1' ),
		state_short: part( 'administrative_area_level_1', true ),
		post_code: part( 'postal_code' ),
		country: part( 'country' ),
		country_short: part( 'country', true ),
	};
}

const google = {
	ready() {
		if ( window.google && window.google.maps ) {
			return Promise.resolve();
		}
		if ( ! googleLoading ) {
			googleLoading = new Promise( ( resolve, reject ) => {
				window.wpffGoogleMapsReady = resolve;
				const s = document.createElement( 'script' );
				s.src =
					'https://maps.googleapis.com/maps/api/js?' +
					new URLSearchParams( {
						key: cfg.googleKey,
						callback: 'wpffGoogleMapsReady',
						loading: 'async',
						language: cfg.language || 'en',
					} );
				s.async = true;
				s.onerror = reject;
				document.head.appendChild( s );
			} );
		}
		return googleLoading;
	},
	create( canvas, center, zoom, onPick, onZoom ) {
		const gm = window.google.maps;
		const map = new gm.Map( canvas, {
			center: { lat: center[ 0 ], lng: center[ 1 ] },
			zoom,
			streetViewControl: false,
			mapTypeControl: false,
		} );
		let marker = null;
		map.addListener( 'click', ( e ) =>
			onPick( e.latLng.lat(), e.latLng.lng() )
		);
		map.addListener( 'zoom_changed', () => onZoom( map.getZoom() ) );
		return {
			setMarker( lat, lng, pan ) {
				const pos = { lat, lng };
				if ( ! marker ) {
					marker = new gm.Marker( {
						position: pos,
						map,
						draggable: true,
						title: __( 'Selected location', 'freeform-fields' ),
					} );
					marker.addListener( 'dragend', () => {
						const p = marker.getPosition();
						onPick( p.lat(), p.lng() );
					} );
				} else {
					marker.setPosition( pos );
				}
				if ( pan ) {
					map.setCenter( pos );
					map.setZoom( Math.max( map.getZoom(), 14 ) );
				}
			},
			removeMarker() {
				if ( marker ) {
					marker.setMap( null );
					marker = null;
				}
			},
			getZoom: () => map.getZoom(),
		};
	},
	async search( query ) {
		const { results } = await new window.google.maps.Geocoder().geocode( {
			address: query,
		} );
		return results.slice( 0, 5 ).map( googlePlace );
	},
	async reverse( lat, lng ) {
		const { results } = await new window.google.maps.Geocoder().geocode( {
			location: { lat, lng },
		} );
		return results.length ? googlePlace( results[ 0 ] ) : null;
	},
};

const provider = 'google' === cfg.provider ? google : osm;

/* ---------------------------------------------------------------------
 * Field
 * ------------------------------------------------------------------ */

function initMap( el ) {
	if ( instances.has( el ) ) {
		return;
	}
	instances.set( el, true );

	const q = ( sel ) => el.querySelector( sel );
	const address = q( '.wpff-map__address' );
	const latInput = q( '.wpff-map__lat' );
	const lngInput = q( '.wpff-map__lng' );
	const zoomInput = q( '.wpff-map__zoom' );
	const placeInput = q( '.wpff-map__place' );
	const results = q( '.wpff-map__results' );
	const status = q( '.wpff-map__status' );
	const canvas = q( '.wpff-map__canvas' );
	// A duplicated repeater row copies the old map's DOM: start from an empty canvas.
	canvas.replaceChildren();
	canvas.className = 'wpff-map__canvas';

	let map = null;
	const say = ( text ) => ( status.textContent = text || '' );
	const changed = () => window.jQuery( latInput ).trigger( 'change' );

	const has = () =>
		latInput.value !== '' &&
		lngInput.value !== '' &&
		isFinite( latInput.value ) &&
		isFinite( lngInput.value );

	function apply( place, pan ) {
		latInput.value = Number( place.lat ).toFixed( 7 ) * 1;
		lngInput.value = Number( place.lng ).toFixed( 7 ) * 1;
		if ( place.address !== undefined ) {
			address.value = place.address;
		}
		const parts = { ...place };
		delete parts.address;
		delete parts.lat;
		delete parts.lng;
		placeInput.value = JSON.stringify(
			Object.fromEntries(
				Object.entries( parts ).filter( ( [ , v ] ) => v !== '' )
			)
		);
		if ( map ) {
			map.setMarker(
				Number( latInput.value ),
				Number( lngInput.value ),
				pan
			);
			zoomInput.value = map.getZoom();
		}
		changed();
	}

	async function pick( lat, lng ) {
		apply( { lat, lng, address: '' }, false );
		say( __( 'Looking up the address…', 'freeform-fields' ) );
		try {
			const place = await provider.reverse( lat, lng );
			say( '' );
			if ( place ) {
				apply( { ...place, lat, lng }, false );
			}
		} catch {
			say( '' );
		}
	}

	function showResults( list ) {
		results.replaceChildren();
		list.forEach( ( place, i ) => {
			const li = document.createElement( 'li' );
			li.setAttribute( 'role', 'option' );
			li.tabIndex = 0;
			li.id = `${ address.id }-result-${ i }`;
			li.textContent = place.address;
			const choose = () => {
				apply( place, true );
				results.hidden = true;
				say( '' );
				address.focus();
			};
			li.addEventListener( 'click', choose );
			li.addEventListener( 'keydown', ( e ) => {
				if ( 'Enter' === e.key || ' ' === e.key ) {
					e.preventDefault();
					choose();
				} else if ( 'ArrowDown' === e.key && li.nextElementSibling ) {
					e.preventDefault();
					li.nextElementSibling.focus();
				} else if ( 'ArrowUp' === e.key ) {
					e.preventDefault();
					( li.previousElementSibling || address ).focus();
				} else if ( 'Escape' === e.key ) {
					results.hidden = true;
					address.focus();
				}
			} );
			results.appendChild( li );
		} );
		results.hidden = ! list.length;
	}

	async function find() {
		const query = address.value.trim();
		if ( ! query ) {
			return;
		}
		say( __( 'Searching…', 'freeform-fields' ) );
		try {
			const list = await provider.search( query );
			showResults( list );
			say(
				list.length
					? __(
							'Choose a result with the arrow keys or the mouse.',
							'freeform-fields'
						)
					: __( 'Nothing found.', 'freeform-fields' )
			);
		} catch {
			say(
				__(
					'Search failed. Try again later or enter coordinates.',
					'freeform-fields'
				)
			);
		}
	}

	address.addEventListener( 'keydown', ( e ) => {
		if ( 'Enter' === e.key ) {
			// Don't submit the post form.
			e.preventDefault();
			find();
		} else if ( 'ArrowDown' === e.key && ! results.hidden ) {
			e.preventDefault();
			results.firstElementChild?.focus();
		} else if ( 'Escape' === e.key ) {
			results.hidden = true;
		}
	} );
	q( '.wpff-map__find' ).addEventListener( 'click', find );

	q( '.wpff-map__clear' ).addEventListener( 'click', () => {
		address.value = '';
		latInput.value = '';
		lngInput.value = '';
		zoomInput.value = '';
		placeInput.value = '';
		results.hidden = true;
		if ( map ) {
			map.removeMarker();
		}
		say( '' );
		changed();
	} );

	const locate = q( '.wpff-map__locate' );
	if ( ! window.navigator.geolocation ) {
		locate.hidden = true;
	}
	locate.addEventListener( 'click', () => {
		say( __( 'Finding your location…', 'freeform-fields' ) );
		window.navigator.geolocation.getCurrentPosition(
			( pos ) => {
				pick( pos.coords.latitude, pos.coords.longitude );
				if ( map ) {
					map.setMarker(
						pos.coords.latitude,
						pos.coords.longitude,
						true
					);
				}
			},
			() =>
				say(
					__( 'Your location is not available.', 'freeform-fields' )
				)
		);
	} );

	// Typed coordinates move the marker.
	[ latInput, lngInput ].forEach( ( input ) =>
		input.addEventListener( 'change', () => {
			if ( map && has() ) {
				map.setMarker(
					Number( latInput.value ),
					Number( lngInput.value ),
					true
				);
			}
		} )
	);

	provider
		.ready()
		.then( () => {
			const center = has()
				? [ Number( latInput.value ), Number( lngInput.value ) ]
				: [
						Number( el.dataset.centerLat ),
						Number( el.dataset.centerLng ),
					];
			const zoom =
				Number( zoomInput.value ) || Number( el.dataset.zoom ) || 14;
			map = provider.create( canvas, center, zoom, pick, ( z ) => {
				if ( has() ) {
					zoomInput.value = z;
				}
			} );
			if ( has() ) {
				map.setMarker( center[ 0 ], center[ 1 ], false );
			}
		} )
		.catch( () => {
			canvas.hidden = true;
			say(
				__(
					'The map could not be loaded. You can still enter coordinates.',
					'freeform-fields'
				)
			);
		} );
}

function initAll( root ) {
	( root || document ).querySelectorAll( '.wpff-map' ).forEach( ( el ) => {
		// Skip row templates: they are copied before use.
		if ( ! el.closest( 'template' ) ) {
			initMap( el );
		}
	} );
}

window.jQuery( document ).on( 'wpff:init', ( e, root ) => initAll( root ) );
window.jQuery( () => initAll( document ) );
