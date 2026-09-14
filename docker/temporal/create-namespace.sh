#!/bin/sh
set -eu

: "${TEMPORAL_ADDRESS:=temporal:7233}"
: "${TEMPORAL_NAMESPACE:=archibot}"

until temporal operator cluster health --address "$TEMPORAL_ADDRESS" >/dev/null 2>&1; do
    sleep 2
done

if temporal operator namespace describe --namespace "$TEMPORAL_NAMESPACE" --address "$TEMPORAL_ADDRESS" >/dev/null 2>&1; then
    echo "Temporal namespace '$TEMPORAL_NAMESPACE' already exists."
    exit 0
fi

temporal operator namespace create --namespace "$TEMPORAL_NAMESPACE" --address "$TEMPORAL_ADDRESS"
echo "Temporal namespace '$TEMPORAL_NAMESPACE' created."
