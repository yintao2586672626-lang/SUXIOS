(() => {
    const components = window.SUXI_ONLINE_DATA_COMPONENTS || {};

    components.PlatformAutoSettingsPanelsBody = {
        name: 'PlatformAutoSettingsPanelsBody',
        props: {
            ctx: {
                type: Object,
                required: true,
            },
        },
        template: `
            <div data-testid="platform-auto-settings-panels" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="rounded-lg border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b">
                        <div class="font-semibold text-gray-900">
                            <i class="fas fa-clock mr-2 text-slate-700"></i>采集时间计划
                        </div>
                    </div>
                    <div class="p-4 space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm text-gray-700">实时采集间隔（小时）</label>
                                <input type="number" min="1" max="24" step="1" v-model.number="ctx.autoFetchRealtimeIntervalHours"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md bg-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700">实时任务执行分钟（0-59）</label>
                                <input type="number" min="0" max="59" step="1" v-model.number="ctx.autoFetchScheduleMinute"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md bg-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        <div class="text-xs text-gray-500">默认每 2 小时，在指定分钟触发实时快照；历史固定数据按每日时间保底抓取。美团定时任务固定使用已绑定 Profile。</div>
                        <button type="button" @click="ctx.saveFetchSchedule"
                            :disabled="!ctx.autoFetchHotelId || !ctx.hasAnyPlatformFetchConfigByHotelId(ctx.autoFetchHotelId)"
                            class="px-4 py-2 rounded-md bg-blue-600 text-white hover:bg-blue-700 disabled:bg-gray-200 disabled:text-gray-500 text-sm">
                            保存（下次采集生效）
                        </button>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b">
                        <div class="font-semibold text-gray-900">
                            <i class="fas fa-window-maximize mr-2 text-slate-700"></i>浏览器设置
                        </div>
                    </div>
                    <div class="p-4 space-y-3">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <span class="relative mt-0.5 inline-flex">
                                <input type="checkbox" v-model="ctx.autoFetchBrowserHeadless" class="sr-only">
                                <span :class="['block w-9 h-5 rounded-full transition-colors', ctx.autoFetchBrowserHeadless ? 'bg-blue-600' : 'bg-gray-300']"></span>
                                <span :class="['absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform', ctx.autoFetchBrowserHeadless ? 'translate-x-4' : '']"></span>
                            </span>
                            <span class="text-sm text-gray-700">无头模式（后台运行，不显示浏览器窗口）</span>
                        </label>
                        <div class="grid grid-cols-1 sm:grid-cols-[180px_1fr] gap-3 items-center">
                            <label class="text-sm text-gray-700">携程板块并发页数</label>
                            <div class="flex flex-wrap items-center gap-2">
                                <input type="number" min="1" max="4" step="1" v-model.number="ctx.autoFetchCtripSectionConcurrency"
                                    class="w-24 px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <span class="text-xs text-slate-500">默认 3；1 为稳定单页，2-4 为同一本机 Profile 下多页面并发，失败板块自动串行补抓。</span>
                            </div>
                        </div>
                        <div class="text-sm text-amber-600">该开关只影响采集；平台授权必须由账号使用者在本机当前浏览器完成，服务器不代开登录窗口。</div>
                        <button type="button" @click="ctx.saveFetchSchedule"
                            :disabled="!ctx.autoFetchHotelId || !ctx.hasAnyPlatformFetchConfigByHotelId(ctx.autoFetchHotelId)"
                            class="px-4 py-2 rounded-md bg-blue-600 text-white hover:bg-blue-700 disabled:bg-gray-200 disabled:text-gray-500 text-sm">
                            保存（下次采集生效）
                        </button>
                    </div>
                </div>
            </div>
        `,
    };

    components.PlatformAutoSecondaryPanelsBody = {
        name: 'PlatformAutoSecondaryPanelsBody',
        props: {
            ctx: {
                type: Object,
                required: true,
            },
        },
        template: `
            <div data-testid="platform-auto-secondary-panels" class="space-y-4">
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-2">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">采集闭环</div>
                            <div class="mt-1 text-xs text-slate-500">立即采集走已保存 Cookie/API；定时任务走账号使用者本机 Profile；两条路径都需完成业务响应、标准字段和入库回读。</div>
                        </div>
                        <span class="inline-flex w-fit items-center rounded-md border border-blue-100 bg-white px-2 py-1 text-xs text-blue-700">OTA 渠道口径</span>
                    </div>
                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-2">
                        <div v-for="item in ctx.autoFetchCollectionBlueprintRows" :key="item.label" class="rounded-md border bg-white px-3 py-2">
                            <div class="text-xs text-slate-400">{{ item.label }}</div>
                            <div class="mt-1 text-sm font-medium text-slate-800">{{ item.value }}</div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="rounded-lg border bg-white px-4 py-3 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-gray-900">美团</div>
                                <div class="mt-1 text-sm text-gray-500" :title="ctx.meituanPlatformProfileStatusRow ? ctx.platformProfileBindingRawText(ctx.meituanPlatformProfileStatusRow) : ''">
                                    <template v-if="ctx.meituanPlatformProfileStatusRow">
                                        {{ ctx.platformProfileBindingText(ctx.meituanPlatformProfileStatusRow) }}
                                    </template>
                                    <template v-else>无记录</template>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span v-if="ctx.meituanPlatformProfileStatusRow"
                                    :title="ctx.platformProfileStatusRawText(ctx.meituanPlatformProfileStatusRow)"
                                    :class="['px-2 py-1 rounded-md text-xs font-medium', ctx.platformProfileStatusBadgeClass(ctx.meituanPlatformProfileStatusRow.status_code)]">
                                    {{ ctx.platformProfileStatusLabel(ctx.meituanPlatformProfileStatusRow) }}
                                </span>
                                <span v-else class="px-2 py-1 rounded-md bg-gray-100 text-gray-500 text-xs font-medium">未配置</span>
                                <button v-if="ctx.meituanPlatformProfileStatusRow"
                                    type="button"
                                    @click="ctx.deletePlatformProfileBinding(ctx.meituanPlatformProfileStatusRow)"
                                    :disabled="!!ctx.platformDataSourceDeletingId"
                                    class="px-2 py-1 rounded-md border border-red-200 text-red-600 bg-white hover:bg-red-50 disabled:text-gray-400 disabled:bg-gray-50 text-xs">
                                    <i class="fas fa-unlink mr-1"></i>解绑
                                </button>
                            </div>
                        </div>
                        <div v-if="ctx.meituanPlatformProfileStatusRow?.next_action" class="mt-2 text-xs text-amber-700" :title="ctx.meituanPlatformProfileStatusRow.next_action">
                            {{ ctx.platformProfileNextActionText(ctx.meituanPlatformProfileStatusRow) }}
                        </div>
                        <div v-if="ctx.meituanPlatformProfileLoginTask" class="mt-2 text-xs text-orange-800" :title="ctx.platformProfileLoginTaskRawText(ctx.meituanPlatformProfileLoginTask)">
                            {{ ctx.platformProfileLoginTaskText(ctx.meituanPlatformProfileLoginTask) }}
                        </div>
                        <div data-testid="meituan-browser-supplement-capture" class="mt-3 border-t border-orange-100 pt-3">
                            <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-slate-900">竞对补充采集</div>
                                    <div class="mt-1 text-xs text-slate-500">复用美团 Profile，补充同行排名、流量分析、搜索词和未来30天预测；仅作为 OTA 渠道补充信号。</div>
                                </div>
                                <button type="button"
                                    @click="ctx.runMeituanBrowserSupplementCapture"
                                    :disabled="ctx.meituanBrowserCaptureRunning || ctx.fetchingData || !ctx.autoFetchHotelId"
                                    class="shrink-0 inline-flex items-center justify-center rounded-md bg-orange-600 px-3 py-2 text-xs text-white hover:bg-orange-700 disabled:bg-gray-200 disabled:text-gray-500">
                                    <i :class="ctx.meituanBrowserCaptureRunning ? 'fas fa-spinner fa-spin mr-1.5' : 'fas fa-chart-line mr-1.5'"></i>{{ ctx.meituanBrowserCaptureRunning ? '采集中' : '采集补充板块' }}
                                </button>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <span v-for="module in ctx.meituanBrowserCaptureSupplementModules" :key="'meituan-supplement-' + module.key" class="rounded border border-orange-100 bg-orange-50 px-2 py-1 text-[11px] text-orange-800" :title="module.endpoint">
                                    {{ module.label }}
                                </span>
                            </div>
                            <div v-if="ctx.meituanBrowserCaptureResult" class="mt-2 flex flex-wrap gap-1.5 text-[11px]">
                                <span v-for="row in ctx.meituanBrowserCaptureSupplementCounts" :key="'meituan-supplement-count-' + row.key" class="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-slate-600">
                                    {{ row.label }} {{ row.count }}
                                </span>
                            </div>
                            <div v-if="ctx.meituanBrowserCaptureResult?.session_proof_status === 'not_recorded'" data-testid="meituan-session-proof-not-recorded" class="mt-2 rounded border border-amber-200 bg-amber-50 px-2.5 py-2 text-xs leading-5 text-amber-900">
                                <div class="font-semibold">数据结果已保留，但登录证据未持久化</div>
                                <div class="mt-1">{{ ctx.meituanBrowserCaptureResult.session_proof_message || '当前响应没有返回可复用登录证据。' }}</div>
                                <div class="mt-1"><span class="font-semibold">下一步：</span>{{ ctx.meituanBrowserCaptureResult.session_proof_next_action || '刷新登录状态后重新执行一次最小采集。' }}</div>
                            </div>
                            <div v-if="ctx.meituanBrowserCaptureResult?.capture_gate?.section_statuses?.ads === 'not_applicable' || ctx.meituanBrowserCaptureResult?.pages?.some(page => page?.section_evidence?.status === 'not_applicable' && page?.section_evidence?.reason === 'ads_not_enabled')" class="mt-2 rounded border border-amber-200 bg-amber-50 px-2.5 py-2 text-xs text-amber-800">
                                广告未开通，本轮不采集广告数据，不影响其他已验证板块入库。
                            </div>
                        </div>
                    </div>

                    <div class="rounded-lg border bg-white px-4 py-3 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-gray-900">携程</div>
                                <div class="mt-1 text-sm text-gray-500" :title="ctx.ctripPlatformProfileStatusRow ? ctx.platformProfileBindingRawText(ctx.ctripPlatformProfileStatusRow) : ''">
                                    <template v-if="ctx.ctripPlatformProfileStatusRow">
                                        {{ ctx.platformProfileBindingText(ctx.ctripPlatformProfileStatusRow) }}
                                    </template>
                                    <template v-else>无记录</template>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span v-if="ctx.ctripPlatformProfileStatusRow"
                                    :title="ctx.platformProfileStatusRawText(ctx.ctripPlatformProfileStatusRow)"
                                    :class="['px-2 py-1 rounded-md text-xs font-medium', ctx.platformProfileStatusBadgeClass(ctx.ctripPlatformProfileStatusRow.status_code)]">
                                    {{ ctx.platformProfileStatusLabel(ctx.ctripPlatformProfileStatusRow) }}
                                </span>
                                <span v-else class="px-2 py-1 rounded-md bg-gray-100 text-gray-500 text-xs font-medium">未配置</span>
                                <button v-if="ctx.ctripPlatformProfileStatusRow"
                                    type="button"
                                    @click="ctx.deletePlatformProfileBinding(ctx.ctripPlatformProfileStatusRow)"
                                    :disabled="!!ctx.platformDataSourceDeletingId"
                                    class="px-2 py-1 rounded-md border border-red-200 text-red-600 bg-white hover:bg-red-50 disabled:text-gray-400 disabled:bg-gray-50 text-xs">
                                    <i class="fas fa-unlink mr-1"></i>解绑
                                </button>
                            </div>
                        </div>
                        <div v-if="ctx.ctripPlatformProfileStatusRow?.next_action" class="mt-2 text-xs text-amber-700" :title="ctx.ctripPlatformProfileStatusRow.next_action">
                            {{ ctx.platformProfileNextActionText(ctx.ctripPlatformProfileStatusRow) }}
                        </div>
                        <div v-if="ctx.ctripPlatformProfileLoginTask" class="mt-2 text-xs text-blue-800" :title="ctx.platformProfileLoginTaskRawText(ctx.ctripPlatformProfileLoginTask)">
                            {{ ctx.platformProfileLoginTaskText(ctx.ctripPlatformProfileLoginTask) }}
                        </div>
                    </div>
                </div>

                <div v-if="ctx.autoFetchRunState.active || ctx.autoFetchRunState.message || ctx.autoFetchStatus?.last_result" class="rounded-lg border bg-white px-4 py-3 text-sm">
                    <span class="font-medium text-gray-900">最近结果：</span>
                    <span :class="ctx.autoFetchRunState.active ? 'text-blue-700' : ((ctx.autoFetchStatus?.last_result?.success === true || ctx.autoFetchRunState.type === 'success') ? 'text-green-700' : 'text-red-700')">
                        {{ ctx.autoFetchResultMessage(ctx.autoFetchRunState.message || ctx.autoFetchStatus?.last_result?.message) }}
                    </span>
                    <div v-if="ctx.autoFetchRunState.active" class="mt-2 rounded-md border border-blue-100 bg-blue-50 px-3 py-2 text-xs text-blue-800">
                        已运行 {{ ctx.formatAutoFetchElapsed(ctx.autoFetchRunElapsedSeconds) }}。{{ ctx.autoFetchRunningHint }}
                    </div>
                    <div v-if="ctx.autoFetchRunState.active && ctx.autoFetchPlatformProgressRows.length" class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-2">
                        <div v-for="row in ctx.autoFetchPlatformProgressRows" :key="'auto-fetch-progress-' + row.platform" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-medium text-slate-800">{{ row.label }}</span>
                                <span :class="['rounded border px-2 py-0.5', row.status_class]">{{ row.status_text }}</span>
                            </div>
                            <div class="mt-1 text-slate-600">{{ row.message || '-' }}</div>
                            <div v-if="row.status === 'success'" class="mt-1 text-slate-500">已写入 {{ row.saved_count }} 次数据操作</div>
                        </div>
                    </div>
                    <div v-if="ctx.autoFetchTimingRows.length" class="mt-2 flex flex-wrap gap-1.5 text-xs">
                        <span v-for="row in ctx.autoFetchTimingRows" :key="'auto-fetch-timing-' + row.key" class="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-slate-600">
                            {{ row.label }} {{ row.value }}
                        </span>
                    </div>
                    <div class="mt-2 text-xs text-slate-500">
                        {{ ctx.autoFetchCtripExecutionText }}
                    </div>
                    <div v-if="ctx.platformSyncActionText(ctx.autoFetchRunState.message || ctx.autoFetchStatus?.last_result?.message)" class="mt-2 text-xs text-amber-700">
                        {{ ctx.platformSyncActionText(ctx.autoFetchRunState.message || ctx.autoFetchStatus?.last_result?.message) }}
                    </div>
                    <div v-if="ctx.autoFetchPlatformResultRows.length" class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-2">
                        <div v-for="row in ctx.autoFetchPlatformResultRows" :key="'auto-fetch-result-' + row.platform" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-medium text-slate-800">{{ row.platform === 'meituan' ? '美团' : '携程' }}</span>
                                <span :class="['rounded px-2 py-0.5 text-xs', ctx.autoFetchResultStatusClass(row)]">{{ ctx.autoFetchResultStatusText(row) }}</span>
                            </div>
                            <div class="mt-1 text-xs text-slate-500">写入操作 {{ row.saved_count || 0 }} 次 · {{ row.mode_label || ctx.autoFetchModeLabel(row.auto_fetch_mode) }}</div>
                            <div class="mt-1 text-xs text-slate-600">{{ ctx.autoFetchResultMessage(row.message, row.saved_count) }}</div>
                            <div v-if="Array.isArray(row.modules) && row.modules.length" class="mt-2 flex flex-wrap gap-1">
                                <span v-for="(module, index) in row.modules" :key="row.platform + '-' + (module.module || 'module') + '-' + index" :class="['rounded border px-2 py-0.5 text-xs', ctx.autoFetchResultStatusClass(module)]">
                                    {{ ctx.autoFetchModuleLabel(module.module) }} · 写入操作 {{ module.saved_count || 0 }} 次
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="mt-2 text-xs text-slate-500">采集结果只代表授权 OTA 账号可见数据，不等同于全酒店经营口径。</div>
                </div>
            </div>
        `,
    };

    window.SUXI_ONLINE_DATA_COMPONENTS = components;
})();
