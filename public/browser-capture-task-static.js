(() => {
    const allowedEndpoints = new Set([
        '/online-data/capture-ctrip-browser',
        '/online-data/capture-meituan-browser',
    ]);
    const request = async ({
        endpoint = '',
        payload = {},
        request: apiRequest,
        poll,
        notify = () => {},
        wait = delayMs => new Promise(resolve => setTimeout(resolve, delayMs)),
    } = {}) => {
        if (!allowedEndpoints.has(endpoint)
            || typeof apiRequest !== 'function'
            || typeof poll !== 'function') {
            throw new Error('浏览器采集后台任务参数无效');
        }

        const source = payload && typeof payload === 'object' ? payload : {};
        const explicitSync = source.sync === true || source.async === false;
        const requestBody = {
            ...source,
            async: !explicitSync,
            background: !explicitSync,
        };
        delete requestBody.sync;
        const accepted = await apiRequest(endpoint, {
            method: 'POST',
            body: JSON.stringify(requestBody),
        });
        if (explicitSync || Number(accepted?.code || 0) !== 202) {
            return accepted;
        }

        const taskId = String(accepted?.data?.task_id || '').trim();
        if (!taskId) throw new Error('浏览器采集后台任务未返回 task_id');
        notify('浏览器 Profile 采集已转入后台，可继续浏览其他页面', 'info');
        const terminal = await poll({
            taskId,
            requestStatus: currentTaskId => apiRequest(
                `/online-data/manual-fetch-task-status?task_id=${encodeURIComponent(currentTaskId)}`,
                { withBusinessContext: false }
            ),
            wait,
            intervalMs: 1500,
            maxAttempts: 800,
        });
        const savedCount = Math.max(0, Number(terminal.savedCount || 0));
        const readbackCount = Math.max(0, Number(terminal.readbackCount || 0));
        const terminalSuccess = terminal.status === 'success'
            && savedCount > 0
            && readbackCount === savedCount
            && terminal.readbackVerified === true;
        const serverIdentity = terminal.qualitySummary?.server_identity;
        const identityVerified = serverIdentity?.verified === true;
        const responseCode = terminalSuccess ? 200 : (terminal.status === 'failed' ? 500 : 409);
        return {
            code: responseCode,
            message: terminal.message || (terminalSuccess
                ? '浏览器 Profile 后台采集已完成'
                : '浏览器 Profile 后台采集失败'),
            data: {
                task_id: terminal.taskId,
                task_kind: terminal.taskKind,
                status: terminal.status,
                stage: terminal.stage,
                status_text: terminal.statusText,
                saved_count: savedCount,
                readback_count: readbackCount,
                readback_verified: terminalSuccess,
                quality_status: terminal.qualityStatus,
                quality_summary: terminal.qualitySummary,
                progress_percent: terminal.progressPercent,
                done: terminal.done,
                background_task: true,
                identity_verified: identityVerified,
                profile_id: identityVerified && serverIdentity.platform === 'ctrip'
                    ? String(serverIdentity.profile_id || '') || null
                    : null,
                store_id: identityVerified && serverIdentity.platform === 'meituan'
                    ? String(serverIdentity.store_id || '') || null
                    : null,
            },
        };
    };

    window.SUXI_BROWSER_CAPTURE_TASK = Object.freeze({ request });
})();
