import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync(new URL('../../public/app-main.js',import.meta.url),'utf8');
const start=source.indexOf('const ctripPublicProfileNumber ='),end=source.indexOf('const normalizeCtripPublicProfileComparable =',start);
assert.ok(start>=0&&end>start);
const profile=(latitude,longitude,role='competitor')=>({role,fields:{latitude,longitude}});
function harness(self=profile(31.2304,121.4737,'self')){
 const own={value:self};
 const context=vm.createContext({ctripPublicProfileSelf:own});
 vm.runInContext(source.slice(start,end)+';globalThis.coordinates=ctripPublicProfileCoordinates;globalThis.distance=ctripPublicProfileDistanceText;',context);
 return {...context,own};
}

test('explicit null coordinates from persisted profiles do not imply zero distance',()=>{
 const {coordinates,distance}=harness(profile(null,null,'self'));
 assert.equal(coordinates(profile(null,null)),null);
 assert.equal(distance(profile(null,null)),'待补坐标');
});
test('each of the four coordinate inputs must exist and be a valid scalar',()=>{
 for(const invalid of [null,undefined,'','  ','\t\n',false,true,[],[0],{},'未获取',NaN,Infinity,'Infinity']){
  for(const field of ['latitude','longitude']){
   const own=profile(31,121,'self'),target=profile(32,122);
   target.fields[field]=invalid;
   assert.equal(harness(own).distance(target),'待补坐标',`target ${field}: ${String(invalid)}`);
   target.fields[field]=field==='latitude'?32:122;own.fields[field]=invalid;
   assert.equal(harness(own).distance(target),'待补坐标',`self ${field}: ${String(invalid)}`);
  }
 }
});
test('true zero coordinates and numeric legacy strings retain meaningful distances',()=>{
 const {coordinates,distance}=harness(profile(0,0,'self'));
 assert.equal(JSON.stringify(coordinates(profile(' 0 ','0'))),'{"latitude":0,"longitude":0}');
 assert.equal(distance(profile('0','0')),'0.0 km');
 assert.equal(distance(profile(0,1)),'111 km');
 assert.equal(distance(profile(-1,0)),'111 km');
 assert.equal(distance(profile(0,0.01)),'1.1 km');
});
test('coordinate range boundaries and the antipodal calculation stay finite',()=>{
 const {coordinates,distance}=harness(profile(0,0,'self'));
 for(const [lat,lng] of [[90,180],[-90,-180],[0,180]])assert.notEqual(coordinates(profile(lat,lng)),null);
 for(const [lat,lng] of [[90.01,0],[-90.01,0],[0,180.01],[0,-180.01]])assert.equal(coordinates(profile(lat,lng)),null);
 assert.equal(distance(profile(0,180)),'20015 km');
});
test('missing profiles and partial coordinate pairs stay unavailable, self label is retained',()=>{
 assert.equal(harness(null).distance(profile(31,121)),'待补坐标');
 assert.equal(harness().distance({}),'待补坐标');
 assert.equal(harness().distance(profile(null,null,'self')),'本店');
 assert.equal(harness(profile(31,null,'self')).distance(profile(null,121)),'待补坐标');
});
test('completing or clearing coordinates updates distance without stale fallback',()=>{
 const {distance,own}=harness(profile(null,null,'self')),target=profile(null,null);
 assert.equal(distance(target),'待补坐标');
 own.value=profile(0,0,'self');target.fields={latitude:0,longitude:0.01};
 assert.equal(distance(target),'1.1 km');
 target.fields.latitude=null;assert.equal(distance(target),'待补坐标');
});
