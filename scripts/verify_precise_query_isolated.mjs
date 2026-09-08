import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import net from 'node:net';
import {spawn} from 'node:child_process';
import {chromium} from 'playwright';
import assert from 'node:assert/strict';

const root=path.resolve(import.meta.dirname,'..');
const output=path.join(root,'output/long-goal');fs.mkdirSync(output,{recursive:true});
const database=path.join(os.tmpdir(),`l03-synthetic-http-${process.pid}.sqlite`);
const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));const port=reserve.address().port;await new Promise(r=>reserve.close(r));
const base=`http://127.0.0.1:${port}`;
const php=spawn('C:/xampp/php/php.exe',['-S',`127.0.0.1:${port}`,'tests/Support/precise_query_http_fixture.php'],{cwd:root,env:{...process.env,L03_SYNTHETIC_DATABASE:database},windowsHide:true,stdio:['ignore','pipe','pipe']});
let serverLog='';php.stdout.on('data',d=>serverLog+=d);php.stderr.on('data',d=>serverLog+=d);
let browser; let page; const errors=[]; const calls=[];
try {
  for(let i=0;i<60;i++){try{await fetch(base);break;}catch{await new Promise(r=>setTimeout(r,100));}}
  const payload={query:'最近七天携程收入',current_scope:{hotel_id:80},client_request_key:'http-income-clarify'};
  const post=async(body)=>{const r=await fetch(base+'/api/agent/precise-queries',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});return {status:r.status,body:await r.json()};};
  const first=await post(payload);assert.equal(first.status,200);assert.equal(first.body.data.answer.reason,'revenue_definition_ambiguous');
  const secondPayload={query:'订单金额',current_scope:{hotel_id:80},parent_question_id:first.body.data.id,client_request_key:'http-order-amount'};
  const second=await post(secondPayload);assert.equal(second.status,200);assert.equal(second.body.data.answer.value,null);assert.equal(second.body.data.answer.partial_value,522);
  const exact=await (await fetch(base+`/api/agent/precise-queries/${second.body.data.id}`)).json();assert.deepEqual(exact.data,second.body.data);
  const duplicate=await post(secondPayload);assert.deepEqual(duplicate.body.data,second.body.data);
  const conflict=await post({...secondPayload,query:'间夜'});assert.equal(conflict.status,409);assert.equal(conflict.body.code,409);
  const unauthorized=await post({query:'酒店90携程昨天订单量',current_scope:{hotel_id:80}});assert.equal(unauthorized.status,403);
  fs.writeFileSync(path.join(output,'synthetic-api-evidence.json'),JSON.stringify({evidence:'synthetic-only',clarification:first.body.data,partial:second.body.data,readback_equal:true,duplicate_equal:true,conflict_status:409,unauthorized_status:403},null,2));
  browser=await chromium.launch({headless:true});
  page=await browser.newPage({viewport:{width:1365,height:1000}});page.on('pageerror',e=>errors.push(String(e)));
  page.on('console',message=>{if(message.type()==='error') errors.push(message.text());});
  page.on('response',async response=>{if(response.url().includes('/api/agent/precise-queries')) calls.push({url:response.url(),status:response.status(),body:await response.text()});});
  await page.goto(base);await page.getByTestId('system-guide-input').waitFor();
  await page.getByTestId('system-guide-input').fill('携程最近七天订单金额');await page.getByTestId('system-guide-submit').click();
  await page.getByTestId('precise-query-conditions').waitFor();
  const card=page.getByTestId('precise-query-fact-card');await card.waitFor();
  assert.match(await card.innerText(),/5\/7/);assert.match(await card.innerText(),/非全期间金额：522/);assert.match(await card.innerText(),/2026-09-02、2026-09-04/);
  const conditions=await page.getByTestId('precise-query-conditions').innerText();
  await page.getByTestId('precise-query-conditions').evaluate(el=>el.scrollIntoView({block:'start'}));
  await page.screenshot({path:path.join(output,'synthetic-conditions.png'),fullPage:true});
  await card.evaluate(el=>el.scrollIntoView({block:'start'}));
  await page.screenshot({path:path.join(output,'synthetic-desktop.png'),fullPage:true});
  await page.reload();await page.getByTestId('precise-query-conditions').waitFor();assert.equal(await page.getByTestId('precise-query-conditions').innerText(),conditions);
  await page.setViewportSize({width:390,height:844});await card.evaluate(el=>el.scrollIntoView({block:'start'}));await page.screenshot({path:path.join(output,'synthetic-mobile.png'),fullPage:true});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth),false,'page must not overflow narrow viewport');
  assert.deepEqual(errors,[]);
  fs.writeFileSync(path.join(output,'synthetic-ui-evidence.json'),JSON.stringify({evidence:'synthetic-only',desktop:[1365,1000],mobile:[390,844],exact_refresh:true,partial_render:true,page_errors:errors,scope_text:conditions},null,2));
  console.log('PASS isolated HTTP: clarify, partial, exact ID, duplicate, 409, 403; actual widget: partial evidence, refresh, desktop/mobile.');
} catch(error) {
  if(page){fs.writeFileSync(path.join(output,'synthetic-ui-failure.json'),JSON.stringify({errors,calls,body:await page.locator('body').innerText()},null,2));await page.screenshot({path:path.join(output,'synthetic-ui-failure.png'),fullPage:true});}
  throw error;
} finally {
  await browser?.close();php.kill();fs.writeFileSync(path.join(output,'synthetic-http.log'),serverLog);
  // The uniquely named test database is disposable; no application DB is touched.
  await new Promise(r=>setTimeout(r,200));if(fs.existsSync(database))fs.unlinkSync(database);
}
