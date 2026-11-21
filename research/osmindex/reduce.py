# Reads osmium geojsonseq, prints one TSV row per parking lot or trailhead:
# kind lat lon osm_id access fee parking name   (kind T = trailhead, P = parking)
import sys, json
def pts(g):
    t, c = g['type'], g['coordinates']
    if t == 'Point': return [c]
    if t == 'LineString': return c
    if t == 'Polygon': return c[0]
    if t == 'MultiPolygon': return [p for poly in c for p in poly[0]]
    return []
def clean(v, n=60): return (v or '').replace('\t', ' ').replace('\n', ' ')[:n]
seen = set()
for line in open(sys.argv[1], encoding='utf-8'):
    line = line.strip().lstrip('\x1e')
    if not line: continue
    f = json.loads(line); p = f.get('properties') or {}
    th = p.get('highway') == 'trailhead' or p.get('tourism') == 'trailhead'
    pk = p.get('amenity') == 'parking'
    if not (th or pk): continue
    if pk and not th and p.get('parking') in ('underground', 'multi-storey', 'rooftop'): continue
    ps = pts(f['geometry'])
    if not ps: continue
    lon = sum(q[0] for q in ps) / len(ps); lat = sum(q[1] for q in ps) / len(ps)
    oid = p.get('@type', '?')[0] + str(p.get('@id', ''))
    if oid in seen: continue
    seen.add(oid)
    print('\t'.join(['T' if th else 'P', '%.5f' % lat, '%.5f' % lon, oid, clean(p.get('access'), 20),
                     clean(p.get('fee'), 10), clean(p.get('parking'), 20), clean(p.get('name'))]))
