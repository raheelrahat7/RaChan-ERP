import type { RelatedType } from '@/lib/sales-crm-tools';

export type SearchableType = RelatedType | 'listing';

export type RecordSearchResult = {
    id: number;
    label: string;
    sublabel: string | null;
};

export type RecordSearchOutcome =
    | { status: 'ok'; results: RecordSearchResult[] }
    | { status: 'unavailable' };

export async function searchRecords(
    type: SearchableType,
    query: string,
    fetchImpl: typeof fetch = fetch,
): Promise<RecordSearchOutcome> {
    const term = query.trim();
    if (term.length < 2) {
        return { status: 'ok', results: [] };
    }
    try {
        const url = `/api/records/search?type=${encodeURIComponent(type)}&q=${encodeURIComponent(term)}`;
        const response = await fetchImpl(url, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            return { status: 'unavailable' };
        }
        const body: unknown = await response.json();
        if (!Array.isArray(body)) {
            return { status: 'unavailable' };
        }

        return { status: 'ok', results: body as RecordSearchResult[] };
    } catch {
        return { status: 'unavailable' };
    }
}
