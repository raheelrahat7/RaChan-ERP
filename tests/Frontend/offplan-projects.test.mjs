import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    countFor,
    datesOrderError,
    hasChanges,
    moneyText,
    projectForm,
    projectQuery,
    soldPercent,
    statusChoices,
    statusLabel,
    updateBody,
} from '../../resources/js/lib/offplan-projects.ts';

const project = {
    id: 1,
    version: 2,
    workflow_status: 'launched',
    launch_on: '2027-01-01',
    handover_on: '2030-01-01',
    completion_on: null,
    assigned_broker_id: 5,
    commission_rate: '2.50',
    units_total: 8,
    units_sold: 2,
};

await test('form mirrors a project, with numbers as text', () => {
    const form = projectForm(project);
    assert.equal(form.assigned_broker_id, '5');
    assert.equal(form.commission_rate, '2.50');
    assert.equal(projectForm().workflow_status, '');
});

await test('update body is partial and versioned; blanks clear but never the status', () => {
    const form = projectForm(project);
    assert.deepEqual(updateBody(project, form), { expected_version: 2 });
    form.workflow_status = 'selling';
    form.assigned_broker_id = '';
    form.commission_rate = 3;
    form.completion_on = '2031-06-30';
    assert.deepEqual(updateBody(project, form), {
        expected_version: 2,
        workflow_status: 'selling',
        assigned_broker_id: null,
        commission_rate: '3',
        completion_on: '2031-06-30',
    });
    form.workflow_status = '';
    assert.equal('workflow_status' in updateBody(project, form), false);
    assert.equal(hasChanges({ expected_version: 2 }), false);
});

await test('dates, statuses and counts', () => {
    assert.equal(
        datesOrderError({ launch_on: '2027-02-01', handover_on: '2027-01-01' }),
        true,
    );
    assert.equal(
        datesOrderError({ launch_on: '', handover_on: '2027-01-01' }),
        false,
    );
    const statuses = [
        { code: 'a', name: 'A', position: 2, active: true },
        { code: 'b', name: 'B', position: 1, active: false },
    ];
    assert.deepEqual(
        statusChoices(statuses, 'x').map((s) => s.code),
        ['a'],
    );
    assert.deepEqual(
        statusChoices(statuses, 'b').map((s) => s.code),
        ['b', 'a'],
    );
    assert.equal(statusLabel(statuses, 'b'), 'B');
    assert.equal(statusLabel(statuses, 'sold_out'), 'Sold out');
    assert.equal(statusLabel(statuses, null), '—');
    assert.equal(countFor([{ code: 'a', total: 3 }], 'z'), 0);
});

await test('sold share, query and money', () => {
    assert.equal(soldPercent(project), 25);
    assert.equal(soldPercent({ units_total: 0, units_sold: 0 }), 0);
    assert.equal(
        projectQuery(' hb ', 'launched', 2),
        'q=hb&workflow_status=launched&page=2',
    );
    assert.equal(projectQuery('', ''), '');
    assert.equal(moneyText('1.00'), 'AED 1.00');
    assert.equal(moneyText(null), '—');
});
