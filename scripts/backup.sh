#!/bin/bash
set -e

# Load env variables from .env
if [ -f .env ]; then
  export $(cat .env | sed 's/#.*//g' | xargs)
fi

DB_CONTAINER="inventory-app-db-1"
DB_USER=${DB_USERNAME:-inventory}
DB_PASS=${DB_PASSWORD:-secret}
DB_NAME=${DB_DATABASE:-inventory}
BACKUP_DIR="./backups"
DATE=$(date +"%Y%m%d_%H%M%S")
FILENAME="${BACKUP_DIR}/${DB_NAME}_${DATE}.sql.gz"

mkdir -p ${BACKUP_DIR}

echo "Starting backup of ${DB_NAME} to ${FILENAME}..."

docker exec ${DB_CONTAINER} mysqldump -u${DB_USER} -p${DB_PASS} ${DB_NAME} | gzip > ${FILENAME}

echo "Backup completed: ${FILENAME}"

# Retention: keep only last 7 days
find ${BACKUP_DIR} -name "*.sql.gz" -type f -mtime +7 -delete

echo "Old backups cleaned up."
