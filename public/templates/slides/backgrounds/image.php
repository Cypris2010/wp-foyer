<?php
/**
 * Image slide background template.
 *
 * @since	1.4.0
 * @since	1.5.1	Switched to using the new 'foyer' image size.
 *					Introduced image responsiveness by using wp_get_attachment_image.
 */

$slide = new Foyer_Slide( get_the_id() );

$attachment_id = get_post_meta( $slide->ID, 'slide_bg_image_image', true );

$zoom_preference = get_post_meta( $slide->ID, 'slide_bg_image_zoom', true );
if ( ! in_array( $zoom_preference, array( 'enabled', 'disabled' ), true ) ) {
	$zoom_preference = 'inherit';
}

$zoom_enabled = false;

if ( 'enabled' === $zoom_preference ) {
	$zoom_enabled = true;
} elseif ( 'inherit' === $zoom_preference ) {
	$zoom_enabled = (bool) get_option( 'foyer_enable_background_zoom', 0 );
}

$background_classes = array();
if ( $zoom_enabled ) {
	$background_classes[] = 'foyer-slide-background-zoom';
}

if ( ! empty( $attachment_id ) ) {

	?><div<?php $slide->background_classes( $background_classes ); ?><?php $slide->background_data_attr();?>>
		<figure>
			<?php echo wp_get_attachment_image( $attachment_id, 'foyer' ); ?>
		</figure>
	</div><?php

}
