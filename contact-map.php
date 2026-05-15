<?php
/**
 * SOTA Contact Map - Standalone iframe page
 * Version: 1.4
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly — served via admin-ajax.php

/**
 * Look up a cached location row from the dedicated locations table.
 * Returns object with lat/lon/label/source, or null if not found / expired.
 */
function sota_magic_location_read( $cache_key ) {
    global $wpdb;
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
    $table = esc_sql( $wpdb->prefix . 'sota_magic_locations' );
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT lat, lon, label, source FROM $table
         WHERE cache_key = %s
           AND (expires_at IS NULL OR expires_at > NOW())
         LIMIT 1",
        $cache_key
    ) );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
}

/**
 * Store a location in the dedicated locations table.
 * Pass $expires_seconds = 0 for permanent storage.
 */
function sota_magic_location_write( $cache_key, $lat, $lon, $label, $source, $expires_seconds = 0 ) {
    global $wpdb;
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
    $table = esc_sql( $wpdb->prefix . 'sota_magic_locations' );
    $wpdb->replace( $table, [
        'cache_key'  => $cache_key,
        'lat'        => $lat,
        'lon'        => $lon,
        'label'      => $label,
        'source'     => $source,
        'expires_at' => $expires_seconds > 0 ? gmdate( 'Y-m-d H:i:s', time() + $expires_seconds ) : null,
        'cached_at'  => gmdate( 'Y-m-d H:i:s' ),
    ] );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
}

// Verify nonce — also checked by the AJAX handler that includes this file, but verified here too for static analysis
if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'sota_magic_contact_map' ) ) {
    exit;
}

// Get and sanitize parameters
$sota_magic_debug_mode = ( isset( $_GET['debug'] ) && $_GET['debug'] === '1' );
$sota_magic_csv_url = isset( $_GET['csv'] ) ? esc_url_raw( wp_unslash( $_GET['csv'] ) ) : '';
if ( ! $sota_magic_csv_url ) {
    echo '<div style="padding:20px;">No CSV file specified</div>';
    exit;
}

// Get callsign lookup credentials
$sota_magic_qrz_user    = get_option( 'sota_qrz_username' );
$sota_magic_qrz_pass    = sota_magic_decrypt_credential( get_option( 'sota_qrz_password' ) );
$sota_magic_hamqth_user = get_option( 'sota_hamqth_username' );
$sota_magic_hamqth_pass = sota_magic_decrypt_credential( get_option( 'sota_hamqth_password' ) );

// Parse CSV via wp_remote_get
$sota_magic_contacts = [];
$sota_magic_csv_response = wp_remote_get( $sota_magic_csv_url, [ 'timeout' => 15 ] );
if ( ! is_wp_error( $sota_magic_csv_response ) ) {
    $sota_magic_csv_body = wp_remote_retrieve_body( $sota_magic_csv_response );
    foreach ( explode( "\n", $sota_magic_csv_body ) as $sota_magic_csv_line ) {
        $sota_magic_row = str_getcsv( trim( $sota_magic_csv_line ) );
        if ( ! empty( $sota_magic_row[0] ) && $sota_magic_row[0] === 'V2' ) {
            $sota_magic_contacts[] = [
                'my_summit'    => $sota_magic_row[2] ?? '',
                'date'         => $sota_magic_row[3] ?? '',
                'time'         => $sota_magic_row[4] ?? '',
                'frequency'    => $sota_magic_row[5] ?? '',
                'mode'         => $sota_magic_row[6] ?? '',
                'callsign'     => $sota_magic_row[7] ?? '',
                'their_summit' => trim( $sota_magic_row[8] ?? '' ),
                'comments'     => trim( $sota_magic_row[9] ?? '' ),
            ];
        }
    }
}

/**
 * Calculate great-circle distance between two lat/lon points (Haversine formula).
 * Returns array with 'km' and 'miles', both rounded to 1 decimal place.
 */
function sota_magic_haversine( $lat1, $lon1, $lat2, $lon2 ) {
    $R     = 6371;
    $dLat  = deg2rad( $lat2 - $lat1 );
    $dLon  = deg2rad( $lon2 - $lon1 );
    $a     = sin( $dLat / 2 ) * sin( $dLat / 2 )
           + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) )
           * sin( $dLon / 2 ) * sin( $dLon / 2 );
    $km    = $R * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
    return [ 'km' => round( $km, 1 ), 'miles' => round( $km * 0.621371, 1 ) ];
}

/**
 * Extract a Maidenhead grid square from free text.
 */
function sota_magic_extract_grid_square( $sota_magic_text ) {
    if ( preg_match( '/(?<![A-Z0-9])([A-R]{2}[0-9]{2}[A-X]{2})(?![A-Z0-9])/i', $sota_magic_text, $m ) ) {
        return strtoupper( $m[1] );
    }
    if ( preg_match( '/(?<![A-Z0-9])([A-R]{2}[0-9]{2})(?![A-Z0-9])/i', $sota_magic_text, $m ) ) {
        return strtoupper( $m[1] );
    }
    return null;
}

/**
 * Convert a 4- or 6-character Maidenhead locator to lat/lon center point.
 */
function sota_magic_maidenhead_to_latlon( $sota_magic_grid ) {
    $g   = strtoupper( $sota_magic_grid );
    $lon = ( ord( $g[0] ) - ord( 'A' ) ) * 20 - 180;
    $lat = ( ord( $g[1] ) - ord( 'A' ) ) * 10 - 90;
    $lon += ( ord( $g[2] ) - ord( '0' ) ) * 2;
    $lat += ( ord( $g[3] ) - ord( '0' ) );
    if ( strlen( $g ) >= 6 ) {
        $lon += ( ord( $g[4] ) - ord( 'A' ) ) * ( 5.0 / 60 ) + ( 5.0 / 60 / 2 );
        $lat += ( ord( $g[5] ) - ord( 'A' ) ) * ( 2.5 / 60 ) + ( 2.5 / 60 / 2 );
    } else {
        $lon += 1.0;
        $lat += 0.5;
    }
    return [ 'lat' => round( $lat, 6 ), 'lon' => round( $lon, 6 ) ];
}

/**
 * Get band color from frequency (MHz).
 */
function sota_magic_get_band_color( $sota_magic_frequency ) {
    $freq = floatval( $sota_magic_frequency );
    if ( $freq >= 1.8   && $freq < 2.0   ) return '#8B4513';
    if ( $freq >= 3.5   && $freq < 4.0   ) return '#FFA500';
    if ( $freq >= 7.0   && $freq < 7.3   ) return '#FFD700';
    if ( $freq >= 10.1  && $freq < 10.15 ) return '#FFFF00';
    if ( $freq >= 14.0  && $freq < 14.35 ) return '#00FF00';
    if ( $freq >= 18.068 && $freq < 18.168 ) return '#00CED1';
    if ( $freq >= 21.0  && $freq < 21.45 ) return '#0000FF';
    if ( $freq >= 24.89 && $freq < 24.99 ) return '#4B0082';
    if ( $freq >= 28.0  && $freq < 29.7  ) return '#8B00FF';
    if ( $freq >= 50.0  && $freq < 54.0  ) return '#FF1493';
    if ( $freq >= 144.0 && $freq < 148.0 ) return '#FF69B4';
    if ( $freq >= 222.0 && $freq < 225.0 ) return '#FFB6C1';
    if ( $freq >= 420.0 && $freq < 450.0 ) return '#FFC0CB';
    return '#999999';
}

// Get summit location from SOTA API (cached 90 days in locations table)
$sota_magic_summit = null;
if ( ! empty( $sota_magic_contacts[0]['my_summit'] ) ) {
    $sota_magic_summit_ref = $sota_magic_contacts[0]['my_summit'];
    $sota_magic_summit_key = 'summit_' . sanitize_key( $sota_magic_summit_ref );
    $sota_magic_summit_row = sota_magic_location_read( $sota_magic_summit_key );
    if ( $sota_magic_summit_row ) {
        $sota_magic_summit = [
            'lat'  => floatval( $sota_magic_summit_row->lat ),
            'lon'  => floatval( $sota_magic_summit_row->lon ),
            'name' => $sota_magic_summit_row->label ?: $sota_magic_summit_ref,
            'ref'  => $sota_magic_summit_ref,
        ];
    } else {
        $sota_magic_api_url  = 'https://api2.sota.org.uk/api/summits/' . $sota_magic_summit_ref;
        $sota_magic_wp_resp  = wp_remote_get( $sota_magic_api_url, [ 'timeout' => 30, 'user-agent' => 'SOTA-Magic-Plugin/1.0' ] );
        $sota_magic_response = ! is_wp_error( $sota_magic_wp_resp ) ? wp_remote_retrieve_body( $sota_magic_wp_resp ) : false;
        if ( $sota_magic_response !== false ) {
            $sota_magic_summit_data = json_decode( $sota_magic_response, true );
            if ( $sota_magic_summit_data && isset( $sota_magic_summit_data['latitude'], $sota_magic_summit_data['longitude'] ) ) {
                $sota_magic_summit_name = $sota_magic_summit_data['name'] ?? $sota_magic_summit_ref;
                $sota_magic_summit = [
                    'lat'  => floatval( $sota_magic_summit_data['latitude'] ),
                    'lon'  => floatval( $sota_magic_summit_data['longitude'] ),
                    'name' => $sota_magic_summit_name,
                    'ref'  => $sota_magic_summit_ref,
                ];
                sota_magic_location_write( $sota_magic_summit_key, $sota_magic_summit['lat'], $sota_magic_summit['lon'], $sota_magic_summit_name, 'sota', 90 * DAY_IN_SECONDS );
            }
        }
    }
}

// Get QRZ session if credentials available
$sota_magic_qrz_session = null;
if ( $sota_magic_qrz_user && $sota_magic_qrz_pass ) {
    $sota_magic_login_url      = 'https://xmldata.qrz.com/xml/current/?username=' . rawurlencode( $sota_magic_qrz_user ) . '&password=' . rawurlencode( $sota_magic_qrz_pass );
    $sota_magic_login_wp       = wp_remote_get( $sota_magic_login_url, [ 'timeout' => 15 ] );
    $sota_magic_login_response = ! is_wp_error( $sota_magic_login_wp ) ? wp_remote_retrieve_body( $sota_magic_login_wp ) : false;
    if ( $sota_magic_login_response ) {
        preg_match( '/<Key>([^<]+)<\/Key>/', $sota_magic_login_response, $sota_magic_matches );
        if ( ! empty( $sota_magic_matches[1] ) ) {
            $sota_magic_qrz_session = $sota_magic_matches[1];
        }
    }
}

// Get HamQTH session if credentials available (sessions valid for 1 hour)
$sota_magic_hamqth_session = null;
if ( $sota_magic_hamqth_user && $sota_magic_hamqth_pass ) {
    $sota_magic_hamqth_login_url      = 'https://www.hamqth.com/xml.php?u=' . rawurlencode( $sota_magic_hamqth_user ) . '&p=' . rawurlencode( $sota_magic_hamqth_pass );
    $sota_magic_hamqth_login_wp       = wp_remote_get( $sota_magic_hamqth_login_url, [ 'timeout' => 15, 'user-agent' => 'Activator-Toolkit-for-SOTA/1.0' ] );
    $sota_magic_hamqth_login_response = ! is_wp_error( $sota_magic_hamqth_login_wp ) ? wp_remote_retrieve_body( $sota_magic_hamqth_login_wp ) : false;
    if ( $sota_magic_hamqth_login_response ) {
        preg_match( '/<session_id>([^<]+)<\/session_id>/', $sota_magic_hamqth_login_response, $sota_magic_hamqth_matches );
        if ( ! empty( $sota_magic_hamqth_matches[1] ) ) {
            $sota_magic_hamqth_session = $sota_magic_hamqth_matches[1];
        }
    }
}

// Get locations for all contacts
$sota_magic_contact_locations = [];
$sota_magic_unresolved        = [];
$sota_magic_lookup_fail_debug = []; // Only populated in debug mode
foreach ( $sota_magic_contacts as $sota_magic_contact ) {
    $sota_magic_callsign   = $sota_magic_contact['callsign'];
    $sota_magic_comments   = $sota_magic_contact['comments'];
    $sota_magic_is_s2s     = ! empty( $sota_magic_contact['their_summit'] );
    $sota_magic_band_color = sota_magic_get_band_color( $sota_magic_contact['frequency'] );

    // Priority 1: grid square in comments
    $sota_magic_grid = sota_magic_extract_grid_square( $sota_magic_comments );
    if ( $sota_magic_grid ) {
        $sota_magic_coords              = sota_magic_maidenhead_to_latlon( $sota_magic_grid );
        $sota_magic_contact_locations[] = [
            'callsign'        => $sota_magic_callsign,
            'lat'             => $sota_magic_coords['lat'],
            'lon'             => $sota_magic_coords['lon'],
            'summit'          => $sota_magic_contact['their_summit'],
            'mode'            => $sota_magic_contact['mode'],
            'frequency'       => $sota_magic_contact['frequency'],
            'is_s2s'          => $sota_magic_is_s2s,
            'color'           => $sota_magic_band_color,
            'location_source' => 'grid',
            'grid'            => $sota_magic_grid,
        ];
        continue;
    }

    // Priority 2: S2S — use SOTA API summit coordinates (cached 90 days in locations table)
    if ( $sota_magic_is_s2s ) {
        $sota_magic_their_ref = $sota_magic_contact['their_summit'];
        $sota_magic_s2s_key   = 'summit_' . sanitize_key( $sota_magic_their_ref );
        $sota_magic_s2s_row   = sota_magic_location_read( $sota_magic_s2s_key );
        if ( $sota_magic_s2s_row ) {
            $sota_magic_contact_locations[] = [
                'callsign'        => $sota_magic_callsign,
                'lat'             => floatval( $sota_magic_s2s_row->lat ),
                'lon'             => floatval( $sota_magic_s2s_row->lon ),
                'summit'          => $sota_magic_their_ref,
                'mode'            => $sota_magic_contact['mode'],
                'frequency'       => $sota_magic_contact['frequency'],
                'is_s2s'          => true,
                'color'           => $sota_magic_band_color,
                'location_source' => 'sota',
                'cached'          => true,
            ];
        } else {
            $sota_magic_their_api_url  = 'https://api2.sota.org.uk/api/summits/' . $sota_magic_their_ref;
            $sota_magic_their_wp       = wp_remote_get( $sota_magic_their_api_url, [ 'timeout' => 15, 'user-agent' => 'SOTA-Magic-Plugin/1.0' ] );
            $sota_magic_their_response = ! is_wp_error( $sota_magic_their_wp ) ? wp_remote_retrieve_body( $sota_magic_their_wp ) : false;
            if ( $sota_magic_their_response !== false ) {
                $sota_magic_their_data = json_decode( $sota_magic_their_response, true );
                if ( $sota_magic_their_data && isset( $sota_magic_their_data['latitude'], $sota_magic_their_data['longitude'] ) ) {
                    $sota_magic_s2s_lat = floatval( $sota_magic_their_data['latitude'] );
                    $sota_magic_s2s_lon = floatval( $sota_magic_their_data['longitude'] );
                    sota_magic_location_write( $sota_magic_s2s_key, $sota_magic_s2s_lat, $sota_magic_s2s_lon, $sota_magic_their_ref, 'sota', 90 * DAY_IN_SECONDS );
                    $sota_magic_contact_locations[] = [
                        'callsign'        => $sota_magic_callsign,
                        'lat'             => $sota_magic_s2s_lat,
                        'lon'             => $sota_magic_s2s_lon,
                        'summit'          => $sota_magic_their_ref,
                        'mode'            => $sota_magic_contact['mode'],
                        'frequency'       => $sota_magic_contact['frequency'],
                        'is_s2s'          => true,
                        'color'           => $sota_magic_band_color,
                        'location_source' => 'sota',
                        'cached'          => false,
                    ];
                } else {
                    $sota_magic_unresolved[] = [ 'callsign' => $sota_magic_callsign, 'reason' => 'SOTA API returned no coordinates for ' . $sota_magic_their_ref ];
                }
            } else {
                $sota_magic_unresolved[] = [ 'callsign' => $sota_magic_callsign, 'reason' => 'SOTA API unreachable for ' . $sota_magic_their_ref ];
            }
        }
        continue;
    }

    // Priority 3: Check unified cache (any source); also check legacy qrz_ key for existing cached entries
    $sota_magic_loc_key    = 'loc_' . sanitize_key( strtolower( $sota_magic_callsign ) );
    $sota_magic_legacy_key = 'qrz_' . sanitize_key( strtolower( $sota_magic_callsign ) );
    $sota_magic_cached_row = sota_magic_location_read( $sota_magic_loc_key )
                          ?? sota_magic_location_read( $sota_magic_legacy_key );
    if ( $sota_magic_cached_row ) {
        $sota_magic_contact_locations[] = [
            'callsign'        => $sota_magic_callsign,
            'lat'             => floatval( $sota_magic_cached_row->lat ),
            'lon'             => floatval( $sota_magic_cached_row->lon ),
            'summit'          => '',
            'mode'            => $sota_magic_contact['mode'],
            'frequency'       => $sota_magic_contact['frequency'],
            'is_s2s'          => false,
            'color'           => $sota_magic_band_color,
            'location_source' => $sota_magic_cached_row->source,
            'cached'          => true,
        ];
        continue;
    }

    $sota_magic_fail_reasons = [];

    // Priority 4: Callook.info — free, US callsigns only (FCC data), no auth required
    $sota_magic_callook_url  = 'https://callook.info/' . rawurlencode( $sota_magic_callsign ) . '/json';
    $sota_magic_callook_wp   = wp_remote_get( $sota_magic_callook_url, [ 'timeout' => 10, 'user-agent' => 'Activator-Toolkit-for-SOTA/1.0' ] );
    $sota_magic_callook_body = ! is_wp_error( $sota_magic_callook_wp ) ? wp_remote_retrieve_body( $sota_magic_callook_wp ) : false;
    if ( $sota_magic_callook_body ) {
        $sota_magic_callook_data = json_decode( $sota_magic_callook_body, true );
        if ( isset( $sota_magic_callook_data['status'] ) && $sota_magic_callook_data['status'] === 'VALID'
             && ! empty( $sota_magic_callook_data['location']['latitude'] )
             && ! empty( $sota_magic_callook_data['location']['longitude'] ) ) {
            $sota_magic_callook_lat = floatval( $sota_magic_callook_data['location']['latitude'] );
            $sota_magic_callook_lon = floatval( $sota_magic_callook_data['location']['longitude'] );
            sota_magic_location_write( $sota_magic_loc_key, $sota_magic_callook_lat, $sota_magic_callook_lon, $sota_magic_callsign, 'callook', 0 );
            $sota_magic_contact_locations[] = [
                'callsign'        => $sota_magic_callsign,
                'lat'             => $sota_magic_callook_lat,
                'lon'             => $sota_magic_callook_lon,
                'summit'          => '',
                'mode'            => $sota_magic_contact['mode'],
                'frequency'       => $sota_magic_contact['frequency'],
                'is_s2s'          => false,
                'color'           => $sota_magic_band_color,
                'location_source' => 'callook',
                'cached'          => false,
            ];
            usleep( 250000 );
            continue;
        }
        $sota_magic_fail_reasons[] = 'Callook: not a US callsign';
    } else {
        $sota_magic_fail_reasons[] = 'Callook: request failed';
    }

    // Priority 5: HamQTH — free account, international
    if ( $sota_magic_hamqth_session ) {
        $sota_magic_hamqth_url      = 'https://www.hamqth.com/xml.php?id=' . rawurlencode( $sota_magic_hamqth_session ) . '&callsign=' . rawurlencode( $sota_magic_callsign ) . '&prg=Activator-Toolkit-for-SOTA';
        $sota_magic_hamqth_wp       = wp_remote_get( $sota_magic_hamqth_url, [ 'timeout' => 15, 'user-agent' => 'Activator-Toolkit-for-SOTA/1.0' ] );
        $sota_magic_hamqth_response = ! is_wp_error( $sota_magic_hamqth_wp ) ? wp_remote_retrieve_body( $sota_magic_hamqth_wp ) : false;
        if ( $sota_magic_hamqth_response ) {
            preg_match( '/<latitude>([^<]+)<\/latitude>/', $sota_magic_hamqth_response, $sota_magic_hamqth_lat_m );
            preg_match( '/<longitude>([^<]+)<\/longitude>/', $sota_magic_hamqth_response, $sota_magic_hamqth_lon_m );
            if ( ! empty( $sota_magic_hamqth_lat_m[1] ) && ! empty( $sota_magic_hamqth_lon_m[1] ) ) {
                $sota_magic_hamqth_lat = floatval( $sota_magic_hamqth_lat_m[1] );
                $sota_magic_hamqth_lon = floatval( $sota_magic_hamqth_lon_m[1] );
                sota_magic_location_write( $sota_magic_loc_key, $sota_magic_hamqth_lat, $sota_magic_hamqth_lon, $sota_magic_callsign, 'hamqth', 0 );
                $sota_magic_contact_locations[] = [
                    'callsign'        => $sota_magic_callsign,
                    'lat'             => $sota_magic_hamqth_lat,
                    'lon'             => $sota_magic_hamqth_lon,
                    'summit'          => '',
                    'mode'            => $sota_magic_contact['mode'],
                    'frequency'       => $sota_magic_contact['frequency'],
                    'is_s2s'          => false,
                    'color'           => $sota_magic_band_color,
                    'location_source' => 'hamqth',
                    'cached'          => false,
                ];
                usleep( 250000 );
                continue;
            }
            $sota_magic_fail_reasons[] = 'HamQTH: no coordinates in response';
            if ( $sota_magic_debug_mode ) {
                $sota_magic_lookup_fail_debug[ $sota_magic_callsign ] = substr( $sota_magic_hamqth_response, 0, 1000 );
            }
        } else {
            $sota_magic_fail_reasons[] = 'HamQTH: request failed';
        }
        usleep( 250000 );
    } else {
        $sota_magic_fail_reasons[] = 'HamQTH: not configured';
    }

    // Priority 6: QRZ.com — paid subscription, international
    if ( $sota_magic_qrz_session ) {
        $sota_magic_qrz_url      = 'https://xmldata.qrz.com/xml/current/?s=' . rawurlencode( $sota_magic_qrz_session ) . '&callsign=' . rawurlencode( $sota_magic_callsign );
        $sota_magic_qrz_wp       = wp_remote_get( $sota_magic_qrz_url, [ 'timeout' => 15, 'user-agent' => 'Activator-Toolkit-for-SOTA/1.0' ] );
        $sota_magic_qrz_response = ! is_wp_error( $sota_magic_qrz_wp ) ? wp_remote_retrieve_body( $sota_magic_qrz_wp ) : false;
        if ( $sota_magic_qrz_response ) {
            preg_match( '/<lat>([^<]+)<\/lat>/', $sota_magic_qrz_response, $sota_magic_lat_match );
            preg_match( '/<lon>([^<]+)<\/lon>/', $sota_magic_qrz_response, $sota_magic_lon_match );
            if ( ! empty( $sota_magic_lat_match[1] ) && ! empty( $sota_magic_lon_match[1] ) ) {
                $sota_magic_qrz_lat = floatval( $sota_magic_lat_match[1] );
                $sota_magic_qrz_lon = floatval( $sota_magic_lon_match[1] );
                sota_magic_location_write( $sota_magic_loc_key, $sota_magic_qrz_lat, $sota_magic_qrz_lon, $sota_magic_callsign, 'qrz', 0 );
                $sota_magic_contact_locations[] = [
                    'callsign'        => $sota_magic_callsign,
                    'lat'             => $sota_magic_qrz_lat,
                    'lon'             => $sota_magic_qrz_lon,
                    'summit'          => '',
                    'mode'            => $sota_magic_contact['mode'],
                    'frequency'       => $sota_magic_contact['frequency'],
                    'is_s2s'          => false,
                    'color'           => $sota_magic_band_color,
                    'location_source' => 'qrz',
                    'cached'          => false,
                ];
                usleep( 500000 );
                continue;
            }
            $sota_magic_fail_reasons[] = 'QRZ: no coordinates in response';
            if ( $sota_magic_debug_mode ) {
                $sota_magic_lookup_fail_debug[ $sota_magic_callsign ] = substr( $sota_magic_qrz_response, 0, 1000 );
            }
        } else {
            $sota_magic_fail_reasons[] = 'QRZ: request failed';
        }
        usleep( 500000 );
    } else {
        $sota_magic_fail_reasons[] = 'QRZ: not configured';
    }

    $sota_magic_unresolved[] = [ 'callsign' => $sota_magic_callsign, 'reason' => implode( '; ', $sota_magic_fail_reasons ) ];
}

// Build the map data object that contact-map.js reads from window.sotaContactMapData.
// This is a standalone HTML document served via admin-ajax.php; wp_enqueue_script/style
// hooks have already fired and cannot be used here. All JS logic lives in the external
// contact-map.js file — the only inline script below is this single data assignment,
// which is the same pattern WordPress core uses for wp_localize_script().
$sota_magic_map_contacts = [];
foreach ( $sota_magic_contact_locations as $sota_magic_loc ) {
    $sota_magic_dist_miles = null;
    $sota_magic_dist_km    = null;
    if ( $sota_magic_summit ) {
        $sota_magic_dist       = sota_magic_haversine(
            $sota_magic_summit['lat'], $sota_magic_summit['lon'],
            $sota_magic_loc['lat'],   $sota_magic_loc['lon']
        );
        $sota_magic_dist_miles = $sota_magic_dist['miles'];
        $sota_magic_dist_km    = $sota_magic_dist['km'];
    }
    $sota_magic_map_contacts[] = [
        'lat'             => floatval( $sota_magic_loc['lat'] ),
        'lon'             => floatval( $sota_magic_loc['lon'] ),
        'callsign'        => $sota_magic_loc['callsign'],
        's2s_summit'      => $sota_magic_loc['summit'] ?? null,
        'frequency'       => $sota_magic_loc['frequency'],
        'mode'            => $sota_magic_loc['mode'],
        'color'           => $sota_magic_loc['color'],
        'is_s2s'          => (bool) $sota_magic_loc['is_s2s'],
        'location_source' => $sota_magic_loc['location_source'],
        'grid'            => $sota_magic_loc['grid'] ?? null,
        'dist_miles'      => $sota_magic_dist_miles,
        'dist_km'         => $sota_magic_dist_km,
    ];
}
$sota_magic_map_data = [
    'summit'     => $sota_magic_summit ? [
        'lat'  => floatval( $sota_magic_summit['lat'] ),
        'lon'  => floatval( $sota_magic_summit['lon'] ),
        'name' => $sota_magic_summit['name'],
        'ref'  => $sota_magic_summit['ref'],
    ] : null,
    'contacts'   => $sota_magic_map_contacts,
    'unresolved' => $sota_magic_unresolved,
    'debug'      => $sota_magic_debug_mode,
];

// Enqueue styles and scripts using WordPress APIs.
// This is a standalone HTML document served via wp_ajax_*; wp_head()/wp_footer() are never
// called automatically, so we call wp_print_styles() / wp_print_scripts() manually below.
wp_enqueue_style( 'sota-cm-leaflet', plugins_url( 'lib/leaflet.css',  __FILE__ ), [],                      '1.9.4' );
wp_enqueue_style( 'sota-cm-css',     plugins_url( 'contact-map.css', __FILE__ ), [],                      '1.0.5' );
wp_enqueue_script( 'sota-cm-leaflet-js', plugins_url( 'lib/leaflet.js',  __FILE__ ), [],                      '1.9.4', false );
wp_enqueue_script( 'sota-cm-js',         plugins_url( 'contact-map.js', __FILE__ ), [ 'sota-cm-leaflet-js' ], '1.0.5', true  );
wp_add_inline_script( 'sota-cm-js', 'var sotaContactMapData = ' . wp_json_encode( $sota_magic_map_data ) . ';', 'before' );
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
        <div class="loading-icon">🏔️</div>
        <div class="loading-spinner"></div>
        <div class="loading-text">Loading contact map...</div>
    </div>

    <div id="map"></div>

    <?php wp_print_scripts( [ 'sota-cm-js' ] ); ?>

    <?php if ( $sota_magic_debug_mode ) : ?>
    <div style="position:fixed;bottom:0;left:0;right:0;background:#fff3cd;border-top:2px solid #ffc107;padding:8px 12px;font-size:11px;font-family:monospace;z-index:99999;max-height:35vh;overflow-y:auto;">
        <strong>🔍 Contact Map Debug (admin only)</strong><br>
        Summit found: <strong><?php echo $sota_magic_summit ? 'YES — ' . esc_html( $sota_magic_summit['ref'] ) . ' (' . esc_html( $sota_magic_summit['lat'] ) . ', ' . esc_html( $sota_magic_summit['lon'] ) . ')' : 'NO (API returned nothing)'; ?></strong><br>
        Raw CSV contacts parsed: <strong><?php echo count( $sota_magic_contacts ); ?></strong><br>
        First row my_summit field: <strong><?php echo esc_html( $sota_magic_contacts[0]['my_summit'] ?? '(empty)' ); ?></strong><br>
        Contact locations resolved: <strong><?php echo count( $sota_magic_contact_locations ); ?></strong><br>
        Lines drawn: <strong><?php echo ( $sota_magic_summit && count( $sota_magic_contact_locations ) > 0 ) ? count( $sota_magic_contact_locations ) . ' lines' : 'NONE — summit was null'; ?></strong><br>
        <?php
        $sota_magic_cached_count = 0;
        $sota_magic_fresh_count  = 0;
        foreach ( $sota_magic_contact_locations as $sota_magic_loc ) {
            if ( ! empty( $sota_magic_loc['cached'] ) ) $sota_magic_cached_count++;
            else $sota_magic_fresh_count++;
        }
        ?>
        Cache hits: <strong><?php echo esc_html( (string) $sota_magic_cached_count ); ?></strong> &nbsp;|&nbsp; Fresh lookups: <strong><?php echo esc_html( (string) $sota_magic_fresh_count ); ?></strong> &nbsp;|&nbsp; Unresolved: <strong><?php echo esc_html( (string) count( $sota_magic_unresolved ) ); ?></strong><br>
        <?php
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sota_magic_loc_table  = esc_sql( $wpdb->prefix . 'sota_magic_locations' );
        $sota_magic_total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $sota_magic_loc_table" );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sota_magic_db_error   = $wpdb->last_error;
        ?>
        <hr style="margin:4px 0;">
        Locations table: <strong><?php echo esc_html( $sota_magic_loc_table ); ?></strong> — <strong><?php echo esc_html( (string) $sota_magic_total_rows ); ?> row(s) stored</strong><br>
        DB error: <strong><?php echo $sota_magic_db_error ? esc_html( $sota_magic_db_error ) : 'none'; ?></strong><br>
        <hr style="margin:4px 0;">
        <?php foreach ( $sota_magic_contact_locations as $sota_magic_idx => $sota_magic_loc ) : ?>
        <?php $sota_magic_cache_label = isset( $sota_magic_loc['cached'] ) ? ( $sota_magic_loc['cached'] ? ' <span style="color:#28a745;">✓ cached</span>' : ' <span style="color:#fd7e14;">⬇ fresh fetch</span>' ) : ''; ?>
        Contact <?php echo esc_html( (string) ( $sota_magic_idx + 1 ) ); ?>: <?php echo esc_html( $sota_magic_loc['callsign'] ); ?> → (<?php echo esc_html( $sota_magic_loc['lat'] ); ?>, <?php echo esc_html( $sota_magic_loc['lon'] ); ?>) via <?php echo esc_html( $sota_magic_loc['location_source'] ); ?><?php echo $sota_magic_cache_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- controlled HTML ?><br>
        <?php endforeach; ?>
        <?php if ( ! empty( $sota_magic_unresolved ) ) : ?>
        <hr style="margin:4px 0;">
        <strong style="color:#c0392b;">⚠ Unresolved contacts (not shown on map):</strong><br>
        <?php foreach ( $sota_magic_unresolved as $sota_magic_ur ) : ?>
        &nbsp;• <strong><?php echo esc_html( $sota_magic_ur['callsign'] ); ?></strong> — <?php echo esc_html( $sota_magic_ur['reason'] ); ?><br>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if ( ! empty( $sota_magic_lookup_fail_debug ) ) : ?>
        <hr style="margin:4px 0;">
        <strong>Raw lookup responses (failed):</strong><br>
        <?php foreach ( $sota_magic_lookup_fail_debug as $sota_magic_cs => $sota_magic_raw ) : ?>
        <em><?php echo esc_html( $sota_magic_cs ); ?>:</em><br>
        <pre style="font-size:10px;white-space:pre-wrap;max-height:120px;overflow-y:auto;background:#f8f8f8;padding:4px;"><?php echo esc_html( $sota_magic_raw ); ?></pre>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</body>
</html>
