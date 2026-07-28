(function (wp, core) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useMemo = wp.element.useMemo;
	var useState = wp.element.useState;
	var registerBlockType = wp.blocks.registerBlockType;
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
	var useSelect = wp.data.useSelect;
	var __ = wp.i18n.__;
	var EVENT_CONTEXT = core.EVENT_CONTEXT;
	var EVENTS_CONTEXT = core.EVENTS_CONTEXT;
	var LOADING_CONTEXT = core.LOADING_CONTEXT;
	var ERROR_CONTEXT = core.ERROR_CONTEXT;
	var PREVIEW_COUNT_CONTEXT = core.PREVIEW_COUNT_CONTEXT;
	var eventFieldsTemplate = core.eventFieldsTemplate;
	var sampleEvent = core.sampleEvent;
	var joinClasses = core.joinClasses;

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
		var innerBlocksProps = useInnerBlocksProps(
			{ className: 'tt5-caldav-event' },
			{
				template: eventFieldsTemplate,
				templateLock: false,
				__unstableDisableLayoutClassNames: true
			}
		);

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
						isActive ? el('article', innerBlocksProps) : null,
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
})(window.wp, window.tt5CaldavEditorCore);
