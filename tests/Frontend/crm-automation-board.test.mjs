import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    actionLabel,
    formatDelay,
    groupRules,
    joinDelay,
    outcomeLabel,
    outcomeTone,
    ruleTarget,
    splitDelay,
} from '../../resources/js/lib/crm-automation-board.ts';

const rule = (id, extra = {}) => ({
    id,
    name: `Rule ${id}`,
    pipeline_id: 1,
    stage_id: 10,
    trigger: 'stage_entered',
    condition_field: null,
    condition_operator: null,
    condition_value: null,
    action: 'notify_assignee',
    due_days: null,
    active: true,
    ...extra,
});

await test('delay formats and round-trips through the editor units', () => {
    assert.equal(formatDelay(0), 'immediately');
    assert.equal(formatDelay(null), 'immediately');
    assert.equal(formatDelay(1), '1 minute later');
    assert.equal(formatDelay(90), '90 minutes later');
    assert.equal(formatDelay(120), '2 hours later');
    assert.equal(formatDelay(1440), '1 day later');
    for (const minutes of [0, 5, 60, 180, 2880, 100]) {
        const { amount, unit } = splitDelay(minutes);
        assert.equal(joinDelay(amount, unit), minutes);
    }
    assert.equal(joinDelay(-3, 'hours'), 0);
    assert.equal(joinDelay(999999, 'days'), 525600);
});

await test('rules group into the triggers row and stage lanes for one pipeline', () => {
    const rules = [
        rule(1),
        rule(2, { stage_id: 11, name: 'Call back' }),
        rule(3, { trigger: 'lead_created', stage_id: null }),
        rule(4, { stage_id: null }),
        rule(5, { pipeline_id: 2 }),
        rule(6, { active: false }),
    ];
    const lanes = groupRules(rules, 1);
    assert.deepEqual(
        lanes.triggers.map((r) => r.id),
        [3, 4],
    );
    assert.deepEqual(
        lanes.byStage.get(10).map((r) => r.id),
        [1, 6],
    );
    assert.deepEqual(
        lanes.byStage.get(11).map((r) => r.id),
        [2],
    );
    assert.equal(groupRules(rules, 1, 'call').byStage.get(11).length, 1);
    assert.equal(groupRules(rules, 1, 'call').triggers.length, 0);
});

await test('targets, labels and outcome tones read clearly', () => {
    const stage = (id) => (id === 12 ? 'Qualified' : '?');
    assert.equal(
        ruleTarget(
            rule(1, { action: 'change_stage', target_stage_id: 12 }),
            stage,
        ),
        'To Qualified',
    );
    assert.equal(
        ruleTarget(
            rule(1, {
                action: 'create_follow_up',
                activity_type: 'call',
                due_days: 2,
            }),
            stage,
        ),
        'call · due in 2 d',
    );
    assert.equal(
        ruleTarget(rule(1, { action: 'notify_managers' }), stage),
        'Supervisors',
    );
    assert.equal(actionLabel('create_follow_up'), 'Schedule an activity');
    assert.equal(actionLabel('mystery_action'), 'mystery action');
    assert.equal(outcomeTone('failed'), 'bad');
    assert.equal(outcomeTone('blocked'), 'bad');
    assert.equal(outcomeTone('completed'), 'ok');
    assert.equal(outcomeTone('pending'), 'neutral');
    assert.equal(outcomeTone('skipped_condition'), 'neutral');
    assert.equal(outcomeLabel('skipped_condition'), 'skipped condition');
});
