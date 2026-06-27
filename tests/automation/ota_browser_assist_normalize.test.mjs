import assert from 'node:assert/strict';
import test from 'node:test';

import { normalizeBrowserAssistCapturePayload } from '../../scripts/lib/ota_browser_assist_normalize.mjs';

test('normalizes browser assist OTA capture into data-import packages', () => {
  const result = normalizeBrowserAssistCapturePayload({
    ctrip: {
      url: 'https://ebooking.ctrip.com/ebkovsroom/inventory/calendar?token=secret',
      source: '携程',
      type: 'ctrip',
      hotelName: '测试酒店',
      rooms: [
        {
          name: '大床房',
          days: [
            {
              date: '2026-06-27',
              state: '开房',
              remain: '剩余3',
              sold: 2,
              limitType: '限量',
            },
          ],
        },
      ],
    },
    ctripStats: {
      url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?token=secret',
      updatedAt: '2026-06-27 10:20:00',
      metrics: {
        ctrip: {
          realtimeVisitors: { label: '实时访客', value: '128' },
          visitorPeerAvg: { label: '同行均值', value: '96' },
          orderConversionRate: { label: '订单转化率', value: '4.5%' },
          realtimeRank: { label: '实时排名', value: '12' },
        },
        qunar: {
          realtimeVisitors: { label: '实时访客', value: '56' },
          orderConversionRate: { label: '订单转化率', value: '3.2%' },
        },
      },
    },
    meituanStats: {
      url: 'https://eb.meituan.com/newhb-sub-app/data-center-pc/home/index.html?token=secret',
      updatedAt: '2026-06-27 10:25:00',
      metrics: {
        exposureUsers: { label: '曝光人数', value: '1000' },
        browseUsers: { label: '浏览人数', value: '150' },
        paidOrders: { label: '支付订单数', value: '8' },
        exposureBrowseRate: { label: '曝光浏览率', value: '15%' },
        browsePayRate: { label: '浏览支付率', value: '5.3%' },
      },
    },
  }, {
    systemHotelId: 58,
    generatedAt: '2026-06-27 10:30:00',
  });

  assert.equal(result.source_contract, 'ota_browser_assist_collection_contract.v1');
  assert.equal(result.summary.row_count, 5);
  assert.deepEqual(
    result.packages.map((item) => `${item.platform}:${item.data_type}`).sort(),
    ['ctrip:inventory', 'ctrip:peer_rank', 'ctrip:traffic', 'meituan:traffic'],
  );
  assert.equal(JSON.stringify(result).includes('https://'), false);

  for (const item of result.packages) {
    assert.equal(item.import_endpoint, '/api/online-data/data-import');
    assert.equal(item.system_hotel_id, 58);
    assert.deepEqual([...new Set(item.rows.map((row) => row.data_type))], [item.data_type]);
  }

  const ctripInventory = result.rows.find((row) => row.source === 'ctrip' && row.data_type === 'inventory');
  assert.equal(ctripInventory.dimension, '大床房');
  assert.equal(ctripInventory.inventory_remaining, 3);
  assert.equal(ctripInventory.raw_data.inventory.remain, 3);
  assert.equal(ctripInventory.raw_data.field_facts.find((fact) => fact.metric_key === 'room_inventory_remaining').status, 'captured');
  assert.match(ctripInventory.source_trace_id, /^ctrip:[a-f0-9]{64}$/);

  const qunarTraffic = result.rows.find((row) => row.source === 'ctrip' && row.data_type === 'traffic' && row.dimension === 'realtime:qunar');
  assert.equal(qunarTraffic.platform, 'ctrip');
  assert.equal(qunarTraffic.detail_exposure, 56);
  assert.equal(qunarTraffic.flow_rate, 3.2);

  const ctripRank = result.rows.find((row) => row.source === 'ctrip' && row.data_type === 'peer_rank');
  assert.equal(ctripRank.rank, 12);
  assert.equal(ctripRank.compare_type, 'channel_realtime_rank');

  const meituanTraffic = result.rows.find((row) => row.source === 'meituan' && row.data_type === 'traffic');
  assert.equal(meituanTraffic.list_exposure, 1000);
  assert.equal(meituanTraffic.detail_exposure, 150);
  assert.equal(meituanTraffic.order_submit_num, 8);
  assert.equal(meituanTraffic.flow_rate, 5.3);
});

test('keeps missing browser assist fields explicit instead of inventing values', () => {
  const result = normalizeBrowserAssistCapturePayload({
    ctrip: {
      rooms: [
        {
          days: [
            {
              date: '2026/06/27',
              state: '关房',
            },
          ],
        },
      ],
    },
  }, {
    generatedAt: '2026-06-27 10:30:00',
  });

  assert.equal(result.summary.row_count, 1);
  const row = result.rows[0];
  assert.equal(row.dimension, 'room_index:0');
  assert.equal(Object.hasOwn(row, 'inventory_remaining'), false);
  assert.deepEqual(row.raw_data.missing_fields, [
    { field: 'room_name', missing_state: 'field_missing' },
    { field: 'remain', missing_state: 'field_missing' },
  ]);
  assert.equal(
    row.raw_data.field_facts.find((fact) => fact.metric_key === 'room_inventory_remaining').missing_state,
    'field_missing',
  );
});
