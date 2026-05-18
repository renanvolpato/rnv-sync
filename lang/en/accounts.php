<?php

return [
    'add_account' => 'Add account',
    'add_subtitle' => 'Connect a OneDrive account to RNV Sync.',
    'login_with_microsoft' => 'Sign in with Microsoft',
    'oauth_redirect_note' => 'You will be redirected to Microsoft to authorize access. RNV Sync never sees your Microsoft password.',
    'accounts' => 'Accounts',
    'provider_personal_desc' => 'A personal Microsoft account (outlook.com, hotmail.com, live.com).',
    'provider_business_desc' => 'A work or school Microsoft 365 account.',
    'provider_sharepoint_desc' => 'A SharePoint document library.',
    'sharepoint_url' => 'SharePoint document library URL',
    'sharepoint_url_hint' => 'e.g. https://contoso.sharepoint.com/sites/Team',
    'coming_later' => 'Coming in a later release',
    'added_success' => 'Account ":name" connected successfully.',

    'status_active' => 'Active',
    'status_disconnected' => 'Disconnected',
    'status_error' => 'Error',

    'browse_files' => 'Browse files',
    'empty_folder' => 'This folder is empty.',
    'col_name' => 'Name',
    'col_size' => 'Size',
    'read_only_note' => 'File listing is read-only in this release. Syncing arrives in v0.2.0.',

    // Easy (zero-config) OAuth
    'easy_hint' => 'Connect your Microsoft account in one click. No app registration, no client IDs — just sign in.',
    'no_registration_note' => 'Uses the bundled rclone OAuth client. Nothing to configure.',
    'connecting' => 'Connecting to Microsoft',
    'connecting_hint' => 'A Microsoft sign-in window should open. Log in and authorize access.',
    'open_microsoft' => 'Open Microsoft sign-in',
    'popup_blocked' => 'Window didn’t open?',
    'open_link' => 'Open it here',
    'waiting_auth' => 'Waiting for you to finish signing in…',

    // Advanced (own Entra app)
    'advanced_toggle' => 'Advanced (use my own Microsoft app)',
    'advanced_hint' => 'For power users or OneDrive Business/SharePoint: register your own Microsoft Entra app and set ONEDRIVE_CLIENT_ID/SECRET. See docs/oauth.md.',
    'advanced_oauth_note' => 'You will be redirected to Microsoft. The redirect URI must match your app registration.',
];
