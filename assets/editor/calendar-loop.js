(function (wp, core) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;
	var registerBlockType = wp.blocks.registerBlockType;
	var createBlock = wp.blocks.createBlock;
	var cloneBlock = wp.blocks.cloneBlock;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var BlockContextProvider = wp.blockEditor.BlockContextProvider;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var RangeControl = wp.components.RangeControl;
	var TextControl = wp.components.TextControl;
	var Notice = wp.components.Notice;
	var Spinner = wp.components.Spinner;
	var Button = wp.components.Button;
	var apiFetch = wp.apiFetch;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var __ = wp.i18n.__;
	var EVENTS_CONTEXT = core.EVENTS_CONTEXT;
	var LOADING_CONTEXT = core.LOADING_CONTEXT;
	var ERROR_CONTEXT = core.ERROR_CONTEXT;
	var PREVIEW_COUNT_CONTEXT = core.PREVIEW_COUNT_CONTEXT;
	var eventFieldsTemplate = core.eventFieldsTemplate;
	var loopTemplate = core.loopTemplate;
	var createBlocksFromTemplate = core.createBlocksFromTemplate;

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
		var forceRefresh = useRef(false);
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
			var refreshRequested = forceRefresh.current;
			forceRefresh.current = false;
			var path = '/tt5-caldav/v1/events?calendar_id=' + encodeURIComponent(calendarId) +
				'&days=' + encodeURIComponent(days) +
				'&offset=' + encodeURIComponent(offsetDays) +
				'&limit=' + encodeURIComponent(maxEvents) +
				(refreshRequested ? '&refresh=true' : '');
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
					onClick: function () {
						forceRefresh.current = true;
						setRefreshKey(function (value) { return value + 1; });
					},
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
})(window.wp, window.tt5CaldavEditorCore);
