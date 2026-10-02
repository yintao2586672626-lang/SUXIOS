import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source=readFileSync('public/app-main.js','utf8');
const bridgeStart=source.indexOf('const downloadMeituanCurrentPageCsv =');
const bridge=source.slice(bridgeStart,source.indexOf('const downloadMeituanFilteredCsv =',bridgeStart));
assert.ok(bridge, 'real download action must be available');
const staticSource=readFileSync('public/meituan-static.js','utf8');
const query=(hotel='80',date='2026-09-25',page=1)=>`page=${page}&page_size=30${hotel?`&system_hotel_id=${hotel}`:''}&source=meituan&data_types=advertising&start_date=${date}&end_date=${date}`;
function harness(hotel='80') {
  const downloads=[],notices=[];
  const rows=[{id:1, source:'meituan', system_hotel_id:80, hotel_name:'Fixture hotel 80', data_date:'2026-09-25', data_type:'advertising', exposure_count:0, click_count:null, validation_status:'partial', readback_verified:0}];
  if(!hotel) rows.push({...rows[0],id:2,system_hotel_id:121,hotel_name:'Fixture hotel 121'});
  const state=vm.createContext({console,window:{},URLSearchParams,Blob,
    onlineDataFilter:{value:{hotel_id:hotel,source:'meituan',data_types:'advertising',start_date:'2026-09-25',end_date:'2026-09-25'}},
    onlineDataPage:{value:1},onlineDataPagination:{value:{page_size:30}},
    onlineDataLoadedQuery:{value:null},onlineDataListLoading:{value:false},onlineDataListError:{value:''},
    downloadCenterTab:{value:'ads'},meituanForm:{value:{hotelId:'80'}},
    onlineDataListSnapshotKey:query(hotel),onlineDataListSnapshotSession:{epoch:1},epoch:1,
    isAuthSessionCurrent:session=>session?.epoch===state.epoch,
    downloadBlob:(blob,name)=>downloads.push({blob,name}), showToast:(message,type)=>notices.push({message,type}),
  });
  vm.runInContext(staticSource,state);
  state.runMeituanStoredPageCsvDownload=state.window.SUXI_MEITUAN_STATIC.runMeituanStoredPageCsvDownload;
  state.meituanDownloadData={value:state.window.SUXI_MEITUAN_STATIC.buildMeituanDownloadData(rows)};
  vm.runInContext(`${bridge}\nglobalThis.download=downloadMeituanCurrentPageCsv;`,state);
  return {state,downloads,notices};
}

test('edited hotel and business dates cannot relabel previously loaded CSV',()=>{
  const h=harness();
  Object.assign(h.state.onlineDataFilter.value,{hotel_id:'121',start_date:'2026-09-26',end_date:'2026-09-26'});
  assert.equal(h.state.download(),false);
  assert.equal(h.downloads.length,0);
  assert.match(h.notices.at(-1).message,/重新查询/);
});

test('all-history hotels stay all and exported rows keep date, quality and zero',async()=>{
  const h=harness('');
  assert.equal(h.state.download(),true);
  assert.equal(h.downloads[0].name,'meituan-advertising-all-2026-09-25-page-1.csv');
  const csv=await h.downloads[0].blob.text();
  assert.match(csv,/Fixture hotel 80,2026-09-25/);
  assert.match(csv,/Fixture hotel 121,2026-09-25/);
  assert.match(csv,/,0,,/);
  assert.match(csv,/,partial,0/);
});

test('unloaded and old-account snapshots cannot export and current reload can recover',()=>{
  const h=harness();
  h.state.onlineDataListSnapshotKey='';
  assert.equal(h.state.download(),false);
  h.state.onlineDataListSnapshotKey=query();h.state.epoch=2;
  assert.equal(h.state.download(),false);
  assert.equal(h.downloads.length,0);
  h.state.onlineDataListSnapshotSession={epoch:2};
  assert.equal(h.state.download(),true);
  assert.equal(h.downloads.length,1);
});

test('changed page, page size and category require query before download',()=>{
  for(const mutate of [h=>h.state.onlineDataPage.value=2,h=>h.state.onlineDataPagination.value.page_size=50,h=>h.state.onlineDataFilter.value.data_types='search_keyword',h=>h.state.onlineDataFilter.value.end_date='2026-09-26',h=>h.state.onlineDataFilter.value.create_start='2026-09-25']){
    const h=harness();mutate(h);
    assert.equal(h.state.download(),false);
    assert.equal(h.downloads.length,0);
  }
});

test('a reloaded date interval is named as that complete interval',()=>{
  const h=harness();
  h.state.onlineDataFilter.value.end_date='2026-09-26';
  h.state.onlineDataListSnapshotKey=query().replace('end_date=2026-09-25','end_date=2026-09-26');
  assert.equal(h.state.download(),true);
  assert.equal(h.downloads[0].name,'meituan-advertising-80-2026-09-25-to-2026-09-26-page-1.csv');
});
