'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const read=file=>fs.readFileSync(path.join(__dirname,'..',file),'utf8');
const source=read('public/hotel_resorts.php');
const matchSource=source.slice(source.indexOf('function hotelMatchesSearchIsland('),source.indexOf('function applySearchWithoutValidation('));
const filterSource=source.slice(source.indexOf('  function applyCheckboxFilters()'),source.indexOf('  function sortHotels('));
let shown=[];const checked=[];
const context={document:{querySelectorAll:()=>checked},filtered:[],rangeMin:{value:'0'},rangeMax:{value:'6000'},displayHotels:list=>{shown=list;}};
vm.createContext(context);vm.runInContext(matchSource+filterSource,context);
const hotels=[{id:3204,name:'Apuao Grande Island Resort',island:' Apuao Grande Island ',price:2000,type:'Resort'},
  {id:3659,name:'Apuao Pequeña Island Resort',island:'Apuao Pequeña',price:0,type:'resort'},
  {id:3,name:'Other resort',island:'Caringo',price:1000,type:'resort'}];
context.filtered=hotels.filter(hotel=>context.hotelMatchesSearchIsland(hotel,'Apuao'));
context.applyCheckboxFilters();assert.deepEqual(Array.from(shown,h=>h.id),[3204,3659],'Both Apuao properties appear at the full default price range');
assert.equal(context.hotelMatchesSearchIsland({island:'APUAO'},' apuao island '),true);
assert.equal(context.hotelMatchesSearchIsland({island:'Caringo'},'Apuao'),false);
assert.equal(context.hotelMatchesSearchIsland({island:'Caringo Island'},'caringo'),true);
assert.equal(context.hotelMatchesSearchIsland({island:'Apuao'},''),true);
context.rangeMin.value='200';context.applyCheckboxFilters();assert.deepEqual(Array.from(shown,h=>h.id),[3204],'Explicit price filtering still works');
context.rangeMin.value='0';context.rangeMax.value='1000';context.applyCheckboxFilters();assert.deepEqual(Array.from(shown,h=>h.id),[3659]);
context.rangeMax.value='6000';checked.push({value:'hotel'});context.applyCheckboxFilters();assert.equal(shown.length,0);
checked[0]={value:'resort'};context.applyCheckboxFilters();assert.equal(shown.length,2,'Property types remain filterable');
assert.match(source,/id="rangeMin" min="0"/);
assert.match(source,/id="rangeMax"[^>]*hotelSearchPriceMax/);
assert.ok(source.includes(": 'See rates'"),'Unpublished rates are not displayed as free rooms');

const booking=read('public/hotel_booking.php');
const calendarSource=booking.slice(booking.indexOf('      const rangeHasUnavailableDate ='),booking.indexOf('      const syncHotelCalendars ='));
const calendar={unavailableRoomDates:new Set(['2026-11-12','2026-11-13']),stayDurationPicker:null,
  toPhpDate:date=>`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`};
vm.createContext(calendar);vm.runInContext(calendarSource+'\nthis.blocked=isRoomDateDisabled;this.overlaps=rangeHasUnavailableDate;',calendar);
const day=value=>new Date(value+'T00:00:00');
assert.equal(calendar.blocked(day('2026-11-12')),true,'Cannot start a stay on a reserved night');
calendar.stayDurationPicker={selectedDates:[day('2026-11-10')]};
assert.equal(calendar.blocked(day('2026-11-12')),false,'Can check out on the next reservation arrival');
assert.equal(calendar.blocked(day('2026-11-13')),true,'Cannot cross a reserved night');
assert.equal(calendar.overlaps('2026-11-10','2026-11-12'),false);
assert.equal(calendar.overlaps('2026-11-10','2026-11-13'),true);
calendar.stayDurationPicker.selectedDates=[day('2026-11-10'),day('2026-11-12')];
assert.equal(calendar.blocked(day('2026-11-12')),false,'Selected checkout remains valid when calendar refreshes');
calendar.stayDurationPicker=null;
assert.equal(calendar.blocked(day('2026-11-14')),false,'Previous checkout day is selectable');

for(const file of ['public/hotel_resorts.php','public/hotel_details.php','public/hotel_booking.php']){
  const html=read(file);
  for(const match of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)){
    if(/\bsrc\s*=|application\/(?:ld\+)?json/i.test(match[1]))continue;
    new vm.Script(match[2].replace(/<\?[\s\S]*?\?>/g,'null'),{filename:file});
  }
}
console.log('Passed hotel search and calendar UI checks: Apuao aliases, unpublished rates, price/type filters, adjacent stays, reserved nights and inline JavaScript syntax.');
