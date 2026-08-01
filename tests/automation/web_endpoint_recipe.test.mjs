import assert from 'node:assert/strict';
import test from 'node:test';

import {
  defineWebEndpointRecipe,
  materializeWebEndpointPlan,
  materializeWebEndpointRecipe,
  WEB_ENDPOINT_RECIPE_SCHEMA_VERSION,
} from '../../scripts/lib/web_endpoint_recipe.mjs';

function sampleRecipe(overrides = {}) {
  return defineWebEndpointRecipe({
    id: 'operating_total',
    platform: 'dingdandao',
    sourceKind: 'pms',
    businessModule: 'accommodation_operating',
    intent: 'daily_core',
    origin: 'https://www.dingdandao.com',
    method: 'POST',
    path: '/v2/um-b/web/pro/data/businessIndicatorsTotal',
    bodyTemplate: {
      TIMEZONEOFFSET: -480,
      ntwNum: '{provider_network_id}',
      startDate: '{target_date}',
      endDate: '{target_date}',
      festivalType: -1200,
    },
    bindings: {
      provider_network_id: { format: 'opaque_id' },
      target_date: { format: 'date' },
    },
    ...overrides,
  });
}

test('stores a deterministic endpoint recipe without real hotel, date, or session values', () => {
  const recipe = sampleRecipe();
  const serialized = JSON.stringify(recipe);

  assert.equal(recipe.schema_version, WEB_ENDPOINT_RECIPE_SCHEMA_VERSION);
  assert.equal(recipe.platform, 'dingdandao');
  assert.equal(recipe.source_kind, 'pms');
  assert.equal(recipe.business_module, 'accommodation_operating');
  assert.equal(recipe.intent, 'daily_core');
  assert.equal(recipe.body_template.ntwNum, '{provider_network_id}');
  assert.equal(recipe.body_template.startDate, '{target_date}');
  assert.doesNotMatch(
    serialized,
    /23013183|2026-07-30|cookie|authorization|bearer|token|password|secret/i,
  );
});

test('materializes runtime values only after typed bindings pass validation', () => {
  const request = materializeWebEndpointRecipe(sampleRecipe(), {
    provider_network_id: 'network_123',
    target_date: '2026-07-30',
  });

  assert.equal(request.recipe_id, 'operating_total');
  assert.equal(request.origin, 'https://www.dingdandao.com');
  assert.equal(request.path, '/v2/um-b/web/pro/data/businessIndicatorsTotal');
  assert.equal(request.body.ntwNum, 'network_123');
  assert.equal(request.body.startDate, '2026-07-30');
  assert.equal(request.body.endDate, '2026-07-30');
});

test('the open recipe contract supports independent PMS and OTA adapters', () => {
  const recipes = [
    sampleRecipe(),
    sampleRecipe({
      id: 'ctrip_business',
      platform: 'ctrip',
      sourceKind: 'ota',
      businessModule: 'business_overview',
      origin: 'https://ebooking.ctrip.com',
      path: '/datacenter/api/business/overview',
    }),
    sampleRecipe({
      id: 'meituan_traffic',
      platform: 'meituan',
      sourceKind: 'ota',
      businessModule: 'traffic',
      origin: 'https://eb.meituan.com',
      path: '/api/v1/ebooking/business/traffic',
    }),
  ];
  const plan = materializeWebEndpointPlan(recipes, {
    provider_network_id: 'provider_opaque',
    target_date: '2026-07-30',
  });

  assert.deepEqual(
    plan.map((request) => request.platform),
    ['dingdandao', 'ctrip', 'meituan'],
  );
  assert.deepEqual(
    plan.map((request) => request.business_module),
    ['accommodation_operating', 'business_overview', 'traffic'],
  );
});

test('rejects unsafe origins, undeclared runtime fields, and invalid dates', () => {
  assert.throws(
    () => sampleRecipe({ origin: 'http://www.dingdandao.com' }),
    /web_endpoint_recipe_origin_invalid/,
  );
  assert.throws(
    () => materializeWebEndpointRecipe(sampleRecipe(), {
      provider_network_id: 'network_123',
      target_date: '2026-02-30',
    }),
    /web_endpoint_recipe_binding_value_invalid/,
  );
  assert.throws(
    () => materializeWebEndpointRecipe(sampleRecipe(), {
      provider_network_id: 'network_123',
      target_date: '2026-07-30',
      authorization: 'forbidden',
    }),
    /web_endpoint_recipe_binding_unknown/,
  );
  assert.throws(
    () => sampleRecipe({
      bindings: {
        provider_network_id: { format: 'opaque_id' },
        target_date: { format: 'date' },
        token: { format: 'text' },
      },
    }),
    /web_endpoint_recipe_binding_definition_invalid/,
  );
});

test('enforces a bounded plan with unique recipe ids', () => {
  assert.throws(
    () => materializeWebEndpointPlan(
      [sampleRecipe(), sampleRecipe()],
      {
        provider_network_id: 'network_123',
        target_date: '2026-07-30',
      },
    ),
    /web_endpoint_recipe_id_duplicate/,
  );
  assert.throws(
    () => materializeWebEndpointPlan(
      [sampleRecipe()],
      {
        provider_network_id: 'network_123',
        target_date: '2026-07-30',
      },
      { maxRequests: 0 },
    ),
    /web_endpoint_recipe_plan_limit_exceeded/,
  );
});
