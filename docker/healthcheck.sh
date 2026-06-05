#!/bin/sh
set -eu

curl -fsS "http://localhost:${GUI_PORT:-8088}/healthz" >/dev/null

if [ -S /tmp/supervisor.sock ]; then
    supervisorctl -c /app/docker/supervisord.conf status | awk '
        $2 != "RUNNING" {
            print "supervisor program is not running: " $0 > "/dev/stderr";
            failed = 1;
        }
        END { exit failed ? 1 : 0 }
    '
fi
