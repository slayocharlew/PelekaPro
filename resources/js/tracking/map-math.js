const EARTH_RADIUS_METRES = 6_371_000;

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
