#!/usr/bin/env bash
# Forced SSH command, installed root-owned outside the writable releases.
set -euo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
umask 0007
if [[ ! ${SSH_ORIGINAL_COMMAND:-} =~ ^deploy\ ([a-f0-9]{40})\ ([0-9]+-[0-9]+)\ ([a-f0-9]{64})$ ]]; then
  echo 'Only verified deployment uploads are accepted.' >&2
  exit 64
fi
sha=${BASH_REMATCH[1]}
run=${BASH_REMATCH[2]}
digest=${BASH_REMATCH[3]}
base=/var/www/tindatala
exec 9>"$base/.deploy.lock"
flock -n 9 || { echo 'Another deployment is running.' >&2; exit 75; }
[[ $(df -Pk "$base" | awk 'NR==2 {print $4}') -gt 716800 ]] || { echo 'Need at least 700 MiB free.' >&2; exit 1; }
release="$base/releases/$sha-$run"
[[ ! -e "$release" ]] || { echo 'Release already exists.' >&2; exit 1; }
previous=$(readlink -f "$base/current")
[[ "$previous" == "$base/releases/"* && -f "$previous/artisan" ]] || exit 1
archive=$(mktemp "$base/incoming/release.XXXXXX")
maintenance=0
switched=0
finish() {
  result=$?
  trap - EXIT
  if [[ $result != 0 ]]; then
    if [[ $switched == 1 ]]; then
      ln -s "$previous" "$base/.current-rollback"
      mv -Tf "$base/.current-rollback" "$base/current"
      echo 'Previous code restored. Database changes are NOT automatically reversed.' >&2
    fi
    if [[ $maintenance == 1 ]]; then
      (cd "$previous" && php artisan up) || true
      sudo -n /usr/local/sbin/tindatala-deploy-services resume || true
    fi
  fi
  rm -f "$archive"
  exit "$result"
}
trap finish EXIT
# Bound compressed upload size; a too-large or truncated archive is rejected.
head -c 209715201 > "$archive"
[[ $(stat -c %s "$archive") -le 209715200 ]] || exit 1
echo "$digest  $archive" | sha256sum --check --status
mkdir "$release"
python3 /usr/local/libexec/tindatala-unpack.py "$archive" "$release"
cd "$release"
[[ -f artisan && -f vendor/autoload.php && -f public/index.php ]] || exit 1
find . -type d -exec chmod 2750 {} +
find . -type f -exec chmod 640 {} +
mkdir -p bootstrap/cache
chmod 2770 bootstrap/cache
ln -s "$base/shared/.env" .env
ln -s "$base/shared/storage" storage
php -r 'require "vendor/autoload.php";'
php artisan package:discover --ansi
php artisan config:cache
php artisan route:cache
ln -s "$base/shared/storage/app/public" public/storage
maintenance=1
(cd "$previous" && php artisan down --retry=60)
sudo -n /usr/local/sbin/tindatala-deploy-services pause
sudo -n /usr/local/sbin/tindatala-deploy-services backup
php artisan migrate --force --no-interaction
php artisan view:cache
ln -s "$release" "$base/.current-next"
mv -Tf "$base/.current-next" "$base/current"
switched=1
php artisan up
sudo -n /usr/local/sbin/tindatala-deploy-services resume
curl --fail --silent --show-error --retry 5 --retry-delay 2 --max-time 15 \
  --resolve api.tindatala.store:443:127.0.0.1 https://api.tindatala.store/api/v1/health | \
  python3 -c 'import json,sys; assert json.load(sys.stdin)["status"] == "ok"'
systemctl is-active --quiet tindatala-worker
maintenance=0
printf '%s\n' "$sha" > "$release/REVISION"
echo "Deployment healthy: $sha"
# Keep current, previous and initial; prune only validated generated release names.
for old in "$base"/releases/*; do
  [[ -d "$old" && ! -L "$old" && "$old" != "$release" && "$old" != "$previous" ]] || continue
  [[ $(basename "$old") =~ ^[a-f0-9]{40}-[0-9]+-[0-9]+$ ]] || continue
  rm -rf -- "$old"
done
