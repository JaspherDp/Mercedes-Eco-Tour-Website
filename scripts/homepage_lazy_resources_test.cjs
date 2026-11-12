'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const header=fs.readFileSync('js/header.js','utf8'),home=fs.readFileSync('js/homepage.js','utf8');
async function authChecks(){
 const nodes={},loads=[],calls=[];let fetches=0,fail=false;
 const window={};
 const document={getElementById:id=>nodes[id],querySelector:()=>null,createElement:()=>({dataset:{},remove(){},set innerHTML(value){nodes.modalOverlay={html:value};}}),body:{appendChild(node){
  if(!node.src){nodes[node.id]=node;return;}loads.push(node.src);
  queueMicrotask(()=>{if(node.src.includes('sweetalert'))window.Swal={};else if(fail){fail=false;node.onerror();return;}else window.initLogSignEvents=()=>{window.AuthModalStore={open:async options=>calls.push(options)};};node.onload();});
 }}};
 const scope={window,document,URLSearchParams,console:{error(){}},fetch:async()=>{fetches++;return{ok:true,text:async()=>'<form></form>'};}};
 vm.createContext(scope);vm.runInContext(header.slice(header.indexOf('function loadScriptOnce'),header.indexOf('// Keep booking-triggered login')),scope);
 assert.equal(fetches,0);
 const first=scope.ensureGlobalAuthModal(),second=scope.ensureGlobalAuthModal();assert.equal(first,second);
 await first;assert.equal(fetches,1);assert.equal(loads.length,2);
 await window.AuthModalStore.open({returnTo:'hotel_booking.php?id=1'});assert.equal(calls[0].returnTo,'hotel_booking.php?id=1');
 delete window.__globalAuthModalPromise;delete window.__headNavScriptPromises;delete window.initLogSignEvents;delete window.AuthModalStore;delete nodes.modalOverlay;
 fail=true;await assert.rejects(scope.ensureGlobalAuthModal(),/Failed to load script/);await scope.ensureGlobalAuthModal();assert.equal(typeof window.AuthModalStore.open,'function');
 let requested=0,replaced='';window.ensureGlobalAuthModal=async()=>{requested++;};window.location={search:'',pathname:'/',hash:'#section'};window.history={replaceState:(_a,_b,url)=>{replaced=url;}};
 vm.runInContext(home.slice(home.indexOf('  function loadLoginModal()'),home.indexOf('  function loadHeader()')),scope);
 scope.loadLoginModal();assert.equal(requested,0,'Ordinary visits do not load login');
 window.location.search='?open_login=1&destination=apuao';scope.loadLoginModal();for(let i=0;i<5;i++)await Promise.resolve();
 assert.equal(requested,1);assert.equal(replaced,'/?destination=apuao#section');assert.equal(calls.at(-1).resume,true);
}
function galleryChecks(){
 let intersect,ticks=0,stops=0;
 const element=()=>({dataset:{},style:{},classList:{toggle(){},remove(){}},setAttribute(){},addEventListener(){}});
 const slides=[element(),element()];slides.forEach((slide,i)=>{slide.dataset.galleryImage=`photo-${i}.webp`;});const slider=element();
 const document={hidden:false,addEventListener(){},getElementById:()=>null,querySelectorAll:selector=>selector==='.fe-slide'?slides:[],querySelector:selector=>selector==='.fe-slider'?slider:null};
 const window={IntersectionObserver:true,matchMedia:()=>({matches:false}),setInterval(){ticks++;return ticks;},clearInterval(){stops++;}};
 const scope={document,window,JSON,IntersectionObserver:function(fn){intersect=fn;this.observe=()=>{};}};vm.createContext(scope);
 vm.runInContext(home.slice(home.indexOf('  function initGallery()'),home.indexOf('  function initTourCategories()'))+'\ninitGallery();',scope);
 assert.equal(slides[0].style.backgroundImage,undefined);assert.equal(ticks,0);
 intersect([{isIntersecting:true}]);assert.equal(slides[0].style.backgroundImage,'url("photo-0.webp")');assert.equal(slides[1].style.backgroundImage,undefined);assert.equal(ticks,1);
 const before=stops;intersect([{isIntersecting:false}]);assert(stops>before);
}
(async()=>{await authChecks();galleryChecks();console.log('PASS: lazy login, concurrent loads, retry, return URL/resume links, and offscreen gallery downloads/timers.');})().catch(error=>{console.error(error);process.exitCode=1;});
