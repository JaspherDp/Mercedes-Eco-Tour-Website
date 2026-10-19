'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('php/profile.php','utf8');
const start = source.indexOf('async function profileSubmitProviderDecision(');
const end = source.indexOf("document.querySelectorAll('.cancel-booking-btn')", start);
assert.ok(start > 0 && end > start);
async function scenario(success) {
  const submit = {disabled:false}, processing={hidden:true}, alerts=[];
  let requests=0;
  const ctx=vm.createContext({URLSearchParams,bookingCancellationCsrf:'test',
    profileDecisionSubmit:submit,document:{getElementById:()=>processing},window:{location:{href:''}},
    fetch:async()=>{requests++;return {ok:success,json:async()=>({success,message:success?'Saved':'Date unavailable'})};},
    Swal:{fire:async options=>{alerts.push(options);}}
  });
  vm.runInContext(source.slice(start,end),ctx);
  await ctx.profileSubmitProviderDecision({}, {cancellation_request_id:1},'reschedule','2099-01-01');
  assert.equal(requests,1);
  assert.equal(alerts[0].customClass.container,'profile-decision-swal');
  assert.equal(alerts[0].icon,success?'success':'error');
  if(success) assert.equal(ctx.window.location.href,'profile.php?section=bookings');
  else {assert.equal(submit.disabled,false);assert.equal(processing.hidden,true);assert.equal(ctx.window.location.href,'');}
  submit.disabled=true;
  await ctx.profileSubmitProviderDecision({}, {},'reschedule','');
  assert.equal(requests,1,'Prevent duplicate submits');
}
(async()=>{
 await scenario(true);await scenario(false);
 const overlay=Number(source.match(/\.profile-decision-overlay\{[^}]*z-index:(\d+)/)[1]);
 const calendar=Number(source.match(/\.flatpickr-calendar\.profile-reschedule-calendar\{z-index:(\d+)/)[1]);
 const alert=Number(source.match(/\.swal2-container\.profile-decision-swal\{z-index:(\d+)/)[1]);
 assert.ok(alert>overlay&&alert>calendar);
 console.log('PASS: success/error alerts appear above decision modal and calendar; success redirects, failure allows retry, duplicate submits blocked.');
})().catch(error=>{console.error(error);process.exitCode=1;});
