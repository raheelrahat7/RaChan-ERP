export type SpendSource = 'estimate' | 'bill_linked';

export function clearBillIdIfEstimate(
    source: SpendSource,
    currentBillId: string,
): string {
    return source === 'estimate' ? '' : currentBillId;
}
