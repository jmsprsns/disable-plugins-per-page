/**
 * Content Powered > Disable Plugins Per Page behaviour. Only loaded on that page.
 *
 * Tabs, rule cards (open/close, add, delete, live titles, the add-ons that
 * go with a plugin), the page picker, "Check a page", before-and-after
 * measuring and the copy buttons. disable_plugins_per_page_admin.ajaxUrl,
 * .nonce, .measureNonce, .names, .deps and .i18n come from PHP (see
 * Disable_Plugins_Per_Page_Admin::enqueue_assets).
 */

/* global disable_plugins_per_page_admin */
( function () {
	'use strict';

	var data = window.disable_plugins_per_page_admin;
	var dirty = false;
	var submitting = false;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' ) {
			return;
		}

		var form = document.querySelector( '.dppp-form' );

		initTabs( form );
		initRules( form );
		initDirty( form );
		initCheck();
		initMeasure();
		initCopy();
	} );

	// Helpers ------------------------------------------------------------------

	function post( params ) {
		var body = new FormData();

		Object.keys( params ).forEach( function ( key ) {
			body.append( key, params[ key ] );
		} );

		return fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function sprintf( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;

		return text.replace( /%(\d\$)?[sd]/g, function ( match, pos ) {
			var index = pos ? parseInt( pos, 10 ) - 1 : i++;

			return args[ index ] !== undefined ? args[ index ] : '';
		} );
	}

	function randomId( length ) {
		var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		var out = '';
		var values = new Uint32Array( length );

		window.crypto.getRandomValues( values );

		for ( var i = 0; i < length; i++ ) {
			out += chars[ values[ i ] % chars.length ];
		}

		return out;
	}

	function showMessage( container, text, isError ) {
		container.textContent = '';

		var p = document.createElement( 'p' );

		p.className = isError ? 'dppp-message is-error' : 'dppp-message';
		p.textContent = text;
		container.appendChild( p );
	}

	// Tabs ---------------------------------------------------------------------

	function initTabs( form ) {
		var tabs = document.querySelectorAll( '[data-dppp-tab]' );
		var panels = document.querySelectorAll( '[data-dppp-panel]' );
		var referer = form ? form.querySelector( 'input[name="_wp_http_referer"]' ) : null;
		var refererBase = referer ? referer.value.split( '#' )[ 0 ].replace( /[?&](dppp_notice|dppp_open)=[^&]*/g, '' ) : '';

		function show( id ) {
			var found = false;

			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-dppp-tab' ) === id;

				found = found || active;
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );

			if ( ! found && tabs.length ) {
				show( tabs[ 0 ].getAttribute( 'data-dppp-tab' ) );
				return;
			}

			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-dppp-panel' ) !== id;
			} );

			// Come back to this tab after saving.
			if ( referer ) {
				referer.value = refererBase + '#' + id;
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				var id = tab.getAttribute( 'data-dppp-tab' );

				event.preventDefault();
				show( id );
				history.replaceState( null, '', '#' + id );
			} );
		} );

		window.addEventListener( 'hashchange', function () {
			show( window.location.hash.replace( '#', '' ) );
		} );

		show( window.location.hash.replace( '#', '' ) );
	}

	// Rule cards ---------------------------------------------------------------

	function initRules( form ) {
		if ( ! form ) {
			return;
		}

		form.querySelectorAll( '[data-dppp-rule]' ).forEach( setupRule );

		form.querySelectorAll( '[data-dppp-add]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var area = button.getAttribute( 'data-dppp-add' );
				var template = document.getElementById( 'dppp-template-' + area );
				var list = form.querySelector( '[data-dppp-rules="' + area + '"]' );

				if ( ! template || ! list ) {
					return;
				}

				var holder = document.createElement( 'div' );

				holder.innerHTML = template.innerHTML.replace( /__ID__/g, 'r' + randomId( 9 ) );

				var rule = holder.firstElementChild;

				list.appendChild( rule );
				setupRule( rule );
				updateEmpty( list );
				dirty = true;

				var first = rule.querySelector( '[data-dppp-plugin], [data-dppp-filter]' );

				if ( first ) {
					first.focus();
				}
			} );
		} );
	}

	function updateEmpty( list ) {
		var empty = list.parentNode.querySelector( '.dppp-rules-empty' );

		if ( empty ) {
			empty.hidden = list.querySelectorAll( '[data-dppp-rule]' ).length > 0;
		}
	}

	function setupRule( rule ) {
		var head = rule.querySelector( '.dppp-rule-head' );
		var body = rule.querySelector( '.dppp-rule-body' );
		var title = rule.querySelector( '[data-dppp-title]' );
		var pill = rule.querySelector( '[data-dppp-pill]' );
		var label = rule.querySelector( '[data-dppp-label]' );
		var everywhere = rule.querySelector( '[data-dppp-everywhere]' );
		var fields = rule.querySelector( '[data-dppp-where-fields]' );
		var filter = rule.querySelector( '[data-dppp-filter]' );

		head.addEventListener( 'click', function () {
			var open = body.hidden;

			body.hidden = ! open;
			rule.classList.toggle( 'is-open', open );
			head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );

		function updateTitle() {
			if ( label && label.value.trim() ) {
				title.textContent = label.value.trim();
				return;
			}

			var names = [];

			rule.querySelectorAll( '[data-dppp-plugin]:checked' ).forEach( function ( box ) {
				names.push( data.names[ box.value ] || box.value );
			} );

			if ( ! names.length ) {
				title.textContent = data.i18n.newRule;
			} else if ( names.length > 2 ) {
				title.textContent = sprintf( data.i18n.more, names[ 0 ], names.length - 1 );
			} else {
				title.textContent = names.join( ' + ' );
			}
		}

		function updateDeps() {
			var note = rule.querySelector( '[data-dppp-deps]' );
			var chosen = [];
			var extra = [];

			if ( ! note ) {
				return;
			}

			rule.querySelectorAll( '[data-dppp-plugin]:checked' ).forEach( function ( box ) {
				chosen.push( box.value );
			} );

			// Add-ons whose parent is chosen (and add-ons of those).
			var changed = true;

			while ( changed ) {
				changed = false;

				Object.keys( data.deps ).forEach( function ( child ) {
					if ( chosen.indexOf( child ) !== -1 || extra.indexOf( child ) !== -1 ) {
						return;
					}

					var parents = data.deps[ child ];

					for ( var i = 0; i < parents.length; i++ ) {
						if ( chosen.indexOf( parents[ i ] ) !== -1 || extra.indexOf( parents[ i ] ) !== -1 ) {
							extra.push( child );
							changed = true;
							break;
						}
					}
				} );
			}

			note.hidden = ! extra.length;
			note.textContent = extra.length ? data.i18n.withIt + ' ' + extra.map( function ( file ) {
				return data.names[ file ] || file;
			} ).join( ', ' ) : '';
		}

		function updateStatus() {
			var checked = rule.querySelector( '[data-dppp-status]:checked' );
			var status = checked ? checked.value : 'test';

			rule.setAttribute( 'data-status', status );
			pill.className = 'dppp-pill is-' + status;
			pill.textContent = data.i18n.status[ status ] || status;
		}

		function updateWhere() {
			if ( everywhere && fields ) {
				fields.classList.toggle( 'is-disabled', everywhere.checked );
			}
		}

		rule.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( '[data-dppp-plugin]' ) ) {
				updateTitle();
				updateDeps();
			} else if ( event.target.matches( '[data-dppp-status]' ) ) {
				updateStatus();
			} else if ( event.target.matches( '[data-dppp-everywhere]' ) ) {
				updateWhere();
			}
		} );

		if ( label ) {
			label.addEventListener( 'input', updateTitle );
		}

		if ( filter ) {
			filter.addEventListener( 'input', function () {
				var term = filter.value.trim().toLowerCase();

				rule.querySelectorAll( '.dppp-picker-item' ).forEach( function ( item ) {
					item.hidden = term !== '' && item.textContent.toLowerCase().indexOf( term ) === -1;
				} );
			} );

			// Enter in the filter mustn't submit the form.
			filter.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
				}
			} );
		}

		rule.querySelector( '[data-dppp-delete]' ).addEventListener( 'click', function () {
			if ( ! window.confirm( data.i18n.confirmDel ) ) {
				return;
			}

			var list = rule.parentNode;

			rule.remove();
			updateEmpty( list );
			dirty = true;
		} );

		setupItems( rule );
		updateDeps();
		updateWhere();
	}

	// Chosen pages picker ---------------------------------------------------------

	function setupItems( rule ) {
		var box = rule.querySelector( '[data-dppp-items]' );

		if ( ! box ) {
			return;
		}

		var input = box.querySelector( '[data-dppp-search]' );
		var results = box.querySelector( '[data-dppp-results]' );
		var list = box.querySelector( '[data-dppp-item-list]' );
		var name = box.getAttribute( 'data-name' );
		var timer = null;
		var request = 0;

		function chosen() {
			return Array.prototype.map.call( list.querySelectorAll( 'input[type="hidden"]' ), function ( field ) {
				return field.value;
			} );
		}

		function add( item ) {
			if ( chosen().indexOf( String( item.id ) ) !== -1 ) {
				return;
			}

			var chip = document.createElement( 'span' );
			var text = document.createElement( 'span' );
			var remove = document.createElement( 'button' );
			var field = document.createElement( 'input' );

			chip.className = 'dppp-item';
			text.textContent = item.title;
			remove.type = 'button';
			remove.className = 'dppp-item-remove';
			remove.setAttribute( 'aria-label', data.i18n.remove );
			remove.setAttribute( 'data-dppp-item-remove', '' );
			remove.innerHTML = data.iconX;
			field.type = 'hidden';
			field.name = name;
			field.value = item.id;

			chip.appendChild( text );
			chip.appendChild( remove );
			chip.appendChild( field );
			list.appendChild( chip );
			dirty = true;
		}

		function search() {
			var term = input.value.trim();
			var id = ++request;

			results.hidden = false;
			results.textContent = '';

			var wait = document.createElement( 'li' );

			wait.className = 'dppp-search-note';
			wait.textContent = data.i18n.searching;
			results.appendChild( wait );

			fetch( data.ajaxUrl + '?action=dppp_search&nonce=' + encodeURIComponent( data.nonce ) + '&q=' + encodeURIComponent( term ), { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( id !== request ) {
						return;
					}

					results.textContent = '';

					var items = json && json.success ? json.data : [];

					if ( ! items.length ) {
						var none = document.createElement( 'li' );

						none.className = 'dppp-search-note';
						none.textContent = data.i18n.noResults;
						results.appendChild( none );
						return;
					}

					items.forEach( function ( item ) {
						var li = document.createElement( 'li' );
						var button = document.createElement( 'button' );
						var type = document.createElement( 'span' );

						button.type = 'button';
						button.textContent = item.title;
						type.textContent = item.type;
						button.appendChild( type );
						button.addEventListener( 'click', function () {
							add( item );
							results.hidden = true;
							input.value = '';
							input.focus();
						} );
						li.appendChild( button );
						results.appendChild( li );
					} );
				} )
				.catch( function () {
					results.hidden = true;
				} );
		}

		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( search, 250 );
		} );

		input.addEventListener( 'focus', function () {
			if ( ! input.value.trim() ) {
				search();
			}
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();

				var first = results.querySelector( 'button' );

				if ( first ) {
					first.click();
				}
			} else if ( event.key === 'Escape' ) {
				results.hidden = true;
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! box.contains( event.target ) ) {
				results.hidden = true;
			}
		} );

		list.addEventListener( 'click', function ( event ) {
			var remove = event.target.closest( '[data-dppp-item-remove]' );

			if ( remove ) {
				remove.parentNode.remove();
				dirty = true;
			}
		} );
	}

	// Unsaved changes ----------------------------------------------------------------

	function initDirty( form ) {
		if ( ! form ) {
			return;
		}

		form.addEventListener( 'change', function ( event ) {
			if ( ! event.target.closest( '[data-dppp-panel="results"]' ) ) {
				dirty = true;
			}
		} );

		form.addEventListener( 'submit', function () {
			submitting = true;
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( dirty && ! submitting ) {
				event.preventDefault();
				event.returnValue = data.i18n.unsaved;
			}
		} );

		// Links that leave the page (starter rules, recovery key) after a confirm.
		document.querySelectorAll( '[data-dppp-leave]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				if ( link.hasAttribute( 'data-dppp-new-key' ) && ! window.confirm( data.i18n.newKey ) ) {
					event.preventDefault();
					return;
				}

				if ( dirty && ! window.confirm( data.i18n.unsaved ) ) {
					event.preventDefault();
					return;
				}

				submitting = true;
			} );
		} );
	}

	// Check a page -------------------------------------------------------------

	function initCheck() {
		var button = document.querySelector( '[data-dppp-check]' );
		var input = document.querySelector( '[data-dppp-check-url]' );
		var output = document.querySelector( '[data-dppp-check-result]' );

		if ( ! button || ! input || ! output ) {
			return;
		}

		function run() {
			button.disabled = true;
			showMessage( output, data.i18n.checking, false );

			post( { action: 'dppp_check', nonce: data.measureNonce, url: input.value } )
				.then( function ( json ) {
					if ( json && json.success ) {
						output.innerHTML = json.data.html;
					} else {
						showMessage( output, json && json.data && json.data.message ? json.data.message : data.i18n.failed, true );
					}
				} )
				.catch( function () {
					showMessage( output, data.i18n.failed, true );
				} )
				.then( function () {
					button.disabled = false;
				} );
		}

		button.addEventListener( 'click', run );
		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				run();
			}
		} );
	}

	// Before and after -----------------------------------------------------------

	function initMeasure() {
		var button = document.querySelector( '[data-dppp-measure]' );
		var input = document.querySelector( '[data-dppp-measure-url]' );
		var all = document.querySelector( '[data-dppp-measure-all]' );
		var progress = document.querySelector( '[data-dppp-progress]' );
		var list = document.querySelector( '[data-dppp-results-list]' );
		var empty = document.querySelector( '.dppp-results-empty' );
		var busy = false;

		if ( ! button || ! input || ! list ) {
			return;
		}

		function setBusy( value ) {
			busy = value;
			button.disabled = value;

			if ( all ) {
				all.disabled = value;
			}
		}

		function label( url ) {
			try {
				return new URL( url, window.location.href ).pathname;
			} catch ( e ) {
				return url;
			}
		}

		function measure( url ) {
			var run = randomId( 16 );
			var total = data.rounds * 2;
			var step = 0;

			function next() {
				if ( step >= total ) {
					return post( { action: 'dppp_finish', nonce: data.measureNonce, url: url, run: run } );
				}

				progress.hidden = false;
				progress.className = 'dppp-progress';
				progress.textContent = sprintf( data.i18n.measuring, label( url ), step + 1, total );

				var current = step;

				step++;

				// Alternate, so a slow moment affects both sides alike.
				return post( {
					action: 'dppp_measure',
					nonce: data.measureNonce,
					url: url,
					mode: current % 2 === 0 ? 'all' : 'rules',
					run: run,
					step: current,
				} ).then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( json && json.data && json.data.message ? json.data.message : data.i18n.failed );
					}

					return next();
				} );
			}

			return next().then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( json && json.data && json.data.message ? json.data.message : data.i18n.failed );
				}

				list.querySelectorAll( '[data-dppp-result]' ).forEach( function ( row ) {
					if ( row.getAttribute( 'data-dppp-result' ) === url || label( row.getAttribute( 'data-dppp-result' ) ) === label( url ) ) {
						row.remove();
					}
				} );

				list.insertAdjacentHTML( 'afterbegin', json.data.html );

				if ( empty ) {
					empty.hidden = true;
				}
			} );
		}

		function runAll( urls ) {
			if ( busy || ! urls.length ) {
				return;
			}

			setBusy( true );

			var chain = Promise.resolve();

			urls.forEach( function ( url ) {
				chain = chain.then( function () {
					return measure( url );
				} );
			} );

			chain
				.then( function () {
					progress.className = 'dppp-progress is-done';
					progress.textContent = data.i18n.done;
				} )
				.catch( function ( error ) {
					progress.hidden = false;
					progress.className = 'dppp-progress is-error';
					progress.textContent = error.message || data.i18n.failed;
				} )
				.then( function () {
					setBusy( false );
				} );
		}

		button.addEventListener( 'click', function () {
			runAll( [ input.value.trim() ] );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				runAll( [ input.value.trim() ] );
			}
		} );

		document.querySelectorAll( '[data-dppp-suggest]' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				input.value = chip.getAttribute( 'data-dppp-suggest' );
				runAll( [ input.value ] );
			} );
		} );

		if ( all ) {
			all.addEventListener( 'click', function () {
				runAll( Array.prototype.map.call( document.querySelectorAll( '[data-dppp-suggest]' ), function ( chip ) {
					return chip.getAttribute( 'data-dppp-suggest' );
				} ) );
			} );
		}

		list.addEventListener( 'click', function ( event ) {
			var forget = event.target.closest( '[data-dppp-forget]' );

			if ( ! forget ) {
				return;
			}

			var row = forget.closest( '[data-dppp-result]' );

			post( { action: 'dppp_forget', nonce: data.measureNonce, url: forget.getAttribute( 'data-dppp-forget' ) } );
			row.remove();

			if ( empty && ! list.querySelector( '[data-dppp-result]' ) ) {
				empty.hidden = false;
			}
		} );
	}

	// Copy buttons -------------------------------------------------------------------

	function initCopy() {
		document.querySelectorAll( '[data-dppp-copy]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var field = button.parentNode.querySelector( '[data-dppp-copy-source]' );
				var text = button.querySelector( 'span' );
				var original = text ? text.textContent : '';

				if ( ! field ) {
					return;
				}

				field.select();

				var done = function () {
					if ( text ) {
						text.textContent = data.i18n.copied;
						setTimeout( function () {
							text.textContent = original;
						}, 1500 );
					}
				};

				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( field.value ).then( done );
				} else {
					document.execCommand( 'copy' );
					done();
				}
			} );
		} );
	}
}() );
