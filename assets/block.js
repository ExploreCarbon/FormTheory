/* FormTheory block: form picker plus server-side-rendered preview. No build step. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.components ) {
		return;
	}
	var el = wp.element.createElement;
	var data = window.FormTheoryBlock || {};
	var forms = Array.isArray( data.forms ) ? data.forms : [];
	var i18n = data.i18n || {};
	var SelectControl = wp.components.SelectControl;
	var Placeholder = wp.components.Placeholder;
	var Notice = wp.components.Notice;
	var SSR = wp.serverSideRender;
	if ( SSR && SSR.default ) {
		SSR = SSR.default;
	}

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	function blockProps() {
		var bp = wp.blockEditor && wp.blockEditor.useBlockProps;
		return bp ? bp() : {};
	}

	wp.blocks.registerBlockType( 'formtheory/form', {
		apiVersion: 2,
		title: t( 'title', 'FormTheory Form' ),
		description: t( 'description', 'Embed a form built with FormTheory.' ),
		category: 'widgets',
		icon: 'feedback',
		attributes: { id: { type: 'number', default: 0 } },
		edit: function ( props ) {
			var id = parseInt( props.attributes.id, 10 ) || 0;
			var props2 = blockProps();
			var known = id > 0 && forms.some( function ( f ) {
				return Number( f.value ) === id;
			} );
			var hasForms = forms.some( function ( f ) {
				return Number( f.value ) > 0;
			} );
			var options = forms.length ? forms.map( function ( f ) {
				return { value: String( f.value ), label: String( f.label ) };
			} ) : [ { value: '0', label: t( 'select_form', '— Select a form —' ) } ];
			if ( id > 0 && ! known ) {
				options.unshift( {
					value: String( id ),
					label: t( 'not_found', 'Form #%d (not found)' ).replace( '%d', String( id ) ),
					disabled: true
				} );
			}
			var select = el( SelectControl, {
				label: t( 'select', 'Form' ),
				value: String( id ),
				options: options,
				onChange: function ( v ) {
					props.setAttributes( { id: parseInt( v, 10 ) || 0 } );
				},
				__nextHasNoMarginBottom: true
			} );
			var children;
			if ( id === 0 ) {
				children = el(
					Placeholder,
					{
						icon: 'feedback',
						label: t( 'title', 'FormTheory Form' ),
						instructions: hasForms ? t( 'instructions', 'Choose which form to show here.' ) : t( 'no_forms', 'No forms yet. Create one under FormTheory first.' )
					},
					select
				);
			} else if ( ! known ) {
				children = el(
					Placeholder,
					{ icon: 'feedback', label: t( 'title', 'FormTheory Form' ) },
					el( Notice, { status: 'warning', isDismissible: false }, t( 'missing', 'The selected form no longer exists. Choose another form.' ) ),
					select
				);
			} else {
				children = [
					el( 'div', { key: 'picker' }, select ),
					el( SSR, { key: 'preview', block: 'formtheory/form', attributes: props.attributes } )
				];
			}
			return el( 'div', props2, children );
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
