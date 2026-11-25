# Fits the trail discovery route confidence (logistic regression, plain Python) and checks it.
# Input: routes.tsv from td_extract.php. Label: route start (or its gate-closed spot) within 400 m of the community start.
import csv, math, random, sys, collections

rows = list(csv.DictReader(open(sys.argv[1]), delimiter="\t"))
for r in rows:
    for k in ("label", "chosen", "rank", "driveup", "hits", "time_min", "gap_m", "pk_m", "pkT_m", "n_ranked"):
        r[k] = int(float(r[k]))
    r["rel_time"] = float(r["rel_time"])
    r["region"] = r["ref"].split("-")[0]

def feats(r, variant):
    rk = r["rank"]
    f = {
        "rank0": rk == 0, "rank1": rk == 1, "rank2": rk == 2, "driveup": r["driveup"] == 1,
        "loghits": math.log(1 + min(r["hits"], 200)) / 5,
        "ev_start": r["ev_start_m"] != "" and float(r["ev_start_m"]) < 100,
        "ev_trail": r["ev_trail_pct"] != "" and float(r["ev_trail_pct"]) >= 75,
        "offtrail": r["gap_m"] > 120,
        "paved": r["cls"] == "paved",
        "short": r["time_min"] < 45,
    }
    if variant in ("old", "abs+pk"):
        f["over4h"] = r["time_min"] > 240
    if variant in ("rel", "rel+pk", "abs+pk"):
        f["rel_1_5"] = r["rel_time"] > 1.5
        f["rel_2_5"] = r["rel_time"] > 2.5
    if variant == "final":
        f["rel_1_5"] = r["rel_time"] > 1.5
        f["rel_2_5"] = r["rel_time"] > 2.5
        f["long_slow"] = r["time_min"] > 240 and r["rel_time"] > 1.2
    if variant in ("v3", "v3b"):
        f["rel_1_5"] = r["rel_time"] > 1.5
        f["rel_2_5"] = r["rel_time"] > 2.5
        f["long_slow"] = r["time_min"] > 240 and r["rel_time"] > 1.2
        fr = float(r["first_ring_km"] or 8)
        f["ring_near"] = fr <= 0.75
        f["ring_1_5"] = fr == 1.5
        f["ring_far"] = fr >= 4
        f["lot_T"] = r["lot_k"] == "T"
        f["lot_alone"] = r["lot_k"] != "" and r["hits"] <= 1
        f["sac3"] = r["sac"] not in ("", "0", "1", "2")
        if variant == "v3":
            f["at_parking"] = r["pk_m"] <= 150
            f["parking_400"] = 150 < r["pk_m"] <= 400
    if variant in ("rel+pk", "abs+pk", "final"):
        f["at_parking"] = r["pk_m"] <= 150
        f["at_trailhead"] = r["pkT_m"] <= 150
        f["parking_400"] = 150 < r["pk_m"] <= 400
    return [1.0] + [float(v) for v in f.values()], ["bias"] + list(f.keys())

def solve(A, b):
    n = len(b); M = [A[i][:] + [b[i]] for i in range(n)]
    for c in range(n):
        p = max(range(c, n), key=lambda i: abs(M[i][c])); M[c], M[p] = M[p], M[c]
        for i in range(n):
            if i != c and M[c][c] != 0:
                f = M[i][c] / M[c][c]
                for j in range(c, n + 1): M[i][j] -= f * M[c][j]
    return [M[i][n] / M[i][i] for i in range(n)]

def fit(X, y, l2=1.0, iters=30):
    d = len(X[0]); w = [0.0] * d
    for _ in range(iters):
        g = [0.0] * d; H = [[0.0] * d for _ in range(d)]
        for x, t in zip(X, y):
            z = sum(a * b for a, b in zip(w, x)); p = 1 / (1 + math.exp(-max(-30, min(30, z))))
            for i in range(d):
                g[i] += (p - t) * x[i]
                for j in range(d): H[i][j] += p * (1 - p) * x[i] * x[j]
        for i in range(1, d): g[i] += l2 * w[i]; H[i][i] += l2
        H[0][0] += 1e-6
        step = solve(H, g); w = [a - b for a, b in zip(w, step)]
    return w

def pred(w, x): return 1 / (1 + math.exp(-sum(a * b for a, b in zip(w, x))))

def auc(scores, labels):
    pos = [s for s, l in zip(scores, labels) if l]; neg = [s for s, l in zip(scores, labels) if not l]
    if not pos or not neg: return float("nan")
    return sum((p > n) + 0.5 * (p == n) for p in pos for n in neg) / (len(pos) * len(neg))

def summit_table(rs, probs, title):
    by = collections.defaultdict(list)
    for r, p in zip(rs, probs): by[(r["run"], r["ref"])].append((r, p))
    bands = collections.defaultdict(lambda: [0, 0])
    for key, lst in by.items():
        shown = [(r, p) for r, p in lst if r["chosen"]]
        if not shown: continue
        q = 1.0
        for r, p in shown: q *= 1 - p
        conf = 100 * (1 - q); ok = any(r["label"] for r, p in shown)
        b = "high" if conf >= 70 else "medium" if conf >= 40 else "low"
        bands[b][0] += 1; bands[b][1] += ok
    print("  " + title + ": " + ", ".join(f"{b} {bands[b][0]} summits, {round(100*bands[b][1]/bands[b][0]) if bands[b][0] else '-'}% show where people park" for b in ("high", "medium", "low")))

summits = sorted({(r["run"], r["ref"]) for r in rows})
print(f"{len(rows)} routes on {len(summits)} summits, {sum(r['label'] for r in rows)} routes start where people start")
y = [r["label"] for r in rows]
print(f"old score (as stored):  AUC {auc([float(r['old_conf'] or 0) for r in rows], y):.3f}")
summit_table(rows, [float(r["old_conf"] or 0) / 100 for r in rows], "old score bands")

for variant in [v for v in (sys.argv[2:] or ("old", "rel", "abs+pk", "rel+pk", "final")) if v != "none"]:
    X = [feats(r, variant)[0] for r in rows]
    # 5-fold cross validation, grouped by summit (a summit's routes are never split)
    random.seed(7); order = summits[:]; random.shuffle(order); fold = {s: i % 5 for i, s in enumerate(order)}
    cv = [0.0] * len(rows)
    for k in range(5):
        tr = [i for i, r in enumerate(rows) if fold[(r["run"], r["ref"])] != k]
        w = fit([X[i] for i in tr], [y[i] for i in tr])
        for i, r in enumerate(rows):
            if fold[(r["run"], r["ref"])] == k: cv[i] = pred(w, X[i])
    # region transfer: fit on one region, test on the other
    tx = []
    regions = sorted({r["region"] for r in rows})
    for test in regions:   # leave one region out: fit on the others, score this one
        tr = [i for i, r in enumerate(rows) if r["region"] != test]; te = [i for i, r in enumerate(rows) if r["region"] == test]
        if not tr: tx.append(float("nan")); continue
        w = fit([X[i] for i in tr], [y[i] for i in tr]); tx.append(auc([pred(w, X[i]) for i in te], [y[i] for i in te]))
    print(f"\n{variant}: cross-validated AUC {auc(cv, y):.3f} | fitted on the other regions: " + ", ".join(f"{g} {a:.3f}" for g, a in zip(regions, tx)))
    summit_table(rows, cv, "cross-validated bands")
    longs = {k for k in summits if min([int(r["time_min"]) for r in rows if (r["run"], r["ref"]) == k and r["driveup"] == 0] or [0]) > 240}
    summit_table([r for r in rows if (r["run"], r["ref"]) in longs], [p for r, p in zip(rows, cv) if (r["run"], r["ref"]) in longs], "big-mountain summits only (quickest over 4 h)")
    w = fit(X, y); names = feats(rows[0], variant)[1]
    print("  weights (all data): " + ", ".join(f"{n} {v:+.2f}" for n, v in zip(names, w)))
