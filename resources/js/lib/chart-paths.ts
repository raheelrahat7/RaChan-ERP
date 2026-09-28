export type Point = [number, number];

export type ChartValue = number | string | null | undefined;

function round(value: number): number {
    return Math.round(value * 100) / 100;
}

function toFinite(value: ChartValue): number | null {
    if (value === null || value === undefined) {
        return null;
    }
    if (typeof value === 'string' && value.trim() === '') {
        return null;
    }
    const number = Number(value);

    return Number.isFinite(number) ? number : null;
}

/**
 * Points grouped into runs of consecutive values. A missing value ends a run,
 * so later points keep their x position instead of shifting left.
 */
export function chartSegments(
    values: ChartValue[],
    width: number,
    height: number,
    padding = 0,
): Point[][] {
    const numbers = values.map(toFinite);
    const present = numbers.filter((value): value is number => value !== null);
    if (present.length === 0) {
        return [];
    }
    const min = Math.min(...present);
    const range = Math.max(...present) - min;
    const inner = height - padding * 2;
    const step = values.length > 1 ? width / (values.length - 1) : 0;
    const segments: Point[][] = [];
    let current: Point[] = [];
    numbers.forEach((value, index) => {
        if (value === null) {
            if (current.length) {
                segments.push(current);
            }
            current = [];

            return;
        }
        current.push([
            round(index * step),
            round(
                range === 0
                    ? height / 2
                    : padding + (1 - (value - min) / range) * inner,
            ),
        ]);
    });
    if (current.length) {
        segments.push(current);
    }

    return segments;
}

export function chartPoints(
    values: ChartValue[],
    width: number,
    height: number,
    padding = 0,
): Point[] {
    return chartSegments(values, width, height, padding).flat();
}

function segmentPath(points: Point[]): string {
    if (points.length === 1) {
        const [x, y] = points[0];

        return `M${x},${y} L${x},${y}`;
    }

    return points
        .map(([x, y], index) => {
            if (index === 0) {
                return `M${x},${y}`;
            }
            const [previousX, previousY] = points[index - 1];
            const middle = round((previousX + x) / 2);

            return `C${middle},${previousY} ${middle},${y} ${x},${y}`;
        })
        .join(' ');
}

export function linePath(
    values: ChartValue[],
    width: number,
    height: number,
    padding = 0,
): string {
    const segments = chartSegments(values, width, height, padding);
    if (segments.length === 0) {
        return '';
    }
    if (values.length === 1) {
        const y = segments[0][0][1];

        return `M0,${y} L${width},${y}`;
    }

    return segments.map(segmentPath).join(' ');
}

export function areaPath(
    values: ChartValue[],
    width: number,
    height: number,
    padding = 0,
): string {
    const segments = chartSegments(values, width, height, padding);
    if (segments.length === 0) {
        return '';
    }
    if (values.length === 1) {
        return `${linePath(values, width, height, padding)} L${width},${height} L0,${height} Z`;
    }

    return segments
        .map((points) => {
            const first = points[0][0];
            const last = points[points.length - 1][0];

            return `${segmentPath(points)} L${last},${height} L${first},${height} Z`;
        })
        .join(' ');
}
