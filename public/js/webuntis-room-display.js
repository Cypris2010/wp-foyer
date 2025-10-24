(function () {
	'use strict';

	// The widget mounts on every node that matches this selector.
	var SELECTOR = '.foyer-webuntis-room-display';
	// Track per-node runtime state so timers and fetches can be cleaned up.
	var instances = new WeakMap();
	// Limit how many future lessons are shown in the upcoming list.
	var MAX_UPCOMING = 3;
	// Treat back-to-back lessons with up to five minutes gap as one block.
	var MERGE_GAP_MS = 5 * 60 * 1000;
	// Some browsers do not ship AbortController; feature-detect before using it.
	var supportsAbort = typeof AbortController !== 'undefined';
	// Fallback filename that ships with the script for local testing.
	var FALLBACK_FILENAME = 'raeume_ganzer_tag.txt';

	// Determine the directory URL the script was loaded from.
	function getScriptBase() {
		var candidates = [];
		if (document.currentScript && document.currentScript.src) {
			candidates.push(document.currentScript.src);
		}
		var scripts = document.getElementsByTagName('script');
		for (var i = scripts.length - 1; i >= 0; i--) {
			var src = scripts[i].src;
			if (src && src.indexOf('webuntis-room-display.js') !== -1) {
				candidates.push(src);
				break;
			}
		}
		for (var j = 0; j < candidates.length; j++) {
			var url = candidates[j];
			if (!url) {
				continue;
			}
			var withoutHash = url.split('#')[0];
			var withoutQuery = withoutHash.split('?')[0];
			var index = withoutQuery.lastIndexOf('/');
			if (index !== -1) {
				return withoutQuery.slice(0, index + 1);
			}
		}
		return '';
	}

	// Resolve the default local data source path once on startup.
	var fallbackSource = (function () {
		var base = getScriptBase();
		if (!base) {
			return '';
		}
		return base + FALLBACK_FILENAME;
	})();

	// Safely parse JSON stored in data attributes and fall back to an empty list.
	function parseJsonAttribute(value) {
		if (!value) {
			return [];
		}
		try {
			var parsed = JSON.parse(value);
			return Array.isArray(parsed) ? parsed : [];
		} catch (err) {
			return [];
		}
	}

	// Normalise room labels to make comparisons whitespace-insensitive.
	function normaliseRoom(value) {
		return (value || '').replace(/\s+/g, ' ').trim();
	}

	// Detect generic lesson markers that should not be surfaced as remarks.
	function isGenericLessonType(value) {
		return (value || '').trim().toLowerCase() === 'unterricht';
	}

	function normaliseDetail(value) {
		return (value || '').replace(/\s+/g, ' ').trim();
	}

	function normaliseSignature(value) {
		var detail = normaliseDetail(value).toLowerCase();
		if (!detail) {
			return '';
		}
		return detail.replace(/[^a-z0-9]/g, '');
	}

	function collectSignatureTokens(value) {
		var trimmed = normaliseDetail(value);
		if (!trimmed) {
			return [];
		}
		var prepared = trimmed.replace(/[,;•/&]+/g, ' ');
		var rough = prepared.split(/\s+/);
		var tokens = [];
		for (var i = 0; i < rough.length; i++) {
			var current = normaliseSignature(rough[i]);
			if (!current) {
				continue;
			}
			var next = null;
			if (i + 1 < rough.length) {
				next = normaliseSignature(rough[i + 1]);
				if (!next) {
					next = null;
				}
			}
			var shouldCombine = false;
			if (next) {
				if (/^[0-9]+$/.test(current) && /^[a-z]+$/.test(next)) {
					shouldCombine = true;
				} else if (/^[a-z]+$/.test(current) && /^[0-9]+$/.test(next)) {
					shouldCombine = true;
				}
			}
			if (shouldCombine) {
				tokens.push(current + next);
				i++;
				continue;
			}
			tokens.push(current);
		}
		var unique = [];
		for (var j = 0; j < tokens.length; j++) {
			if (unique.indexOf(tokens[j]) === -1) {
				unique.push(tokens[j]);
			}
		}
		unique.sort();
		return unique;
	}

	function normaliseListSignature(value) {
		var tokens = collectSignatureTokens(value);
		return tokens.join('|');
	}

	function ensureCommaSpacing(value) {
		if (!value) {
			return '';
		}
		return value.replace(/,\s*/g, ', ').trim();
	}

	function applyColumnScaling(node) {
		var grid = node.querySelector('.foyer-webuntis-room-display__grid');
		if (!grid) {
			return;
		}
		var schedule = (typeof window !== 'undefined' && window.requestAnimationFrame) ? window.requestAnimationFrame.bind(window) : function (callback) {
			return setTimeout(callback, 16);
		};
		schedule(function () {
			var columns = grid.querySelectorAll('.foyer-webuntis-room-display__column');
			if (!columns.length) {
				return;
			}
			var baseline = 480;
			var minScale = 0.5;
			var maxScale = 1.4;
			for (var i = 0; i < columns.length; i++) {
				var column = columns[i];
				var rect = column.getBoundingClientRect();
				if (!rect.height) {
					column.style.removeProperty('--foyer-column-scale');
					column.classList.remove('foyer-webuntis-room-display__column--spacious');
					continue;
				}
				var heightRatio = rect.height / baseline;
				// Dampening keeps tall displays from inflating the type too aggressively.
				var scale = heightRatio <= 1 ? heightRatio : 1 + (heightRatio - 1) * 0.35;
				if (scale < minScale) {
					scale = minScale;
				} else if (scale > maxScale) {
					scale = maxScale;
				}
				column.style.setProperty('--foyer-column-scale', scale.toFixed(3));
				if (scale >= 1.15) {
					column.classList.add('foyer-webuntis-room-display__column--spacious');
				} else {
					column.classList.remove('foyer-webuntis-room-display__column--spacious');
				}
			}
		});
	}

	function buildMergeKey(entry) {
		return [
			entry ? normaliseRoom(entry.room) : '',
			normaliseListSignature(entry ? entry.teachers : ''),
			normaliseListSignature(entry ? entry.classes : ''),
			normaliseSignature(entry ? entry.subject : '')
		].join('||');
	}

	// Convert the WebUntis timestamp format into a Date object.
	function parseDate(raw) {
		if (!raw || raw === '-' ) {
			return null;
		}
		var normalised = String(raw).trim().replace(' ', 'T');
		var date = new Date(normalised);
		return isNaN(date.getTime()) ? null : date;
	}

	// Format a single clock time in the configured locale/timezone;
	// fall back to a manual formatter if Intl fails (e.g. on old browsers).
	function formatTime(date, locale, timezone) {
		try {
			return new Intl.DateTimeFormat(locale || undefined, {
				hour: '2-digit',
				minute: '2-digit',
				timeZone: timezone || undefined,
				hour12: false
			}).format(date);
		} catch (err) {
			var hours = date.getHours();
			var minutes = date.getMinutes();
			return (hours < 10 ? '0' + hours : '' + hours) + ':' + (minutes < 10 ? '0' + minutes : '' + minutes);
		}
	}

	// Render a human readable time span such as "08:00 – 09:30".
	function formatTimeRange(start, end, locale, timezone) {
		if (!start || !end) {
			return '';
		}
		return formatTime(start, locale, timezone) + ' – ' + formatTime(end, locale, timezone);
	}

	// Determine whether two consecutive events should be merged into one block.
	function shouldMerge(prev, next) {
		if (!prev || !next) {
			return false;
		}
		if (prev.room !== next.room) {
			return false;
		}
		if (normaliseListSignature(prev.teachers) !== normaliseListSignature(next.teachers)) {
			return false;
		}
		if (normaliseListSignature(prev.classes) !== normaliseListSignature(next.classes)) {
			return false;
		}
		if (normaliseSignature(prev.subject) !== normaliseSignature(next.subject)) {
			return false;
		}
		var prevEnd = prev.endDate ? prev.endDate.getTime() : null;
		var nextStart = next.startDate ? next.startDate.getTime() : null;
		if (prevEnd === null || nextStart === null) {
			return false;
		}
		if (nextStart <= prevEnd) {
			return true;
		}
		return (nextStart - prevEnd) <= MERGE_GAP_MS;
	}

	// Merge adjacent entries that belong together so the display stays compact.
	function mergeEntries(entries) {
		if (!entries.length) {
			return entries;
		}
		entries.sort(function (a, b) {
			if (a.startDate.getTime() !== b.startDate.getTime()) {
				return a.startDate.getTime() - b.startDate.getTime();
			}
			return a.endDate.getTime() - b.endDate.getTime();
		});
		var result = [];
		var lastByKey = new Map();
		for (var i = 0; i < entries.length; i++) {
			var current = entries[i];
			var key = buildMergeKey(current);
			var previous = lastByKey.get(key) || null;
			if (!previous) {
				result.push(current);
				lastByKey.set(key, current);
				continue;
			}
			if (shouldMerge(previous, current)) {
				if (current.startDate.getTime() < previous.startDate.getTime()) {
					previous.startRaw = current.startRaw;
					previous.startDate = current.startDate;
				}
				if (current.endDate.getTime() > previous.endDate.getTime()) {
					previous.endRaw = current.endRaw;
					previous.endDate = current.endDate;
				}
				previous.type = mergeText(previous.type, current.type);
				previous.textSubstitute = mergeText(previous.textSubstitute, current.textSubstitute);
				previous.textBooking = mergeText(previous.textBooking, current.textBooking);
				previous.title = mergeText(previous.title, current.title);
				previous.cancelled = !!(previous.cancelled || current.cancelled);
				lastByKey.set(key, previous);
			} else {
				result.push(current);
				lastByKey.set(key, current);
			}
		}
		return result;
	}

	function mergeText(first, second) {
		var primary = normaliseDetail(first);
		var secondary = normaliseDetail(second);
		if (!primary) {
			return secondary ? second : first;
		}
		if (!secondary) {
			return first;
		}
		var lowerPrimary = primary.toLowerCase();
		var lowerSecondary = secondary.toLowerCase();
		if (lowerSecondary && lowerPrimary.indexOf(lowerSecondary) !== -1) {
			return first;
		}
		return first + ' • ' + second;
	}

	// Parse the raw WebUntis export (pipe-separated text) into structured events.
	function parseUntis(text) {
		var lines = String(text || '').split(/\r?\n/);
		var entries = [];

		for (var i = 0; i < lines.length; i++) {
			var line = lines[i].trim();
			if (!line) {
				continue;
			}
			var parts = line.split('|');
			var roomOriginal = parts[0] ? parts[0].trim() : '';
			var room = normaliseRoom(roomOriginal);
			if (!room) {
				continue;
			}

			var statusRaw = parts[7] ? parts[7].trim() : '';
			var statusNormalised = statusRaw.toLowerCase();
			var isCancelled = statusNormalised === 'cancelled' || statusNormalised === 'gone';

			var startRaw = parts[1] ? parts[1].trim() : '';
			var endRaw = parts[2] ? parts[2].trim() : '';
			var startDate = parseDate(startRaw);
			var endDate = parseDate(endRaw);
			if (!startDate || !endDate) {
				continue;
			}

			var roomsDetail = parts[3] ? parts[3].trim() : '';
			var teachersDetail = parts[4] ? parts[4].trim() : '';
			var classesDetail = ensureCommaSpacing(parts[5] ? parts[5].trim() : '');
			var subjectDetail = parts[6] ? parts[6].trim() : '';
			var typeDetail = parts[8] ? parts[8].trim() : '';
			var textSubstitute = parts[9] ? parts[9].trim() : '';
			var textBooking = parts[10] ? parts[10].trim() : '';
			var titleDetail = [subjectDetail, classesDetail].filter(Boolean).join(' • ');

			entries.push({
				roomOriginal: roomOriginal || room,
				room: room,
				startRaw: startRaw,
				endRaw: endRaw,
				startDate: startDate,
				endDate: endDate,
				rooms: roomsDetail,
				teachers: teachersDetail,
				classes: classesDetail,
				subject: subjectDetail,
				title: titleDetail,
				status: statusRaw,
				type: typeDetail,
				textSubstitute: textSubstitute,
				textBooking: textBooking,
				cancelled: isCancelled
			});
		}

		return mergeEntries(entries);
	}

	// Group parsed entries by the requested rooms and compute current/upcoming events.
	function buildRoomData(entries, requested) {
		var now = new Date();
		var byRoom = new Map();
		var order = [];

		for (var i = 0; i < requested.length; i++) {
			var displayName = requested[i];
			var key = normaliseRoom(displayName);
			if (!key) {
				continue;
			}
			if (!byRoom.has(key)) {
				byRoom.set(key, []);
				order.push({ key: key, display: displayName });
			}
		}

		for (var j = 0; j < entries.length; j++) {
			var entry = entries[j];
			if (entry.endDate.getTime() <= now.getTime()) {
				continue;
			}
			if (!byRoom.has(entry.room)) {
				continue;
			}
			byRoom.get(entry.room).push(entry);
		}

		var result = [];
		for (var k = 0; k < order.length; k++) {
			var descriptor = order[k];
			var list = byRoom.get(descriptor.key) || [];
			list.sort(function (a, b) {
				if (a.startDate.getTime() !== b.startDate.getTime()) {
					return a.startDate.getTime() - b.startDate.getTime();
				}
				return a.endDate.getTime() - b.endDate.getTime();
			});
			var current = [];
			var upcoming = [];
			var nowTime = now.getTime();

			for (var idx = 0; idx < list.length; idx++) {
				var item = list[idx];
				var startTime = item.startDate.getTime();
				var endTime = item.endDate.getTime();
				if (startTime <= nowTime && endTime > nowTime) {
					current.push(item);
				} else if (startTime > nowTime) {
					upcoming.push(item);
				}
			}

			result.push({
				room: descriptor.display,
				key: descriptor.key,
				current: current,
				upcoming: upcoming
			});
		}

		return result;
	}

	// Remove every child node so the grid can be rebuilt from scratch.
	function clearChildren(node) {
		while (node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	// Utility to create a <p> element that shows a label/value pair when data exists.
	function createLine(label, value, className) {
		if (!value) {
			return null;
		}
		var line = document.createElement('p');
		line.className = className;
		line.textContent = label ? (label + ': ' + value) : value;
		return line;
	}

	// Build the detail block for the currently running event.
	function buildDetails(event, labels, locale, timezone) {
		var container = document.createElement('div');
		container.className = 'foyer-webuntis-room-display__details';

		var heading = document.createElement('div');
		heading.className = 'foyer-webuntis-room-display__heading';

		var time = document.createElement('p');
		time.className = 'foyer-webuntis-room-display__time';
		time.textContent = formatTimeRange(event.startDate, event.endDate, locale, timezone);
		heading.appendChild(time);

		if (event.cancelled) {
			container.classList.add('is-cancelled');
		}

		var titleLine = createLine('', event.title, 'foyer-webuntis-room-display__title');
		if (titleLine) {
		heading.appendChild(titleLine);
		}

		container.appendChild(heading);

		var teacherLine = createLine(labels.teachers, event.teachers, 'foyer-webuntis-room-display__line');
		if (teacherLine) {
			container.appendChild(teacherLine);
		}

		var remarks = [];
		if (event.type && event.type.length && !isGenericLessonType(event.type)) {
			remarks.push(event.type);
		}
		if (event.textSubstitute && event.textSubstitute.length) {
			remarks.push(event.textSubstitute);
		}
		if (event.textBooking && event.textBooking.length) {
			if (!isGenericLessonType(event.textBooking)) {
				remarks.push(event.textBooking);
			}
		}
		if (remarks.length) {
			var remarkLine = createLine(labels.remarks, remarks.join(' • '), 'foyer-webuntis-room-display__line');
			if (remarkLine) {
				container.appendChild(remarkLine);
			}
		}

		return container;
	}

	// Render the list of upcoming bookings underneath the current event.
	function buildUpcomingList(upcoming, labels, locale, timezone) {
		var list = document.createElement('ul');
		list.className = 'foyer-webuntis-room-display__list';

		for (var i = 0; i < upcoming.length && i < MAX_UPCOMING; i++) {
			var event = upcoming[i];
			var item = document.createElement('li');
			item.className = 'foyer-webuntis-room-display__list-item';
			if (event.cancelled) {
				item.classList.add('is-cancelled');
			}

			var time = document.createElement('div');
			time.className = 'foyer-webuntis-room-display__list-time';
			time.textContent = formatTimeRange(event.startDate, event.endDate, locale, timezone);
			item.appendChild(time);

			var info = document.createElement('div');
			info.className = 'foyer-webuntis-room-display__list-info';

			var title = event.title || event.subject || event.textBooking || event.rooms || '';
			if (title) {
				var titleNode = document.createElement('div');
				titleNode.className = 'foyer-webuntis-room-display__list-subject';
				titleNode.textContent = title;
				info.appendChild(titleNode);
			}

			var subtitleParts = [];
			if (event.cancelled && labels.cancelled) {
				subtitleParts.push(labels.cancelled);
			}
			if (event.teachers) {
				subtitleParts.push(event.teachers);
			}
			if (subtitleParts.length === 0) {
				var fallbackMeta = event.textSubstitute || '';
				if (!fallbackMeta && event.textBooking && !isGenericLessonType(event.textBooking)) {
					fallbackMeta = event.textBooking;
				}
				if (!fallbackMeta && event.type && !isGenericLessonType(event.type)) {
					fallbackMeta = event.type;
				}
				if (!fallbackMeta && event.classes) {
					fallbackMeta = event.classes;
				}
				if (fallbackMeta) {
					subtitleParts.push(fallbackMeta);
				}
			}
			if (subtitleParts.length) {
				var subtitle = document.createElement('div');
				subtitle.className = 'foyer-webuntis-room-display__list-meta';
				subtitle.textContent = subtitleParts.join(' • ');
				info.appendChild(subtitle);
			}

			item.appendChild(info);
			list.appendChild(item);
		}

		return list;
	}

	// Update the "last refreshed" badge that sits in each column header.
	function updateTimestamp(node, labels, locale, timezone, timestamp) {
		var updatedNode = node.querySelector('.foyer-webuntis-room-display__updated');
		if (!updatedNode || !timestamp) {
			return;
		}
		var formattedTime = formatTime(timestamp, locale, timezone);
		if (labels.updated && labels.updated.indexOf('%s') !== -1) {
			updatedNode.textContent = labels.updated.replace('%s', formattedTime);
		} else if (labels.updated) {
			updatedNode.textContent = labels.updated + ' ' + formattedTime;
		} else {
			updatedNode.textContent = formattedTime;
		}
	}

	// Manual zero-padding helper for fallback formatters.
	function pad(number) {
		return number < 10 ? '0' + number : String(number);
	}

	// Format a calendar date using Intl if available, otherwise fall back to DD.MM.YYYY.
	function formatDate(date, locale, timezone) {
		try {
			return new Intl.DateTimeFormat(locale || undefined, {
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				timeZone: timezone || undefined
			}).format(date);
		} catch (err) {
			return pad(date.getDate()) + '.' + pad(date.getMonth() + 1) + '.' + date.getFullYear();
		}
	}

	// Format a time string (HH:MM) for the live clock shown on the widget.
	function formatClockTime(date, locale, timezone) {
		try {
			return new Intl.DateTimeFormat(locale || undefined, {
				hour: '2-digit',
				minute: '2-digit',
				hour12: false,
				timeZone: timezone || undefined
			}).format(date);
		} catch (err) {
			return pad(date.getHours()) + ':' + pad(date.getMinutes());
		}
	}

	// Kick off the live clock displayed above the schedule.
	function startClock(node, state) {
		var container = node.querySelector('.foyer-webuntis-room-display__clock');
		if (!container) {
			return;
		}
		if (state.clockTimer) {
			clearTimeout(state.clockTimer);
			state.clockTimer = null;
		}

		var dateNode = container.querySelector('.foyer-webuntis-room-display__clock-date');
		var timeNode = container.querySelector('.foyer-webuntis-room-display__clock-time');

		var formatDateAttr = container.getAttribute('data-format-date');
		var formatTimeAttr = container.getAttribute('data-format-time');

		function applyFormats(now) {
			if (dateNode) {
				if (formatDateAttr) {
					dateNode.textContent = formatCustom(now, formatDateAttr, state.timezone);
				} else {
					dateNode.textContent = formatDate(now, state.locale, state.timezone);
				}
			}
			if (timeNode) {
				if (formatTimeAttr) {
					timeNode.textContent = formatCustom(now, formatTimeAttr, state.timezone);
				} else {
					timeNode.textContent = formatClockTime(now, state.locale, state.timezone);
				}
			}
		}

		function schedule() {
			var now = new Date();
			applyFormats(now);
			var delay = 60000 - ((now.getSeconds() * 1000) + now.getMilliseconds());
			state.clockTimer = setTimeout(schedule, delay > 0 ? delay : 60000);
		}

		schedule();
	}

	// Interpret a small custom formatting syntax (Y, m, d, H, i, ...).
	function formatCustom(date, pattern, timezone) {
		var components = dateWithZone(date, timezone);
		var replacements = {
			'Y': String(components.year),
			'y': String(components.year).slice(-2),
			'm': pad(components.month),
			'n': String(components.month),
			'd': pad(components.day),
			'j': String(components.day),
			'H': pad(components.hour),
			'G': String(components.hour),
			'i': pad(components.minute)
		};

		return pattern.replace(/Y|y|m|n|d|j|H|G|i/g, function (token) {
			return replacements[token] !== undefined ? replacements[token] : token;
		});
	}

	// Read date components for a specific timezone, falling back to local time.
	function dateWithZone(date, timezone) {
		var fallback = {
			year: date.getFullYear(),
			month: date.getMonth() + 1,
			day: date.getDate(),
			hour: date.getHours(),
			minute: date.getMinutes()
		};

		if (!timezone || !Intl || !Intl.DateTimeFormat) {
			return fallback;
		}
		try {
			var parts = new Intl.DateTimeFormat('en-US', {
				timeZone: timezone,
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit',
				hour12: false
			}).formatToParts(date);
			var values = {};
			for (var i = 0; i < parts.length; i++) {
				values[parts[i].type] = parts[i].value;
			}

			return {
				year: values.year ? Number(values.year) : fallback.year,
				month: values.month ? Number(values.month) : fallback.month,
				day: values.day ? Number(values.day) : fallback.day,
				hour: values.hour ? Number(values.hour) : fallback.hour,
				minute: values.minute ? Number(values.minute) : fallback.minute
			};
		} catch (err) {
			return fallback;
		}
	}

	function stopAllRotations(state) {
		if (!state || !state.rotationTimers) {
			return;
		}
		state.rotationTimers.forEach(function (timer) {
			clearInterval(timer);
		});
		state.rotationTimers.clear();
	}

	function setBlockVisibility(node, isVisible) {
		if (!node) {
			return;
		}
		node.classList.toggle('is-visible', isVisible);
		node.hidden = !isVisible;
		if (isVisible) {
			node.style.removeProperty('display');
		} else {
			node.style.display = 'none';
		}
		node.setAttribute('aria-hidden', isVisible ? 'false' : 'true');
	}

	function triggerOverlay(overlay, onBeforeSwitch, onAfterSwitch) {
		if (!overlay) {
			if (typeof onBeforeSwitch === 'function') {
				onBeforeSwitch();
			}
			if (typeof onAfterSwitch === 'function') {
				onAfterSwitch();
			}
			return;
		}
		overlay.classList.remove('is-active');
		// Force reflow so the animation restarts even if the class was already applied.
		void overlay.offsetWidth;
		overlay.classList.add('is-active');
		var midpointDelay = 180;
		var releaseDelay = 420;
		if (typeof onBeforeSwitch === 'function') {
			setTimeout(onBeforeSwitch, midpointDelay);
		}
		setTimeout(function () {
			overlay.classList.remove('is-active');
			if (typeof onAfterSwitch === 'function') {
				onAfterSwitch();
			}
		}, releaseDelay);
	}

	function scheduleCurrentRotation(state, roomKey, nodes) {
		if (!state || !state.rotationTimers) {
			return;
		}
		if (!nodes || !nodes.length) {
			return;
		}
		nodes.forEach(function (node, idx) {
			setBlockVisibility(node, idx === 0);
		});
		if (nodes.length === 1) {
			return;
		}
		var overlay = nodes[0].parentNode ? nodes[0].parentNode.querySelector('.foyer-webuntis-room-display__current-overlay') : null;
		var index = 0;
		var timer = setInterval(function () {
			if (!nodes.length) {
				return;
			}
			var currentIndex = index;
			var next = (index + 1) % nodes.length;
			setBlockVisibility(nodes[next], false);
			triggerOverlay(overlay, function () {
				setBlockVisibility(nodes[currentIndex], false);
			}, function () {
				setBlockVisibility(nodes[next], true);
			});
			index = next;
		}, 10000);
		state.rotationTimers.set(roomKey, timer);
	}

	function buildCurrentBlock(entries, labels, locale, timezone, cancelledGroup) {
		var block = document.createElement('div');
		block.className = 'foyer-webuntis-room-display__current';
		if (cancelledGroup) {
			block.classList.add('foyer-webuntis-room-display__current--cancelled', 'is-cancelled');
		} else {
			block.classList.add('foyer-webuntis-room-display__current--active');
		}
		for (var idx = 0; idx < entries.length; idx++) {
			var detailNode = buildDetails(entries[idx], labels, locale, timezone);
			detailNode.classList.add('foyer-webuntis-room-display__current-item');
			block.appendChild(detailNode);
		}
		return block;
	}

	// Rebuild the entire grid for the requested rooms based on the latest data.
	function render(node, rooms, labels, locale, timezone, options, state) {
		var grid = node.querySelector('.foyer-webuntis-room-display__grid');
		if (!grid) {
			return;
		}

		stopAllRotations(state);
		clearChildren(grid);

		var count = rooms.length;
		var size = Math.max(1, Math.ceil(Math.sqrt(count || 1)));
		var columns = Math.min(size, Math.max(count, 1));
		if (columns === 0) {
			columns = 1;
		}
		var rows = Math.max(1, Math.ceil(count / columns));
		grid.style.setProperty('--foyer-room-columns', String(columns));
		grid.style.setProperty('--foyer-room-rows', String(rows));

		var outdatedRoomMarkers = node.querySelectorAll('.foyer-webuntis-room-display__header .foyer-webuntis-room-display__updated');
		for (var removeIndex = 0; removeIndex < outdatedRoomMarkers.length; removeIndex++) {
			var marker = outdatedRoomMarkers[removeIndex];
			marker.parentNode.removeChild(marker);
		}

		if (!rooms.length) {
			var placeholder = document.createElement('div');
			placeholder.className = 'foyer-webuntis-room-display__placeholder';
			placeholder.textContent = labels.noRooms;
			grid.appendChild(placeholder);
			return;
		}

		var hideUpcoming = options && options.hideUpcoming;
		var hideCurrent = options && options.hideCurrent;

		for (var i = 0; i < rooms.length; i++) {
			var data = rooms[i];
			var currentEntries = Array.isArray(data.current) ? data.current.slice() : (data.current ? [data.current] : []);
			var column = document.createElement('section');
			column.className = 'foyer-webuntis-room-display__column';

			var header = document.createElement('header');
			header.className = 'foyer-webuntis-room-display__header';

			var title = document.createElement('h2');
			title.className = 'foyer-webuntis-room-display__room';
			title.textContent = data.room;
			header.appendChild(title);

				var badge = document.createElement('span');
				badge.className = 'foyer-webuntis-room-display__status';
				var occupied = currentEntries.some(function (entry) {
					return !entry.cancelled;
				});
				badge.classList.add(occupied ? 'is-occupied' : 'is-free');
				badge.textContent = occupied ? labels.occupied : labels.free;
			header.appendChild(badge);

			column.appendChild(header);

			var body = document.createElement('div');
			body.className = 'foyer-webuntis-room-display__body';

			if (!hideCurrent) {
				var currentTitle = document.createElement('h3');
				currentTitle.className = 'foyer-webuntis-room-display__section-title';
				currentTitle.textContent = labels.current;
				body.appendChild(currentTitle);
			}

			if (currentEntries.length) {
				var rotationWrapper = document.createElement('div');
				rotationWrapper.className = 'foyer-webuntis-room-display__current-wrapper';
				var rotationNodes = [];
				var activeEntries = currentEntries.filter(function (entry) {
					return !entry.cancelled;
				});
				if (activeEntries.length) {
					var activeBlock = buildCurrentBlock(activeEntries, labels, locale, timezone, false);
					rotationWrapper.appendChild(activeBlock);
					rotationNodes.push(activeBlock);
				}
				var cancelledEntries = currentEntries.filter(function (entry) {
					return !!entry.cancelled;
				});
				if (cancelledEntries.length) {
					var cancelledBlock = buildCurrentBlock(cancelledEntries, labels, locale, timezone, true);
					rotationWrapper.appendChild(cancelledBlock);
					rotationNodes.push(cancelledBlock);
				}
				if (rotationNodes.length) {
					var overlay = document.createElement('div');
					overlay.className = 'foyer-webuntis-room-display__current-overlay';
					overlay.setAttribute('aria-hidden', 'true');
					rotationWrapper.appendChild(overlay);
					body.appendChild(rotationWrapper);
					scheduleCurrentRotation(state, data.key, rotationNodes);
				}
			} else {
				var freeBlock = document.createElement('div');
				freeBlock.className = 'foyer-webuntis-room-display__current foyer-webuntis-room-display__current--free';
				var free = document.createElement('p');
				free.className = 'foyer-webuntis-room-display__free';
				free.textContent = labels.freeNow;
				freeBlock.appendChild(free);
				body.appendChild(freeBlock);
			}

			if (!hideUpcoming) {
				var upcomingTitle = document.createElement('h3');
				upcomingTitle.className = 'foyer-webuntis-room-display__section-title foyer-webuntis-room-display__section-title--upcoming';
				upcomingTitle.textContent = labels.upcoming;
				body.appendChild(upcomingTitle);

				var upcomingBlock = document.createElement('div');
				upcomingBlock.className = 'foyer-webuntis-room-display__upcoming';

				if (data.upcoming && data.upcoming.length) {
					upcomingBlock.appendChild(buildUpcomingList(data.upcoming, labels, locale, timezone));
				} else {
					var empty = document.createElement('p');
					empty.className = 'foyer-webuntis-room-display__empty';
					empty.textContent = labels.noUpcoming;
					upcomingBlock.appendChild(empty);
				}

				body.appendChild(upcomingBlock);
			}
				column.appendChild(body);
				grid.appendChild(column);
			}

			applyColumnScaling(node);
		}

	// Show an inline error message inside the widget.
	function setError(node, message) {
		var errorBox = node.querySelector('.foyer-webuntis-room-display__error');
		if (!errorBox) {
			return;
		}
		errorBox.textContent = message;
		errorBox.hidden = false;
	}

	// Hide the error area so the normal content can be shown.
	function clearError(node) {
		var errorBox = node.querySelector('.foyer-webuntis-room-display__error');
		if (!errorBox) {
			return;
		}
		errorBox.hidden = true;
		errorBox.textContent = '';
	}

	// Cancel timers and pending fetches when an instance is torn down.
	function cleanupInstance(state) {
		if (!state) {
			return;
		}
		if (state.timer) {
			clearTimeout(state.timer);
			state.timer = null;
		}
		if (state.controller && state.controller.abort) {
			state.controller.abort();
			state.controller = null;
		}
		if (state.clockTimer) {
			clearTimeout(state.clockTimer);
			state.clockTimer = null;
		}
		stopAllRotations(state);
		if (state.resizeHandler && typeof window !== 'undefined' && window.removeEventListener) {
			window.removeEventListener('resize', state.resizeHandler);
			state.resizeHandler = null;
		}
	}

	// Fetch the WebUntis export, parse it, and refresh the rendered DOM.
	function fetchAndRender(node, state) {
		if (state.timer) {
			clearTimeout(state.timer);
			state.timer = null;
		}
		if (state.controller && state.controller.abort) {
			state.controller.abort();
		}

		var controller = supportsAbort ? new AbortController() : null;
		state.controller = controller;

		fetch(state.source, {
			cache: 'no-store',
			signal: controller ? controller.signal : undefined,
			headers: { 'Accept': 'text/plain' }
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('HTTP ' + response.status);
			}
			var lastModifiedHeader = response.headers ? response.headers.get('Last-Modified') : null;
			var dateHeader = response.headers ? response.headers.get('Date') : null;
			return response.text().then(function (text) {
				return {
					text: text,
					lastModified: lastModifiedHeader,
					responseDate: dateHeader
				};
			});
		}).then(function (payload) {
			clearError(node);
			var entries = parseUntis(payload.text);
			var rooms = buildRoomData(entries, state.rooms);

			var parsedLastModified = null;
			if (payload.lastModified) {
				var headerDate = new Date(payload.lastModified);
				if (!isNaN(headerDate.getTime())) {
					parsedLastModified = headerDate;
				}
			}
			if (!parsedLastModified && payload.responseDate) {
				var fallbackDate = new Date(payload.responseDate);
				if (!isNaN(fallbackDate.getTime())) {
					parsedLastModified = fallbackDate;
				}
			}
			if (!parsedLastModified) {
				parsedLastModified = new Date();
			}
			state.lastUpdated = parsedLastModified;

				render(node, rooms, state.labels, state.locale, state.timezone, { hideUpcoming: state.hideUpcoming, hideCurrent: state.hideCurrent }, state);
				startClock(node, state);
				updateTimestamp(node, state.labels, state.locale, state.timezone, state.lastUpdated);
		}).catch(function (error) {
			var message = error && error.message ? error.message : 'unknown error';
			setError(node, state.labels.error + ' (' + message + ')');
		}).finally(function () {
			state.controller = null;
			if (state.refresh > 0) {
				state.timer = setTimeout(function () {
					fetchAndRender(node, state);
				}, state.refresh * 1000);
			}
		});
	}

	// Initialise a newly discovered widget node and start polling its data source.
	function initInstance(node) {
		if (instances.has(node)) {
			return;
		}

		var source = node.getAttribute('data-source') || '';
		if (!source && fallbackSource) {
			source = fallbackSource;
		}
		var rooms = parseJsonAttribute(node.getAttribute('data-rooms')).map(function (room) {
			return String(room || '').trim();
		}).filter(Boolean);
		var uniqueRooms = [];
		rooms.forEach(function (room) {
			if (uniqueRooms.indexOf(room) === -1) {
				uniqueRooms.push(room);
			}
		});
		rooms = uniqueRooms;

		var refresh = parseInt(node.getAttribute('data-refresh'), 10);
		if (!(refresh > 0)) {
			refresh = 60;
		}
		if (refresh < 15) {
			refresh = 15;
		}

		var labels = {
			free: node.getAttribute('data-label-free') || 'Frei',
			occupied: node.getAttribute('data-label-occupied') || 'Belegt',
			current: node.getAttribute('data-label-current') || 'Aktuell',
			upcoming: node.getAttribute('data-label-upcoming') || 'Nächste Belegungen',
			noUpcoming: node.getAttribute('data-label-no-upcoming') || 'Keine weiteren Belegungen heute.',
			freeNow: node.getAttribute('data-label-free-now') || 'Der Raum ist aktuell frei.',
			updated: node.getAttribute('data-label-updated') || 'Aktualisiert um %s',
			subject: node.getAttribute('data-label-subject') || 'Fach',
			classes: node.getAttribute('data-label-classes') || 'Klasse(n)',
			teachers: node.getAttribute('data-label-teachers') || 'Lehrperson(en)',
			remarks: node.getAttribute('data-label-remarks') || 'Hinweis',
			cancelled: node.getAttribute('data-label-cancelled') || 'Unterricht entfällt',
			error: node.getAttribute('data-error-message') || 'Daten konnten nicht geladen werden.',
			noRooms: node.getAttribute('data-error-no-rooms') || 'Bitte wählen Sie mindestens einen Raum aus.'
		};

		var state = {
			source: source,
			rooms: rooms,
			refresh: refresh,
			locale: node.getAttribute('data-locale') || undefined,
			timezone: node.getAttribute('data-timezone') || undefined,
			labels: labels,
			timer: null,
			controller: null,
			lastUpdated: null,
			clockTimer: null,
			rotationTimers: new Map(),
			hideUpcoming: node.getAttribute('data-hide-upcoming') === '1',
			hideCurrent: node.getAttribute('data-hide-current') === '1',
			resizeHandler: null
		};

		instances.set(node, state);
		if (typeof window !== 'undefined' && window.addEventListener) {
			state.resizeHandler = function () {
				applyColumnScaling(node);
			};
			window.addEventListener('resize', state.resizeHandler);
		}

		if (!state.source) {
			setError(node, labels.error + ' (no source available)');
			return;
		}

		startClock(node, state);

		if (!rooms.length) {
			var grid = node.querySelector('.foyer-webuntis-room-display__grid');
			if (grid) {
				clearChildren(grid);
				var placeholder = document.createElement('div');
				placeholder.className = 'foyer-webuntis-room-display__placeholder';
				placeholder.textContent = labels.noRooms;
				grid.appendChild(placeholder);
			}
			return;
		}

		fetchAndRender(node, state);
	}

	// Tear down a widget instance when it leaves the DOM.
	function destroyInstance(node) {
		var state = instances.get(node);
		if (!state) {
			return;
		}
		cleanupInstance(state);
		instances.delete(node);
	}

	// Run initialisation for all matching nodes on first load.
	function initAll() {
		var nodes = document.querySelectorAll(SELECTOR);
		for (var i = 0; i < nodes.length; i++) {
			initInstance(nodes[i]);
		}
	}

	// Auto-init existing nodes after DOM ready and watch for additions/removals.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll, { once: true });
	} else {
		initAll();
	}

	// Observe the whole document so dynamically inserted widgets are supported.
	var observer = new MutationObserver(function (mutations) {
		mutations.forEach(function (mutation) {
			for (var i = 0; i < mutation.addedNodes.length; i++) {
				var node = mutation.addedNodes[i];
				if (!(node instanceof HTMLElement)) {
					continue;
				}
				if (node.matches && node.matches(SELECTOR)) {
					initInstance(node);
				}
				var descendants = node.querySelectorAll ? node.querySelectorAll(SELECTOR) : [];
				for (var j = 0; j < descendants.length; j++) {
					initInstance(descendants[j]);
				}
			}

			for (var r = 0; r < mutation.removedNodes.length; r++) {
				var removed = mutation.removedNodes[r];
				if (!(removed instanceof HTMLElement)) {
					continue;
				}
				if (removed.matches && removed.matches(SELECTOR)) {
					destroyInstance(removed);
				}
				var removedDesc = removed.querySelectorAll ? removed.querySelectorAll(SELECTOR) : [];
				for (var d = 0; d < removedDesc.length; d++) {
					destroyInstance(removedDesc[d]);
				}
			}
		});
	});

	observer.observe(document.documentElement, { childList: true, subtree: true });
})();
