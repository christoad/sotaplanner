<?php
// Multi-Activate breaks: a rest stop of a set length (lunch, a coffee stop, a nap
// between summits) placed anywhere in the route, shared by multi_activate.php and
// multi_activate_pdf.php.
//
// In the route list carried by the URL ("ids") a break is a token "b<minutes>"
// among the summit ids, e.g. ids=12,b30,15 is summit 12, a 30 minute break, then
// summit 15. Links built on the page add bk=1, meaning "the ids list is the whole
// truth, breaks included". Without bk=1 (links from the dashboard, older links)
// the breaks saved with the route are used instead.
//
// Saved on multi_activations.breaks as "pos:min,pos:min", pos = how many summits
// come before the break (0 = before the first summit, n = after the last one).
// A break never changes how summits pair up (hike direct, alternate starts): it
// only adds time to the timeline, after one summit's hike down and before the
// travel to the next.

const MULTI_BREAK_DEFAULT_MIN = 30;
const MULTI_BREAK_MAX_COUNT   = 8;

function multi_break_clamp_min($m) {
    return max(5, min(720, (int)$m));
}

// Breaks from a raw ids string. $valid_ids (summit id => anything) drops tokens for
// summits that are not on this dashboard, so positions match the summits really shown.
function multi_breaks_from_ids_param($ids_param, array $valid_ids) {
    $breaks = [];
    $pos = 0;
    foreach (explode(',', (string)$ids_param) as $tok) {
        $tok = trim($tok);
        if ($tok === '') continue;
        if (preg_match('/^b(\d+)$/i', $tok, $m)) {
            if (count($breaks) < MULTI_BREAK_MAX_COUNT) $breaks[] = ['pos' => $pos, 'min' => multi_break_clamp_min($m[1])];
        } elseif (isset($valid_ids[(int)$tok])) {
            $pos++;
        }
    }
    return $breaks;
}

// Breaks from the saved column, kept within the current number of summits
function multi_breaks_decode($stored, $summit_count) {
    $breaks = [];
    foreach (explode(',', (string)$stored) as $part) {
        if (!preg_match('/^(\d+):(\d+)$/', trim($part), $m)) continue;
        if (count($breaks) >= MULTI_BREAK_MAX_COUNT) break;
        $breaks[] = ['pos' => min((int)$m[1], (int)$summit_count), 'min' => multi_break_clamp_min($m[2])];
    }
    usort($breaks, fn($a, $b) => $a['pos'] <=> $b['pos']);   // stable in PHP 8, keeps the saved order within a slot
    return $breaks;
}

function multi_breaks_encode(array $breaks) {
    return implode(',', array_map(fn($b) => (int)$b['pos'] . ':' . (int)$b['min'], $breaks));
}

// Summit ids and breaks merged into one ordered token list (summit ids as ints, breaks as "b30")
function multi_route_tokens(array $summit_ids, array $breaks) {
    $tokens = [];
    $n = count($summit_ids);
    for ($pos = 0; $pos <= $n; $pos++) {
        foreach ($breaks as $b) if ((int)$b['pos'] === $pos) $tokens[] = 'b' . (int)$b['min'];
        if ($pos < $n) $tokens[] = (int)$summit_ids[$pos];
    }
    return $tokens;
}

// Breaks that sit in front of summit position $pos (or after the last summit when $pos = count)
function multi_breaks_at(array $breaks, $pos) {
    return array_values(array_filter($breaks, fn($b) => (int)$b['pos'] === (int)$pos));
}
