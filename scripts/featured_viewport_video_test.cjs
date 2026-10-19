'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync(path.join(__dirname,'../js/featured-viewport-video.js'),'utf8');
function fixture(type){
 const counts={play:0,pause:0,mute:0};let intersection;const events={},windowEvents={};
 const source=type==='drive'?'https://drive.google.com/file/d/Example_Drive_File_12345/preview':type==='youtube'?'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ':'https://player.vimeo.com/video/12345?h=private';
 const frame={dataset:{mediaProvider:type==='drive'?'google_drive':type},src:source,getAttribute(){return this.src;}};
 const video={muted:false,autoplay:true,playsInline:false,removeAttribute(){},play(){counts.play++;return Promise.resolve();},pause(){counts.pause++;},addEventListener(name,callback){events[name]=callback;}};
 let soundButton;const box={appendChild(element){soundButton=element;},dataset:{},querySelector:selector=>selector==='video'?(type==='native'?video:null):type==='native'?null:frame};
 const document={createElement(){return {hidden:false,addEventListener(name,callback){this[name]=callback;}};},hidden:false,querySelector:()=>box,addEventListener(name,callback){events[name]=callback;},head:{appendChild(){throw new Error('Unexpected remote SDK load in fixture');}}};
 const window={IntersectionObserver:true,location:{href:'https://example.com/',origin:'https://example.com'},addEventListener(name,callback){windowEvents[name]=callback;}};
 let youtubeReady;
 if(type==='youtube')window.YT={Player:function(_element,options){youtubeReady=()=>options.events.onReady({target:{unMute(){counts.mute++;},playVideo(){counts.play++;},pauseVideo(){counts.pause++;}}});}};
 if(type==='vimeo')window.Vimeo={Player:function(){this.ready=()=>Promise.resolve();this.setMuted=value=>{assert.equal(value,false);counts.mute++;return Promise.resolve();};this.play=()=>{counts.play++;return Promise.resolve();};this.pause=()=>{counts.pause++;return Promise.resolve();};}};
 const context={window,document,URL,IntersectionObserver:function(callback){intersection=callback;this.observe=()=>{};}};vm.createContext(context);vm.runInContext(code,context);
 return {get soundButton(){return soundButton;},counts,video,frame,source,document,events,windowEvents,ready(){youtubeReady?.();},visible(ratio){intersection([{target:box,isIntersecting:ratio>0,intersectionRatio:ratio}]);}};
}
(async()=>{
 const native=fixture('native');assert.equal(native.counts.play,0);assert.equal(native.video.muted,false);assert.equal(native.video.autoplay,false);
 native.visible(.6);assert.equal(native.counts.play,1);native.visible(.05);assert.ok(native.counts.pause>=2);
 native.visible(.7);const plays=native.counts.play;native.document.hidden=true;native.events.visibilitychange();const pauses=native.counts.pause;assert.ok(pauses>=3);assert.equal(native.counts.play,plays);
 native.document.hidden=false;native.events.visibilitychange();assert.equal(native.counts.play,plays+1);native.windowEvents.pagehide();assert.ok(native.counts.pause>pauses);native.windowEvents.pageshow();assert.equal(native.counts.play,plays+2);
 const blocked=fixture('native');blocked.video.play=()=>Promise.reject(Object.assign(new Error('Gesture required'),{name:'NotAllowedError'}));blocked.visible(.7);await Promise.resolve();assert.equal(blocked.soundButton.hidden,false);blocked.visible(0);assert.equal(blocked.soundButton.hidden,true);blocked.visible(.7);await Promise.resolve();let clicked=false;blocked.video.play=()=>{clicked=true;blocked.events.playing();return Promise.resolve();};blocked.soundButton.click();assert.equal(clicked,true);assert.equal(blocked.video.muted,false);assert.equal(blocked.soundButton.hidden,true);
 const drive=fixture('drive');assert.equal(drive.frame.src,'about:blank');drive.visible(.7);assert.equal(drive.frame.src,drive.source);drive.visible(0);assert.equal(drive.frame.src,'about:blank');drive.visible(.7);assert.equal(drive.frame.src,drive.source);
 const youtube=fixture('youtube');assert.match(youtube.frame.src,/enablejsapi=1/);assert.match(youtube.frame.src,/origin=https%3A%2F%2Fexample.com/);youtube.visible(.8);assert.equal(youtube.counts.play,0);youtube.ready();assert.equal(youtube.counts.mute,1);assert.equal(youtube.counts.play,1);youtube.visible(0);assert.equal(youtube.counts.pause,1);
 const vimeo=fixture('vimeo');vimeo.visible(.8);for(let i=0;i<8;i++)await Promise.resolve();assert.equal(vimeo.counts.mute,1);assert.equal(vimeo.counts.play,1);assert.match(vimeo.frame.src,/h=private/);vimeo.visible(0);assert.equal(vimeo.counts.pause,1);
 const mutedFallback=fixture('native');let attempts=0;
 mutedFallback.video.play=()=>{
   attempts++;
   if(!mutedFallback.video.muted)return Promise.reject(Object.assign(new Error('Sound blocked'),{name:'NotAllowedError'}));
   mutedFallback.events.playing();return Promise.resolve();
 };
 mutedFallback.visible(.7);for(let i=0;i<4;i++)await Promise.resolve();
 assert.equal(attempts,2);assert.equal(mutedFallback.video.muted,true);
 assert.equal(mutedFallback.soundButton.hidden,false);assert.equal(mutedFallback.soundButton.textContent,'Turn sound on');
 mutedFallback.visible(0);assert.equal(mutedFallback.soundButton.hidden,true);
 mutedFallback.visible(.7);assert.equal(mutedFallback.soundButton.hidden,false);
 mutedFallback.video.play=()=>{mutedFallback.events.playing();return Promise.resolve();};
 mutedFallback.soundButton.click();assert.equal(mutedFallback.video.muted,false);assert.equal(mutedFallback.soundButton.hidden,true);
 console.log('Passed featured viewport checks: sound playback, muted autoplay fallback and click-to-unmute, scroll/background pause, return resume, YouTube/Vimeo controls, and Drive offscreen unload.');
})().catch(error=>{console.error(error);process.exitCode=1;});
