# API deployment

Push to `main` to run PHP 8.3/8.5 tests, formatting checks and MySQL concurrency tests. Only a passing main build deploys. Pull requests never deploy. The API checks workflow also supports manual dispatch on main. Superseded queued commits are skipped.

GitHub builds production dependencies and streams a checksummed archive over SSH. Production `.env` and storage stay in `/var/www/tindatala/shared`; they are never included in the artifact. Releases live under `/var/www/tindatala/releases` and `current` selects the active release.

## Server bootstrap (already installed)

- `receive.sh`: root-owned `/usr/local/libexec/tindatala-receive`
- `unpack.py`: root-owned `/usr/local/libexec/tindatala-unpack.py`
- `services.sh`: root-owned `/usr/local/sbin/tindatala-deploy-services`
- Dedicated `tindatala-deploy` account with primary group `www-data`.
- Root-controlled authorized key uses `restrict,command="/usr/local/libexec/tindatala-receive"`. Shell, forwarding and arbitrary commands are unavailable with this key.
- Sudo permits only the services helper's `backup`, `pause` and `resume` actions.
- Repository Actions secrets: `EC2_DEPLOY_KEY` (dedicated private key) and `EC2_KNOWN_HOSTS` (verified server host keys). The operator's personal PEM is not used by Actions.

Changes to these trusted server scripts must be installed by an administrator separately; a repository push does not overwrite them. The deployment account owns the releases directory and parent used for switching `current`, and can read the shared environment and write shared storage through `www-data`.

## Deployment behavior

The server validates the archive and prepares caches before entering a short maintenance window. It stops scheduled work and the queue worker, takes a database backup, applies migrations, switches `current`, restarts services and checks the HTTPS health endpoint. The scheduler is resumed automatically.

On failure during maintenance, it attempts to restore the previous code and resume services. **Database migrations are not automatically reversed.** Use backward-compatible migrations and review destructive schema changes before pushing. A failed backup prevents migration. The health check confirms application startup, not every business workflow.

Backups are root-only compressed SQL files in `/var/backups/tindatala`; the latest three are retained. They are on the same EC2 disk and do not protect against instance/disk loss. Off-instance backups remain a separate task. Current and previous generated releases plus the original `initial` release are retained. The receiver refuses deployment with less than 700 MiB available.

## Operator checks

```sh
readlink -f /var/www/tindatala/current
cat /var/www/tindatala/current/REVISION
curl --fail https://api.tindatala.store/api/v1/health
systemctl is-active tindatala-worker tindatala-scheduler.timer
sudo journalctl -u tindatala-worker -n 30 --no-pager
```

To change production environment values, edit `/var/www/tindatala/shared/.env`, then run `sudo -u www-data php artisan config:cache` from `/var/www/tindatala/current` and restart `tindatala-worker`. Do not regenerate the production application key.

If an EC2 public IP/hostname or SSH host key changes, update DNS, workflow destination and the verified known-hosts secret as appropriate. Never disable SSH host-key verification to bypass a mismatch.
