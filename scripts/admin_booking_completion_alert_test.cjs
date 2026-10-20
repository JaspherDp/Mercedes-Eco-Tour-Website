const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const source=fs.readFileSync('admin/adbookings.php','utf8');
const code=source.slice(source.indexOf('function completeBookingWithoutBalance('),source.indexOf('async function cancelAdminPayMongoPayment('));
async function scenario(confirmed,success){
 const calls=[];let requests=0,reloaded=false,ack=false;
 const ctx=vm.createContext({FormData,adminBookingCsrf:'csrf',window:{location:{href:'https://example.test/adbookings.php'}},location:{reload(){assert(ack);reloaded=true;}},
 Swal:{showLoading(){},async fire(o){calls.push(o);if(calls.length===1)return {isConfirmed:confirmed};if(o.icon==='success'){assert(!reloaded);assert.equal(o.confirmButtonColor,'#176b58');ack=true;}return {}; }},
 fetch:async(_,o)=>{requests++;assert.equal(o.body.get('finish_json'),'1');assert.equal(o.body.get('csrf_token'),'csrf');return {ok:success,json:async()=>({success,message:'Result'})};}});
 vm.runInContext(code,ctx);await ctx.completeBookingWithoutBalance(8);
 if(!confirmed){assert.equal(requests,0);assert(!reloaded);return;}
 assert.equal(calls[1].title,'Completing booking');assert.equal(calls[2].icon,success?'success':'error');assert.equal(reloaded,success);
}
(async()=>{await scenario(true,true);await scenario(false,true);await scenario(true,false);console.log('PASS: completion waits for green success alert before reload; cancelled/failed completion does not reload.');})().catch(e=>{console.error(e);process.exitCode=1;});
