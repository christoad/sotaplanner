// ============================================
// FIELD HELP CONTENT
// "What's this?" modals for every field
// ============================================

$field_help = [
    'summit_name' => [
        'title' => 'Summit Name',
        'content' => 'The common name of the mountain or peak. This is how you\'ll identify it in your planning dashboard.',
        'example' => 'Example: "Flint Peak", "Mount Wilson", "Strawberry Peak"'
    ],
    
    'sota_reference' => [
        'title' => 'SOTA Reference',
        'content' => 'The official Summits On The Air reference code (e.g., W6/CT-225). This is used to:<br>
        • Pull data from SOTA and SOTLAS APIs<br>
        • Determine activation zone boundaries<br>
        • Track your activation history<br>
        • Award points when you activate',
        'example' => 'Example: W6/CT-225, W6/SC-001, VE3/ON-001',
        'link' => 'https://sotl.as',
        'link_text' => 'Look up your summit on SOTLAS'
    ],
    
    'points' => [
        'title' => 'SOTA Points',
        'content' => 'Points awarded for activating this summit. Based on elevation:<br>
        • 1 point: Standard summits<br>
        • 2 points: Higher elevation<br>
        • 4 points: Very high elevation<br>
        • 6 points: Exceptional peaks<br>
        • 8 points: Extreme elevation<br>
        • 10 points: Highest peaks<br><br>
        The planner uses this to help prioritize targets.',
        'example' => 'Most California summits are 1-4 points'
    ],
    
    'elevation' => [
        'title' => 'Summit Elevation',
        'content' => 'The elevation at the summit. Used to:<br>
        • Calculate activation zone (25m vertical drop rule)<br>
        • Help you prepare for altitude effects<br>
        • Estimate difficulty<br><br>
        This is automatically filled from SOTA data when you enter the SOTA reference.',
        'example' => 'Example: 10,250 ft or 3,124 m'
    ],
    
    'hike_distance' => [
        'title' => 'Round-Trip Distance',
        'content' => 'Total hiking distance from the starting point to summit and back. Used to:<br>
        • Calculate estimated hiking time<br>
        • Help you plan your day<br>
        • Compare summit difficulty<br><br>
        <strong>Data Sources (priority order):</strong><br>
        1. Your uploaded GPX track (most accurate)<br>
        2. SOTLAS imported data<br>
        3. Manual entry',
        'example' => 'Example: 6.4 mi (10.3 km) round trip'
    ],
    
    'elevation_gain' => [
        'title' => 'Elevation Gain',
        'content' => 'Total elevation gained on the ascent. This is a key difficulty factor. Used to:<br>
        • Calculate estimated hiking time (slower with more gain)<br>
        • Determine difficulty rating<br>
        • Help you prepare physically<br><br>
        <strong>Formula:</strong> We use 1 hour per 1,500 ft of gain (more conservative than standard Naismith\'s Rule to account for radio gear).<br><br>
        <strong>Data Sources:</strong> GPX upload > SOTLAS > Manual',
        'example' => 'Example: 2,400 ft (732 m) of climbing'
    ],
    
    'difficulty' => [
        'title' => 'Difficulty Rating',
        'content' => 'Subjective difficulty rating (Easy, Moderate, Hard, Very Hard). Considers:<br>
        • Distance and elevation gain<br>
        • Trail condition (maintained vs bushwhack)<br>
        • Exposure and terrain<br>
        • Navigation complexity<br><br>
        This helps you choose appropriate summits for your skill level and available time.',
        'example' => 'Easy: Short, maintained trail<br>Hard: Long distance, rough terrain, or significant bushwhacking'
    ],
    
    'trailhead_coords' => [
        'title' => 'Starting Point Coordinates',
        'content' => 'GPS coordinates where you start hiking — a trailhead, parking area, or transit stop. Used to:<br>
        • Calculate travel time from your address<br>
        • Generate Google Maps directions<br>
        • Show exact parking location<br><br>
        <strong>Input Options:</strong><br>
        • Paste exact lat/long for precision<br>
        • Enter an address to geocode<br>
        • Import from SOTLAS data<br><br>
        💡 Tip: Copy coordinates directly from Google Maps, AllTrails, or CalTopo for exact precision.',
        'example' => 'Exact: 34.23938, -118.09335<br>Address: "1001 Marengo Dr, Glendale, CA"'
    ],
    
    'drive_time' => [
        'title' => 'Travel Time (Round Trip)',
        'content' => 'Total driving time from your address to the starting point and back, calculated using Google Maps with current traffic patterns.<br><br>
        <strong>How It Works:</strong><br>
        1. Select your address in the dashboard<br>
        2. Click "Calculate Travel Times"<br>
        3. System queries Google Maps for each summit<br><br>
        This helps you plan total door-to-door time for activation trips.',
        'example' => 'Example: 1h 20m (40 min each way)'
    ],
    
    'hike_time' => [
        'title' => 'Estimated Hiking Time (Round Trip)',
        'content' => 'Calculated hiking time based on distance and elevation gain using a SOTA-specific formula:<br><br>
        <strong>Formula:</strong><br>
        • 2.5 mph base pace (24 min/mile)<br>
        • +1 hour per 1,500 ft elevation gain<br>
        • +10% buffer for breaks<br><br>
        <strong>Why slower than standard?</strong><br>
        • Radio gear adds 10-20 lbs<br>
        • SOTA trails often rough/unmarked<br>
        • Need energy for summit operation<br><br>
        <strong>GPX Override:</strong> If you upload a GPX track, we use your actual hiking time instead (excluding activation zone time).',
        'example' => 'Example: 3h 30m calculated, or 4h 15m from your GPX'
    ],
    
    'total_time' => [
        'title' => 'Total Trip Time',
        'content' => 'Complete door-to-door time estimate:<br><br>
        <strong>Total = Travel + Hike + Activation</strong><br><br>
        Components:<br>
        • <strong>Travel Time:</strong> Google Maps calculation<br>
        • <strong>Hike Time:</strong> Calculated or GPX-derived<br>
        • <strong>Activation Time:</strong> 45 min default, or GPX-measured<br><br>
        This is your complete time commitment for the activation, helping you choose summits that fit your available time.',
        'example' => 'Example: 6h 15m total (1h 20m travel + 3h 30m hike + 45m activation + buffer)'
    ],
    
    'activation_time' => [
        'title' => 'Activation Time',
        'content' => 'Time spent in the activation zone operating radio. Default: 45 minutes.<br><br>
        <strong>With GPX Upload:</strong><br>
        We automatically detect activation zone time using:<br>
        1. <strong>Activation.Zone API</strong> (N6ARA) - Terrain-based 25m drop boundary<br>
        2. <strong>Fallback:</strong> 50m radius from highest GPS point<br><br>
        All time spent in the activation zone counts (setting up, operating, taking down, photos) regardless of whether you\'re moving or stationary.',
        'example' => 'Manual: 45 min estimate<br>GPX: 1h 12m actual (from your track)'
    ],
    
    'gpx_track' => [
        'title' => 'GPS Track Upload',
        'content' => 'Upload your actual GPS track (.gpx file) for precise analysis:<br><br>
        <strong>What We Calculate:</strong><br>
        • Actual hiking time (excluding activation zone)<br>
        • Actual activation time (time in zone)<br>
        • True distance and elevation gain<br>
        • Your hiking speed<br>
        • Rest breaks during hike<br><br>
        <strong>Activation Zone Detection:</strong><br>
        Uses activation.zone API for terrain-based boundaries (25m vertical drop rule), or 50m radius fallback.<br><br>
        <strong>Data Priority:</strong><br>
        Your GPX data overrides all estimates and SOTLAS imports.',
        'example' => 'Export from: Garmin, Gaia GPS, CalTopo, AllTrails'
    ],
    
    'last_activated' => [
        'title' => 'Last Activated',
        'content' => 'Most recent activation date for this summit on your dashboard.<br><br>
        <strong>Why Track This:</strong><br>
        • SOTA rules: Summits activated in current calendar year aren\'t eligible for points again until next year<br>
        • Dashboard shows gray rows for recently activated summits<br>
        • Helps prioritize which summits to target<br><br>
        Each dashboard tracks activations separately - other dashboards\' activations don\'t affect your eligibility.',
        'example' => 'Feb 17, 2026 | KI6CR, W6ABC'
    ],
    
    'activation_history' => [
        'title' => 'Activation History',
        'content' => 'Complete log of all activations for this summit on your dashboard.<br><br>
        <strong>What to Track:</strong><br>
        • Date of activation<br>
        • Callsigns of all activators<br>
        • Optional notes (weather, conditions, challenges)<br><br>
        <strong>Benefits:</strong><br>
        • Remember past trips<br>
        • Track who you\'ve activated with<br>
        • Learn from previous attempts<br>
        • Build your activation resume',
        'example' => 'Feb 17, 2026 | KI6CR, W6ABC | "Perfect weather, 15 QSOs"'
    ],
    
    'status' => [
        'title' => 'Summit Status',
        'content' => 'Current readiness state:<br><br>
        <strong>Nominated:</strong> Added to list, needs research<br>
        <strong>Ready:</strong> All data complete, ready to activate<br>
        <strong>Activated:</strong> Successfully activated<br><br>
        Dashboard color coding:<br>
        • 🟢 Green: Ready to go<br>
        • 🩶 Gray: Activated this year (not eligible)<br>
        • ⚪ White: Needs more research',
        'example' => 'Status changes as you add trail data and activate summits'
    ],
    
    'data_source' => [
        'title' => 'Data Source',
        'content' => 'Shows where data came from, in priority order:<br><br>
        <strong>1. GPX Upload:</strong> Your actual GPS track (most accurate)<br>
        <strong>2. SOTLAS:</strong> Imported from SOTLAS community data<br>
        <strong>3. Manual:</strong> Entered by hand<br><br>
        Each field shows its source so you know data reliability. GPX data always overrides other sources.',
        'example' => 'Distance: GPX upload ✓<br>Elevation: SOTLAS<br>Difficulty: Manual'
    ]
];
