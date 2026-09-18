#!/bin/sh
set -u

interval="${SYNDICATUM_WORKER_INTERVAL_SECONDS:-5}"
webhook_batch_size="${SYNDICATUM_WEBHOOK_BATCH_SIZE:-100}"
outbox_batch_size="${SYNDICATUM_OUTBOX_BATCH_SIZE:-100}"
stale_seconds="${SYNDICATUM_WORKER_STALE_SECONDS:-120}"

case "$interval" in
    ''|*[!0-9]*) echo "SYNDICATUM_WORKER_INTERVAL_SECONDS must be a positive integer." >&2; exit 64 ;;
esac
case "$webhook_batch_size" in
    ''|*[!0-9]*) echo "SYNDICATUM_WEBHOOK_BATCH_SIZE must be a positive integer." >&2; exit 64 ;;
esac
case "$outbox_batch_size" in
    ''|*[!0-9]*) echo "SYNDICATUM_OUTBOX_BATCH_SIZE must be a positive integer." >&2; exit 64 ;;
esac
case "$stale_seconds" in
    ''|*[!0-9]*) echo "SYNDICATUM_WORKER_STALE_SECONDS must be 30-3600." >&2; exit 64 ;;
esac
if [ "$stale_seconds" -lt 30 ] || [ "$stale_seconds" -gt 3600 ] || [ "$interval" -ge "$stale_seconds" ]; then
    echo "Worker stale threshold must be 30-3600 seconds and exceed the worker interval." >&2
    exit 64
fi
if [ "$interval" -lt 1 ] || [ "$webhook_batch_size" -lt 1 ] || [ "$outbox_batch_size" -lt 1 ]; then
    echo "Worker interval and batch sizes must be greater than zero." >&2
    exit 64
fi

trap 'exit 0' TERM INT

while :; do
    cycle_failed=0

    php /var/www/html/scripts/process-agent-webhooks.php "$webhook_batch_size" || cycle_failed=1
    php /var/www/html/scripts/process-message-outbox.php --limit="$outbox_batch_size" || cycle_failed=1

    if [ "$cycle_failed" -eq 0 ]; then
        php /var/www/html/scripts/record-delivery-worker-heartbeat.php || cycle_failed=1
    fi

    if [ "$cycle_failed" -eq 0 ]; then
        touch /tmp/syndicatum-worker-heartbeat
    else
        echo "One or more Syndicatum worker jobs failed; retrying after the configured interval." >&2
    fi

    sleep "$interval" &
    wait $!
done
