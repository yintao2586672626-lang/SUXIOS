import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
async function mount(page, initialTab = 'video', canExecute = true) {
    await page.route('http://localhost/**', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><meta charset="utf-8"><div id="app"></div>' }));
    await page.goto('http://localhost/');
    await page.addScriptTag({ path: path.join(root, 'public/vue.runtime.global.prod.js') });
    await page.addStyleTag({ path: path.join(root, 'public/tailwind.min.css') });
    for (const name of ['campaign-local-media.js', 'campaign-marketing-weekly.js', 'campaign-operations-panel.js']) await page.addScriptTag({ path: path.join(root, 'public/components/system', name) });
    await page.evaluate(({ initialTab, canExecute }) => {
        window.rows = []; window.requests = [];
        const request = async (url, options = {}) => {
            const u = new URL(url, location.origin); window.requests.push({ url, body: options.body });
            const hotel = Number(u.searchParams.get('hotel_id') || 11);
            if (u.pathname.endsWith('/overview')) return { code: 200, data: { hotel_id: hotel, business_date: u.searchParams.get('business_date'), total: window.rows.length, data_status: 'unverified', records: window.rows.filter(row => row.hotel_id === hotel), previous_handover: null } };
            if (u.pathname.endsWith('/weekly')) {
                if (window.failWeekly) return { code: 503, message: 'synthetic 周榜读取失败：没有确认覆盖' };
                const ids = u.searchParams.get('hotel_ids').split(',').map(Number).sort((a, b) => a - b), start = u.searchParams.get('week_start');
                const dates = Array.from({ length: 7 }, (_, i) => { const date = new Date(`${start}T00:00:00Z`); date.setUTCDate(date.getUTCDate() + i); return date.toISOString().slice(0, 10); });
                const rules = window.rows.filter(row => row.kind === 'marketing_score_rule' && ids.includes(row.hotel_id));
                const rule = rules.find(row => row.id === Number(u.searchParams.get('rule_id'))) || null;
                return { code: 200, data: { schema_version: 'campaign_marketing_weekly.v1', tenant_id: 101, hotel_ids: ids, platform: 'douyin', week_start: start, week_end: dates[6], metric_as_of_date: dates[6], dates, available_rules: rules, rule, boundary: 'synthetic 人工抖音参考记录', rows: ids.map(hotel_id => ({ hotel_id, coverage_days: 0, totals: { posts: null, views: null, likes: null, reposts: null }, score: null, rank: null, eligible_for_rank: false, exclusion_reasons: ['synthetic 7日覆盖缺失'], daily_coverage: dates.map(business_date => ({ business_date, status: 'missing', source_label: null, expected_posts: null, saved_posts: 0, coverage_record_id: null })) })) } };
            }
            if (options.method === 'POST') {
                const input = JSON.parse(options.body);
                const record = { id: window.rows.length + 1, kind: input.kind, hotel_id: input.hotel_id, tenant_id: 101, source_hotel_id: input.hotel_id, business_date: input.business_date, source_label: input.source_label, record_key: input.record_key, schema_version: 'campaign_operations.v1', version_no: 1, payload: structuredClone(input.payload), data_status: 'unverified' };
                window.rows.push(record);
                return { code: 200, data: { request_status: 'saved_and_readback_verified', record, reused: false } };
            }
            const record = window.rows.find(row => row.id === Number(/records\/(\d+)/.exec(url)?.[1]) && row.hotel_id === hotel);
            return record ? { code: 200, data: record } : { code: 404, message: 'synthetic 记录不在酒店范围' };
        };
        window.app = Vue.createApp({ render: () => Vue.h(window.SUXI_SYSTEM_COMPONENTS.CampaignOperationsPanel, { ref: 'panel', selectedHotelId: 11, canExecute, initialTab, request, hotels: [{ id: 11, name: 'synthetic 酒店A' }, { id: 13, name: 'synthetic 酒店B' }] }) }).mount('#app');
    }, { initialTab, canExecute });
    await page.waitForFunction(() => window.app.$refs.panel.overview?.hotel_id === 11);
}

function musicWav() {
    const samples = 22050, buffer = Buffer.alloc(44 + samples * 2);
    buffer.write('RIFF', 0); buffer.writeUInt32LE(buffer.length - 8, 4); buffer.write('WAVEfmt ', 8); buffer.writeUInt32LE(16, 16);
    buffer.writeUInt16LE(1, 20); buffer.writeUInt16LE(1, 22); buffer.writeUInt32LE(22050, 24); buffer.writeUInt32LE(44100, 28); buffer.writeUInt16LE(2, 32); buffer.writeUInt16LE(16, 34); buffer.write('data', 36); buffer.writeUInt32LE(samples * 2, 40);
    for (let i = 0; i < samples; i++) buffer.writeInt16LE(Math.round(Math.sin(i / 22050 * Math.PI * 2 * 440) * 10000), 44 + i * 2);
    return buffer;
}

test('synthetic local photo/video/music are saved as manifests and exported to playable audiovisual WebM', { timeout: 45000 }, async () => {
    const browser = await chromium.launch({ headless: true }); const temp = await fs.mkdtemp(path.join(os.tmpdir(), 'campaign-local-media-'));
    try {
        const page = await browser.newPage({ acceptDownloads: true, viewport: { width: 1440, height: 1000 } }); await mount(page);
        const visual = await page.evaluate(async () => {
            const canvas = document.createElement('canvas'); canvas.width = 640; canvas.height = 360;
            const ctx = canvas.getContext('2d'); ctx.fillStyle = '#b7193a'; ctx.fillRect(0, 0, 640, 360);
            const image = canvas.toDataURL('image/png').split(',')[1];
            const stream = canvas.captureStream(24), recorder = new MediaRecorder(stream, { mimeType: 'video/webm' }), chunks = [];
            const finished = new Promise(resolve => { recorder.ondataavailable = event => chunks.push(event.data); recorder.onstop = resolve; });
            recorder.start(100); const animation = setInterval(() => { ctx.fillStyle = '#249bc0'; ctx.fillRect(0, 0, 640, 360); }, 40);
            await new Promise(resolve => setTimeout(resolve, 750)); recorder.stop(); await finished; clearInterval(animation); stream.getTracks().forEach(track => track.stop());
            const bytes = new Uint8Array(await new Blob(chunks).arrayBuffer()); let binary = ''; for (const byte of bytes) binary += String.fromCharCode(byte);
            return { image, video: btoa(binary) };
        });
        const files = [{ name: 'synthetic-hotel.png', mimeType: 'image/png', buffer: Buffer.from(visual.image, 'base64') }, { name: 'synthetic-room.webm', mimeType: 'video/webm', buffer: Buffer.from(visual.video, 'base64') }, { name: 'synthetic-music.wav', mimeType: 'audio/wav', buffer: musicWav() }];
        await page.locator('input[name="source_label"]').fill('synthetic 本地测试素材'); await page.locator('input[name="hotel_name"]').fill('synthetic 酒店');
        await page.locator('input[name="title"]').fill('synthetic 影像'); await page.locator('textarea[name="copy"]').fill('synthetic 测试作品，不代表真实经营素材');
        await page.locator('textarea[name="material_notes"]').fill('synthetic 自制颜色画面和测试音调，仅用于编码验收'); await page.locator('input[name="duration_seconds"]').fill('3');
        await page.locator('input[type="file"]').setInputFiles(files);
        await page.waitForFunction(() => window.app.$refs.panel.form.payload.local_media_manifest.length === 3 && !window.app.$refs.panel.busy);
        await page.getByRole('button', { name: '保存并回读', exact: true }).click(); await page.waitForFunction(() => window.rows.length === 1 && !window.app.$refs.panel.busy);
        const manifest = await page.evaluate(() => window.rows[0].payload.local_media_manifest);
        assert.equal(manifest[2].kind, 'audio'); assert.match(manifest[0].sha256, /^[a-f0-9]{64}$/); assert.equal(JSON.stringify(manifest).includes('base64'), false);
        const download = page.waitForEvent('download'); await page.getByRole('button', { name: '生成并下载WebM', exact: true }).click();
        const product = await download; const output = path.join(temp, 'product.webm'); await product.saveAs(output); const bytes = await fs.readFile(output); assert.ok(bytes.length > 20000);
        const decoded = await page.evaluate(async bytes => {
            const buffer = new Uint8Array(bytes).buffer, blob = new Blob([buffer], { type: 'video/webm' }), video = document.createElement('video'); video.src = URL.createObjectURL(blob); video.muted = true;
            await new Promise((resolve, reject) => { video.onloadeddata = resolve; video.onerror = () => reject(new Error('Synthetic product cannot decode')); });
            await video.play(); await new Promise(resolve => setTimeout(resolve, 250));
            const stream = video.captureStream(), canvas = document.createElement('canvas'); canvas.width = 1280; canvas.height = 720; const ctx = canvas.getContext('2d'); ctx.drawImage(video, 0, 0);
            const imagePixel = [...ctx.getImageData(640, 350, 1, 1).data]; video.currentTime = 2.1;
            await new Promise(resolve => { video.onseeked = resolve; }); ctx.drawImage(video, 0, 0); const videoPixel = [...ctx.getImageData(640, 350, 1, 1).data];
            const audio = new AudioContext(); const sample = await audio.decodeAudioData(buffer.slice(0)); let peak = 0; for (const value of sample.getChannelData(0)) peak = Math.max(peak, Math.abs(value)); await audio.close();
            const result = { width: video.videoWidth, height: video.videoHeight, audioTracks: stream.getAudioTracks().length, peak, imagePixel, videoPixel }; video.pause(); stream.getTracks().forEach(track => track.stop()); URL.revokeObjectURL(video.src); return result;
        }, [...bytes]);
        assert.equal(decoded.width, 1280); assert.equal(decoded.height, 720); assert.equal(decoded.audioTracks, 1); assert.ok(decoded.peak > 0.05);
        assert.ok(decoded.imagePixel[0] > decoded.imagePixel[1] * 2, 'photo scene is present'); assert.ok(decoded.videoPixel[2] > decoded.videoPixel[0] * 2, 'video scene is present');
        await page.locator('input[type="file"]').setInputFiles([{ ...files[0], name: 'different.png' }, ...files.slice(1)]);
        await page.waitForFunction(() => !window.app.$refs.panel.busy); await page.getByRole('button', { name: '生成并下载WebM', exact: true }).click();
        await page.getByRole('alert').filter({ hasText: '当前文件与保存制作单的素材摘要不匹配' }).waitFor();
        await page.setViewportSize({ width: 320, height: 900 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        console.log(JSON.stringify({ evidence: 'synthetic isolated browser fixtures; actual local encoding and decoding', bytes: bytes.length, manifestEntries: manifest.length, ...decoded }));
    } finally { await browser.close(); await fs.rm(temp, { recursive: true, force: true }); }
});

test('synthetic view-only account reselects matching local media and exports without server writes', { timeout: 15000 }, async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ acceptDownloads: true }); await mount(page, 'video', false);
        const image = await page.evaluate(async () => {
            const canvas = document.createElement('canvas'); canvas.width = 640; canvas.height = 360;
            const ctx = canvas.getContext('2d'); ctx.fillStyle = '#315f46'; ctx.fillRect(0, 0, 640, 360);
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
            const file = new File([blob], 'synthetic-view-only.png', { type: 'image/png' });
            const manifest = await SUXI_CAMPAIGN_MEDIA.describeFiles([file]);
            const panel = window.app.$refs.panel;
            window.rows = [{ id: 1, kind: 'video_brief', hotel_id: 11, tenant_id: 101, source_hotel_id: 11,
                business_date: panel.businessDate, source_label: 'synthetic 已存制作单', record_key: 'synthetic_view_only', schema_version: 'campaign_operations.v1', version_no: 1, data_status: 'unverified',
                payload: { hotel_name: 'synthetic 酒店', title: 'synthetic 查看导出', copy: 'synthetic 只读测试', brand_color: '#143a31', material_notes: 'synthetic 自制颜色照片', duration_seconds: 3, brand_review_status: 'pending_review', material_review_status: 'pending_review', local_media_manifest: manifest } }];
            await panel.load(); return canvas.toDataURL('image/png').split(',')[1];
        });
        await page.getByRole('button', { name: '精确回读', exact: true }).click();
        await page.waitForFunction(() => window.app.$refs.panel.form.expected_id === 1);
        assert.equal(await page.locator('input[type="file"]').isEnabled(), true);
        assert.equal(await page.getByRole('button', { name: '保存并回读', exact: true }).isDisabled(), true);
        await page.locator('input[type="file"]').setInputFiles({ name: 'synthetic-view-only.png', mimeType: 'image/png', buffer: Buffer.from(image, 'base64') });
        await page.waitForFunction(() => window.app.$refs.panel.mediaFiles.length === 1 && !window.app.$refs.panel.busy);
        await page.evaluate(() => window.app.$refs.panel.save());
        const downloaded = page.waitForEvent('download'); await page.getByRole('button', { name: '生成并下载WebM', exact: true }).click();
        const product = await downloaded, stream = await product.createReadStream(), chunks = []; for await (const chunk of stream) chunks.push(chunk);
        const bytes = Buffer.concat(chunks); assert.ok(bytes.length > 1000); assert.equal(bytes.subarray(0, 4).toString('hex'), '1a45dfa3');
        const size = await page.evaluate(async bytes => {
            const video = document.createElement('video'); video.src = URL.createObjectURL(new Blob([new Uint8Array(bytes)], { type: 'video/webm' }));
            await new Promise((resolve, reject) => { video.onloadeddata = resolve; video.onerror = reject; });
            const result = [video.videoWidth, video.videoHeight]; URL.revokeObjectURL(video.src); return result;
        }, [...bytes]);
        assert.deepEqual(size, [1280, 720]); assert.equal(await page.evaluate(() => window.requests.filter(row => row.body).length), 0);
        assert.equal(await page.evaluate(() => window.rows.length), 1);
        console.log(JSON.stringify({ evidence: 'synthetic view-only browser', localWebmBytes: bytes.length, dimensions: size, serverWrites: 0 }));
    } finally { await browser.close(); }
});

test('synthetic weekly UI keeps missing days unknown, persists explicit rule and clears scope/error states', { timeout: 20000 }, async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } }); await mount(page, 'campaign');
        const weekly = page.getByTestId('campaign-marketing-weekly'); await weekly.getByLabel('周一', { exact: true }).fill('2026-09-28');
        await weekly.getByRole('button', { name: '读取7日覆盖与周榜', exact: true }).click();
        await weekly.getByText('synthetic 酒店A · 未入榜：synthetic 7日覆盖缺失', { exact: true }).waitFor();
        assert.match(await weekly.locator('tbody').innerText(), /0\/7\s+未知\s+未知\s+未知\s+未知\s+未知\s+未入榜/);
        await weekly.locator('details').last().locator('summary').click();
        await weekly.getByLabel('评分规则名称', { exact: true }).fill('synthetic 显式规则'); await weekly.getByLabel('统计口径与核对说明', { exact: true }).fill('synthetic 周日累计统计'); await weekly.getByLabel('规则来源说明', { exact: true }).fill('synthetic 手工确认');
        for (const label of ['作品数', '播放量', '点赞量', '转发量']) { await weekly.getByLabel(`${label}权重（0至100）`, { exact: true }).fill('25'); await weekly.getByLabel(`${label}周目标（正整数）`, { exact: true }).fill('10'); }
        await weekly.getByRole('button', { name: '保存并回读评分规则', exact: true }).click();
        await weekly.getByRole('status').filter({ hasText: '评分规则已保存并回读' }).waitFor();
        const saved = await page.evaluate(() => window.rows[0]); assert.equal(saved.kind, 'marketing_score_rule'); assert.equal(saved.payload.weights.reposts, '25');
        await weekly.getByRole('button', { name: '读取7日覆盖与周榜', exact: true }).click(); await weekly.getByText(/规则 synthetic 显式规则 #1\/v1/).waitFor();
        await page.evaluate(() => { window.failWeekly = true; }); await weekly.getByRole('button', { name: '读取7日覆盖与周榜', exact: true }).click();
        await weekly.getByRole('alert').filter({ hasText: 'synthetic 周榜读取失败' }).waitFor(); assert.equal(await weekly.locator('tbody').count(), 0);
        await weekly.getByLabel('覆盖核对说明', { exact: true }).fill('synthetic A草稿');
        await page.locator('header select').selectOption('13'); await page.waitForFunction(() => window.app.$refs.panel.overview?.hotel_id === 13);
        await weekly.locator('details').last().locator('summary').click(); assert.equal(await weekly.getByLabel('覆盖核对说明', { exact: true }).inputValue(), ''); assert.equal(await weekly.getByLabel('评分规则名称', { exact: true }).inputValue(), '');
        await page.setViewportSize({ width: 320, height: 900 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    } finally { await browser.close(); }
});

test('synthetic local rendering rejects unreadable files, mismatched manifests and cancelled work', { timeout: 10000 }, async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage(); await mount(page);
        const failures = await page.evaluate(async () => {
            const file = new File(['synthetic invalid image'], 'synthetic-broken.png', { type: 'image/png' });
            const manifest = await SUXI_CAMPAIGN_MEDIA.describeFiles([file]);
            const record = { id: 1, kind: 'video_brief', hotel_id: 11, business_date: '2026-10-04', version_no: 1, source_label: 'synthetic', payload: { title: 'synthetic', hotel_name: 'synthetic', copy: 'synthetic', brand_color: '#143a31', duration_seconds: 3, local_media_manifest: manifest } };
            const errors = [];
            for (const options of [{ files: [file] }, { files: [] }, { files: [file], signal: AbortSignal.abort() }]) {
                try { await SUXI_CAMPAIGN_MEDIA.renderWebm(record, options); errors.push('unexpected success'); } catch (error) { errors.push(error.message); }
            }
            return errors;
        });
        assert.match(failures[0], /无法解码/); assert.match(failures[1], /素材摘要不匹配/); assert.match(failures[2], /取消/);
    } finally { await browser.close(); }
});

test('synthetic mid-play cancellation settles promptly and releases recorder, media and tracks', { timeout: 10000 }, async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage(); await mount(page);
        const result = await page.evaluate(async () => {
            // A real decodable local clip; only its pre-play promise is delayed to control the cancellation boundary.
            const canvas = document.createElement('canvas'); canvas.width = 32; canvas.height = 32; canvas.getContext('2d').fillRect(0, 0, 32, 32);
            const fixtureStream = canvas.captureStream(24), fixtureRecorder = new MediaRecorder(fixtureStream, { mimeType: 'video/webm' }), chunks = [];
            const fixtureDone = new Promise(resolve => { fixtureRecorder.ondataavailable = event => chunks.push(event.data); fixtureRecorder.onstop = resolve; });
            fixtureRecorder.start(50); await new Promise(resolve => setTimeout(resolve, 200)); fixtureRecorder.stop(); await fixtureDone;
            fixtureStream.getTracks().forEach(track => track.stop());
            const file = new File([new Blob(chunks)], 'synthetic-mid-play.webm', { type: 'video/webm' });
            const manifest = await SUXI_CAMPAIGN_MEDIA.describeFiles([file]);
            const original = { Recorder: MediaRecorder, play: HTMLMediaElement.prototype.play, capture: HTMLCanvasElement.prototype.captureStream, create: document.createElement, url: URL.createObjectURL, revoke: URL.revokeObjectURL };
            const recorders = [], streams = [], media = [], urls = [], revoked = []; let releasePlay;
            try {
                window.MediaRecorder = class extends original.Recorder { constructor(...args) { super(...args); recorders.push(this); } };
                HTMLCanvasElement.prototype.captureStream = function (...args) { const stream = original.capture.apply(this, args); streams.push(stream); return stream; };
                document.createElement = function (...args) { const element = original.create.apply(this, args); if (['video', 'audio'].includes(args[0])) media.push(element); return element; };
                URL.createObjectURL = function (...args) { const url = original.url.apply(this, args); urls.push(url); return url; };
                URL.revokeObjectURL = function (url) { revoked.push(url); return original.revoke.call(this, url); };
                HTMLMediaElement.prototype.play = function () { return new Promise(resolve => { releasePlay = resolve; }); };
                const controller = new AbortController();
                const record = { id: 1, kind: 'video_brief', hotel_id: 11, version_no: 1, business_date: '2026-10-04', source_label: 'synthetic', payload: { hotel_name: 'synthetic', title: 'synthetic', copy: 'synthetic', brand_color: '#143a31', duration_seconds: 3, local_media_manifest: manifest } };
                const rendering = SUXI_CAMPAIGN_MEDIA.renderWebm(record, { files: [file], signal: controller.signal }).then(() => ({ status: 'unexpected_success' }), error => ({ status: 'rejected', message: error.message }));
                for (let i = 0; i < 100 && !releasePlay; i++) await new Promise(resolve => setTimeout(resolve, 5));
                if (!releasePlay) throw new Error('Synthetic pre-play boundary was not reached');
                controller.abort();
                const outcome = await Promise.race([rendering, new Promise(resolve => setTimeout(() => resolve({ status: 'timeout' }), 500))]);
                releasePlay(); await new Promise(resolve => setTimeout(resolve, 50));
                return { ...outcome, recorders: recorders.map(recorder => recorder.state), tracks: streams.flatMap(stream => stream.getTracks().map(track => track.readyState)), mediaReleased: media.every(element => element.paused && !element.hasAttribute('src')), urls: urls.length, revoked: revoked.length };
            } finally {
                window.MediaRecorder = original.Recorder; HTMLMediaElement.prototype.play = original.play; HTMLCanvasElement.prototype.captureStream = original.capture;
                document.createElement = original.create; URL.createObjectURL = original.url; URL.revokeObjectURL = original.revoke;
            }
        });
        assert.equal(result.status, 'rejected'); assert.match(result.message, /取消/); assert.deepEqual(result.recorders, ['inactive']);
        assert.ok(result.tracks.length > 0); assert.ok(result.tracks.every(state => state === 'ended')); assert.equal(result.mediaReleased, true); assert.ok(result.urls > 0); assert.equal(result.revoked, result.urls);
        console.log(JSON.stringify({ evidence: 'synthetic controlled mid-play cancellation', ...result }));
    } finally { await browser.close(); }
});
