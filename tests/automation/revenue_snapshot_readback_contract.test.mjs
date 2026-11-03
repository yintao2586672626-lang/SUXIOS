import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Exercise the candidate's actual helper factory. Only its unrelated date and
// wording dependencies are supplied here; snapshot validation stays in source.
const sourceUrl = new URL('../../public/revenue-cockpit-static.js', import.meta.url);
const context = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync(sourceUrl, 'utf8'), context, {
  filename: sourceUrl.pathname,
});
const helpers = context.window.SUXI_REVENUE_COCKPIT_STATIC.create({
  revenueAiReasonText: value => String(value || ''),
  resolveRevenueOverviewAsOfDate: () => ({
    ok: true,
    asOfDate: '2026-08-21',
    contractVersion: 'revenue_overview_as_of_date.v1',
  }),
  revenueAiIsoDate: value => {
    const text = String(value || '');
    return /^\d{4}-\d{2}-\d{2}$/.test(text)
      && Number.isFinite(Date.parse(`${text}T00:00:00Z`))
      && new Date(`${text}T00:00:00Z`).toISOString().slice(0, 10) === text;
  },
  revenueOverviewAsOfDateContractVersion: 'revenue_overview_as_of_date.v1',
});

const canonicalValue = value => {
  if (Array.isArray(value)) return value.map(canonicalValue);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort()
      .map(key => [key, canonicalValue(value[key])]));
  }
  return value;
};
const digestOf = value => createHash('sha256')
  .update(JSON.stringify(canonicalValue(value)))
  .digest('hex');
const reorderKeys = value => {
  if (Array.isArray(value)) return value.map(reorderKeys);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).reverse()
      .map(key => [key, reorderKeys(value[key])]));
  }
  return value;
};

const fixture = () => {
  const model = {
    contractVersion: 'revenue_daily_cockpit.v2',
    status: 'ready',
    hotelId: 80,
    tenantId: 9,
    businessDate: '2026-08-20',
    selectedPlatform: 'all_ota',
    asOfDate: '2026-08-21',
    asOfDateContractVersion: 'revenue_overview_as_of_date.v1',
    canSaveSnapshot: true,
    actionDisabledReason: '',
    summary: 'strict channel evidence fixture',
    sections: [{ sectionKey: 'ota_channel', cards: [{ metricKey: 'revenue', value: 1250, unit: 'CNY' }] }],
    visibleSections: [{ sectionKey: 'ota_channel', cards: [{ metricKey: 'revenue', value: 1250, unit: 'CNY' }] }],
    opportunities: Array.from({ length: 8 }, (_, index) => ({
      opportunityKey: `opportunity_${index + 1}`,
      canCreatePendingApproval: false,
    })),
  };
  const modelDigest = digestOf(model);
  const evidence = { tenant_id: 9, system_hotel_id: 80, platform: 'all_ota', business_date: '2026-08-20', accepted_row_ids: [101] };
  const snapshot = {
    id: 33,
    tenant_id: 9,
    contract_version: 'revenue_decision_snapshot.v1',
    persistence_status: 'readback_verified',
    evidence_identity_status: 'matched_current',
    system_hotel_id: 80,
    business_date: model.businessDate,
    platform: model.selectedPlatform,
    as_of_date: model.asOfDate,
    as_of_date_contract_version: model.asOfDateContractVersion,
    visible_model: structuredClone(model),
    visible_model_digest: modelDigest,
    evidence_digest: digestOf(evidence),
    content_digest: digestOf({ model, evidence }),
  };
  const expected = {
    snapshotId: snapshot.id,
    tenantId: model.tenantId,
    hotelId: model.hotelId,
    businessDate: model.businessDate,
    platform: model.selectedPlatform,
    asOfDate: model.asOfDate,
    asOfDateContractVersion: model.asOfDateContractVersion,
    visibleModelDigest: modelDigest,
  };
  return { model, modelDigest, snapshot, expected };
};

test('snapshot save and restore require a known current tenant before any request', async () => {
  for (const tenantId of [undefined, 0, -1, 'unknown']) {
    const { model, modelDigest } = fixture();
    model.tenantId = tenantId;
    let requests = 0;
    const request = async () => { requests++; throw new Error('must not request a missing tenant scope'); };
    assert.equal((await helpers.saveRevenueDecisionSnapshotWithReadback({ request, model, modelDigest, hotelId: 80 })).ok, false);
    assert.equal((await helpers.restoreRevenueDecisionSnapshotWithReadback({ request, model, hotelId: 80 })).ok, false);
    assert.equal(requests, 0);
  }
});
const payload = snapshot => ({ found: true, persistence_status: 'readback_verified', snapshot });
const response = snapshot => ({ code: 200, data: payload(snapshot) });
const save = (item, request) => helpers.saveRevenueDecisionSnapshotWithReadback({
  request,
  model: item.model,
  modelDigest: item.modelDigest,
  hotelId: item.model.hotelId,
});
const restore = (item, request) => helpers.restoreRevenueDecisionSnapshotWithReadback({
  request,
  model: item.model,
  hotelId: item.model.hotelId,
});

test('snapshot save accepts canonical model equality despite nested JSON key order', async () => {
  const item = fixture();
  const requests = [];
  const result = await save(item, async (endpoint, options = {}) => {
    requests.push({ endpoint, options });
    const snapshot = structuredClone(item.snapshot);
    snapshot.visible_model = reorderKeys(snapshot.visible_model);
    return response(snapshot);
  });
  assert.equal(result.ok, true);
  assert.equal(result.status, 'matched_current');
  assert.deepEqual(canonicalValue(result.snapshot.visible_model), canonicalValue(item.model));
  assert.equal(requests.length, 2);
  assert.equal(requests[0].options.method, 'POST');
  assert.deepEqual(JSON.parse(requests[0].options.body).visible_model, item.model);
  const query = new URL(requests[1].endpoint, 'http://fixture.invalid').searchParams;
  assert.equal(query.get('snapshot_id'), '33');
  assert.equal(query.get('hotel_id'), '80');
  assert.equal(query.get('platform'), 'all_ota');
  assert.equal(query.get('business_date'), '2026-08-20');
  assert.equal(query.get('as_of_date'), '2026-08-21');
  assert.equal(query.get('as_of_date_contract_version'), 'revenue_overview_as_of_date.v1');
});

test('snapshot save rejects GET model changes even when every digest stays unchanged', async () => {
  const item = fixture();
  await assert.rejects(save(item, async (_endpoint, options = {}) => {
    const snapshot = structuredClone(item.snapshot);
    if (options.method !== 'POST') snapshot.visible_model.visibleSections[0].cards[0].value = 9999;
    return response(snapshot);
  }));
});

test('snapshot save rejects a changed POST model before requesting exact readback', async () => {
  const item = fixture();
  let requestCount = 0;
  await assert.rejects(save(item, async () => {
    requestCount += 1;
    const snapshot = structuredClone(item.snapshot);
    snapshot.visible_model.summary = 'different posted content';
    return response(snapshot);
  }));
  assert.equal(requestCount, 1);
});

for (const [name, alter] of [
  ['wrong stored tenant', snapshot => { snapshot.tenant_id = 10; }],
  ['missing stored tenant', snapshot => { delete snapshot.tenant_id; }],
  ['wrong model tenant', snapshot => { snapshot.visible_model.tenantId = 10; }],
  ['missing model tenant', snapshot => { delete snapshot.visible_model.tenantId; }],
]) {
  test(`snapshot resolve rejects ${name}`, () => {
    const item = fixture();
    alter(item.snapshot);
    const result = helpers.resolveRevenueDecisionSnapshot(payload(item.snapshot), item.expected);
    assert.equal(result.ok, false);
    assert.equal(result.snapshot, null);
  });
}

for (const [name, alter] of [
  ['wrong tenant in both envelope and model', snapshot => {
    snapshot.tenant_id = 10;
    snapshot.visible_model.tenantId = 10;
  }],
  ['missing tenant in envelope', snapshot => { delete snapshot.tenant_id; }],
]) {
  test(`snapshot save rejects POST ${name}`, async () => {
    const item = fixture();
    let requestCount = 0;
    await assert.rejects(save(item, async () => {
      requestCount += 1;
      const snapshot = structuredClone(item.snapshot);
      alter(snapshot);
      return response(snapshot);
    }));
    assert.equal(requestCount, 1);
  });
}

test('snapshot save rejects exact GET from another tenant despite matching hotel and dates', async () => {
  const item = fixture();
  await assert.rejects(save(item, async (_endpoint, options = {}) => {
    const snapshot = structuredClone(item.snapshot);
    if (options.method !== 'POST') {
      snapshot.tenant_id = 10;
      snapshot.visible_model.tenantId = 10;
    }
    return response(snapshot);
  }));
});

test('snapshot restore rejects another tenant despite matching hotel and dates', async () => {
  const item = fixture();
  item.snapshot.tenant_id = 10;
  item.snapshot.visible_model.tenantId = 10;
  const result = await restore(item, async () => response(item.snapshot));
  assert.equal(result.ok, false);
  assert.equal(result.snapshot, null);
});

for (const digestKey of ['visible_model_digest', 'evidence_digest', 'content_digest']) {
  test(`snapshot ${digestKey} mismatch reports explicit failure without a success claim`, async () => {
    const item = fixture();
    await assert.rejects(save(item, async (_endpoint, options = {}) => {
      const snapshot = structuredClone(item.snapshot);
      if (options.method !== 'POST') snapshot[digestKey] = 'a'.repeat(64);
      return response(snapshot);
    }), error => {
      assert.match(error.message, /不一致|失败|未通过/);
      assert.doesNotMatch(error.message, /已按同一内容|已精确回读/);
      return true;
    });
  });
}

test('snapshot restore retains historical stale content with the same tenant and date scope', async () => {
  const item = fixture();
  item.snapshot.evidence_identity_status = 'stale_current_evidence';
  item.snapshot.visible_model.summary = 'historical channel evidence fixture';
  item.snapshot.visible_model.visibleSections[0].cards[0].value = 1100;
  item.snapshot.visible_model_digest = digestOf(item.snapshot.visible_model);
  item.snapshot.content_digest = digestOf({ historical_model: item.snapshot.visible_model });
  const result = await restore(item, async () => response(item.snapshot));
  assert.equal(result.ok, true);
  assert.equal(result.status, 'stale_current_evidence');
  assert.equal(result.snapshot.visible_model.summary, 'historical channel evidence fixture');
  assert.equal(result.snapshot.visible_model.visibleSections[0].cards[0].value, 1100);
  assert.match(result.message, /当前事实证据身份已变化/);
});

for (const status of ['stale_current_comparison', 'comparison_currentness_not_verified']) {
  test(`snapshot ${status} preserves exact saved content and explains the blocked basis`, async () => {
    const item = fixture();
    const stale = structuredClone(item.snapshot);
    stale.evidence_identity_status = status;
    const result = await restore(item, async () => response(stale));
    assert.equal(result.ok, true);
    assert.equal(result.status, status);
    assert.deepEqual(result.snapshot.visible_model, item.model);
    assert.match(result.message, /比较基期/);
    assert.doesNotMatch(result.message, /同一内容与证据身份精确回读/);
    const saved = await save(item, async (_url, options = {}) => response(
      options.method === 'POST' ? item.snapshot : stale
    ));
    assert.equal(saved.status, status);
    assert.match(saved.message, /比较基期/);
    const recovered = await restore(item, async () => response(item.snapshot));
    assert.equal(recovered.status, 'matched_current');
    assert.equal(recovered.snapshot.id, item.snapshot.id);
  });
}

for (const failure of ['save response failure', 'readback response failure', 'readback transport failure', 'restore response failure']) {
  test(`snapshot ${failure} leaves a later valid restore usable`, async () => {
    const item = fixture();
    const failingRequest = async (_endpoint, options = {}) => {
      if (failure === 'save response failure' && options.method === 'POST') {
        return { code: 500, message: 'save response failure' };
      }
      if (failure === 'readback response failure' && options.method !== 'POST') {
        return { code: 503, message: 'readback response failure' };
      }
      if (failure === 'readback transport failure' && options.method !== 'POST') {
        throw new Error('readback transport failure');
      }
      if (failure === 'restore response failure') return { code: 503, message: failure };
      return response(structuredClone(item.snapshot));
    };
    const attempt = failure === 'restore response failure'
      ? restore(item, failingRequest)
      : save(item, failingRequest);
    await assert.rejects(attempt, error => {
      if (failure === 'restore response failure') assert.equal(error.message, failure);
      else {
        assert.equal(error.message, `收益决策快照保存结果未确认，请刷新驾驶舱只读核对后重试：${failure}`);
        assert.equal(error.decisionSnapshotReadbackUnverified, true);
      }
      return true;
    });
    const recovered = await restore(item, async () => response(structuredClone(item.snapshot)));
    assert.equal(recovered.ok, true);
    assert.equal(recovered.status, 'matched_current');
    assert.equal(recovered.snapshot.id, 33);
    assert.equal(recovered.snapshot.tenant_id, 9);
    assert.deepEqual(recovered.snapshot.visible_model, item.model);
  });
}
