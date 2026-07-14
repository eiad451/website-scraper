#!/bin/bash
DIR="$(cd "$(dirname "$0")" && pwd)"
PORT=${1:-8080}
PIDFILE="$DIR/.scraper.pid"

start() {
    php "$DIR/start.php"
}

stop() {
    if [ -f "$PIDFILE" ]; then
        kill "$(cat "$PIDFILE")" 2>/dev/null
        rm -f "$PIDFILE"
    fi
    pkill -f "php -S 0.0.0.0:$PORT" 2>/dev/null
    echo "⏹ توقف"
}

status() {
    if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        echo "🟢 شغال http://localhost:$PORT"
    else
        echo "🔴 متوقف"
    fi
}

case "${1:-start}" in
    start|run) start ;;
    stop|off) stop ;;
    restart) stop; sleep 0.5; start ;;
    status) status ;;
    *) echo "استعمال: ./server.sh {start|stop|restart|status}" ;;
esac
