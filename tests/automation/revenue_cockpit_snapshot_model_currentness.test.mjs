import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// local_synthetic_fixture_only: original creation handler, no authenticated or external writes.
const main = fs.readFileSync(process.env.SUXIOS_CONSUMER_SOURCE || 'public/app-main.js', 'utf8').replaceAll('\r\n', '\n');
const begin = main.indexOf('            const createRevenueCockpitOpportunityPendingApproval =');
const end = main.indexOf('            const openRevenueCockpitPendingApproval =', begin);
assert.ok(begin >= 0 && end > begin);
const card = { opportunityKey: 'traffic_entry_shortage', canCreatePendingApproval: true };
const snapshot = (id, digest) => ({ id, evidence_identity_status: 'matched_current', visible_model_digest: digest });
const ref = value => ({ value });
function harness(saved = snapshot(1, 'historical-digest'), digest = 'current-digest') {
    const calls = [], messages = [];
    const ctx = {
        revenueCockpitDecisionSnapshot: ref(saved), revenueCockpitLiveModelDigest: ref(digest),
        revenueCockpitSnapshotError: ref(''), revenueCockpitSnapshotReadbackStatus: ref('matched_current'),
        revenueCockpitPendingApprovalError: ref(''), revenueCockpitPendingApprovalReadbackStatus: ref('idle'),
        revenueCockpitOpportunityApprovalLoadingKey: ref(''), revenueCockpitOpportunityApprovals: ref({}),
        revenueCockpitPendingApproval: ref(null), captureRevenueCockpitMutationContext: () => 'scope-generation-1',
        isRevenueCockpitMutationCurrent: () => true, request() { throw Error('unexpected direct request'); },
        showToast: message => messages.push(message),
        saveRevenueCockpitDecisionSnapshot: async () => { calls.push({ action: 'save' }); return snapshot(2, 'current-digest'); },
        revenueAiRunCockpitHelper: async (name, input) => {
            calls.push({ action: 'pending', name, snapshot: input.snapshot });
            return { ok: true, intent: { id: 7, status: 'pending_approval', snapshot_id: input.snapshot.id }, message: 'saved' };
        },
    };
    const create = vm.runInNewContext(`(() => { ${main.slice(begin, end)} return createRevenueCockpitOpportunityPendingApproval; })()`, ctx);
    return { ctx, calls, messages, create };
}

test('a historical same-fact snapshot is replaced by the currently visible model before pending creation', async () => {
    const historical = { ...snapshot(1, 'historical-digest'), content_digest: 'immutable-old-content',
        visible_model: { value: null, displayValue: '—', status: 'readback_verified' } };
    const bytes = JSON.stringify(historical), h = harness(historical);
    assert.equal((await h.create(card)).snapshot_id, 2);
    assert.deepEqual(h.calls.map(c => c.action), ['save', 'pending']);
    assert.equal(h.calls[1].snapshot.visible_model_digest, 'current-digest');
    assert.equal(JSON.stringify(historical), bytes);
    assert.equal(h.ctx.revenueCockpitPendingApprovalReadbackStatus.value, 'readback_verified');
    assert.equal(h.ctx.revenueCockpitOpportunityApprovalLoadingKey.value, '');
});

test('an exact snapshot of the current visible model is reused without an extra save', async () => {
    const h = harness(snapshot(2, 'current-digest'));
    assert.equal((await h.create(card)).snapshot_id, 2);
    assert.deepEqual(h.calls.map(c => c.action), ['pending']);
});

test('missing or stale saved-model readback sends no pending mutation and can recover on a later click', async () => {
    for (const saved of [null, snapshot(3, 'historical-digest'), { ...snapshot(3, 'current-digest'), evidence_identity_status: 'drifted' }]) {
        const h = harness();
        h.ctx.saveRevenueCockpitDecisionSnapshot = async () => { h.calls.push({ action: 'save' }); return saved; };
        assert.equal(await h.create(card), null);
        assert.deepEqual(h.calls.map(c => c.action), ['save']);
        assert.equal(h.ctx.revenueCockpitPendingApproval.value, null);
        assert.match(h.messages[0], /快照保存/);
        h.ctx.saveRevenueCockpitDecisionSnapshot = async () => snapshot(2, 'current-digest');
        assert.equal((await h.create(card)).snapshot_id, 2);
    }
});

test('same-scope model refresh during snapshot save prevents an obsolete pending request', async () => {
    const h = harness();
    h.ctx.saveRevenueCockpitDecisionSnapshot = async () => {
        h.calls.push({ action: 'save' }); h.ctx.revenueCockpitLiveModelDigest.value = 'refreshed-digest';
        return snapshot(2, 'current-digest');
    };
    assert.equal(await h.create(card), null);
    assert.deepEqual(h.calls.map(c => c.action), ['save']);
    assert.equal(h.ctx.revenueCockpitPendingApproval.value, null);
});

test('an unavailable live canonical digest cannot make two empty digest strings a match', async () => {
    for (const digest of ['', undefined]) {
        const h = harness(snapshot(1, ''), digest);
        h.ctx.revenueCockpitLiveModelDigest.value = digest;
        h.ctx.saveRevenueCockpitDecisionSnapshot = async () => { h.calls.push({ action: 'save' }); return snapshot(2, ''); };
        assert.equal(await h.create(card), null);
        assert.deepEqual(h.calls.map(c => c.action), ['save']);
    }
});

test('existing scope-generation and eligibility gates still prevent writes and stale state changes', async () => {
    const h = harness();
    h.ctx.isRevenueCockpitMutationCurrent = () => false;
    assert.equal(await h.create(card), null);
    assert.deepEqual(h.calls.map(c => c.action), ['save']);
    assert.equal(h.ctx.revenueCockpitPendingApproval.value, null);
    const ineligible = harness();
    assert.equal(await ineligible.create({ ...card, canCreatePendingApproval: false }), null);
    assert.equal(ineligible.calls.length, 0);
});
