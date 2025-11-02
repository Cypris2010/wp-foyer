jQuery(document).ready(function() {

	foyer_display_setup_channel_scheduler();

});

function foyer_display_setup_channel_scheduler() {

	$start_datetime = jQuery('#foyer_channel_editor_scheduled_channel_start');
	$end_datetime = jQuery('#foyer_channel_editor_scheduled_channel_end');
	$list_rows = jQuery('#foyer_sched_list tbody tr');

	if (jQuery($start_datetime).length || jQuery($end_datetime).length || $list_rows.length) {

		// Only continue when datetime picker fields are present, datetimepicker will work with empty jQuery objects
		jQuery.foyer_datetimepicker.setLocale(foyer_channel_scheduler_defaults.locale);

		var i18n = ($.fn.foyer_datetimepicker && $.fn.foyer_datetimepicker.defaults && $.fn.foyer_datetimepicker.defaults.i18n) ? $.fn.foyer_datetimepicker.defaults.i18n : {};
		var localeKey = foyer_channel_scheduler_defaults.locale;
		var localeData = i18n[localeKey] || null;
		var formatterOptions = {};
		var pickerFormat = foyer_channel_scheduler_defaults.picker_format || foyer_channel_scheduler_defaults.datetime_format;
		if (localeData) {
			formatterOptions.dateSettings = {
				days: localeData.dayOfWeek || [],
				daysShort: localeData.dayOfWeekShort || [],
				months: localeData.months || [],
				monthsShort: $.map(localeData.months || [], function(name){ return name ? name.substring(0,3) : ''; })
			};
		}
		var formatter = new DateFormatter(formatterOptions);

		var parseIsoDate = function(value) {
			if (!value) { return null; }
			var time = Date.parse(value);
			return isNaN(time) ? null : new Date(time);
		};

		var parseExisting = function(value) {
			if (!value) { return null; }
			var trimmed = $.trim(String(value));
			if (!trimmed) { return null; }
			try {
				return formatter.parseDate(trimmed, pickerFormat);
			} catch (err) {
				return parseIsoDate(trimmed);
			}
		};

		var verifyFormat = function(label, $input) {
			if (!$input || !$input.length) { return; }
			var raw = $.trim(String($input.val() || ''));
			if (!raw) { return; }
			var parsed = parseExisting(raw);
			if (parsed) {
				console.info('Foyer scheduler: initial value parsed', {
					field: label,
					value: raw,
					expected_format: pickerFormat,
					locale: localeKey,
					parsed: parsed
				});
			} else {
				console.warn('Foyer scheduler: value does not match expected datetime format', {
					field: label,
					value: raw,
					expected_format: pickerFormat,
					locale: localeKey
				});
			}
		};

		verifyFormat('start', $start_datetime);
		verifyFormat('end', $end_datetime);

		var verifyRowValues = function($rows) {
			if (!$rows || !$rows.length) { return; }
			$rows.each(function(index) {
				var $row = $(this);
				if ($row.hasClass('foyer-sched-empty')) { return; }
				var label = $.trim($row.find('.foyer-sched-channel-title').text() || '') || ('row-' + (index + 1));
				var startRaw = $.trim(String($row.find('.foyer-sched-start-text').attr('data-display') || $row.find('.foyer-sched-start-text').text() || ''));
				if (startRaw) {
					var parsedStart = parseExisting(startRaw);
					if (parsedStart) {
						console.info('Foyer scheduler row: start parsed', {
							row: label,
							value: startRaw,
							expected_format: pickerFormat,
							locale: localeKey,
							parsed: parsedStart
						});
					} else {
						console.warn('Foyer scheduler row: start does not match expected datetime format', {
							row: label,
							value: startRaw,
							expected_format: pickerFormat,
							locale: localeKey
						});
					}
				}
				var endRaw = $.trim(String($row.find('.foyer-sched-end-text').attr('data-display') || $row.find('.foyer-sched-end-text').text() || ''));
				if (endRaw) {
					var parsedEnd = parseExisting(endRaw);
					if (parsedEnd) {
						console.info('Foyer scheduler row: end parsed', {
							row: label,
							value: endRaw,
							expected_format: pickerFormat,
							locale: localeKey,
							parsed: parsedEnd
						});
					} else {
						console.warn('Foyer scheduler row: end does not match expected datetime format', {
							row: label,
							value: endRaw,
							expected_format: pickerFormat,
							locale: localeKey
						});
					}
				}
			});
		};

		verifyRowValues($list_rows);

		$start_datetime.foyer_datetimepicker({
			format: pickerFormat,
			dayOfWeekStart : foyer_channel_scheduler_defaults.start_of_week,
			step: 15,
			parseInputDate: parseExisting,
			onChangeDateTime: function(start) {
				if (start) {
					var currentEnd = parseExisting($end_datetime.val());
					if (!currentEnd || currentEnd < start) {
						var new_end = new Date(start.getTime() + foyer_channel_scheduler_defaults.duration * 1000);
						// Uses https://plugins.krajee.com/php-date-formatter included with datetimepicker
						$end_datetime.val(formatter.formatDate(new_end, pickerFormat));
					}
				}
			}
		});

		$end_datetime.foyer_datetimepicker({
			format: pickerFormat,
			dayOfWeekStart : foyer_channel_scheduler_defaults.start_of_week,
			step: 15,
			parseInputDate: parseExisting
		});

	}
}
