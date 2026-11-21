# Merges out/*.tsv into one list: drops duplicates (same OSM object in overlapping regions)
# and keeps only points within RADIUS_KM of an active SOTA summit. Adds a last column:
# distance in km to the nearest active summit.
import csv, glob, math, sys, datetime, collections
RADIUS_KM = float(sys.argv[1]) if len(sys.argv) > 1 else 20.0
CELL = 0.2
today = datetime.date.today()
grid = collections.defaultdict(list)
n_summits = 0
with open("summitslist.csv", encoding="utf-8", errors="replace") as f:
    next(f)
    for r in csv.DictReader(f):
        try:
            lat, lon = float(r["Latitude"]), float(r["Longitude"])
            d, m, y = (int(x) for x in r["ValidTo"][:10].split("/"))
            if datetime.date(y, m, d) < today: continue
        except Exception:
            continue
        n_summits += 1
        dlat = RADIUS_KM / 111.2
        dlon = RADIUS_KM / (111.2 * max(math.cos(math.radians(lat)), 0.01))
        for i in range(math.floor((lat - dlat) / CELL), math.floor((lat + dlat) / CELL) + 1):
            for j in range(math.floor((lon - dlon) / CELL), math.floor((lon + dlon) / CELL) + 1):
                grid[(i, j % 1800)].append((lat, lon))
def near(lat, lon):
    best = None
    for (slat, slon) in grid.get((math.floor(lat / CELL), math.floor(lon / CELL) % 1800), ()):
        dl = math.radians(slat - lat); dn = math.radians(slon - lon)
        a = math.sin(dl/2)**2 + math.cos(math.radians(lat))*math.cos(math.radians(slat))*math.sin(dn/2)**2
        km = 12742 * math.asin(math.sqrt(a))
        if km <= RADIUS_KM and (best is None or km < best): best = km
    return best
seen = set(); stats = collections.Counter(); bands = collections.Counter()
out = open(f"parking_{int(RADIUS_KM)}km.tsv", "w", encoding="utf-8")
for path in sorted(glob.glob("out/*.tsv")):
    for line in open(path, encoding="utf-8"):
        p = line.rstrip("\n").split("\t")
        stats["read"] += 1
        oid = p[3]; key = (oid[0], int(oid[1:]))
        if key in seen: stats["dupe"] += 1; continue
        km = near(float(p[1]), float(p[2]))
        if km is None: stats["far"] += 1; continue
        seen.add(key); stats["kept"] += 1; stats["kept_" + p[0]] += 1
        bands["0-5" if km < 5 else "5-10" if km < 10 else "10-15" if km < 15 else "15-20"] += 1
        out.write(line.rstrip("\n") + "\t%.2f\n" % km)
out.close()
print("active summits", n_summits); print(dict(stats)); print(dict(bands))
