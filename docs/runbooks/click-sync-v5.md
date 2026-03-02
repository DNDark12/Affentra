# Click Sync v5 Runbook

## 1. Queue and scheduler startup

Run worker with queue priority `sync,default`:

```bash
php artisan queue:work database --queue=sync,default --sleep=1 --tries=3 --timeout=120 --memory=256 --no-interaction
```

Run scheduler:

```bash
php artisan schedule:work
```

Docker services are provided in `docker-compose.yml`:

- `worker-sync`
- `scheduler`

## 2. Deploy procedure

Before deploy:

```bash
php artisan queue:restart
```

After deploy:

1. Restart worker process (`worker-sync`).
2. Restart scheduler process (`scheduler`).

## 3. Health checks

Failed jobs:

```bash
php artisan queue:failed
```

Queue backlog and retry pressure:

```sql
SELECT id, queue, attempts, created_at
FROM jobs
ORDER BY id DESC
LIMIT 50;
```

Connection sync status:

```sql
SELECT id, platform, status, sync_mode, last_sync_status, last_error_at
FROM platform_connections
ORDER BY id DESC;
```

## 4. Expected statuses

- Full success: `completed`
- Partial success: `completed_with_warnings`
- Full failure: `failed_*` (`failed_auth`, `failed_api`, `failed_validation`) or `rate_limited`

## 5. Cookie test behavior

Cookie test is now `any endpoint pass`:

- Probes:
  - `dashboard/detail`
  - `report/list` (conversion)
  - `click_report/list`
- Result is valid when at least one probe returns success.
- Failed probes are returned in `checks` and summarized in `message`.
