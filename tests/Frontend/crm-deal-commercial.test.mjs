import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    commercialFields,
    commercialPayload,
    optionChoices,
    optionLabel,
    sharesTooHigh,
} from '../../resources/js/lib/crm-deal-commercial.ts';

const options = [
    { code: 'submitted', name: 'Submitted', active: true },
    { code: 'old', name: 'Old status', active: false },
];

await test('form starts blank and mirrors a deal', () => {
    assert.deepEqual(commercialFields(), {
        deal_status: '',
        scenario: '',
        gross_commission: '',
        co_broker_share: '',
        agent_share: '',
    });
    assert.equal(
        commercialFields({ deal_status: 'submitted', agent_share: '40.00' })
            .agent_share,
        '40.00',
    );
});

await test('archived options stay selectable only for the current value', () => {
    assert.deepEqual(
        optionChoices(options, 'submitted').map((o) => o.code),
        ['submitted'],
    );
    assert.deepEqual(
        optionChoices(options, 'old').map((o) => o.code),
        ['submitted', 'old'],
    );
    assert.equal(optionLabel(options, 'old'), 'Old status');
    assert.equal(optionLabel(options, 'contract_signed'), 'Contract signed');
    assert.equal(optionLabel(options, null), '—');
});

await test('shares are checked together and number inputs are handled', () => {
    assert.equal(sharesTooHigh({ co_broker_share: 60, agent_share: 41 }), true);
    assert.equal(
        sharesTooHigh({ co_broker_share: '60', agent_share: '40' }),
        false,
    );
    assert.equal(
        sharesTooHigh({ co_broker_share: '', agent_share: '' }),
        false,
    );
});

await test('payload nulls blanks and omits money without permission', () => {
    const form = {
        deal_status: '',
        scenario: 'co_broker',
        gross_commission: 15000,
        co_broker_share: '30',
        agent_share: '',
    };
    assert.deepEqual(commercialPayload(form, true), {
        deal_status: null,
        scenario: 'co_broker',
        gross_commission: '15000',
        co_broker_share: '30',
        agent_share: null,
    });
    assert.deepEqual(commercialPayload(form, false), {
        deal_status: null,
        scenario: 'co_broker',
    });
});
