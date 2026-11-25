# Summit confidence check and calibration for trail discovery (plain Python, no numpy).
# Input: routes.tsv from trail_discovery_extract.php. Uses the route model in trail_discovery_fit.py
# (variant given as argv[2], default v3), re-picks the shown 3 with cross-validated route scores (as the
# site would with the new weights), then compares ways to turn route scores into a summit score.
# Summit label: one of the shown starts (or its gate-closed spot) within 400 m of the community start.
import csv, math, random, sys, collections, importlib.util, io, contextlib, os

spec = importlib.util.spec_from_file_location("tdfit", os.path.join(os.path.dirname(__file__), "trail_discovery_fit.py"))
fitmod = importlib.util.module_from_spec(spec)
sys_argv = sys.argv; sys.argv = [sys.argv[0], sys.argv[1], "none"]
with contextlib.redirect_stdout(io.StringIO()):
    try: spec.loader.exec_module(fitmod)
    except Exception: pass
sys.argv = sys_argv
feats, fit, pred, auc = fitmod.feats, fitmod.fit, fitmod.pred, fitmod.auc
rows = fitmod.rows
variant = sys.argv[2] if len(sys.argv) > 2 else "v3"
summits = sorted({(r["run"], r["ref"]) for r in rows})
X = [feats(r, variant)[0] for r in rows]; y = [r["label"] for r in rows]

random.seed(7); order = summits[:]; random.shuffle(order); fold = {s: i % 5 for i, s in enumerate(order)}
cv = [0.0] * len(rows)
for k in range(5):
    tr = [i for i, r in enumerate(rows) if fold[(r["run"], r["ref"])] != k]
    w = fit([X[i] for i in tr], [y[i] for i in tr])
    for i, r in enumerate(rows):
        if fold[(r["run"], r["ref"])] == k: cv[i] = pred(w, X[i])
print(f"route model {variant}: cross-validated AUC {auc(cv, y):.3f}")

by = collections.defaultdict(list)
for r, p in zip(rows, cv): by[(r["run"], r["ref"])].append((r, p))

def pick(lst, paved_rule):
    s = sorted(lst, key=lambda t: -t[1]); top = s[:3]
    if paved_rule and not any(r["cls"] == "paved" for r, p in top):
        pv = [t for t in s if t[0]["cls"] == "paved"]
        if pv and top: top[-1] = pv[0]
    return top

def logit(p): p = min(max(p, 1e-4), 1 - 1e-4); return math.log(p / (1 - p))

for paved_rule in (True, False):
    S = []
    for key in summits:
        shown = pick(by[key], paved_rule)
        if not shown: continue
        ps = sorted([p for r, p in shown], reverse=True) + [0, 0, 0]
        q = 1.0
        for p in ps[:3]: q *= 1 - p
        lab = int(any(r["label"] for r, p in shown))
        S.append({"key": key, "lab": lab, "prod": 1 - q, "max": ps[0], "sum": sum(ps[:3]), "p2": ps[1], "n": len(by[key]),
                  "region": key[1].split("-")[0]})
    print(f"\n== pick: confidence top 3, {'one paved start kept' if paved_rule else 'no paved rule'} ==")
    print(f"summits {len(S)}, shown start right on {sum(s['lab'] for s in S)} ({round(100*sum(s['lab'] for s in S)/len(S))}%)")
    L = [s["lab"] for s in S]
    for agg in ("prod", "max", "sum"):
        print(f"  summit AUC using {agg:4s}: {auc([s[agg] for s in S], L):.3f}")
    # Calibrated summit model: logistic on logit(max) and logit(second), cross-validated by summit fold
    def sx(s): return [1.0, logit(s["max"]), logit(max(s["p2"], 1e-3)), math.log(s["n"])]
    cal = [0.0] * len(S)
    for k in range(5):
        tr = [i for i, s in enumerate(S) if fold[s["key"]] != k]
        w = fit([sx(S[i]) for i in tr], [L[i] for i in tr], l2=0.5)
        for i, s in enumerate(S):
            if fold[s["key"]] == k: cal[i] = pred(w, sx(s))
    print(f"  summit AUC calibrated (max, second, n candidates): {auc(cal, L):.3f}")
    wall = fit([sx(s) for s in S], L, l2=0.5)
    print("  calibration weights: " + ", ".join(f"{n} {v:+.3f}" for n, v in zip(["bias", "logit_max", "logit_p2", "log_n"], wall)))
    for name, sc in (("1 - product (today)", [s["prod"] for s in S]), ("calibrated", cal)):
        for hi, lo in ((0.70, 0.40), (0.75, 0.45), (0.80, 0.50)):
            b = collections.defaultdict(lambda: [0, 0])
            for s, v in zip(S, sc):
                band = "high" if v >= hi else "medium" if v >= lo else "low"; b[band][0] += 1; b[band][1] += s["lab"]
            print(f"  {name:20s} cut {int(hi*100)}/{int(lo*100)}: " + ", ".join(
                f"{x} {b[x][0]} ({round(100*b[x][1]/b[x][0]) if b[x][0] else '-'}% right)" for x in ("high", "medium", "low")))
    # per region with calibrated
    for reg in sorted({s["region"] for s in S}):
        b = collections.defaultdict(lambda: [0, 0])
        for s, v in zip(S, cal):
            if s["region"] != reg: continue
            band = "high" if v >= 0.7 else "medium" if v >= 0.4 else "low"; b[band][0] += 1; b[band][1] += s["lab"]
        print(f"    {reg} calibrated 70/40: " + ", ".join(f"{x} {b[x][0]} ({round(100*b[x][1]/b[x][0]) if b[x][0] else '-'}%)" for x in ("high", "medium", "low")))

# ---- Summit features beyond the route scores: does the summit look like one where our candidates include the real start?
print("\n== summit model with summit-wide signals (confidence pick, no paved rule) ==")
S = []
for key in summits:
    lst = by[key]; shown = pick(lst, False)
    if not shown: continue
    ps = sorted([p for r, p in shown], reverse=True) + [0, 0]
    hits = [r["hits"] for r, p in lst]; tot = sum(hits) or 1
    top = max(shown, key=lambda t: t[1])[0]
    S.append({"key": key, "lab": int(any(r["label"] for r, p in shown)), "region": key[1].split("-")[0],
              "x": {"logit_max": logit(ps[0]), "logit_p2": logit(max(ps[1], 1e-3)),
                    "consensus": max(hits) / tot,
                    "ev_any": any(r["ev_tracks"] not in ("", "0") for r, p in lst),
                    "ev_start_shown": any(r["ev_start_m"] != "" and float(r["ev_start_m"]) < 100 for r, p in shown),
                    "T_shown": any(r["pkT_m"] <= 150 for r, p in shown),
                    "named_lot_shown": any(r["lot_named"] == "1" for r, p in shown),
                    "log_n": math.log(len(lst)),
                    "fastest_h": min(r["time_min"] for r, p in lst) / 60,
                    "top_far": float(top["first_ring_km"] or 8) >= 4,
                    "top_paved": top["cls"] == "paved"}})
L = [s["lab"] for s in S]; names = list(S[0]["x"].keys())
def sx2(s, use): return [1.0] + [float(s["x"][n]) for n in use]
for k_ in names:   # one signal at a time
    a = auc([float(s["x"][k_]) for s in S], L); print(f"  {k_:16s} alone: summit AUC {max(a, 1-a):.3f} ({'higher is better' if a >= 0.5 else 'lower is better'})")
for use in (["logit_max", "logit_p2"], names):
    cal = [0.0] * len(S)
    for k in range(5):
        tr = [i for i, s in enumerate(S) if fold[s["key"]] != k]
        w = fit([sx2(S[i], use) for i in tr], [L[i] for i in tr], l2=1.0)
        for i, s in enumerate(S):
            if fold[s["key"]] == k: cal[i] = pred(w, sx2(s, use))
    print(f"  model {use if len(use) < 4 else 'all signals'}: cross-validated summit AUC {auc(cal, L):.3f}")
    for hi, lo in ((0.70, 0.40), (0.75, 0.45)):
        b = collections.defaultdict(lambda: [0, 0])
        for s, v in zip(S, cal):
            band = "high" if v >= hi else "medium" if v >= lo else "low"; b[band][0] += 1; b[band][1] += s["lab"]
        print(f"    cut {int(hi*100)}/{int(lo*100)}: " + ", ".join(f"{x} {b[x][0]} ({round(100*b[x][1]/b[x][0]) if b[x][0] else '-'}% right)" for x in ("high", "medium", "low")))
    w = fit([sx2(s, use) for s in S], L, l2=1.0)
    print("    weights: " + ", ".join(f"{n} {v:+.2f}" for n, v in zip(["bias"] + use, w)))
