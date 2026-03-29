=== SOTA Magic ===
Contributors: KI6CR
Tags: sota, amateur radio, ham radio, gpx, contacts, mapping
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 0.517 Beta
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Block-based SOTA activation data uploader with intelligent GPX track analysis using activation.zone API, contact tables, and interactive mapping.

== Description ==

SOTA Magic is a comprehensive WordPress plugin designed for amateur radio operators participating in Summits On The Air (SOTA) activations. Display your activation data beautifully on your WordPress site with GPX track maps, detailed contact logs, and interactive contact mapping.

**NEW in 0.512 Beta:** Visual activation zone overlay on GPX maps - see exactly where the activation zone is!

= Features =

* **GPX Track Visualization** - Upload and display your activation GPS tracks with interactive maps
* **Visual Activation Zone Overlay** - Red polygon/circle overlaid on map showing the exact activation zone
* **Intelligent Track Analysis** - Automatically calculates hiking time vs. summit activation time
* **Activation.Zone API Integration** - Uses N6ARA's activation.zone API for precise activation zone based on 25m elevation drop (per SOTA rules)
* **Automatic Fallback** - Falls back to radius method if API unavailable (shown as dashed orange circle)
* **Summit Peak Marker** - 🏔️ marker shows the highest point in your track
* **Activation Zone Detection** - Accurately identifies when you're at the summit vs. hiking
* **Rest Break Tracking** - Tracks rest breaks separately but includes them in hiking time
* **Activity Statistics** - Display hiking distance, elevation gain/loss, speeds, and time breakdowns
* **Metric or Imperial Units** - Choose your preferred unit system (km/m or mi/ft)
* **Contact Log Tables** - Beautiful, responsive tables showing all your activation contacts
* **Summit-to-Summit (S2S) Highlighting** - Automatic detection and highlighting of S2S contacts
* **Interactive Contact Map** - Visual map showing where your contacts are located
* **QRZ Integration** - Automatic location lookup for contacts via QRZ.com API
* **Fully Customizable** - Customize colors, fonts, headlines, and display options
* **Responsive Design** - Tables automatically adapt to mobile devices with horizontal scrolling
* **Block Editor Compatible** - Easy-to-use Gutenberg block for adding SOTA data
* **Transparent Background Option** - Blend seamlessly with your theme

= How It Works - The Methodology =

**GPX Track Analysis Algorithm:**

The plugin uses an intelligent hybrid approach with two methods for determining the activation zone:

**Method 1: Activation.Zone API (Primary - Most Accurate)**

When enabled (default), the plugin queries activation.zone (created by N6ARA) for the precise activation zone geometry:

1. **Extract Summit Reference** - Gets the summit reference (e.g., W6/CC-001) from your CSV file
2. **Query API** - Sends summit coordinates and elevation to api.activation.zone
3. **Receive Polygon** - Gets back the exact activation zone boundary based on terrain elevation data
4. **Point-in-Polygon Check** - Uses ray-casting algorithm to determine if each GPS point is within the zone

This method uses actual terrain data and the official SOTA rule of 25 meters vertical drop from the summit peak, providing the most accurate activation zone possible.

**Method 2: Radius Fallback (Automatic)**

If the API is disabled or unavailable, the plugin automatically falls back to a simpler method:

1. **Summit Peak Detection**
   - Analyzes your entire GPX track to find the highest elevation point
   - This point is assumed to be at or very near the SOTA summit
   - Coordinates of this peak become the center of the "activation zone"

2. **Activation Zone Definition**
   - Creates a circular zone around the summit peak (default: 50 meters radius)
   - This is configurable in settings (20-200 meters)
   - Any time spent stationary within this zone is classified as "Activation Time"

**Rest Break Tracking:**

3. **Rest Break Filtering**
   - All stationary time outside the activation zone is counted as hiking time
   - Rest breaks are tracked separately and shown as a sub-note
   - This ensures complete time accounting

4. **Speed-Based Movement Detection**
   - Movement between GPS points is analyzed for speed
   - Below threshold (default 0.3 km/h) = stationary
   - Above threshold = hiking/moving

5. **Time Classification**
   - **Hiking Time:** All time outside the activation zone (moving + rest breaks)
   - **Activation Time:** Stationary time within activation zone
   - **Rest Breaks:** Tracked separately and shown as sub-note under hiking time
   
   This ensures your total time is fully accounted for, with rest breaks properly attributed to hiking time rather than being lost or excluded.

**Contact Map Location Sources:**

Contact locations are determined using multiple data sources:

1. **Summit-to-Summit (S2S) Contacts:**
   - Exact summit coordinates retrieved from SOTA API (api2.sota.org.uk)
   - Provides precise location of the other activator's summit
   - No QRZ lookup required for S2S contacts

2. **Regular Contacts:**
   - Station location from QRZ.com XML API
   - Uses latitude/longitude from operator's QRZ profile
   - Requires valid QRZ.com credentials (XML subscription)
   - Rate-limited to 0.5 seconds between lookups to respect QRZ terms

3. **Fallback:**
   - If location data is unavailable, contact won't appear on map
   - Contact will still appear in the contacts table

**Distance and Elevation Calculations:**

- Uses Haversine formula for accurate great-circle distances
- Accounts for Earth's curvature (essential for longer hikes)
- Elevation data comes directly from GPX track points
- All conversions between metric/imperial use standard factors:
  - 1 km = 0.621371 miles
  - 1 meter = 3.28084 feet

= How GPX Analysis Works =

The plugin analyzes your GPS track to intelligently distinguish between:
- **Hiking Time**: Periods when you're moving above a configurable speed threshold (default: 0.3 km/h)
- **Activation Time**: Stationary periods when you're operating your radio
- **Total Statistics**: Complete breakdown of distance, elevation, and time

The analysis displays:
- Hiking time and distance
- Activation (stationary) time
- Total time and distance
- Elevation gain and loss
- Average hiking speed
- Peak and base elevations

= How to Use =

1. Install and activate the plugin
2. Go to Settings → SOTA Magic to configure your preferences
3. In any post or page, add the "SOTA DATA" block
4. Upload your GPX file (GPS track) and/or CSV file (contacts)
5. Publish and view your activation data with statistics!

= CSV Format =

The plugin expects SOTA CSV v2 format with the following columns:
V2, MyCall, MySummit, Date (DD/MM/YY), Time, Frequency, Mode, TheirCall, TheirSummit, Comments

= Requirements =

* WP GPX Maps plugin (for GPX track visualization)
* QRZ.com account (optional, for contact mapping feature)

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Navigate to Plugins → Add New
3. Search for "SOTA Magic"
4. Click "Install Now" and then "Activate"

= Manual Installation =

1. Download the plugin zip file
2. Log in to your WordPress admin panel
3. Navigate to Plugins → Add New → Upload Plugin
4. Choose the downloaded zip file and click "Install Now"
5. Activate the plugin

= After Installation =

1. Go to Settings → SOTA Magic
2. Configure your preferences (headlines, colors, etc.)
3. Enable "Show GPX Statistics" to display hiking/activation analysis
4. Adjust the stationary speed threshold if needed (default: 0.3 km/h works well)
5. If using the contact map feature, enter your QRZ.com username and password
6. Start adding SOTA data blocks to your posts and pages!

== Frequently Asked Questions ==

= What file formats does the plugin support? =

The plugin supports GPX files for GPS tracks and CSV files for contact logs. The CSV should be in SOTA CSV v2 format.

= How does the plugin determine hiking vs. activation time? =

The plugin uses two methods to determine the activation zone:

**Primary Method: Activation.Zone API (Most Accurate)**
- Queries api.activation.zone (by N6ARA) for precise zone geometry
- Uses actual terrain elevation data and SOTA's 25m vertical drop rule
- Provides the most accurate activation zone possible
- Enabled by default, can be disabled in settings

**Fallback Method: Radius Approximation**
- Uses a configurable radius (default 50m) around highest GPS point
- Automatically used if API is disabled or unavailable
- Still quite accurate for most activations

Time spent stationary within the activation zone = activation time. All other time = hiking time (including rest breaks, which are tracked separately).

= What is activation.zone and how does it work? =

Activation.zone is a tool created by N6ARA (Ara) that calculates the precise SOTA activation zone for any summit. It uses Digital Elevation Model (DEM) data to find all points within 25 meters vertical drop from the summit peak, following official SOTA rules. Our plugin integrates with their API to get this precise geometry for your activations.

= Do I need an activation.zone account? =

No! The activation.zone API is publicly available and doesn't require an account. The plugin automatically queries it when you have both a GPX file and CSV file with summit reference.

= What if the activation.zone API is down? =

The plugin automatically falls back to the radius method (50m circle around highest point). Your statistics will still calculate, just with slightly less precision. You can also manually disable the API in settings if you prefer to always use the radius method.

= Can I see the activation zone on the map? =

Yes! Version 0.512+ overlays the activation zone directly on your GPX map:

- **Red polygon with solid line**: Precise zone from activation.zone API
- **Orange circle with dashed line**: Fallback radius estimate
- **🏔️ marker**: Shows the summit peak (highest point in your track)

Click on the overlay to see details about the activation zone. This visual confirmation helps you verify where you operated from and understand the zone boundaries.

= Why are there two different overlay styles? =

The red solid polygon appears when the activation.zone API successfully returns the precise terrain-based activation zone. The orange dashed circle appears when using the fallback radius method (either because the API is disabled or unavailable). This visual distinction helps you understand which method was used for the analysis.

= The activation zone overlay doesn't appear on my map. How can I troubleshoot? =

1. **Check browser console** (F12 → Console tab) for "SOTA Magic" messages
2. **Look for errors** - the console will show what's happening
3. **Verify WP GPX Maps is installed and active**
4. **Try refreshing** the page - sometimes the map loads slowly
5. **Check GPX stats are enabled** in Settings → SOTA Magic

The console will show messages like:
- "SOTA Magic: Found map via _wpgpxmaps" = Working correctly
- "Could not find Leaflet map" = WP GPX Maps might not be loaded

If you see errors, please report them with the console output for support.

= Why are rest breaks included in hiking time? =

Rest breaks are part of your hiking experience! Whether you stop for water, photos, or to catch your breath, that time is still part of your overall hike to the summit. The plugin tracks rest breaks separately and displays them as a sub-note (e.g., "2h 15m (25m breaks)") so you can see the breakdown while keeping your total hiking time accurate.

= What's the difference between a rest break and activation time? =

- **Rest break:** Stationary time outside the activation zone (on the trail)
- **Activation time:** Stationary time within the activation zone (at the summit)

The key difference is location - activation time is specifically when you're stationary at or near the summit peak, where you're operating your radio.

= Why use activation zone instead of just speed? =

The activation zone method is much more accurate because:
- It identifies where you actually operated from (at the summit)
- It ignores brief rest stops during the hike up or down
- It prevents long lunch breaks on the trail from counting as "activation"
- It's based on the physical location where SOTA activations occur

= Can I adjust the activation zone settings? =

Yes! Go to Settings → SOTA Magic → GPX Track Analysis:
- **Activation Zone Radius:** 20-200 meters (default: 50m)
- **Rest Break Threshold:** 1-30 minutes (default: 10 min)
- **Stationary Speed Threshold:** 0.1-2.0 km/h (default: 0.3 km/h)

= What if my GPX track doesn't reach the actual summit? =

The plugin uses the highest point in your track, so it will still work. The activation zone will be centered on the highest point you reached, which is typically at or very near the summit.

= How accurate is the activation zone method? =

Very accurate for typical SOTA activations! The 50-meter radius captures the area where most activators set up. If you operate from a different location (e.g., slightly below the peak), you can increase the radius in settings.

= Can I switch between metric and imperial units? =

Yes! Go to Settings → SOTA Magic → GPX Track Analysis and choose your preferred "Unit System". Select Metric for km, m, and km/h, or Imperial for mi, ft, and mph. All statistics will automatically convert to your chosen system.

= How are contact locations determined on the map? =

Contact locations come from different sources:

**Summit-to-Summit (S2S) contacts:** Exact summit coordinates from the SOTA API (api2.sota.org.uk). These are very accurate.

**Regular contacts:** Station location from the operator's QRZ.com profile. Requires your QRZ username and password in settings.

**No location:** If a contact isn't S2S and doesn't have location data in QRZ, they won't appear on the map (but will still show in the contact table).

= Do I need a QRZ subscription for the contact map? =

You need a QRZ.com account with XML access. This is included with QRZ Logbook subscriptions and XML subscriptions. The free QRZ account does not include XML API access.

= Why don't all my contacts show on the map? =

Contacts will only appear if:
1. They're S2S contacts (location from SOTA API), OR
2. They have location data in their QRZ profile AND you have QRZ XML access

Some operators don't include precise coordinates in their QRZ profiles, so they won't appear on the map.

= Do I need the WP GPX Maps plugin? =

Yes, WP GPX Maps is required for displaying GPX track visualizations. The contact table, statistics, and map features work independently.

= How do I get my contacts to show on the map? =

Enable the contact map in Settings → SOTA Magic and enter your QRZ.com credentials. The plugin will automatically look up contact locations.

= Can I customize the colors and appearance? =

Yes! Go to Settings → SOTA Magic to customize background colors, text colors, S2S highlighting colors, and more. You can also use transparent backgrounds to match your theme.

= What is S2S highlighting? =

Summit-to-Summit (S2S) contacts are when you contact another station that's also activating a SOTA summit. The plugin automatically detects these from your CSV and highlights them with custom colors.

= The comments field is cut off in my table. What should I do? =

As of version 5.07, the plugin includes responsive table design with horizontal scrolling. Long comments will wrap within the column, and the entire table scrolls horizontally if needed on narrow screens.

= Can I disable the GPX statistics? =

Yes, go to Settings → SOTA Magic → GPX Track Analysis and uncheck "Show GPX Statistics" if you prefer not to display the analysis.

= Can I use this plugin on multiple posts/pages? =

Yes! You can add the SOTA DATA block to as many posts and pages as you like, each with different GPX and CSV files.

== Screenshots ==

1. SOTA Magic settings page with customization options
2. GPX track visualization with hiking/activation statistics
3. Statistics grid showing hiking time, activation time, and elevation data
4. Contact table with S2S highlighting
5. Interactive contact map showing QSO locations
6. Block editor interface for uploading files

== Changelog ==

= 0.517 Beta =
* NEW: Methodology indicator on Activation Time stat
* NEW: Shows "✓ API-based zone" when using activation.zone API
* NEW: Shows "Within XXm of summit" when using radius fallback
* NEW: Detailed explanation box below stats explaining which method was used
* NEW: Links to activation.zone and suggests enabling API if using fallback
* Improved: Users can now see how their activation time was calculated
* Enhanced: Tooltips on methodology indicators

= 0.516 Beta - STABLE BASELINE =
* FIXED: Removed all visual overlay code causing JavaScript errors
* FIXED: No more "Invalid character: '#'" errors in Safari
* Status: Fully stable and working version
* Core Features: All activation.zone API integration working perfectly
* Core Features: Accurate time calculations (hiking vs activation)
* Core Features: Rest break tracking included in hiking time
* Core Features: All statistics displaying correctly
* Note: Visual map overlay temporarily removed - will be re-added with different approach

= 0.512 Beta =
* NEW: Visual activation zone overlay on GPX maps
* NEW: Red polygon overlay when using activation.zone API
* NEW: Orange dashed circle overlay when using radius fallback
* NEW: Summit peak marker (🏔️) on map at highest point
* NEW: Clickable overlays with information popups
* Improved: Can now visually verify activation zone on map
* Enhanced: Better understanding of where you operated from
* Technical: Leaflet integration with WP GPX Maps

= 0.511 Beta =
* MAJOR: Integration with activation.zone API (by N6ARA)
* NEW: Queries api.activation.zone for precise activation zone geometry based on terrain
* NEW: Uses official SOTA 25m vertical drop rule via API
* NEW: Automatic fallback to radius method if API unavailable
* NEW: Point-in-polygon algorithm for accurate zone detection
* NEW: Setting to enable/disable API usage (enabled by default)
* Improved: Most accurate activation zone detection possible
* Enhanced: Settings page explains API vs. fallback method
* Added: Credits to N6ARA for activation.zone tool

= 0.510 Beta =
* FIXED: Rest breaks now properly included in hiking time (not excluded)
* NEW: Rest break time tracked separately and shown as sub-note under hiking time
* NEW: Display format: "2h 15m (25m breaks)" shows total hiking with break breakdown
* Improved: All time now accounted for - hiking time + activation time = total time
* Improved: More intuitive time classification that matches real hiking experience
* Enhanced: Settings page clarifies that rest breaks are included in hiking time
* Updated: Documentation reflects new rest break handling

= 0.509 Beta =
* MAJOR: Complete rewrite of GPX analysis algorithm
* NEW: Activation zone detection using summit peak location
* NEW: Configurable activation zone radius (20-200m, default 50m)
* NEW: Rest break filtering - ignores brief stops during hiking
* NEW: Configurable rest threshold (1-30 minutes, default 10 min)
* NEW: Comprehensive documentation explaining all methodology
* NEW: Settings page includes detailed explanations of how calculations work
* Improved: Much more accurate distinction between hiking and activation time
* Improved: Eliminates false positives from photo stops and water breaks
* Enhanced: Better handling of complex hiking patterns
* Changed: Version numbering to 0.509 Beta to reflect development status

= 5.09 =
* NEW: Metric/Imperial unit system preference
* NEW: All statistics now display in your chosen unit system
* NEW: Setting to switch between km/m/km/h and mi/ft/mph
* Improved: Better international support for different measurement preferences
* Enhanced: Automatic unit conversion throughout the plugin

= 5.08 =
* NEW: Intelligent GPX track analysis distinguishing hiking from activation time
* NEW: Comprehensive statistics display including:
  - Hiking time and distance
  - Activation (stationary) time
  - Total time and distance
  - Elevation gain and loss
  - Average hiking speed
  - Peak and base elevations
* NEW: Configurable stationary speed threshold (default: 0.3 km/h)
* NEW: Option to show/hide GPX statistics in settings
* NEW: Beautiful grid layout for statistics with icons
* Improved: Better responsive design for statistics on mobile
* Enhanced: More detailed activity breakdown for activators

= 5.07 =
* Fixed: Comments column now wraps text properly instead of being cut off
* Added: Responsive table wrapper with horizontal scrolling
* Improved: Better mobile display for contact tables
* Enhanced: Table styling for better readability on all screen sizes

= 5.06 =
* Previous stable version
* Full feature set including GPX maps, contact tables, and contact mapping

== Upgrade Notice ==

= 0.510 Beta =
Important update: Rest breaks are now properly included in hiking time! This version fixes the time accounting so all time is properly categorized. Your hiking time will now include rest breaks (with the break time shown as a helpful sub-note), making the total time calculation accurate and complete.

= 0.509 Beta =
MAJOR UPDATE: Complete rewrite of the activation time detection algorithm! Now uses activation zone method based on summit peak location for much more accurate results. This beta version introduces powerful new features including configurable activation zone radius and rest break filtering. Highly recommended for all SOTA activators who want the most accurate hiking vs. activation analysis.

= 5.07 =
This version fixes the issue where long comments were being cut off in the contact table. Recommended upgrade for all users.

== Additional Information ==

= Support =

For support, feature requests, or bug reports, please visit the plugin's support forum or contact KI6CR.

= Credits =

Created by KI6CR for the amateur radio and SOTA community.

= Contributing =

This plugin is open to contributions and improvements from the ham radio community.

== Privacy Policy ==

SOTA Magic does not collect or store any user data. If you enable the contact map feature and provide QRZ.com credentials, those credentials are stored in your WordPress database and used only to query the QRZ.com API for contact location data. No data is sent to third parties except QRZ.com when the contact map feature is enabled.

== Technical Details ==

= GPX Analysis Algorithm =

The plugin implements a sophisticated two-pass analysis of GPX tracks:

**Pass 1: Summit Detection**
- Parses all trackpoints from the GPX file
- Identifies the highest elevation point in the track
- Records coordinates of this peak as the summit center

**Pass 2: Segment Classification**
- Calculates distance between consecutive points using Haversine formula
- Determines speed for each segment (distance/time)
- Checks if each point falls within the activation zone radius
- Classifies segments based on location and movement:
  * In activation zone + stationary = Activation Time
  * Outside zone + moving = Hiking Time
  * Outside zone + stationary = Hiking Time (counted as rest break)
- Rest breaks are tracked separately but included in total hiking time
- Display shows: "Hiking Time: 2h 15m (25m breaks)" for complete transparency

**Haversine Distance Formula:**
The plugin uses the Haversine formula to calculate great-circle distances between GPS coordinates, accounting for the Earth's spherical shape. This provides accuracy within a few meters for the distances typical in SOTA activations.

**Activation Zone Geometry:**
Unlike the full SOTA activation zone (which uses 25m vertical drop from peak based on terrain), this plugin uses a simpler horizontal radius approach. This is a practical compromise that works well for most summits while avoiding the need for Digital Elevation Model (DEM) data.

= Performance =

GPX analysis is performed during page rendering. Performance characteristics:

- **Typical activation (500-1000 points):** <1 second processing time
- **Long hikes (2000+ points):** 1-3 seconds processing time
- **Memory usage:** Minimal - points are processed sequentially
- **No caching:** Results are calculated fresh each page load

For very long tracks, consider splitting the GPX file to only include the activation portion.

= API Usage =

**SOTA API (api2.sota.org.uk):**
- Used to retrieve summit coordinates for contact mapping
- No authentication required
- Rate limiting: None specified, plugin uses reasonable delays
- Endpoint: `/api/summits/{summit_reference}`

**QRZ.com XML API:**
- Used to retrieve operator location data for contact mapping
- Requires XML subscription (not included with free accounts)
- Rate limiting: 0.5 seconds between requests (enforced by plugin)
- Session-based authentication using username/password

= Browser Compatibility =

The plugin uses standard WordPress/PHP server-side rendering with minimal JavaScript:
- Compatible with all modern browsers
- JavaScript only used for: 
  * Leaflet maps (contact map feature)
  * Minor UI enhancements (decimal precision fixes)
- Works with JavaScript disabled (except interactive maps)

= Data Privacy =

- All GPX processing happens on your WordPress server
- No GPX data is sent to external services
- QRZ credentials stored in WordPress options table
- No tracking or analytics
- No data sent to third parties except:
  * SOTA API for summit coordinates
  * QRZ API for contact locations (only if feature enabled)
