#!/usr/bin/env bash
# Nightly MariaDB backup for GSD (risk-register durability item).
# Install on the box, e.g.:
#   crontab -e  ->  0 3 * * * /var/www/gsd/deploy/backup/mysqldump-gsd.sh >> /var/log/gsd-backup.log 2>&1
# Credentials come from the backup user's ~/.my.cnf (never on the command line).
set -euo pipefail

DB_NAME="${GSD_DB_NAME:-gsd}"
BACKUP_DIR="${GSD_BACKUP_DIR:-/var/backups/gsd}"
RETENTION_DAYS="${GSD_BACKUP_RETENTION_DAYS:-14}"

mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$BACKUP_DIR/gsd-$STAMP.sql.gz"

mysqldump --single-transaction --quick "$DB_NAME" | gzip > "$OUT"

# Prune dumps older than the retention window.
find "$BACKUP_DIR" -name 'gsd-*.sql.gz' -mtime +"$RETENTION_DAYS" -delete

echo "Backup written: $OUT"
