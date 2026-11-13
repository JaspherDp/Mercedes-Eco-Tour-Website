'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('js/header.js','utf8');
const logoutCode=source.slice(source.indexOf('async function headNavLogout('),source.indexOf('// Keep booking-triggered login'));
function fixture(options={}) {
 const calls={loads:0,posts:[],confirm:0,alerts:0,dialogs:[],menuClose:0};
 const element=()=>({dataset:{},attrs:{},listeners:[],setAttribute(k,v){this.attrs[k]=v;},removeAttribute(k){delete this.attrs[k];},addEventListener(type,fn){this.listeners.push(fn);},click(){this.lastClick=this.listeners[0]({preventDefault(){},currentTarget:this});return this.lastClick;}});
 const button=element(),mobile=element();
 const dialog={async fire(config){calls.dialogs.push(config.icon);if(config.icon==='warning')return {isConfirmed:options.confirmed!==false};if(options.successDialogFails&&config.icon==='success')throw Error('Dialog failed');return {};}};
 const window={location:{href:'original'},confirm(){calls.confirm++;return options.confirmed!==false;},alert(){calls.alerts++;},setTimeout(fn){if(options.cdn==='timeout')queueMicrotask(fn);return 1;},clearTimeout(){}};
 if(options.preloaded)window.Swal=dialog;
 const document={getElementById:id=>id==='logoutBtn'?button:id==='headNavMobileToggle'?{getAttribute:()=> 'true',click(){calls.menuClose++;}}:null,querySelectorAll:()=>[mobile]};
 const scope={window,document,console:{error(){}},loadScriptOnce:async()=>{calls.loads++;if(options.cdn==='fail')throw Error('CDN unavailable');if(options.cdn==='timeout')await new Promise(()=>{});window.Swal=dialog;},fetch:async(url,config)=>{calls.posts.push({url,config});if(options.networkFails)throw Error('Offline');return {ok:!options.serverFails};}};
 vm.createContext(scope);vm.runInContext(logoutCode,scope);
 const binding=source.slice(source.indexOf('    // Idempotent binding:'),source.indexOf('    // Scroll to top',source.indexOf('    // Idempotent binding:')));
 const bind=()=>vm.runInContext('(function(){'+binding+'})()',scope);bind();bind();
 assert.equal(button.listeners.length,1,'Repeated header initialization must not duplicate logout handlers');
 assert.equal(mobile.listeners.length,1,'Mobile logout must bind once');
 return {scope,window,button,mobile,calls,dialog};
}
(async()=>{
 for(const options of [{preloaded:true},{},{cdn:'fail'},{cdn:'timeout'}]) {
  const f=fixture(options);await f.button.click();assert.equal(f.calls.posts.length,1);assert.equal(f.calls.posts[0].url,'php/logout.php');assert.equal(f.calls.posts[0].config.method,'POST');assert.equal(f.calls.posts[0].config.credentials,'same-origin');assert.equal(f.window.location.href,'./');assert.equal(f.button.attrs['aria-busy'],undefined);assert.equal(f.calls.loads,options.preloaded?0:1);
 }
 for(const options of [{preloaded:true,confirmed:false},{cdn:'fail',confirmed:false}]){const f=fixture(options);await f.button.click();assert.equal(f.calls.posts.length,0);assert.equal(f.window.location.href,'original');}
 for(const options of [{preloaded:true,serverFails:true},{cdn:'fail',networkFails:true}]){const f=fixture(options);await f.button.click();assert.equal(f.window.location.href,'original');assert(f.calls.alerts||f.calls.dialogs.includes('error'));assert.equal(f.button.dataset.logoutPending,undefined);}
 const f=fixture({preloaded:true});let release;f.dialog.fire=config=>config.icon==='warning'?new Promise(resolve=>{release=resolve;}):Promise.resolve({});const first=f.button.click();await f.button.click();release({isConfirmed:true});await first;assert.equal(f.calls.posts.length,1,'Rapid double clicks submit once');
 const m=fixture({});m.mobile.click();await m.button.lastClick;assert.equal(m.calls.menuClose,1);assert.equal(m.calls.posts.length,1);assert.equal(m.window.location.href,'./');
 const d=fixture({preloaded:true,successDialogFails:true});await d.button.click();assert.equal(d.window.location.href,'./');
 console.log('PASS: desktop/mobile logout, loaded/lazy/failed/timed-out dialogs, cancellation, double clicks, repeated initialization, server/network errors and post-logout redirect.');
})().catch(error=>{console.error(error);process.exitCode=1;});
