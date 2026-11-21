#!/bin/bash
# Streaming filter: parking/trailhead objects plus the nodes of their ways, then node locations.
# No big node index (the old add-locations-to-ways step thrashed on regions over ~400 MB).
# Run inside work/; writes f.pbf with locations on ways.
set -eo pipefail
in=$1
osmium tags-filter -R "$in" nwr/amenity=parking nwr/highway=trailhead nwr/tourism=trailhead -o tagged.pbf --overwrite
osmium cat tagged.pbf -t node -f opl | cut -d" " -f1 | sort -u > have.txt
osmium cat tagged.pbf -t way -f opl | sed -n "s/.* N\([^ ]*\).*/\1/p" | tr "," "\n" | sed -n "s/^n\([0-9]*\).*/n\1/p" | sort -u | comm -23 - have.txt > ids.txt
osmium cat "$in" -t node -f opl,add_metadata=false | awk "NR==FNR{ids[\$1];next} (\$1 in ids)" ids.txt - > refnodes.opl
osmium cat -F opl refnodes.opl -o refnodes.pbf --overwrite
osmium merge tagged.pbf refnodes.pbf -o m.pbf --overwrite
osmium add-locations-to-ways -i flex_mem m.pbf -f pbf,locations_on_ways=true -o f.pbf --overwrite
