#!/usr/bin/env bash
# Installed root-owned. Never executes application code or accepts paths.
set -euo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
[[ $# == 1 ]] || exit 64
case "$1" in
  backup)
    umask 077
    mkdir -p /var/backups/tindatala
    chmod 700 /var/backups/tindatala
    file="/var/backups/tindatala/$(date -u +%Y%m%dT%H%M%S)-$$.sql.gz"
    trap 'rm -f "$file.partial"' EXIT
    mysqldump --single-transaction --routines --triggers --events --no-tablespaces tindatala_prod | gzip > "$file.partial"
    gzip -t "$file.partial"
    mv "$file.partial" "$file"
    # Keep three successful backups; these are local recovery copies, not off-site backups.
    find /var/backups/tindatala -maxdepth 1 -type f -name '*.sql.gz' -printf '%f\n' | sort -r | tail -n +4 | while read -r old; do
      rm -- "/var/backups/tindatala/$old"
    done
    echo 'Database backup completed.'
    ;;
  pause)
    systemctl stop tindatala-scheduler.timer
    # Let a running scheduled task finish instead of killing it.
    for attempt in $(seq 1 60); do
      state=$(systemctl show tindatala-scheduler.service -p ActiveState --value)
      [[ "$state" != active && "$state" != activating ]] && break
      sleep 1
    done
    [[ "$state" != active && "$state" != activating ]] || exit 1
    systemctl stop tindatala-worker
    ;;
  resume)
    systemctl reload php8.5-fpm
    systemctl restart tindatala-worker
    systemctl start tindatala-scheduler.timer
    ;;
  *) exit 64 ;;
esac
