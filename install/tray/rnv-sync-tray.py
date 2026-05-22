#!/usr/bin/env python3
"""
RNV Sync — system-tray indicator.

Sits next to the clock (like AnyDesk/OneDrive): shows the app icon
when everything is synced, an animated spinner while syncing, and a
menu listing what is currently being synced. Reads state from the
always-on local web panel (GET /sync-state), so it adds no load of
its own — just a 3s HTTP poll on localhost.

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

URL = os.environ.get("RNV_SYNC_URL", "http://127.0.0.1:8770")
APP_ICON = "rnv-sync"                      # idle / everything synced
FRAMES = [f"rnv-sync-sync-{i}" for i in range(8)]  # spinner animation
POLL_MS = 3000
ANIM_MS = 120
MAX_ROWS = 12                              # cap menu length


class Tray:
    def __init__(self):
        self.ind = _Ind.Indicator.new(
            "rnv-sync",
            APP_ICON,
            _Ind.IndicatorCategory.APPLICATION_STATUS,
        )
        self.ind.set_status(_Ind.IndicatorStatus.ACTIVE)
        self.ind.set_title("RNV Sync")

        self._syncing = False
        self._frame = 0
        self._sig = None  # last rendered menu signature (avoid flicker)
        self._rebuild_menu(False, [], 0)

        GLib.timeout_add(POLL_MS, self._poll)
        GLib.timeout_add(ANIM_MS, self._animate)
        self._poll()

    # ---- menu -----------------------------------------------------------
    def _rebuild_menu(self, syncing, items, count):
        m = Gtk.Menu()

        if syncing:
            header = Gtk.MenuItem(label=f"Sincronizando ({count})…")
            header.set_sensitive(False)
            m.append(header)
            for it in items[:MAX_ROWS]:
                icon = "🗂  " if it.get("kind") == "folder" else "📄  "
                row = Gtk.MenuItem(label=icon + it.get("name", "?"))
                row.set_sensitive(False)
                m.append(row)
            if count > MAX_ROWS:
                more = Gtk.MenuItem(label=f"… e mais {count - MAX_ROWS}")
                more.set_sensitive(False)
                m.append(more)
        else:
            done = Gtk.MenuItem(label="Tudo sincronizado")
            done.set_sensitive(False)
            m.append(done)

        m.append(Gtk.SeparatorMenuItem())

        open_item = Gtk.MenuItem(label="Abrir RNV Sync")
        open_item.connect("activate", lambda *_: self._open())
        m.append(open_item)

        quit_item = Gtk.MenuItem(label="Sair")
        quit_item.connect("activate", lambda *_: Gtk.main_quit())
        m.append(quit_item)

        m.show_all()
        self.ind.set_menu(m)

    def _open(self):
        subprocess.Popen(["xdg-open", URL])

    # ---- polling --------------------------------------------------------
    def _poll(self):
        syncing, items, count = False, [], 0
        try:
            with urllib.request.urlopen(URL + "/sync-state", timeout=4) as r:
                data = json.load(r)
            syncing = bool(data.get("syncing"))
            items = data.get("items", []) or []
            count = int(data.get("count", len(items)))
        except Exception:
            syncing, items, count = False, [], 0

        self._syncing = syncing
        if not syncing:
            self.ind.set_icon_full(APP_ICON, "RNV Sync — synced")

        # Rebuild the menu only when the visible state actually changed.
        sig = (syncing, count, tuple(i.get("name", "") for i in items[:MAX_ROWS]))
        if sig != self._sig:
            self._sig = sig
            self._rebuild_menu(syncing, items, count)

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
