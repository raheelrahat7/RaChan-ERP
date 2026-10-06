import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    buildPayload,
    catalogCode,
    catalogSummary,
    findCatalogSection,
    formFor,
} from '../../resources/js/lib/crm-catalog.ts';

await test('codes are lowercase identifiers starting with a letter', () => {
    assert.equal(catalogCode('VAT 5%'), 'vat-5');
    assert.equal(catalogCode('5 percent'), '');
});

await test('tax payload sends numbers and a generated code', () => {
    const section = findCatalogSection('taxes');
    const form = formFor(section);
    form.name = 'VAT Standard';
    form.settings.rate = '5';
    assert.deepEqual(buildPayload(section, form), {
        code: 'vat-standard',
        name: 'VAT Standard',
        active: true,
        position: 100,
        settings: { rate: 5, description: null },
    });
});

await test('editing keeps the original code', () => {
    const section = findCatalogSection('units');
    const record = {
        id: 1,
        kind: 'units',
        code: 'sqm',
        name: 'Square metre',
        active: 1,
        position: 1,
        settings: { symbol: 'm²', precision: 2 },
    };
    const form = formFor(section, record);
    assert.equal(form.settings.precision, '2');
    assert.equal(buildPayload(section, form, record.code).code, 'sqm');
});

await test('templates turn lines into a field list', () => {
    const section = findCatalogSection('detail-templates');
    const form = formFor(section);
    form.name = 'Buyer';
    form.settings.fields = 'name\n\n email ';
    assert.deepEqual(buildPayload(section, form).settings, {
        entity: 'contact',
        fields: ['name', 'email'],
    });
});

await test('products default to AED and blank links become null', () => {
    const section = findCatalogSection('products');
    const form = formFor(section);
    form.name = 'Survey';
    form.settings.price = '250.5';
    const { settings } = buildPayload(section, form);
    assert.deepEqual(settings, {
        sku: null,
        price: 250.5,
        currency: 'AED',
        unit_id: null,
        tax_id: null,
    });
});

await test('summaries are short readable strings', () => {
    assert.equal(
        catalogSummary(findCatalogSection('taxes'), {
            settings: { rate: '5.00' },
        }),
        '5.00%',
    );
    assert.equal(
        catalogSummary(findCatalogSection('products'), {
            settings: { price: '10', currency: 'AED' },
        }),
        'AED 10',
    );
});

import {
    lineTotal,
    productDefaults,
} from '../../resources/js/lib/crm-lead-products.ts';

await test('lead product totals round to cents', () => {
    assert.equal(lineTotal({ quantity: '3', unit_price: '10.10' }), '30.30');
    assert.equal(lineTotal({ quantity: 'x', unit_price: '1' }), '0.00');
});

await test('picking a product suggests its price and currency', () => {
    assert.deepEqual(
        productDefaults({
            id: 1,
            name: 'A',
            active: 1,
            settings: { price: '99.50', currency: 'USD' },
        }),
        { unit_price: '99.50', currency: 'USD' },
    );
    assert.deepEqual(
        productDefaults({ id: 1, name: 'A', active: 1, settings: {} }),
        { unit_price: '', currency: 'AED' },
    );
});

await test('payload accepts numbers from number inputs', () => {
    const section = findCatalogSection('taxes');
    const form = formFor(section);
    form.name = 'VAT';
    form.settings.rate = 5;
    assert.equal(buildPayload(section, form).settings.rate, 5);
    const products = findCatalogSection('products');
    const productForm = formFor(products);
    productForm.name = 'Survey';
    productForm.settings.price = 250;
    assert.equal(buildPayload(products, productForm).settings.price, 250);
});
