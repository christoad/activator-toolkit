<?php
/**
 * Settings page: General | Summits (SOTA) | Parks (POTA) | Display | Callsign Lookup | Developer | Cache
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function sota_magic_settings_page() {
    if (!current_user_can('manage_options')) return;

    $sota_magic_valid_tabs = ['general', 'sota', 'pota', 'display', 'lookup', 'developer', 'cache'];
    $sota_current_tab = 'general';
    if (isset($_POST['sota_magic_save'])) {
        $sota_current_tab = sanitize_key($_POST['sota_active_tab'] ?? 'general');
    } elseif (isset($_GET['tab'])) {
        $sota_current_tab = sanitize_key($_GET['tab']);
    }
    if ($sota_current_tab === 'gpx') $sota_current_tab = 'sota'; // legacy tab slug
    if (!in_array($sota_current_tab, $sota_magic_valid_tabs, true)) $sota_current_tab = 'general';

    if (isset($_POST['sota_magic_save'])) {
        check_admin_referer('sota_magic_settings');
        update_option('sota_default_program', sota_magic_sanitize_program(sanitize_key(wp_unslash($_POST['sota_default_program'] ?? 'sota'))));
        update_option('sota_headline_gpx', sanitize_text_field(wp_unslash($_POST['sota_headline_gpx'] ?? '')));
        update_option('sota_headline_csv', sanitize_text_field(wp_unslash($_POST['sota_headline_csv'] ?? '')));
        update_option('sota_headline_map', sanitize_text_field(wp_unslash($_POST['sota_headline_map'] ?? '')));
        update_option('sota_bg_color', sanitize_hex_color(wp_unslash($_POST['sota_bg_color'] ?? '')));
        update_option('sota_text_color', sanitize_hex_color(wp_unslash($_POST['sota_text_color'] ?? '')));
        update_option('sota_is_transparent', isset($_POST['sota_is_transparent']) ? 1 : 0);
        update_option('sota_use_theme_font', isset($_POST['sota_use_theme_font']) ? 1 : 0);
        update_option('sota_s2s_highlight', sanitize_hex_color(wp_unslash($_POST['sota_s2s_highlight'] ?? '')));
        update_option('sota_s2s_text_color', sanitize_hex_color(wp_unslash($_POST['sota_s2s_text_color'] ?? '')));
        update_option('pota_p2p_highlight', sanitize_hex_color(wp_unslash($_POST['pota_p2p_highlight'] ?? '')) ?: '#e8f5e9');
        update_option('pota_p2p_text_color', sanitize_hex_color(wp_unslash($_POST['pota_p2p_text_color'] ?? '')) ?: '#2e7d32');
        update_option('sota_show_contact_map', isset($_POST['sota_show_contact_map']) ? 1 : 0);
        $bw_raw = sanitize_text_field(wp_unslash($_POST['sota_block_width'] ?? ''));
        if ($bw_raw === 'custom') {
            $bw_raw = (string) absint(wp_unslash($_POST['sota_block_width_custom'] ?? ''));
            if ($bw_raw === '0') $bw_raw = '';
        }
        update_option('sota_block_width', $bw_raw);
        update_option('sota_hamqth_username', sanitize_text_field(wp_unslash($_POST['sota_hamqth_username'] ?? '')));
        $sota_magic_hamqth_pass = wp_unslash($_POST['sota_hamqth_password'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- password encrypted immediately
        if (!empty($sota_magic_hamqth_pass)) {
            update_option('sota_hamqth_password', sota_magic_encrypt_credential($sota_magic_hamqth_pass));
        }
        update_option('sota_qrz_username', sanitize_text_field(wp_unslash($_POST['sota_qrz_username'] ?? '')));
        $sota_magic_new_pass = wp_unslash($_POST['sota_qrz_password'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- password is immediately encrypted; no sanitizer can run without corrupting special characters
        if (!empty($sota_magic_new_pass)) {
            update_option('sota_qrz_password', sota_magic_encrypt_credential($sota_magic_new_pass));
        }
        update_option('sota_show_gpx_stats', isset($_POST['sota_show_gpx_stats']) ? 1 : 0);
        update_option('sota_stationary_threshold', sanitize_text_field(wp_unslash($_POST['sota_stationary_threshold'] ?? '')));
        update_option('sota_unit_system', sanitize_text_field(wp_unslash($_POST['sota_unit_system'] ?? '')));
        update_option('sota_activation_zone_radius', sanitize_text_field(wp_unslash($_POST['sota_activation_zone_radius'] ?? '')));
        update_option('sota_rest_threshold_minutes', sanitize_text_field(wp_unslash($_POST['sota_rest_threshold_minutes'] ?? '')));
        update_option('sota_use_azapi', isset($_POST['sota_use_azapi']) ? 1 : 0);
        update_option('sota_debug_mode', isset($_POST['sota_debug_mode']) ? 1 : 0);
        update_option('sota_debug_mode_public', isset($_POST['sota_debug_mode_public']) ? 1 : 0);
        update_option('sota_default_map_layer', sanitize_text_field(wp_unslash($_POST['sota_default_map_layer'] ?? 'carto')));
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }

    $tab_labels = [
        'general'   => 'General',
        'sota'      => 'Summits (SOTA)',
        'pota'      => 'Parks (POTA)',
        'display'   => 'Display',
        'lookup'    => 'Callsign Lookup',
        'developer' => 'Developer',
        'cache'     => 'Cache',
    ];
    $default_program = get_option('sota_default_program', 'sota');
    ?>
    <div class="wrap">
        <h1><img src="<?php echo esc_url(plugins_url('lib/activator-toolkit-logo.svg', SOTA_MAGIC_PLUGIN_FILE)); ?>" alt="Activator Toolkit" style="height:48px;vertical-align:middle;margin-right:10px;">Activator Toolkit Settings</h1>
        <?php $sota_magic_data = get_plugin_data( SOTA_MAGIC_PLUGIN_FILE ); ?>
        <p style="font-size:12px;color:#666;"><em>Created by KI6CR. Version <?php echo esc_html( $sota_magic_data['Version'] ); ?></em></p>

        <h2 class="nav-tab-wrapper">
            <?php foreach ($tab_labels as $slug => $label) : ?>
                <a href="#" data-tab="<?php echo esc_attr($slug); ?>" class="nav-tab <?php echo $sota_current_tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </h2>

        <form method="post" action="">
            <input type="hidden" name="sota_active_tab" id="sota_active_tab" value="<?php echo esc_attr($sota_current_tab); ?>">
            <?php wp_nonce_field('sota_magic_settings'); ?>

            <!-- Tab: General -->
            <div id="sota-tab-general" class="sota-tab-panel" style="<?php echo $sota_current_tab === 'general' ? '' : 'display:none;'; ?>">
                <table class="form-table">
                    <tr><th colspan="2"><h2>What This Plugin Does</h2></th></tr>
                    <tr><td colspan="2" style="padding-left:0;"><div style="background:#f0f0f0;padding:12px 16px;border-left:4px solid #0073aa;margin:6px 0;line-height:1.7;max-width:760px;">
                        <p style="margin-top:0;">Activator Toolkit turns the files from a radio activation into a ready-made section of a WordPress post. Add the <strong>Activator Toolkit</strong> block to a post, choose a mode, upload your files, and the plugin builds the maps and tables for you.</p>
                        <p><strong>Summits on the Air (SOTA) mode</strong><br>
                        Upload a GPX track and a SOTA CSV or ADIF log. You get an interactive map of your hike with the activation zone, an elevation chart, hiking and activation statistics, a contact table, and a map of everyone you worked. Summit-to-Summit (S2S) contacts are highlighted.</p>
                        <p><strong>Parks on the Air (POTA) mode</strong><br>
                        Upload your POTA ADIF log. You get a map of every station you worked, with lines back to your park, plus a contact table. Park-to-Park (P2P) contacts are highlighted, and activations from several parks at once are supported.</p>
                        <p style="margin-bottom:0;">Station locations come from grid squares in your log or from callsign lookups, and are saved so the map shows where each station was at the time of your activation.</p>
                    </div></td></tr>

                    <tr><th colspan="2"><h2>Default Mode</h2></th></tr>
                    <tr><th>New blocks start in</th><td>
                        <label style="margin-right:20px;"><input type="radio" name="sota_default_program" value="sota" <?php checked('sota', $default_program); ?> /> Summits on the Air (SOTA)</label>
                        <label><input type="radio" name="sota_default_program" value="pota" <?php checked('pota', $default_program); ?> /> Parks on the Air (POTA)</label>
                        <br><small>This is the mode a new block starts in. You can flip any individual block between SOTA and POTA using the toggle on the block itself. Existing SOTA posts are not affected.</small>
                    </td></tr>

                    <tr><th colspan="2"><h2>Where To Find Things</h2></th></tr>
                    <tr><td colspan="2" style="padding-left:0;"><div style="max-width:760px;line-height:1.7;">
                        <strong>Summits (SOTA)</strong>: activation zone, GPX statistics, units, hike map layer, S2S colors.<br>
                        <strong>Parks (POTA)</strong>: P2P colors and notes on how POTA logs are read.<br>
                        <strong>Display</strong>: headlines, colors, contact map, and block width (both modes).<br>
                        <strong>Callsign Lookup</strong>: how contact locations are found (both modes).<br>
                        <strong>Developer</strong> and <strong>Cache</strong>: troubleshooting tools.
                    </div></td></tr>
                </table>
            </div>

            <!-- Tab: Summits (SOTA) -->
            <div id="sota-tab-sota" class="sota-tab-panel" style="<?php echo $sota_current_tab === 'sota' ? '' : 'display:none;'; ?>">
                <table class="form-table">
                    <tr><th colspan="2"><h2>Activation Zone</h2></th></tr>
                    <tr><td colspan="2" style="padding-left:0;"><p style="background:#f0f0f0;padding:10px;border-left:4px solid #0073aa;margin:10px 0;">
                        <strong>How Hiking vs. Activation Time is Calculated:</strong><br>
                        The plugin looks for the activation zone in this order:<br>
                        <strong>1. SOTLAS (most detailed):</strong> Terrain-based boundaries at roughly 1 meter detail, from <a href="https://sotl.as" target="_blank">sotl.as</a>. SOTLAS is still adding associations, so a summit without a boundary today is checked again after 30 days.<br>
                        <strong>2. Activation.Zone:</strong> Terrain-based boundaries from <a href="https://activation.zone" target="_blank">activation.zone</a> (by N6ARA), at lower detail. Used when SOTLAS has no boundary for the summit.<br>
                        <strong>3. Radius circle:</strong> A simple circle around the highest GPS point. Used if neither service has a boundary, or if terrain lookups are turned off.<br><br>
                        Both services follow the 25m vertical drop rule. You can force a specific source for a single post in the block's Statistics Overrides.<br><br>
                        <strong>All time spent within the activation zone counts as activation time</strong> (regardless of movement). All other time counts as hiking time, with rest breaks shown as a sub-note.
                    </p></td></tr>
                    <tr><th>Use Terrain-Based Zones</th><td>
                        <input type="checkbox" name="sota_use_azapi" value="1" <?php checked(1, get_option('sota_use_azapi')); ?> />
                        <br><small>Look up SOTLAS and Activation.Zone boundaries. If turned off, or if neither has a boundary, the radius circle is used.</small>
                    </td></tr>
                    <tr><th>Activation Zone Radius</th><td>
                        <input type="number" name="sota_activation_zone_radius" value="<?php echo esc_attr(get_option('sota_activation_zone_radius')); ?>" step="10" min="20" max="200" style="width:80px;" /> meters
                        <br><small>Used when no terrain-based boundary is found or terrain lookups are turned off (default: 50m)</small>
                    </td></tr>

                    <tr><th colspan="2"><h2>Display &amp; Units</h2></th></tr>
                    <tr><th>Show GPX Statistics</th><td><input type="checkbox" name="sota_show_gpx_stats" value="1" <?php checked(1, get_option('sota_show_gpx_stats')); ?> /><br><small>Display hiking time, activation time, and other statistics</small></td></tr>
                    <tr><th>Unit System</th><td>
                        <select name="sota_unit_system">
                            <option value="metric" <?php selected('metric', get_option('sota_unit_system')); ?>>Metric (km, m, km/h)</option>
                            <option value="imperial" <?php selected('imperial', get_option('sota_unit_system')); ?>>Imperial (mi, ft, mph)</option>
                        </select>
                        <br><small>Choose how distances and speeds are displayed</small>
                    </td></tr>
                    <tr><th>Default Map Layer</th><td>
                        <select name="sota_default_map_layer">
                            <option value="topo" <?php selected('topo', get_option('sota_default_map_layer')); ?>>Topographic (OpenTopoMap)</option>
                            <option value="osm" <?php selected('osm', get_option('sota_default_map_layer')); ?>>OpenStreetMap</option>
                            <option value="carto" <?php selected('carto', get_option('sota_default_map_layer')); ?>>Minimal</option>
                        </select>
                        <br><small>Which base map layer loads by default on the GPX track map</small>
                    </td></tr>

                    <tr><th colspan="2"><h2>Track Analysis</h2></th></tr>
                    <tr><th>Rest Break Threshold</th><td>
                        <input type="number" name="sota_rest_threshold_minutes" value="<?php echo esc_attr(get_option('sota_rest_threshold_minutes')); ?>" step="1" min="1" max="30" style="width:80px;" /> minutes
                        <br><small>Minimum duration to count as a rest break. Short stops (photos, water) won't count. (default: 3 min)</small>
                    </td></tr>
                    <tr><th>Stationary Speed Threshold</th><td>
                        <input type="number" name="sota_stationary_threshold" value="<?php echo esc_attr(get_option('sota_stationary_threshold')); ?>" step="0.1" min="0.1" max="2.0" style="width:80px;" /> km/h
                        <br><small>Speed below this is considered stationary (default: 0.3 km/h)</small>
                    </td></tr>

                    <tr><th colspan="2"><h2>Summit-to-Summit (S2S) Highlighting</h2></th></tr>
                    <tr><th>S2S Background</th><td><input type="color" name="sota_s2s_highlight" value="<?php echo esc_attr(get_option('sota_s2s_highlight')); ?>" /></td></tr>
                    <tr><th>S2S Text</th><td><input type="color" name="sota_s2s_text_color" value="<?php echo esc_attr(get_option('sota_s2s_text_color')); ?>" /></td></tr>
                </table>
            </div>

            <!-- Tab: Parks (POTA) -->
            <div id="sota-tab-pota" class="sota-tab-panel" style="<?php echo $sota_current_tab === 'pota' ? '' : 'display:none;'; ?>">
                <table class="form-table">
                    <tr><th colspan="2"><h2>How POTA Mode Works</h2></th></tr>
                    <tr><td colspan="2" style="padding-left:0;"><p style="background:#f0f0f0;padding:10px 14px;border-left:4px solid #0073aa;margin:10px 0;line-height:1.8;max-width:760px;">
                        <strong>Your log file does the work.</strong> Upload the ADIF (.adi or .adif) log you submit to pota.app. The plugin reads your park reference from the log (the MY_SIG_INFO field) and finds each park&rsquo;s location from the official POTA database.<br>
                        <strong>Several parks at once:</strong> if you activated more than one park from the same spot, all of them are shown, and distances are measured from the first park.<br>
                        <strong>Park-to-Park (P2P):</strong> when a contact logged their own park, their dot is placed at that park and the contact is highlighted in the table.<br>
                        <strong>No activation zone:</strong> POTA does not publish park boundaries, so each park is shown as a single pin at its official location.<br>
                        <strong>Everything else:</strong> headlines, colors, block width, and callsign lookups are shared with SOTA mode and live on the Display and Callsign Lookup tabs.
                    </p></td></tr>

                    <tr><th colspan="2"><h2>Park-to-Park (P2P) Highlighting</h2></th></tr>
                    <tr><th>P2P Background</th><td><input type="color" name="pota_p2p_highlight" value="<?php echo esc_attr(get_option('pota_p2p_highlight', '#e8f5e9')); ?>" /></td></tr>
                    <tr><th>P2P Text</th><td><input type="color" name="pota_p2p_text_color" value="<?php echo esc_attr(get_option('pota_p2p_text_color', '#2e7d32')); ?>" /></td></tr>
                </table>
            </div>

            <!-- Tab: Display -->
            <div id="sota-tab-display" class="sota-tab-panel" style="<?php echo $sota_current_tab === 'display' ? '' : 'display:none;'; ?>">
                <table class="form-table">
                    <tr><th colspan="2"><h2>Headlines</h2></th></tr>
                    <tr><th>GPS Track Headline (SOTA)</th><td><input type="text" name="sota_headline_gpx" value="<?php echo esc_attr(get_option('sota_headline_gpx')); ?>" class="regular-text" /></td></tr>
                    <tr><th>Contacts Headline</th><td><input type="text" name="sota_headline_csv" value="<?php echo esc_attr(get_option('sota_headline_csv')); ?>" class="regular-text" /></td></tr>
                    <tr><th>Map Headline</th><td><input type="text" name="sota_headline_map" value="<?php echo esc_attr(get_option('sota_headline_map')); ?>" class="regular-text" /></td></tr>

                    <tr><th colspan="2"><h2>Colors</h2></th></tr>
                    <tr><th>Background</th><td><input type="color" name="sota_bg_color" value="<?php echo esc_attr(get_option('sota_bg_color')); ?>" /></td></tr>
                    <tr><th>Text</th><td><input type="color" name="sota_text_color" value="<?php echo esc_attr(get_option('sota_text_color')); ?>" /></td></tr>
                    <tr><th>Transparent BG</th><td><input type="checkbox" name="sota_is_transparent" value="1" <?php checked(1, get_option('sota_is_transparent')); ?> /></td></tr>
                    <tr><th>Use Theme Font</th><td><input type="checkbox" name="sota_use_theme_font" value="1" <?php checked(1, get_option('sota_use_theme_font')); ?> /></td></tr>

                    <tr><th colspan="2"><h2>Contact Map</h2></th></tr>
                    <tr><th>Show Contact Map</th><td><input type="checkbox" name="sota_show_contact_map" value="1" <?php checked(1, get_option('sota_show_contact_map')); ?> /></td></tr>

                    <tr><th colspan="2"><h2>Block Width</h2></th></tr>
                    <tr><th>Width</th><td>
                        <?php
                        $bw_saved = get_option('sota_block_width', '');
                        $bw_known = ['', 'wide', 'full', '700', '800', '900', '1000', '1100', '1200'];
                        $bw_select_val = in_array($bw_saved, $bw_known, true) ? $bw_saved : (is_numeric($bw_saved) && $bw_saved !== '' ? 'custom' : $bw_saved);
                        $bw_custom_val = ($bw_select_val === 'custom') ? $bw_saved : '';
                        ?>
                        <select name="sota_block_width" id="sota_block_width_select" onchange="sotaWidthChange(this.value)">
                            <option value=""     <?php selected('',     $bw_select_val); ?>>Follow theme (default)</option>
                            <option value="700"  <?php selected('700',  $bw_select_val); ?>>700px</option>
                            <option value="800"  <?php selected('800',  $bw_select_val); ?>>800px</option>
                            <option value="900"  <?php selected('900',  $bw_select_val); ?>>900px</option>
                            <option value="1000" <?php selected('1000', $bw_select_val); ?>>1000px</option>
                            <option value="1100" <?php selected('1100', $bw_select_val); ?>>1100px</option>
                            <option value="1200" <?php selected('1200', $bw_select_val); ?>>1200px</option>
                            <option value="wide" <?php selected('wide', $bw_select_val); ?>>Wide: break out of content column</option>
                            <option value="full" <?php selected('full', $bw_select_val); ?>>Full page width</option>
                            <option value="custom" <?php selected('custom', $bw_select_val); ?>>Custom…</option>
                        </select>
                        <span id="sota_custom_width_wrap" style="display:<?php echo $bw_select_val === 'custom' ? 'inline-flex' : 'none'; ?>;align-items:center;gap:4px;margin-left:6px;">
                            <input type="number" name="sota_block_width_custom" id="sota_block_width_custom"
                                   value="<?php echo esc_attr($bw_custom_val); ?>"
                                   min="400" max="2000" step="10" style="width:80px;" /> px
                        </span>
                        <script>function sotaWidthChange(v){document.getElementById('sota_custom_width_wrap').style.display=v==='custom'?'inline-flex':'none';}</script>
                        <br><small>Choose a fixed pixel width, or use <strong>Wide</strong>/<strong>Full page width</strong> to use WordPress alignment classes that break out of the content column.</small>
                    </td></tr>
                </table>
            </div>

            <!-- Tab: Callsign Lookup -->
            <div id="sota-tab-lookup" class="sota-tab-panel" style="<?php echo $sota_current_tab === 'lookup' ? '' : 'display:none;'; ?>">
                <table class="form-table">
                    <tr><th colspan="2"><h2>How Contact Locations Are Determined</h2></th></tr>
                    <tr><td colspan="2" style="padding-left:0;"><p style="background:#f0f0f0;padding:10px 14px;border-left:4px solid #0073aa;margin:10px 0;line-height:1.8;">
                        <strong>How the plugin figures out where each contact was located:</strong><br>
                        <strong>1. Summit-to-Summit (S2S) or Park-to-Park (P2P)</strong>: if the log shows the other station was also on a summit or in a park, the plugin uses that summit&rsquo;s or park&rsquo;s official coordinates. No lookup needed.<br>
                        <strong>2. Grid square in log file</strong>: if your logging app recorded a Maidenhead grid square for the contact (common in ADIF/.adi files), the plugin plots directly from that. No lookup needed.<br>
                        <strong>3. Grid square in Comments</strong>: if you typed a grid square into the comments field of your log, the plugin uses that instead.<br>
                        <strong>4. Callook.info</strong>: free automatic lookup for US callsigns using FCC data. No account or configuration needed.<br>
                        <strong>5. HamQTH</strong>: free lookup for international callsigns. Requires a free HamQTH account (see below).<br>
                        <strong>6. QRZ.com</strong>: international lookup. Requires a paid QRZ XML subscription (see below).<br>
                        <br>Once a location is found it&rsquo;s saved permanently. The address at the <em>time of your activation</em> is kept intentionally. If they&rsquo;ve moved since, the cached location is still the historically accurate one.
                    </p></td></tr>

                    <tr><th colspan="2"><h2>Callook.info <span style="font-weight:normal;font-size:13px;color:#28a745;">&#10003; Free, no account needed</span></h2></th></tr>
                    <tr><th colspan="2"><p style="color:#555;padding:0 0 10px;">Callook.info serves US callsigns from FCC data. It runs automatically for every contact, with no configuration needed. Non-US callsigns fall through to HamQTH or QRZ.</p></th></tr>

                    <tr><th colspan="2"><h2>HamQTH <span style="font-weight:normal;font-size:13px;color:#0073aa;">Free account required</span></h2></th></tr>
                    <tr><th>HamQTH Username</th><td>
                        <input type="text" name="sota_hamqth_username" value="<?php echo esc_attr(get_option('sota_hamqth_username')); ?>" class="regular-text" />
                        <br><small>Your HamQTH.com callsign. <a href="https://www.hamqth.com/register.php" target="_blank">Register free at HamQTH.com</a>. No paid subscription required. Provides international callsign location data.</small>
                    </td></tr>
                    <tr><th>HamQTH Password</th><td>
                        <input type="password" name="sota_hamqth_password" value="" placeholder="<?php echo get_option('sota_hamqth_password') ? esc_attr('(saved, leave blank to keep current password)') : ''; ?>" class="regular-text" />
                        <br><small>Your HamQTH.com password. Leave blank to keep the current saved password.</small>
                    </td></tr>

                    <tr><th colspan="2"><h2>QRZ.com <span style="font-weight:normal;font-size:13px;color:#888;">Paid XML subscription required</span></h2></th></tr>
                    <tr><th>QRZ Username</th><td>
                        <input type="text" name="sota_qrz_username" value="<?php echo esc_attr(get_option('sota_qrz_username')); ?>" class="regular-text" />
                        <br><small>Your QRZ.com callsign. <strong>Requires a QRZ XML subscription</strong>. A free QRZ account does not include XML access. <a href="https://www.qrz.com/page/xml_data.html" target="_blank">Learn more at QRZ.com</a>.</small>
                    </td></tr>
                    <tr><th>QRZ Password</th><td>
                        <input type="password" name="sota_qrz_password" value="" placeholder="<?php echo get_option('sota_qrz_password') ? esc_attr('(saved, leave blank to keep current password)') : ''; ?>" class="regular-text" />
                        <br><small>Your QRZ.com password. Leave blank to keep the current saved password.</small>
                    </td></tr>
                </table>
            </div>

            <!-- Tab: Developer -->
            <div id="sota-tab-developer" class="sota-tab-panel" style="<?php echo $sota_current_tab === 'developer' ? '' : 'display:none;'; ?>">
                <table class="form-table">
                    <tr><th colspan="2"><h2>Debug Mode</h2></th></tr>
                    <tr><th>Debug Mode (admin only)</th><td>
                        <input type="checkbox" name="sota_debug_mode" value="1" <?php checked(1, get_option('sota_debug_mode')); ?> />
                        <br><small>Shows technical debug panels <strong>visible only to logged-in admins</strong>. Safe to leave on without affecting public visitors. Enables two panels:
                        <br>• <strong>GPX stats page</strong>: API response details, polygon vertex count, points-in-zone count, and speed ranges (SOTA mode)
                        <br>• <strong>Contact map</strong>: summit or park lookup result, contacts resolved, per-contact location source, and lines-drawn count</small>
                    </td></tr>
                    <tr><th>Debug Mode (public)</th><td>
                        <input type="checkbox" name="sota_debug_mode_public" value="1" <?php checked(1, get_option('sota_debug_mode_public')); ?> />
                        <br><small>Same debug panels as above but <strong>visible to all visitors</strong>. Use temporarily when testing while logged out. Disable when done.</small>
                    </td></tr>
                </table>
            </div>

            <!-- Tab: Cache -->
            <div id="sota-tab-cache" class="sota-tab-panel" style="<?php echo $sota_current_tab === 'cache' ? '' : 'display:none;'; ?>">
                <?php
                global $wpdb;
                $sota_cache_table = $wpdb->prefix . 'sota_magic_locations';
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $sota_cache_table_safe = esc_sql( $sota_cache_table );
                $sota_cached_entries = $wpdb->get_results( "SELECT cache_key, lat, lon, source, cached_at FROM {$sota_cache_table_safe} WHERE source NOT IN ('sota','pota') ORDER BY cache_key ASC" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name sanitized via esc_sql()
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $sota_cache_count = count( $sota_cached_entries );
                ?>
                <p style="margin:12px 0 16px;">When your contact map loads, the plugin looks up each callsign&rsquo;s location via QRZ, Callook, or HamQTH and stores the result locally. Locations are cached permanently so the map reflects where each station was at the time of your activation, even if they&rsquo;ve moved since. Summit and park coordinates are cached separately for 90 days and are not listed here.</p>
                <p style="background:#e8f5e9;padding:10px;border-left:4px solid #28a745;margin:10px 0;">
                    <strong>&#10003; Changes on this page take effect immediately.</strong> No Save button needed. Deleting an entry or clearing all cached locations happens the moment you click.
                </p>
                <h2>Cached Callsign Locations</h2>
                <p id="sota-cache-count"><?php echo esc_html( $sota_cache_count ); ?> callsign location(s) cached.</p>

                <?php if ( $sota_cache_count > 0 ) : ?>
                <p><input type="text" id="sota-cache-search" placeholder="Filter by callsign&hellip;" class="regular-text" autocomplete="off" /></p>
                <table class="widefat striped" id="sota-cache-table">
                    <thead>
                        <tr>
                            <th>Callsign</th>
                            <th>Source</th>
                            <th>Lat / Lon</th>
                            <th>Cached</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $sota_source_labels = [ 'callook' => 'Callook', 'hamqth' => 'HamQTH', 'qrz' => 'QRZ' ];
                    foreach ( $sota_cached_entries as $sota_entry ) :
                        $sota_callsign = preg_replace( '/^(loc_|qrz_)/', '', $sota_entry->cache_key );
                        $sota_cached_date = $sota_entry->cached_at ? gmdate( 'Y-m-d', strtotime( $sota_entry->cached_at ) ) : '-';
                    ?>
                        <tr data-callsign="<?php echo esc_attr( strtolower( $sota_callsign ) ); ?>">
                            <td><strong><?php echo esc_html( strtoupper( $sota_callsign ) ); ?></strong></td>
                            <td><?php echo esc_html( $sota_source_labels[ $sota_entry->source ] ?? $sota_entry->source ); ?></td>
                            <td><?php echo esc_html( number_format( (float) $sota_entry->lat, 4 ) ); ?>, <?php echo esc_html( number_format( (float) $sota_entry->lon, 4 ) ); ?></td>
                            <td><?php echo esc_html( $sota_cached_date ); ?></td>
                            <td><button type="button" class="button button-small sota-delete-cache-entry" data-cache-key="<?php echo esc_attr( $sota_entry->cache_key ); ?>">Delete</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p id="sota-no-cache-results" style="display:none;color:#999;margin-top:6px;">No entries match your filter.</p>
                <?php else : ?>
                <p style="color:#999;">No callsign locations are currently cached.</p>
                <?php endif; ?>

                <hr style="margin:30px 0;">
                <h2>Clear All Cached Locations</h2>
                <p class="description" style="color:#c0392b;margin-bottom:10px;"><strong>&#9888; Nuclear option:</strong> Wipes all cached callsign locations site-wide. The next time any contact map loads, every contact without a grid square or summit/park reference will trigger a fresh lookup. Only use this if you believe cached locations are incorrect.</p>
                <button type="button" id="sota-clear-cache-btn" class="button button-secondary">Clear All Cached Locations</button>
                <p id="sota-clear-cache-result" style="margin-top:6px;font-weight:bold;display:none;"></p>
            </div>

            <?php submit_button('Save Settings', 'primary', 'sota_magic_save'); ?>
        </form>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var tabs = document.querySelectorAll('.nav-tab[data-tab]');
        var activeInput = document.getElementById('sota_active_tab');
        var submitRow = document.querySelector('p.submit');
        function toggleSubmit(tabName) {
            if (submitRow) submitRow.style.display = tabName === 'cache' ? 'none' : '';
        }
        toggleSubmit(activeInput ? activeInput.value : 'general');
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                var t = this.dataset.tab;
                activeInput.value = t;
                tabs.forEach(function(el) { el.classList.remove('nav-tab-active'); });
                this.classList.add('nav-tab-active');
                document.querySelectorAll('.sota-tab-panel').forEach(function(p) { p.style.display = 'none'; });
                document.getElementById('sota-tab-' + t).style.display = '';
                toggleSubmit(t);
            });
        });
    });
    </script>
    <?php
}
