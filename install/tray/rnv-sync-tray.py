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
POLL_MS = 2000                             # snappy enough to feel live
ANIM_MS = 120
MAX_ROWS = 12                              # cap menu length


def _human_speed(b):
    """Bytes/s → human string ('' when zero/unknown)."""
    b = float(b or 0)
    if b <= 0:
        return ""
    for unit in ("B/s", "KB/s", "MB/s", "GB/s"):
        if b < 1024 or unit == "GB/s":
            return f"{b:.0f} {unit}" if unit == "B/s" else f"{b:.1f} {unit}"
        b /= 1024


def _row_label(it):
    """One menu row: ⬆/⬇ file — 42%  ·  🗂 folder  ·  📄 file."""
    name = it.get("name", "?")
    if it.get("kind") == "folder":
        return "🗂  " + name
    d = it.get("dir")
    icon = "⬆  " if d == "up" else ("⬇  " if d == "down" else "📄  ")
    pct = it.get("pct")
    return f"{icon}{name}  —  {pct}%" if pct is not None else icon + name


def _header_label(syncing, items, count, transfer):
    """Top (disabled) row summarising the queue, OneDrive-style."""
    if transfer and transfer.get("total"):
        dirs = {it.get("dir") for it in items
                if it.get("kind") == "file" and it.get("dir")}
        verb = ("Enviando" if dirs == {"up"}
                else "Baixando" if dirs == {"down"}
                else "Sincronizando")
        spd = _human_speed(transfer.get("speed", 0))
        base = f"{verb} {transfer.get('done', 0)}/{transfer['total']}"
        return base + (f"  ·  {spd}" if spd else "")
    return f"Sincronizando ({count})…"


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
        self._paused = False
        self._frame = 0
        self._idle_shown = False  # is the static cloud icon currently shown?
        self._sig = None  # last rendered menu signature (avoid flicker)
        self._rebuild_menu(False, [], 0, None, False)

        GLib.timeout_add(POLL_MS, self._poll)
        GLib.timeout_add(ANIM_MS, self._animate)
        self._poll()

    # ---- menu -----------------------------------------------------------
    def _rebuild_menu(self, syncing, items, count, transfer, paused):
        m = Gtk.Menu()

        if paused:
            # Even when paused, a one-shot user action (Keep online / Keep local)
            # still runs. List those so the user can see what's still finishing
            # instead of an empty menu that hides the progress.
            label = "Sincronização pausada"
            if count > 0:
                label = f"Pausado — concluindo ({count})…"
            header = Gtk.MenuItem(label=label)
            header.set_sensitive(False)
            m.append(header)
            for it in items[:MAX_ROWS]:
                row = Gtk.MenuItem(label=_row_label(it))
                row.set_sensitive(False)
                m.append(row)
            if count > MAX_ROWS:
                more = Gtk.MenuItem(label=f"… e mais {count - MAX_ROWS}")
                more.set_sensitive(False)
                m.append(more)
        elif syncing:
            header = Gtk.MenuItem(label=_header_label(syncing, items, count, transfer))
            header.set_sensitive(False)
            m.append(header)
            for it in items[:MAX_ROWS]:
                row = Gtk.MenuItem(label=_row_label(it))
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

        # The real on/off — flips global pause via the local panel.
        pause_item = Gtk.MenuItem(
            label="Retomar sincronização" if paused else "Pausar sincronização"
        )
        pause_item.connect("activate", lambda *_: self._toggle_pause())
        m.append(pause_item)

        open_item = Gtk.MenuItem(label="Abrir RNV Sync")
        open_item.connect("activate", lambda *_: self._open())
        m.append(open_item)

        # NOTE: this only hides the tray icon; syncing keeps running in the
        # background. Use "Pausar sincronização" above to actually stop it.
        quit_item = Gtk.MenuItem(label="Ocultar ícone")
        quit_item.connect("activate", lambda *_: Gtk.main_quit())
        m.append(quit_item)

        m.show_all()
        self.ind.set_menu(m)

    def _toggle_pause(self):
        try:
            req = urllib.request.Request(URL + "/sync-state/pause", method="POST", data=b"")
            urllib.request.urlopen(req, timeout=4)
        except Exception:
            pass
        self._poll()  # reflect the new state immediately

    def _open(self):
        subprocess.Popen(["xdg-open", URL])

    # ---- polling --------------------------------------------------------
    def _poll(self):
        syncing, items, count, transfer, paused = False, [], 0, None, False
        try:
            with urllib.request.urlopen(URL + "/sync-state", timeout=4) as r:
                data = json.load(r)
            syncing = bool(data.get("syncing"))
            paused = bool(data.get("paused"))
            items = data.get("items", []) or []
            count = int(data.get("count", len(items)))
            transfer = data.get("transfer")
        except Exception:
            syncing, items, count, transfer, paused = False, [], 0, None, False

        self._syncing = syncing
        self._paused = paused
        # The icon is owned by _animate so the spinner→cloud switch is one
        # clean change: setting the static icon here every poll raced the
        # 120ms animation and some panels left it stuck on a spinner frame.

        # Rebuild only when something visible changed — but include the
        # live progress (pct/dir) and done/total so the menu animates as
        # files climb to 100% and the next one starts.
        sig = (
            syncing, paused, count,
            (transfer or {}).get("done"), (transfer or {}).get("total"),
            tuple((i.get("name", ""), i.get("pct"), i.get("dir"))
                  for i in items[:MAX_ROWS]),
        )
        if sig != self._sig:
            self._sig = sig
            self._rebuild_menu(syncing, items, count, transfer, paused)

        return True  # keep polling

    def _animate(self):
        if self._syncing:
            self._frame = (self._frame + 1) % len(FRAMES)
            self.ind.set_icon_full(FRAMES[self._frame], "RNV Sync — syncing")
            self._idle_shown = False
        elif not self._idle_shown:
            # Just settled (or first idle): switch back to the cloud icon as a
            # single clean change away from the last spinner frame.
            self._frame = 0
            self._idle_shown = True
            self.ind.set_icon_full(APP_ICON, "RNV Sync — synced")
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
