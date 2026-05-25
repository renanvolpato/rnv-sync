<?php

return [
    'oauth_failed' => 'Microsoft sign-in could not be completed. Please try again.',
    'oauth_denied' => 'You declined the authorization request. RNV Sync needs access to manage your OneDrive files.',
    'oauth_state_mismatch' => 'The sign-in session expired or was invalid. Please start again.',
    'oauth_token_exchange' => 'Microsoft rejected the sign-in. Please try again.',
    'oauth_refresh_failed' => 'We could not refresh access to this account. Please reconnect it.',
    'rclone_unavailable_title' => 'rclone is not available',
    'rclone_unavailable_body' => 'The bundled rclone engine could not be reached. File listing is unavailable until it is installed.',
    'listing_failed_title' => 'Could not load this folder',
    'listing_failed_body' => 'A temporary error occurred while listing this folder (connection or session). Retrying automatically…',
    'disk_full_skip' => 'Download skipped: the disk is almost full. Free space (mark folders online) or raise the limit in settings.',
];
