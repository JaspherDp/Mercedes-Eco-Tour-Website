'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../js/notification-inbox.js'), 'utf8');
const tick = () => new Promise(setImmediate);
let assertions = 0;
function verify(condition, message) { assert.ok(condition, message); assertions++; }
class Element {
  constructor(tag = 'div') { this.tagName = tag; this.children = []; this.events = {}; this.attributes = {}; this.dataset = {}; this.style = {}; this.isConnected = true; this.hidden = false; this.textContent = ''; this._html = ''; }
  set innerHTML(value) {
    this._html = value;
    if (value.includes('itour-inbox-panel')) {
      this.nodes = {};
      ['bell','badge','panel','list','status','mark'].forEach(name => this.nodes['.itour-inbox-' + name] = new Element(name === 'bell' || name === 'mark' ? 'button' : 'div'));
      this.nodes['.itour-inbox-panel'].hidden = true;
    }
  }
  get innerHTML() { return this._html; }
  querySelector(selector) { return this.nodes?.[selector] || null; }
  addEventListener(type, callback) { this.events[type] = callback; }
  setAttribute(key, value) { this.attributes[key] = value; }
  append(...children) { children.forEach(child => this.appendChild(child)); }
  appendChild(child) { this.children.push(child); child.parentElement = this; return child; }
  prepend(child) { this.children.unshift(child); }
  replaceChildren(...children) { this.children = []; this.append(...children); }
  replaceWith(next) { this.replacement = next; this.isConnected = false; }
  closest() { return null; }
  contains(node) { return node === this || this.children.includes(node); }
  click() { this.clicked = true; return this.events.click?.({ target: this, preventDefault() {} }); }
  focus() { this.focused = true; }
}
async function fixture(role, {payload, search = '', buttons = [], billing = [], pending = 0, readyState = 'complete', dynamic = false, noHeader = false} = {}) {
  const legacy = new Element();
  const main = new Element('main');
  const events = {};
  const intervals = [];
  const calls = [];
  const navigations = [];
  let response = payload || {success:true, unread:1, data:[{notification_id:8, title:'Tourist Information Required', message:'Please submit the guest details for your booking.', read_at:null, created_at:'2026-10-19 12:00:00'}]};
  let postFails = false;
  const document = {
    readyState, hidden:false, currentScript:{defer:!dynamic},
    head:new Element('head'),
    createElement:tag => new Element(tag),
    querySelector:selector => selector === '[data-inbox-config]' ? null : selector.startsWith('link') ? null : selector.startsWith('main,') ? main : noHeader && selector === '.head-nav-main-header .head-nav-notif-wrap' ? null : legacy.isConnected ? legacy : null,
    querySelectorAll:selector => selector.startsWith('.billing') ? billing : buttons,
    getElementById:() => null,
    addEventListener:(type,fn) => { (events[type] ||= []).push(fn); }
  };
  const window = {ItourNotificationConfig:{role, base:'/fixture/', csrf:'fixture-csrf', pending}, location:{search,assign:url=>navigations.push(url),replace:url=>navigations.push(url)}};
  const context = {window, document, URLSearchParams, Date, Number, console,
    setInterval:(fn,ms) => {intervals.push({fn,ms});return intervals.length;}, clearInterval(){},
    fetch:async (url,options) => {
      calls.push({url,options});
      const post = options.method === 'POST';
      return {ok:post ? !postFails : response.success, status:post ? postFails ? 403 : 200 : response.status || 200,
        json:async () => post ? postFails ? {success:false,message:'Read status could not be saved.'} : {success:true} : response};
    }
  };
  vm.createContext(context); vm.runInContext(source,context);
  if (readyState !== 'complete' && !dynamic) {
    verify(!legacy.replacement, 'deferred mount waits until legacy header callbacks have run');
    for (const fn of events.DOMContentLoaded || []) fn();
  }
  await tick();
  const wrap = legacy.replacement;
  const nodes = wrap?.nodes;
  return {legacy,main,wrap,nodes,document,events,calls,intervals,navigations,context,window,
    setResponse:value=>{response=value;}, failPost:value=>{postFails=value;}};
}
(async () => {
  const bookingSource=fs.readFileSync(path.join(__dirname,'../admin/adbookings.php'),'utf8');
  const filterStart=bookingSource.indexOf('// Initialize before remote report/payment scripts');
  const filterCode=bookingSource.slice(filterStart,bookingSource.indexOf('</script>',filterStart));
  const filterEvents={};
  const fields={adRangeFilter:{value:'all',addEventListener:(name,fn)=>{filterEvents[name]=fn;}},adRangeYear:{value:'2026'},adRangeMonth:{value:'10'},adRangeDate:{value:'2026-10-19'}};
  vm.runInNewContext(filterCode,{document:{getElementById:()=>({querySelector:selector=>fields[selector.slice(1)]})}});
  for(const mode of ['all','yearly','monthly','daily','all']){
    fields.adRangeFilter.value=mode;filterEvents.change();
    verify(fields.adRangeYear.hidden === !['yearly','monthly','daily'].includes(mode) && fields.adRangeMonth.hidden === (mode!=='monthly') && fields.adRangeDate.hidden === (mode!=='daily'),`booking filter ${mode} initializes independently of external scripts`);
  }
  verify(fields.adRangeDate.value==='2026-10-19' && fields.adRangeMonth.value==='10','booking filter visibility changes retain selections');
  // Homepage loads the module inside/after DOMContentLoaded while images can
  // keep readyState interactive. There will be no second DOMContentLoaded.
  const dynamic = await fixture('tourist',{readyState:'interactive',dynamic:true});
  verify(dynamic.calls.length === 1 && !!dynamic.wrap,'late-loaded homepage inbox mounts without waiting for an already-fired event');
  dynamic.nodes['.itour-inbox-bell'].click();
  verify(!dynamic.nodes['.itour-inbox-panel'].hidden,'late-loaded homepage notification bell opens');
  verify(dynamic.nodes['.itour-inbox-bell'].children[0]?.src === '/fixture/img/notificon.png','tourist header uses its original bell image');
  const profile = await fixture('tourist',{noHeader:true});
  verify(!profile.wrap && profile.calls.length === 0 && profile.intervals.length === 0,'profile without the website header creates no bell or inbox polling');
  const linked = new Element('button'); linked.dataset.booking = JSON.stringify({booking_id:42});
  await fixture('tourist',{noHeader:true,search:'?notification_booking=42',buttons:[linked]});
  verify(linked.clicked,'header-free profile still opens the exact linked booking');
  const resume = await fixture('tourist',{noHeader:true,pending:42});
  verify(resume.navigations[0]?.endsWith('role=tourist&open=42'),'login resume works on a profile without a bell');
  for (const role of ['tourist','admin','hotel_admin','operator']) {
    const f = await fixture(role,{readyState:'interactive'});
    verify(f.calls[0].url.endsWith(`notification_inbox.php?role=${role}`), `${role}: explicit role endpoint`);
    verify(f.nodes['.itour-inbox-badge'].textContent === '1', `${role}: accurate unread badge`);
    verify(f.nodes['.itour-inbox-list'].children.length === 1, `${role}: compact notification row`);
    verify(f.intervals.length === 1 && f.intervals[0].ms === 60000, `${role}: one lightweight interval`);
    f.nodes['.itour-inbox-bell'].click(); await tick();
    verify(!f.nodes['.itour-inbox-panel'].hidden, `${role}: dropdown opens`);
    verify(f.calls.filter(call=>call.options.method === 'POST').length === 0, `${role}: opening does not silently mark new events read`);
    for (const fn of f.events.keydown) fn({key:'Escape'});
    verify(f.nodes['.itour-inbox-panel'].hidden && f.nodes['.itour-inbox-bell'].focused, `${role}: Escape closes and restores focus`);
    f.failPost(true);
    await f.nodes['.itour-inbox-mark'].click(); await tick();
    verify(!f.nodes['.itour-inbox-mark'].disabled && f.nodes['.itour-inbox-status'].textContent.includes('could not'), `${role}: failed read is recoverable`);
    f.failPost(false);
    f.setResponse({success:true,unread:0,data:[{notification_id:8,title:'Read',message:'Saved',read_at:'2026-10-19 13:00:00',created_at:'2026-10-19 12:00:00'}]});
    await f.nodes['.itour-inbox-mark'].click(); await tick();
    const mutation=f.calls.find(call=>call.options.method === 'POST');
    verify(mutation.options.body.get('csrf_token') === 'fixture-csrf' && mutation.options.body.get('through') === '8', `${role}: scoped CSRF and arrival boundary`);
    verify(f.nodes['.itour-inbox-badge'].hidden, `${role}: badge updated only after saved response`);
    await f.nodes['.itour-inbox-list'].children[0].events.click({preventDefault(){}});
    verify(f.navigations[0].endsWith(`role=${role}&open=8`), `${role}: stable authenticated record resolver`);
    vm.runInContext(source,f.context);
    verify(f.intervals.length === 1, `${role}: loading script twice does not duplicate polling`);
    f.document.hidden = true;
    const before=f.calls.length; f.intervals[0].fn(); await tick();
    verify(f.calls.length === before, `${role}: hidden tab skips polling`);
  }
  const hostile = '<img src=x onerror=alert(1)>';
  const xss = await fixture('tourist',{payload:{success:true,unread:1,data:[{notification_id:2,title:hostile,message:hostile,created_at:'invalid'}]}});
  const row=xss.nodes['.itour-inbox-list'].children[0];
  verify(row.children[0].textContent === hostile && row.children[0].innerHTML === '', 'preview is text, never executable markup');
  verify(row.children[2].textContent === '', 'invalid timestamp fails gracefully');
  const empty = await fixture('operator',{payload:{success:true,unread:0,data:[]}});
  verify(empty.nodes['.itour-inbox-status'].textContent.includes('caught up'), 'empty state');
  const expired = await fixture('admin',{payload:{success:false,status:401,message:'Please sign in again.'}});
  verify(expired.nodes['.itour-inbox-status'].textContent.includes('sign in'), 'expired session is reported');
  const before=expired.calls.length; await expired.window.ItourNotificationInbox.refresh(true);
  verify(expired.calls.length === before, 'polling stops after session expiry');
  const pending=await fixture('operator',{pending:42});
  verify(pending.navigations[0].endsWith('role=operator&open=42'), 'login resumes exact notification through authorized resolver');
  const tour = new Element('button'); tour.dataset.booking = JSON.stringify({booking_id:42});
  const hotel = new Element('button'); hotel.dataset.booking = JSON.stringify({hotel_booking_id:42});
  await fixture('tourist',{search:'?notification_booking=42&notification_domain=hotel',buttons:[tour,hotel]});
  verify(hotel.clicked && !tour.clicked, 'deep link selects hotel despite overlapping tour ID');
  const billing = new Element('button'); billing.dataset={billingId:'42',billingType:'hotel'};
  await fixture('tourist',{search:'?notification_booking=42&notification_domain=hotel',billing:[billing]});
  verify(billing.clicked,'completed hotel booking falls back to existing billing control');
  const decision = new Element('button'); decision.dataset.request = JSON.stringify({cancellation_request_id:6});
  await fixture('tourist',{search:'?notification_booking=42&cancellation_request=6&notification_action=decision',buttons:[decision]});
  verify(decision.clicked,'actionable reschedule opens the existing exact decision control');
  const unavailable=await fixture('tourist',{search:'?notification_booking=999'});
  verify(unavailable.main.children[0]?.textContent.includes('no longer available'), 'unavailable record has visible fallback');
  console.log(`PASS: ${assertions} notification UI assertions across all four roles; DOM fixtures only, no real network or browser rendering.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
