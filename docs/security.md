# Security

## What is encrypted

OAuth tokens (access + refresh) are stored encrypted at rest using
Laravel's `Crypt` (AES-256 with `APP_KEY`). They are decrypted only to
generate the rclone config or refresh the token, and are **never**
logged — a Monolog processor redacts tokens, secrets and Bearer headers
from every log channel.

## Where data goes

Nowhere except Microsoft (for the actual sync, via rclone). No
telemetry, analytics or phone-home. All app state is local SQLite.

## Network exposure

- Default bind: `127.0.0.1:8770` only.
- LAN exposure requires explicitly changing the port binding and is
  discouraged without a strong password.
- Internet exposure is strongly discouraged; if you must, put it behind
  an HTTPS reverse proxy with a strong password.

## Authentication

- Single panel user, bcrypt password (min 12 chars).
- Login throttled to 5 attempts per 5 minutes per IP.
- Session-based; CSRF protection on all state-changing routes.
- Password reset via CLI: `php artisan rnvsync:reset-password`.

## File permissions

- SQLite DB: `0600`
- rclone config: `0600`
- Log files: daily-rotated, 14-day retention

## Microsoft Graph scopes

Only `Files.ReadWrite.All`, `offline_access`, `User.Read`. No broad
SharePoint admin scopes.

## Reporting a vulnerability

Please open a private security advisory on the GitHub repository rather
than a public issue.
