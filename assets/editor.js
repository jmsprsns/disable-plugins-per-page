/**
 * "Plugins on this page" panel in the block editor's document sidebar.
 *
 * Each plugin can follow the rules, be turned off here, or always load
 * here. Changes save straight away to the plugin's own REST route, and the
 * response brings back what the rules now do on this page.
 * disable_plugins_per_page_editor comes from PHP (see
 * Disable_Plugins_Per_Page_Editor::enqueue).
 */

/* global wp, disable_plugins_per_page_editor */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! config || ! wp.plugins || ! wp.element || ! wp.components || ! wp.apiFetch ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useRef = wp.element.useRef;
	var SelectControl = wp.components.SelectControl;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var i18n = config.i18n;

	if ( ! Panel ) {
		return;
	}

	function stateOf( plugin ) {
		if ( plugin.mode === 'off' ) {
			return { cls: 'is-off', text: i18n.offHere };
		}

		if ( plugin.mode === 'on' && plugin.ruled ) {
			return { cls: 'is-on', text: i18n.onHere };
		}

		if ( ! plugin.ruled ) {
			return { cls: 'is-on', text: i18n.onNow };
		}

		return { cls: 'is-ruled', text: i18n.offBy.replace( '%s', plugin.reason ) };
	}

	function PluginsPanel() {
		var dataState = useState( config.data );
		var data = dataState[ 0 ];
		var setData = dataState[ 1 ];
		var saveState = useState( '' );
		var saving = saveState[ 0 ];
		var setSaving = saveState[ 1 ];
		var filterState = useState( '' );
		var filter = filterState[ 0 ];
		var setFilter = filterState[ 1 ];
		var timer = useRef( null );

		function save( next ) {
			setData( next );
			setSaving( 'saving' );
			clearTimeout( timer.current );

			timer.current = setTimeout( function () {
				var settings = {};

				next.plugins.forEach( function ( plugin ) {
					if ( plugin.mode ) {
						settings[ plugin.file ] = plugin.mode;
					}
				} );

				wp.apiFetch( {
					path: config.route,
					method: 'POST',
					data: { status: next.status, settings: settings },
				} ).then( function ( fresh ) {
					setData( fresh );
					setSaving( 'saved' );
				} ).catch( function () {
					setSaving( 'failed' );
				} );
			}, 500 );
		}

		function setMode( file, mode ) {
			save( Object.assign( {}, data, {
				plugins: data.plugins.map( function ( plugin ) {
					return plugin.file === file ? Object.assign( {}, plugin, { mode: mode } ) : plugin;
				} ),
			} ) );
		}

		var term = filter.trim().toLowerCase();

		// Plugins a rule or this page affects first, then the rest by name.
		var sorted = data.plugins.slice().sort( function ( a, b ) {
			var aFirst = a.ruled || a.mode ? 0 : 1;
			var bFirst = b.ruled || b.mode ? 0 : 1;

			return aFirst - bFirst || a.name.localeCompare( b.name );
		} );

		var rows = sorted.filter( function ( plugin ) {
			return term === '' || plugin.name.toLowerCase().indexOf( term ) !== -1;
		} ).map( function ( plugin ) {
			var state = stateOf( plugin );
			var id = 'dppp-mode-' + plugin.file.replace( /[^a-z0-9]/gi, '-' );

			return el(
				'div',
				{ key: plugin.file, className: 'dppp-editor-plugin ' + state.cls },
				el( 'label', { className: 'dppp-editor-name', htmlFor: id }, plugin.name ),
				el( 'div', { className: 'dppp-editor-state' }, state.text ),
				el(
					'select',
					{
						id: id,
						className: 'dppp-editor-select',
						value: plugin.mode,
						onChange: function ( event ) {
							setMode( plugin.file, event.target.value );
						},
					},
					el( 'option', { value: '' }, i18n.follow ),
					el( 'option', { value: 'off' }, i18n.off ),
					el( 'option', { value: 'on' }, i18n.on )
				)
			);
		} );

		var foot = {
			saving: i18n.saving,
			saved: i18n.saved,
			failed: i18n.failed,
		}[ saving ] || i18n.instant;

		return el(
			Panel,
			{ name: 'disable-plugins-per-page', title: i18n.title, className: 'dppp-editor-panel' },
			el( 'p', { className: 'dppp-editor-intro' }, i18n.intro ),
			data.paused ? el( 'p', { className: 'dppp-editor-paused' }, i18n.paused ) : null,
			data.plugins.length > 8 ? el( 'input', {
				type: 'search',
				className: 'dppp-editor-filter',
				placeholder: i18n.filter,
				'aria-label': i18n.filter,
				value: filter,
				onChange: function ( event ) {
					setFilter( event.target.value );
				},
			} ) : null,
			data.plugins.length ? el( 'div', { className: 'dppp-editor-list' }, rows ) : el( 'p', null, i18n.none ),
			el( SelectControl, {
				label: i18n.appliesTo,
				value: data.status,
				options: [
					{ value: 'test', label: i18n.testing },
					{ value: 'live', label: i18n.live },
				],
				onChange: function ( status ) {
					save( Object.assign( {}, data, { status: status } ) );
				},
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} ),
			el(
				'p',
				{ className: 'dppp-editor-foot is-' + ( saving || 'idle' ) },
				el( 'span', { role: 'status' }, foot ),
				el( 'a', { href: config.settingsUrl }, i18n.allRules )
			)
		);
	}

	wp.plugins.registerPlugin( 'disable-plugins-per-page', {
		render: PluginsPanel,
		icon: 'admin-plugins',
	} );
}( window.wp, window.disable_plugins_per_page_editor ) );
