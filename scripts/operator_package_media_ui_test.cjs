'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(path.join(__dirname,'../operator/optourpackages.php'),'utf8');
class Element {
  constructor(){this.style={};this.dataset={};this.value='';this.disabled=false;this.innerText='Save changes';this.events={};this.files=[];this.classList={add(){},remove(){}};}
  addEventListener(type,fn){(this.events[type]??=[]).push(fn);}
  async emit(type,event={}){for(const fn of this.events[type]||[])await fn(event);}
  click(){}
  set src(value){this._src=value;if(this.onload)Promise.resolve().then(()=>this.onload());}
  get src(){return this._src;}
}
const nodes={};const get=id=>nodes[id]??=new Element();
for(const id of ['editModal','imageModal','dragArea','imageInput','cropContainer','cropImage','doneBtn','cancelBtn'])get(id);
get('editModal').dataset.mode='edit';get('editModal').style.display='flex';
const previews={package_image:{src:'https://example.test/existing.webp'},package_image2:{src:'img/placeholder.png'}};
const bodies=[],optimized=[],cropExports=[],revoked=[];let serial=0,failOptimize=false;
const optimizer={setButtonBusy(button,busy){button.disabled=busy;},async optimizeSource(file,max){optimized.push([file,max]);if(failOptimize)throw Error('Invalid image');return {type:file.type};},async exportCrop(cropper,type,options){cropExports.push({type,options});return {type,size:1024};}};
class FormDataFixture {
  constructor(){this.entries={package_id:'42',package_title:'Existing package'};}
  append(field,blob,name){this.entries[field]={blob,name};}
}
const context={console,document:{getElementById:get,body:{classList:{add(){},remove(){}}},querySelector(selector){if(selector.includes('data-package-tab'))return {dataset:{packageTab:'information'}};const match=selector.match(/img\[data-field="([^"]+)"\]/);return match?previews[match[1]]:null;}},
  editModal:get('editModal'),imageModal:get('imageModal'),dragArea:get('dragArea'),imageInput:get('imageInput'),cropContainer:get('cropContainer'),cropImage:get('cropImage'),doneBtn:get('doneBtn'),cancelBtn:get('cancelBtn'),
  ItourImageOptimizer:optimizer,URL:{createObjectURL:()=>`blob:fixture-${++serial}`,revokeObjectURL:url=>revoked.push(url)},Cropper:class{destroy(){this.destroyed=true;}},
  FormData:FormDataFixture,updateDisplayOrders(){},closeModal(){},location:{reload(){}},Swal:{fire:async()=>({})},
  fetch:async(_url,options)=>{bodies.push(options.body.entries);return {json:async()=>({success:false,message:'Fixture response'})};}};
vm.createContext(context);
const state=source.slice(source.indexOf('let currentPackageId = null;'),source.indexOf('/* =====================================================\n   DOM ELEMENTS'));
const media=source.slice(source.indexOf('function openGeneralImageModal(field){'),source.indexOf('function renderEmptyPackageImages(){'));
vm.runInContext(state+media,context);
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const submit=async()=>{await get('editPackageForm').emit('submit',{preventDefault(){},target:get('editPackageForm')});await tick();await tick();};
(async()=>{
  await submit();assert.deepEqual(Object.keys(bodies.at(-1)),['package_id','package_title'],'Unchanged save sends neither existing URLs nor placeholders');
  context.openGeneralImageModal('package_image');
  await context.handleOperatorPackageFile({size:30*1024*1024,type:'image/webp'});
  assert.equal(optimized.at(-1)[1],4096,'Same admin optimizer prepares the large source');
  await get('doneBtn').emit('click');await submit();
  assert.equal(bodies.at(-1).package_image.name,'package_image.webp');assert.equal(bodies.at(-1).package_image.blob.type,'image/webp');
  assert.deepEqual(Object.keys(bodies.at(-1)),['package_id','package_title','package_image'],'Only modified photo is uploaded');
  assert.equal(cropExports.at(-1).options.maxWidth,1600);assert.equal(cropExports.at(-1).options.maxHeight,1200);
  const previous=previews.package_image.src;
  context.openGeneralImageModal('package_image');await context.handleOperatorPackageFile({type:'image/png'});await get('doneBtn').emit('click');
  assert.ok(revoked.includes(previous),'Replacing a pending preview releases its URL');
  context.openGeneralImageModal('route_image');await context.handleOperatorPackageFile({type:'image/jpeg'});await get('doneBtn').emit('click');await submit();
  assert.equal(bodies.at(-1).route_image.name,'route_image.jpg');assert.equal(cropExports.at(-1).options.maxWidth,1920);assert.equal(cropExports.at(-1).options.maxHeight,1080);
  context.resetPendingPackageImages();await submit();assert.deepEqual(Object.keys(bodies.at(-1)),['package_id','package_title'],'New editing session cannot reuse previous package uploads');
  context.openGeneralImageModal('package_image');await context.handleOperatorPackageFile({type:'image/jpeg'});context.closeImageModal();await submit();assert.equal(bodies.at(-1).package_image,undefined,'Cancelling a crop does not replace saved photo');
  failOptimize=true;context.openGeneralImageModal('package_image');await context.handleOperatorPackageFile({type:'image/jpeg'});await submit();assert.equal(bodies.at(-1).package_image,undefined,'Failed optimization cannot submit an image');
  assert.equal(get('imageModal').style.display,'none');

  // Verify the real shared optimizer's file-size/MIME checks, without browser canvas mocks.
  class FileFixture{constructor(size,type){this.size=size;this.type=type;}}
  const shared={window:{},File:FileFixture};vm.createContext(shared);vm.runInContext(fs.readFileSync(path.join(__dirname,'../js/image-upload-optimizer-v2.js'),'utf8'),shared);
  const validate=shared.window.ItourImageOptimizer.validateFile;
  validate(new FileFixture(40*1024*1024,'image/webp'));
  assert.throws(()=>validate(new FileFixture(40*1024*1024+1,'image/jpeg')),/40 MB/);
  assert.throws(()=>validate(new FileFixture(1024,'image/svg+xml')),/valid JPG/);
  assert.throws(()=>validate(new FileFixture(0,'image/png')),/empty/);
  for(const match of source.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)){
    if(/\bsrc\s*=|application\/(?:ld\+)?json/i.test(match[1]))continue;
    new vm.Script(match[2].replace(/<\?[\s\S]*?\?>/g,'null'),{filename:'operator/optourpackages.php'});
  }
  console.log('Passed operator package UI checks: unchanged save, changed-only multipart uploads, shared optimization, JPEG/PNG/WebP, crop cancellation, package reset, size/type limits and inline syntax.');
})().catch(error=>{console.error(error);process.exitCode=1;});
