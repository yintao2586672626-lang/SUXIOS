(() => {
    'use strict';
    const token = window.location.hash.slice(1), form = document.getElementById('feedback-form'), status = document.getElementById('entry-status'), error = document.getElementById('error');
    let requestKey = '', busy = false;
    const randomKey = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
    const failure = message => { error.textContent = message; error.hidden = false; };
    const request = async (path, payload) => {
        const response = await fetch(`/api/guest-feedback/${path}`, { method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token, ...payload }), cache: 'no-store', referrerPolicy: 'no-referrer' });
        const result = await response.json(); if (!response.ok || result.code !== 200) throw new Error(result.message || '反馈服务暂不可用，请联系前台'); return result.data;
    };
    if (!/^[a-f0-9]{64}$/.test(token)) { status.textContent = '无法使用反馈入口'; failure('入口链接无效，请重新扫描房间二维码或联系前台'); return; }
    request('entry', {}).then(entry => { if (!entry.submission_enabled) throw new Error('入口已停用，请联系前台'); status.textContent = `${entry.hotel_name} · ${entry.room_label} · ${entry.label}`; document.getElementById('privacy').textContent = entry.privacy_notice; form.hidden = false; }).catch(reason => { status.textContent = '无法使用反馈入口'; failure(reason.message || '入口读取失败，请联系前台'); });
    form.addEventListener('input', () => { if (!busy) requestKey = ''; });
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (busy) return;
        const summary = form.elements.summary.value.trim();
        if (!summary || summary.length > 1500) { failure('请填写1至1500字反馈'); return; }
        requestKey ||= randomKey(); busy = true; error.hidden = true; form.querySelector('button').disabled = true;
        try { const result = await request('submit', { summary, category: form.elements.category.value, request_key: requestKey }); if (result.submission_status !== 'readback_verified') throw new Error('提交未取得保存回读，请重试或联系前台'); status.textContent = `${result.message}；回执 ${result.receipt}`; form.hidden = true; }
        catch (reason) { failure(reason.message || '提交失败，请重试或联系前台'); }
        finally { busy = false; form.querySelector('button').disabled = false; }
    });
})();
