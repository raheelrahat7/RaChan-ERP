export type DealStatus = 'enquiry' | 'reserved' | 'contracted' | 'cancelled';

export function allowedDealTransitions(status: DealStatus): DealStatus[] {
    switch (status) {
        case 'enquiry':
            return ['reserved', 'cancelled'];
        case 'reserved':
            return ['contracted', 'cancelled'];
        default:
            return [];
    }
}

export function milestonePercentageValid(
    existingTotal: number,
    newPercentage: number,
): boolean {
    return existingTotal + newPercentage <= 100.001;
}

export type UnitStatus = 'available' | 'reserved' | 'sold';

export function dealTransitionsForUnit(
    status: DealStatus,
    unitStatus: UnitStatus,
): DealStatus[] {
    const transitions = allowedDealTransitions(status);

    return unitStatus === 'available'
        ? transitions
        : transitions.filter((next) => next !== 'reserved');
}
