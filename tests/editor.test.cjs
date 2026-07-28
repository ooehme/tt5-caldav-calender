const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.dirname(__dirname);
const registered = new Map();
const noop = () => {};
const identity = (value) => value;

global.window = {
	Intl,
	tt5CaldavEditor: {},
	wp: {
		apiFetch: () => Promise.resolve([]),
		blockEditor: {
			BlockContextProvider: noop,
			BlockPreview: noop,
			InnerBlocks: Object.assign(noop, { Content: noop }),
			InspectorControls: noop,
			useBlockProps: () => ({}),
			useInnerBlocksProps: () => ({}),
		},
		blocks: {
			cloneBlock: identity,
			createBlock: (name, attributes, innerBlocks) => ({ name, attributes, innerBlocks }),
			registerBlockType: (name, settings) => registered.set(name, settings),
		},
		components: new Proxy({}, { get: () => noop }),
		data: {
			useDispatch: () => ({}),
			useSelect: () => [],
		},
		element: {
			Fragment: Symbol('Fragment'),
			createElement: noop,
			useEffect: noop,
			useMemo: (factory) => factory(),
			useRef: (value) => ({ current: value }),
			useState: (value) => [value, noop],
		},
		i18n: {
			__: identity,
		},
	},
};

[
	'assets/editor/core.js',
	'assets/editor/calendar-loop.js',
	'assets/editor/event-template.js',
	'assets/editor/event-fields.js',
	'assets/editor.js',
].forEach((file) => {
	const source = fs.readFileSync(path.join(root, file), 'utf8');
	vm.runInThisContext(source, { filename: file });
});

assert.ok(window.tt5CaldavEditorCore, 'shared editor core is available');
assert.equal(registered.size, 8, 'all block types are registered exactly once');
assert.equal(typeof registered.get('tt5-caldav/calendar-loop').edit, 'function');
assert.equal(typeof registered.get('tt5-caldav/event-template').edit, 'function');
assert.equal(typeof registered.get('tt5-caldav/event-title').edit, 'function');

console.log(`OK (${registered.size} editor blocks)`);
