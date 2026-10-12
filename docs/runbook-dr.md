# Runbook: Disaster Recovery & Backup Restore

## 1. Backups
Backups are performed using the `scripts/backup.sh` script, which dumps the MySQL database from the Docker container to a gzipped file inside the `backups/` directory.

### To trigger a manual backup:
```bash
./scripts/backup.sh
```

### Automation
It is recommended to run this script via a cron job on the host machine, for example, daily at 2 AM:
```cron
0 2 * * * cd /path/to/inventory-app && ./scripts/backup.sh >> /var/log/inventory-backup.log 2>&1
```

## 2. Restore Procedure

If the database gets corrupted or data is lost, you can restore from the latest backup.

**WARNING: This will overwrite current data!**

### Steps to restore:
1. Locate the latest backup in the `backups/` folder (e.g. `inventory_20261005_120000.sql.gz`).
2. Make sure the database container is running (`docker compose -f docker-compose.prod.yml ps db`).
3. Decompress and pipe the SQL to the container:

```bash
gunzip < backups/inventory_20261005_120000.sql.gz | docker exec -i inventory-app-db-1 mysql -u inventory -psecret inventory
```
*(Replace `inventory` and `secret` with your `DB_USERNAME` and `DB_PASSWORD` if changed in `.env`)*

4. Verify data integrity by logging into the app or querying the DB.

## 3. Application Recovery
If the application goes down completely:
1. Pull the latest code.
2. Ensure `.env` is present with production secrets.
3. Bring up the stack: `docker compose -f docker-compose.prod.yml up -d --build`.
4. Run migrations if necessary: `docker compose -f docker-compose.prod.yml exec app php artisan migrate --force`.
