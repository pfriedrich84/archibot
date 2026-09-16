#!/bin/sh
set -eu

: "${TEMPORAL_ADDRESS:=temporal:7233}"
: "${TEMPORAL_NAMESPACE:=archibot}"

until temporal operator cluster health --address "$TEMPORAL_ADDRESS" >/dev/null 2>&1; do
    sleep 2
done

if temporal operator namespace describe --namespace "$TEMPORAL_NAMESPACE" --address "$TEMPORAL_ADDRESS" >/dev/null 2>&1; then
    echo "Temporal namespace '$TEMPORAL_NAMESPACE' already exists."
else
    temporal operator namespace create --namespace "$TEMPORAL_NAMESPACE" --address "$TEMPORAL_ADDRESS"
    echo "Temporal namespace '$TEMPORAL_NAMESPACE' created."
fi

ensure_search_attribute() {
    name="$1"
    type="$2"
    output="$(temporal operator search-attribute create \
        --namespace "$TEMPORAL_NAMESPACE" \
        --address "$TEMPORAL_ADDRESS" \
        --name "$name" \
        --type "$type" 2>&1)" && status=0 || status=$?
    if [ "$status" -eq 0 ]; then
        echo "Temporal search attribute '$name' created."
        return
    fi
    case "$output" in
        *"already exists"*|*"AlreadyExists"*)
            echo "Temporal search attribute '$name' already exists."
            ;;
        *)
            echo "$output" >&2
            return "$status"
            ;;
    esac
}

ensure_search_attribute ArchiBotPhase Keyword
ensure_search_attribute ArchiBotPipelineRunId Int
ensure_search_attribute ArchiBotDocumentId Int
