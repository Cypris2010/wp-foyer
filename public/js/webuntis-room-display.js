(function () {
	'use strict';

	var SELECTOR = '.foyer-webuntis-room-display';
	var instances = new WeakMap();
	var MAX_UPCOMING = 3;
	var supportsAbort = typeof AbortController !== 'undefined';

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

	function normaliseRoom(value) {
		return (value || '').replace(/\s+/g, ' ').trim();
	}

	function parseDate(raw) {
		if (!raw || raw === '-' ) {
			return null;
		}
		var normalised = String(raw).trim().replace(' ', 'T');
		var date = new Date(normalised);
		return isNaN(date.getTime()) ? null : date;
	}

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

	function formatTimeRange(start, end, locale, timezone) {
		if (!start || !end) {
			return '';
		}
		return formatTime(start, locale, timezone) + ' – ' + formatTime(end, locale, timezone);
	}

	function shouldMerge(prev, next) {
		if (!prev || !next) {
			return false;
		}
		return prev.room === next.room &&
			prev.endRaw === next.startRaw &&
			prev.title === next.title &&
			prev.teachers === next.teachers &&
			prev.classes === next.classes &&
			prev.subject === next.subject &&
			prev.status === next.status;
	}

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
		var result = [entries[0]];
		for (var i = 1; i < entries.length; i++) {
			var current = entries[i];
			var previous = result[result.length - 1];
			if (shouldMerge(previous, current)) {
				previous.endRaw = current.endRaw;
				previous.endDate = current.endDate;
				if (!previous.remark && current.remark) {
					previous.remark = current.remark;
				}
				if (!previous.remark2 && current.remark2) {
					previous.remark2 = current.remark2;
				}
				if (!previous.remark3 && current.remark3) {
					previous.remark3 = current.remark3;
				}
			} else {
				result.push(current);
			}
		}
		return result;
	}

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
			if (statusRaw.toLowerCase() === 'cancelled' || statusRaw.toLowerCase() === 'gone') {
				continue;
			}

			var startRaw = parts[1] ? parts[1].trim() : '';
			var endRaw = parts[2] ? parts[2].trim() : '';
			var startDate = parseDate(startRaw);
			var endDate = parseDate(endRaw);
			if (!startDate || !endDate) {
				continue;
			}

			entries.push({
				roomOriginal: roomOriginal || room,
				room: room,
				startRaw: startRaw,
				endRaw: endRaw,
				startDate: startDate,
				endDate: endDate,
				title: parts[3] ? parts[3].trim() : '',
				teachers: parts[4] ? parts[4].trim() : '',
				classes: parts[5] ? parts[5].trim() : '',
				subject: parts[6] ? parts[6].trim() : '',
				status: statusRaw,
				remark: parts[8] ? parts[8].trim() : '',
				remark2: parts[9] ? parts[9].trim() : '',
				remark3: parts[10] ? parts[10].trim() : ''
			});
		}

		return mergeEntries(entries);
	}

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
			var current = null;
			var upcoming = [];

			for (var idx = 0; idx < list.length; idx++) {
				var item = list[idx];
				if (!current && item.startDate.getTime() <= now.getTime() && item.endDate.getTime() > now.getTime()) {
					current = item;
				} else if (item.startDate.getTime() > now.getTime()) {
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

	function clearChildren(node) {
		while (node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	function createLine(label, value, className) {
		if (!value) {
			return null;
		}
		var line = document.createElement('p');
		line.className = className;
		line.textContent = label ? (label + ': ' + value) : value;
		return line;
	}

	function buildDetails(event, labels, locale, timezone) {
		var container = document.createElement('div');
		container.className = 'foyer-webuntis-room-display__details';

		var time = document.createElement('p');
		time.className = 'foyer-webuntis-room-display__time';
		time.textContent = formatTimeRange(event.startDate, event.endDate, locale, timezone);
		container.appendChild(time);

		var subject = createLine(labels.subject, event.subject, 'foyer-webuntis-room-display__line');
		if (subject) {
			container.appendChild(subject);
		}

		var classesLine = createLine(labels.classes, event.classes, 'foyer-webuntis-room-display__line');
		if (classesLine) {
			container.appendChild(classesLine);
		}

		var teacherLine = createLine(labels.teachers, event.teachers, 'foyer-webuntis-room-display__line');
		if (teacherLine) {
			container.appendChild(teacherLine);
		}

		var remarks = [event.remark, event.remark2, event.remark3].filter(function (value) {
			return value && value.length;
		});
		if (remarks.length) {
			var remarkLine = createLine(labels.remarks, remarks.join(' • '), 'foyer-webuntis-room-display__line');
			if (remarkLine) {
				container.appendChild(remarkLine);
			}
		}

		return container;
	}

	function buildUpcomingList(upcoming, labels, locale, timezone) {
		var list = document.createElement('ul');
		list.className = 'foyer-webuntis-room-display__list';

		for (var i = 0; i < upcoming.length && i < MAX_UPCOMING; i++) {
			var event = upcoming[i];
			var item = document.createElement('li');
			item.className = 'foyer-webuntis-room-display__list-item';

			var time = document.createElement('div');
			time.className = 'foyer-webuntis-room-display__list-time';
			time.textContent = formatTimeRange(event.startDate, event.endDate, locale, timezone);
			item.appendChild(time);

			var info = document.createElement('div');
			info.className = 'foyer-webuntis-room-display__list-info';

			var title = event.subject || event.title || '';
			if (title) {
				var titleNode = document.createElement('div');
				titleNode.className = 'foyer-webuntis-room-display__list-subject';
				titleNode.textContent = title;
				info.appendChild(titleNode);
			}

			var subtitleParts = [];
			if (event.classes) {
				subtitleParts.push(event.classes);
			}
			if (event.teachers) {
				subtitleParts.push(event.teachers);
			}
			if (event.remark && subtitleParts.length === 0) {
				subtitleParts.push(event.remark);
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

	function pad(number) {
		return number < 10 ? '0' + number : String(number);
	}

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

	function render(node, rooms, labels, locale, timezone, options) {
		var grid = node.querySelector('.foyer-webuntis-room-display__grid');
		if (!grid) {
			return;
		}

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
			var occupied = !!data.current;
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

			var currentBlock = document.createElement('div');
			currentBlock.className = 'foyer-webuntis-room-display__current';

			if (data.current) {
				currentBlock.appendChild(buildDetails(data.current, labels, locale, timezone));
			} else {
				var free = document.createElement('p');
				free.className = 'foyer-webuntis-room-display__free';
				free.textContent = labels.freeNow;
				currentBlock.appendChild(free);
			}

			body.appendChild(currentBlock);

			if (!hideUpcoming) {
				var upcomingTitle = document.createElement('h3');
				upcomingTitle.className = 'foyer-webuntis-room-display__section-title';
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
	}

	function setError(node, message) {
		var errorBox = node.querySelector('.foyer-webuntis-room-display__error');
		if (!errorBox) {
			return;
		}
		errorBox.textContent = message;
		errorBox.hidden = false;
	}

	function clearError(node) {
		var errorBox = node.querySelector('.foyer-webuntis-room-display__error');
		if (!errorBox) {
			return;
		}
		errorBox.hidden = true;
		errorBox.textContent = '';
	}

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
	}

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

				render(node, rooms, state.labels, state.locale, state.timezone, { hideUpcoming: state.hideUpcoming, hideCurrent: state.hideCurrent });
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

	function initInstance(node) {
		if (instances.has(node)) {
			return;
		}

		var source = node.getAttribute('data-source') || '';
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
			hideUpcoming: node.getAttribute('data-hide-upcoming') === '1',
			hideCurrent: node.getAttribute('data-hide-current') === '1'
		};

		instances.set(node, state);

		if (!source) {
			setError(node, labels.error + ' (no source)');
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

	function destroyInstance(node) {
		var state = instances.get(node);
		if (!state) {
			return;
		}
		cleanupInstance(state);
		instances.delete(node);
	}

	function initAll() {
		var nodes = document.querySelectorAll(SELECTOR);
		for (var i = 0; i < nodes.length; i++) {
			initInstance(nodes[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll, { once: true });
	} else {
		initAll();
	}

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
