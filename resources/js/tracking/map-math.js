const EARTH_RADIUS_METRES = 6_371_000;
const DEGREES_TO_RADIANS = Math.PI / 180;

export function distanceInMetres(from, to) {
    const latitude1 = from.latitude * Math.PI / 180;
    const latitude2 = to.latitude * Math.PI / 180;
    const latitudeDelta = (to.latitude - from.latitude) * Math.PI / 180;
    const longitudeDelta = (to.longitude - from.longitude) * Math.PI / 180;
    const haversine = Math.sin(latitudeDelta / 2) ** 2
        + Math.cos(latitude1) * Math.cos(latitude2) * Math.sin(longitudeDelta / 2) ** 2;

    return EARTH_RADIUS_METRES * 2 * Math.atan2(Math.sqrt(haversine), Math.sqrt(1 - haversine));
}

export function shouldAnimateMarker(from, to, reducedMotion, maximumDistance = 5_000) {
    return Boolean(from)
        && !reducedMotion
        && distanceInMetres(from, to) > 0.5
        && distanceInMetres(from, to) <= maximumDistance;
}

export function interpolatePosition(from, to, progress) {
    const boundedProgress = Math.max(0, Math.min(1, progress));

    return {
        latitude: from.latitude + ((to.latitude - from.latitude) * boundedProgress),
        longitude: from.longitude + ((to.longitude - from.longitude) * boundedProgress),
    };
}

export function markerAnimationDuration(
    previousRecordedAt,
    nextRecordedAt,
    distance,
    maximumDuration = 4_800
) {
    const previousTime = Date.parse(previousRecordedAt);
    const nextTime = Date.parse(nextRecordedAt);
    const updateInterval = nextTime - previousTime;
    const cadenceDuration = Number.isFinite(updateInterval)
        && updateInterval > 0
        && updateInterval <= 30_000
        ? updateInterval * 0.9
        : Math.max(0, Number(distance)) * 4;

    return Math.round(Math.min(maximumDuration, Math.max(750, cadenceDuration)));
}

export function distanceToPathInMetres(point, path) {
    if (!Array.isArray(path) || path.length === 0) {
        return Infinity;
    }

    if (path.length === 1) {
        return distanceInMetres(point, path[0]);
    }

    let shortestDistance = Infinity;

    for (let index = 1; index < path.length; index += 1) {
        const start = offsetFrom(point, path[index - 1]);
        const end = offsetFrom(point, path[index]);
        const segmentX = end.x - start.x;
        const segmentY = end.y - start.y;
        const segmentLengthSquared = (segmentX ** 2) + (segmentY ** 2);
        const projection = segmentLengthSquared === 0
            ? 0
            : Math.max(0, Math.min(1, -((start.x * segmentX) + (start.y * segmentY)) / segmentLengthSquared));
        const nearestX = start.x + (segmentX * projection);
        const nearestY = start.y + (segmentY * projection);

        shortestDistance = Math.min(
            shortestDistance,
            Math.hypot(nearestX, nearestY)
        );
    }

    return shortestDistance;
}

export function shouldRefreshRemainingRoute({
    location,
    routeOrigin,
    routePath,
    lastRequestedAt,
    now = Date.now(),
    minimumInterval = 60_000,
    minimumMovement = 150,
    deviationThreshold = 100,
}) {
    return Boolean(location && routeOrigin)
        && Array.isArray(routePath)
        && routePath.length > 1
        && now - lastRequestedAt >= minimumInterval
        && distanceInMetres(routeOrigin, location) >= minimumMovement
        && distanceToPathInMetres(location, routePath) >= deviationThreshold;
}

function offsetFrom(origin, point) {
    const meanLatitude = ((origin.latitude + point.latitude) / 2) * DEGREES_TO_RADIANS;

    return {
        x: (point.longitude - origin.longitude)
            * DEGREES_TO_RADIANS
            * EARTH_RADIUS_METRES
            * Math.cos(meanLatitude),
        y: (point.latitude - origin.latitude)
            * DEGREES_TO_RADIANS
            * EARTH_RADIUS_METRES,
    };
}
