import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    capabilityLabel,
    providerBody,
    providerForm,
    settingsSummary,
} from '../../resources/js/lib/crm-providers.ts';

const caps = ['sms', 'payment', 'email'];

await test('new forms start on the first capability, edit forms read the record', () => {
    assert.equal(providerForm(caps).capability, 'sms');
    const record = {
        id: 1,
        name: 'Sales email',
        capability: 'email',
        provider: null,
        active: 0,
        settings: { label: 'Sales', daily_limit: 50 },
        version: 2,
    };
    const form = providerForm(caps, record);
    assert.deepEqual(
        [form.name, form.capability, form.label, form.daily_limit],
        ['Sales email', 'email', 'Sales', '50'],
    );
});

await test('body is always inactive, trims, and drops blank settings', () => {
    const form = {
        ...providerForm(caps),
        name: ' Sales ',
        label: ' Sales ',
        daily_limit: '25',
        terms: '  ',
    };
    assert.deepEqual(providerBody(form), {
        name: 'Sales',
        capability: 'sms',
        provider: null,
        active: false,
        settings: { label: 'Sales', daily_limit: 25 },
    });
    assert.equal(providerBody(form, 3).expected_version, 3);
});

await test('labels and summaries are readable', () => {
    assert.equal(capabilityLabel('accounting_export'), 'Accounting export');
    assert.equal(capabilityLabel('fax_machine'), 'fax machine');
    assert.equal(
        settingsSummary({ label: 'Sales', sender: 'noreply' }),
        'Sales · noreply',
    );
    assert.equal(settingsSummary(null), '');
});
