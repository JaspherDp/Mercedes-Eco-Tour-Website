// ======== DATA ========
const placesData = {
  'apuao-pequena island': {
    name:'Sun, Sand, Serenity',
    image:'imagess/Apuao Pequena_header-img.png',
    description:'Apuao Pequeña offers the best place for family bonding and camping. Families and tourists alike enjoy the shade under the cool pine trees along the white powdery sand and crystal clear blue waters. Mediation practitioners as well as those seeking relaxation may unwind from their toxic urban life along the sand bar that offers the perfect view of the pacific ocean. Adventurers may enjoy trekking and bat watching before the high waves on the other side of the island which is ideal for surfing and other water sports.',
    coords:{ lat:14.083545760332994, lng:123.10354227771039 },
    activities:['Scuba Diving','Photography','Surfing', 'Camping', 'Swimming', 'Kayaking', 'Fishing'],
    gallery:[
      'imagess/Apuao Pequena_1.jpg',
      'imagess/Apuao Pequena_2.jpg',
      'imagess/Apuao Pequena_3.jpg',
      'imagess/Apuao Pequena_2.jpg',
      'imagess/Apuao Pequena_1.jpg',
      'imagess/Apuao Pequena_3.jpg',
      'imagess/Apuao Pequena_3.jpg',
      'imagess/Apuao Pequena_2.jpg',
      'imagess/Apuao Pequena_1.jpg',
      'imagess/Apuao Pequena_2.jpg'
    ],
    resorts:[{
      name:'Island View Resort',
      description:'Apuao Pequeña Island, part of the Mercedes Islands in Camarines Norte, Philippines, is a tranquil paradise known for its unique landscape of white sand beaches fringed with tall Agoho trees (pine-like), offering a tropical-meets-forest vibe, calm waters, and abundant fruit bats, all connected to its larger neighbor, Apuao Grande, by a stunning low-tide sandbar perfect for peaceful relaxation, camping, and island hopping. Apuao Pequeña Island, part of the Mercedes Islands in Camarines Norte, Philippines, is a tranquil paradise known for its unique landscape of white sand beaches fringed with tall Agoho trees (pine-like), offering a tropical-meets-forest vibe, calm waters, and abundant fruit bats, all connected to its larger neighbor, Apuao Grande, by a stunning low-tide sandbar perfect for peaceful relaxation, camping, and island hopping. Apuao Pequeña Island, part of the Mercedes Islands in Camarines Norte, Philippines, is a tranquil paradise known for its unique landscape of white sand beaches fringed with tall Agoho trees (pine-like), offering a tropical-meets-forest vibe, calm waters, and abundant fruit bats, all connected to its larger neighbor, Apuao Grande, by a stunning low-tide sandbar perfect for peaceful relaxation, camping, and island hopping. Apuao Pequeña Island, part of the Mercedes Islands in Camarines Norte, Philippines, is a tranquil paradise known for its unique landscape of white sand beaches fringed with tall Agoho trees (pine-like), offering a tropical-meets-forest vibe, calm waters, and abundant fruit bats, all connected to its larger neighbor, Apuao Grande, by a stunning low-tide sandbar perfect for peaceful relaxation, camping, and island hopping. Apuao Pequeña Island, part of the Mercedes Islands in Camarines Norte, Philippines, is a tranquil paradise known for its unique landscape of white sand beaches fringed with tall Agoho trees (pine-like), offering a tropical-meets-forest vibe, calm waters, and abundant fruit bats, all connected to its larger neighbor, Apuao Grande, by a stunning low-tide sandbar perfect for peaceful relaxation, camping, and island hopping. ',
      activities:['Dining','Boat Tour','Sunset Watching', 'Kayaking', 'Swimming'],
      gallery:[
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945'
      ]
    }]
  },
  'apuao-grande island': {
    name:'Feel the Breeze, Embrace the Sea',
    image:'imagess/Apuao Grande_header-img.png',
    description:'Apuao Grande Island is part of the island hopping activity around Mercedes island. It has whitish shorelines and turquoise waters that will entice you to the plunge. The beach is idyllic, making it the perfect place to forget your worries and the stress of city living. The island is also accessible from Manila, which means you can do it as a weekend or long holiday trip.',
    coords:{ lat:14.085190731378665, lng:123.09085486814827 },
    activities:['Scuba Diving','Photography','Surfing', 'Camping', 'Swimming', 'Kayaking', 'Hiking', 'Fishing'],
    gallery:[
      'imagess/Apuao Grande_1.jpg',
      'imagess/Apuao Grande_1.jpg',
      'imagess/Apuao Grande_2.jpg',
      'imagess/Apuao Grande_1.jpg',
      'imagess/Apuao Grande_2.jpg',
      'imagess/Apuao Grande_1.jpg',
      'imagess/Apuao Grande_2.jpg',
      'imagess/Apuao Grande_2.jpg',
      'imagess/Apuao Grande_1.jpg',
      'imagess/Apuao Grande_3.jpg'
    ],
    resorts:[{
      name:'Sunset Cove Resort',
      description:'Famous for sunsets.',
      activities:['Swimming','Beach Party','Bonfire'],
      gallery:[
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945'
      ]
    }]
  },
  'quinapaguian island': {
    name:'Unwind, Dive, Explore',
    image:'imagess/Quinapaguian_header-img.png',
    description:'Quinapaguian Island is part of the island hopping activity around Mercedes island. It has whitish shorelines and turquoise waters that will entice you to the plunge. The beach is idyllic, making it the perfect place to forget your worries and the stress of city living. The island is also accessible from Manila, which means you can do it as a weekend or long holiday trip.',
    coords:{ lat:14.07057854011248, lng:123.07456397609789 },
    activities:['Scuba Diving','Photography','Surfing', 'Camping', 'Swimming', 'Kayaking', 'Fishing'],
    gallery:[
      'imagess/Quinapaguian.jpg',
      'imagess/Quinapaguian_2.jpg',
      'imagess/Quinapaguian.jpg',
      'imagess/Quinapaguian.jpg',
      'imagess/Quinapaguian_2.jpg',
      'imagess/Quinapaguian.jpg',
      'imagess/Quinapaguian_2.jpg',
      'imagess/Quinapaguian_2.jpg',
      'imagess/Quinapaguian.jpg',
      'imagess/Quinapaguian_3.jpg'
    ],
    resorts:[{
      name:'Ocean Breeze Resort',
      description:'Relaxing resort near the beach.',
      activities:['Fishing','Yoga','Kayaking'],
      gallery:[
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945'
      ]
    }]
  },
  'canimog island': {
    name:'Let the Tides Take You Away',
    image:'imagess/Canimog_header-img.png',
    description:'Canimog Island is the biggest among the seven islands of Mercedes, Camarines Norte. Dubbed as the "Crocodile Island"  (names after the crocodile-like shaped physical structure). It is famous for its wildlife and bat sanctuary. It also has an almost century-old lighthouse established on June 26, 1927.',
    coords:{ lat:14.122486901057396, lng:123.06486698533702 },
    activities:['Scuba Diving','Photography','Surfing', 'Hiking', 'Camping', 'Swimming', 'Kayaking', 'Fishing'],
    gallery:[
      'imagess/Canimog_1.jpg',
      'imagess/Canimog_2.jpg',
      'imagess/Canimog_1.jpg',
      'imagess/Canimog_2.jpg',
      'imagess/Canimog_1.jpg',
      'imagess/Canimog_2.jpg',
      'imagess/Canimog_1.jpg',
      'imagess/Canimog_2.jpg',
      'imagess/Canimog_1.jpg',
      'imagess/Canimog_3.jpg'
    ],
    resorts:[{
      name:'Palm Beach Resort',
      description:'Tropical resort with palm trees.',
      activities:['Swimming','Photography','Dining'],
      gallery:[
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945'
      ]
    }]
  },
  'caringo island': {
    name:'Your Ultimate Seaside Escape',
    image:'imagess/Caringo_header-img.png',
    description:'Caringo Island is imbued with white sandy beach where ornamental shells abound the shores. At the tip of the island lies the Falaconete Point or the "Falcons Nest" where one can see the face of the sleeping giant-like mountain range formation. It also offers a breath-taking view of the nearby San Miguel bay.',
    coords:{ lat:14.039512003220867, lng:123.10344802458009 },
    activities:['Scuba Diving','Photography','Surfing', 'Camping', 'Swimming', 'Kayaking', 'Fishing'],
    gallery:[
      'imagess/Caringo_1.jpg',
      'imagess/Caringo_1.jpg',
      'imagess/Caringo_2.jpg',
      'imagess/Caringo_1.jpg',
      'imagess/Caringo_2.jpg',
      'imagess/Caringo_1.jpg',
      'imagess/Caringo_2.jpg',
      'imagess/Caringo_2.jpg',
      'imagess/Caringo_1.jpg',
      'imagess/Caringo_3.jpg'
    ],
    resorts:[{
      name:'Sunrise Retreat',
      description:'Peaceful resort near the shore.',
      activities:['Yoga','Meditation','Beach Walks'],
      gallery:[
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945'
      ]
    }]
  },
  'malasugui island': {
    name:'Experience the Magic of Ocean',
    image:'imagess/Malasugui_header-img.png',
    description:'Malasugui Island is the smallest of all Mercedes seven islands, but despite its size, it makes up for it with beauty. The shore is sandy white, with rocks dotting it; its waters a glistering turquoise. Its idyllic setting makes it the perfect place to go camping, spend an hour or so doing nothing and just enjoying nature, and forgetting about the work you need to return to.',
    coords:{ lat:14.055381299959112, lng:123.0883865917819 },
    activities:['Scuba Diving','Photography','Surfing', 'Camping', 'Swimming', 'Kayaking', 'Fishing'],
    gallery:[
      'imagess/Malasugui.jpg',
      'imagess/Malasugui.jpg',
      'imagess/Malasugui_2.jpg',
      'imagess/Malasugui.jpg',
      'imagess/Malasugui_2.jpg',
      'imagess/Malasugui.jpg',
      'imagess/Malasugui_2.jpg',
      'imagess/Malasugui_2.jpg',
      'imagess/Malasugui.jpg',
      'imagess/Malasugui_3.jpg'
    ],
    resorts:[{
      name:'Coral Bay Resort',
      description:'Resort with coral reef nearby.',
      activities:['Scuba Diving','Swimming','Boat Tour'],
      gallery:[
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945'
      ]
    }]
  },
  'canton island': {
    name:'Beautiful Rock Formation in Canron',
    image:'imagess/Canton_header-img.png',
    description:'Canton Island, also Canron Island, is part of the island hopping escapade in Mercedes, Camarines Norte. It may not have the usual fine white sand shore of more popular beaches, but its rawness and ruggedness give it a distinct type of beauty. Other than swimming and beach bumming, there are a variety of activities you can do here such as spelunking, rock climbing, and visiting mangrove forests.',
    coords:{ lat:14.082387398172218, lng:123.10722212802747 },
    activities:['Scuba Diving','Photography','Surfing', 'Camping', 'Swimming', 'Kayaking', 'Fishing'],
    gallery:[
      'imagess/Canton_1.jpg',
      'imagess/Canton_1.jpg',
      'imagess/Canton.jpg',
      'imagess/Canton_1.jpg',
      'imagess/Canton.jpg',
      'imagess/Canton_1.jpg',
      'imagess/Canton.jpg',
      'imagess/Canton.jpg',
      'imagess/Canton_1.jpg',
      'imagess/Canton_3.jpg'
    ],
    resorts:[{
      name:'Seaside Haven',
      description:'Comfortable resort by the sea.',
      activities:['Dining','Swimming','Beach Volleyball'],
      gallery:[
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945'
      ]
    }]
  },
  'st. anthony of padua church': {
    name:'A home of faith by the sea',
    image:'imagess/Church_header-img.png',
    description:'The St. Anthony of Padua Parish in Mercedes, Camarines Norte has long been a quiet anchor in this small fishing town. Its story began not long after Mercedes became its own municipality, when the community—still finding its identity—came together to build a place where people could pray, gather, and feel at home. Under the guidance of Rev. Fr. Roman G. Rayos, the church and convent slowly rose in 1949, built not just with cement and wood but with the shared effort of families who offered whatever they could. When the parish was officially established in 1954, it became more than a building; it became the heart of Mercedes. Dedicated to St. Anthony of Padua, the church has watched generations grow, celebrate, grieve, and return. Even today, it carries the warmth of a community that has always leaned on faith and on one another.',
    coords:{ lat:14.109079454664029, lng:123.0111236644175 },
    activities:['Sightseeing','Photography','Cultural Tour'],
    gallery:[
      'imagess/Church_1.jpg',
      'imagess/Church.jpg',
      'imagess/Church_1.jpg',
      'imagess/Church.jpg',
      'imagess/Church_1.jpg',
      'imagess/Church.jpg',
      'imagess/Church_1.jpg',
      'imagess/Church.jpg',
      'imagess/Church_1.jpg',
      'imagess/Church_3.jpg'
    ],
    resorts:[{
      name:'Heritage Resort',
      description:'Resort near the historical site.',
      activities:['Sightseeing','Photography','Cultural Tour'],
      gallery:[
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=943',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=944',
        'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=945'
      ]
    }]
  }
};

// ======== GLOBALS ========
let currentGalleryImages = [], currentImageIndex = 0, currentPlaceId = null;
let currentResortImages = [], currentResortIndex = 0;
let currentMapZoom = 14; // default


const activitiesGrid = document.getElementById('des_activitiesGrid');
activitiesGrid.innerHTML = '';
// ======== ACTIVITY ICONS ========
const activityIcons = {
  'Scuba Diving': 'icons/scuba_diving.png',
  'Photography': 'icons/photography.png',
  'Surfing': 'icons/surfing.png',
  'Camping': 'icons/camping.png',
  'Swimming': 'icons/swimming.png',
  'Kayaking': 'icons/kayaking.png',
  'Fishing': 'icons/fishing.png',
  'Hiking': 'icons/hiking.png',
  'Dining': 'icons/dining.png',
  'Boat Tour': 'icons/boat_tour.png',
  'Sunset Watching': 'icons/sunset_watching.png',
  'Sightseeing': 'icons/sight_seeing.png',
  'Cultural Tour': 'icons/cultural_tour.png',
  'Beach Volleyball': 'icons/beach_volleyball.png',
  'Yoga': 'icons/yoga.png',
  'Meditation': 'icons/meditation.png',
  'Beach Walks': 'icons/beach_walks.png',
  'Beach Party': 'icons/beach_party.png',
  'Bonfire': 'icons/bonfire.png',
};


// small helper to create gallery image that fades in when loaded
function createGalleryImg(src, alt, clickHandler){
  const img=document.createElement('img');
  img.className='des_gallery-img';
  img.src=src;
  img.alt=alt||'';
  img.loading='lazy';
  img.addEventListener('load',()=>img.classList.add('loaded'));
  if(clickHandler) img.addEventListener('click',clickHandler);
  return img;
}

// Helper to auto split text into paragraphs
function formatToParagraphs(text, maxSentences = 4) {
  if (!text) return '';

  const sentences = text
    .replace(/\s+/g, ' ')
    .replace(/([a-z])([A-Z])/g, '$1. $2') // ✅ fixes "connected toApuao"
    .split(/(?<=[.!?])\s+/)
    .map(s => s.trim())
    .filter(Boolean);

  let output = '';
  for (let i = 0; i < sentences.length; i += maxSentences) {
    output += `<p>${sentences.slice(i, i + maxSentences).join(' ')}</p>`;
  }

  return output;
}


// ======== MAP HELPERS ========
function buildMapSrc(lat, lng, zoom){
  // Google Maps embed with query coordinates
  return `https://www.google.com/maps?q=${lat},${lng}&z=${zoom}&output=embed`;
}

function setPlaceMap(coords, initialZoom = 14){
  const mapSection = document.getElementById('placeMapSection');
  const iframe = document.getElementById('des_placeMap');

  if(!coords || !coords.lat || !coords.lng){
    hidePlaceMap();
    return;
  }

  iframe.src = buildMapSrc(coords.lat, coords.lng, initialZoom);

  mapSection.setAttribute('aria-hidden','false');
  mapSection.inert = false;
  setTimeout(()=> mapSection.classList.add('show'), 20);

  // Optional: focus first interactive element for accessibility
  mapSection.querySelector('button, a, input')?.focus();
}


function addMapMeta(coords){
  // Insert or update a small metadata line below iframe (lat/lng)
  let meta = document.querySelector('.map-meta');
  if(!meta){
    meta = document.createElement('div');
    meta.className = 'map-meta';
    const mapSection = document.getElementById('placeMapSection');
    mapSection.appendChild(meta);
  }
  meta.textContent = `Coordinates: ${coords.lat.toFixed(5)}, ${coords.lng.toFixed(5)} • Interactive map (drag/zoom) — opens in Google Maps.`;
}

function hidePlaceMap(){
  const mapSection = document.getElementById('placeMapSection');
  const iframe = document.getElementById('des_placeMap');
  iframe.src = '';
  mapSection.classList.remove('show');
  mapSection.setAttribute('aria-hidden','true');
}

function mapZoomChange(delta){
  currentMapZoom = Math.max(3, Math.min(20, currentMapZoom + delta));
  if(!currentPlaceId) return;
  const coords = placesData[currentPlaceId].coords;
  if(coords) document.getElementById('des_placeMap').src = buildMapSrc(coords.lat, coords.lng, currentMapZoom);
}

function openInGoogleMaps(){
  if(!currentPlaceId) return;
  const coords = placesData[currentPlaceId].coords;
  if(!coords) return;
  const url = `https://www.google.com/maps/search/?api=1&query=${coords.lat},${coords.lng}`;
  window.open(url, '_blank');
}

// ======== FUNCTIONS ========
function loadPlaces(){
  const container=document.getElementById('placeGridContainer'); container.innerHTML='';
  Object.keys(placesData).forEach(placeId=>{
    const place=placesData[placeId];
    const div=document.createElement('div');
    div.className='des_place-card';
    div.innerHTML=`<img src="${place.image}" alt="${place.name}"${place.name}</div>`;
    div.onclick=()=>openPlacePage(placeId);
    container.appendChild(div);
  });
}

function openPlacePage(placeId){
  currentPlaceId = placeId; 
  const place = placesData[placeId];

  // ===== UPDATE PAGE CONTENT =====
  const pageTitle = document.getElementById('des_pageTitle');
  if(pageTitle) pageTitle.textContent = place.name;

  const pageHeaderImg = document.getElementById('des_pageHeaderImg');
  if(pageHeaderImg) pageHeaderImg.src = place.image;

  const descriptionElement = document.getElementById('des_pageDescription');
    if (descriptionElement) {
      descriptionElement.innerHTML = formatToParagraphs(place.description);
    }

  const activitiesGrid = document.getElementById('des_activitiesGrid');
  if(activitiesGrid){
    activitiesGrid.innerHTML = '';
    place.activities.forEach(a => {
      const div = document.createElement('div');
      div.className = 'des_activity-item';
      const iconSrc = activityIcons[a] || 'icons/default.png'; // fallback if missing
      div.innerHTML = `<img src="${iconSrc}" alt="${a}" class="activity-icon"> ${a}`;
      activitiesGrid.appendChild(div);
    });
  }

  // Gallery
  currentGalleryImages = place.gallery.slice();
  const gallery = document.getElementById('des_imageGallery');
  if(gallery){
    gallery.innerHTML = '';
    place.gallery.forEach((src,i)=>{
      gallery.appendChild(createGalleryImg(src, place.name, () => openImageModal(i)));
    });
  }

  // Map
  hidePlaceMap();
  setPlaceMap(place.coords, 14);

// ===== RESET TABS =====
const aboutBtn = document.getElementById('aboutBtn');
const resortBtn = document.getElementById('resortBtn');
const aboutSection = document.getElementById('sectionAbout');
const resortsSection = document.getElementById('sectionResorts');
const resortListDiv = document.getElementById('resortList');
const resortPage = document.getElementById('des_resortPage');
const resortBody = document.getElementById('resortBody');

// 1️⃣ Clear all visited/active classes
if(aboutBtn) aboutBtn.classList.remove('visited', 'active');
if(resortBtn) resortBtn.classList.remove('visited', 'active');

// 2️⃣ Show About section, hide Resorts section
if(aboutSection) aboutSection.classList.add('show');
if(resortsSection) resortsSection.classList.remove('show');

// 3️⃣ Mark About as visited and active
if(aboutBtn){
  aboutBtn.classList.add('visited', 'active');
}

// 4️⃣ Ensure Resort button is NOT visited or active
if(resortBtn){
  resortBtn.classList.remove('visited', 'active');
}

// 5️⃣ Clear Resort content and hide Resort page if open
if(resortListDiv) resortListDiv.innerHTML = '';
if(resortPage){
  resortPage.classList.remove('open');
  resortPage.setAttribute('aria-hidden','true');
  resortPage.inert = true;
}
if(resortBody) resortBody.classList.remove('show');


  // ===== SHOW PLACE PAGE =====
  const placePage = document.getElementById('des_placePage');
  if(placePage){
    placePage.classList.add('open');
    placePage.setAttribute('aria-hidden','false');
    placePage.inert = false;
    setTimeout(()=>{
      const placeBody = document.getElementById('placeBody');
      if(placeBody) placeBody.classList.add('show');
    }, 20);
  }

  const fixedButtons = document.getElementById('des_fixedButtons');
  if(fixedButtons) fixedButtons.classList.add('show');
  document.body.style.overflow = 'hidden';
}


  // About / Resorts sections
  const aboutBtn = document.getElementById('aboutBtn');
  const resortBtn = document.getElementById('resortBtn');
  const aboutSection = document.getElementById('sectionAbout');
  const resortsSection = document.getElementById('sectionResorts');

  if(aboutBtn && resortBtn && aboutSection && resortsSection){
    aboutBtn.classList.remove('visited')
    resortBtn.classList.remove('visited');

    aboutSection.classList.add('show');
    resortsSection.classList.remove('show');

    aboutBtn.classList.add('visited');
  }

  const resortListDiv = document.getElementById('resortList');
  if(resortListDiv) resortListDiv.innerHTML = '';


function closePlacePage() {
  const placePage = document.getElementById('des_placePage');
  const fixedButtons = document.getElementById('des_fixedButtons');

  // ✅ STEP 1: REMOVE FOCUS FIRST (THIS STOPS THE ERROR)
  if (document.activeElement && placePage.contains(document.activeElement)) {
    document.activeElement.blur();
  }

  if (document.activeElement && fixedButtons?.contains(document.activeElement)) {
    document.activeElement.blur();
  }

  // ✅ STEP 2: HIDE VISUALLY
  placePage.classList.remove('open');
  fixedButtons?.classList.remove('show');

  // ✅ STEP 3: HIDE FROM ACCESSIBILITY TREE SAFELY
  placePage.setAttribute('aria-hidden', 'true');
  placePage.inert = true;

  if (fixedButtons) {
    fixedButtons.setAttribute('aria-hidden', 'true');
    fixedButtons.inert = true;
  }

  // ✅ STEP 4: RESTORE SCROLL
  document.body.style.overflow = 'auto';
}



function showAbout(){
  document.getElementById('aboutBtn').classList.add('visited');
  document.getElementById('resortBtn').classList.remove('visited');
}

// ======== RESORTS FUNCTIONS ========

function backToPlace(){
  const resortBody=document.getElementById('resortBody');
  resortBody.classList.remove('show');
  setTimeout(()=>{
    document.getElementById('des_resortPage').classList.remove('open');
    document.getElementById('des_placePage').classList.add('open');
    setTimeout(()=>document.getElementById('placeBody').classList.add('show'),20);
    // restore map for the place
    const place = placesData[currentPlaceId];
    if(place && place.coords) setPlaceMap(place.coords, currentMapZoom);
    showAbout();
  }, 260);
}

// Place Gallery Modal
function openImageModal(index){
  currentImageIndex = index;

  const modal = document.getElementById('des_imageModal');
  const modalImg = document.getElementById('des_imageModalImg');
  const counter = document.getElementById('des_imageCounter');

  modalImg.src = currentGalleryImages[index];
  counter.textContent = `${index + 1}/${currentGalleryImages.length}`;

  modal.classList.add('visible');
  modal.setAttribute('aria-hidden', 'false');
  modal.inert = false;

  // ✅ Move focus safely into modal
  modal.querySelector('button')?.focus();
}


function closeImageModal() {
  const modal = document.getElementById('des_imageModal');
  if (!modal) return;

  // ✅ Remove focus FIRST before hiding
  if (modal.contains(document.activeElement)) {
    document.activeElement.blur();
  }

  modal.classList.remove('visible');
  modal.setAttribute('aria-hidden', 'true');
  modal.inert = true;
}



function changeImage(dir){ currentImageIndex+=dir; if(currentImageIndex<0) currentImageIndex=currentGalleryImages.length-1; else if(currentImageIndex>=currentGalleryImages.length) currentImageIndex=0; document.getElementById('des_imageModalImg').src=currentGalleryImages[currentImageIndex]; document.getElementById('des_imageCounter').textContent=`${currentImageIndex+1}/${currentGalleryImages.length}`; }

// Resort Gallery Modal
function openResortImageModal(resortIndex, imgIndex){
  currentResortImages = placesData[currentPlaceId].resorts[resortIndex].gallery.slice();
  currentResortIndex = imgIndex;

  const modal = document.getElementById('des_resortModal');
  const img = document.getElementById('des_resortModalImg');
  const counter = document.getElementById('des_resortCounter');

  img.src = currentResortImages[imgIndex];
  counter.textContent = `${imgIndex + 1}/${currentResortImages.length}`;

  modal.classList.add('visible');
  modal.setAttribute('aria-hidden', 'false');
  modal.inert = false;

  // ✅ Move focus safely into modal
  modal.querySelector('button')?.focus();
}


function closeResortImageModal() {
  const modal = document.getElementById('des_resortModal');
  if (!modal) return;

  // ✅ Remove focus FIRST before hiding
  if (modal.contains(document.activeElement)) {
    document.activeElement.blur();
  }

  modal.classList.remove('visible');
  modal.setAttribute('aria-hidden', 'true');
  modal.inert = true;
}

function changeResortImage(dir){ currentResortIndex+=dir; if(currentResortIndex<0) currentResortIndex=currentResortImages.length-1; else if(currentResortIndex>=currentResortImages.length) currentResortIndex=0; document.getElementById('des_resortModalImg').src=currentResortImages[currentResortIndex]; document.getElementById('des_resortCounter').textContent=`${currentResortIndex+1}/${currentResortImages.length}`; }

// Keyboard controls: Escape close, arrows navigate
document.addEventListener('keydown',(e)=>{
  const placeModal=document.getElementById('des_imageModal');
  const resortModal=document.getElementById('des_resortModal');
  if(e.key==='Escape'){ if(placeModal.classList.contains('visible')) closeImageModal(); if(resortModal.classList.contains('visible')) closeResortImageModal(); }
  if(e.key==='ArrowLeft'){ if(placeModal.classList.contains('visible')) changeImage(-1); if(resortModal.classList.contains('visible')) changeResortImage(-1); }
  if(e.key==='ArrowRight'){ if(placeModal.classList.contains('visible')) changeImage(1); if(resortModal.classList.contains('visible')) changeResortImage(1); }
});

window.onload=()=>{ loadPlaces(); };
// ensure inline onclick handlers work (expose close function globally)
if (typeof closePlacePage === 'function') {
  window.closePlacePage = closePlacePage;
  window.closePage = closePlacePage; // in case HTML still uses closePage()
} else if (typeof closePage === 'function') {
  window.closePlacePage = closePage;
  window.closePage = closePage;
} else {
  // fallback global that logs a helpful message instead of throwing
  window.closePlacePage = function() {
    console.error('No closePlacePage/closePage function found — make sure your JS is loaded and the function is defined before this line.');
  };
}

// ===== SHOW TAB SECTION =====
function showSection(section) {
  const about = document.getElementById("sectionAbout");
  const resorts = document.getElementById("sectionResorts");
  const navAbout = document.getElementById("navAbout");
  const navResorts = document.getElementById("navResorts");
  const resortListDiv = document.getElementById("resortList");

  // Reset all
  about.classList.remove("show");
  resorts.classList.remove("show");
  navAbout.classList.remove("active");
  navResorts.classList.remove("active");

  // Activate selected tab
  if (section === "about") {
    about.classList.add("show");
    navAbout.classList.add("active");
  }

  if (section === "resort") {
    resorts.classList.add("show");
    navResorts.classList.add("active");

    // Load resorts fresh every time
    if (resortListDiv) resortListDiv.innerHTML = "";
    loadResortsForCurrentPlace();
  }
}


// ===== LOAD RESORTS FOR CURRENT PLACE =====
function loadResortsForCurrentPlace() {
  if (!currentPlaceId || !placesData[currentPlaceId]) return;

  const place = placesData[currentPlaceId];
  const resortListDiv = document.getElementById('resortList');
  if (!resortListDiv) return;

  resortListDiv.innerHTML = '';

  if (!place.resorts || place.resorts.length === 0) {
    resortListDiv.innerHTML = "<p>No resorts available for this location.</p>";
    return;
  }

  place.resorts.forEach((resort, index) => {
    // Resort name outside the frame
    const resortName = document.createElement('h3');
    resortName.textContent = resort.name;
    resortName.className = 'resort-name-outside';
    resortListDiv.appendChild(resortName);

    // Resort description frame
    const descDiv = document.createElement('div');
    descDiv.className = 'des_info-section';
    descDiv.innerHTML = formatToParagraphs(resort.description);
    resortListDiv.appendChild(descDiv);

    // Things To Do section outside the frame
    const activitiesSection = document.createElement('div');
    activitiesSection.className = 'des_activities-section-outside';
    activitiesSection.innerHTML = `
      <h4>Things To Do:</h4>
      <div class="des_activities-grid">
        ${resort.activities.map(a => `<div class="des_activity-item">${activityIcons[a]?`<img src="${activityIcons[a]}" alt="${a}" class="activity-icon">`:'✓'} ${a}</div>`).join('')}
      </div>
    `;
    resortListDiv.appendChild(activitiesSection);

    // Gallery outside the frame
    const galleryDiv = document.createElement('div');
    galleryDiv.className = 'des_image-gallery-outside';
    galleryDiv.id = `resort-gallery-${index}`;
    resortListDiv.appendChild(galleryDiv);

    

    resort.gallery.forEach((src, i) => {
      galleryDiv.appendChild(createGalleryImg(src, resort.name, () => openResortImageModal(index, i)));
    });
  });
}



function openPlacePage(placeId) {
  if (!placesData[placeId]) return;

  currentPlaceId = placeId;
  const place = placesData[placeId];

  // ===== UPDATE CONTENT =====
  document.getElementById('des_pageTitle').textContent = place.name;
  document.getElementById('des_pageHeaderImg').src = place.image;

  const descriptionElement = document.getElementById('des_pageDescription');
  descriptionElement.innerHTML = formatToParagraphs(place.description);

  // Activities
  const activitiesGrid = document.getElementById('des_activitiesGrid');
  activitiesGrid.innerHTML = '';
place.activities.forEach(a => {
  const div = document.createElement('div');
  div.className = 'des_activity-item';
  const iconSrc = activityIcons[a] || 'icons/default.png'; // fallback icon
  div.innerHTML = `<img src="${iconSrc}" alt="${a}" class="activity-icon"> ${a}`;
  activitiesGrid.appendChild(div);
});


  // Gallery
  currentGalleryImages = [...place.gallery];
  const gallery = document.getElementById('des_imageGallery');
  gallery.innerHTML = '';
  place.gallery.forEach((src, i) => {
    gallery.appendChild(createGalleryImg(src, place.name, () => openImageModal(i)));
  });

  // Map
  hidePlaceMap();
  setPlaceMap(place.coords, 14);

  // ✅ ALWAYS start on About tab
  showSection("about");

  // ===== SHOW PAGE =====
  const placePage = document.getElementById('des_placePage');
  const placeBody = document.getElementById('placeBody');

  placePage.classList.add('open');
  placePage.setAttribute('aria-hidden', 'false');
  placePage.inert = false;

  setTimeout(() => placeBody.classList.add('show'), 20);

  // Lock scroll
  document.getElementById('des_fixedButtons')?.classList.add('show');
  document.body.style.overflow = 'hidden';
}