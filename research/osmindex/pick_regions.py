import json, csv, math, sys, datetime
d = json.load(open('/var/lib/osmindex/index-v1.json'))
regs = {}
children = {}
for f in d['features']:
    p = f['properties']; g = f['geometry']
    if not g: continue
    polys = g['coordinates'] if g['type'] == 'MultiPolygon' else [g['coordinates']]
    rings = []
    for poly in polys:
        for ring in poly:  # treat holes as rings too (even-odd)
            xs = [c[0] for c in ring]; ys = [c[1] for c in ring]
            rings.append((min(xs), min(ys), max(xs), max(ys), ring))
    regs[p['id']] = dict(parent=p.get('parent'), rings=rings, url=p['urls']['pbf'])
    if p.get('parent'): children.setdefault(p['parent'], []).append(p['id'])
def depth(r):
    n = 0
    while regs[r]['parent'] and regs[r]['parent'] in regs: r = regs[r]['parent']; n += 1
    return n
dep = {r: depth(r) for r in regs}
size = {}
for line in open('/var/lib/osmindex/all_sizes.txt'):
    a = line.split()
    size[a[0]] = int(a[1]) if len(a) > 1 and a[1].isdigit() else 10**12
def inside(rid, x, y):
    c = False
    for (x0, y0, x1, y1, ring) in regs[rid]['rings']:
        if x < x0 or x > x1 or y < y0 or y > y1: continue
        n = len(ring); j = n - 1
        for i in range(n):
            xi, yi = ring[i]; xj, yj = ring[j]
            if (yi > y) != (yj > y) and x < (xj - xi) * (y - yi) / (yj - yi) + xi: c = not c
            j = i
    return c
# grid of 1-degree cells -> candidate regions (bbox)
cell = {}
for rid, r in regs.items():
    for (x0, y0, x1, y1, ring) in r['rings']:
        for gx in range(math.floor(x0), math.floor(x1) + 1):
            for gy in range(math.floor(y0), math.floor(y1) + 1):
                cell.setdefault((gx, gy), set()).add(rid)
today = datetime.date.today()
own = {}; skipped = []; need = {}; nosum = 0; miss = 0; pts = 0
rows = list(csv.reader(open('/var/lib/osmindex/summitslist.csv', encoding='utf-8', errors='replace')))[2:]
for row in rows:
    try:
        vt = datetime.datetime.strptime(row[13], '%d/%m/%Y').date()
        if vt < today: continue
        lon = float(row[8]); lat = float(row[9])
    except Exception: continue
    nosum += 1
    dl = 12 / 111.0; dx = dl / max(0.2, math.cos(math.radians(lat)))
    for k, (x, y) in enumerate([(lon, lat), (lon + dx, lat), (lon - dx, lat), (lon, lat + dl), (lon, lat - dl)]):
        pts += 1
        best = None
        for rid in cell.get((math.floor(x), math.floor(y)), ()):
            if dep[rid] > 0 and (best is None or size[rid] < size[best]) and inside(rid, x, y): best = rid
        if best is None:
            miss += 1
            if k == 0: skipped.append(row[0])
            continue
        if k == 0: own[best] = own.get(best, 0) + 1
        need[best] = need.get(best, 0) + 1
# drop regions whose ancestor is also needed (ancestor covers them)
def anc(r):
    out = []
    while regs[r]['parent'] in regs: r = regs[r]['parent']; out.append(r)
    return out
# a big region picked only by buffer points spilling over a border: ignore it
for r in list(need):
    if own.get(r, 0) == 0 and need[r] < 50 and size[r] > 3e8: print('ignore spill', r, need[r], file=sys.stderr); del need[r]
# a parent picked only for a few points in the gaps between its own children: use the children
for r in list(need):
    kids = [c for c in need if r in anc(c)]
    if kids and need[r] < 50: print('ignore gap points', r, need[r], file=sys.stderr); del need[r]
final = {r: n for r, n in need.items() if not any(a in need for a in anc(r))}
dropped = [r for r in need if r not in final]
print('skipped (no regional extract):', len(skipped), sorted(set(x.split('/')[0] for x in skipped)), file=sys.stderr)
print('valid summits', nosum, 'points', pts, 'outside all regions', miss, file=sys.stderr)
print('regions needed', len(need), 'after removing nested', len(final), 'dropped', dropped, file=sys.stderr)
tot = 0
for r, n in sorted(final.items()): print(r, n, size[r], regs[r]['url']); tot += size[r]
print('TOTAL GB', round(tot/1e9, 1), file=sys.stderr)
