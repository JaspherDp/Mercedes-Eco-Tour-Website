'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const root=path.join(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const context={window:{},URL,document:{currentScript:{src:'https://fixture.test/itour/js/activity-icons.js'},baseURI:'https://fixture.test/itour/destination.php'}};
vm.createContext(context);vm.runInContext(read('js/activity-icons.js'),context);
const icons=context.window.ItourActivityIcons;
const examples={
  'Hunting':'Hunting','Bird hunting':'Hunting','huting':'Hunting','Walking':'Walking','Walking around the island':'Walking',
  'Night walking':'Walking','Beach walking':'Beach Walks','Trekking':'Hiking','Forest trekking':'Hiking','hicking':'Hiking',
  'Rock climbing':'Climbing','Caving':'Caving','Spelunking':'Caving','Drinking':'Drinking','Beach drinking':'Drinking','driking':'Drinking',
  'Inuman':'Drinking','Cocktails':'Drinking','Coffee':'Drinking','Jogging':'Running','Mountain biking':'Cycling','Cycling':'Cycling',
  'Archery':'Archery','Target shooting':'Archery','Bird watching':'Bird Watching','Wildlife viewing':'Wildlife','Stargazing':'Stargazing',
  'Night sky':'Stargazing','Photography':'Photography','Taking photos':'Photography','Swimming':'Swimming','Snorkeling':'Scuba Diving',
  'Free diving':'Scuba Diving','Kayaking':'Kayaking','Stand up paddleboarding':'Kayaking','Surfing':'Surfing',
  'Camping':'Camping','Campfire':'Bonfire','Fishing':'Fishing','Spearfishing':'Fishing','Island hopping':'Boat Tour','Sunset watching':'Sunset Watching',
  'Cooking':'Cooking','Picnic':'Picnic','Dining':'Dining','Yoga':'Yoga','Meditation':'Meditation','Relaxing':'Resting','Sunbathing':'Resting',
  'Shopping':'Shopping','Karaoke':'Music','Dancing':'Dancing','Reading':'Reading','Board games':'Games',
  'Basketball':'Basketball','Soccer':'Football','Badminton':'Racket','Zip lining':'Ziplining','Jet skiing':'Jet Ski',
  'Parasailing':'Parasailing','Shell collecting':'Shell Collecting','Beach cleanup':'Beach Cleanup','Museum visit':'Cultural Tour',
};
let checks=0;
for(const [label,expected] of Object.entries(examples)){
  const result=icons.resolve(label);
  assert.equal(result.category,expected,label);assert.equal(result.matched,true,label);
  assert.ok(!result.source.includes('activity.svg'),label+' must not use compass');
  if(result.source.startsWith('data:')){
    const svg=decodeURIComponent(result.source.split(',')[1]);assert.ok(svg.includes('<svg ')&&svg.endsWith('</svg>'));
  }else{
    const local=new URL(result.source).pathname.replace('/itour/','');assert.ok(fs.existsSync(path.join(root,local)),local);
  }
  checks++;
}
assert.notEqual(icons.source('Hunting'),icons.source('Walking'));
assert.notEqual(icons.source('Drinking'),icons.source('Walking'));
assert.equal(icons.resolve('  WALKING!!!  ').category,'Walking');
assert.equal(icons.resolve('Completely unfamiliar activity').matched,false);

// Admin preview and public activity items use the same source, preserving label text safely.
const createElement=tag=>({tag,children:[],value:'',append(...nodes){this.children.push(...nodes);},replaceChildren(){this.children=[];}});
const nodes={activityIconPreview:createElement('div'),fieldActivities:{value:'Hunting, Walking\nDrinking'}};
context.byId=id=>nodes[id];context.document.createElement=createElement;
const admin=read('js/admin_destinations.js');
vm.runInContext(admin.slice(admin.indexOf('  function renderActivityIconPreview()'),admin.indexOf('  function updateEditorNavigation(')),context);
context.renderActivityIconPreview();
assert.equal(nodes.activityIconPreview.children.length,3);
for(const [i,label] of ['Hunting','Walking','Drinking'].entries()){
  const preview=nodes.activityIconPreview.children[i];assert.equal(preview.children[0].src,icons.source(label));assert.equal(preview.children[1].textContent,label);
}
const publicSource=read('destination.js');
vm.runInContext('function destinationActivityIconSource(value){return window.ItourActivityIcons.source(value);}',context);
vm.runInContext(publicSource.slice(publicSource.indexOf('  function createActivityItemV2('),publicSource.indexOf('  function buildMapSrcV2(')),context);
const item=context.createActivityItemV2('Hunting');assert.equal(item.children[0].src,icons.source('Hunting'));
assert.equal(item.children[1].textContent,'Hunting');
const unsafe=context.createActivityItemV2('<img src=x onerror=alert(1)>');assert.equal(unsafe.innerHTML,undefined);
const page=read('public/destination.php'),adminPage=read('admin/addestination.php');
assert.ok(page.indexOf('src="js/activity-icons.js')<page.indexOf('src="destination.js'));
assert.ok(adminPage.indexOf('src="js/activity-icons.js')<adminPage.indexOf('src="js/admin_destinations.js'));
console.log(`Passed ${checks} activity matching cases plus icon assets, typos, admin/public consistency, safe labels and script loading checks.`);
