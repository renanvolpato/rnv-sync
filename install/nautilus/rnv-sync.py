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
from gi.repository import Nautilus, GObject

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


class RnvSyncExtension(GObject.GObject, Nautilus.InfoProvider, Nautilus.MenuProvider):

    def update_file_info(self, file):
        cfg = _load()
        path = _path_of(file)
        if not _in_base(path, cfg.get("bases", [])):
            return
        # Custom emblems shipped by the installer (reliable + correct
        # colors). Fall back to stock names if the theme lacks ours.
        if path.rstrip("/") in _pending(cfg):
            file.add_emblem("emblem-rnvsync-syncing")
            file.add_emblem("emblem-synchronizing")
        elif _is_downloaded(path):
            file.add_emblem("emblem-rnvsync-synced")
            file.add_emblem("emblem-default")
        else:
            file.add_emblem("emblem-rnvsync-cloud")  # ☁ blue, online only

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
