<?php
/**
 * Calendar slide format template.
 *
 * @since 1.10.0
 */

$slide = new Foyer_Slide( get_the_id() );

if ( ! function_exists( 'foyer_calendar_tag_style' ) ) {
	function foyer_calendar_tag_style( $color ) {
		$hex = sanitize_hex_color( $color );
		if ( empty( $hex ) ) {
			return '';
		}

		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return '';
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		$luminance = ( 0.299 * $r ) + ( 0.587 * $g ) + ( 0.114 * $b );
		$contrast = ( $luminance > 186 ) ? '#111111' : '#ffffff';

		return sprintf(
			'--foyer-calendar-tag-color:%1$s;--foyer-calendar-tag-contrast:%2$s;',
			'#' . $hex,
			$contrast
		);
	}
}

$slide_title = get_post_meta( $slide->ID, 'slide_calendar_title', true );
if ( ! is_string( $slide_title ) ) {
	$slide_title = '';
}
$slide_title = trim( wp_strip_all_tags( $slide_title ) );

$days_ahead = absint( get_post_meta( $slide->ID, 'slide_calendar_days_ahead', true ) );
if ( $days_ahead < 1 ) {
	$days_ahead = 7;
}

$cache_minutes = absint( get_post_meta( $slide->ID, 'slide_calendar_cache_minutes', true ) );
if ( $cache_minutes < 1 ) {
	$cache_minutes = 30;
}

$feed_configs = get_post_meta( $slide->ID, 'slide_calendar_feeds', true );
if ( ! is_array( $feed_configs ) ) {
	$feed_configs = array();
}

$feeds = array();
foreach ( $feed_configs as $feed_config ) {
	$url = isset( $feed_config['url'] ) ? esc_url_raw( $feed_config['url'] ) : '';
	if ( empty( $url ) ) {
		continue;
	}
	$feeds[] = array(
		'url'   => $url,
		'tag'   => isset( $feed_config['tag'] ) ? sanitize_text_field( $feed_config['tag'] ) : '',
		'color' => isset( $feed_config['color'] ) ? sanitize_hex_color( $feed_config['color'] ) : '',
	);
}

$events_by_key = array();
$errors = array();

$cache_ttl = max( 1, $cache_minutes ) * MINUTE_IN_SECONDS;

$wp_timezone = wp_timezone();
$start_of_today = ( new DateTimeImmutable( 'today', $wp_timezone ) )->getTimestamp();
$range_end = $start_of_today + ( $days_ahead * DAY_IN_SECONDS ) - 1;

foreach ( $feeds as $feed ) {
	$result = Foyer_ICS::get_events( $feed['url'], array( 'cache_ttl' => $cache_ttl ) );
	if ( ! empty( $result['error'] ) ) {
		$label = $feed['tag'];
		if ( empty( $label ) ) {
			$label = $feed['url'];
		}
		$errors[] = sprintf( __( 'Calendar "%1$s" could not be loaded: %2$s', 'foyer' ), $label, $result['error'] );
	}

	foreach ( $result['events'] as $event ) {
		$event_start = isset( $event['start_timestamp'] ) ? (int) $event['start_timestamp'] : 0;
		if ( $event_start < $start_of_today ) {
			continue;
		}
		if ( $event_start > $range_end ) {
			continue;
		}

		$key = ( isset( $event['uid'] ) ? $event['uid'] : '' ) . '|' . $event_start . '|' . ( isset( $event['end_timestamp'] ) ? (int) $event['end_timestamp'] : 0 );
		if ( ! isset( $events_by_key[ $key ] ) ) {
			$summary = isset( $event['summary'] ) ? trim( wp_strip_all_tags( (string) $event['summary'] ) ) : '';
			$location = isset( $event['location'] ) ? trim( wp_strip_all_tags( (string) $event['location'] ) ) : '';
			$description = isset( $event['description'] ) ? trim( wp_strip_all_tags( (string) $event['description'] ) ) : '';
			if ( '' !== $description ) {
				$description = wp_trim_words( $description, 36, '...' );
			}
			if ( '' === $summary ) {
				$summary = __( '(Untitled event)', 'foyer' );
			}

			$events_by_key[ $key ] = array(
				'uid' => isset( $event['uid'] ) ? $event['uid'] : $key,
				'summary' => $summary,
				'location' => $location,
				'description' => $description,
				'start_timestamp' => $event_start,
				'end_timestamp' => isset( $event['end_timestamp'] ) ? (int) $event['end_timestamp'] : $event_start,
				'all_day' => ! empty( $event['all_day'] ),
				'tags' => array(),
			);
		}

		$tag_label = $feed['tag'];
		if ( ! empty( $tag_label ) ) {
			$tag_color = $feed['color'];
			if ( empty( $tag_color ) ) {
				$tag_color = '';
			}
			$already = false;
			foreach ( $events_by_key[ $key ]['tags'] as $existing_tag ) {
				if ( $existing_tag['label'] === $tag_label ) {
					$already = true;
					break;
				}
			}
			if ( ! $already ) {
				$events_by_key[ $key ]['tags'][] = array(
					'label' => $tag_label,
					'color' => $tag_color,
				);
			}
		}
	}
}

$events = array_values( $events_by_key );

usort(
	$events,
	static function ( $a, $b ) {
		if ( $a['start_timestamp'] === $b['start_timestamp'] ) {
			if ( $a['all_day'] !== $b['all_day'] ) {
				return $a['all_day'] ? -1 : 1;
			}
			return strcasecmp( $a['summary'], $b['summary'] );
		}
		return ( $a['start_timestamp'] < $b['start_timestamp'] ) ? -1 : 1;
	}
);

$days = array();
foreach ( $events as $event ) {
	$day_key = wp_date( 'Y-m-d', $event['start_timestamp'] );
	if ( ! isset( $days[ $day_key ] ) ) {
		$days[ $day_key ] = array();
	}
	$days[ $day_key ][] = $event;
}

foreach ( $days as $day_key => $day_events ) {
	usort(
		$day_events,
		static function ( $a, $b ) {
			if ( $a['all_day'] !== $b['all_day'] ) {
				return $a['all_day'] ? -1 : 1;
			}
			if ( $a['start_timestamp'] === $b['start_timestamp'] ) {
				return strcasecmp( $a['summary'], $b['summary'] );
			}
			return ( $a['start_timestamp'] < $b['start_timestamp'] ) ? -1 : 1;
		}
	);
	$days[ $day_key ] = $day_events;
}

ksort( $days );

?><div<?php $slide->classes( array( 'foyer-slide-calendar' ) ); ?><?php $slide->data_attr(); ?>>
	<div class="inner">
		<div class="foyer-slide-calendar-wrapper">
			<?php if ( '' !== $slide_title ) { ?>
				<div class="foyer-slide-calendar-title">
					<span><?php echo esc_html( $slide_title ); ?></span>
				</div>
			<?php } ?>
			<?php if ( empty( $events ) ) { ?>
				<div class="foyer-slide-calendar-empty">
					<?php echo esc_html( __( 'No upcoming events within the selected range.', 'foyer' ) ); ?>
				</div>
			<?php } else { ?>
				<div class="foyer-slide-calendar-scroll">
					<div class="foyer-slide-calendar-body">
					<?php foreach ( $days as $day_key => $day_events ) { ?>
						<section class="foyer-slide-calendar-day">
							<header class="foyer-slide-calendar-day-header">
								<span class="foyer-slide-calendar-day-weekday"><?php echo esc_html( wp_date( 'l', strtotime( $day_key ) ) ); ?></span>
								<span class="foyer-slide-calendar-day-date"><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $day_key ) ) ); ?></span>
							</header>
							<ul class="foyer-slide-calendar-events">
							<?php foreach ( $day_events as $event ) { ?>
								<li class="foyer-slide-calendar-event">
									<div class="foyer-slide-calendar-event-time">
										<?php if ( ! empty( $event['all_day'] ) ) { ?>
											<span><?php echo esc_html( __( 'All day', 'foyer' ) ); ?></span>
										<?php } else { ?>
											<span><?php echo esc_html( wp_date( 'H:i', $event['start_timestamp'] ) ); ?></span>
											<?php if ( ! empty( $event['end_timestamp'] ) && $event['end_timestamp'] > $event['start_timestamp'] ) { ?>
												<span class="foyer-slide-calendar-event-time-range">&ndash; <?php echo esc_html( wp_date( 'H:i', $event['end_timestamp'] ) ); ?></span>
											<?php } ?>
										<?php } ?>
									</div>
									<div class="foyer-slide-calendar-event-details">
										<div class="foyer-slide-calendar-event-summary">
											<span><?php echo esc_html( $event['summary'] ); ?></span>
											<?php if ( ! empty( $event['tags'] ) ) { ?>
												<span class="foyer-slide-calendar-event-tags">
												<?php foreach ( $event['tags'] as $tag ) { 
													$style = foyer_calendar_tag_style( isset( $tag['color'] ) ? $tag['color'] : '' );
													?>
													<span class="foyer-slide-calendar-event-tag"<?php if ( ! empty( $style ) ) { ?> style="<?php echo esc_attr( $style ); ?>"<?php } ?>><?php echo esc_html( $tag['label'] ); ?></span>
												<?php } ?>
												</span>
											<?php } ?>
										</div>
										<?php if ( ! empty( $event['location'] ) ) { ?>
											<div class="foyer-slide-calendar-event-location"><?php echo esc_html( $event['location'] ); ?></div>
										<?php } ?>
										<?php if ( ! empty( $event['description'] ) ) { ?>
											<div class="foyer-slide-calendar-event-description"><?php echo esc_html( $event['description'] ); ?></div>
										<?php } ?>
									</div>
								</li>
							<?php } ?>
							</ul>
						</section>
					<?php } ?>
					</div>
				</div>
			<?php } ?>
			<?php if ( ! empty( $errors ) ) { ?>
				<div class="foyer-slide-calendar-errors">
				<?php foreach ( $errors as $message ) { ?>
					<p><?php echo esc_html( $message ); ?></p>
				<?php } ?>
				</div>
			<?php } ?>
	</div>
</div>
	<?php $slide->background(); ?>
	<script>
( function( $ ) {
	function setupCalendarScroll( $containers ) {
		$containers.each( function() {
			var $container = $( this );
			var $body = $container.find( '.foyer-slide-calendar-body' ).first();
			if ( ! $body.length ) {
				return;
			}

			var available = $container.innerHeight();
			var required = $body.outerHeight();
			$body.css( {
				'--foyer-calendar-scroll-distance': '0px',
				'--foyer-calendar-scroll-duration': '0s',
				'--foyer-calendar-scroll-start': '0px'
			} );
			$container.removeClass( 'is-scrollable' );

			if ( required <= available + 2 ) {
				return;
			}

			var distance = required - available;
			var slideDuration = parseFloat( $container.closest( '.foyer-slide' ).data( 'foyer-slide-duration' ) );
			var minDuration = Math.max( Math.round( distance / 40 ) + 6, 10 );
			var duration = ( slideDuration && slideDuration > 0 ) ? slideDuration : minDuration;
			var startOffset = Math.min( distance, available * 0.33 );

			$body.css( {
				'--foyer-calendar-scroll-distance': distance + 'px',
				'--foyer-calendar-scroll-duration': duration + 's',
				'--foyer-calendar-scroll-start': startOffset + 'px'
			} );
			$container.addClass( 'is-scrollable' );
		} );
	}

	$( function() {
		setupCalendarScroll( $( '.foyer-slide-calendar-scroll' ) );
	} );

	$( window ).on( 'resize', function() {
		setupCalendarScroll( $( '.foyer-slide-calendar-scroll' ) );
	} );

	$( document ).on( 'slide:became-active', '.foyer-slide-calendar', function() {
		var $scrollContainers = $( this ).find( '.foyer-slide-calendar-scroll' );
		var $bodies = $scrollContainers.find( '.foyer-slide-calendar-body' );
		$bodies.each( function() {
			var $body = $( this );
			$body.css( 'animation', 'none' );
			// Force reflow so the browser registers the animation reset
			void $body[0].offsetHeight;
			$body.css( 'animation', '' );
		} );
		setupCalendarScroll( $scrollContainers );
	} );
})( jQuery );
	</script>
</div>
