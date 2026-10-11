<?php
/**
 * POTA (Parks on the Air) support: park reference handling, park lookups
 * against the POTA API, and the POTA-mode block renderer.
 *
 * POTA exposes a single point (lat/lon) per park and no boundary data, so
 * there is intentionally no activation zone logic here. Every park lookup is
 * cached for 90 days in the shared locations table (source = 'pota').
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Split a string of park references ("US-3315, US-4820") into a clean array
 * of unique, uppercased, valid references. Anything that is not shaped like a
 * POTA reference (e.g. a SOTA summit like W6/CT-014) is dropped.
 */
function sota_magic_park_refs( $value ) {
    $refs = [];
    foreach ( preg_split( '/[\s,;]+/', strtoupper( (string) $value ) ) as $piece ) {
        $piece = trim( $piece );
        if ( preg_match( '/^[A-Z0-9]{1,4}-\d{3,6}$/', $piece ) && ! in_array( $piece, $refs, true ) ) {
            $refs[] = $piece;
        }
    }
    return $refs;
}

/**
 * Look up a park's official point coordinates and name. Cached for 90 days.
 *
 * @return array|null [ 'ref', 'name', 'lat', 'lon', 'location' ] or null on failure.
 */
function sota_magic_get_park_info( $park_ref ) {
    static $memo = [];
    $park_ref = strtoupper( trim( $park_ref ) );
    if ( ! preg_match( '/^[A-Z0-9]{1,4}-\d{3,6}$/', $park_ref ) ) return null;
    if ( array_key_exists( $park_ref, $memo ) ) return $memo[ $park_ref ];

    $cache_key = 'park_' . sanitize_key( $park_ref );
    $row       = sota_magic_location_read( $cache_key );
    if ( $row ) {
        return $memo[ $park_ref ] = [
            'ref'      => $park_ref,
            'name'     => $row->label ?: $park_ref,
            'lat'      => floatval( $row->lat ),
            'lon'      => floatval( $row->lon ),
            'cached'   => true,
        ];
    }

    $response = wp_remote_get( 'https://api.pota.app/park/' . $park_ref, [
        'timeout'    => 15,
        'user-agent' => 'Activator-Toolkit-for-SOTA/1.0 (WordPress plugin; +https://wordpress.org/plugins/activator-toolkit-for-sota)',
    ] );
    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        return $memo[ $park_ref ] = null;
    }
    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $data ) || ! isset( $data['latitude'], $data['longitude'] ) ) {
        return $memo[ $park_ref ] = null;
    }

    $name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : $park_ref;
    sota_magic_location_write( $cache_key, floatval( $data['latitude'] ), floatval( $data['longitude'] ), $name, 'pota', 90 * DAY_IN_SECONDS );
    usleep( 150000 ); // be polite to the POTA API

    return $memo[ $park_ref ] = [
        'ref'    => $park_ref,
        'name'   => $name,
        'lat'    => floatval( $data['latitude'] ),
        'lon'    => floatval( $data['longitude'] ),
        'cached' => false,
    ];
}

/**
 * Sort contacts chronologically. Dates arrive as DD/MM/YY from the ADIF parser.
 */
function sota_magic_sort_contacts_by_time( array $contacts ) {
    $key = function ( $c ) {
        $parts = explode( '/', $c['date'] );
        if ( count( $parts ) === 3 ) {
            $y = (int) $parts[2];
            $y = $y < 50 ? 2000 + $y : 1900 + $y;
            $date_key = sprintf( '%04d%02d%02d', $y, (int) $parts[1], (int) $parts[0] );
        } else {
            $date_key = $c['date'];
        }
        return $date_key . str_replace( ':', '', $c['time'] );
    };
    usort( $contacts, function ( $a, $b ) use ( $key ) {
        return strcmp( $key( $a ), $key( $b ) );
    } );
    return $contacts;
}

/**
 * Render the block in POTA mode: park header, contact map, contact table.
 * No GPX handling; POTA mode is driven entirely by the ADIF log.
 */
function sota_magic_render_pota_data( $atts ) {
    $log_url = $atts['csvUrl'] ?? '';
    if ( ! $log_url ) return '';

    $park_override = $atts['myParkRef'] ?? '';
    $show_map      = get_option( 'sota_show_contact_map' );
    $block_width   = get_option( 'sota_block_width', '' );
    $width_class   = $block_width === 'wide' ? ' alignwide' : ( $block_width === 'full' ? ' alignfull' : '' );
    $width_style   = ( is_numeric( $block_width ) && (int) $block_width > 0 ) ? 'max-width:' . absint( $block_width ) . 'px;width:100%;' : '';

    static $pota_counter = 0;
    $pota_counter++;
    $map_dom_id   = 'pota-contact-map-' . $pota_counter;
    $table_dom_id = 'pota-contact-table-' . $pota_counter;

    $contacts = sota_magic_sort_contacts_by_time( sota_magic_parse_adif_contacts( $log_url, '', $park_override ) );

    if ( ! $contacts ) {
        return '<div class="sota-main-container"><p>' . esc_html__( 'No contacts were found in this log file.', 'activator-toolkit-for-sota' ) . '</p></div>';
    }

    // Parks being activated: everything named in the log plus the editor field.
    $my_parks = sota_magic_park_refs( $park_override );
    foreach ( $contacts as $c ) {
        foreach ( sota_magic_park_refs( $c['my_park'] ?? '' ) as $ref ) {
            if ( ! in_array( $ref, $my_parks, true ) ) $my_parks[] = $ref;
        }
    }

    $map_iframe_url = '';
    if ( $show_map ) {
        $debug_param    = ( get_option( 'sota_debug_mode_public' ) || ( get_option( 'sota_debug_mode' ) && current_user_can( 'manage_options' ) ) ) ? '&debug=1' : '';
        $map_iframe_url = admin_url( 'admin-ajax.php' ) . '?action=sota_magic_contact_map&csv=' . urlencode( $log_url )
            . '&format=adif&program=pota'
            . ( $park_override ? '&park_ref=' . urlencode( $park_override ) : '' )
            . '&_nonce=' . wp_create_nonce( 'sota_magic_contact_map' ) . $debug_param;
    }

    ob_start();
    ?>
    <div class="sota-main-container<?php echo esc_attr( $width_class ); ?>"<?php if ( $width_style ) echo ' style="' . esc_attr( $width_style ) . '"'; ?>>
        <?php if ( $my_parks ) : ?>
            <p class="pota-my-parks" style="margin:0 0 8px 0;font-size:0.9em;">
                <?php echo esc_html( count( $my_parks ) > 1 ? 'My Parks:' : 'My Park:' ); ?>
                <?php
                $labels = [];
                foreach ( $my_parks as $ref ) {
                    $info     = sota_magic_get_park_info( $ref );
                    $labels[] = '<strong>' . esc_html( $ref ) . '</strong>' . ( $info ? ' ' . esc_html( $info['name'] ) : '' );
                }
                echo wp_kses( implode( ', ', $labels ), [ 'strong' => [] ] );
                ?>
            </p>
        <?php endif; ?>

        <?php if ( $map_iframe_url ) : ?>
            <h3><?php sota_magic_echo_svg( 'map', 22 ); ?> <?php echo esc_html( get_option( 'sota_headline_map' ) ); ?></h3>
            <iframe id="<?php echo esc_attr( $map_dom_id ); ?>" src="<?php echo esc_url( $map_iframe_url ); ?>"
                    style="width:100%; height:500px; border:none; border-radius:8px; background:#f5f5f5;"
                    title="Contact Map">
            </iframe>
        <?php endif; ?>

        <h3 style="margin-top:40px;"><?php sota_magic_echo_svg( 'antenna', 22 ); ?> <?php echo esc_html( get_option( 'sota_headline_csv' ) ); ?></h3>
        <div class="sota-table-wrapper">
            <table class="sota-table" id="<?php echo esc_attr( $table_dom_id ); ?>">
                <thead>
                    <tr>
                        <th>Date / Time</th>
                        <th>Callsign</th>
                        <th>Frequency</th>
                        <th>Mode</th>
                        <th>Their Park</th>
                        <th>Comments</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                foreach ( $contacts as $tc ) {
                    $their_parks = sota_magic_park_refs( $tc['their_park'] ?? '' );
                    $p2p         = ! empty( $their_parks );
                    $parts       = explode( '/', $tc['date'] );
                    if ( count( $parts ) === 3 ) {
                        $year           = (int) $parts[2];
                        $year           = $year < 50 ? 2000 + $year : 1900 + $year;
                        $formatted_date = date_i18n( get_option( 'date_format' ), strtotime( $year . '-' . (int) $parts[1] . '-' . (int) $parts[0] ) );
                    } else {
                        $formatted_date = $tc['date'];
                    }
                    echo '<tr class="' . ( $p2p ? 'p2p-row' : '' ) . '" data-callsign="' . esc_attr( $tc['callsign'] ) . '">';
                    echo '<td>' . esc_html( $formatted_date ) . '<br><span class="sota-contact-time">' . esc_html( $tc['time'] ) . '</span></td>';
                    echo '<td><strong>' . esc_html( $tc['callsign'] ) . '</strong>' . ( $p2p ? '<span class="p2p-badge">P2P</span>' : '' ) . '</td>';
                    echo '<td>' . esc_html( $tc['frequency'] ) . '</td>';
                    echo '<td>' . esc_html( $tc['mode'] ) . '</td>';
                    echo '<td>' . esc_html( implode( ', ', $their_parks ) ) . '</td>';
                    echo '<td>' . esc_html( $tc['comments'] ) . '</td>';
                    echo '</tr>';
                }
                ?>
                </tbody>
            </table>
        </div>
        <?php
        if ( $map_iframe_url ) :
            if ( ! wp_script_is( 'sota-table-hover', 'registered' ) ) {
                wp_register_script( 'sota-table-hover', '', [], '1.1.5', true );
            }
            wp_enqueue_script( 'sota-table-hover' );
            $hover_js = '(function(){'
                . 'var iframe=document.getElementById(' . wp_json_encode( $map_dom_id ) . ');'
                . 'if(!iframe)return;'
                . 'var rows=document.querySelectorAll("#' . $table_dom_id . ' tbody tr[data-callsign]");'
                . 'function send(msg){try{iframe.contentWindow.postMessage(msg,"*");}catch(e){}}'
                . 'rows.forEach(function(row){'
                .     'row.style.cursor="pointer";'
                .     'row.addEventListener("mouseenter",function(){send({action:"show",callsign:row.dataset.callsign});});'
                .     'row.addEventListener("mouseleave",function(){send({action:"hide"});});'
                . '});'
                . '})();';
            wp_add_inline_script( 'sota-table-hover', $hover_js );
        endif;
        ?>
    </div>
    <?php
    return ob_get_clean();
}
