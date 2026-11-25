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
        'key'     => 'discovered_routes',
        'name'    => 'Suggested routes for summits with no track',
        'status'  => 'next',
        'summary' => 'For summits nobody has uploaded a track for yet, the summit page will suggest up to three possible routes from a road or parking spot to the top. '
                   . 'Mark each one as looking viable or not, and pick one to fill in the hike distance, climb and time for your dashboard.',
        'focus'   => [],
        'cases'   => [],
    ],
];
