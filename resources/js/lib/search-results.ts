export type SearchResult = {
    type: string;
    title: string;
    detail: string;
    href: string;
};

/** Result types with how many results each has, most common first. */
export function typeCounts(
    results: SearchResult[],
): { type: string; total: number }[] {
    const counts = new Map<string, number>();
    for (const result of results) {
        counts.set(result.type, (counts.get(result.type) ?? 0) + 1);
    }

    return [...counts.entries()]
        .map(([type, total]) => ({ type, total }))
        .sort((a, b) => b.total - a.total || a.type.localeCompare(b.type));
}

export function filterByType(
    results: SearchResult[],
    type: string,
): SearchResult[] {
    return type === '' ? results : results.filter((r) => r.type === type);
}

/** Splits text around the search term so the match can be marked without v-html. */
export function highlight(
    text: string,
    term: string,
): { text: string; match: boolean }[] {
    const needle = term.trim().toLowerCase();
    if (needle.length < 2 || !text) {
        return [{ text, match: false }];
    }
    const parts: { text: string; match: boolean }[] = [];
    const lower = text.toLowerCase();
    let from = 0;
    for (
        let at = lower.indexOf(needle);
        at !== -1;
        at = lower.indexOf(needle, from)
    ) {
        if (at > from) {
            parts.push({ text: text.slice(from, at), match: false });
        }
        parts.push({ text: text.slice(at, at + needle.length), match: true });
        from = at + needle.length;
    }
    if (from < text.length) {
        parts.push({ text: text.slice(from), match: false });
    }

    return parts;
}
