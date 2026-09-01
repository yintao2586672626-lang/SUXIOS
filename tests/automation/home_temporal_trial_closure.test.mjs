import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = [
    readFileSync(new URL('../../public/components/system/knowledge-center-domain.js', import.meta.url), 'utf8'),
    readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8'),
].join('\n');
const template = readFileSync(
    new URL('../../resources/frontend/templates/fragments/23b-page-ai-workbench.html', import.meta.url),
    'utf8'
);

const functionBlock = (name, nextName) => {
    const startMarker = `const ${name} = async`;
    const endMarker = `const ${nextName} = async`;
    const start = appMain.indexOf(startMarker);
    const end = appMain.indexOf(endMarker, start + startMarker.length);
    assert.ok(start >= 0, `missing ${name}`);
    assert.ok(end > start, `missing boundary after ${name}`);
    return appMain.slice(start, end);
};

test('trial list is verified only after hotel, scope, write boundary and immutable detail readback', () => {
    assert.match(appMain, /homeTemporalTrialListVerified\.value = false/);
    assert.match(appMain, /Number\(res\.data\?\.hotel_id \|\| 0\) !== hotelId/);
    assert.match(appMain, /res\.data\?\.metric_scope !== 'ota_channel'/);
    assert.match(appMain, /res\.data\?\.automatic_price_write !== false/);
    assert.match(appMain, /normalizeHomeTemporalTrialPointDigests\(points, '独立 GET'\)/);
    assert.match(appMain, /if \(isCurrentRequest\(\)\) homeTemporalTrialListVerified\.value = true/);
    assert.match(appMain, /new Set\(rows\.map\(row => row\.key\)\)\.size !== 42/);
});

test('all forecast trial writes use auth, page, hotel, trial and action epoch guards', () => {
    assert.match(appMain, /const captureHomeTemporalTrialActionContext/);
    assert.match(appMain, /epoch: \+\+homeTemporalTrialActionEpoch/);
    assert.match(appMain, /isAuthSessionCurrent\(context\.session\)/);
    assert.match(appMain, /Number\(homeTemporalSelectedHotelId\.value \|\| 0\) === context\.hotelId/);
    assert.match(appMain, /currentPage\.value === context\.page/);
    assert.match(appMain, /context\.trialId <= 0 \|\| Number\(homeTemporalTrial\.value\?\.id \|\| 0\) === context\.trialId/);

    const blocks = [
        ['createHomeTemporalTrial', 'submitHomeTemporalTrialForReview', 'create'],
        ['submitHomeTemporalTrialForReview', 'openHomeTemporalTrialOperation', 'submit'],
        ['refreshHomeTemporalTrialActuals', 'finalizeHomeTemporalTrialReview', 'refresh'],
        ['finalizeHomeTemporalTrialReview', 'loadHomeTemporalInsights', 'finalize'],
    ];
    for (const [name, nextName, action] of blocks) {
        const block = functionBlock(name, nextName);
        assert.ok(block.includes(`captureHomeTemporalTrialActionContext('${action}'`), `${name} missing action context`);
        assert.ok(block.includes('isHomeTemporalTrialActionCurrent(actionContext)'), `${name} missing stale-response guard`);
        assert.ok(block.includes('homeTemporalTrialActionEpoch === actionContext.epoch'), `${name} missing epoch-safe finally`);
    }
});

test('submit performs an independent execution-intent GET and preserves zero-task approval boundary', () => {
    const block = functionBlock('submitHomeTemporalTrialForReview', 'openHomeTemporalTrialOperation');
    assert.match(block, /readOperationExecutionIntent\(intentId\)/);
    assert.match(block, /operationExecutionHotelId\(persistedIntent\) !== hotelId/);
    assert.match(block, /persistedIntent\?\.source_module \|\| ''\) !== 'temporal_forecast_trial'/);
    assert.match(block, /persistedIntent\?\.source_record_id \|\| 0\) !== trialId/);
    assert.match(block, /persistedIntent\?\.status \|\| ''\) !== 'pending_approval'/);
    assert.match(block, /!Array\.isArray\(persistedIntent\?\.tasks\)/);
    assert.match(block, /persistedIntent\.tasks\.length !== 0/);
    assert.match(block, /readHomeTemporalTrialSnapshot\(trialId, hotelId, res\.data, 'submit'\)/);
    assert.match(appMain, /String\(operationFlow\.status \|\| ''\) === 'readback_failed'/);
});

test('actual POST is followed by an exact 42-point mutable tuple and summary GET comparison', () => {
    const block = functionBlock('refreshHomeTemporalTrialActuals', 'finalizeHomeTemporalTrialReview');
    assert.match(block, /String\(homeTemporalTrial\.value\?\.status \|\| ''\) !== 'running'/);
    assert.match(block, /readHomeTemporalTrialSnapshot\(trialId, hotelId, res\.data, 'actual'\)/);
    assert.doesNotMatch(block, /ready_points \|\| 0/);
    assert.doesNotMatch(block, /total_points \|\| 42/);

    for (const field of [
        'actual_status',
        'actual_value',
        'absolute_error',
        'within_range',
        'actual_reason_code',
        'actual_readback_at',
    ]) {
        assert.ok(appMain.includes(`'${field}'`), `missing exact actual field ${field}`);
    }
    assert.match(appMain, /new Set\(rows\.map\(row => row\.id\)\)\.size !== 42/);
    assert.match(appMain, /JSON\.stringify\(actualPoints\[index\]\) !== JSON\.stringify\(expectedPoints\[index\]\)/);
    assert.match(appMain, /actual_summary 不一致/);
});

test('final review POST is followed by exact digest and review tuple GET comparison', () => {
    const block = functionBlock('finalizeHomeTemporalTrialReview', 'loadHomeTemporalInsights');
    assert.match(block, /readHomeTemporalTrialSnapshot\(trialId, hotelId, res\.data, 'final'\)/);
    assert.match(appMain, /const fields = \['review_digest', 'decision', 'note', 'reviewed_by', 'reviewed_at'\]/);
    assert.match(appMain, /actualDigest !== expectedDigest/);
    assert.match(appMain, /\['reviewed', 'stopped'\]\.includes/);
    assert.match(appMain, /JSON\.stringify\(actualTuple\) !== JSON\.stringify\(expectedTuple\)/);
});

test('UI keeps missing values missing and only exposes actual refresh while running', () => {
    assert.match(template, /row\.trusted_days === null \|\| row\.trusted_days === undefined \? '未返回'/);
    assert.match(template, /ready_points === null \|\| homeTemporalTrialActualSummary\.total_points === null \? '回读缺失'/);
    assert.match(template, /matured_target_days === null \|\| homeTemporalTrialActualSummary\.required_target_days === null \? '未返回'/);
    assert.match(template, /String\(homeTemporalTrial\.status \|\| ''\) === 'running'/);
    assert.doesNotMatch(template, /\['pending_approval', 'running'\]/);
    assert.match(appMain, /!Number\.isFinite\(row\.trusted_days\)/);
    assert.match(appMain, /未返回可信日/);
});

test('formal promotion and XLSX source integrations remain present', () => {
    assert.match(appMain, /const captureKnowledgePromotionContext/);
    assert.match(appMain, /readKnowledgePromotionActionSnapshot/);
    assert.match(appMain, /knowledgeCenterImportSelectedFile/);
    assert.match(appMain, /new FormData\(\)/);
    assert.match(appMain, /\/knowledge\/document-text/);
});
