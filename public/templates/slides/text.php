<?php
/**
 * Text slide format template.
 *
 * @since	1.5.0
 */

$slide = new Foyer_Slide( get_the_id() );

$slide_text_pretitle = get_post_meta( $slide->ID, 'slide_text_pretitle', true );
$slide_text_title = get_post_meta( $slide->ID, 'slide_text_title', true );
$slide_text_subtitle = get_post_meta( $slide->ID, 'slide_text_subtitle', true );
$slide_text_content = get_post_meta( $slide->ID, 'slide_text_content', true );
// QR data (SVG markup stored server-side)
$slide_text_qr_svg = get_post_meta( $slide->ID, 'slide_text_qr_svg', true );
// Fallback: if no SVG cached but QR text exists, generate on the fly
$slide_text_qr      = get_post_meta( $slide->ID, 'slide_text_qr', true );
$slide_text_qr_ecc  = strtoupper( get_post_meta( $slide->ID, 'slide_text_qr_ecc', true ) );
if ( empty( $slide_text_qr_svg ) && ! empty( $slide_text_qr ) ) {
    if ( ! in_array( $slide_text_qr_ecc, array( 'L','M','Q','H' ), true ) ) { $slide_text_qr_ecc = 'M'; }
    if ( class_exists( 'Foyer_QR' ) ) {
        $slide_text_qr_svg = Foyer_QR::svg( $slide_text_qr, $slide_text_qr_ecc, 0 );
    }
        // Last-resort fallback: call phpqrcode directly if available
        if ( empty( $slide_text_qr_svg ) && class_exists( 'QRcode' ) && defined( 'QR_ECLEVEL_M' ) ) {
            $ecc_map = array('L'=>QR_ECLEVEL_L,'M'=>QR_ECLEVEL_M,'Q'=>QR_ECLEVEL_Q,'H'=>QR_ECLEVEL_H);
            $lvl = isset($ecc_map[$slide_text_qr_ecc]) ? $ecc_map[$slide_text_qr_ecc] : QR_ECLEVEL_M;
            ob_start();
            QRcode::svg( $slide_text_qr, false, $lvl, 4, 0, false, 0xFFFFFF, 0x000000 );
            $slide_text_qr_svg = ob_get_clean();
        }
}

?><div<?php $slide->classes(); ?><?php $slide->data_attr(); ?>>
	<div class="inner">
		<div class="foyer-slide-text-qr-row">
			<div class="foyer-slide-fields">
			<?php if ( ! empty( $slide_text_pretitle ) ) { ?>
				<div class="foyer-slide-field foyer-slide-field-pretitle"><span><?php echo $slide_text_pretitle; ?></span></div>
			<?php } ?>
			<?php if ( ! empty( $slide_text_title ) ) { ?>
				<div class="foyer-slide-field foyer-slide-field-title"><span><?php echo $slide_text_title; ?></span></div>
			<?php } ?>
			<?php if ( ! empty( $slide_text_subtitle ) ) { ?>
				<div class="foyer-slide-field foyer-slide-field-subtitle"><span><?php echo $slide_text_subtitle; ?></span></div>
			<?php } ?>
			<?php if ( ! empty( $slide_text_content ) ) { ?>
				<div class="foyer-slide-field foyer-slide-field-content"><?php echo wpautop( $slide_text_content ); ?></div>
			<?php } ?>
			</div>
		</div>
        <?php if ( ! empty( $slide_text_qr_svg ) ) { ?>
            <div class="foyer-slide-qr" aria-hidden="true" style="background-color: rgba(255,255,255,0.8);">
                <?php
                    // Prefer inline SVG for maximum compatibility and crisp rendering.
                    // Backward compatibility: if a base64 data URI is stored, fall back to <img src>.
                    if ( is_string( $slide_text_qr_svg ) && 0 === strpos( $slide_text_qr_svg, 'data:' ) ) {
                        echo '<img src="' . esc_attr( $slide_text_qr_svg ) . '" alt="" />';
                    } else {
                        echo $slide_text_qr_svg; // safe: generated server-side by QR library
                    }
                ?>
            </div>
        <?php } ?>
	</div>
	<?php $slide->background(); ?>
</div>
