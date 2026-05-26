"""
RNV Sync — file-manager extension (Nautilus / Nemo / Caja).

Shows OneDrive-style emblems inside RNV Sync account folders:
  • green check (emblem-rnvsync-synced)   → downloaded (real file on disk)
  • blue cloud  (emblem-rnvsync-cloud)    → cloud only (0-byte placeholder)
  • sync arrows (emblem-rnvsync-syncing)  → operation in flight

…and a right-click menu: "Manter Local" (cloud → disk) / "Manter Online"
(disk → cloud placeholder), bridged to the app via `php artisan rnvsync:fs`.

ONE file serves all three GTK file managers — they share the same
nautilus-python API (InfoProvider / MenuProvider / add_emblem). We load the
binding that matches the HOST process (Nautilus, Nemo or Caja), so the same
extension works on GNOME, Cinnamon/Mint and MATE. (COSMIC Files and KDE's
Dolphin don't support this API — see install.sh, which detects and explains.)

Install: see install/nautilus/install.sh.
"""

import importlib
import json
import os
import subprocess

import gi

# Pick the file-manager binding. Each FM only loads extensions from its own
# directory and, inside its process, exposes its own gi namespace — so we must
# bind to the HOST one. Detect it from the running executable, then try that
# namespace first and fall back to the others (covers odd setups).
_HOSTS = [
    ("Nautilus", ("4.0", "3.0")),
    ("Nemo", ("3.0",)),
    ("Caja", ("2.0",)),
]


def _ordered_hosts():
    try:
        exe = os.path.basename(os.readlink("/proc/self/exe")).lower()
    except OSError:
        exe = ""
    prefer = {"nautilus": "Nautilus", "nemo": "Nemo", "caja": "Caja"}.get(exe)
    if prefer:
        return sorted(_HOSTS, key=lambda h: 0 if h[0] == prefer else 1)
    return _HOSTS


FM = None
for _ns, _vers in _ordered_hosts():
    for _v in _vers:
        try:
            gi.require_version(_ns, _v)
            FM = importlib.import_module("gi.repository." + _ns)
            break
        except (ValueError, ImportError):
            continue
    if FM is not None:
        break

if FM is None:  # no supported binding present — nothing we can do
    raise ImportError("RNV Sync: no Nautilus/Nemo/Caja python binding found")

from gi.repository import GObject, GLib  # noqa: E402

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


def _write_loaded_marker():
    """Tell the app whether the file manager actually loaded the extension —
    surfaced in Settings → "Integração com o desktop" (Extensão carregada há
    Xmin / NÃO carregada). Best-effort; never breaks the extension."""
    try:
        import time
        d = os.path.expanduser("~/.cache/rnv-sync")
        os.makedirs(d, exist_ok=True)
        with open(os.path.join(d, "extension-loaded.json"), "w") as fh:
            fh.write('{"loaded_at": %d, "fm": "%s"}' % (
                int(time.time()), FM.__name__.split(".")[-1]))
    except Exception:
        pass


class RnvSyncExtension(GObject.GObject, FM.InfoProvider, FM.MenuProvider):

    def __init__(self):
        super().__init__()
        _write_loaded_marker()
        # Live refresh: the file manager only re-reads emblems when asked, so
        # poll the (small) set of on-screen items every 2s and force a
        # re-read of any whose state changed — emblems update on their
        # own within ~2s instead of needing a manual refresh.
        self._seen = {}  # path -> [FileInfo, last_state]
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
        # Force a re-read of emblems so the state updates without a manual
        # refresh. It reflects "syncing" immediately and the final state on
        # the next poll.
        for f in files:
            try:
                f.invalidate_extension_info()
            except Exception:
                pass

    def get_file_items(self, *args):
        # Nautilus 4 passes (files); Nautilus 3 / Nemo / Caja pass (window, files).
        files = args[-1]
        cfg = _load()
        bases = cfg.get("bases", [])
        targets = [p for f in files if (p := _path_of(f)) and _in_base(p, bases)]
        if not targets:
            return []

        any_cloud = any(not _is_downloaded(p) for p in targets)
        menu = FM.MenuItem(
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
