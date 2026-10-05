import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    accessRequests,
    collectPrincipals,
    dealGroups,
    dealValues,
    principalLabel,
    sectionRequests,
    sectionValues,
    toRoles,
} from '../../resources/js/lib/crm-access.ts';

const lookups = {
    members: [{ id: 7, name: 'Sana Nadeem' }],
    teams: [{ id: 2, name: 'Team C' }],
    subdepartments: [],
    departments: [{ id: 1, name: 'Sales' }],
};
const pipelines = [
    { id: 10, name: 'Off plan' },
    { id: 11, name: 'Resale' },
];
const rules = [
    {
        pipeline_id: 10,
        principal_type: 'role',
        principal_id: 'manager',
        permissions: { read: 'organization', move: 'team' },
    },
    {
        pipeline_id: 10,
        principal_type: 'user',
        principal_id: '7',
        permissions: { read: 'own' },
    },
    {
        pipeline_id: 11,
        principal_type: 'team',
        principal_id: 2,
        permissions: { read: 'team' },
    },
];

await test('columns are the default roles plus named principals, never owners or administrators', () => {
    const principals = collectPrincipals([
        ...rules,
        { principal_type: 'role', principal_id: 'owner' },
    ]);
    assert.deepEqual(
        principals.map((p) => `${p.type}:${p.id}`),
        ['role:manager', 'role:member', 'role:viewer', 'user:7', 'team:2'],
    );
    assert.deepEqual(
        toRoles(principals, lookups).map((r) => r.name),
        ['Manager', 'Member', 'Viewer', 'Sana Nadeem', 'Team: Team C'],
    );
    assert.equal(toRoles(principals, lookups)[3].members[0].id, 7);
    assert.equal(
        principalLabel({ type: 'department', id: '99' }, lookups),
        'Department: #99',
    );
});

await test('rules fill the matrix and unmatched cells default to no access', () => {
    const principals = collectPrincipals(rules);
    const values = dealValues(pipelines, principals, rules);
    assert.equal(values[1]['p10.read'], 'organization');
    assert.equal(values[1]['p10.move'], 'team');
    assert.equal(values[1]['p11.read'], 'none');
    assert.equal(values[4]['p10.read'], 'own');
    assert.equal(values[5]['p11.read'], 'team');
    assert.equal(dealGroups(pipelines)[1].rows.length, 8);
    assert.equal(dealGroups(pipelines)[0].label, 'Deal pipeline: Off plan');
});

await test('only changed pipeline and principal pairs become requests with the full action set', () => {
    const principals = collectPrincipals(rules);
    const before = dealValues(pipelines, principals, rules);
    const after = structuredClone(before);
    after[2]['p11.add'] = 'own';
    after[1]['p10.read'] = 'team';
    const requests = accessRequests(pipelines, principals, before, after);
    assert.equal(requests.length, 2);
    const member = requests.find((r) => r.body.principal_id === 'member');
    assert.equal(member.pipelineId, 11);
    assert.equal(Object.keys(member.body.permissions).length, 8);
    assert.equal(member.body.permissions.add, 'own');
    assert.equal(member.body.permissions.read, 'none');
    assert.deepEqual(
        accessRequests(pipelines, principals, before, structuredClone(before)),
        [],
    );
});

await test('sections are on by default and requests list only switched cells', () => {
    const principals = collectPrincipals([]);
    const permissions = ['view_finance', 'manage_crm'];
    const before = sectionValues(permissions, principals, [
        {
            principal_type: 'role',
            principal_id: 'viewer',
            permission: 'manage_crm',
            enabled: 0,
        },
    ]);
    assert.equal(before[1]['sections.view_finance'], true);
    assert.equal(before[3]['sections.manage_crm'], false);
    const after = structuredClone(before);
    after[1]['sections.view_finance'] = false;
    after[3]['sections.manage_crm'] = true;
    assert.deepEqual(sectionRequests(permissions, principals, before, after), [
        {
            principal_type: 'role',
            principal_id: 'manager',
            permission: 'view_finance',
            enabled: false,
        },
        {
            principal_type: 'role',
            principal_id: 'viewer',
            permission: 'manage_crm',
            enabled: true,
        },
    ]);
});
