# FAQ

**Is RNV Sync a new OneDrive client?**
No. Sync, mount and conflict handling are delegated to
[rclone](https://rclone.org/). RNV Sync is the UX and lifecycle layer.

**Does my data leave my machine?**
No. OAuth tokens are stored encrypted locally. No telemetry, no
analytics, no phone-home.

**Which accounts are supported?**
OneDrive Personal, OneDrive Business and SharePoint document libraries.
Other providers (Google Drive, Dropbox) are out of scope for 1.0 but the
architecture allows them later.

**Can I access the panel from another device on my LAN?**
Yes, but it is off by default. Change the port binding to `0.0.0.0` and
use a strong password (and ideally an HTTPS reverse proxy). See
[security.md](security.md).

**Where do my files appear?**
Under the mount base (default `~/RnvSync/<account name>/`). Files are
Files-on-Demand: they appear instantly and download on access.

**How do I keep a file available offline?**
Browse the account, use **Keep offline** to pin it. Pinned files are
protected from cache eviction.

**Do I need my own Azure app registration?**
No — the rclone public client id is used by default. You can supply your
own via `ONEDRIVE_CLIENT_ID`.

**Is it multi-user?**
No. v1.0 is single-user by design.

**What license is it?**
MIT. rclone (also MIT) is bundled unmodified — see `LICENSES/rclone.txt`.
