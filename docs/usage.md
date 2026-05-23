# Usage

## Add a OneDrive account

1. Dashboard → **Add account**.
2. Pick a provider (Personal, Business, or SharePoint).
3. **Sign in with Microsoft** → authorize. You return to the dashboard
   with the account connected. Tokens are stored encrypted locally.

## Your folders (online by default)

There is **no manual folder-selection step**. The moment an account is
connected, **all of its folders are mirrored online** — they appear as
cloud placeholders (☁) both in the **file manager** and in the app's
**Files** view, without downloading anything. New folders created later
on the OneDrive website show up automatically too.

Open the account → **Open files** to browse them, then keep what you
want on this device (see *Files-on-Demand* below).

## Sync now / pause

- **Sync now** on a folder forces an immediate sync.
- **Pause sync** stops scheduled and queued syncs globally; resume any
  time. An account with more than 10 unresolved conflicts is paused
  automatically until you resolve them.

## Files-on-Demand

Browse an account's files. Each file shows a status:

- ☁ **Online only** — not downloaded yet
- ✓ **Cached** — a local copy exists
- 🔖 **Always offline** — pinned, protected from eviction

Use **Keep offline** to pin (downloads immediately), **Free up space**
to evict a cached copy, or **Free up all cache** to reclaim space.
Pinned files are never auto-evicted.

## Conflicts

If a file changed both locally and remotely, it appears under
**Conflicts**. Resolve per file (keep local / remote / both / ignore)
or use the bulk actions per account.

## Search & trends

- **Search** finds files by name across all connected accounts.
- **Trends** charts cloud and local-cache usage over time.

## Forgot the panel password?

Run on the host (Docker: `docker compose exec rnv-sync ...`):

```bash
php artisan rnvsync:reset-password
```
