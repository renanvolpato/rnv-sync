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
    if os.path.isdir(path):
        return True
    try:
        return os.path.getsize(path) > 0
    except OSError:
        return False


class RnvSyncExtension(GObject.GObject, Nautilus.InfoProvider, Nautilus.MenuProvider):

    def update_file_info(self, file):
        cfg = _load()
        path = _path_of(file)
        if not _in_base(path, cfg.get("bases", [])):
            return
        if _is_downloaded(path):
            file.add_emblem("emblem-default")        # ✓ green check
        else:
            file.add_emblem("emblem-synchronizing")  # ☁ cloud

    def _run(self, action, path):
        cfg = _load()
        if not cfg.get("artisan"):
            return
        subprocess.Popen(
            [cfg.get("php", "php"), cfg["artisan"], "rnvsync:fs", action, path]
        )

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
            label=("Sempre manter neste dispositivo (RNV Sync)" if any_cloud else "Liberar espaço (RNV Sync)"),
        )
        action = "download" if any_cloud else "free"
        menu.connect(
            "activate",
            lambda _m: [self._run(action, p) for p in targets],
        )
        return [menu]
