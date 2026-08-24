import { getApps, initializeApp } from 'firebase/app';
import {
    inMemoryPersistence,
    initializeAuth,
    signInWithCustomToken,
    signOut,
} from 'firebase/auth';
import { getDatabase, onValue, ref } from 'firebase/database';

let inMemoryAuth;

function clientConfiguration() {
    const config = {
        apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
        authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
        databaseURL: import.meta.env.VITE_FIREBASE_DATABASE_URL,
        projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
        appId: import.meta.env.VITE_FIREBASE_APP_ID,
    };

    return Object.values(config).every((value) => typeof value === 'string' && value.length > 0)
        ? config
        : null;
}

function normalizeLivePoint(value) {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const receivedAt = Number(value.received_at_ms);

    if (!Number.isFinite(receivedAt)) {
        return null;
    }

    return {
        latitude: value.latitude,
        longitude: value.longitude,
        accuracy: value.accuracy ?? null,
        speed: value.speed ?? null,
        heading: value.heading ?? null,
        battery_level: value.battery_level ?? null,
        recorded_at: value.recorded_at,
        updated_at: new Date(receivedAt).toISOString(),
    };
}

export class CustomerFirebaseTracking {
    constructor({ csrfToken, onLocation, onTerminal, onUnavailable, onConnected }) {
        this.csrfToken = csrfToken;
        this.onLocation = onLocation;
        this.onTerminal = onTerminal;
        this.onUnavailable = onUnavailable;
        this.onConnected = onConnected;
        this.auth = null;
        this.unsubscribers = [];
        this.databasePath = null;
    }

    async connect(credentialsUrl) {
        await this.disconnect();
        const config = clientConfiguration();

        if (!config) {
            throw new Error('Firebase client configuration is unavailable.');
        }

        const response = await fetch(credentialsUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': this.csrfToken(),
            },
        });

        if (!response.ok) {
            throw new Error('Firebase tracking authorization failed.');
        }

        const payload = await response.json();
        const credential = payload?.data;

        if (typeof credential?.token !== 'string'
            || typeof credential?.database_path !== 'string'
            || !/^delivery_tracking\/[a-f0-9]{64}$/.test(credential.database_path)
        ) {
            throw new Error('Firebase tracking authorization response is invalid.');
        }

        const app = getApps()[0] ?? initializeApp(config);
        inMemoryAuth ??= initializeAuth(app, { persistence: inMemoryPersistence });
        this.auth = inMemoryAuth;
        await signInWithCustomToken(this.auth, credential.token);
        const database = getDatabase(app);
        this.databasePath = credential.database_path;

        this.unsubscribers = [
            onValue(ref(database, `${this.databasePath}/live`), (snapshot) => {
                const location = normalizeLivePoint(snapshot.val());

                if (location) {
                    this.onLocation(location);
                } else {
                    this.onUnavailable(false);
                }
            }, () => this.onUnavailable(true)),
            onValue(ref(database, `${this.databasePath}/public_status`), (snapshot) => {
                const status = snapshot.val();

                if (status && ['delivered', 'failed', 'cancelled'].includes(status.status)) {
                    this.onTerminal(status);
                }
            }, () => this.onUnavailable(true)),
        ];
        this.onConnected();
    }

    async disconnect() {
        for (const unsubscribe of this.unsubscribers) {
            unsubscribe();
        }

        this.unsubscribers = [];
        this.databasePath = null;

        if (this.auth?.currentUser) {
            await signOut(this.auth).catch(() => {});
        }

        this.auth = null;
    }
}

export function firebaseClientConfigurationAvailable() {
    return clientConfiguration() !== null;
}
