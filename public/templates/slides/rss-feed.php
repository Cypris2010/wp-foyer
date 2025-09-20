<?php
/**
 * RSS feed slide format template.
 *
 * @since	1.9.1
 */

$slide = new Foyer_Slide( get_the_id() );

$feed_url = trim( (string) get_post_meta( $slide->ID, 'slide_rss_feed_url', true ) );
$limit = absint( get_post_meta( $slide->ID, 'slide_rss_limit', true ) );
if ( $limit < 1 ) {
	$limit = 5;
}

$cache_minutes = absint( get_post_meta( $slide->ID, 'slide_rss_cache_minutes', true ) );
if ( $cache_minutes < 1 ) {
	$cache_minutes = 15;
}
$cache_lifetime = max( 1, $cache_minutes ) * MINUTE_IN_SECONDS;

$show_feed_title = get_post_meta( $slide->ID, 'slide_rss_show_feed_title', true );
if ( '' === $show_feed_title ) {
	$show_feed_title = 1;
} else {
	$show_feed_title = (int) $show_feed_title;
}

$items = array();
$feed_title = '';
$error_message = '';

if ( empty( $feed_url ) ) {
	$error_message = __( 'No RSS feed URL configured for this slide.', 'foyer' );
}
else {
	include_once ABSPATH . WPINC . '/feed.php';

	$transient_key = 'foyer_rss_' . md5( $slide->ID . '|' . $feed_url . '|' . $limit );
	$cached = get_transient( $transient_key );

	if ( is_array( $cached ) ) {
		$items = isset( $cached['items'] ) && is_array( $cached['items'] ) ? $cached['items'] : array();
		$feed_title = isset( $cached['feed_title'] ) ? $cached['feed_title'] : '';
		$error_message = isset( $cached['error'] ) ? $cached['error'] : '';
	}
	else {
		$feed = fetch_feed( $feed_url );

		if ( is_wp_error( $feed ) ) {
			$error_message = $feed->get_error_message();
			set_transient(
				$transient_key,
				array(
					'items' => array(),
					'feed_title' => '',
					'error' => $error_message,
				),
				5 * MINUTE_IN_SECONDS
			);
		}
		else {
			$feed_title = $feed->get_title();
			if ( ! empty( $feed_title ) ) {
				$feed_title = wp_strip_all_tags( html_entity_decode( $feed_title, ENT_QUOTES, get_bloginfo( 'charset' ) ) );
			}

			$max_items = $feed->get_item_quantity( $limit );
			$feed_items = $feed->get_items( 0, $max_items );

			$extract_image = static function ( $item, $fallback_html ) {
				$candidates = array();

				if ( method_exists( $item, 'get_thumbnail' ) ) {
					$thumb = $item->get_thumbnail();
					if ( ! empty( $thumb ) ) {
						$candidates[] = $thumb;
					}
				}

				if ( method_exists( $item, 'get_enclosure' ) ) {
					$enclosure = $item->get_enclosure();
					if ( $enclosure ) {
						$link = $enclosure->get_link();
						if ( ! empty( $link ) ) {
							$candidates[] = $link;
						}
					}
				}

				$namespaces = array();
				if ( defined( 'SIMPLEPIE_NAMESPACE_MEDIARSS' ) ) {
					$namespaces[] = SIMPLEPIE_NAMESPACE_MEDIARSS;
				}
				$namespaces[] = 'http://search.yahoo.com/mrss/';

				foreach ( $namespaces as $ns ) {
					$media = $item->get_item_tags( $ns, 'content' );
					if ( ! empty( $media ) ) {
						foreach ( $media as $entry ) {
							if ( ! empty( $entry['attribs']['']['url'] ) ) {
								$candidates[] = $entry['attribs']['']['url'];
							}
						}
					}

					$thumbs = $item->get_item_tags( $ns, 'thumbnail' );
					if ( ! empty( $thumbs ) ) {
						foreach ( $thumbs as $entry ) {
							if ( ! empty( $entry['attribs']['']['url'] ) ) {
								$candidates[] = $entry['attribs']['']['url'];
							}
						}
					}
				}

				if ( empty( $candidates ) && ! empty( $fallback_html ) ) {
					if ( preg_match( '/<img[^>]+src=("|\')(.*?)(\1)/i', $fallback_html, $matches ) ) {
						$candidates[] = $matches[2];
					}
				}

				foreach ( $candidates as $candidate ) {
					$candidate = esc_url_raw( $candidate );
					if ( ! empty( $candidate ) ) {
						return $candidate;
					}
				}

				return '';
			};

			foreach ( $feed_items as $feed_item ) {

					$raw_title = $feed_item->get_title();
					$title = $raw_title ? wp_strip_all_tags( html_entity_decode( $raw_title, ENT_QUOTES, get_bloginfo( 'charset' ) ) ) : '';
					$subtitle = '';
					if ( '' !== $title ) {
						if ( preg_match( '/^(.+?)(?:\s*-\s+|\s*[\x{2010}-\x{2015}]\s*|\s*[\.\!\?;:,]\s*)(.+)$/u', $title, $title_matches ) ) {
							$title = trim( $title_matches[1] );
							$subtitle = trim( $title_matches[2] );
						}
					}

				$raw_content = $feed_item->get_description();
				if ( empty( $raw_content ) ) {
					$raw_content = $feed_item->get_content();
				}
				$raw_content = (string) $raw_content;

				$content_text = trim( wp_strip_all_tags( html_entity_decode( $raw_content, ENT_QUOTES, get_bloginfo( 'charset' ) ) ) );
				if ( '' !== $content_text ) {
					$content_text = wp_trim_words( $content_text, 80, '&hellip;' );
				}

				$content = '';
				if ( '' !== $content_text ) {
					$content = wpautop( esc_html( $content_text ) );
				}

				$link = $feed_item->get_permalink();
				$link = $link ? esc_url_raw( $link ) : '';

				$image_url = $extract_image( $feed_item, $raw_content );

				$qr_svg = '';
				if ( ! empty( $link ) && class_exists( 'Foyer_QR' ) ) {
					$qr_svg = Foyer_QR::svg( $link, 'M', 0 );
				}

					$items[] = array(
						'title' => $title,
						'subtitle' => $subtitle,
						'content' => $content,
						'link' => $link,
						'image' => $image_url,
						'qr_svg' => $qr_svg,
					);
			}

			set_transient(
				$transient_key,
				array(
					'items' => $items,
					'feed_title' => $feed_title,
					'error' => '',
				),
				$cache_lifetime
			);
		}
	}
}

if ( empty( $items ) ) {
	?><div<?php $slide->classes(); ?><?php $slide->data_attr(); ?>>
		<div class="inner">
			<div class="foyer-slide-rss-empty">
				<?php if ( $show_feed_title && ! empty( $feed_title ) ) { ?>
					<div class="foyer-slide-field foyer-slide-field-title"><span><?php echo esc_html( $feed_title ); ?></span></div>
				<?php } else { ?>
					<div class="foyer-slide-field foyer-slide-field-title"><span><?php echo esc_html__( 'RSS feed', 'foyer' ); ?></span></div>
				<?php } ?>
				<div class="foyer-slide-field foyer-slide-field-content">
					<?php echo wpautop( esc_html( $error_message ? $error_message : __( 'No entries found in the feed.', 'foyer' ) ) ); ?>
				</div>
			</div>
		</div>
		<?php $slide->background(); ?>
	</div><?php
	return;
}

foreach ( $items as $item ) {
	$background_image = isset( $item['image'] ) ? $item['image'] : '';
	$qr_svg = isset( $item['qr_svg'] ) ? $item['qr_svg'] : '';
	?><div<?php $slide->classes(); ?><?php $slide->data_attr(); ?>>
		<div class="inner">
			<div class="foyer-slide-rss">
				<div class="foyer-slide-fields">
					<?php if ( $show_feed_title && ! empty( $feed_title ) ) { ?>
						<div class="foyer-slide-field foyer-slide-field-pretitle"><span><?php echo esc_html( $feed_title ); ?></span></div>
					<?php } ?>
					<?php if ( ! empty( $item['title'] ) ) { ?>
						<div class="foyer-slide-field foyer-slide-field-title"><span><?php echo esc_html( $item['title'] ); ?></span></div>
					<?php } ?>
					<?php if ( ! empty( $item['subtitle'] ) ) { ?>
						<div class="foyer-slide-field foyer-slide-field-subtitle"><span><?php echo esc_html( $item['subtitle'] ); ?></span></div>
					<?php } ?>
					<?php if ( ! empty( $item['content'] ) ) { ?>
						<div class="foyer-slide-field foyer-slide-field-content"><?php echo $item['content']; ?></div>
					<?php } ?>
				</div>
				<?php if ( ! empty( $qr_svg ) ) { ?>
					<div class="foyer-slide-qr" aria-hidden="true">
						<?php echo $qr_svg; // Safe output: generated server-side ?>
					</div>
				<?php } ?>
			</div>
		</div>
		<?php if ( ! empty( $background_image ) ) { ?>
			<div class="foyer-slide-background foyer-slide-background-image foyer-slide-rss-background">
				<figure>
					<img src="<?php echo esc_url( $background_image ); ?>" alt="" />
				</figure>
			</div>
		<?php } else { ?>
			<?php $slide->background(); ?>
		<?php } ?>
	</div><?php
}
