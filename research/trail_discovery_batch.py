# Usage (from the Mac): python3 trail_discovery_batch.py https://christopherreddick.com/sotaplanner-a <run> [<run> ...]
# Needs a staging slot (developer login). Results land in the shared DB and sota_discovered/, so production shows them too.
# Runs every test summit of a trail discovery run on a staging slot, one at a time, re-logging in if the session expires.
import sys, json, re, time, urllib.request, urllib.parse, http.cookiejar
base, runs = sys.argv[1], [int(x) for x in sys.argv[2:]]
cj = http.cookiejar.CookieJar(); op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
def login():
    op.open(base + "/login.php", timeout=30).read()
    op.open(base + "/login.php", data=urllib.parse.urlencode({"callsign": "KI6CR", "dev_login": "1"}).encode(), timeout=30).read()
login()
for run in runs:
    html = op.open(f"{base}/trail_discovery.php?run={run}", timeout=60).read().decode()
    todo = json.loads(re.search(r"const TEST_SET = (\[.*?\]);", html).group(1))
    done = {r["sota_ref"] for r in json.loads(op.open(f"{base}/trail_discovery.php?ajax=results&run={run}", timeout=60).read())["rows"] if r["status"] != "error"}
    todo = [t for t in todo if t not in done]
    print(f"run {run}: {len(todo)} summits to do", flush=True)
    for i, ref in enumerate(todo):
        for attempt in range(3):
            try:
                raw = op.open(f"{base}/trail_discovery.php?ajax=one&run={run}&ref={urllib.parse.quote(ref)}", timeout=300).read()
                r = json.loads(raw); break
            except json.JSONDecodeError:
                login(); r = {"ok": False, "error": "not JSON (session?)"}
            except Exception as e:
                r = {"ok": False, "error": str(e)}; time.sleep(30)
        row = r.get("row") or {}
        print(f"run {run} {i+1}/{len(todo)} {ref} {row.get('status', r.get('error'))} {row.get('verdict')} {row.get('seconds')}s pk {row.get('best_parking_m')}/{row.get('known_parking_m')}", flush=True)
        time.sleep(2)
print("ALL DONE", flush=True)
