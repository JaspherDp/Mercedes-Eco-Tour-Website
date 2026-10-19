'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/hotel_details.php', 'utf8');
let instant = '2026-10-19T22:00:00Z'; // 6 AM, October 20 in Manila; UTC is still October 19.
class Clock extends Date { constructor(...args) { super(...(args.length ? args : [instant])); } }
const ctx = vm.createContext({ window: {}, Date: Clock, Intl });
vm.runInContext(fs.readFileSync('js/search-date-policy.js', 'utf8'), ctx);
const start = source.indexOf('    function validDetailsStay(');
const end = source.indexOf('    function fillSearchFromData(', start);
vm.runInContext(source.slice(start, end), ctx);
assert.equal(ctx.window.SearchDatePolicy.minimumDate('hotel'), '2026-10-20');
assert.equal(ctx.validDetailsStay('2026-10-19', '2026-10-21'), false);
assert.equal(ctx.validDetailsStay('2026-10-20', '2026-10-21'), true);
assert.equal(ctx.validDetailsStay('2026-10-20', '2026-10-20'), false);
assert.equal(ctx.validDetailsStay('2026-02-30', '2026-10-21'), false);
assert.equal(ctx.validDetailsStay('', ''), false);
instant = '2026-10-20T16:00:00Z';
assert.equal(ctx.validDetailsStay('2026-10-20', '2026-10-22'), false, 'Open page advances its minimum after Manila midnight');
assert.equal(ctx.validDetailsStay('2026-10-21', '2026-10-22'), true);
const calls=[];
Object.assign(ctx, {
 picker: { clear: value=>calls.push(['clear',value]), setDate: (...args)=>calls.push(['date',...args]) },
 islandInput:{value:''}, checkinInput:{value:''}, checkoutInput:{value:''}, hotelData:{island:'Apuao'},
 normalizeDetailsIsland: value=>value, updateStayDurationBadge: (...args)=>calls.push(['badge',...args]),
 document:{getElementById:()=>({innerText:''})}, renderChildAgeRows(){}, applyGuestSelection(){},updateIslandFieldState(){}
});
vm.runInContext(source.slice(end, source.indexOf('    function showResortsSearchResults(',end)), ctx);
ctx.fillSearchFromData({checkin:'2026-10-19',checkout:'2026-10-22',island:'Apuao'});
assert.equal(ctx.checkinInput.value,''); assert.equal(ctx.checkoutInput.value,'');
assert.equal(calls[0][0],'clear'); assert.equal(calls.some(c=>c[0]==='date'),false);
assert.match(source,/onOpen\(\)\s*\{\s*this\.set\('minDate', window\.SearchDatePolicy\.minimumDate\('hotel'\)\)/);
assert.match(source,/else if \(!validDetailsStay\(data.checkin, data.checkout\)\)/);
assert.match(source,/if \(!validDetailsStay\(searchData.checkin, searchData.checkout\)\)/);
console.log('PASS: hotel details blocks past Manila dates, refreshes after midnight, rejects invalid ranges and clears stale restored dates.');
