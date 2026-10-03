(() => {
    'use strict';
    const allowed = { image: ['image/jpeg', 'image/png', 'image/webp'], video: ['video/mp4', 'video/webm'], audio: ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/mp4', 'audio/webm'] };
    const split = (text, width) => String(text || '').split('\n').flatMap(line => { const chars = [...line], out = []; do { out.push(chars.splice(0, width).join('')); } while (chars.length); return out; });
    const ink = color => {
        const rgb = color.slice(1).match(/../g).map(part => { const s = parseInt(part, 16) / 255; return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; });
        return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722 > 0.179 ? '#1c3028' : '#ffffff';
    };
    const describeFiles = async files => {
        if (!Array.isArray(files) || files.length > 21) throw new Error('最多选择20个画面和1个音乐文件');
        let visual = 0, audio = 0, bytes = 0;
        const manifest = [];
        for (const file of files) {
            const kind = Object.keys(allowed).find(key => allowed[key].includes(file.type));
            if (!kind || !file.size || file.size > 200 * 1024 * 1024) throw new Error('素材格式不支持、为空或单文件超过200MB');
            bytes += file.size; kind === 'audio' ? audio++ : visual++;
            if (visual > 20 || audio > 1 || bytes > 300 * 1024 * 1024) throw new Error('最多20个画面和1个音乐，总计300MB');
            const hash = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
            manifest.push({ name: file.name, kind, mime_type: file.type, size_bytes: file.size, sha256: [...new Uint8Array(hash)].map(value => value.toString(16).padStart(2, '0')).join('') });
        }
        if (audio && !visual) throw new Error('音乐须配合酒店照片或视频');
        return manifest;
    };
    const matchFiles = async (saved, files) => {
        const actual = await describeFiles(files);
        if (saved.length !== actual.length || saved.some((row, i) => ['name', 'kind', 'mime_type', 'size_bytes', 'sha256'].some(key => row[key] !== actual[i][key]))) throw new Error('当前文件与保存制作单的素材摘要不匹配；重新选择原素材，或保存新的制作单版本');
        return actual;
    };
    const waitForMedia = (work, signal) => {
        if (!signal) return Promise.resolve(work);
        return new Promise((resolve, reject) => {
            const cancel = () => { signal.removeEventListener('abort', cancel); reject(new Error('酒店、日期或页面已切换，视频生成已取消')); };
            if (signal.aborted) cancel(); else signal.addEventListener('abort', cancel, { once: true });
            Promise.resolve(work).then(value => { signal.removeEventListener('abort', cancel); resolve(value); }, error => { signal.removeEventListener('abort', cancel); reject(error); });
        });
    };
    const openMedia = (file, kind, signal) => new Promise((resolve, reject) => {
        const element = document.createElement(kind === 'image' ? 'img' : kind === 'audio' ? 'audio' : 'video');
        const url = URL.createObjectURL(file); element.src = url;
        if (kind !== 'image') { element.preload = 'auto'; element.playsInline = true; element.loop = true; }
        const clean = () => { element.onload = element.onloadeddata = element.onerror = null; signal?.removeEventListener('abort', abort); clearTimeout(timer); };
        const fail = message => { clean(); URL.revokeObjectURL(url); reject(new Error(message)); };
        const abort = () => fail('本地素材读取已取消');
        const ready = () => { clean(); resolve({ element, url, kind }); };
        const timer = setTimeout(() => fail(`素材 ${file.name} 无法解码或读取超时`), 15000);
        element.onerror = () => fail(`素材 ${file.name} 无法解码，请使用浏览器支持的格式`);
        if (kind === 'image') element.onload = ready; else element.onloadeddata = ready;
        signal?.addEventListener('abort', abort, { once: true });
        if (signal?.aborted) abort();
    });
    const renderWebm = async (record, { signal, onProgress, files = [] } = {}) => {
        if (record?.kind !== 'video_brief' || !record?.id || !record?.payload) throw new Error('先保存并精确回读视频制作单');
        if (!window.MediaRecorder || !HTMLCanvasElement.prototype.captureStream) throw new Error('当前浏览器不支持本地WebM，请使用支持MediaRecorder和Canvas captureStream的Chromium浏览器');
        const mime = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'].find(value => MediaRecorder.isTypeSupported(value));
        if (!mime) throw new Error('当前浏览器没有可用的WebM编码器');
        const p = record.payload, manifest = p.local_media_manifest || [];
        const duration = Number(p.duration_seconds) * 1000;
        if (!Number.isFinite(duration) || duration < 3000 || duration > 30000) throw new Error('保存的制作单视频时长无效');
        if (manifest.length) await matchFiles(manifest, files);
        const canvas = document.createElement('canvas'); canvas.width = 1280; canvas.height = 720;
        const ctx = canvas.getContext('2d'); if (!ctx) throw new Error('浏览器无法创建视频画面');
        const opened = []; let stream, recorder, audioContext, animation = 0, abortListener;
        try {
            if (signal?.aborted) throw new Error('视频生成已取消');
            for (let i = 0; i < manifest.length; i++) opened.push(await openMedia(files[i], manifest[i].kind, signal));
            const visuals = opened.filter(media => media.kind !== 'audio');
            const music = opened.find(media => media.kind === 'audio');
            stream = canvas.captureStream(24);
            if (music) {
                const Audio = window.AudioContext || window.webkitAudioContext;
                if (!Audio) throw new Error('当前浏览器无法混入音乐，请移除音乐并保存新版本后导出');
                audioContext = new Audio(); await waitForMedia(audioContext.resume(), signal);
                if (audioContext.state !== 'running') throw new Error('音乐播放未获浏览器允许，请再次点击生成');
                const output = audioContext.createMediaStreamDestination();
                audioContext.createMediaElementSource(music.element).connect(output);
                output.stream.getAudioTracks().forEach(track => stream.addTrack(track));
                await waitForMedia(music.element.play(), signal);
            }
            visuals.filter(media => media.kind === 'video').forEach(media => { media.element.muted = true; });
            let currentVisual = -1;
            const lines = split(p.copy, 38), pages = Math.max(1, Math.ceil(lines.length / 7));
            const draw = progress => {
                ctx.fillStyle = '#f4f6f4'; ctx.fillRect(0, 0, 1280, 720);
                if (visuals.length) {
                    const index = Math.min(visuals.length - 1, Math.floor(progress * visuals.length)), media = visuals[index];
                    if (index !== currentVisual) {
                        if (currentVisual >= 0 && visuals[currentVisual].kind === 'video') visuals[currentVisual].element.pause();
                        currentVisual = index;
                        if (media.kind === 'video') media.element.play().catch(() => { playbackFailed = true; if (recorder?.state !== 'inactive' && recorder) recorder.stop(); });
                    }
                    const width = media.element.naturalWidth || media.element.videoWidth, height = media.element.naturalHeight || media.element.videoHeight;
                    const scale = Math.max(1280 / width, 720 / height);
                    ctx.drawImage(media.element, (1280 - width * scale) / 2, (720 - height * scale) / 2, width * scale, height * scale);
                    ctx.fillStyle = 'rgba(0,0,0,0.65)'; ctx.fillRect(0, 0, 1280, 170); ctx.fillRect(0, 570, 1280, 150);
                    ctx.fillStyle = '#ffffff'; ctx.font = '25px Microsoft YaHei, sans-serif'; split(p.hotel_name, 42).slice(0, 2).forEach((line, i) => ctx.fillText(line, 48, 40 + i * 26));
                    ctx.font = 'bold 38px Microsoft YaHei, sans-serif'; split(p.title, 28).slice(0, 2).forEach((line, i) => ctx.fillText(line, 48, 92 + i * 43));
                    const page = Math.min(Math.max(1, Math.ceil(lines.length / 2)) - 1, Math.floor(progress * Math.max(1, Math.ceil(lines.length / 2))));
                    ctx.font = '24px Microsoft YaHei, sans-serif'; lines.slice(page * 2, page * 2 + 2).forEach((line, i) => ctx.fillText(line, 48, 605 + i * 30));
                } else {
                    ctx.fillStyle = p.brand_color; ctx.fillRect(0, 0, 1280, 230); ctx.fillStyle = ink(p.brand_color);
                    ctx.font = '25px Microsoft YaHei, sans-serif'; split(p.hotel_name, 42).forEach((line, i) => ctx.fillText(line, 60, 32 + i * 25));
                    ctx.font = 'bold 38px Microsoft YaHei, sans-serif'; split(p.title, 28).forEach((line, i) => ctx.fillText(line, 60, 126 + i * 37));
                    ctx.fillStyle = '#1c3028'; ctx.font = '28px Microsoft YaHei, sans-serif';
                    lines.slice(Math.min(pages - 1, Math.floor(progress * pages)) * 7).slice(0, 7).forEach((line, i) => ctx.fillText(line, 60, 270 + i * 40));
                }
                ctx.fillStyle = visuals.length ? '#ffffff' : '#5b6d63'; ctx.font = '16px Microsoft YaHei, sans-serif';
                const provenance = `制作单 #${record.id} / v${record.version_no} · 当前酒店${record.hotel_id} · 来源酒店${record.source_hotel_id || record.hotel_id} · ${record.business_date} · 来源：${record.source_label}`;
                split(provenance, 68).slice(0, 2).forEach((line, i) => ctx.fillText(line, 48, 665 + i * 19));
                if (visuals.length) { const review = { pending_review: '待审核', reviewed: '人工已审核', rejected: '未通过' }; ctx.fillText(`品牌：${review[p.brand_review_status] || '未知'} · 素材：${review[p.material_review_status] || '未知'} · ${music ? '已混入所选音乐' : '无音乐'}`, 48, 705); }
                if (!visuals.length) ctx.fillText('文字画面生成，未提供实拍素材与音乐', 60, 675);
                ctx.fillStyle = p.brand_color; ctx.fillRect(0, 710, 1280 * progress, 10);
            };
            const chunks = []; let playbackFailed = false;
            recorder = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 1800000, audioBitsPerSecond: 128000 });
            // Start the first clip before recording so an undecodable/autoplay-blocked clip cannot yield a silent success.
            for (const media of visuals.filter(media => media.kind === 'video')) { try { await waitForMedia(media.element.play(), signal); media.element.pause(); } catch (error) { if (signal?.aborted) throw error; throw new Error('本地视频无法播放，尚未生成作品'); } }
            const finish = new Promise((resolve, reject) => {
                recorder.ondataavailable = event => { if (event.data?.size) chunks.push(event.data); };
                recorder.onerror = () => reject(new Error('本地视频编码失败，没有生成可用作品'));
                recorder.onstop = () => {
                    if (signal?.aborted) return reject(new Error('酒店、日期或页面已切换，视频生成已取消'));
                    if (playbackFailed) return reject(new Error('本地视频播放失败，作品未完成'));
                    const blob = new Blob(chunks, { type: mime }); blob.size ? resolve(blob) : reject(new Error('视频编码结果为空，请重试'));
                };
                abortListener = () => { if (recorder.state !== 'inactive') recorder.stop(); };
                signal?.addEventListener('abort', abortListener, { once: true });
            });
            visuals.filter(media => media.kind === 'video').forEach(media => { media.element.onerror = () => { playbackFailed = true; if (recorder.state !== 'inactive') recorder.stop(); }; });
            if (signal?.aborted) throw new Error('视频生成已取消');
            draw(0); if (signal?.aborted) throw new Error('视频生成已取消');
            recorder.start(250); const start = performance.now();
            const frame = () => {
                if (signal?.aborted) { if (recorder.state !== 'inactive') recorder.stop(); return; }
                if (recorder.state === 'inactive') return;
                const progress = Math.min(1, (performance.now() - start) / duration); draw(progress); onProgress?.(Math.round(progress * 100));
                if (progress >= 1) recorder.stop(); else animation = requestAnimationFrame(frame);
            };
            animation = requestAnimationFrame(frame); return await finish;
        } finally {
            cancelAnimationFrame(animation); signal?.removeEventListener('abort', abortListener);
            if (recorder?.state !== 'inactive' && recorder) recorder.stop();
            stream?.getTracks().forEach(track => track.stop());
            for (const media of opened) { if (media.kind !== 'image') media.element.pause(); media.element.removeAttribute('src'); URL.revokeObjectURL(media.url); }
            if (audioContext) await audioContext.close();
        }
    };
    const fields = (h, payload, files, disabled, pick) => h('div', { class: 'space-y-2 text-sm' }, [
        h('label', { class: 'block' }, ['酒店照片/视频与音乐（最多20个画面、1个音乐）', h('input', { type: 'file', multiple: true, accept: Object.values(allowed).flat().join(','), disabled, class: 'block w-full min-h-[44px] border border-slate-300 rounded-lg p-2', onChange: event => pick(Array.from(event.target.files || [])) })]),
        h('p', { class: 'text-slate-600' }, '文件仅在当前浏览器处理；保存名称和SHA256后导出。重新打开须选择同一批原文件。视频原声静音，可选音乐混入WebM；没有选择素材时仅生成文字画面。'),
        ...(payload.local_media_manifest || []).map((row, index) => h('p', { key: index, class: 'break-words text-xs text-slate-600' }, `${row.name} · ${row.kind} · ${Math.round(row.size_bytes / 1024)}KB · ${files[index] ? '当前已选择' : '需重新选择'} · SHA256 ${row.sha256.slice(0, 12)}…`)),
    ]);
    window.SUXI_CAMPAIGN_MEDIA = Object.freeze({ renderWebm, describeFiles, matchFiles, fields });
})();
