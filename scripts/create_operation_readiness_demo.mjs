import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { createServer } from 'node:http';
import { compile } from '@vue/compiler-dom';

const root = resolve(import.meta.dirname, '..');
const read = path => readFileSync(resolve(root, path), 'utf8');
const source = read('public/app-main.js');
const start = source.indexOf('const operationDataRequestSeq =');
const end = source.indexOf('const loadOperationAlerts =', start);
if (start < 0 || end < start) throw new Error('Operation request controller could not be located.');
const controller = source.slice(start, end);
const fragment = read('resources/frontend/templates/fragments/15a-page-ops-source.html');
const template = `<div class="p-5 max-w-7xl mx-auto">
<div role="note" class="border border-amber-300 bg-amber-50 p-4 mb-5"><strong>本地模拟验收 · 无真实门店数据</strong><p>下方使用实际数据核对页面与请求控制代码。数据由本页模拟，不访问 OTA、数据库、模型或真实账号。</p></div>
<div class="flex flex-wrap gap-3 mb-5"><label>测试场景 <select aria-label="测试场景" v-model="scenario" class="border p-2"><option value="complete">完整模拟数据</option><option value="missing">缺少数据</option><option value="failure">读取失败</option><option value="wrong">返回身份不符</option><option value="slow">慢请求（两秒）</option></select></label><p role="status">{{ notice }}</p></div>
${fragment}
</div>`;
const render = compile(template, { mode: 'function' }).code;
const page = `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>本地模拟 · 经营数据核对验收</title><link rel="stylesheet" href="/tailwind.min.css"><style>body{font-family:"Microsoft YaHei",sans-serif;background:#f6f7f3}button,select,input{min-height:40px}a{color:#143a31}</style></head><body><div id="demo"></div>
<script src="/vue.runtime.global.prod.js"></script><script src="/system-static.js"></script><script src="/operation-static.js"></script><script>
const render = new Function('Vue', ${JSON.stringify(render)})(Vue);
Vue.createApp({render,setup(){
const {ref,computed,watch}=Vue, S=window.SUXI_SYSTEM_STATIC, O=window.SUXI_OPERATION_STATIC;
const currentPage=ref('ops-source'),isLoggedIn=ref(true),pageRequestGeneration=1,scenario=ref('complete'),notice=ref('');
const operationFilters=ref({hotel_id:'7',date:'2026-09-07'}),operationLoading=ref({fullData:false,rootCause:false}),operationError=ref({fullData:'',rootCause:''}),operationFullData=ref(null),operationRootCause=ref(null);
const captureAuthSession=()=>1,isAuthSessionCurrent=value=>value===1,ensureOperationStaticReady=async()=>{},operationErrorMessage=error=>error.message,showToast=text=>notice.value=text;
watch(scenario,()=>notice.value='');
const normalizeOperationHotelSelection=form=>form.value.hotel_id;
const operationParams=()=>new URLSearchParams(operationFilters.value).toString();
const apiRequest=(url,options)=>{const q=options?JSON.parse(options.body):Object.fromEntries(new URL(url,location.origin).searchParams);const hotel=Number(q.hotel_id||0),date=q.date,mode=scenario.value;return new Promise((yes,no)=>setTimeout(()=>{
if(mode==='failure'){no(new Error('模拟读取失败，请重新读取'));return;}
const state=mode==='missing'?'missing':'ok',base=hotel*100;
yes({code:200,data:{query_scope:{hotel_id:mode==='wrong'?99:hotel,business_date:date},
summary:{data_status:state,revenue:mode==='missing'?null:base,orders:hotel,room_nights:hotel,adr:100,occ:null,revpar:null},
ota:{data_status:state,exposure:base,visitors:base/10,views:base/5,orders:hotel,view_rate:20,order_rate:10},
competitors:{data_status:'missing'},service_quality:{data_status:'missing'},holiday:{data_status:'missing'},
abnormal_flags:mode==='missing'?['本地模拟：缺少当前日期渠道事实']:[]}});
},mode==='slow'?2000:80));};
${controller}
const formatters={value:S.operationValue,money:S.operationMoney};
return {currentPage,scenario,notice,operationFilters,operationLoading,operationError,operationFullData,operationRootCause,
operationHotelOptions:[{id:7,name:'模拟门店 A'},{id:8,name:'模拟门店 B'}],
operationSourceBrief:computed(()=>O.buildOperationSourceBrief(operationFullData.value)),
operationSummaryCards:computed(()=>O.buildOperationSummaryCards(operationFullData.value?.summary||{},formatters)),
operationOtaCards:computed(()=>O.buildOperationOtaCards(operationFullData.value?.ota||{},formatters)),
operationCompetitorCards:computed(()=>O.buildOperationCompetitorCards(operationFullData.value?.competitors||{},formatters)),
operationValue:S.operationValue,operationDataStatusText:S.operationDataStatusText,loadOperationFullData,analyzeOperationRootCause};
}}).mount('#demo');
</script></body></html>`;
const destination = resolve(root, 'output/qa/product-capability-20260908/operations-demo.html');
mkdirSync(dirname(destination), {recursive:true});
writeFileSync(destination,page,'utf8');
console.log(JSON.stringify({output:destination,evidence:'local_mock_only'}));
if (process.argv.includes('--serve')) {
  const allowed=new Set(['vue.runtime.global.prod.js','tailwind.min.css','system-static.js','operation-static.js']);
  createServer((request,response)=>{
    const pathname=new URL(request.url,'http://127.0.0.1').pathname;
    if(request.method!=='GET'){response.writeHead(405).end();return;}
    if(pathname==='/'){response.setHeader('Content-Type','text/html; charset=utf-8');response.end(page);return;}
    if(pathname==='/product-guide.html'){response.writeHead(302,{Location:'http://127.0.0.1:8080/product-guide.html'}).end();return;}
    const asset=pathname.slice(1);
    if(!allowed.has(asset)){response.writeHead(404).end();return;}
    response.setHeader('Content-Type',asset.endsWith('.css')?'text/css; charset=utf-8':'text/javascript; charset=utf-8');
    response.end(read('public/'+asset));
  }).listen(8096,'127.0.0.1',()=>console.log('Local mock only: http://127.0.0.1:8096/'));
}
