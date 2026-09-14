#!/bin/sh
set -eu

: "${POSTGRES_SEEDS:?POSTGRES_SEEDS is required}"
: "${POSTGRES_USER:?POSTGRES_USER is required}"
: "${DB_PORT:=5432}"

sql_tool() {
    database="$1"
    action="$2"
    version="${3:-}"
    if [ -n "$version" ]; then
        temporal-sql-tool --plugin postgres12 --ep "$POSTGRES_SEEDS" \
            -u "$POSTGRES_USER" -p "$DB_PORT" --db "$database" \
            "$action" -v "$version"
    else
        temporal-sql-tool --plugin postgres12 --ep "$POSTGRES_SEEDS" \
            -u "$POSTGRES_USER" -p "$DB_PORT" --db "$database" \
            "$action"
    fi
}

database_exists() {
    psql -h "$POSTGRES_SEEDS" -p "$DB_PORT" -U "$POSTGRES_USER" -d postgres -tAc \
        "SELECT 1 FROM pg_database WHERE datname = '$1'" | grep -qx 1
}

schema_exists() {
    psql -h "$POSTGRES_SEEDS" -p "$DB_PORT" -U "$POSTGRES_USER" -d "$1" -tAc \
        "SELECT to_regclass('public.schema_version') IS NOT NULL" | grep -qx t
}

prepare_database() {
    database="$1"
    schema_dir="$2"

    if ! database_exists "$database"; then
        sql_tool "$database" create
    fi
    if ! schema_exists "$database"; then
        sql_tool "$database" setup-schema 0.0
    fi
    temporal-sql-tool \
        --plugin postgres12 \
        --ep "$POSTGRES_SEEDS" \
        -u "$POSTGRES_USER" \
        -p "$DB_PORT" \
        --db "$database" \
        update-schema -d "$schema_dir"
}

prepare_database temporal /etc/temporal/schema/postgresql/v12/temporal/versioned
prepare_database temporal_visibility /etc/temporal/schema/postgresql/v12/visibility/versioned
echo "Temporal PostgreSQL schemas are ready."
