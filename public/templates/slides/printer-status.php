<?php
/**
 * Printer status slide format template.
 *
 * @package Foyer
 */

$slide   = new Foyer_Slide( get_the_id() );
$config  = Foyer_Printer_Status_Slide::get_config( $slide->ID );
$refresh = isset( $config['refresh'] ) ? absint( $config['refresh'] ) : Foyer_Printer_Status_Slide::DEFAULT_REFRESH;
$data    = array();
$error   = '';

if ( empty( $config['devices'] ) ) {
	$error = __( 'Keine Drucker hinterlegt.', 'foyer' );
} else {
	$result = Foyer_Printer_Status_Manager::get_status_for_slide( $slide->ID, $config['provider'], $config['devices'], $refresh );
	if ( is_wp_error( $result ) ) {
		$error = $result->get_error_message();
	} else {
		$data = $result['printers'];
	}
}

wp_enqueue_script( 'foyer-printer-status' );

$rest_endpoint = esc_url( rest_url( 'foyer/v1/printer-status/' . $slide->ID ) );
$instance_id   = 'foyer-printer-status-' . $slide->ID;

?><div<?php $slide->classes( array( 'foyer-slide-printer-status' ) ); ?><?php $slide->data_attr(); ?>>
	<div class="foyer-printer-status" id="<?php echo esc_attr( $instance_id ); ?>" data-endpoint="<?php echo $rest_endpoint; ?>" data-refresh="<?php echo esc_attr( $refresh ); ?>">
		<div class="foyer-printer-status__grid" role="list">
			<?php foreach ( $data as $printer ) :
				$host          = isset( $printer['host'] ) ? $printer['host'] : '';
				$name          = isset( $printer['name'] ) ? $printer['name'] : $host;
				$status        = isset( $printer['status'] ) ? $printer['status'] : 'unknown';
				$label         = ucwords( strtolower( str_replace( array( '_', '-' ), ' ', $status ) ) );
				$progress      = isset( $printer['progress'] ) ? floatval( $printer['progress'] ) : 0;
				$job_name      = isset( $printer['job_name'] ) ? $printer['job_name'] : '';
				$eta_human     = isset( $printer['eta_human'] ) ? $printer['eta_human'] : '';
				$nozzle_temp   = isset( $printer['nozzle_temp'] ) ? floatval( $printer['nozzle_temp'] ) : null;
				$bed_temp      = isset( $printer['bed_temp'] ) ? floatval( $printer['bed_temp'] ) : null;
				$snapshot_url  = isset( $printer['snapshot_url'] ) ? $printer['snapshot_url'] : '';
				$camera_stream = isset( $printer['camera_stream'] ) ? $printer['camera_stream'] : '';
				$error_msg     = isset( $printer['error'] ) ? $printer['error'] : '';
				$progress_clamped = min( 100, max( 0, $progress ) );
				$nozzle_display = ( null === $nozzle_temp ) ? '' : round( $nozzle_temp );
				$bed_display    = ( null === $bed_temp ) ? '' : round( $bed_temp );
				?>
				<article class="foyer-printer-status__tile" role="listitem" data-printer-host="<?php echo esc_attr( $host ); ?>" data-camera="<?php echo esc_attr( $camera_stream ); ?>" data-snapshot="<?php echo esc_attr( $snapshot_url ); ?>">
					<div class="foyer-printer-status__media">
						<?php if ( $snapshot_url ) : ?>
							<img src="<?php echo esc_url( add_query_arg( 't', time(), $snapshot_url ) ); ?>" alt="" loading="lazy" />
						<?php elseif ( $camera_stream ) : ?>
							<video src="<?php echo esc_url( $camera_stream ); ?>" autoplay muted loop playsinline></video>
						<?php else : ?>
							<div class="foyer-printer-status__placeholder"></div>
						<?php endif; ?>
					</div>
					<div class="foyer-printer-status__overlay">
						<header class="foyer-printer-status__header">
							<h3 class="foyer-printer-status__name"><?php echo esc_html( $name ); ?></h3>
							<p class="foyer-printer-status__status" data-status="<?php echo esc_attr( strtolower( $status ) ); ?>"><?php echo esc_html( $label ); ?></p>
						</header>
						<div class="foyer-printer-status__body">
							<p class="foyer-printer-status__job" <?php echo empty( $job_name ) ? 'hidden' : ''; ?>><?php echo esc_html( $job_name ); ?></p>
							<div class="foyer-printer-status__progress" aria-label="<?php esc_attr_e( 'Fortschritt', 'foyer' ); ?>">
								<div class="foyer-printer-status__progress-bar" style="width: <?php echo esc_attr( $progress_clamped ); ?>%"></div>
								<span class="foyer-printer-status__progress-text"><?php echo esc_html( round( $progress_clamped ) ); ?>%</span>
							</div>
							<ul class="foyer-printer-status__metrics">
								<li class="foyer-printer-status__metric foyer-printer-status__metric--eta" <?php echo empty( $eta_human ) ? 'hidden' : ''; ?>><?php echo esc_html( $eta_human ); ?></li>
								<li class="foyer-printer-status__metric foyer-printer-status__metric--nozzle" <?php echo ( null === $nozzle_temp ) ? 'hidden' : ''; ?>><?php printf( esc_html__( 'Düse: %s°C', 'foyer' ), esc_html( $nozzle_display ) ); ?></li>
								<li class="foyer-printer-status__metric foyer-printer-status__metric--bed" <?php echo ( null === $bed_temp ) ? 'hidden' : ''; ?>><?php printf( esc_html__( 'Bett: %s°C', 'foyer' ), esc_html( $bed_display ) ); ?></li>
							</ul>
						</div>
						<p class="foyer-printer-status__error" role="alert" <?php echo empty( $error_msg ) ? 'hidden' : ''; ?>><?php echo esc_html( $error_msg ); ?></p>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<div class="foyer-printer-status__message" role="alert" <?php echo empty( $error ) ? 'hidden' : ''; ?>><?php echo esc_html( $error ); ?></div>
	</div>
	<?php $slide->background(); ?>
</div>
