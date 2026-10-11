=== Activator Toolkit for SOTA & POTA ===
Contributors: creddick
Tags: sota, pota, amateur radio, ham radio, gpx
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show off SOTA and POTA activations: GPX hike maps, elevation charts, hiking stats, contact tables, and interactive contact maps.

== Description ==

Activator Toolkit is a WordPress plugin for amateur radio operators who activate summits for **Summits on the Air (SOTA)** or parks for **Parks on the Air (POTA)**. Add the Activator Toolkit block to any post or page, pick SOTA or POTA with the toggle at the top of the block, upload your files, and the plugin builds the rest.

**In SOTA mode**, upload your GPX track and your activation log (SOTA CSV, ADIF, or ADI). You get:

* An interactive map of your hike with three base layers (Topographic, OpenStreetMap, Minimal)
* An elevation profile chart that tracks your position on the map as you hover
* The summit's activation zone drawn on the map, with a "Zoom to Activation Zone" button
* Hiking statistics: hiking time, activation time, total time, elevation gain and peak, hiking speed, and total distance
* A contacts table with Summit-to-Summit (S2S) contacts highlighted
* An interactive contact map showing where every station you worked was located

**In POTA mode**, upload the ADIF log you submit to pota.app. You get:

* Your park (or parks) named at the top, looked up automatically from the official POTA park list
* An interactive contact map with a pin at your park and a line to every station you worked
* Park-to-Park (P2P) contacts placed at the other station's park and marked with a P2P badge
* A contacts table with a "Their Park" column and P2P contacts highlighted
* Support for activating several parks at once from the same spot

Both modes show a "Furthest contact" tile on the contact map, and hovering a row in the contacts table highlights that station on the map.

**Your existing posts are safe.** Every block created before POTA support was added stays in SOTA mode. Only blocks you switch to POTA render as POTA.

**No other plugins required.** All map and chart libraries (Leaflet 1.9.4, Chart.js 4.5.1) are bundled with the plugin.

= Features =

* **SOTA / POTA Toggle:** Each block has its own SOTA or POTA mode, so one site can mix summit and park posts. Choose which mode new blocks start in under Settings → Activator Toolkit → General.
* **GPX Track Map (SOTA):** Interactive Leaflet map with Topographic, OpenStreetMap, and Minimal base layers
* **Elevation Chart (SOTA):** Profile chart below the map; hover to see a position dot move along the track
* **Activation Zone Overlay (SOTA):** The most detailed zone available is used automatically: SOTLAS terrain zones first, then Activation.Zone (by N6ARA), then a simple radius circle. You can override the source per post.
* **Intelligent Track Analysis (SOTA):** Splits your outing into hiking time and activation time, with rest breaks tracked separately
* **Park Lookup (POTA):** Your park reference is read from the log (MY_SIG_INFO or MY_POTA_REF) and the park's name and location come from the POTA park database
* **Park-to-Park Detection (POTA):** Contacts who logged their own park are highlighted, placed at that park on the map, and shown with a P2P badge
* **Multiple Log Formats:** SOTA CSV v2, ADIF (.adif), and ADI (.adi); the format is detected automatically
* **Interactive Contact Map:** Lines from your summit or park to every contact, colored by band, with a Furthest Contact tile and a list of any stations that could not be located
* **Smart Station Locations:** Grid squares from your log are used first; otherwise the plugin looks up the callsign with Callook.info (free, US), HamQTH (free account, worldwide), or QRZ.com (XML subscription)
* **Locations Saved at Activation Time:** Each station's location is stored, so your map keeps showing where they were on the day of the activation even if they move later
* **Metric or Imperial Units:** km, m, km/h or mi, ft, mph
* **Fully Customizable:** Colors, S2S and P2P highlight colors, headlines, block width, and fonts in Settings → Activator Toolkit
* **Block Editor Friendly:** Simple Gutenberg block with file upload, auto-detected summit or park reference, and manual override fields
* **Responsive Design:** Works on phones and desktops

= How Activation Time is Calculated (SOTA) =

The plugin finds the summit's activation zone, then counts any time your GPS track spends inside it as activation time. It looks for the zone in this order:

1. **SOTLAS:** Terrain-based zone boundaries at roughly 1 meter detail, when SOTLAS has them for the summit.
2. **Activation.Zone:** Terrain-based zone from the Activation.Zone API by N6ARA, based on elevation data and the official SOTA 25m vertical drop rule.
3. **Radius circle:** A configurable circle (default 50m) around the highest point of your track, used when neither service has a zone for the summit.

You can force a specific source for any post in the block's Statistics Overrides panel.

= How POTA Mode Works =

POTA mode is driven entirely by your log file. Upload the ADIF log you submit to pota.app and the plugin:

1. Reads your park reference from the log (for example US-8144). For a multi-park activation, list several parks separated by commas.
2. Looks up each park's name and official location from the POTA park database.
3. Places a pin at your park and draws a line to each station you worked.
4. Places Park-to-Park contacts at their park, and everyone else at their grid square or callsign location.

POTA does not publish park boundaries, so there is no activation zone in POTA mode. Each park is shown as a single pin at its official location.

= Supported Log Formats =

**SOTA CSV v2** (the format exported from SOTA logging apps and submitted to sota.org):
`V2, MyCall, MySummit, Date (DD/MM/YY), Time, Frequency, Mode, TheirCall, TheirSummit, Comments`

**ADIF / ADI:** Standard amateur radio log files exported from logging software such as HAMRS, Polo, WSJT-X, Log4OM, MacLoggerDX, and most others. Both `.adif` and `.adi` file extensions are accepted, and the format is detected automatically. POTA mode reads the standard POTA fields (MY_SIG / MY_SIG_INFO / MY_POTA_REF for your park, SIG / SIG_INFO / POTA_REF for the other station's park).

= Requirements =

* WordPress 6.0 or later
* PHP 7.4 or later
* A QRZ.com XML subscription is optional. Free Callook.info and HamQTH lookups are tried first.

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Go to Plugins → Add New
3. Search for "Activator Toolkit"
4. Click "Install Now", then "Activate"

= Manual Installation =

1. Download the plugin zip file
2. Go to Plugins → Add New → Upload Plugin
3. Upload the zip and click "Install Now"
4. Activate the plugin

= After Installation =

1. Go to Settings → Activator Toolkit. On the General tab, choose whether new blocks start in SOTA or POTA mode.
2. In any post or page, add the "Activator Toolkit" block
3. Use the toggle at the top of the block to pick SOTA (Summits) or POTA (Parks)
4. For SOTA, upload your GPX track and your log. For POTA, upload your ADIF log.
5. Preview or publish. Your maps and tables appear automatically.

== Frequently Asked Questions ==

= Does it work for Parks on the Air (POTA)? =

Yes. Switch the block to POTA (Parks) using the toggle at the top of the block, then upload your ADIF log. You get a park header, a contact map with your park pinned, and a contacts table with Park-to-Park contacts highlighted.

= Will updating change my existing SOTA posts? =

No. Every block made before POTA support was added stays in SOTA mode. Changing the default mode in Settings only affects brand new blocks.

= How does the plugin find my park? =

From your log. Most POTA logging apps write your park into the MY_SIG_INFO or MY_POTA_REF field, and the plugin reads it automatically. You can also type or correct the park reference in the block's sidebar.

= Can I activate more than one park at once? =

Yes. Enter the parks separated by commas (for example `US-1254, US-3424`). Each park gets a pin on the map and is listed at the top. Distances are measured from the first park.

= Why is there no activation zone in POTA mode? =

POTA only publishes a single point for each park, not its boundary, so each park is shown as a pin at its official location.

= Can I upload a GPX track in POTA mode? =

Not yet. POTA mode is log only for now. Hike maps and statistics are available in SOTA mode.

= Do I need any other plugins? =

No. All mapping libraries are bundled with the plugin.

= How does the plugin determine hiking vs. activation time? =

It finds the activation zone (SOTLAS first, then Activation.Zone, then a radius circle around the highest point) and counts all time your track spends inside the zone as activation time. See "How Activation Time is Calculated" above.

= What is Activation.Zone? =

Activation.Zone is a free tool by N6ARA that calculates the SOTA activation zone for a summit from elevation data and the official 25m vertical drop rule. No account required.

= What is SOTLAS? =

SOTLAS is a popular SOTA mapping site that publishes high-detail activation zones for many summits. When it has a zone for your summit, the plugin uses it first.

= What if no zone service has my summit? =

The plugin falls back to a radius circle around the highest point of your track. Statistics are still calculated, and the zone shows as an orange dashed circle instead of a red polygon.

= Do I need a QRZ subscription? =

No. Station locations are found in this order: grid squares in your log, then Callook.info (free, US callsigns), then HamQTH (free account, worldwide), then QRZ.com if you have an XML subscription. Stations that still cannot be located are listed in a "No location found" panel on the map rather than silently dropped.

= What log file formats are supported? =

SOTA CSV v2, ADIF (.adif), and ADI (.adi). The format is detected automatically. If the file does not include your summit or park reference, the block prompts you to enter one.

= Can I use Maidenhead grid squares for contact locations? =

Yes. A grid square in the ADIF GRIDSQUARE field, or a 4 or 6 character grid in the contact's comments, is used to place the contact on the map without any lookup service.

= Can I override the calculated statistics? =

Yes. In SOTA mode, the block's sidebar has manual override fields for hiking distance, hiking time, activation time, rest breaks, and total time, plus a choice of activation zone source.

= Can I use multiple blocks on one page? =

Yes. Each Activator Toolkit block is independent, and SOTA and POTA blocks can sit on the same page.

= Can I switch between metric and imperial units? =

Yes. Settings → Activator Toolkit → Display → Unit System. All statistics and charts convert automatically.

= Can I customize the appearance? =

Yes. Settings → Activator Toolkit lets you set background and text colors, S2S and P2P highlight colors, headlines, block width, and whether to use your theme's font.

== Screenshots ==

1. SOTA: GPX track map with elevation chart and "Zoom to Activation Zone" button
2. SOTA: Hiking statistics showing time, distance, elevation, and speed
3. SOTA: Contact table with Summit-to-Summit highlighting
4. Interactive contact map showing QSO locations by band
5. Block editor with file upload and manual override panel
6. Settings page
7. POTA: Contact map with the park pinned, a Park-to-Park contact popup, and the Furthest Contact tile
8. POTA: Contacts table with the Their Park column and Park-to-Park contacts highlighted
9. POTA: Block editor with the SOTA / POTA toggle and the auto-detected park reference
10. Settings: General tab with a plain-language overview and the default mode choice
11. Settings: Parks (POTA) tab

== Changelog ==

= 1.2.0 =
* New: Parks on the Air (POTA) support. Each block has a SOTA / POTA toggle; POTA mode takes your ADIF log and shows your park, a contact map with your park pinned, and a contacts table
* New: Park lookups from the POTA park database, with names and official locations; activations from several parks at once are supported
* New: Park-to-Park (P2P) contacts are placed at their park on the map, highlighted in the table, and marked with a P2P badge, with their own colors in Settings
* New: "Furthest contact" tile on the contact map, for both SOTA and POTA; click it to jump to that station
* New: SOTLAS activation zones (about 1 meter detail) are now used first, then Activation.Zone, then the radius circle; each post can override the zone source
* New: Settings reorganized into General, Summits (SOTA), Parks (POTA), Display, Callsign Lookup, Developer, and Cache tabs; the General tab explains the plugin and sets the default mode for new blocks
* New: Hike statistics show Total Distance; Elevation Gain and Peak Elevation are combined into one tile
* Improve: Plugin renamed to "Activator Toolkit for SOTA & POTA" with a new logo and banner
* Improve: A log file with no contacts now shows a short message instead of an empty map and table
* Improve: Existing posts are unaffected. Blocks made before this version always stay in SOTA mode

= 1.1.10 =
* Fix: Replaced the "Minimal" map tile source (CartoDB), which began requiring an API key and showing an "API KEY REQUIRED" watermark, with OpenStreetMap tiles plus a muted CSS filter: same look, no API key needed. Affects both the Contact Map and the GPX map's Minimal layer.
* Improve: "Minimal" is now the default map style for new plugin installs
* Improve: Tested up to WordPress 7.1

= 1.1.9 =
* New: Small trash icon appears next to the GPX and log file upload boxes in the block editor once a file is attached, letting you clear just that file without deleting the whole block

= 1.1.8 =
* Fix: Log file uploads (.ADI/.ADIF) with uppercase extensions were rejected as an invalid file type; extension matching is now case-insensitive

= 1.1.7 =
* Improve: Contacts table Date and Time merged into a single column; time displayed smaller and dimmed below the date for a cleaner layout

= 1.1.6 =
* Improve: Contact location lookup now checks ADIF gridsquare field before falling back to comments or callsign lookup services
* Improve: Summit-to-Summit contacts are now prioritized first in location lookup hierarchy; grid square (ADIF field or comments) comes second; callsign lookup services are used only when no grid is available
* Improve: Settings → Callsign Lookup tab now explains the full location lookup hierarchy in plain language
* Improve: ADIF frequency values are normalized to a minimum of 3 decimal places (144.2 → 144.200); up to 5 decimal places are preserved when present

= 1.1.5 =
* Improve: WordPress 7.0 compatibility: block editor uses current wp.blockEditor API instead of deprecated wp.editor for MediaUpload
* Improve: Block editor buttons use current variant prop instead of deprecated isPrimary/isSecondary
* Improve: Block category updated from deprecated 'common' to 'text'
* Improve: Tested up to WordPress 7.0
* Improve: Contact table now shows your summit reference as a subtitle above the table; "My Summit" column removed from individual rows to reduce clutter
* Improve: Block Width setting now offers pixel presets (700–1200px) and a custom pixel input, in addition to the existing Wide and Full options
* Improve: Contact map loading animation now appears immediately when the iframe loads; callsign lookups happen in the background instead of blocking the initial render

= 1.1.4 =
* New: ADIF log file support: upload .adif or .adi files in addition to SOTA CSV; format is detected automatically
* New: Modal prompts for summit reference when log file does not include one (works for both CSV and ADIF)
* New: Summit Reference field in block inspector sidebar for easy review and correction after upload
* New: Hovering a row in the contacts table highlights the corresponding marker on the contact map
* Improve: Contacts table sorted oldest-to-newest regardless of order in the source file
* Fix: Contact map markers now reliably open on first click (polylines were intercepting click events)
* Fix: Contact map zoom now correctly fits all contacts without clipping at the edges

= 1.1.3 =
* Improve: Hike statistics tiles now display in a fixed 2×3 grid for a cleaner, balanced layout

= 1.1.2 =
* Improve: Summit marker on GPX map and contact map now uses minimalist SVG mountain icon instead of emoji
* Improve: Contact map loading overlay now uses SVG mountain icon instead of emoji
* Improve: Activation Zone button now toggles between "Zoom to Activation Zone" and "Zoom to Full Track"
* Fix: Removed artificial maxZoom cap on Activation Zone button zoom
* Fix: Contact map initial zoom now correctly fits all contacts within the visible frame

= 1.1.1 =
* New: Granular cache management tab: view, filter, and delete cached callsign locations individually
* Improve: Plugin UI icons replaced with a consistent minimalist SVG icon set
* Improve: Visual refresh across stats display and settings pages
* Fix: Contact map fitBounds timing corrected for reliable initial zoom on all themes

= 1.1.0 =
* New: Callook.info lookup: automatic, free, no account needed; resolves US callsigns from FCC data
* New: HamQTH lookup: free account, international coverage; runs after Callook for non-US callsigns
* Improve: Callsign lookup now tries Callook → HamQTH → QRZ in priority order before marking a contact unresolved
* Improve: Settings page reorganized into tabs: Display, Callsign Lookup, GPX Track, Developer
* Improve: Cache clearing now preserves S2S summit location cache; only callsign lookup entries are cleared
* Fix: Debug Mode (public) checkbox was not being saved: now works correctly

= 1.0.7 =
* Fix: QRZ password now preserved exactly as entered: special characters no longer stripped before encryption

= 1.0.6 =
* Fix: Contact map assets now use wp_enqueue_style(), wp_enqueue_script(), and wp_add_inline_script() with wp_print_styles()/wp_print_scripts() called manually: correct WordPress API usage for a standalone HTML page served via wp_ajax_*

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
* FIXED: All WordPress Plugin Check tool errors and warnings resolved: passes clean with no ERRORs

= 1.0.3 =
* FIXED: Contact map initial zoom now fits all contacts and summit optimally: map waits for container to fully render before fitting bounds

= 1.0.2 =
* NEW: Persistent location cache using a dedicated `wp_sota_magic_locations` database table: QRZ callsign locations stored permanently for historical accuracy; SOTA summit coordinates cached 90 days
* NEW: "No location found" panel on contact map: contacts that could not be located are listed rather than silently dropped
* NEW: Debug Mode split into two options: admin-only (safe to leave on) and public (for testing while logged out)
* IMPROVED: QRZ callsign lookups now include a user-agent header, fixing lookups that were silently failing
* IMPROVED: Settings page version number is now read dynamically from the plugin header: always stays in sync
* IMPROVED: Debug panel now shows unresolved contact count, reason per callsign, and raw QRZ XML response for failed lookups
* FIXED: Hiking speed statistic not recalculating when hiking distance or time manual overrides were applied
* FIXED: QRZ password now stored encrypted (AES-256-CBC) rather than plain text

= 1.0.1 =
* NEW: Per-post "Hide GPX hike statistics from post" checkbox in block editor: suppresses stats display while keeping map, activation zone, and contact map fully functional
* FIXED: Contact map lines not drawing: SOTA API summit lookup was broken by missing stream context and user-agent header
* FIXED: Elevation chart showing dead space on the right: Chart.js was auto-extending x-axis beyond actual track length
* ADDED: Admin-only debug panel on the contact map (visible only when Debug Mode is enabled in Settings)

= 1.0.0 =
* MAJOR: Plugin is now fully standalone: no other plugins required
* NEW: Self-contained Leaflet 1.9.4 map replaces WP-GPX Maps dependency
* NEW: Elevation profile chart (Chart.js 4.4.0) below the map with hover-to-map interaction
* NEW: "Zoom to Activation Zone" button above the map
* NEW: Three selectable base layers: Topographic, OpenStreetMap, CartoDB Minimal
* NEW: Activation zone overlay uses precise red polygon (API) or orange circle (fallback)
* IMPROVED: All map libraries (Leaflet, Chart.js) bundled locally: no CDN calls
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

= 1.2.0 =
Adds Parks on the Air (POTA) support with a SOTA / POTA toggle on each block. Your existing SOTA posts are not changed.

= 1.0.0 =
Major release. The WP-GPX Maps plugin dependency has been completely removed. If you had WP-GPX Maps installed only for this plugin, you may now deactivate it. All map and chart functionality is now built in.

== External Services ==

This plugin connects to the following external services. By using this plugin you agree to their respective terms.

**SOTA API** (api2.sota.org.uk)
SOTA mode only. Used to retrieve official summit coordinates for the summit marker and Summit-to-Summit contact locations. No authentication required. No personal data is sent.
Terms: https://www.sota.org.uk

**SOTLAS activation zones** (az.sotl.as)
SOTA mode only. Used to retrieve high-detail activation zone boundaries for a summit. Only the summit reference is sent. Results are cached for up to a year.
Terms: https://sotl.as

**Activation.Zone API** (api.activation.zone)
SOTA mode only. Used to retrieve the activation zone for a summit when SOTLAS has none. The summit reference, coordinates, and elevation are sent. Created by N6ARA.
Terms: https://activation.zone

**POTA API** (api.pota.app)
POTA mode only. Used to look up a park's name and official location from its park reference (for example US-8144). Only the park reference is sent. Results are cached for 90 days.
Terms: https://pota.app

**Callook.info** (callook.info)
Used to look up the location of US callsigns for the contact map when the log has no grid square. Only the callsign is sent. No account required.
Terms: https://callook.info

**HamQTH** (www.hamqth.com)
Used to look up callsign locations worldwide when HamQTH credentials are entered in settings. Your HamQTH username and password are sent to HamQTH for authentication.
Terms: https://www.hamqth.com

**QRZ.com XML API** (xmldata.qrz.com)
Used to look up callsign locations when QRZ credentials are entered in settings. Your QRZ username and password are sent to QRZ.com for authentication.
Terms: https://www.qrz.com/page/terms_of_service.html

**OpenStreetMap tile servers** (tile.openstreetmap.org)
Used for the OpenStreetMap and Minimal base map layers. Standard tile requests, including your visitors' IP addresses, are sent to OpenStreetMap servers.
Terms: https://wiki.osmfoundation.org/wiki/Terms_of_Use

**OpenTopoMap tile servers** (tile.opentopomap.org)
Used for the Topographic base map layer. Standard tile requests are sent to OpenTopoMap servers.
Terms: https://opentopomap.org/about

== Privacy Policy ==

Activator Toolkit does not collect, store, or transmit any personal data beyond what is described in the External Services section above. GPX and log files are stored in your WordPress media library and processed on your own server. Lookup service credentials are stored in your WordPress options table and are only ever sent to the service they belong to.
