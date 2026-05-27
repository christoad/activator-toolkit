<?php
/**
 * SOTA Contact Map - Standalone iframe shell
 * Outputs the loading UI immediately; contact-map.js fetches data asynchronously.
 * Version: 1.5
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Verify nonce
if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'sota_magic_contact_map' ) ) {
    exit;
}

// Get and sanitize parameters
$sota_cm_debug      = ( isset( $_GET['debug'] ) && $_GET['debug'] === '1' );
$sota_cm_csv        = isset( $_GET['csv'] )        ? esc_url_raw( wp_unslash( $_GET['csv'] ) )               : '';
$sota_cm_format     = isset( $_GET['format'] )     ? sanitize_key( wp_unslash( $_GET['format'] ) )            : 'csv';
$sota_cm_summit_ref = isset( $_GET['summit_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['summit_ref'] ) ) : '';

if ( ! $sota_cm_csv ) {
    echo '<div style="padding:20px;">No log file specified</div>';
    exit;
}

// Build the data endpoint URL that contact-map.js will call
$sota_cm_data_url = add_query_arg( [
    'action'     => 'sota_magic_contact_map_data',
    'csv'        => $sota_cm_csv,
    'format'     => $sota_cm_format,
    'summit_ref' => $sota_cm_summit_ref,
    'debug'      => $sota_cm_debug ? '1' : '0',
    '_nonce'     => wp_create_nonce( 'sota_magic_contact_map' ),
], admin_url( 'admin-ajax.php' ) );

// Enqueue assets using WordPress APIs (required by WP.org — no raw <script>/<link> tags)
wp_enqueue_style(  'sota-cm-leaflet',    plugins_url( 'lib/leaflet.css',  __FILE__ ), [],                       '1.9.4' );
wp_enqueue_style(  'sota-cm-css',        plugins_url( 'contact-map.css',  __FILE__ ), [],                       '1.0.5' );
wp_enqueue_script( 'sota-cm-leaflet-js', plugins_url( 'lib/leaflet.js',   __FILE__ ), [],                       '1.9.4', false );
wp_enqueue_script( 'sota-cm-js',         plugins_url( 'contact-map.js',   __FILE__ ), [ 'sota-cm-leaflet-js' ], '1.1.0', true );
wp_add_inline_script( 'sota-cm-js', 'var sotaContactMapParams = ' . wp_json_encode( [ 'dataUrl' => $sota_cm_data_url ] ) . ';', 'before' );
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>SOTA Contact Map</title>
    <?php wp_print_styles( [ 'sota-cm-leaflet', 'sota-cm-css' ] ); ?>
    <?php wp_print_scripts( [ 'sota-cm-leaflet-js' ] ); ?>
</head>
<body>
    <div id="loading-overlay">
        <div class="loading-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#CC2200" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 20L12 4L21 20H3Z"/><path d="M9 20L12 13L15 17"/></svg></div>
        <div class="loading-spinner"></div>
        <div id="loading-text" class="loading-text">Loading contact map...</div>
    </div>

    <div id="map"></div>

    <?php wp_print_scripts( [ 'sota-cm-js' ] ); ?>
</body>
</html>
