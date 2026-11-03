"""Storage-only history controls: isolated browser fixture, no real account or OTA calls."""
import json
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[2]
source = (ROOT / 'public/app-main.js').read_text(encoding='utf-8')
fragment = (ROOT / 'resources/frontend/templates/fragments/24-page-ctrip-ebooking.html').read_text(encoding='utf-8')


def section(text, start, end):
    offset = text.index(start)
    return text[offset:text.index(end, offset + len(start))]


states = section(source, "const ctripRankingStoredDate = ref('');", '\n            const ctripHeaderRecordCount')
handler = section(source, 'const loadCtripRankingStoredData = async', '\n            const loadSelectedCtripStoredBusinessDate')
controls = section(fragment, '<div data-testid="ctrip-ranking-history-controls"', '<input type="hidden" v-model="ctripForm.nodeId"')
vue = (ROOT / 'node_modules/vue/dist/vue.global.prod.js').read_text(encoding='utf-8')
css = (ROOT / 'public/tailwind.min.css').read_text(encoding='utf-8')
script = r"""
const { createApp, ref } = Vue;
createApp({ setup() {
  const selectedCtripHotelId = ref('229'), fetchingData = ref(false);
  const currentPage = ref('ctrip-ebooking'), onlineDataTab = ref('ctrip-ranking');
  const ctripLatestMeta = ref(null), displayed = ref([]);
  const captureAuthSession = () => 'test-only', isAuthSessionCurrent = () => true;
  const clearCtripRankingDisplayState = () => { displayed.value = []; };
  const request = async path => (await fetch('/api' + path)).json();
  const loadLatestCtripData = async ({hotelId, range}) => {
    const data = await request('/online-data/ctrip/latest?' + new URLSearchParams({hotel_id: hotelId, range}));
    return data.code === 200 ? { payload: data.data } : { payload: null };
  };
  const applyLatestCtripSnapshot = payload => {
    displayed.value = payload.rank.display_hotels;
    ctripLatestMeta.value = payload.metadata;
  };
  __STATES__
  __HANDLER__
  return { selectedCtripHotelId, fetchingData, displayed, ctripLatestMeta,
    ctripRankingStoredDate, ctripRankingHistoryRange, ctripRankingHistoryLoading,
    ctripRankingHistoryMessage, loadCtripRankingStoredData };
}}).mount('#app');
""".replace('__STATES__', states).replace('__HANDLER__', handler)
html = f'''<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>历史读取 · test-only</title>
<style>{css}</style><body style="background:#f4efe5;font-family:Microsoft YaHei,sans-serif;padding:20px">
<main id="app" style="max-width:1100px;margin:auto"><h1 style="font-size:20px;margin-bottom:12px">竞争圈历史读取 · 本地模拟验证</h1>
<div style="padding:12px;background:#ffedd5;margin-bottom:16px">模拟：当前 Cookie 无效；历史记录仍可独立读取</div>
{controls}
<div v-if="ctripLatestMeta" data-testid="fixture-date">业务日期：{{{{ ctripLatestMeta.data_date }}}} · {{{{ ctripLatestMeta.status }}}}</div>
<table style="width:100%;background:white"><tr v-for="row in displayed" data-testid="fixture-row"><td>{{{{ row.hotelName }}}}</td></tr></table>
</main><script>{vue}</script><script>{script}</script></body></html>'''
requests = []


def route_request(route):
    url = urlparse(route.request.url)
    if url.path == '/':
        route.fulfill(content_type='text/html', body=html)
        return
    requests.append((route.request.method, url.path, parse_qs(url.query)))
    if route.request.method != 'GET':
        route.abort()
        raise AssertionError('History view attempted a non-read request')
    if url.path == '/api/online-data/ctrip/history':
        body = {'code': 200, 'data': {'list': [{'system_hotel_id': 229, 'data_date': '2026-09-02'}]}}
    elif url.path == '/api/online-data/ctrip/latest':
        query = parse_qs(url.query)
        assert query.get('hotel_id') == ['229']
        date = query['range'][0]
        rows = [{'hotelName': 'test-only 历史酒店 A'}, {'hotelName': 'test-only 历史酒店 B'}] if date == '2026-09-02' else []
        body = {'code': 200, 'data': {
            'metadata': {'hotel_id': '229', 'data_date': date, 'status': 'source_unverified' if rows else 'empty'},
            'rank': {'data_date': date, 'status': 'success' if rows else 'empty', 'display_hotels': rows},
        }}
    else:
        route.abort()
        raise AssertionError(f'Unexpected request: {url.path}')
    route.fulfill(content_type='application/json', body=json.dumps(body))


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    try:
        page = browser.new_page(viewport={'width': 1280, 'height': 850})
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.route('**/*', route_request)
        page.goto('http://history-ui.test/')
        page.wait_for_load_state('networkidle')
        expect(page.get_by_test_id('ctrip-ranking-history-controls')).to_be_visible()
        expect(page.get_by_test_id('ctrip-ranking-history-read')).to_be_disabled()
        page.get_by_test_id('ctrip-ranking-history-date').fill('2026-09-02')
        page.get_by_test_id('ctrip-ranking-history-read').click()
        expect(page.get_by_test_id('fixture-row')).to_have_count(2)
        expect(page.get_by_test_id('fixture-date')).to_contain_text('2026-09-02 · source_unverified')
        expect(page.get_by_test_id('ctrip-ranking-history-status')).to_contain_text('未重新采集')
        page.get_by_test_id('ctrip-ranking-history-date').fill('2026-09-03')
        page.get_by_test_id('ctrip-ranking-history-read').click()
        expect(page.get_by_test_id('fixture-row')).to_have_count(0)
        expect(page.get_by_test_id('ctrip-ranking-history-status')).to_contain_text('2026-09-03 没有可展示')
        page.get_by_test_id('ctrip-ranking-history-latest').click()
        expect(page.get_by_test_id('fixture-row')).to_have_count(2)
        expect(page.get_by_test_id('ctrip-ranking-history-date')).to_have_value('2026-09-02')
        output = ROOT / 'output/playwright'
        output.mkdir(parents=True, exist_ok=True)
        page.screenshot(path=str(output / 'ctrip-history-controls-desktop.png'), full_page=True)
        page.set_viewport_size({'width': 390, 'height': 844})
        expect(page.get_by_test_id('ctrip-ranking-history-read')).to_be_visible()
        expect(page.get_by_test_id('ctrip-ranking-history-latest')).to_be_visible()
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        page.screenshot(path=str(output / 'ctrip-history-controls-mobile.png'), full_page=True)
        assert not errors, errors
        assert len(requests) == 4, requests
        print(json.dumps({'status': 'PASS', 'evidence': 'test-only browser fixture', 'viewport_widths': [1280, 390],
                          'checks': ['exact-date read', 'empty-date clear', 'latest saved read', 'GET-only', 'source quality retained', 'no horizontal overflow'],
                          'requests': requests, 'page_errors': errors}, ensure_ascii=False))
    finally:
        browser.close()
