#!/bin/sh
set -eu

required_variables="PBB_AGENTCHAT_DB_HOST PBB_AGENTCHAT_DB_NAME PBB_AGENTCHAT_DB_USER PBB_AGENTCHAT_DB_PASS PBB_AGENTCHAT_SECRET SYNDICATUM_MASTER_KEY"
for variable_name in $required_variables; do
    eval "variable_value=\${$variable_name:-}"
    if [ -z "$variable_value" ]; then
        echo "Required environment variable $variable_name is not set." >&2
        exit 64
    fi
done

if [ "${#PBB_AGENTCHAT_SECRET}" -lt 32 ]; then
    echo "PBB_AGENTCHAT_SECRET must contain at least 32 characters." >&2
    exit 64
fi

if [ "${#PBB_AGENTCHAT_DB_PASS}" -lt 16 ]; then
    echo "PBB_AGENTCHAT_DB_PASS must contain at least 16 characters." >&2
    exit 64
fi

if [ "${#SYNDICATUM_MASTER_KEY}" -lt 32 ]; then
    echo "SYNDICATUM_MASTER_KEY must contain at least 32 characters." >&2
    exit 64
fi

case "$PBB_AGENTCHAT_SECRET" in
    change-me|changeme|secret|password)
        echo "PBB_AGENTCHAT_SECRET contains an unsafe placeholder value." >&2
        exit 64
        ;;
esac

case "$PBB_AGENTCHAT_DB_PASS" in
    change-me|changeme|secret|password|root)
        echo "PBB_AGENTCHAT_DB_PASS contains an unsafe placeholder value." >&2
        exit 64
        ;;
esac

case "$SYNDICATUM_MASTER_KEY" in
    change-me|changeme|secret|password)
        echo "SYNDICATUM_MASTER_KEY contains an unsafe placeholder value." >&2
        exit 64
        ;;
esac

attempt=1
maximum_attempts="${SYNDICATUM_DB_WAIT_ATTEMPTS:-60}"
case "$maximum_attempts" in
    ''|*[!0-9]*)
        echo "SYNDICATUM_DB_WAIT_ATTEMPTS must be a positive integer." >&2
        exit 64
        ;;
esac
if [ "$maximum_attempts" -lt 1 ]; then
    echo "SYNDICATUM_DB_WAIT_ATTEMPTS must be greater than zero." >&2
    exit 64
fi
while ! php -r '
try {
    $dsn = sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", getenv("PBB_AGENTCHAT_DB_HOST"), getenv("PBB_AGENTCHAT_DB_NAME"));
    new PDO($dsn, getenv("PBB_AGENTCHAT_DB_USER"), getenv("PBB_AGENTCHAT_DB_PASS"), [PDO::ATTR_TIMEOUT => 2]);
} catch (Throwable $error) {
    exit(1);
}
'; do
    if [ "$attempt" -ge "$maximum_attempts" ]; then
        echo "Database did not become ready after $maximum_attempts attempts." >&2
        exit 70
    fi
    echo "Waiting for the database ($attempt/$maximum_attempts)..." >&2
    attempt=$((attempt + 1))
    sleep 2
done

echo "Verifying the baseline installation state..." >&2
php /var/www/html/scripts/chat-db.php startup-schema

exec "$@"
