import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/meituan-static.js', 'utf8');
const onlineDataQualityConcern = readFileSync('app/controller/concern/OnlineDataQualityConcern.php', 'utf8');
const sandbox = { console, window: {} };
vm.runInNewContext(`${source}\nthis.__api = window.SUXI_MEITUAN_STATIC;`, sandbox);
const api = sandbox.__api;

test('Meituan stored-data download keeps each data type in its own tab', () => {
  const rows = [
    { id: 1, source: 'meituan', data_type: 'peer_rank', hotel_name: '同行 A', data_date: '2026-07-14', data_value: 800, rank: 1, rank_percent: 100 },
    { id: 2, source: 'meituan', data_type: 'order_flow', data_value: 99999, amount: 99999 },
    { id: 3, source: 'meituan', data_type: 'business', data_value: 77777 },
    { id: 4, source: 'meituan', data_type: 'traffic', list_exposure: 0, detail_exposure: 0, flow_rate: 0 },
    { id: 5, source: 'meituan', data_type: 'traffic_analysis', list_exposure: 100, detail_exposure: 20 },
    { id: 6, source: 'meituan', data_type: 'order', book_order_num: 0, quantity: 0, amount: 0 },
    { id: 7, source: 'meituan', data_type: 'advertising', exposure_count: 10, click_count: 0 },
    { id: 8, source: 'ctrip', data_type: 'traffic', list_exposure: 5000, detail_exposure: 1000 },
  ];

  const result = api.buildMeituanDownloadData(rows);

  assert.deepEqual(Array.from(result.allRows, row => row.id), [1, 2, 3, 4, 5, 6, 7]);
  assert.equal(result.allRowsCount, 7);
  assert.deepEqual(Array.from(result.overviewRows, row => row.id), [1]);
  assert.deepEqual(Array.from(result.trafficRows, row => row.id), [4, 5]);
  assert.deepEqual(Array.from(result.orderRows, row => row.id), [6]);
  assert.deepEqual(Array.from(result.adsRows, row => row.id), [7]);
  assert.equal(result.trafficExposure, 100);
  assert.equal(result.trafficClick, 20);
  assert.equal(result.trafficAvgFlowRate, 10);
  assert.equal(result.orderBookOrder, 0);
  assert.equal(result.orderQuantity, 0);
  assert.equal(result.orderAmount, 0);
  assert.equal(result.adsExposure, 10);
  assert.equal(result.adsClick, 0);
  assert.equal(result.adsClickRate, 0);
});

test('generic data_value never becomes Meituan traffic and missing metrics stay missing', () => {
  const peerRank = { source: 'meituan', data_type: 'peer_rank', data_value: 12345 };
  const orderFlow = { source: 'meituan', data_type: 'order_flow', data_value: 67890 };

  assert.equal(api.isMeituanTrafficDataRow(peerRank), false);
  assert.equal(api.isMeituanTrafficDataRow(orderFlow), false);
  assert.equal(api.hasMeituanExposureMetric(peerRank), false);

  const result = api.buildMeituanDownloadData([
    { source: 'meituan', data_type: 'traffic_analysis' },
    { source: 'meituan', data_type: 'order' },
    { source: 'meituan', data_type: 'advertising' },
  ]);
  assert.equal(result.trafficExposure, null);
  assert.equal(result.trafficClick, null);
  assert.equal(result.trafficAvgFlowRate, null);
  assert.equal(result.trafficClickRate, null);
  assert.equal(result.orderBookOrder, null);
  assert.equal(result.orderQuantity, null);
  assert.equal(result.orderAmount, null);
  assert.equal(result.adsExposure, null);
  assert.equal(result.adsClick, null);
  assert.equal(result.adsClickRate, null);
});

test('daily data API displays the bound system hotel name and preserves the captured source label', () => {
  assert.match(onlineDataQualityConcern, /Db::name\('hotels'\)->whereIn\('id', \$systemHotelIds\)->column\('name', 'id'\)/);
  assert.match(onlineDataQualityConcern, /\$item\['captured_hotel_name'\] = \$capturedHotelName/);
  assert.match(onlineDataQualityConcern, /\$item\['system_hotel_name'\] = \$systemHotelName/);
  assert.match(onlineDataQualityConcern, /\$item\['hotel_name'\] = \$systemHotelName/);
});
