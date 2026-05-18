<?php

return [
    'usage' => 'Cache usage',
    'files' => 'files',
    'col_status' => 'Status',
    'status_online' => 'Online only',
    'status_cached' => 'Cached',
    'status_pinned' => 'Always offline',
    'pin' => 'Keep offline',
    'unpin' => 'Stop keeping offline',
    'free' => 'Free up space',
    'free_all' => 'Free up all cache',
    'free_all_confirm' => 'Remove all cached files except pinned ones?',
    'pinned' => 'File pinned and downloaded.',
    'pinning' => 'Downloading in the background — it will stay available offline.',
    'unpinned' => 'File unpinned.',
    'tip_online' => 'Online only — opens on demand, uses no local space.',
    'tip_cached' => 'Available on this device — a local copy exists and may be freed later.',
    'tip_pinned' => 'Always kept on this device — downloaded and protected from automatic cleanup.',
    'tip_pin_action' => 'Download now and always keep on this device.',
    'tip_unpin_action' => 'Stop keeping offline (becomes online only).',
    'tip_free_action' => 'Remove the local copy; the file stays available online.',
    'freed' => 'Space freed; file stays available online.',
    'freed_all' => 'All cache freed (pinned files kept).',
    'pin_too_large' => 'This file is larger than the cache limit. Increase the cache size in Settings first.',
    'fod_note' => 'Files appear here on demand. "Keep offline" downloads and protects a file; "Free up space" removes the local copy.',

    // Settings → Cache
    'section_cache' => 'Cache',
    'max_size_gb' => 'Maximum cache size (GB)',
    'max_size_hint' => 'Leave empty for automatic (10% of free disk, 1–20 GB).',
];
