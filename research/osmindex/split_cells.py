# Splits parking_20km.tsv into one file per 0.2 x 0.2 degree map square for nginx to serve
# at https://hikepath.sotaplanner.com/parking/<i>_<j>.tsv (i = floor(lat/0.2), j = floor(lon/0.2)).
# Columns: kind (P parking, T trailhead), lat, lon, osm_id, access, fee, parking, name, km_to_nearest_summit
import sys, os, math, json, collections, datetime
src, dst = sys.argv[1], sys.argv[2]
CELL = 0.2
cells = collections.defaultdict(list); n = 0
for line in open(src, encoding="utf-8"):
    p = line.split("\t", 3)
    cells[(math.floor(float(p[1]) / CELL), math.floor(float(p[2]) / CELL))].append(line); n += 1
os.makedirs(dst, exist_ok=True)
for (i, j), rows in cells.items():
    with open(os.path.join(dst, f"{i}_{j}.tsv"), "w", encoding="utf-8") as f: f.writelines(rows)
json.dump({"version": datetime.date.today().isoformat(), "cell_deg": CELL, "radius_km": 20, "points": n, "cells": len(cells),
           "file": "{i}_{j}.tsv with i=floor(lat/0.2), j=floor(lon/0.2); missing file = no points",
           "columns": ["kind", "lat", "lon", "osm_id", "access", "fee", "parking", "name", "km_to_nearest_summit"],
           "source": "OpenStreetMap contributors (ODbL), via Geofabrik extracts"}, open(os.path.join(dst, "index.json"), "w"), indent=1)
print(n, "points in", len(cells), "cells")
