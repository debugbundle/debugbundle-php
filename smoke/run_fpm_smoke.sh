#!/bin/sh

set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
run_dir=$(mktemp -d)

cleanup() {
  if [ -r "$run_dir/container.id" ]; then
    docker rm -f "$(cat "$run_dir/container.id")" >/dev/null 2>&1 || true
    rm -- "$run_dir/container.id"
  fi
  rmdir "$run_dir" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

docker run --rm -d \
  --cidfile "$run_dir/container.id" \
  --health-cmd 'php -r '\''$s = @fsockopen("127.0.0.1", 9000, $errno, $error, 1); if (!$s) exit(1); fclose($s);'\''' \
  --health-interval 1s --health-timeout 2s --health-retries 15 \
  -p 127.0.0.1::9000 \
  -v "$repo_dir:/app:ro" \
  -w /app \
  php:8.2-fpm@sha256:109746bc1e4ca075688fc5cdf743b41b642ae552177c9030327ec8bbf9cd9c59 >/dev/null

container_id=$(cat "$run_dir/container.id")
# Docker's published TCP port can accept before FPM is listening. Check the
# process inside the container before sending the measured request.
remaining=20
until [ "$(docker inspect --format '{{.State.Health.Status}}' "$container_id")" = "healthy" ]; do
  remaining=$((remaining - 1))
  if [ "$remaining" -eq 0 ]; then
    docker logs "$container_id" >&2
    echo "PHP-FPM did not become ready" >&2
    exit 1
  fi
  sleep 1
done
port=$(docker port "$container_id" 9000/tcp | awk -F: '{ print $NF }')
python3 "$repo_dir/smoke/fpm_fastcgi_client.py" "$port"
