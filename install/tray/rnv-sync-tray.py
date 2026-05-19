#!/usr/bin/env python3
"""
RNV Sync — system-tray indicator.

Sits next to the clock (like AnyDesk): shows the app icon when
everything is synced, and an animated spinner while syncing. Reads
state from the always-on local web panel (GET /sync-state), so it
adds no load of its own — just a 3s HTTP poll on localhost.

Menu: open the panel / quit.
Needs: gir1.2-ayatanaappindicator3-0.1 (or the older AppIndicator3),
python3-gi, gir1.2-gtk-3.0 — installed by install/bootstrap.sh.
"""

import json
import os
import subprocess
import urllib.request

import gi

gi.require_version("Gtk", "3.0")
from gi.repository import Gtk, GLib  # noqa: E402

# Ayatana is the maintained fork; fall back to the legacy name.
_Ind = None
for _ns in ("AyatanaAppIndicator3", "AppIndicator3"):
    try:
        gi.require_version(_ns, "0.1")
        _Ind = getattr(__import__("gi.repository", fromlist=[_ns]), _ns)
        break
    except (ValueError, ImportError):
        continue

URL = os.environ.get("RNV_SYNC_URL", "http://127.0.0.1:8080")
APP_ICON = "rnv-sync"                      # idle / everything synced
FRAMES = [f"rnv-sync-sync-{i}" for i in range(8)]  # spinner animation
POLL_MS = 3000
ANIM_MS = 120


class Tray:
    def __init__(self):
        self.ind = _Ind.Indicator.new(
            "rnv-sync",
            APP_ICON,
            _Ind.IndicatorCategory.APPLICATION_STATUS,
        )
        self.ind.set_status(_Ind.IndicatorStatus.ACTIVE)
        self.ind.set_title("RNV Sync")
        self.ind.set_menu(self._menu())

        self._syncing = False
        self._frame = 0
        GLib.timeout_add(POLL_MS, self._poll)
        GLib.timeout_add(ANIM_MS, self._animate)
        self._poll()

    def _menu(self):
        m = Gtk.Menu()

        open_item = Gtk.MenuItem(label="Abrir RNV Sync")
        open_item.connect("activate", lambda *_: self._open())
        m.append(open_item)

        m.append(Gtk.SeparatorMenuItem())

        quit_item = Gtk.MenuItem(label="Sair")
        quit_item.connect("activate", lambda *_: Gtk.main_quit())
        m.append(quit_item)

        m.show_all()
        return m

    def _open(self):
        subprocess.Popen(["xdg-open", URL])

    def _poll(self):
        syncing = False
        try:
            with urllib.request.urlopen(URL + "/sync-state", timeout=4) as r:
                syncing = bool(json.load(r).get("syncing"))
        except Exception:
            # Panel unreachable: treat as idle, keep polling.
            syncing = False

        if syncing != self._syncing:
            self._syncing = syncing
            if not syncing:
                self.ind.set_icon_full(APP_ICON, "RNV Sync — synced")
        return True  # keep polling

    def _animate(self):
        if self._syncing:
            self._frame = (self._frame + 1) % len(FRAMES)
            self.ind.set_icon_full(FRAMES[self._frame], "RNV Sync — syncing")
        return True  # keep the timer alive


def main():
    if _Ind is None:
        # No AppIndicator backend — the panel still works headless.
        raise SystemExit(
            "AppIndicator not available. Install "
            "gir1.2-ayatanaappindicator3-0.1 (see install/bootstrap.sh)."
        )
    Tray()
    Gtk.main()


if __name__ == "__main__":
    main()
