export function buildBroadcastAuthorizationRequest(socketId, channelName, csrfToken) {
    if (!csrfToken || typeof csrfToken !== 'string') {
        throw new Error('A CSRF token is required for private channel authorization.');
    }

    return {
        url: '/broadcasting/auth',
        options: {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({
                socket_id: socketId,
                channel_name: channelName,
            }),
        },
    };
}

export async function authorizePrivateChannel(socketId, channel, csrfToken) {
    const request = buildBroadcastAuthorizationRequest(socketId, channel.name, csrfToken);
    const response = await fetch(request.url, request.options);

    if (!response.ok) {
        const error = new Error('Private channel authorization was denied.');
        error.status = response.status;

        throw error;
    }

    const payload = await response.json();

    if (!payload || typeof payload.auth !== 'string' || payload.auth.length === 0) {
        throw new Error('Private channel authorization returned an invalid response.');
    }

    return payload;
}
