'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync('js/page-loader.js','utf8');
function fixture(homepage){
 const timers=[];let removed=0,unlocked=0,leaving=0;
 const loader={parentNode:{removeChild(){removed++;}},classList:{add(){leaving++;}},setAttribute(){}};
 const document={body:{classList:{contains:()=>homepage}},readyState:'complete',documentElement:{classList:{remove(){unlocked++;}}},getElementById:()=>loader};
 const window={setTimeout(fn,ms){timers.push({fn,ms});},addEventListener(){}};
 vm.runInNewContext(code,{document,window,Date});
 return{timers,get removed(){return removed;},get unlocked(){return unlocked;},get leaving(){return leaving;}};
}
const home=fixture(true);assert.equal(home.timers[0].ms,0);home.timers[0].fn();assert.equal(home.removed,1);assert.equal(home.unlocked,1);assert.equal(home.leaving,0);
const other=fixture(false);assert(other.timers[0].ms>400);other.timers[0].fn();assert.equal(other.leaving,1);assert.equal(other.removed,0);other.timers.at(-1).fn();assert.equal(other.removed,1);
const html=fs.readFileSync('public/homepage.php','utf8');
const marker=html.indexOf('// Reveal server-rendered content'); const safety=html.slice(html.lastIndexOf('<script>',marker)+8,html.indexOf('</script>',marker));
let callback,removed=0,unlocked=0;
vm.runInNewContext(safety,{window:{setTimeout(fn,ms){assert.equal(ms,1800);callback=fn;}},document:{documentElement:{classList:{remove(){unlocked++;}}},getElementById:()=>({remove(){removed++;}})}});
callback();assert.equal(removed,1);assert.equal(unlocked,1);
console.log('PASS: homepage reveals immediately, other-page loader timing remains intact, external-script failure cannot leave the homepage covered.');
