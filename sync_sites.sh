#!/bin/bash
# Sync GPX files ki6cr → sotaplannerdotcom
rsync -a /home/chrisr069/ki6cr.com/sotaplanner/gpx_files/ /home/chrisr069/sotaplannerdotcom/gpx_files/

# Sync full site sotaplannerdotcom → ki6cr (excluding admin.php)
mkdir -p /home/chrisr069/ki6cr.com/sotaplanner/gpx_files
rsync -a --delete --exclude=admin.php /home/chrisr069/sotaplannerdotcom/ /home/chrisr069/ki6cr.com/sotaplanner/
