#!/bin/sh
# Periodic pg_dump loop for the mkEngage stack — run by the db-backup
# service in docker-compose.prod.yml. Connection comes from the standard
# PG* environment variables; output lands in /backups (bind-mounted to
# deploy/backups on the host).
set -u

keep_days="${BACKUP_KEEP_DAYS:-14}"
interval_hours="${BACKUP_INTERVAL_HOURS:-24}"

while true; do
  stamp="$(date +%Y-%m-%d_%H%M%S)"
  tmp="/backups/.mkengage-${stamp}.sql.gz.part"
  out="/backups/mkengage-${stamp}.sql.gz"

  # Write to a .part file first so a crash mid-dump never leaves a
  # truncated file that looks like a valid backup.
  if pg_dump | gzip >"$tmp" && [ -s "$tmp" ]; then
    mv "$tmp" "$out"
    echo "backup written: $out ($(du -h "$out" | cut -f1))"
  else
    rm -f "$tmp"
    echo "backup FAILED at $stamp" >&2
  fi

  find /backups -name 'mkengage-*.sql.gz' -mtime +"$keep_days" -delete

  sleep "$((interval_hours * 3600))"
done
