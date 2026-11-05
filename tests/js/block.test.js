const assert = require( 'assert' );
const fs = require( 'fs' );
const src = fs.readFileSync( process.argv[ 2 ] || require( 'path' ).join( __dirname, '../../assets/block.js' ), 'utf8' );
function run( formsData ) {
	let reg = null;
	const el = ( type, props, ...kids ) => ( { type, props, kids } );
	global.window = global;
	global.wp = {
		blocks: { registerBlockType: ( n, s ) => { reg = [ n, s ]; } },
		element: { createElement: el },
		blockEditor: { useBlockProps: () => ( { className: 'x' } ) },
		components: { SelectControl: 'Select', Placeholder: 'Placeholder', Notice: 'Notice' },
		serverSideRender: 'SSR',
	};
	global.FormTheoryBlock = formsData;
	new Function( 'window', src ).call( global, global );
	return reg;
}
const forms = [ { value: 0, label: 'Pick' }, { value: 3, label: 'Contact' } ];
for ( const d of [ undefined, { forms: [], i18n: {} }, { forms, i18n: { title: 'T' } } ] ) {
	const reg = run( d );
	assert.strictEqual( reg[ 0 ], 'formtheory/form' );
	assert.ok( reg[ 1 ].attributes.id );
	assert.strictEqual( reg[ 1 ].save(), null );
	let setAttrs = null;
	for ( const id of [ 0, 3, 99 ] ) {
		const out = reg[ 1 ].edit( { attributes: { id }, setAttributes: ( a ) => { setAttrs = a; } } );
		assert.ok( out );
		const s = JSON.stringify( out );
		if ( d && d.forms.length && id === 3 ) assert.ok( s.includes( '"type":"SSR"' ) );
		if ( d && d.forms.length && id === 99 ) assert.ok( s.includes( 'Notice' ) );
	}
}
const reg = run( { forms, i18n: {} } );
const known = JSON.stringify( reg[ 1 ].edit( { attributes: { id: 3 }, setAttributes() {} } ) );
assert.ok( known.includes( '"type":"SSR"' ) );
const unknown = JSON.stringify( reg[ 1 ].edit( { attributes: { id: 99 }, setAttributes() {} } ) );
assert.ok( unknown.includes( 'Notice' ) && ! unknown.includes( '"type":"SSR"' ) );
const miss = JSON.stringify( reg[ 1 ].edit( { attributes: { id: 99 }, setAttributes() {} } ) );
assert.ok( miss.includes( 'Form #99 (not found)' ) && miss.includes( '"disabled":true' ) );
console.log( 'block.test.js ok' );
