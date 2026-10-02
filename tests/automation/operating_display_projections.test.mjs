import assert from 'node:assert/strict';import fs from 'node:fs';import vm from 'node:vm';import test from 'node:test';
const env={window:{},Intl,Date,URLSearchParams};vm.runInNewContext(fs.readFileSync('public/system-static.js','utf8'),env);
vm.runInNewContext(fs.readFileSync('public/system-page-projections.js','utf8'),env);
const api=env.window.SUXI_SYSTEM_STATIC,ref=value=>({value});
test('startup page delegates fail explicitly before deferred loading and reuse the captured API after loading',()=>{
 const startup={window:{},Intl,Date,URLSearchParams};
 vm.runInNewContext(fs.readFileSync('public/system-static.js','utf8'),startup);
 const captured=startup.window.SUXI_SYSTEM_STATIC.buildCloudAuthorizationRows;
 const context={cloudBrowserAuthorization:ref({profiles:[]}),platformAuthorizationEvidenceRows:ref([])};
 assert.throws(()=>captured(context),/页面展示工具尚未加载/);
 vm.runInNewContext(fs.readFileSync('public/system-page-projections.js','utf8'),startup);
 const rows=captured(context);assert.equal(rows.length,4);
 assert.equal(rows[0].status,'unauthorized');assert.equal(rows[0].latestText,'最近采集：未取得');
 context.cloudBrowserAuthorization.value={profiles:[{platform:'ctrip',authorization_status:'ready_to_collect'}]};
 assert.equal(captured(context)[0].status,'ready_to_collect');
 assert.equal(captured(context)[0].latestText,'最近采集：未取得');
});
test('deferred core projections keep absent evidence unknown and an explicitly confirmed zero distinct',()=>{
 const context={coreOperationsSelectedWorkbenchRow:ref({platformRows:[{platform:'ctrip',target_date_rows:null},{platform:'meituan',target_date_rows:0}]}),coreOperationsMetrics:ref({}),coreOperationsMetricCard:()=>({}),coreOperationsPlatformTruthStatus:()=> 'not_loaded',coreOperationsEvidenceStatusClass:x=>x,coreOperationsEvidenceStatusText:x=>x,coreOperationsSourceEvidenceStatusText:x=>x,onlineTruthStatusClass:x=>x,onlineTruthStatusText:x=>x};
 const rows=api.buildCoreOperationsPlatformCards(context);
 assert.equal(rows[0].sourceRows,null);assert.equal(rows[0].sourceRowsText,'未验证/未知');
 assert.equal(rows[1].sourceRows,0);assert.equal(rows[1].sourceRowsText,'0 行');
});
test('notification summary keeps delivered sources tied to their plan and selected business date',()=>{
 const context={manualNotificationOperatingDailyPlans:ref([{id:1,source_scope:'ctrip',send_method:'wecom_formal'},{id:2,source_scope:'pms',send_method:'test'}]),manualNotificationForm:ref({business_date:'2026-09-26'}),operationToday:'2026-09-26',manualNotificationDispatchHistory:ref({list:[{notification_id:1,status:'sent',business_date:'2026-09-25'},{notification_id:2,status:'sent',business_date:'2026-09-26'},{notification_id:1,status:'failed',business_date:'2026-09-26'}]}),manualNotificationMetadata:ref({}),manualNotificationPlanIsActive:()=>true};
 const result=api.buildManualNotificationThreeSourceSummary(context);assert.equal(result.integratedCount,2);assert.equal(result.deliveredCount,1);
 assert.equal(result.cards[0].currentDelivery,null);assert.equal(result.cards[0].latestDeliveryBusinessDate,'2026-09-25');assert.equal(result.cards[1].integrated,false);assert.equal(result.cards[2].currentDelivery.notification_id,2);
 context.manualNotificationForm.value.business_date='2026-09-25';assert.equal(api.buildManualNotificationThreeSourceSummary(context).cards[0].currentDelivery.notification_id,1);
});
test('employee checklist preserves the original manual action identity and waits for a patrol snapshot',()=>{
 const row={hotelId:7,hotelName:'Synthetic',platform:'ctrip',questionKey:'conversion',actionCode:'verify',actionText:'核对渠道来源',priority:'high'};
 const c={otaTodayCollectionReminderRows:ref([]),dataAcquisitionWorkbenchRows:ref([]),dailyWorkbenchRows:ref([]),dailyWorkbenchPatrolVisibleActions:ref([row]),dailyWorkbenchPatrolLatest:ref(null),dataHealthTodayWorkOrders:ref([]),dataHealthPriorityText:x=>x,employeeOtaChecklistCategoryClass:x=>x,employeeOtaChecklistCategoryText:x=>x,dataHealthPriorityClass:x=>x,dailyWorkbenchStatusText:x=>x,dailyWorkbenchStatusClass:x=>x,dailyWorkbenchPatrolTaskId:()=>0,dailyWorkbenchPatrolTrackedStatusText:()=> '未执行',dailyWorkbenchPatrolExecutionText:()=>'',employeeOtaChecklistPriorityRank:()=>0};
 let result=api.buildEmployeeOtaChecklistRows(c);assert.equal(result.length,1);assert.equal(result[0].actionSource,row);assert.equal(result[0].canTrackAction,false);assert.equal(result[0].actionType,'patrol');
 c.dailyWorkbenchPatrolLatest.value={id:3};result=api.buildEmployeeOtaChecklistRows(c);assert.equal(result[0].canTrackAction,true);assert.equal(result[0].actionSource.hotelId,7);assert.match(result[0].boundaryText,/只记录员工处理状态/);
});
test('frozen investment action projection retains blocking evidence without inventing completion',()=>{
 const rows=api.buildInvestmentDecisionActionQueueRows({investmentDecisionOverview:ref({operating_data_gate:{status:'not_ready',missing_evidence:[{code:'missing-revenue'}]}}),investmentDecisionBusinessChainRows:ref([]),investmentDecisionRiskRows:ref([]),investmentDecisionGapTitle:gap=>gap.code,investmentDecisionGapAction:()=> '补齐同酒店来源'});
 assert.equal(rows.length,1);assert.equal(rows[0].blocking,true);assert.equal(rows[0].severity,'high');assert.equal(rows[0].title,'missing-revenue');
});
