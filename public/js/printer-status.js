(function( window, document ) {
	'use strict';

	function toLabel( status ) {
		if ( ! status ) {
			return 'Unbekannt';
		}
		return status.replace(/[_-]+/g, ' ').toLowerCase().replace(/(^|\s)\S/g, function( s ) {
			return s.toUpperCase();
		});
	}

	function safeQuery( selector, context ) {
		try {
			return ( context || document ).querySelector( selector );
		} catch ( e ) {
			return null;
		}
	}

	function buildSelectorForHost( host ) {
		if ( window.CSS && typeof window.CSS.escape === 'function' ) {
			return '[data-printer-host="' + window.CSS.escape( host ) + '"]';
		}
		return '[data-printer-host="' + host.replace( /"/g, '\\"' ) + '"]';
	}

	function updateMedia( tile, data ) {
		var snapshot = data.snapshot_url || '';
		var camera = data.camera_stream || '';
		tile.setAttribute( 'data-snapshot', snapshot );
		tile.setAttribute( 'data-camera', camera );
		var media = safeQuery( '.foyer-printer-status__media', tile );
		if ( ! media ) {
			return;
		}

		var img = media.querySelector( 'img' );
		var video = media.querySelector( 'video' );

		if ( snapshot ) {
			var bust = snapshot + ( snapshot.indexOf( '?' ) === -1 ? '?' : '&' ) + 't=' + Date.now();
			if ( img ) {
				img.src = bust;
			} else {
				if ( video ) {
					video.remove();
				}
				img = document.createElement( 'img' );
				img.loading = 'lazy';
				img.alt = '';
				img.src = bust;
				media.innerHTML = '';
				media.appendChild( img );
			}
			return;
		}

		if ( camera ) {
			if ( video ) {
				video.src = camera;
			} else {
				media.innerHTML = '';
				video = document.createElement( 'video' );
				video.autoplay = true;
				video.loop = true;
				video.muted = true;
				video.playsInline = true;
				video.src = camera;
				media.appendChild( video );
			}
			return;
		}

		media.innerHTML = '<div class="foyer-printer-status__placeholder"></div>';
	}

	function updateTile( tile, data ) {
		if ( ! tile ) {
			return;
		}

		var status = ( data.status || 'unknown' ).toLowerCase();
		var statusNode = safeQuery( '.foyer-printer-status__status', tile );
		if ( statusNode ) {
			statusNode.textContent = toLabel( status );
			statusNode.setAttribute( 'data-status', status );
		}

		var nameNode = safeQuery( '.foyer-printer-status__name', tile );
		if ( nameNode && data.name ) {
			nameNode.textContent = data.name;
		}

		var jobNode = safeQuery( '.foyer-printer-status__job', tile );
		if ( jobNode ) {
			if ( data.job_name ) {
				jobNode.textContent = data.job_name;
				jobNode.removeAttribute( 'hidden' );
			} else {
				jobNode.textContent = '';
				jobNode.setAttribute( 'hidden', 'hidden' );
			}
		}

		var progress = typeof data.progress === 'number' ? Math.max( 0, Math.min( 100, data.progress ) ) : 0;
		var bar = safeQuery( '.foyer-printer-status__progress-bar', tile );
		if ( bar ) {
			bar.style.width = progress + '%';
		}
		var progressText = safeQuery( '.foyer-printer-status__progress-text', tile );
		if ( progressText ) {
			progressText.textContent = Math.round( progress ) + '%';
		}

		var metrics = tile.querySelectorAll( '.foyer-printer-status__metric' );
		metrics.forEach( function( metric ) {
			if ( metric.classList.contains( 'foyer-printer-status__metric--eta' ) ) {
				if ( data.eta_human ) {
					metric.textContent = data.eta_human;
					metric.removeAttribute( 'hidden' );
				} else {
					metric.textContent = '';
					metric.setAttribute( 'hidden', 'hidden' );
				}
			}
			if ( metric.classList.contains( 'foyer-printer-status__metric--nozzle' ) ) {
				if ( typeof data.nozzle_temp === 'number' ) {
					metric.textContent = 'Düse: ' + Math.round( data.nozzle_temp ) + '°C';
					metric.removeAttribute( 'hidden' );
				} else {
					metric.textContent = '';
					metric.setAttribute( 'hidden', 'hidden' );
				}
			}
			if ( metric.classList.contains( 'foyer-printer-status__metric--bed' ) ) {
				if ( typeof data.bed_temp === 'number' ) {
					metric.textContent = 'Bett: ' + Math.round( data.bed_temp ) + '°C';
					metric.removeAttribute( 'hidden' );
				} else {
					metric.textContent = '';
					metric.setAttribute( 'hidden', 'hidden' );
				}
			}
		} );

		var errorNode = safeQuery( '.foyer-printer-status__error', tile );
		if ( errorNode ) {
			if ( data.error ) {
				errorNode.textContent = data.error;
				errorNode.removeAttribute( 'hidden' );
			} else {
				errorNode.textContent = '';
				errorNode.setAttribute( 'hidden', 'hidden' );
			}
		}

		updateMedia( tile, data );
	}

	function applyData( instance, printers ) {
		if ( ! printers || ! printers.length ) {
			return;
		}
		var grid = safeQuery( '.foyer-printer-status__grid', instance );
		if ( ! grid ) {
			return;
		}
		printers.forEach( function( printer ) {
			if ( ! printer || ! printer.host ) {
				return;
			}
			var selector = buildSelectorForHost( printer.host );
			var tile = safeQuery( selector, grid );
			if ( tile ) {
				updateTile( tile, printer );
			}
		} );
	}

	function logDebug() {
		if ( typeof console !== 'undefined' && console.debug ) {
			console.debug.apply( console, arguments );
		}
	}

	function scheduleRefresh( instance ) {
		var refreshAttr = parseInt( instance.getAttribute( 'data-refresh' ), 10 );
		var refresh = isNaN( refreshAttr ) || refreshAttr < 15 ? 45 : refreshAttr;
		var endpoint = instance.getAttribute( 'data-endpoint' );
		var message = safeQuery( '.foyer-printer-status__message', instance );
		if ( ! endpoint ) {
			return;
		}

		function handleError( error ) {
			logDebug( '[Foyer PrinterStatus] fetch error:', error );
			if ( message ) {
				message.textContent = error;
				message.removeAttribute( 'hidden' );
			}
		}

		function clearError() {
			if ( message ) {
				message.textContent = '';
				message.setAttribute( 'hidden', 'hidden' );
			}
		}

		function fetchData() {
			logDebug( '[Foyer PrinterStatus] requesting status from', endpoint );
			fetch( endpoint, { cache: 'no-store' } )
				.then( function( response ) {
					logDebug( '[Foyer PrinterStatus] response status:', response.status );
					if ( ! response.ok ) {
						throw new Error( 'HTTP ' + response.status );
					}
					return response.json();
				} )
				.then( function( json ) {
					logDebug( '[Foyer PrinterStatus] payload:', json );
					if ( ! json || ! json.printers ) {
						return;
					}
					clearError();
					applyData( instance, json.printers );
				} )
				.catch( function( err ) {
					handleError( err.message || 'Verbindung fehlgeschlagen' );
				} );
		}

		fetchData();
		var timer = window.setInterval( fetchData, refresh * 1000 );
		instance.foyerPrinterStatusTimer = timer;
	}

	function initInstance( instance ) {
		if ( ! instance || instance.foyerPrinterStatusTimer ) {
			return;
		}
		scheduleRefresh( instance );
	}

	document.addEventListener( 'DOMContentLoaded', function() {
		var instances = document.querySelectorAll( '.foyer-printer-status' );
		instances.forEach( initInstance );
	} );

})( window, document );
