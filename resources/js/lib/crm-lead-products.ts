export type LeadProductLine = {
    id: number;
    product_id: number;
    code: string;
    name: string;
    quantity: string | number;
    unit_price: string | number;
    currency: string;
};
export type LeadEstimate = {
    id: number;
    title: string;
    reference: string | null;
    stage?: { name: string } | null;
};
export type CatalogProduct = {
    id: number;
    name: string;
    active: boolean | number;
    settings: Record<string, unknown>;
};

/** Quantity × price in whole cents, shown with two decimals. */
export function lineTotal(
    line: Pick<LeadProductLine, 'quantity' | 'unit_price'>,
): string {
    const cents = Math.round(
        Number(line.quantity) * Number(line.unit_price) * 100,
    );

    return Number.isFinite(cents) ? (cents / 100).toFixed(2) : '0.00';
}

/** Price and currency suggested by the catalog when a product is picked. */
export function productDefaults(product: CatalogProduct): {
    unit_price: string;
    currency: string;
} {
    const price = product.settings.price;
    const currency = product.settings.currency;

    return {
        unit_price:
            typeof price === 'string' || typeof price === 'number'
                ? String(price)
                : '',
        currency: typeof currency === 'string' ? currency : 'AED',
    };
}
