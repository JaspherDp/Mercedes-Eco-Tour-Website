'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('php/profile.php','utf8');
const start=source.indexOf('// ===================== SUBMIT =====================');
const end=source.indexOf('function keepMobileProfileTabVisible',start);
const code=source.slice(start,end).replace(/<\?= json_encode\(\$touristEngagementCsrf\) \?>/g,'"fixture-csrf"');
async function scenario(success,confirmedFields=true){
 let handler,closed=false,reloaded=false,posted=0,ack=false;
 const button={disabled:false,addEventListener(_,fn){handler=fn;}};
 const alerts=[];
 const elements={'af-booking-id':{value:'8'},'af-booking-type':{value:'boat'},'af-feedback-comment':{value:confirmedFields?'Excellent service':''}};
 const ctx=vm.createContext({submitBtn:button,URLSearchParams,document:{getElementById:id=>elements[id],querySelector:()=>({value:'5'})},
 closeModal(){closed=true;},location:{reload(){assert(ack);reloaded=true;}},console:{error(){}},
 fetch:async(_,options)=>{posted++;assert(button.disabled);assert.equal(options.body.get('csrf_token'),'fixture-csrf');return {ok:success,text:async()=>JSON.stringify({success,message:success?'Saved':'Already reviewed'})};},
 Swal:{async fire(options){alerts.push(options);assert.equal(options.customClass.container,'profile-review-swal');assert.equal(options.confirmButtonColor,'#176b58');if(options.icon==='success'){assert(closed,'Review modal closes before success alert');assert(!reloaded);ack=true;}}}
 });
 vm.runInContext(code,ctx);await handler();
 assert.equal(button.disabled,false);
 if(!confirmedFields){assert.equal(posted,0);assert.equal(alerts[0].icon,'warning');assert(!closed);return;}
 assert.equal(alerts[0].icon,success?'success':'error');assert.equal(closed,success);assert.equal(reloaded,success);
 button.disabled=true;await handler();assert.equal(posted,1,'Duplicate submission prevented');
}
(async()=>{await scenario(true);await scenario(false);await scenario(false,false);assert.match(source,/\.swal2-container\.profile-review-swal\{z-index:130200!important\}/);console.log('PASS: successful review closes its modal before success alert and refresh; errors keep form open, alerts are elevated and duplicate submissions blocked.');})().catch(error=>{console.error(error);process.exitCode=1;});
