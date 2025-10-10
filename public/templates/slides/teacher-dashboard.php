<?php
/**
 * Teacher Dashboard slide format template.
 *
 * @since	1.?.?
 */

$slide = new Foyer_Slide( get_the_id() );
$source = get_post_meta( $slide->ID, 'slide_teacher_dashboard_source', true );
if ( empty( $source ) ) {
	$source = 'https://bigbrother2.lgsit.de/untisdata/lehrer.txt';
}

$teachers = get_post_meta( $slide->ID, 'slide_teacher_dashboard_teachers', true );
$teachers = is_array( $teachers ) ? array_values( array_map( 'sanitize_text_field', $teachers ) ) : array();

$theme = get_post_meta( $slide->ID, 'slide_teacher_dashboard_theme', true );
if ( ! in_array( $theme, array( 'dark', 'light' ), true ) ) {
	$theme = 'dark';
}

$legend = get_post_meta( $slide->ID, 'slide_teacher_dashboard_show_legend', true );
$legend = ( 'no' === $legend ) ? 'no' : 'yes';

$theme_class = 'foyer-teacher-dashboard--' . $theme;

wp_enqueue_script( 'foyer-teacher-dashboard' );

?><div<?php $slide->classes( array( 'foyer-slide-teacher-dashboard' ) ); ?><?php $slide->data_attr(); ?>>
	<div class="foyer-teacher-dashboard <?php echo esc_attr( $theme_class ); ?>" data-source="<?php echo esc_url( $source ); ?>" data-refresh="30" data-teachers='<?php echo esc_attr( wp_json_encode( $teachers ) ); ?>' data-error-no-source="<?php echo esc_attr__( 'Keine Datenquelle definiert.', 'foyer' ); ?>' data-theme="<?php echo esc_attr( $theme ); ?>">
		<div class="foyer-teacher-dashboard__grid" role="list"></div>
		<?php if ( 'yes' === $legend ) : ?>
			<div class="foyer-teacher-dashboard__legend" role="group" aria-label="<?php echo esc_attr__( 'Statusfilter', 'foyer' ); ?>"></div>
		<?php endif; ?>
		<div class="foyer-teacher-dashboard__error" hidden>
			<div class="foyer-teacher-dashboard__error-box" role="alert">
				<h3><?php esc_html_e( 'CSV konnte nicht geladen werden', 'foyer' ); ?></h3>
				<p class="foyer-teacher-dashboard__error-msg"></p>
			</div>
		</div>
	</div>
	<?php $slide->background(); ?>
</div>
