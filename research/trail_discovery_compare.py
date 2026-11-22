# Compare trail discovery test runs. Plain Python, no extra packages.
# Input: saved answers of trail_discovery.php?ajax=results&run=N (one JSON file per run, e.g. r11.json).
# Usage: python3 trail_discovery_compare.py r5.json r6.json r11.json r12.json
import sys, json
print("run  summits  shown@400m  any@400m  in_top3  match/partial/miss  median_s  conf_high/med/low(right%)")
for f in sys.argv[1:]:
    # Every test summit that was searched, including ones where no start was found (status "none").
    rows = [r for r in json.load(open(f))["rows"] if r["status"] in ("ok", "none")]
    n = len(rows)
    pct = lambda k: round(100 * k / n) if n else 0
    shown = sum(1 for r in rows if r["known_shown_start_m"] is not None and r["known_shown_start_m"] <= 400)
    anyc = sum(1 for r in rows if r["known_start_m"] is not None and r["known_start_m"] <= 400)
    # "Community route in our top 3": the shown routes include theirs and the verdict is not a miss.
    top3 = sum(1 for r in rows if int(r["known_in_chosen"] or 0) == 1 and r["verdict"] != "miss")
    v = {k: sum(1 for r in rows if r["verdict"] == k) for k in ("match", "partial", "miss")}
    secs = sorted(float(r["seconds"] or 0) for r in rows)
    bands = []
    for lo, hi in ((70, 101), (40, 70), (0, 40)):
        b = [r for r in rows if r["confidence"] is not None and lo <= r["confidence"] < hi]
        ok = sum(1 for r in b if r["known_shown_start_m"] is not None and r["known_shown_start_m"] <= 400)
        bands.append(f"{len(b)}({round(100 * ok / len(b)) if b else 0}%)")
    run = rows[0]["run_id"] if rows else "?"
    print(f"{run:<4} {n:<8} {pct(shown):>3}%        {pct(anyc):>3}%      {pct(top3):>3}%     {v['match']}/{v['partial']}/{v['miss']:<12} {secs[n // 2] if n else 0:<9} {' '.join(bands)}")
