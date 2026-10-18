(() => {
  'use strict';
  const imageBase = new URL('../icons/', document.currentScript?.src || new URL('js/activity-icons.js', document.baseURI).href);
  const images = {
    'Scuba Diving':'scuba_diving.png', Photography:'photography.png', Surfing:'surfing.png', Camping:'camping.png',
    Swimming:'swimming.png', Kayaking:'kayaking.png', Fishing:'fishing.png', Hiking:'hiking.png', Dining:'dining.png',
    'Boat Tour':'boat_tour.png', 'Sunset Watching':'sunset_watching.png', Sightseeing:'sight_seeing.png',
    'Cultural Tour':'cultural_tour.png', 'Beach Volleyball':'beach_volleyball.png', Yoga:'yoga.png',
    Meditation:'meditation.png', 'Beach Walks':'beach_walks.png', 'Beach Party':'beach_party.png', Bonfire:'bonfire.png'
  };
  // Local vector icons need no remote service or additional uploaded image files.
  const vectors = {
    Drinking:'<path d="M5 7h14l-1.6 13H6.6L5 7Zm7 5 3-9h5M6 12h12"/>',
    Walking:'<circle cx="14" cy="4" r="2"/><path d="m12 8-3 5 4 3 1 5m-3-6-4 6m5-13 3 4 4 1M9 13l-4 1"/>',
    Running:'<circle cx="16" cy="4" r="2"/><path d="m13 8-4 4 5 3 3 5m-5-6-4 5H3m9-10 4 3 4-2M9 12l-3-2-3 2"/>',
    Hunting:'<path d="m8 10 1 9 3 3 3-3 1-9-4-2-4 2Zm1 0L5 7 4 3m1 4H2m13 3 4-3 1-4m-1 4h3M10 14h.01M14 14h.01m-3 4h2"/>',
    Archery:'<circle cx="11" cy="13" r="8"/><circle cx="11" cy="13" r="4"/><path d="m11 13 9-9m-1-2v4h4"/>',
    Cycling:'<circle cx="5" cy="17" r="4"/><circle cx="19" cy="17" r="4"/><path d="m5 17 5-9 5 9H5m10 0 3-12h3M8 8h5"/>',
    Climbing:'<path d="m2 21 6-11 4 6 4-9 6 14H2"/><circle cx="12" cy="4" r="1.5"/><path d="m11 8-3 2m3-2 3 2 3-4m-6 3 1 5"/>',
    Caving:'<path d="M2 21 7 5l5-3 5 3 5 16H2Zm6 0v-8a4 4 0 0 1 8 0v8M7 5l2 4m8-4-2 4"/>',
    'Bird Watching':'<path d="m3 9 2-5h3l2 5m4 0 2-5h3l2 5M10 12h4"/><circle cx="6" cy="15" r="5"/><circle cx="18" cy="15" r="5"/>',
    Wildlife:'<path d="M5 14c-3-2-4-6-2-7l5 3m11 4c3-2 4-6 2-7l-5 3M7 11l-1 7 6 4 6-4-1-7-5-3-5 3Zm3 4h.01M14 15h.01m-3 3h2"/>',
    Stargazing:'<path d="M15 3a8 8 0 1 0 6 12A8 8 0 0 1 15 3ZM5 2v4M3 4h4m13 0v4m-2-2h4M10 10v3m-1.5-1.5h3"/>',
    Shopping:'<path d="M4 7h16l-1 14H5L4 7Zm4 0V5a4 4 0 0 1 8 0v2"/>',
    Cooking:'<path d="M5 11h14v7a3 3 0 0 1-3 3H8a3 3 0 0 1-3-3v-7Zm-3 3h3m14 0h3M4 8h16M8 2c-3 2 3 3 0 5m5-5c-3 2 3 3 0 5"/>',
    Picnic:'<path d="M3 10h18l-2 11H5L3 10Zm4 0 4-7m6 7-4-7M8 14v4m4-4v4m4-4v4"/>',
    Music:'<path d="M9 18V5l11-3v13M9 9l11-3"/><ellipse cx="5.5" cy="18" rx="3.5" ry="3"/><ellipse cx="16.5" cy="15" rx="3.5" ry="3"/>',
    Dancing:'<circle cx="12" cy="4" r="2"/><path d="m5 4 3 6h8l3-6M12 10v6m0-1-6 6m6-6 6 6"/>',
    Reading:'<path d="M12 5C9 2 5 2 2 3v16c3-1 7-1 10 2m0-16c3-3 7-3 10-2v16c-3-1-7-1-10 2V5ZM5 7h4M5 11h4m6-4h4m-4 4h4"/>',
    Resting:'<path d="M3 7v14m18-8v8M3 17h18M3 13h18v4M7 13V9h4a4 4 0 0 1 4 4"/><circle cx="6" cy="10" r="2"/>',
    Games:'<path d="M7 7h10c4 0 6 12 3 13-2 1-4-4-5-4H9c-1 0-3 5-5 4C1 19 3 7 7 7Zm0 4v4m-2-2h4m7-2h.01M18 14h.01"/>',
    Basketball:'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3v18M6 5c6 6 6 8 0 14M18 5c-6 6-6 8 0 14"/>',
    Football:'<circle cx="12" cy="12" r="9"/><path d="m12 8 4 3-2 5h-4l-2-5 4-3Zm0 0V3m4 8 5-2m-7 7 3 4m-7-4-3 4m1-9L3 9"/>',
    Racket:'<ellipse cx="14" cy="8" rx="6" ry="7" transform="rotate(35 14 8)"/><path d="m10 14-7 8m7-17 7 7m-9-3 7 7m-3-14 7 7"/>',
    Ziplining:'<path d="M2 3h20M12 3v7m0 3-5 3m5-3 5 3m-5-1-4 6m4-6 5 6"/><circle cx="12" cy="10" r="2"/>',
    'Jet Ski':'<path d="M2 17h19l-5 4H7l-5-4Zm8 0 3-6h4m-5 0-2-4H6M2 23l3-1 4 1 4-1 4 1 4-1"/><circle cx="9" cy="4" r="2"/>',
    Parasailing:'<path d="M2 10a10 8 0 0 1 20 0H2Zm0 0 10 8 10-8M12 2c-4 2-4 6-4 8m4-8c4 2 4 6 4 8m-4 8v2m-3 3 3-3 3 3"/>',
    'Shell Collecting':'<path d="M12 3C3 3 0 14 4 17l5 2 1 3h4l1-3 5-2c4-3 1-14-8-14Zm0 1v14M6 7l4 11m8-11-4 11M3 12l6 6m12-6-6 6"/>',
    'Beach Cleanup':'<path d="M5 7h14l-1 14H6L5 7Zm-2 0h18M9 3h6v4m-5 4v6m4-6v6"/>',
  };
  const groups = [
    ['Hunting','hunt hunting hunter pangangaso'], ['Archery','archery bow arrow target shooting'],
    ['Walking','walk walking stroll strolling lakad paglalakad'], ['Running','run running jog jogging marathon takbo'],
    ['Cycling','cycle cycling bicycle bicycling bike biking siklista'],
    ['Climbing','climb climbing rock climbing bouldering mountaineering'], ['Caving','cave caving caves spelunking'],
    ['Hiking','hike hiking trek trekking trails trail nature trail forest trekking'],
    ['Drinking','drink drinking drinks beverages beverage refreshments cocktail cocktails mocktail mocktails beer wine coffee tea juice inuman'],
    ['Scuba Diving','scuba diving dive dives snorkeling snorkelling snorkel freediving free diving'],
    ['Photography','photography photos photo camera selfie selfies picture pictures taking photos'],
    ['Beach Volleyball','volleyball beach volleyball'], ['Beach Walks','beach walk beach walks beach walking beach strolling'],
    ['Kayaking','kayak kayaking canoe canoeing paddle paddling paddleboarding stand up paddleboarding'],
    ['Surfing','surf surfing windsurfing kitesurfing'], ['Swimming','swim swimming bathing langoy paglangoy'],
    ['Bonfire','bonfire bonfires campfire campfires'], ['Camping','camp camping campsite campsites tent tents glamping'],
    ['Fishing','fish fishing angling spearfishing pangingisda'],
    ['Boat Tour','boat boats boating sailing cruise cruising island hopping boat riding banana boat'],
    ['Sunset Watching','sunset sunrise sunset watching sunrise watching'], ['Bird Watching','bird birding birdwatching bird watching binoculars'],
    ['Wildlife','wildlife safari animals animal watching animal spotting'], ['Stargazing','stars stargazing astronomy night sky'],
    ['Cooking','cook cooking grilling barbecue barbeque bbq'], ['Picnic','picnic picnics picnicking'],
    ['Dining','dining food eating eat restaurant restaurants seafood food tasting food trip'],
    ['Yoga','yoga stretch stretching'], ['Meditation','meditation meditating mindfulness'], ['Resting','rest resting relaxing relaxation sleeping sleep sunbathing'],
    ['Dancing','dance dances dancing sayaw'], ['Music','music singing karaoke concert concerts videoke live music'],
    ['Beach Party','party parties partying beach party nightlife'], ['Shopping','shop shopping souvenirs souvenir market markets'],
    ['Reading','read reading books book'], ['Games','games game gaming board games card games'],
    ['Basketball','basketball'], ['Football','football soccer'], ['Racket','badminton tennis pickleball table tennis'],
    ['Ziplining','zipline ziplining zip lining zip line'], ['Jet Ski','jet ski jet skiing jetski jetskiing'],
    ['Parasailing','parasail parasailing paragliding'], ['Shell Collecting','shell shells seashell seashells shell collecting'],
    ['Beach Cleanup','cleanup cleaning beach cleanup beach cleaning litter collecting trash collection'],
    ['Cultural Tour','culture cultural heritage history historical church museum museums cultural tour'],
    ['Sightseeing','sightseeing sights exploring explore nature viewing landscape landscapes sightseeing tour'],
  ];
  const normalize = value => String(value || '').normalize('NFKD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,' ').trim().replace(/\s+/g,' ');
  // Each word is a synonym; longer phrases get priority over a generic word.
  const terms = groups.flatMap(([category, words]) => words.split(' ').map(term => ({category,term})))
    .filter(({term}) => !['beach','rock','tour','watching','taking','up','stand','free','riding','collecting','trip','line','lining','tasting','live','table','board','card','target','shooting','night','sky','hopping'].includes(term));
  const phrases = [
    ['Beach Walks','beach walking'],['Beach Walks','beach walk'],['Beach Walks','beach strolling'],
    ['Climbing','rock climbing'],['Bird Watching','bird watching'],['Wildlife','animal watching'],
    ['Boat Tour','island hopping'],['Boat Tour','banana boat'],['Jet Ski','jet skiing'],['Jet Ski','jet ski'],
    ['Beach Cleanup','beach cleanup'],['Beach Cleanup','beach cleaning'],['Shell Collecting','shell collecting'],
    ['Parasailing','parasailing'],['Scuba Diving','free diving'],['Archery','target shooting'],
    ['Games','board games'],['Games','card games'],['Stargazing','night sky'],['Racket','table tennis'],
  ].map(([category,term])=>({category,term}));
  const candidates = [...phrases,...terms].sort((a,b)=>b.term.length-a.term.length);
  const exact = new Map([...Object.keys(images),...Object.keys(vectors)].map(category=>[normalize(category),category]));
  const vectorCache = new Map();
  function iconSource(category) {
    if (images[category]) return new URL(images[category],imageBase).href;
    if (!vectorCache.has(category)) {
      const drawing=vectors[category] || '<path d="m12 3 2.5 5.5L21 9l-5 4.5 1.5 6.5-5.5-3-5.5 3L8 13.5 3 9l6.5-.5L12 3Z"/>';
      const svg=`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#17332f" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${drawing}</svg>`;
      vectorCache.set(category,'data:image/svg+xml;charset=utf-8,'+encodeURIComponent(svg));
    }
    return vectorCache.get(category);
  }
  function oneEditApart(a,b) {
    if (Math.abs(a.length-b.length)>1) return false;
    let i=0,j=0,edits=0;
    while(i<a.length && j<b.length) {
      if(a[i]===b[j]){i++;j++;continue;}
      if(++edits>1)return false;
      if(a.length>=b.length)i++;
      if(b.length>=a.length)j++;
    }
    return edits+(i<a.length || j<b.length?1:0)<=1;
  }
  function resolve(value) {
    const label=normalize(value);
    let category=exact.get(label);
    if(!category)category=candidates.find(({term})=>(' '+label+' ').includes(' '+term+' '))?.category;
    if(!category) {
      for(const token of label.split(' ').filter(word=>word.length>=5)) {
        const matches=terms.filter(({term})=>term.length>=5 && oneEditApart(token,term));
        const categories=new Set(matches.map(item=>item.category));
        if(categories.size===1){category=matches[0].category;break;}
      }
    }
    category=category || 'Activity';
    return {category,source:iconSource(category),matched:category!=='Activity'};
  }
  window.ItourActivityIcons=Object.freeze({resolve,source:value=>resolve(value).source});
})();
