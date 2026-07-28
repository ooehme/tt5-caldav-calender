(function (wp, core) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var RangeControl = wp.components.RangeControl;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var __ = wp.i18n.__;
	var EVENT_CONTEXT = core.EVENT_CONTEXT;
	var textSupports = core.textSupports;
	var joinClasses = core.joinClasses;
	var eventFromProps = core.eventFromProps;
	var formatPhpTimestamp = core.formatPhpTimestamp;

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
})(window.wp, window.tt5CaldavEditorCore);
