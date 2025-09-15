<?php

// PHP 8.4 compatibility: Serializable was removed. Provide a tiny shim if missing.
if ( ! interface_exists('Serializable') ) {
    interface Serializable {
        public function serialize(): string;
        public function unserialize(string $data): void;
    }
}

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

    protected static function debug($msg){
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('[Foyer_QR] ' . (is_string($msg) ? $msg : print_r($msg, true)));
        }
    }

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

        // Force-include critical files FIRST to satisfy class inheritance (no Composer autoload here)
        if ( is_dir( $base ) ) {
            $force = array(
                // options first
                $base . '/QROptionsTrait.php',
                $base . '/QRCodeReaderOptionsTrait.php',
                $base . '/QROptions.php',
                // matrix constants needed by QROutputInterface
                $base . '/Data/QRMatrix.php',
                // output base interfaces/abstracts before markup
                $base . '/Output/QROutputInterface.php',
                $base . '/Output/QROutputAbstract.php',
                $base . '/Output/RGBArrayModuleValueTrait.php',
                $base . '/Output/CssColorModuleValueTrait.php',
                $base . '/Output/QRMarkup.php',
                $base . '/Output/QRMarkupSVG.php',
                // main facade
                $base . '/QRCode.php',
            );
            foreach ( $force as $f ) {
                if ( file_exists( $f ) ) {
                    require_once $f;
                }
            }

            // Register a lightweight PSR-4 autoloader for the remaining classes
            $settings_base = FOYER_PLUGIN_PATH . 'includes/lib/chillerlan-settings';
            spl_autoload_register(function($class) use ($base, $settings_base){
                if (strpos($class, 'chillerlan\\QRCode\\') === 0){
                    $rel  = substr($class, strlen('chillerlan\\QRCode\\'));
                    $path = $base . '/' . str_replace('\\','/',$rel) . '.php';
                    if (file_exists($path)) { require_once $path; }
                }
                elseif (strpos($class, 'chillerlan\\Settings\\') === 0){
                    $rel  = substr($class, strlen('chillerlan\\Settings\\'));
                    $path = $settings_base . '/' . str_replace('\\','/',$rel) . '.php';
                    if (file_exists($path)) { require_once $path; }
                }
            });

            // Do not include the rest of the library files to avoid parse-order issues.
        }

        $loaded = class_exists('chillerlan\\QRCode\\QRCode');
        self::debug('chillerlan loaded: ' . ($loaded ? 'yes' : 'no'));

        // Last resort: if chillerlan didn't load for any reason, enable phpqrcode on PHP 8+ as well
        if ( ! $loaded ) {
            $phpqrcode = FOYER_PLUGIN_PATH . 'includes/lib/phpqrcode.php';
            if ( file_exists( $phpqrcode ) ) {
                include_once $phpqrcode; // defines class QRcode
                self::debug('phpqrcode fallback loaded');
            }
        }
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
        $has_qrclass = class_exists('chillerlan\\QRCode\\QRCode');
        $has_opts    = class_exists('chillerlan\\QRCode\\QROptions');
        $has_svgout  = class_exists('chillerlan\\QRCode\\Output\\QRMarkupSVG');
        self::debug('classes: QRCode=' . ($has_qrclass?'yes':'no') . ', QROptions=' . ($has_opts?'yes':'no') . ', QRMarkupSVG=' . ($has_svgout?'yes':'no'));
        if ( $has_qrclass && $has_opts ) {
            try {
                $opts = new \chillerlan\QRCode\QROptions([
                    // Use the SVG markup output interface
                    'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
                    // Return raw SVG markup (not a base64 data URI)
                    'outputBase64'    => false,
                    // Error correction level as letter; internal setter maps to bit value
                    'eccLevel'        => $ecc,
                    // Quiet zone handling: only add when border > 0
                    'addQuietzone'    => ($border > 0),
                    'quietzoneSize'   => max(0,(int)$border),
                    // Keep output lean
                    'svgAddXmlHeader' => false,
                    'svgUseFillAttributes' => true,
                    'drawLightModules'=> false,
                    'bgColor'         => null,
                ]);

                self::debug('rendering via chillerlan, ecc=' . $ecc . ', border=' . $border);
                $svg = (new \chillerlan\QRCode\QRCode($opts))->render($text);
                return preg_replace('/\s+/', ' ', $svg);
            } catch ( \Throwable $e ) { self::debug('chillerlan error: ' . $e->getMessage()); }
        }

        // If chillerlan path failed and phpqrcode is not yet loaded, try to include it now
        if ( ! class_exists('QRcode') ) {
            $phpqrcode = FOYER_PLUGIN_PATH . 'includes/lib/phpqrcode.php';
            if ( file_exists( $phpqrcode ) ) {
                include_once $phpqrcode;
                self::debug('phpqrcode fallback loaded (post-chillerlan)');
            }
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
                self::debug('rendering via phpqrcode');
                return is_string($svg) ? preg_replace('/\s+/', ' ', $svg) : '';
            } catch ( \Throwable $e ) { self::debug('phpqrcode error: ' . $e->getMessage()); }
        }

        self::debug('QR generation failed, returning empty string');
        return '';
    }
}
