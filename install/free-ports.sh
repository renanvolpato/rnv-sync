#!/usr/bin/env bash
# Free RNV Sync dev ports before starting, so a stale serve / leftover
# rclone child never causes "Address already in use". Only touches the
# current user's own processes (no sudo).
set -u

for port in 8770 8771; do
  if command -v fuser >/dev/null 2>&1; then
    fuser -k "${port}/tcp" >/dev/null 2>&1 || true
  else
    pids=$(ss -ltnp 2>/dev/null | grep ":${port} " | grep -oE 'pid=[0-9]+' | grep -oE '[0-9]+' | sort -u)
    for p in $pids; do kill -9 "$p" >/dev/null 2>&1 || true; done
  fi
done

# Give the kernel a moment to release the sockets.
sleep 1 2>/dev/null || true
exit 0
