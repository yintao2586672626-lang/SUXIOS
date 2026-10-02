import assert from 'node:assert/strict'; import fs from 'node:fs'; import vm from 'node:vm'; import test from 'node:test';
const main=fs.readFileSync('public/app-main.js','utf8'),source=fs.readFileSync('public/operation-static.js','utf8');
const take=(start,end)=>{const a=main.indexOf(start),b=main.indexOf(end,a);assert.ok(a>=0&&b>a);return main.slice(a,b);};
function setup(){
 const calls=[],messages=[],session={epoch:1};
 const c={window:{}, selectedOpeningProjectId:{value:'31'},openingOverview:{value:null},openingTasks:{value:[]},openingLoading:{value:false},openingProjectForm:{value:{}},currentPage:{value:'opening-checklist'},
  captureAuthSession:()=>({epoch:session.epoch}),isAuthSessionCurrent:s=>s.epoch===session.epoch,
  clearSelectedOpeningTasks:()=>{},pruneSelectedOpeningTaskIds:()=>{},syncOpeningProjectForm:()=>{},resetOpeningProjectForm:()=>({}),nextTick:async()=>{},
  showToast:(...args)=>messages.push(args),request:(url,options)=>new Promise((resolve,reject)=>calls.push({url,options,resolve,reject}))};
 vm.createContext(c);vm.runInContext(source,c);
 if(c.window.SUXI_OPERATION_STATIC.createOpeningProjectDataController)c.openingProjectDataController=c.window.SUXI_OPERATION_STATIC.createOpeningProjectDataController(c);
 vm.runInContext(take('const selectOpeningProject =','const updateOpeningTask =')+take('const recalculateOpening =','const renderHomeTrendChart =')+'\nthis.api={selectOpeningProject,loadOpeningOverview,loadOpeningTasks,generateOpeningTasks,recalculateOpening};',c);
 const respond=(call,tag='')=>{const id=Number(call.url.match(/projects\/(\d+)/)[1]);const data=call.url.endsWith('/tasks')?{list:[{id:id*10,project_id:id,task_name:tag||'project '+id}]}:call.url.endsWith('/generate-tasks')?{tasks:[{id:id*10,project_id:id,task_name:tag}],overview:{project:{id},tag}}:{project:{id},tag};call.resolve({code:200,data});};
 return{c,calls,messages,session,respond};
}
test('project selection ignores late A overview and task rows after B is displayed',async()=>{
 const s=setup();const a=s.c.api.selectOpeningProject();s.c.selectedOpeningProjectId.value='32';const b=s.c.api.selectOpeningProject();
 s.calls.slice(2).forEach(x=>s.respond(x,'B'));await b;s.calls.slice(0,2).forEach(x=>s.respond(x,'A'));await a;
 assert.equal(s.c.openingOverview.value.project.id,32);assert.equal(s.c.openingTasks.value[0].project_id,32);
});
test('A to B to A retains the latest A read rather than the first A response',async()=>{
 const s=setup();const a=s.c.api.selectOpeningProject();s.c.selectedOpeningProjectId.value='32';const b=s.c.api.selectOpeningProject();s.c.selectedOpeningProjectId.value='31';const newest=s.c.api.selectOpeningProject();
 s.calls.slice(4).forEach(x=>s.respond(x,'new A'));await newest;s.calls.slice(0,4).forEach(x=>s.respond(x,'old'));await Promise.all([a,b]);
 assert.equal(s.c.openingOverview.value.tag,'new A');assert.equal(s.c.openingTasks.value[0].task_name,'new A');
});
test('clearing the selected project leaves both surfaces empty despite late reads',async()=>{
 const s=setup();const pending=s.c.api.selectOpeningProject();s.c.selectedOpeningProjectId.value='';await s.c.api.selectOpeningProject();s.calls.forEach(x=>s.respond(x));await pending;
 assert.equal(s.c.openingOverview.value,null);assert.equal(s.c.openingTasks.value.length,0);
});
test('superseded same-project refresh and prior login session responses cannot overwrite current state',async()=>{
 const s=setup();const a=s.c.api.loadOpeningOverview(),b=s.c.api.loadOpeningOverview();s.respond(s.calls[1],'new');await b;s.respond(s.calls[0],'old');await a;assert.equal(s.c.openingOverview.value.tag,'new');
 const oldSession=s.c.api.loadOpeningTasks();s.session.epoch++;s.respond(s.calls[2]);await oldSession;assert.equal(s.c.openingTasks.value.length,0);
});
test('stale business errors remain silent; current transport failure is handled and can retry',async()=>{
 const s=setup();const old=s.c.api.loadOpeningOverview();s.c.selectedOpeningProjectId.value='32';s.calls[0].resolve({code:500,message:'old A failure'});await old;assert.equal(s.messages.length,0);
 const current=s.c.api.loadOpeningTasks().catch(e=>e);s.calls[1].reject(new Error('synthetic offline'));const result=await current;assert.equal(result instanceof Error,false);assert.equal(s.messages.length,1);
 const retry=s.c.api.loadOpeningTasks();s.respond(s.calls[2]);await retry;assert.equal(s.c.openingTasks.value[0].project_id,32);
});
test('generating in A cannot replace B results after selecting B while the write is pending',async()=>{
 const s=setup();const generated=s.c.api.generateOpeningTasks();s.c.selectedOpeningProjectId.value='32';const selected=s.c.api.selectOpeningProject();s.calls.slice(1).forEach(x=>s.respond(x,'B'));await selected;s.respond(s.calls[0],'generated A');await generated;
 assert.equal(s.c.openingOverview.value.project.id,32);assert.equal(s.c.openingTasks.value[0].project_id,32);assert.equal(s.messages.length,0);assert.equal(s.c.openingLoading.value,false);
});
test('same-project selection after A-B-A also invalidates an older generation receipt',async()=>{
 const s=setup();const generated=s.c.api.generateOpeningTasks();s.c.selectedOpeningProjectId.value='32';const b=s.c.api.selectOpeningProject();s.c.selectedOpeningProjectId.value='31';const a=s.c.api.selectOpeningProject();s.calls.slice(3).forEach(x=>s.respond(x,'new A'));await a;s.calls.slice(1,3).forEach(x=>s.respond(x,'B'));await b;s.respond(s.calls[0],'old generated A');await generated;
 assert.equal(s.c.openingOverview.value.tag,'new A');assert.equal(s.c.openingTasks.value[0].task_name,'new A');
});
test('recalculation in A cannot replace B or start a follow-up request for the wrong project',async()=>{
 const s=setup();const recalculated=s.c.api.recalculateOpening();s.c.selectedOpeningProjectId.value='32';const selected=s.c.api.selectOpeningProject();s.calls.slice(1).forEach(x=>s.respond(x,'B'));await selected;s.respond(s.calls[0],'recalculated A');
 await new Promise(r=>setImmediate(r));s.calls.slice(3).forEach(x=>s.respond(x,'unexpected B refresh'));await recalculated;
 assert.equal(s.calls.length,3);assert.equal(s.c.openingOverview.value.project.id,32);assert.equal(s.messages.length,0);
});
test('current project generation preserves its normal receipt, success message and busy lifetime',async()=>{
 const s=setup();const pending=s.c.api.generateOpeningTasks();assert.equal(s.c.openingLoading.value,true);assert.equal(s.messages.length,0);
 s.respond(s.calls[0],'generated current');assert.equal(await pending,true);
 assert.equal(s.c.openingTasks.value[0].task_name,'generated current');assert.equal(s.c.openingOverview.value.project.id,31);
 assert.equal(s.messages.length,1);assert.equal(s.c.openingLoading.value,false);
});
test('current recalculation refreshes the same project tasks, including a legitimate empty checklist',async()=>{
 const s=setup();const pending=s.c.api.recalculateOpening();s.respond(s.calls[0],'current score');await new Promise(r=>setImmediate(r));
 assert.equal(s.calls[1].url,'/opening/projects/31/tasks');assert.equal(s.c.openingLoading.value,true);
 s.calls[1].resolve({code:200,data:{list:[]}});await pending;
 assert.equal(s.c.openingOverview.value.tag,'current score');assert.equal(s.c.openingTasks.value.length,0);assert.equal(s.c.openingLoading.value,false);assert.equal(s.messages.length,1);
});
test('only the owner of the current busy operation can release its loading indicator',()=>{
 const s=setup(),controller=s.c.openingProjectDataController;
 const first=controller.startBusy('projects'),second=controller.startBusy('generate');
 controller.finishBusy(first);assert.equal(s.c.openingLoading.value,true);
 controller.finishBusy(second);assert.equal(s.c.openingLoading.value,false);
});
