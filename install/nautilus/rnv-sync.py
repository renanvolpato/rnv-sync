"""
RNV Sync — Nautilus extension.

Shows OneDrive-style emblems inside RNV Sync account folders:
  • green check (emblem-default)        → downloaded (real file on disk)
  • cloud/sync (emblem-synchronizing)   → cloud only (0-byte placeholder)

…and a right-click menu: "Baixar" (cloud → disk) / "Liberar espaço"
(disk → cloud placeholder), bridged to the app via `php artisan
rnvsync:fs`.

Install: see install/nautilus/install.sh (needs python3-nautilus).
"""

import json
import os
import subprocess

import gi

# Declare the Nautilus API version BEFORE importing it (otherwise the
# import can silently fail on some distros — e.g. Pop!_OS — and the
# extension never loads). Try 4.x first, fall back to 3.x.
for _v in ("4.0", "3.0"):
    try:
        gi.require_version("Nautilus", _v)
        break
    except ValueError:
        continue

from gi.repository import Nautilus, GObject, GLib  # noqa: E402

CONFIG = os.path.expanduser("~/.config/rnv-sync/extension.json")


def _load():
    try:
        with open(CONFIG, "r") as fh:
            return json.load(fh)
    except Exception:
        return {"php": "php", "artisan": "", "bases": []}


def _path_of(file):
    if file.get_uri_scheme() != "file":
        return None
    return file.get_location().get_path()


def _in_base(path, bases):
    if not path:
        return False
    return any(path == b or path.startswith(b.rstrip("/") + "/") for b in bases)


def _is_downloaded(path):
    # A folder counts as "on this device" only if it holds at least one
    # real file; a tree of 0-byte placeholders is cloud-only.
    if os.path.isdir(path):
        for root, _dirs, names in os.walk(path):
            for n in names:
                try:
                    if os.path.getsize(os.path.join(root, n)) > 0:
                        return True
                except OSError:
                    pass
        return False
    try:
        return os.path.getsize(path) > 0
    except OSError:
        return False


def _pending(cfg):
    try:
        with open(cfg.get("pending", ""), "r") as fh:
            data = json.load(fh)
            return set(data) if isinstance(data, list) else set()
    except Exception:
        return set()


def _state(path, cfg):
    if path.rstrip("/") in _pending(cfg):
        return "syncing"
    if _is_downloaded(path):
        return "synced"
    return "cloud"


_EMBLEM = {
    "syncing": "emblem-rnvsync-syncing",
    "synced": "emblem-rnvsync-synced",
    "cloud": "emblem-rnvsync-cloud",  # ☁ blue, online only
}


class RnvSyncExtension(GObject.GObject, Nautilus.InfoProvider, Nautilus.MenuProvider):

    def __init__(self):
        super().__init__()
        # Live refresh: Nautilus only re-reads emblems when asked, so
        # poll the (small) set of on-screen items every 2s and force a
        # re-read of any whose state changed — emblems update on their
        # own within ~2s instead of needing a manual refresh.
        self._seen = {}  # path -> [NautilusFileInfo, last_state]
        GLib.timeout_add_seconds(2, self._tick)

    def update_file_info(self, file):
        cfg = _load()
        path = _path_of(file)
        if not _in_base(path, cfg.get("bases", [])):
            return
        # Exactly ONE emblem per item; never a doubled badge.
        st = _state(path, cfg)
        file.add_emblem(_EMBLEM[st])
        self._seen[path] = [file, st]

    def _tick(self):
        if not self._seen:
            return True
        cfg = _load()
        for path, pair in list(self._seen.items()):
            file, old = pair
            if not os.path.exists(path):
                self._seen.pop(path, None)
                continue
            new = _state(path, cfg)
            if new != old:
                pair[1] = new
                try:
                    file.invalidate_extension_info()
                except Exception:
                    self._seen.pop(path, None)
        # Keep the registry bounded (long sessions, many folders).
        if len(self._seen) > 4000:
            for p in list(self._seen)[:2000]:
                self._seen.pop(p, None)
        return True  # keep the timer alive

    def _run(self, action, path):
        cfg = _load()
        if not cfg.get("artisan"):
            return
        subprocess.Popen(
            [cfg.get("php", "php"), cfg["artisan"], "rnvsync:fs", action, path]
        )

    def _refresh(self, files):
        # Force Nautilus to re-read emblems so the state updates without
        # a manual refresh. It will reflect "syncing" immediately and the
        # final state on the next poll.
        for f in files:
            try:
                f.invalidate_extension_info()
            except Exception:
                pass

    def get_file_items(self, *args):
        # Nautilus 4 passes (files); Nautilus 3 passes (window, files).
        files = args[-1]
        cfg = _load()
        bases = cfg.get("bases", [])
        targets = [p for f in files if (p := _path_of(f)) and _in_base(p, bases)]
        if not targets:
            return []

        any_cloud = any(not _is_downloaded(p) for p in targets)
        menu = Nautilus.MenuItem(
            name="RnvSync::Action",
            label=("Manter Local (RNV Sync)" if any_cloud else "Manter Online (RNV Sync)"),
        )
        action = "download" if any_cloud else "free"

        def _activate(_m, fs=files, paths=targets):
            for p in paths:
                self._run(action, p)
            self._refresh(fs)

        menu.connect("activate", _activate)
        return [menu]
