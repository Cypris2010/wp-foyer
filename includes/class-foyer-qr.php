<?php

/**
 * Minimal wrapper around a vendored QR generator to produce SVG.
 *
 * Uses the lightweight, bundled chillerlan/php-qrcode sources (no Composer)
 * and exposes a tiny static API that returns SVG markup. We keep the wrapper
 * small so the rest of the plugin does not depend on any vendor-specific types.
 *
 * @since 1.9.0
 */
class Foyer_QR {

    /**
     * Ensure vendor is loaded.
     */
    protected static function ensure_vendor() {
        static $loaded = false;
        if ( $loaded ) { return; }

        // On PHP < 8, use classic phpqrcode single-file lib instead.
        if ( defined('PHP_VERSION_ID') && PHP_VERSION_ID < 80000 ) {
            $phpqrcode = FOYER_PLUGIN_PATH . 'includes/lib/phpqrcode.php';
            if ( file_exists( $phpqrcode ) ) {
                include_once $phpqrcode; // defines class QRcode
            }
            // leave $loaded=false so chillerlan path is skipped, handled in svg()
            return;
        }

        // Load chillerlan/php-qrcode (bundled sources) without Composer.
        // It depends on a small settings container shim; load that first if present.
        $settings = FOYER_PLUGIN_PATH . 'includes/lib/chillerlan-settings';
        if ( is_dir( $settings ) ) {
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($settings));
                foreach ( $it as $file ) {
                    if ( $file->isFile() && substr($file->getFilename(), -4) === '.php' ) {
                        require_once $file->getPathname();
                    }
                }
            } catch ( \Throwable $e ) {}
        }

        $base = FOYER_PLUGIN_PATH . 'includes/lib/chillerlan-phpqrcode';
        if ( is_dir( $base ) ) {
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
                foreach ( $it as $file ) {
                    if ( $file->isFile() && substr($file->getFilename(), -4) === '.php' ) {
                        require_once $file->getPathname();
                    }
                }
            } catch ( \Throwable $e ) {}
        }

        $loaded = class_exists('chillerlan\\QRCode\\QRCode');
    }

    /**
     * Generate an SVG string for the given text and error correction level.
     *
     * @param string $text The content to encode.
     * @param string $ecc  Error correction: L, M, Q, H.
     * @param int    $border Quiet zone in modules (default 0, we add padding in CSS/layout if needed).
     * @return string SVG markup
     */
    public static function svg( $text, $ecc = 'M', $border = 0 ) {
        self::ensure_vendor();

        $text = (string) $text;
        $ecc  = strtoupper( (string) $ecc );
        if ( ! in_array( $ecc, array( 'L', 'M', 'Q', 'H' ), true ) ) {
            $ecc = 'M';
        }
        $border = max( 0, (int) $border );

        // chillerlan/php-qrcode (PHP 8+)
        if ( class_exists('chillerlan\\QRCode\\QRCode') && class_exists('chillerlan\\QRCode\\QROptions') ) {
            try {
                $map = array('L'=>0,'M'=>1,'Q'=>2,'H'=>3);
                $eccLevel = isset($map[$ecc]) ? $map[$ecc] : 1;

                $opts = new \chillerlan\QRCode\QROptions([
                    // Use the SVG markup output interface
                    'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
                    // Error correction level
                    'eccLevel'        => $eccLevel,
                    // Quiet zone handling: only add when border > 0
                    'addQuietzone'    => ($border > 0),
                    'quietzoneSize'   => max(0,(int)$border),
                    // Keep output lean
                    'svgAddXmlHeader' => false,
                    'svgUseFillAttributes' => true,
                    'drawLightModules'=> false,
                    'bgColor'         => null,
                ]);

                $svg = (new \chillerlan\QRCode\QRCode($opts))->render($text);
                return preg_replace('/\s+/', ' ', $svg);
            } catch ( \Throwable $e ) { }
        }

        // phpqrcode (PHP 5+) fallback: emit SVG via output buffering
        if ( class_exists('QRcode') && defined('QR_ECLEVEL_M') ) {
            try {
                $map = array('L'=>QR_ECLEVEL_L,'M'=>QR_ECLEVEL_M,'Q'=>QR_ECLEVEL_Q,'H'=>QR_ECLEVEL_H);
                $lvl = isset($map[$ecc]) ? $map[$ecc] : QR_ECLEVEL_M;
                ob_start();
                // $outfile=false (echo), $size=4, $margin=$border, $saveandprint=false
                // Transparent background: pass 0 as back_color so no background <rect> is rendered
                QRcode::svg($text, false, $lvl, 4, max(0,(int)$border), false, 0x00000000, 0x000000);
                $svg = ob_get_clean();
                return is_string($svg) ? preg_replace('/\s+/', ' ', $svg) : '';
            } catch ( \Throwable $e ) { }
        }

        return '';
    }
}
