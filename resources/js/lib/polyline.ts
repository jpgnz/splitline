/**
 * Decode a Google Encoded Polyline string (1e5 precision) into [lat, lng] pairs.
 */
export function decodePolyline(encoded: string): [number, number][] {
    const points: [number, number][] = [];
    let index = 0;
    let lat = 0;
    let lng = 0;
    const length = encoded.length;

    while (index < length) {
        let result = 0;
        let shift = 0;
        let byte: number;

        do {
            byte = encoded.charCodeAt(index++) - 63;
            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20 && index < length);

        lat += (result & 1) !== 0 ? ~(result >> 1) : result >> 1;

        result = 0;
        shift = 0;

        do {
            byte = encoded.charCodeAt(index++) - 63;
            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20 && index < length);

        lng += (result & 1) !== 0 ? ~(result >> 1) : result >> 1;

        points.push([lat / 1e5, lng / 1e5]);
    }

    return points;
}
