import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { authorizePrivateChannel } from './tracking/broadcast-auth';

let echoInstance;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function reverbConfigurationAvailable() {
    return typeof import.meta.env.VITE_REVERB_APP_KEY === 'string'
        && import.meta.env.VITE_REVERB_APP_KEY.length > 0;
}

export function getEcho() {
    if (echoInstance) {
        return echoInstance;
    }

    if (!reverbConfigurationAvailable()) {
        return null;
    }

    window.Pusher = Pusher;
    echoInstance = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authorizer: (channel) => ({
            authorize: (socketId, callback) => {
                authorizePrivateChannel(socketId, channel, csrfToken())
                    .then((response) => callback(null, response))
                    .catch((error) => callback(error, null));
            },
        }),
    });

    return echoInstance;
}

export function disconnectEcho() {
    if (!echoInstance) {
        return;
    }

    echoInstance.disconnect();
    echoInstance = undefined;
}
