<?php
/**
 * What beta testers see on beta.php. Edit this list when a feature enters or leaves beta.
 *
 * status:  'testing'  live for testers now, shown with its test cases
 *          'next'     coming soon, shown as a short preview (no test cases needed)
 *          'public'   graduated to everyone; drop it from the list (or keep briefly as a thank-you)
 * since:   date it opened to testers (YYYY-MM-DD), for 'testing'
 * where:   ['text' => 'button label', 'url' => 'page.php'] where testers start, optional
 * focus:   what we most want to hear about (short bullets)
 * cases:   specific test cases: title, steps (list), expect (what should happen)
 *
 * The real feature code must also check canSeeBeta() so only testers (and God Mode) get it.
 * User-facing text: no spaced em dashes, no emoji.
 */
return [
    [
        'key'     => 'gpx_editor',
        'name'    => 'GPX editor',
        'status'  => 'next',
        'summary' => 'Trim, split, join and reshape GPX tracks right inside SOTAplanner, then save the result as your own copy. '
                   . 'Nearby trailheads and parking lots will show on the map so a track can start where people actually park.',
        'focus'   => [],
        'cases'   => [],
    ],
    [
        'key'     => 'route_discovery',
        'name'    => 'Hiking route auto-discovery',
        'status'  => 'next',
        'summary' => 'Many summits have no recorded track yet. For those, SOTAplanner will look for likely hiking routes on its own. '
                   . 'Starting at the summit, it searches outward along the trails and roads in OpenStreetMap until it reaches a spot you can drive to, '
                   . 'and it also tries every parking lot and trailhead mapped in OpenStreetMap nearby. '
                   . 'Each summit gets up to three possible routes with the starting point, distance, climb and hike time. '
                   . 'Every route carries a probability score: our estimate of how likely it is to be the way people actually go, '
                   . 'based on how often the search kept arriving at that start, whether it is a mapped parking lot or trailhead, '
                   . 'and how it compares with real tracks hikers have recorded on other summits. '
                   . 'When a dirt road might be gated or closed, you will also see where to park on the paved road instead. '
                   . 'You will be able to mark a route as right or wrong, and those votes make the scores better over time. '
                   . 'Map data from OpenStreetMap contributors.',
        'focus'   => [],
        'cases'   => [],
    ],
];
