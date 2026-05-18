# Microsoft OAuth setup

RNV Sync signs in to OneDrive **inside the app** (SPEC §8, Path B). For
that, Microsoft must redirect back to `${APP_URL}/oauth/callback`, and
that exact URI has to be registered on the Microsoft application used.

The default `ONEDRIVE_CLIENT_ID` is rclone's public application. Its
registration only allows rclone's own loopback redirect, **not** RNV
Sync's `/oauth/callback`. So for the in-app flow you must register your
own (free) Microsoft Entra application.

> Symptom if you skip this:
> `invalid_request: The provided value for the input parameter
> 'redirect_uri' is not valid.`

## Register an app (≈3 min)

1. <https://entra.microsoft.com> → **App registrations** → **New
   registration**.
2. Name: `RNV Sync`.
3. Supported account types: *Accounts in any organizational directory
   and personal Microsoft accounts* (covers Personal + Business +
   SharePoint).
4. Redirect URI → platform **Web** → exactly:
   `http://localhost:8080/oauth/callback`
   (must equal `${APP_URL}/oauth/callback` — same scheme/host/port/path).
5. **Register**, copy the **Application (client) ID**.
6. **Certificates & secrets** → **New client secret** → copy the
   **Value**.
7. **API permissions** → **Microsoft Graph** → **Delegated**:
   `Files.ReadWrite.All`, `offline_access`, `User.Read`. Grant admin
   consent if it's a work/school tenant.

## Configure

```dotenv
APP_URL=http://localhost:8080
ONEDRIVE_CLIENT_ID=<application (client) id>
ONEDRIVE_CLIENT_SECRET=<secret value>
```

```bash
php artisan config:clear
php artisan serve --port=8080
```

Always open the panel at the URL in `APP_URL`. If you change the port,
update `APP_URL` **and** add the matching redirect URI to the app
registration — Microsoft requires an exact match.

## Multiple redirect URIs

You can register several redirect URIs on the same app (e.g.
`http://localhost:8080/oauth/callback` for native and
`http://localhost:8080/oauth/callback` behind Docker). Add each one
under the app's **Authentication** blade.
