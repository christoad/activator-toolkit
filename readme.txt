=== Activator Toolkit for SOTA ===
Contributors: creddick
Tags: sota, amateur radio, ham radio, gpx, mapping
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.0.6
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display your SOTA activation data beautifully — GPX maps, elevation charts, hiking stats, contact tables, and a contact map. No other plugins needed.

== Description ==

Activator Toolkit for SOTA is a WordPress plugin for amateur radio operators participating in Summits On The Air (SOTA). Add the Activator Toolkit block to any post or page, upload your GPX track and SOTA CSV log, and the plugin automatically generates:

* An interactive GPX track map with three selectable base layers (Topographic, OpenStreetMap, Minimal)
* An elevation profile chart with hover-to-map interaction
* A "Zoom to Activation Zone" button showing the precise activation boundary
* Hiking statistics: time, distance, elevation gain/loss, average speed, and peak elevation
* A contacts table with automatic Summit-to-Summit (S2S) highlighting
* An interactive contact map showing where your QSOs were located

**No other plugins required.** All map libraries (Leaflet 1.9.4, Chart.js 4.5.1) are bundled with the plugin.

= Features =

* **Standalone GPX Map** — Interactive Leaflet map with Topographic, OpenStreetMap, and CartoDB Minimal base layers
* **Elevation Chart** — Profile chart below the map; hover to see a position dot track across the map
* **Activation Zone Overlay** — Precise terrain-based polygon from the Activation.Zone API (by N6ARA), or a radius circle fallback
* **Zoom to Activation Zone** — One-click button to zoom the map to the activation zone boundary
* **Summit Peak Marker** — 🏔️ marker at the highest point in your track
* **Intelligent Track Analysis** — Automatically calculates hiking time vs. activation time using the Activation.Zone API or a configurable radius fallback
* **Rest Break Tracking** — Tracked separately and shown as a sub-note under hiking time
* **Metric or Imperial Units** — Choose km/m/km/h or mi/ft/mph in settings
* **Contact Log Tables** — Responsive, horizontally-scrollable tables showing all contacts
* **S2S Highlighting** — Automatic detection and custom color highlighting for Summit-to-Summit contacts
* **Interactive Contact Map** — Shows contact locations by band, with lines to the summit; contacts with a grid square in Comments are plotted without any external service; S2S contacts use the free SOTA API; all other contacts use QRZ.com XML lookups (requires a QRZ XML subscription)
* **Maidenhead Grid Support** — Contacts with a grid square in the comments field are plotted automatically
* **Fully Customizable** — Colors, fonts, headlines, and display options in Settings → Activator Toolkit for SOTA
* **Block Editor Compatible** — Simple Gutenberg block with file upload and manual override fields
* **Responsive Design** — Works on mobile and desktop

= How Activation Time is Calculated =

The plugin uses two methods to determine the activation zone, applied in priority order:

**Method 1: Activation.Zone API (Primary)**
Queries api.activation.zone (by N6ARA) using your summit reference from the CSV file. The API returns a precise polygon based on terrain elevation data and the official SOTA 25m vertical drop rule. All time spent inside this polygon counts as activation time.

**Method 2: Radius Fallback (Automatic)**
If the API is disabled or unavailable, the plugin draws a configurable circle (default 50m) around the highest GPS point. Configurable in Settings → Activator Toolkit for SOTA (20–200m).

= CSV Format =

The plugin expects SOTA CSV v2 format:
`V2, MyCall, MySummit, Date (DD/MM/YY), Time, Frequency, Mode, TheirCall, TheirSummit, Comments`

= Requirements =

* WordPress 6.0 or later
* PHP 7.4 or later
* QRZ.com XML subscription (optional — only needed for contact map location lookups)

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Navigate to Plugins → Add New
3. Search for "SOTA Magic"
4. Click "Install Now" then "Activate"

= Manual Installation =

1. Download the plugin zip file
2. Navigate to Plugins → Add New → Upload Plugin
3. Upload the zip and click "Install Now"
4. Activate the plugin

= After Installation =

1. Go to Settings → Activator Toolkit for SOTA and configure your preferences
2. In any post or page, add the "Activator Toolkit" block
3. Upload your GPX file and/or SOTA CSV file
4. Publish or preview — your activation data appears automatically

== Frequently Asked Questions ==

= Do I need any other plugins? =

No. As of version 1.0.0, SOTA Magic is fully standalone. All mapping libraries are bundled with the plugin. No WP GPX Maps or any other plugin is required.

= How does the plugin determine hiking vs. activation time? =

**Primary method — Activation.Zone API:**
Queries api.activation.zone (by N6ARA) for a precise terrain-based zone polygon. Requires a summit reference in your CSV file. All time inside the polygon = activation time.

**Fallback method — Radius approximation:**
A circle of configurable radius (default 50m) around the highest GPS point. Used automatically if the API is disabled or unavailable.

= What is Activation.Zone? =

Activation.Zone is a free tool by N6ARA that calculates the precise SOTA activation zone for any summit using Digital Elevation Model (DEM) data and the official 25m vertical drop rule. SOTA Magic integrates with their public API. No account required.

= What if the Activation.Zone API is unavailable? =

The plugin automatically falls back to the radius method. Statistics are still calculated; the activation zone will show as an orange dashed circle instead of a red polygon.

= Do I need a QRZ subscription? =

Only for the contact map's fallback location lookup. The contact map resolves locations in priority order: (1) Maidenhead grid squares in the Comments field — no QRZ needed; (2) S2S contacts via the free SOTA API — no QRZ needed; (3) all other contacts via the QRZ.com XML API.

QRZ XML access requires a paid QRZ subscription — either the "XML Logbook Data" plan (~$35.95/year) or the Platinum plan. A free QRZ account does not include XML access. Contacts that cannot be located are shown in a "No location found" panel on the map rather than silently dropped.

= Can I use Maidenhead grid squares for contact locations? =

Yes. If a 4- or 6-character grid square appears anywhere in the contact's Comments field, SOTA Magic will use it to plot the contact on the map. This works without QRZ credentials.

= Can I override the calculated statistics? =

Yes. The Activator Toolkit block editor panel includes manual override fields for hiking distance, hiking time, activation time, rest breaks, and total time. Check the box next to a field to enable the override.

= Can I force the radius method instead of the API? =

Yes. In the block editor, check "Activation Zone: Radius-based (API skipped)" in the Manual Overrides panel. Or disable the API globally in Settings → Activator Toolkit for SOTA.

= Can I use multiple blocks on one page? =

Yes. Each Activator Toolkit block is fully independent. Multiple activations can appear on the same post or page.

= Can I switch between metric and imperial units? =

Yes — Settings → Activator Toolkit for SOTA → Unit System. All statistics and the elevation chart convert automatically.

= Can I customize the appearance? =

Yes — Settings → Activator Toolkit for SOTA lets you set background color, text color, transparent background, S2S highlight colors, and whether to use your theme's font.

== Screenshots ==

1. GPX track map with elevation chart and "Zoom to Activation Zone" button
2. Hiking statistics grid showing time, distance, elevation, and speed
3. Contact table with S2S highlighting
4. Interactive contact map showing QSO locations by band
5. Block editor interface with file upload and manual override panel
6. Settings page

== Changelog ==

= 1.0.6 =
* Fix: Contact map assets now use wp_enqueue_style(), wp_enqueue_script(), and wp_add_inline_script() with wp_print_styles()/wp_print_scripts() called manually — correct WordPress API usage for a standalone HTML page served via wp_ajax_*

= 1.0.5 =
* Fix: Contact map JS moved to external contact-map.js; inline script reduced to a single JSON data assignment (wp_localize_script pattern)
* Fix: phpcs:ignore annotations added for stylesheet/script tags in standalone AJAX page where wp_enqueue hooks are unavailable
* Fix: Stable tag updated to match plugin version header
* Improve: Block name updated to "SOTA Activator Toolkit"
* Improve: Settings page explains QRZ XML subscription requirement with link to QRZ.com
* Improve: Settings page explains why QRZ locations are cached permanently (historical accuracy at time of activation)
* Improve: QRZ cache clear button moved from block editor to Settings page with nuclear-option warning
* Improve: Plugin logo updated to new 128x128 artwork

= 1.0.4 =
* IMPROVED: Plugin renamed to Activator Toolkit for SOTA; all files and references updated from sota-magic to activator-toolkit
* IMPROVED: All remote HTTP calls replaced with wp_remote_get() / wp_remote_post() (WordPress HTTP API)
* IMPROVED: All inline styles and scripts replaced with wp_enqueue_style(), wp_add_inline_style(), and wp_add_inline_script()
* IMPROVED: Contact map served via admin-ajax.php instead of direct file access
* IMPROVED: Added sanitize callbacks to all register_setting() calls
* IMPROVED: Chart.js updated from 4.4.0 to 4.5.1
* FIXED: All WordPress Plugin Check tool errors and warnings resolved — passes clean with no ERRORs

= 1.0.3 =
* FIXED: Contact map initial zoom now fits all contacts and summit optimally — map waits for container to fully render before fitting bounds

= 1.0.2 =
* NEW: Persistent location cache using a dedicated `wp_sota_magic_locations` database table — QRZ callsign locations stored permanently for historical accuracy; SOTA summit coordinates cached 90 days
* NEW: "No location found" panel on contact map — contacts that could not be located are listed rather than silently dropped
* NEW: Debug Mode split into two options — admin-only (safe to leave on) and public (for testing while logged out)
* IMPROVED: QRZ callsign lookups now include a user-agent header, fixing lookups that were silently failing
* IMPROVED: Settings page version number is now read dynamically from the plugin header — always stays in sync
* IMPROVED: Debug panel now shows unresolved contact count, reason per callsign, and raw QRZ XML response for failed lookups
* FIXED: Hiking speed statistic not recalculating when hiking distance or time manual overrides were applied
* FIXED: QRZ password now stored encrypted (AES-256-CBC) rather than plain text

= 1.0.1 =
* NEW: Per-post "Hide GPX hike statistics from post" checkbox in block editor — suppresses stats display while keeping map, activation zone, and contact map fully functional
* FIXED: Contact map lines not drawing — SOTA API summit lookup was broken by missing stream context and user-agent header
* FIXED: Elevation chart showing dead space on the right — Chart.js was auto-extending x-axis beyond actual track length
* ADDED: Admin-only debug panel on the contact map (visible only when Debug Mode is enabled in Settings)

= 1.0.0 =
* MAJOR: Plugin is now fully standalone — no other plugins required
* NEW: Self-contained Leaflet 1.9.4 map replaces WP-GPX Maps dependency
* NEW: Elevation profile chart (Chart.js 4.4.0) below the map with hover-to-map interaction
* NEW: "Zoom to Activation Zone" button above the map
* NEW: Three selectable base layers — Topographic, OpenStreetMap, CartoDB Minimal
* NEW: Activation zone overlay uses precise red polygon (API) or orange circle (fallback)
* IMPROVED: All map libraries (Leaflet, Chart.js) bundled locally — no CDN calls
* IMPROVED: GPX track points sampled to max 800 for efficient JSON delivery
* IMPROVED: Summit coordinates fall back to highest track point when CSV is absent
* FIXED: Removed all dependency on WP-GPX Maps plugin
* FIXED: Decimal-precision hack and .wpgpxmaps CSS workarounds removed

= 0.607 Beta =
* Manual override fields for hiking distance, time, activation time, rest breaks, total time
* Force-radius toggle to skip activation.zone API per block
* Help modal explaining how each statistic is calculated
* Debug mode for admins showing API call results

= 0.517 Beta =
* Methodology indicator on Activation Time stat box
* Shows API-based vs radius method in the UI
* Links to activation.zone when API is active

= 0.512 Beta =
* Visual activation zone overlay on GPX maps (via WP-GPX Maps + Leaflet interception)
* Red polygon when using activation.zone API; orange dashed circle for radius fallback
* Summit peak 🏔️ marker on map

= 0.511 Beta =
* Integration with activation.zone API (by N6ARA)
* Point-in-polygon algorithm for accurate zone detection
* Automatic fallback to radius method

= 0.510 Beta =
* Rest breaks now included in hiking time with separate sub-note display
* All time properly accounted for across hiking + activation + rest

= 0.509 Beta =
* Complete rewrite of GPX analysis algorithm
* Activation zone detection using summit peak location
* Configurable activation zone radius and rest break threshold

= 0.508 Beta =
* Intelligent GPX track analysis: hiking vs activation time
* Statistics grid with icons
* Configurable stationary speed threshold

= 0.507 Beta =
* Responsive table design with horizontal scrolling
* Long comments wrap within column

== Upgrade Notice ==

= 1.0.0 =
Major release. The WP-GPX Maps plugin dependency has been completely removed. If you had WP-GPX Maps installed only for SOTA Magic, you may now deactivate it. All map and chart functionality is now built in.

== External Services ==

This plugin connects to the following external services. By using this plugin you agree to their respective terms.

**SOTA API** (api2.sota.org.uk)
Used to retrieve official summit coordinates (for the summit marker and S2S contact locations). No authentication required. No personal data is sent.
Terms: https://www.sota.org.uk

**Activation.Zone API** (api.activation.zone)
Used to retrieve the precise SOTA activation zone polygon for a given summit reference. No authentication required. The summit reference, coordinates, and elevation are sent to the API. Created by N6ARA.
Terms: https://activation.zone

**QRZ.com XML API** (xmldata.qrz.com)
Used to look up contact operator locations for the contact map. Only contacted when the contact map is enabled and QRZ credentials are provided in settings. Your QRZ username and password are sent to QRZ.com for authentication.
Terms: https://www.qrz.com/page/terms_of_service.html

**OpenStreetMap tile servers** ({s}.tile.openstreetmap.org)
Used as a base map layer option. Standard tile requests including your IP address are sent to OpenStreetMap servers.
Terms: https://wiki.osmfoundation.org/wiki/Terms_of_Use

**OpenTopoMap tile servers** ({s}.tile.opentopomap.org)
Used as the default base map layer (topographic). Standard tile requests are sent to OpenTopoMap servers.
Terms: https://opentopomap.org/about

**CartoDB/CARTO tile servers** ({s}.basemaps.cartocdn.com)
Used as a minimal base map layer option. Standard tile requests are sent to CARTO servers.
Terms: https://carto.com/legal/

== Privacy Policy ==

SOTA Magic does not collect, store, or transmit any personal data beyond what is described in the External Services section above. GPX files and CSV files are stored in your WordPress media library and processed on your own server. QRZ.com credentials are stored in your WordPress options table and are never transmitted to anyone other than QRZ.com.
