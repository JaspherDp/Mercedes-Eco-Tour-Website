'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('js/tourist-pdf-preview.js','utf8');
const importCall="import(new URL('pdf.min.mjs', baseUrl).href)";
assert.ok(source.includes(importCall));
async function fixture({status=200,pdf=true,moduleFailure=false,delayed=false}={}){
 const error={hidden:true,querySelector:()=>message},message={textContent:''},body={clientWidth:600,scrollTop:0,classList:{add(){},remove(){}}};
 const pages={clientWidth:600,closest:()=>body,querySelectorAll:()=>[],replaceChildren(){},append(){}};
 const elements={touristProfilePdfPages:pages,touristProfilePdfError:error,touristProfilePdfOpen:{},touristProfilePdfRetry:{addEventListener(){}}};
 let loadCount=0,rendered=0,received,fetchOptions,resolveFetch;
 const response={status,ok:status===200,arrayBuffer:async()=>new TextEncoder().encode(pdf?'%PDF-fixture':'<html>Login</html>').buffer};
 const library={GlobalWorkerOptions:{},getDocument(options){received=options;return {destroy:async()=>{},promise:Promise.resolve({numPages:1,getPage:async()=>({getViewport:()=>({width:600,height:800}),render(){rendered++;return {promise:Promise.resolve(),cancel(){}};},cleanup(){}})})};}};
 const ctx=vm.createContext({URL,Uint8Array,AbortController,console:{error(){}},Error,window:{devicePixelRatio:1},
 document:{currentScript:{src:'https://example.test/js/tourist-pdf-preview.js'},getElementById:id=>elements[id],createElement:()=>({append(){},setAttribute(){},getContext:()=>({})})},
 fetch:async(_,options)=>{fetchOptions=options;if(delayed)return new Promise(resolve=>{resolveFetch=()=>resolve(response);});return response;},
 loadPdfLibrary:async()=>{loadCount++;if(moduleFailure)throw new Error('Module blocked');return library;}
 });
 vm.runInContext(source.replace(importCall,'loadPdfLibrary()'),ctx);
 const task=ctx.window.TouristPdfPreview.open('private.pdf');
 if(delayed){ctx.window.TouristPdfPreview.close();resolveFetch();}
 await task;
 return {error,message,loadCount,rendered,received,fetchOptions,ctx};
}
(async()=>{
 let r=await fixture();assert.equal(r.rendered,1);assert.equal(r.error.hidden,true);assert.ok(r.received.data instanceof Uint8Array);assert.equal(r.fetchOptions.credentials,'same-origin');assert.equal(r.fetchOptions.cache,'no-store');
 for(const status of [401,403,404,500]){r=await fixture({status});assert.equal(r.loadCount,0);assert.equal(r.error.hidden,false);assert.match(r.message.textContent,status<404?/session may have expired/:/HTTP/);}
 r=await fixture({pdf:false});assert.equal(r.loadCount,0);assert.match(r.message.textContent,/did not return a PDF/);
 r=await fixture({moduleFailure:true});assert.equal(r.error.hidden,false);assert.equal(r.rendered,0);
 r=await fixture({delayed:true});assert.equal(r.error.hidden,true);assert.equal(r.loadCount,0);assert.equal(r.fetchOptions.signal.aborted,true);
 assert.match(fs.readFileSync('.htaccess','utf8'),/AddType application\/javascript \.mjs/);
 console.log('PASS: private PDF rendering, authenticated no-cache fetch, login/server errors, non-PDF response, module failure and closing during load.');
})().catch(error=>{console.error(error);process.exitCode=1;});
