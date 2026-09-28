export type Point = [number, number];

function round(value: number): number {
    return Math.round(value * 100) / 100;
}

export function chartPoints(
    values: number[],
    width: number,
    height: number,
    padding = 0,
): Point[] {
    const clean = values.filter((value) => Number.isFinite(value));
    if (clean.length === 0) {
        return [];
    }
    const min = Math.min(...clean);
    const range = Math.max(...clean) - min;
    const inner = height - padding * 2;
    const step = clean.length > 1 ? width / (clean.length - 1) : 0;

    return clean.map((value, index) => [
        round(index * step),
        round(
            range === 0
                ? height / 2
                : padding + (1 - (value - min) / range) * inner,
        ),
    ]);
}

export function linePath(
    values: number[],
    width: number,
    height: number,
    padding = 0,
): string {
    const points = chartPoints(values, width, height, padding);
    if (points.length === 0) {
        return '';
    }
    if (points.length === 1) {
        return `M0,${points[0][1]} L${width},${points[0][1]}`;
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

export function areaPath(
    values: number[],
    width: number,
    height: number,
    padding = 0,
): string {
    const line = linePath(values, width, height, padding);

    return line === '' ? '' : `${line} L${width},${height} L0,${height} Z`;
}
