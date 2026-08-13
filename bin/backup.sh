#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APP_ROOT="/var/www/jaraguatower/ia"
ENV_FILE="$APP_ROOT/config/.env"
BACKUP_ROOT="/var/backups/jaraguatower-ia"
LOCK_FILE="/run/lock/jaraguatower-ia-backup.lock"
RETENTION_DAYS="14"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Este backup deve ser executado como root." >&2
  exit 1
fi
if [[ ! -r "$ENV_FILE" ]]; then
  echo "Arquivo de configuração não encontrado." >&2
  exit 1
fi

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

mkdir -p "$BACKUP_ROOT"
exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

STAMP="$(date +%Y%m%d-%H%M%S)"
SQL_FILE="$BACKUP_ROOT/database-$STAMP.sql"
ARCHIVE="$BACKUP_ROOT/jaraguatower-ia-$STAMP.tar.gz"

MYSQL_PWD="$DB_PASS" mysqldump \
  --protocol=socket \
  --single-transaction \
  --quick \
  --skip-lock-tables \
  --no-tablespaces \
  -u"$DB_USER" "$DB_NAME" > "$SQL_FILE"

tar -czf "$ARCHIVE" \
  -C "$BACKUP_ROOT" "$(basename "$SQL_FILE")" \
  -C "$APP_ROOT" config/.env

rm -f "$SQL_FILE"
find "$BACKUP_ROOT" -type f -name 'jaraguatower-ia-*.tar.gz' -mtime +"$RETENTION_DAYS" -delete
chmod 600 "$BACKUP_ROOT"/jaraguatower-ia-*.tar.gz

echo "Backup criado: $ARCHIVE"
