#!/bin/sh
set -eu

validate_password() {
    variable_name="$1"
    eval "variable_value=\${$variable_name:-}"

    if [ "${#variable_value}" -lt 16 ]; then
        echo "$variable_name must contain at least 16 characters." >&2
        exit 64
    fi

    case "$variable_value" in
        change-me|changeme|secret|password|root|mysql|syndicatum)
            echo "$variable_name contains an unsafe placeholder value." >&2
            exit 64
            ;;
    esac
}

validate_password MYSQL_ROOT_PASSWORD
validate_password MYSQL_PASSWORD

if [ "$MYSQL_ROOT_PASSWORD" = "$MYSQL_PASSWORD" ]; then
    echo "MYSQL_ROOT_PASSWORD and MYSQL_PASSWORD must be different." >&2
    exit 64
fi

exec /usr/local/bin/docker-entrypoint.sh "$@"
