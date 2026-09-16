const instrumentedConstructors = new WeakMap();
const reportedMaps = new WeakSet();

export function instrumentMapConstructor(MapConstructor) {
    if (instrumentedConstructors.has(MapConstructor)) {
        return instrumentedConstructors.get(MapConstructor);
    }

    const InstrumentedMap = new Proxy(MapConstructor, {
        construct(target, argumentsList, newTarget) {
            const map = Reflect.construct(
                target,
                argumentsList,
                newTarget === InstrumentedMap ? target : newTarget
            );

            // Map creation is observed once. Panning, marker movement and GPS events are not counted.
            void reportMapOpen(map, argumentsList[0]);

            return map;
        },
    });

    instrumentedConstructors.set(MapConstructor, InstrumentedMap);

    return InstrumentedMap;
}

export async function reportMapOpen(map, element, {
    documentObject = globalThis.document,
    locationObject = globalThis.location,
    cryptoObject = globalThis.crypto,
    fetchRequest = globalThis.fetch,
} = {}) {
    try {
        const endpoint = element?.dataset?.mapUsageUrl;
        const csrfToken = documentObject?.querySelector('meta[name="csrf-token"]')?.content;

        if (!endpoint || !csrfToken || !locationObject?.origin
            || !cryptoObject?.randomUUID || !fetchRequest || reportedMaps.has(map)) {
            return false;
        }

        const url = new URL(endpoint, locationObject.origin);

        const reportingPath = /^(?:\/tracking\/map-usage|\/delivery-request\/map-usage|\/portal\/map-usage\/(?:shop_location|business_onboarding))$/;

        if (url.origin !== locationObject.origin || !reportingPath.test(url.pathname)) {
            return false;
        }

        reportedMaps.add(map);

        const response = await fetchRequest(`${url.pathname}${url.search}`, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            keepalive: true,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({ event_id: cryptoObject.randomUUID() }),
        });

        return response.ok;
    } catch {
        // A measurement outage must never prevent the actual map from working.
        return false;
    }
}
