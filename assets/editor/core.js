(function (wp) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useEffect = wp.element.useEffect;
	var useMemo = wp.element.useMemo;
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;
	var registerBlockType = wp.blocks.registerBlockType;
	var createBlock = wp.blocks.createBlock;
	var cloneBlock = wp.blocks.cloneBlock;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var useInnerBlocksProps = wp.blockEditor.useInnerBlocksProps;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var BlockContextProvider = wp.blockEditor.BlockContextProvider;
	var BlockPreview = wp.blockEditor.BlockPreview;
	var useBlockPreview = wp.blockEditor.__experimentalUseBlockPreview || function (options) {
		return Object.assign({}, options.props || {}, {
			children: BlockPreview ? el(BlockPreview, { blocks: options.blocks }) : null
		});
	};
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var RangeControl = wp.components.RangeControl;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var Notice = wp.components.Notice;
	var Spinner = wp.components.Spinner;
	var Button = wp.components.Button;
	var apiFetch = wp.apiFetch;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var __ = wp.i18n.__;

	var EVENT_CONTEXT = 'tt5-caldav/event';
	var EVENTS_CONTEXT = 'tt5-caldav/events';
	var LOADING_CONTEXT = 'tt5-caldav/loading';
	var ERROR_CONTEXT = 'tt5-caldav/error';
	var PREVIEW_COUNT_CONTEXT = 'tt5-caldav/previewCount';

	var textSupports = {
		html: false,
		color: { text: true, link: true },
		border: { color: true, radius: true, style: true, width: true },
		spacing: { margin: true, padding: true },
		typography: {
			fontSize: true,
			lineHeight: true,
			fontFamily: true,
			fontWeight: true,
			fontStyle: true,
			letterSpacing: true,
			textTransform: true,
			textDecoration: true
		}
	};

	var eventFieldsTemplate = [
		['tt5-caldav/event-date', {}],
		['tt5-caldav/event-title', {}],
		['tt5-caldav/event-time', {}],
		['tt5-caldav/event-location', {}]
	];

	// Lock only the direct template block. Its freely editable children are initialized separately.
	var loopTemplate = [
		['tt5-caldav/event-template', { layout: { type: 'grid', columnCount: 1 } }]
	];

	function makeSampleEvent() {
		var start = new Date();
		start.setDate(start.getDate() + 1);
		start.setHours(18, 0, 0, 0);
		var end = new Date(start.getTime() + 90 * 60 * 1000);
		var timezone = 'UTC';
		try {
			timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
		} catch (e) {
			// UTC is a safe editor fallback.
		}
		return {
			uid: 'tt5-caldav-sample',
			title: __('Beispielveranstaltung', 'tt5-caldav-calendar'),
			description: __('Hier erscheint die Beschreibung des Termins.', 'tt5-caldav-calendar'),
			location: __('Veranstaltungsort', 'tt5-caldav-calendar'),
			url: '#',
			allDay: false,
			startTimestamp: Math.floor(start.getTime() / 1000),
			endTimestamp: Math.floor(end.getTime() / 1000),
			endDisplayTimestamp: Math.floor(end.getTime() / 1000),
			timezone: timezone,
			date: '',
			endDate: '',
			time: '',
			endTime: '',
			isSample: true
		};
	}

	var sampleEvent = makeSampleEvent();

	function joinClasses() {
		return Array.prototype.slice.call(arguments).filter(Boolean).join(' ');
	}

	function createBlocksFromTemplate(template) {
		return template.map(function (item) {
			return createBlock(item[0], item[1] || {}, item[2] ? createBlocksFromTemplate(item[2]) : []);
		});
	}

	function eventFromProps(props) {
		return (props.context && props.context[EVENT_CONTEXT]) || sampleEvent;
	}

	function formatPhpTimestamp(timestamp, timezone, format, fallback) {
		if (!timestamp || !window.Intl || !Intl.DateTimeFormat) {
			return fallback || '';
		}
		try {
			var date = new Date(timestamp * 1000);
			var numeric = new Intl.DateTimeFormat(undefined, {
				timeZone: timezone,
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit',
				second: '2-digit',
				hourCycle: 'h23'
			}).formatToParts(date);
			var parts = {};
			numeric.forEach(function (part) {
				if (part.type !== 'literal') {
					parts[part.type] = part.value;
				}
			});
			var weekdayLong = new Intl.DateTimeFormat(undefined, { timeZone: timezone, weekday: 'long' }).format(date);
			var weekdayShort = new Intl.DateTimeFormat(undefined, { timeZone: timezone, weekday: 'short' }).format(date);
			var monthLong = new Intl.DateTimeFormat(undefined, { timeZone: timezone, month: 'long' }).format(date);
			var monthShort = new Intl.DateTimeFormat(undefined, { timeZone: timezone, month: 'short' }).format(date);
			var hour24 = parseInt(parts.hour || '0', 10);
			var hour12 = hour24 % 12 || 12;
			var tokens = {
				d: parts.day || '',
				j: String(parseInt(parts.day || '0', 10)),
				D: weekdayShort,
				l: weekdayLong,
				F: monthLong,
				M: monthShort,
				m: parts.month || '',
				n: String(parseInt(parts.month || '0', 10)),
				Y: parts.year || '',
				y: (parts.year || '').slice(-2),
				H: String(hour24).padStart(2, '0'),
				G: String(hour24),
				h: String(hour12).padStart(2, '0'),
				g: String(hour12),
				i: parts.minute || '00',
				s: parts.second || '00',
				a: hour24 < 12 ? 'am' : 'pm',
				A: hour24 < 12 ? 'AM' : 'PM'
			};
			var output = '';
			var escaped = false;
			String(format || '').split('').forEach(function (character) {
				if (escaped) {
					output += character;
					escaped = false;
					return;
				}
				if (character === '\\') {
					escaped = true;
					return;
				}
				output += Object.prototype.hasOwnProperty.call(tokens, character) ? tokens[character] : character;
			});
			return output || fallback || '';
		} catch (e) {
			return fallback || '';
		}
	}

	window.tt5CaldavEditorCore = {
		EVENT_CONTEXT: EVENT_CONTEXT,
		EVENTS_CONTEXT: EVENTS_CONTEXT,
		LOADING_CONTEXT: LOADING_CONTEXT,
		ERROR_CONTEXT: ERROR_CONTEXT,
		PREVIEW_COUNT_CONTEXT: PREVIEW_COUNT_CONTEXT,
		textSupports: textSupports,
		eventFieldsTemplate: eventFieldsTemplate,
		loopTemplate: loopTemplate,
		sampleEvent: sampleEvent,
		joinClasses: joinClasses,
		createBlocksFromTemplate: createBlocksFromTemplate,
		eventFromProps: eventFromProps,
		formatPhpTimestamp: formatPhpTimestamp
	};
})(window.wp);
