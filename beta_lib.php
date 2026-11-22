<?php
/**
 * Beta testers: one list of callsigns (managed in God Mode, Beta Testers tab) who get
 * early access to features before everyone else. Table: beta_testers (db_migrate_beta.php).
 *
 * isBetaTester()   true if the callsign is on the list (defaults to the signed-in user,
 *                  so while God Mode impersonates a tester you see what they see)
 * canSeeBeta()     what beta-only features should check: God Mode or a listed tester
 * userChipName()   the callsign markup for the top-right user chip; adds the BETA tag
 *                  and switches the chip to the beta color for listed testers
 */

/** Seconds a cached yes/no answer is reused from the session before asking the DB again. */
const BETA_CACHE_SECONDS = 300;

function isBetaTester($callsign = null) {
    $callsign = strtoupper(trim($callsign ?? (getCurrentCallsign() ?? '')));
    if ($callsign === '') return false;

    static $memo = [];
    if (array_key_exists($callsign, $memo)) return $memo[$callsign];

    // Session cache (5 min), so most page loads skip the extra DB connection.
    // Pages that already closed the session just re-check next time; harmless.
    $cached = $_SESSION['_beta_cache'][$callsign] ?? null;
    if (is_array($cached) && (time() - $cached['t']) < BETA_CACHE_SECONDS) {
        return $memo[$callsign] = (bool)$cached['v'];
    }

    try {
        $st = getDbConnection()->prepare("SELECT 1 FROM beta_testers WHERE callsign = ?");
        $st->execute([$callsign]);
        $is = (bool)$st->fetchColumn();
    } catch (Exception $e) {
        $is = false; // table not created yet: nobody is a tester
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_beta_cache'][$callsign] = ['v' => $is ? 1 : 0, 't' => time()];
    }
    return $memo[$callsign] = $is;
}

/** Forget the cached answer for a callsign (God Mode calls this after adding or removing). */
function betaForget($callsign) {
    $callsign = strtoupper(trim($callsign));
    unset($_SESSION['_beta_cache'][$callsign]);
}

function canSeeBeta($callsign = null) {
    return isGodMode() || !empty($_SESSION['_god_mode_real_callsign']) || isBetaTester($callsign);
}

function userChipName($callsign) {
    $cs = htmlspecialchars($callsign ?? '');
    if (!isBetaTester($callsign)) return '<span>' . $cs . '</span>';

    static $css_done = false;
    $css = '';
    if (!$css_done) {
        $css_done = true;
        $css = '<style>
.user-chip:has(.chip-beta-tag) {
  background: var(--blue-bg, oklch(95% 0.04 240));
  border-color: oklch(80% 0.08 240);
  color: var(--blue, oklch(52% 0.12 240));
  padding-top: 0.2rem; padding-bottom: 0.2rem;
}
.user-chip:has(.chip-beta-tag):hover { background: oklch(91% 0.06 240); }
.chip-name-stack { display: inline-flex; flex-direction: column; align-items: center; line-height: 1.1; }
.chip-beta-tag {
  font-size: 0.55rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase;
  background: var(--blue, oklch(52% 0.12 240)); color: #fff;
  border-radius: 3px; padding: 0.05rem 0.3rem; margin-top: 2px;
}
</style>';
    }
    return $css . '<span class="chip-name-stack" title="Beta tester: you get new features early">'
         . '<span class="chip-callsign">' . $cs . '</span>'
         . '<span class="chip-beta-tag">Beta</span></span>';
}

/** "Beta Features" link for the user chip menu; empty for everyone who can't see beta features. */
function betaMenuLink() {
    return canSeeBeta() ? '<a href="beta.php">Beta Features</a>' : '';
}
