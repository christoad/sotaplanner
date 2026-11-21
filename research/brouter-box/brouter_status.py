#!/usr/bin/env python3
# Writes /var/lib/brouter-status/status.json for the SOTAplanner pages (served by nginx at /status.json, API key needed).
# Reads the last hour of /var/log/nginx/brouter_timed.log ("msec status request_time uri"). Run by brouter-status.timer every 15 s.
import json, os, time, subprocess, shutil
LOG = "/var/log/nginx/brouter_timed.log"; OUT = "/var/lib/brouter-status/status.json"
now = time.time(); rows = []
try:
    with open(LOG, "rb") as f:
        f.seek(0, 2); size = f.tell(); f.seek(max(0, size - 4_000_000))
        for line in f.read().decode("utf-8", "replace").splitlines():
            p = line.split()
            if len(p) >= 3:
                try: rows.append((float(p[0]), int(p[1]), float(p[2])))
                except ValueError: pass
except FileNotFoundError:
    pass
def window(sec):
    w = [r for r in rows if r[0] >= now - sec]
    t = sorted(r[2] for r in w if r[1] == 200)
    return {"n": len(w), "ok": len(t), "limited": sum(1 for r in w if r[1] in (429, 503)), "no_route": sum(1 for r in w if r[1] == 400), "failed": sum(1 for r in w if r[1] not in (200, 400, 429, 503)),
            "avg_s": round(sum(t) / len(t), 2) if t else None, "p90_s": round(t[int(len(t) * 0.9)], 2) if t else None}
mem = {}
for line in open("/proc/meminfo"):
    k, v = line.split(":"); mem[k] = int(v.split()[0])
du = shutil.disk_usage("/")
up = subprocess.run(["systemctl", "is-active", "brouter"], capture_output=True, text=True).stdout.strip()
st = {"updated": int(now), "brouter": up, "cpus": os.cpu_count(), "load": [round(x, 2) for x in os.getloadavg()],
      "mem_used_pct": round(100 * (1 - mem["MemAvailable"] / mem["MemTotal"])), "disk_used_pct": round(100 * du.used / du.total),
      "routes": {"1m": window(60), "5m": window(300), "60m": window(3600)},
      "last_route_ago_s": int(now - rows[-1][0]) if rows else None}
tmp = OUT + ".tmp"; open(tmp, "w").write(json.dumps(st)); os.chmod(tmp, 0o644); os.replace(tmp, OUT)
