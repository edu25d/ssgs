#!/usr/bin/env bash
# 세션 작업 시간 로그. 시작/종료 시각을 dev/session_time.log에 기록한다.

LOG="dev/session_time.log"

log() {
  local tag="$1"
  echo "$(date '+%Y-%m-%d %H:%M') [$tag]" >> "$LOG"
}

case "${1:-}" in
  start)
    log "START"
    echo "세션 시작 기록: $(tail -n1 "$LOG")"
    ;;
  end)
    log "END"
    echo "세션 종료 기록: $(tail -n1 "$LOG")"
    ;;
  status)
    if [ -f "$LOG" ]; then
      tail -n5 "$LOG"
    else
      echo "기록 없음 (아직 시작되지 않음)"
    fi
    ;;
  reset)
    echo -n "" > "$LOG"
    echo "세션 시간 로그 초기화"
    ;;
  *)
    echo "usage: $0 {start|end|status|reset}"
    ;;
esac
