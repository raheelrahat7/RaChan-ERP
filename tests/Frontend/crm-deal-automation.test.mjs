import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    delayText,
    outcomeLabel,
    ruleForm,
    rulePayload,
} from '../../resources/js/lib/crm-deal-automation.ts';

await test('delays read naturally', () => {
    assert.equal(delayText(0), 'Immediately');
    assert.equal(delayText(90), 'After 1 h 30 min');
    assert.equal(delayText(1500), 'After 1 d 1 h');
});

await test('outcomes have labels and unknown ones pass through', () => {
    assert.equal(
        outcomeLabel('skipped_condition'),
        'Skipped: conditions not met',
    );
    assert.equal(outcomeLabel('weird'), 'weird');
});

await test('new rule form defaults and edit form reads stored flags', () => {
    const fresh = ruleForm(null, 3, 9);
    assert.deepEqual(
        [fresh.pipeline_id, fresh.stage_id, fresh.active, fresh.action],
        [3, 9, true, 'notify_assignee'],
    );
    const edited = ruleForm(
        {
            id: 1,
            name: 'x',
            pipeline_id: 1,
            stage_id: 2,
            action: 'change_stage',
            active: 0,
            conditions: [],
            working_hours_only: 1,
            target_stage_id: 5,
            delay_minutes: 60,
            due_days: null,
            activity_type: null,
        },
        1,
        2,
    );
    assert.equal(edited.active, false);
    assert.equal(edited.working_hours_only, true);
    assert.equal(edited.target_stage_id, 5);
});

await test('payload only includes fields for the chosen action', () => {
    const base = ruleForm(null, 1, 2);
    base.name = ' Remind ';
    assert.equal('target_stage_id' in rulePayload(base, []), false);
    assert.equal('due_days' in rulePayload(base, []), false);
    const move = { ...base, action: 'change_stage', target_stage_id: 4 };
    assert.equal(rulePayload(move, []).target_stage_id, 4);
    const follow = { ...base, action: 'create_follow_up', due_days: 2 };
    const payload = rulePayload(follow, []);
    assert.equal(payload.due_days, 2);
    assert.equal(payload.name, 'Remind');
});
