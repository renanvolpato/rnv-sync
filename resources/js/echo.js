import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Reverb (first-party Laravel WebSocket server) is optional: real-time
// progress is a progressive enhancement and the UI works without it
// (Livewire still drives every action). Initialise defensively so a
// missing key or an unreachable Reverb server can never break the page.
const key = import.meta.env.VITE_REVERB_APP_KEY;

if (key && key !== '${REVERB_APP_KEY}') {
    try {
        window.Echo = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 8771,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 8771,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
            enabledTransports: ['ws', 'wss'],
            // Don't reconnect forever against a server that isn't running
            // (e.g. when started with `php artisan serve`).
            activityTimeout: 15000,
            pongTimeout: 10000,
        });
        window.Echo.connector.pusher.connection.bind('error', () => {});
    } catch (e) {
        console.warn('[RNV Sync] Real-time disabled:', e?.message ?? e);
    }
}
