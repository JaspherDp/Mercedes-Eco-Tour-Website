'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
(async()=>{
const source=fs.readFileSync(require('path').join(__dirname,'../js/booking-rejection.js'),'utf8');let handler,submitted=0,created=[],config,loading=0;
class Form{constructor(){this.dataset={};this.inputs=[];this.buttons=[{}];}matches(s){return s==='[data-cancel-form]';}querySelector(){return this.inputs[0]||null;}appendChild(n){this.inputs.push(n);}querySelectorAll(){return this.buttons;}}
Form.prototype.submit=function(){submitted++;};
const scope={HTMLFormElement:Form,document:{addEventListener(type,fn,capture){assert.equal(type,'submit');assert.equal(capture,true);handler=fn;},createElement(){const e={};created.push(e);return e;}},window:{Swal:true,setTimeout:fn=>fn()},Swal:{showLoading(){loading++;},async fire(c){if(c.showConfirmButton!==false)config=c;if(c.didOpen)c.didOpen();return{isConfirmed:true,value:'Weather <unsafe> "quoted"'};}}};vm.createContext(scope);vm.runInContext(source,scope);
const form=new Form();let stopped=0;await handler({target:form,preventDefault(){},stopImmediatePropagation(){stopped++;}});assert.equal(submitted,1);assert.equal(loading,1,'Spinner opened before submission');assert.equal(stopped,1);assert.equal(form.inputs[0].name,'rejection_reason');assert.equal(form.inputs[0].value,'Weather <unsafe> "quoted"');assert.equal(form.buttons[0].disabled,true);assert(config.inputValidator(''));assert(config.inputValidator(' '.repeat(2)));assert(config.inputValidator('a'.repeat(1501)));assert.equal(config.inputValidator('Safety concern'),undefined);
await handler({target:form,preventDefault(){},stopImmediatePropagation(){}});assert.equal(submitted,1,'Repeated submits blocked');
scope.Swal.fire=async()=>({isConfirmed:false});await handler({target:new Form(),preventDefault(){},stopImmediatePropagation(){}});assert.equal(submitted,1,'Cancelled dialog must not submit');
let alerts=0;scope.window.Swal=false;scope.window.prompt=()=>'';scope.window.alert=()=>alerts++;await handler({target:new Form(),preventDefault(){},stopImmediatePropagation(){}});assert.equal(alerts,1);assert.equal(submitted,1);scope.window.prompt=()=>null;await handler({target:new Form(),preventDefault(){},stopImmediatePropagation(){}});assert.equal(submitted,1);
scope.window.prompt=()=> 'Unavailable';await handler({target:new Form(),preventDefault(){},stopImmediatePropagation(){}});assert.equal(submitted,2,'Fallback prompt submits valid reason');
const flashScope={document:{addEventListener(){}},window:{Swal:true,bookingRejectionFlash:{icon:'success',message:'Email sent'}},Swal:{fire(c){assert.equal(c.confirmButtonColor,'#176b58');}}};vm.createContext(flashScope);vm.runInContext(source,flashScope);
console.log('PASS: shared operator/hotel reason dialog, required/length validation, safe reason values, cancellation and repeat-submit protection.');
})().catch(e=>{console.error(e);process.exitCode=1;});
