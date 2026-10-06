import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    companyBody,
    contactBody,
    displayField,
    fieldDraft,
    fieldPayload,
    fullName,
    whatsappUrl,
} from '../../resources/js/lib/crm-parties.ts';

const fields = [
    {
        key: 'budget',
        name: 'Budget',
        type: 'currency',
        value: 5000,
        editable: true,
    },
    { key: 'vip', name: 'VIP', type: 'checkbox', value: false, editable: true },
    {
        key: 'tags',
        name: 'Tags',
        type: 'multi_select',
        options: ['a', 'b'],
        value: ['a'],
        editable: true,
    },
    { key: 'note', name: 'Note', type: 'text', value: 'hello', editable: true },
    {
        key: 'secret',
        name: 'Secret',
        type: 'text',
        value: 'x',
        editable: false,
    },
];

await test('names join and trim', () => {
    assert.equal(
        fullName({ first_name: 'Lina', last_name: 'Karim' }),
        'Lina Karim',
    );
    assert.equal(fullName({ first_name: 'Cher', last_name: '' }), 'Cher');
});

await test('draft covers only editable fields with form-friendly values', () => {
    assert.deepEqual(fieldDraft(fields), {
        budget: '5000',
        vip: false,
        tags: ['a'],
        note: 'hello',
    });
});

await test('payload carries only changed fields, coerced by type', () => {
    const draft = fieldDraft(fields);
    assert.deepEqual(fieldPayload(fields, draft), {});
    draft.budget = '7500.5';
    draft.vip = true;
    draft.tags = ['a', 'b'];
    draft.note = '  ';
    assert.deepEqual(fieldPayload(fields, draft), {
        budget: 7500.5,
        vip: true,
        tags: ['a', 'b'],
        note: null,
    });
});

await test('read-only fields are never sent', () => {
    const draft = { ...fieldDraft(fields), secret: 'changed' };
    assert.equal('secret' in fieldPayload(fields, draft), false);
});

await test('display text handles lists, booleans and blanks', () => {
    assert.equal(displayField({ ...fields[2] }), 'a');
    assert.equal(displayField({ ...fields[1], value: true }), 'Yes');
    assert.equal(displayField({ ...fields[3], value: null }), '—');
});

await test('update bodies include the version and nulls for blank optionals', () => {
    assert.deepEqual(
        contactBody(
            {
                first_name: ' Lina ',
                last_name: 'K',
                email: '',
                phone: '',
                account_id: null,
            },
            3,
            {},
        ),
        {
            expected_version: 3,
            first_name: 'Lina',
            last_name: 'K',
            email: null,
            phone: null,
            account_id: null,
        },
    );
    assert.equal(
        contactBody(
            {
                first_name: 'a',
                last_name: 'b',
                email: '',
                phone: '',
                account_id: 4,
            },
            1,
            { note: 'x' },
        ).custom_fields.note,
        'x',
    );
    assert.equal(
        companyBody({ name: 'Acme', email: '', phone: '', website: '' }, 2, {})
            .website,
        null,
    );
});

await test('whatsapp links keep only digits and drop the 00 prefix', () => {
    assert.equal(whatsappUrl('+971 50 123 4567'), 'https://wa.me/971501234567');
    assert.equal(whatsappUrl('00971501234567'), 'https://wa.me/971501234567');
    assert.equal(whatsappUrl('123'), null);
    assert.equal(whatsappUrl(null), null);
});
