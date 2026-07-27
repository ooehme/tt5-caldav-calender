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

	var loopTemplate = [
		['tt5-caldav/event-template', { layout: { type: 'grid', columnCount: 1 } }, eventFieldsTemplate]
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

	function LoopEdit(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var clientId = props.clientId;
		var calendarId = attributes.calendarId;
		var days = attributes.days;
		var offsetDays = attributes.offsetDays;
		var maxEvents = attributes.maxEvents;
		var emptyMessage = attributes.emptyMessage;
		var editorPreviewCount = attributes.editorPreviewCount || 3;
		var blockProps = useBlockProps({ className: 'tt5-caldav-loop' });
		var calendarsState = useState([]);
		var calendars = calendarsState[0];
		var setCalendars = calendarsState[1];
		var calendarsLoadingState = useState(true);
		var calendarsLoading = calendarsLoadingState[0];
		var setCalendarsLoading = calendarsLoadingState[1];
		var eventsState = useState([]);
		var events = eventsState[0];
		var setEvents = eventsState[1];
		var eventsLoadingState = useState(false);
		var eventsLoading = eventsLoadingState[0];
		var setEventsLoading = eventsLoadingState[1];
		var errorState = useState('');
		var error = errorState[0];
		var setError = errorState[1];
		var refreshState = useState(0);
		var refreshKey = refreshState[0];
		var setRefreshKey = refreshState[1];
		var migrationDone = useRef(false);
		var directBlocks = useSelect(function (select) {
			return select('core/block-editor').getBlocks(clientId);
		}, [clientId]);
		var blockEditorDispatch = useDispatch('core/block-editor');

		useEffect(function () {
			if (migrationDone.current || !directBlocks.length) {
				return;
			}

			var templates = directBlocks.filter(function (block) {
				return block.name === 'tt5-caldav/event-template';
			});
			if (templates.length === 1 && directBlocks.length === 1) {
				migrationDone.current = true;
				return;
			}

			var templateAttributes = templates.length ? templates[0].attributes : { layout: { type: 'grid', columnCount: 1 } };
			var migratedChildren = [];
			directBlocks.forEach(function (block) {
				if (block.name === 'tt5-caldav/event-template') {
					(block.innerBlocks || []).forEach(function (innerBlock) {
						migratedChildren.push(cloneBlock(innerBlock));
					});
				} else {
					migratedChildren.push(cloneBlock(block));
				}
			});
			if (!migratedChildren.length) {
				migratedChildren = createBlocksFromTemplate(eventFieldsTemplate);
			}
			blockEditorDispatch.replaceInnerBlocks(
				clientId,
				[createBlock('tt5-caldav/event-template', templateAttributes, migratedChildren)],
				false
			);
			migrationDone.current = true;
		}, [clientId, directBlocks, blockEditorDispatch]);

		useEffect(function () {
			var active = true;
			setCalendarsLoading(true);
			apiFetch({ path: '/tt5-caldav/v1/calendars' }).then(function (items) {
				if (!active) {
					return;
				}
				items = items || [];
				setCalendars(items);
				setCalendarsLoading(false);
				if (!calendarId && items.length === 1) {
					setAttributes({ calendarId: items[0].id });
				}
			}).catch(function (requestError) {
				if (!active) {
					return;
				}
				setCalendarsLoading(false);
				setError(requestError.message || __('Kalender konnten nicht geladen werden.', 'tt5-caldav-calendar'));
			});
			return function () {
				active = false;
			};
		}, []);

		useEffect(function () {
			var active = true;
			if (!calendarId) {
				setEvents([]);
				setEventsLoading(false);
				return function () {
					active = false;
				};
			}
			setEventsLoading(true);
			setError('');
			var path = '/tt5-caldav/v1/events?calendar_id=' + encodeURIComponent(calendarId) +
				'&days=' + encodeURIComponent(days) +
				'&offset=' + encodeURIComponent(offsetDays) +
				'&limit=' + encodeURIComponent(maxEvents) +
				(refreshKey ? '&refresh=true' : '');
			apiFetch({ path: path }).then(function (items) {
				if (!active) {
					return;
				}
				setEvents(items || []);
				setEventsLoading(false);
			}).catch(function (requestError) {
				if (!active) {
					return;
				}
				setError(requestError.message || __('Termine konnten nicht geladen werden.', 'tt5-caldav-calendar'));
				setEvents([]);
				setEventsLoading(false);
			});
			return function () {
				active = false;
			};
		}, [calendarId, days, offsetDays, maxEvents, refreshKey]);

		var calendarOptions = [{ label: __('Kalender auswählen', 'tt5-caldav-calendar'), value: '' }].concat(calendars.map(function (calendar) {
			return { label: calendar.name, value: calendar.id };
		}));

		var inspector = el(InspectorControls, {},
			el(PanelBody, { title: __('Kalenderabfrage', 'tt5-caldav-calendar'), initialOpen: true },
				el(SelectControl, {
					label: __('Kalender', 'tt5-caldav-calendar'),
					value: calendarId,
					options: calendarOptions,
					onChange: function (value) { setAttributes({ calendarId: value }); }
				}),
				el(RangeControl, {
					label: __('Versatz in Tagen', 'tt5-caldav-calendar'),
					help: __('0 beginnt heute; negative Werte beziehen vergangene Tage ein.', 'tt5-caldav-calendar'),
					value: offsetDays,
					min: -365,
					max: 365,
					onChange: function (value) { setAttributes({ offsetDays: value }); }
				}),
				el(RangeControl, {
					label: __('Zeitraum in Tagen', 'tt5-caldav-calendar'),
					value: days,
					min: 1,
					max: 730,
					onChange: function (value) { setAttributes({ days: value }); }
				}),
				el(RangeControl, {
					label: __('Maximale Terminanzahl', 'tt5-caldav-calendar'),
					value: maxEvents,
					min: 1,
					max: 100,
					onChange: function (value) { setAttributes({ maxEvents: value }); }
				}),
				el(RangeControl, {
					label: __('Vorschautermine im Editor', 'tt5-caldav-calendar'),
					help: __('Ein Termin ist jeweils direkt bearbeitbar; weitere Termine werden wie in der Abfrageschleife als Vorschau gezeigt.', 'tt5-caldav-calendar'),
					value: editorPreviewCount,
					min: 1,
					max: 8,
					onChange: function (value) { setAttributes({ editorPreviewCount: value }); }
				}),
				el(TextControl, {
					label: __('Text ohne Treffer', 'tt5-caldav-calendar'),
					value: emptyMessage,
					onChange: function (value) { setAttributes({ emptyMessage: value }); }
				}),
				el(Button, {
					variant: 'secondary',
					onClick: function () { setRefreshKey(refreshKey + 1); },
					disabled: !calendarId || eventsLoading
				}, __('Vorschau aktualisieren', 'tt5-caldav-calendar')),
				el('p', {}, el('a', {
					href: (window.tt5CaldavEditor || {}).settingsUrl || '#',
					target: '_blank',
					rel: 'noreferrer'
				}, __('Kalenderkonten verwalten', 'tt5-caldav-calendar')))
			)
		);

		var status = null;
		if (calendarsLoading || eventsLoading) {
			status = el('div', { className: 'tt5-caldav-editor__loading' }, el(Spinner), ' ', __('CalDAV-Termine werden geladen …', 'tt5-caldav-calendar'));
		} else if (error) {
			status = el(Notice, { status: 'error', isDismissible: false }, error);
		} else if (!calendars.length) {
			status = el(Notice, { status: 'warning', isDismissible: false },
				__('Es ist noch kein CalDAV-Kalender eingerichtet. Die Termin-Vorlage kann bereits gestaltet werden. ', 'tt5-caldav-calendar'),
				el('a', {
					href: (window.tt5CaldavEditor || {}).settingsUrl || '#',
					target: '_blank',
					rel: 'noreferrer'
				}, __('Zu den Einstellungen', 'tt5-caldav-calendar'))
			);
		} else if (!calendarId) {
			status = el(Notice, { status: 'info', isDismissible: false }, __('Bitte rechts einen Kalender auswählen. Bis dahin werden Beispieldaten gezeigt.', 'tt5-caldav-calendar'));
		} else if (!events.length) {
			status = el(Notice, { status: 'info', isDismissible: false }, emptyMessage + ' ' + __('Die Vorlage bleibt mit Beispieldaten bearbeitbar.', 'tt5-caldav-calendar'));
		}

		var editorContext = {};
		editorContext[EVENTS_CONTEXT] = events;
		editorContext[LOADING_CONTEXT] = eventsLoading;
		editorContext[ERROR_CONTEXT] = error;
		editorContext[PREVIEW_COUNT_CONTEXT] = editorPreviewCount;

		return el(Fragment, {},
			inspector,
			el('div', blockProps,
				status,
				el(BlockContextProvider, { value: editorContext },
					el(InnerBlocks, {
						allowedBlocks: ['tt5-caldav/event-template'],
						template: loopTemplate,
						templateLock: 'all'
					})
				)
			)
		);
	}

	function EventTemplateInnerBlocks() {
		var innerBlocksProps = useInnerBlocksProps(
			{ className: 'tt5-caldav-event' },
			{
				template: eventFieldsTemplate,
				templateLock: false,
				__unstableDisableLayoutClassNames: true
			}
		);
		return el('article', innerBlocksProps);
	}

	function EventTemplateBlockPreview(props) {
		var blockPreviewProps = useBlockPreview({
			blocks: props.blocks,
			props: { className: 'tt5-caldav-event' }
		});
		var style = Object.assign({}, blockPreviewProps.style || {});
		if (props.isHidden) {
			style.display = 'none';
		}
		function activate(event) {
			if (event && event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') {
				return;
			}
			if (event && event.preventDefault) {
				event.preventDefault();
			}
			props.setActiveEventId(props.eventId);
		}
		return el('article', Object.assign({}, blockPreviewProps, {
			tabIndex: 0,
			role: 'button',
			'aria-label': __('Diesen Termin als bearbeitbare Vorschau aktivieren', 'tt5-caldav-calendar'),
			onClick: activate,
			onKeyDown: activate,
			style: style
		}));
	}

	function EventTemplateEdit(props) {
		var context = props.context || {};
		var realEvents = context[EVENTS_CONTEXT] || [];
		var previewCount = Math.max(1, Math.min(8, context[PREVIEW_COUNT_CONTEXT] || 3));
		var displayEvents = realEvents.length ? realEvents.slice(0, previewCount) : [sampleEvent];
		var activeState = useState();
		var activeEventId = activeState[0];
		var setActiveEventId = activeState[1];
		var blocks = useSelect(function (select) {
			return select('core/block-editor').getBlocks(props.clientId);
		}, [props.clientId]);
		var blockContexts = useMemo(function () {
			return displayEvents.map(function (event, index) {
				return {
					id: String(event.uid || 'event') + ':' + String(event.startTimestamp || index) + ':' + index,
					event: event
				};
			});
		}, [displayEvents]);
		var selectedId = activeEventId;
		if (!selectedId || !blockContexts.some(function (item) { return item.id === selectedId; })) {
			selectedId = blockContexts[0] ? blockContexts[0].id : '';
		}
		var blockProps = useBlockProps({
			className: joinClasses('tt5-caldav-loop__items', props.__unstableLayoutClassNames)
		});

		return el(Fragment, {},
			el(InspectorControls, {},
				el(PanelBody, { title: __('Termin-Vorlage', 'tt5-caldav-calendar'), initialOpen: true },
					el('p', {}, __('Alle Blöcke innerhalb dieser Vorlage werden für jeden gefundenen Termin wiederholt. Normale WordPress-Blöcke und die CalDAV-Terminblöcke können frei kombiniert werden.', 'tt5-caldav-calendar'))
				)
			),
			el('div', blockProps,
				blockContexts.map(function (blockContext) {
					var eventContext = {};
					eventContext[EVENT_CONTEXT] = blockContext.event;
					var isActive = blockContext.id === selectedId;
					return el(BlockContextProvider, { key: blockContext.id, value: eventContext },
						isActive ? el(EventTemplateInnerBlocks) : null,
						el(EventTemplateBlockPreview, {
							blocks: blocks,
							eventId: blockContext.id,
							setActiveEventId: setActiveEventId,
							isHidden: isActive
						})
					);
				})
			)
		);
	}

	registerBlockType('tt5-caldav/calendar-loop', {
		apiVersion: 3,
		title: __('CalDAV-Terminschleife', 'tt5-caldav-calendar'),
		description: __('Fragt einen CalDAV-Kalender ab und enthält eine frei gestaltbare Termin-Vorlage.', 'tt5-caldav-calendar'),
		icon: 'calendar-alt',
		category: 'tt5-caldav',
		attributes: {
			calendarId: { type: 'string', default: '' },
			days: { type: 'integer', default: 30 },
			offsetDays: { type: 'integer', default: 0 },
			maxEvents: { type: 'integer', default: 20 },
			editorPreviewCount: { type: 'integer', default: 3 },
			emptyMessage: { type: 'string', default: __('Keine Termine im gewählten Zeitraum.', 'tt5-caldav-calendar') },
			editorMode: { type: 'string', default: 'template' }
		},
		supports: {
			align: ['wide', 'full'],
			anchor: true,
			html: false,
			color: { background: true, text: true, link: true, gradients: true },
			border: { color: true, radius: true, style: true, width: true },
			spacing: { margin: true, padding: true, blockGap: true },
			typography: { fontSize: true, lineHeight: true, fontFamily: true, fontWeight: true, fontStyle: true, letterSpacing: true, textTransform: true, textDecoration: true }
		},
		edit: LoopEdit,
		save: function () { return el(InnerBlocks.Content); }
	});

	registerBlockType('tt5-caldav/event-template', {
		apiVersion: 3,
		title: __('Termin-Vorlage', 'tt5-caldav-calendar'),
		description: __('Wiederholt die enthaltenen Blöcke für jeden CalDAV-Termin.', 'tt5-caldav-calendar'),
		icon: 'screenoptions',
		category: 'tt5-caldav',
		parent: ['tt5-caldav/calendar-loop'],
		usesContext: [EVENTS_CONTEXT, LOADING_CONTEXT, ERROR_CONTEXT, PREVIEW_COUNT_CONTEXT],
		attributes: {
			layout: { type: 'object', default: { type: 'grid', columnCount: 1 } }
		},
		supports: {
			html: false,
			color: { background: true, text: true, link: true, gradients: true },
			border: { color: true, radius: true, style: true, width: true },
			spacing: { margin: true, padding: true, blockGap: true },
			layout: { allowSwitching: true, allowInheriting: true },
			typography: { fontSize: true, lineHeight: true, fontFamily: true, fontWeight: true, fontStyle: true, letterSpacing: true, textTransform: true, textDecoration: true }
		},
		edit: EventTemplateEdit,
		save: function () { return el(InnerBlocks.Content); }
	});

	registerBlockType('tt5-caldav/event-title', {
		apiVersion: 3,
		title: __('Termintitel', 'tt5-caldav-calendar'),
		icon: 'heading',
		category: 'tt5-caldav',
		ancestor: ['tt5-caldav/calendar-loop'],
		usesContext: [EVENT_CONTEXT],
		supports: textSupports,
		attributes: { level: { type: 'integer', default: 3 }, linkToEvent: { type: 'boolean', default: false } },
		edit: function (props) {
			var event = eventFromProps(props);
			var level = Math.min(6, Math.max(2, props.attributes.level || 3));
			var title = event.title || __('(Ohne Titel)', 'tt5-caldav-calendar');
			var content = props.attributes.linkToEvent && event.url ? el('a', { href: event.url, onClick: function (e) { e.preventDefault(); } }, title) : title;
			return el(Fragment, {},
				el(InspectorControls, {}, el(PanelBody, { title: __('Termintitel', 'tt5-caldav-calendar') },
					el(SelectControl, {
						label: __('Überschriftenebene', 'tt5-caldav-calendar'),
						value: level,
						options: [2, 3, 4, 5, 6].map(function (number) { return { label: 'H' + number, value: number }; }),
						onChange: function (value) { props.setAttributes({ level: parseInt(value, 10) }); }
					}),
					el(ToggleControl, {
						label: __('Mit Termin-URL verlinken', 'tt5-caldav-calendar'),
						checked: props.attributes.linkToEvent,
						onChange: function (value) { props.setAttributes({ linkToEvent: value }); }
					})
				)),
				el('h' + level, useBlockProps({ className: 'tt5-caldav-event__title' }), content)
			);
		},
		save: function () { return null; }
	});

	registerBlockType('tt5-caldav/event-date', {
		apiVersion: 3,
		title: __('Termindatum', 'tt5-caldav-calendar'),
		icon: 'calendar',
		category: 'tt5-caldav',
		ancestor: ['tt5-caldav/calendar-loop'],
		usesContext: [EVENT_CONTEXT],
		supports: textSupports,
		attributes: { format: { type: 'string', default: 'j. F Y' }, showEnd: { type: 'boolean', default: false }, separator: { type: 'string', default: ' – ' }, prefix: { type: 'string', default: '' } },
		edit: function (props) {
			var event = eventFromProps(props);
			var dateText = formatPhpTimestamp(event.startTimestamp, event.timezone, props.attributes.format || 'j. F Y', event.date);
			var endDateText = formatPhpTimestamp(event.endDisplayTimestamp, event.timezone, props.attributes.format || 'j. F Y', event.endDate);
			if (props.attributes.showEnd && endDateText && endDateText !== dateText) {
				dateText += (props.attributes.separator || ' – ') + endDateText;
			}
			return el(Fragment, {},
				el(InspectorControls, {}, el(PanelBody, { title: __('Termindatum', 'tt5-caldav-calendar') },
					el(TextControl, { label: __('PHP-Datumsformat', 'tt5-caldav-calendar'), value: props.attributes.format, onChange: function (value) { props.setAttributes({ format: value }); } }),
					el(TextControl, { label: __('Präfix', 'tt5-caldav-calendar'), value: props.attributes.prefix, onChange: function (value) { props.setAttributes({ prefix: value }); } }),
					el(ToggleControl, { label: __('Enddatum anzeigen', 'tt5-caldav-calendar'), checked: props.attributes.showEnd, onChange: function (value) { props.setAttributes({ showEnd: value }); } }),
					el(TextControl, { label: __('Trennzeichen', 'tt5-caldav-calendar'), value: props.attributes.separator, onChange: function (value) { props.setAttributes({ separator: value }); } })
				)),
				el('p', useBlockProps({ className: 'tt5-caldav-event__date' }), (props.attributes.prefix || '') + dateText)
			);
		},
		save: function () { return null; }
	});

	registerBlockType('tt5-caldav/event-time', {
		apiVersion: 3,
		title: __('Terminzeit', 'tt5-caldav-calendar'),
		icon: 'clock',
		category: 'tt5-caldav',
		ancestor: ['tt5-caldav/calendar-loop'],
		usesContext: [EVENT_CONTEXT],
		supports: textSupports,
		attributes: { format: { type: 'string', default: 'H:i' }, separator: { type: 'string', default: ' – ' }, prefix: { type: 'string', default: '' }, hideAllDay: { type: 'boolean', default: false }, allDayLabel: { type: 'string', default: __('Ganztägig', 'tt5-caldav-calendar') } },
		edit: function (props) {
			var event = eventFromProps(props);
			var startTimeText = formatPhpTimestamp(event.startTimestamp, event.timezone, props.attributes.format || 'H:i', event.time);
			var endTimeText = formatPhpTimestamp(event.endTimestamp, event.timezone, props.attributes.format || 'H:i', event.endTime);
			var timeText = event.allDay ? (props.attributes.allDayLabel || __('Ganztägig', 'tt5-caldav-calendar')) : startTimeText + ((endTimeText && endTimeText !== startTimeText) ? (props.attributes.separator || ' – ') + endTimeText : '');
			if (event.allDay && props.attributes.hideAllDay) {
				timeText = __('Terminzeit wird bei ganztägigen Terminen ausgeblendet.', 'tt5-caldav-calendar');
			}
			return el(Fragment, {},
				el(InspectorControls, {}, el(PanelBody, { title: __('Terminzeit', 'tt5-caldav-calendar') },
					el(TextControl, { label: __('PHP-Zeitformat', 'tt5-caldav-calendar'), value: props.attributes.format, onChange: function (value) { props.setAttributes({ format: value }); } }),
					el(TextControl, { label: __('Präfix', 'tt5-caldav-calendar'), value: props.attributes.prefix, onChange: function (value) { props.setAttributes({ prefix: value }); } }),
					el(TextControl, { label: __('Trennzeichen', 'tt5-caldav-calendar'), value: props.attributes.separator, onChange: function (value) { props.setAttributes({ separator: value }); } }),
					el(ToggleControl, { label: __('Bei ganztägigen Terminen ausblenden', 'tt5-caldav-calendar'), checked: props.attributes.hideAllDay, onChange: function (value) { props.setAttributes({ hideAllDay: value }); } }),
					el(TextControl, { label: __('Text für ganztägig', 'tt5-caldav-calendar'), value: props.attributes.allDayLabel, onChange: function (value) { props.setAttributes({ allDayLabel: value }); } })
				)),
				el('p', useBlockProps({ className: joinClasses('tt5-caldav-event__time', event.allDay && props.attributes.hideAllDay ? 'tt5-caldav-editor__empty-field' : '') }), (props.attributes.prefix || '') + timeText)
			);
		},
		save: function () { return null; }
	});

	registerBlockType('tt5-caldav/event-location', {
		apiVersion: 3,
		title: __('Terminort', 'tt5-caldav-calendar'),
		icon: 'location',
		category: 'tt5-caldav',
		ancestor: ['tt5-caldav/calendar-loop'],
		usesContext: [EVENT_CONTEXT],
		supports: textSupports,
		attributes: { prefix: { type: 'string', default: '' } },
		edit: function (props) {
			var event = eventFromProps(props);
			var location = event.location || __('Kein Terminort vorhanden', 'tt5-caldav-calendar');
			return el(Fragment, {},
				el(InspectorControls, {}, el(PanelBody, { title: __('Terminort', 'tt5-caldav-calendar') },
					el(TextControl, { label: __('Präfix', 'tt5-caldav-calendar'), value: props.attributes.prefix, onChange: function (value) { props.setAttributes({ prefix: value }); } })
				)),
				el('p', useBlockProps({ className: joinClasses('tt5-caldav-event__location', event.location ? '' : 'tt5-caldav-editor__empty-field') }), (props.attributes.prefix || '') + location)
			);
		},
		save: function () { return null; }
	});

	registerBlockType('tt5-caldav/event-description', {
		apiVersion: 3,
		title: __('Terminbeschreibung', 'tt5-caldav-calendar'),
		icon: 'text-page',
		category: 'tt5-caldav',
		ancestor: ['tt5-caldav/calendar-loop'],
		usesContext: [EVENT_CONTEXT],
		supports: textSupports,
		attributes: { maxLength: { type: 'integer', default: 0 } },
		edit: function (props) {
			var event = eventFromProps(props);
			var description = event.description || __('Keine Terminbeschreibung vorhanden', 'tt5-caldav-calendar');
			if (props.attributes.maxLength > 0 && description.length > props.attributes.maxLength) {
				description = description.substring(0, props.attributes.maxLength) + '…';
			}
			return el(Fragment, {},
				el(InspectorControls, {}, el(PanelBody, { title: __('Terminbeschreibung', 'tt5-caldav-calendar') },
					el(RangeControl, { label: __('Maximale Zeichen; 0 = vollständig', 'tt5-caldav-calendar'), value: props.attributes.maxLength, min: 0, max: 2000, onChange: function (value) { props.setAttributes({ maxLength: value }); } })
				)),
				el('p', useBlockProps({ className: joinClasses('tt5-caldav-event__description', event.description ? '' : 'tt5-caldav-editor__empty-field') }), description)
			);
		},
		save: function () { return null; }
	});

	registerBlockType('tt5-caldav/event-link', {
		apiVersion: 3,
		title: __('Terminlink', 'tt5-caldav-calendar'),
		icon: 'admin-links',
		category: 'tt5-caldav',
		ancestor: ['tt5-caldav/calendar-loop'],
		usesContext: [EVENT_CONTEXT],
		supports: textSupports,
		attributes: { label: { type: 'string', default: __('Termin öffnen', 'tt5-caldav-calendar') }, newTab: { type: 'boolean', default: false } },
		edit: function (props) {
			var event = eventFromProps(props);
			var label = props.attributes.label || __('Termin öffnen', 'tt5-caldav-calendar');
			return el(Fragment, {},
				el(InspectorControls, {}, el(PanelBody, { title: __('Terminlink', 'tt5-caldav-calendar') },
					el(TextControl, { label: __('Linktext', 'tt5-caldav-calendar'), value: props.attributes.label, onChange: function (value) { props.setAttributes({ label: value }); } }),
					el(ToggleControl, { label: __('In neuem Tab öffnen', 'tt5-caldav-calendar'), checked: props.attributes.newTab, onChange: function (value) { props.setAttributes({ newTab: value }); } })
				)),
				el('p', useBlockProps({ className: joinClasses('tt5-caldav-event__link', event.url ? '' : 'tt5-caldav-editor__empty-field') }),
					el('a', { href: event.url || '#', onClick: function (e) { e.preventDefault(); } }, label),
					event.url ? null : el('span', {}, ' — ' + __('keine Termin-URL vorhanden', 'tt5-caldav-calendar'))
				)
			);
		},
		save: function () { return null; }
	});
})(window.wp);
