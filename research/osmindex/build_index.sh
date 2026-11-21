#!/bin/bash
# Builds the parking and trailhead list, one Geofabrik region at a time.
# Each download is deleted right after filtering. Peak disk is about 3x the largest region (~3.5 GB).
# Filtering streams each file (filter.sh, changed 2026-11-20); osmium runs in a 700 MB memory cage so it can never push BRouter out of memory.
cd /var/lib/osmindex
mkdir -p work out
log=build.log
echo "start $(date -u +%FT%TZ)" >> $log
while read id n sz url; do
  fid=${id//\//_}   # us/texas -> us_texas for file names
  [ -s "out/$fid.tsv" ] || [ -e "out/$fid.empty" ] && continue
  free=$(df --output=avail -B1 / | tail -1)
  if [ "$free" -lt $((sz * 3 + 2000000000)) ]; then echo "STOP low disk before $id" >> $log; exit 1; fi
  t0=$(date +%s)
  if ! curl -sfL --retry 3 --retry-delay 20 -A "sotaplanner-trail-index" -o work/r.pbf "$url"; then echo "FAILED download $id" >> $log; rm -f work/r.pbf; sleep 10; continue; fi
  t1=$(date +%s)
  if sudo systemd-run --quiet --scope -p MemoryMax=700M --uid=ubuntu --setenv=OSMIUM_POOL_THREADS=1 nice -n 15 bash -c "cd work && ../filter.sh r.pbf" \
     && nice -n 15 osmium export work/f.pbf -i none -f geojsonseq -a type,id -o work/f.geojsonseq --overwrite \
     && nice -n 15 python3 reduce.py work/f.geojsonseq > "out/$fid.tsv.part"; then
    mv "out/$fid.tsv.part" "out/$fid.tsv"; [ -s "out/$fid.tsv" ] || { rm -f "out/$fid.tsv"; touch "out/$fid.empty"; }
    echo "OK $id $(cat out/$fid.tsv 2>/dev/null | wc -l) rows, download $((t1 - t0))s, filter $(( $(date +%s) - t1 ))s" >> $log
  else
    echo "FAILED filter $id" >> $log
  fi
  rm -f work/r.pbf work/f.pbf work/f.geojsonseq work/idx.bin work/tagged.pbf work/have.txt work/ids.txt work/refnodes.opl work/refnodes.pbf work/m.pbf
  sleep 5
done < regions_sized.txt
echo "done $(date -u +%FT%TZ)" >> $log
