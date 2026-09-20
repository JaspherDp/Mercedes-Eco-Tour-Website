// ======== DATA ========
const placesData = window.destinationData || {
  'apuao-pequena island': {
    name:'Sun, Sand, Serenity',
    image:'imagess/Apuao Pequena_header-img.png',
    description:'Apuao Pequeña offers the best place for family bonding and camping. Families and tourists alike enjoy the shade under the cool pine trees along the white powdery sand and crystal clear blue waters. Mediation practitioners as well as those seeking relaxation may unwind from their toxic urban life along the sand bar that offers the perfect view of the pacific ocean. Adventurers may enjoy trekking and bat watching before the high waves on the other side of the island which is ideal for surfing and other water sports.',
    coords:{ lat:14.083545760332994, lng:123.10354227771039 },
    activities:['Scuba Diving','Photography','Surfing', 'Camping', 'Swimming', 'Kayaking', 'Fishing'],
    gallery:[
      'imagess/Apuao Pequena_Gallery (1).JPG',
      'imagess/Apuao Pequena_Gallery (2).JPG',
      'imagess/Apuao Pequena_Gallery (3).JPG',
      'imagess/Apuao Pequena_Gallery (4).JPG',
      'imagess/Apuao Pequena_Gallery (5).JPG',
      'imagess/Apuao Pequena_Gallery (6).JPG',
      'imagess/Apuao Pequena_Gallery (7).JPG',
      'imagess/Apuao Pequena_Gallery (8).JPG',
      'imagess/Apuao Pequena_Gallery (9).JPG',
      'imagess/Apuao Pequena_Gallery (10).JPG'
    ],
    resorts:[{
      name:'Island View Resort',
      description:'Apuao Pequeña Island, part of the Mercedes Islands in Camarines Norte, Philippines, is a tranquil paradise known for its unique landscape of white sand beaches fringed with tall Agoho trees (pine-like), offering a tropical-meets-forest vibe, calm waters, and abundant fruit bats, all connected to its larger neighbor, Apuao Grande, by a stunning low-tide sandbar perfect for peaceful relaxation, camping, and island hopping. Apuao Pequeña Island, part of the Mercedes Islands in Camarines Norte, Philippines, is a tranquil paradise known for its unique landscape of white sand beaches fringed with tall Agoho trees (pine-like), offering a tropical-meets-forest vibe, calm waters, and abundant fruit bats, all connected to its larger neighbor, Apuao Grande, by a stunning low-tide sandbar perfect for peaceful relaxation, camping, and island hopping. Apuao Pequeña Island, part of the Mercedes Islands in Camarines Norte, Philippines, is a tranquil paradise known for its unique landscape of white sand beaches fringed with tall Agoho trees (pine-like), offering a tropical-meets-forest vibe, calm waters, and abundant fruit bats, all connected to its larger neighbor, Apuao Grande, by a stunning low-tide sandbar perfect for peaceful relaxation, camping, and island hopping. Apuao Pequeña Island, part of the Mercedes Islands in Camarines Norte, Philippines, is a tranquil paradise known for its unique landscape of white sand beaches fringed with tall Agoho trees (pine-like), offering a tropical-meets-forest vibe, calm waters, and abundant fruit bats, all connected to its larger neighbor, Apuao Grande, by a stunning low-tide sandbar perfect for peaceful relaxation, camping, and island hopping. Apuao Pequeña Island, part of the Mercedes Islands in Camarines Norte, Philippines, is a tranquil paradise known for its unique landscape of white sand beaches fringed with tall Agoho trees (pine-like), offering a tropical-meets-forest vibe, calm waters, and abundant fruit bats, all connected to its larger neighbor, Apuao Grande, by a stunning low-tide sandbar perfect for peaceful relaxation, camping, and island hopping. ',
      activities:['Dining','Boat Tour','Sunset Watching', 'Kayaking', 'Swimming'],
      gallery:[
      'imagess/Apuao Pequena_Gallery (1).JPG',
      'imagess/Apuao Pequena_Gallery (2).JPG',
      'imagess/Apuao Pequena_Gallery (3).JPG',
      'imagess/Apuao Pequena_Gallery (4).JPG',
      'imagess/Apuao Pequena_Gallery (5).JPG',
      'imagess/Apuao Pequena_Gallery (6).JPG',
      'imagess/Apuao Pequena_Gallery (7).JPG',
      'imagess/Apuao Pequena_Gallery (8).JPG',
      'imagess/Apuao Pequena_Gallery (9).JPG',
      'imagess/Apuao Pequena_Gallery (10).JPG'
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
      'imagess/Apuao Grande_Gallery (1).JPG',
      'imagess/Apuao Grande_Gallery (2).JPG',
      'imagess/Apuao Grande_Gallery (3).JPG',
      'imagess/Apuao Grande_Gallery (4).JPG',
      'imagess/Apuao Grande_Gallery (5).JPG',
      'imagess/Apuao Grande_Gallery (6).JPG',
      'imagess/Apuao Grande_Gallery (7).JPG',
      'imagess/Apuao Grande_Gallery (8).JPG',
      'imagess/Apuao Grande_Gallery (9).JPG',
      'imagess/Apuao Grande_Gallery.JPG'
    ],
    resorts:[{
      name:'Sunset Cove Resort',
      description:'Famous for sunsets.',
      activities:['Swimming','Beach Party','Bonfire'],
      gallery:[
      'imagess/Apuao Grande_Gallery (1).JPG',
      'imagess/Apuao Grande_Gallery (2).JPG',
      'imagess/Apuao Grande_Gallery (3).JPG',
      'imagess/Apuao Grande_Gallery (4).JPG',
      'imagess/Apuao Grande_Gallery (5).JPG',
      'imagess/Apuao Grande_Gallery (6).JPG',
      'imagess/Apuao Grande_Gallery (7).JPG',
      'imagess/Apuao Grande_Gallery (8).JPG',
      'imagess/Apuao Grande_Gallery (9).JPG',
      'imagess/Apuao Grande_Gallery.JPG'
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
      'imagess/Quinapaguian_Gallery (1).JPG',
      'imagess/Quinapaguian_Gallery (2).JPG',
      'imagess/Quinapaguian_Gallery (3).JPG',
      'imagess/Quinapaguian_Gallery (4).JPG',
      'imagess/Quinapaguian_Gallery (5).JPG',
      'imagess/Quinapaguian_Gallery (6).JPG',
      'imagess/Quinapaguian_Gallery (7).JPG',
      'imagess/Quinapaguian_Gallery (8).JPG',
      'imagess/Quinapaguian_Gallery (9).JPG',
      'imagess/Quinapaguian_Gallery (10).JPG'
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
      'imagess/Canimog_Gallery (2).JPG',
      'imagess/Canimog_Gallery (1).JPG',
      'imagess/Canimog_Gallery (3).JPG',
      'imagess/Canimog_Gallery (4).JPG',
      'imagess/Canimog_Gallery (5).JPG',
      'imagess/Canimog_Gallery (6).JPG',
      'imagess/Canimog_Gallery (7).JPG',
      'imagess/Canimog_Gallery (8).JPG',
      'imagess/Canimog_Gallery (9).JPG',
      'imagess/Canimog_Gallery (10).JPG'
    ],
    resorts:[{
      name:'Palm Beach Resort',
      description:'Tropical resort with palm trees.',
      activities:['Swimming','Photography','Dining'],
      gallery:[
         'imagess/Canimog_Gallery (2).JPG',
      'imagess/Canimog_Gallery (1).JPG',
      'imagess/Canimog_Gallery (3).JPG',
      'imagess/Canimog_Gallery (4).JPG',
      'imagess/Canimog_Gallery (5).JPG',
      'imagess/Canimog_Gallery (6).JPG',
      'imagess/Canimog_Gallery (7).JPG',
      'imagess/Canimog_Gallery (8).JPG',
      'imagess/Canimog_Gallery (9).JPG',
      'imagess/Canimog_Gallery (10).JPG'
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
       'imagess/Caringo_Gallery (1).JPG',
      'imagess/Caringo_Gallery (12).JPG',
      'imagess/Caringo_Gallery (13).JPG',
      'imagess/Caringo_Gallery (14).JPG',
      'imagess/Caringo_Gallery (15).JPG',
      'imagess/Caringo_Gallery (16).JPG',
      'imagess/Caringo_Gallery (17).JPG',
      'imagess/Caringo_Gallery (18).JPG',
      'imagess/Caringo_Gallery (20).JPG',
      'imagess/Caringo_Gallery (21).JPG'
    ],
    resorts:[{
      name:'Sunrise Retreat',
      description:'Peaceful resort near the shore.',
      activities:['Yoga','Meditation','Beach Walks'],
      gallery:[
         'imagess/Caringo_Gallery (1).JPG',
      'imagess/Caringo_Gallery (12).JPG',
      'imagess/Caringo_Gallery (13).JPG',
      'imagess/Caringo_Gallery (14).JPG',
      'imagess/Caringo_Gallery (15).JPG',
      'imagess/Caringo_Gallery (16).JPG',
      'imagess/Caringo_Gallery (17).JPG',
      'imagess/Caringo_Gallery (18).JPG',
      'imagess/Caringo_Gallery (20).JPG',
      'imagess/Caringo_Gallery (21).JPG'
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
      'imagess/Malasugui_Gallery (1).JPG',
      'imagess/Malasugui_Gallery (2).JPG',
      'imagess/Malasugui_Gallery (3).JPG',
      'imagess/Malasugui_Gallery (4).JPG',
      'imagess/Malasugui_Gallery (5).JPG',
      'imagess/Malasugui_Gallery (6).JPG',
      'imagess/Malasugui_Gallery (7).JPG',
      'imagess/Malasugui_Gallery (8).JPG',
      'imagess/Malasugui_Gallery (9).JPG',
      'imagess/Malasugui_Gallery (10).JPG'
    ],
    resorts:[{
      name:'Coral Bay Resort',
      description:'Resort with coral reef nearby.',
      activities:['Scuba Diving','Swimming','Boat Tour'],
      gallery:[
        'imagess/Malasugui_Gallery (1).JPG',
      'imagess/Malasugui_Gallery (2).JPG',
      'imagess/Malasugui_Gallery (3).JPG',
      'imagess/Malasugui_Gallery (4).JPG',
      'imagess/Malasugui_Gallery (5).JPG',
      'imagess/Malasugui_Gallery (6).JPG',
      'imagess/Malasugui_Gallery (7).JPG',
      'imagess/Malasugui_Gallery (8).JPG',
      'imagess/Malasugui_Gallery (9).JPG',
      'imagess/Malasugui_Gallery (10).JPG'
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
  if (window.__destinationV2Ready) return;
  const placeModal=document.getElementById('des_imageModal');
  const resortModal=document.getElementById('des_resortModal');
  if(e.key==='Escape'){ if(placeModal.classList.contains('visible')) closeImageModal(); if(resortModal.classList.contains('visible')) closeResortImageModal(); }
  if(e.key==='ArrowLeft'){ if(placeModal.classList.contains('visible')) changeImage(-1); if(resortModal.classList.contains('visible')) changeResortImage(-1); }
  if(e.key==='ArrowRight'){ if(placeModal.classList.contains('visible')) changeImage(1); if(resortModal.classList.contains('visible')) changeResortImage(1); }
});

window.onload=()=>{ if (!window.__destinationV2Ready) loadPlaces(); };
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

// ======== Destination UI Refactor Override ========
(() => {
  window.__destinationV2Ready = true;
  let currentGalleryImagesLocal = [];
  let currentImageIndexLocal = 0;
  let currentGalleryPageLocal = 0;
  let currentResortImagesLocal = [];
  let currentResortIndexLocal = 0;
  let currentMapZoomLocal = 14;
  let currentPlaceIdLocal = null;
  let destinationPreviewMapLocal = null;
  let destinationFullMapLocal = null;
  let destinationPreviewLayerLocal = null;
  let destinationFullLayerLocal = null;
  const destinationMarkerLookupLocal = new Map();

  const iconMap = activityIcons || {};

  function placeLabelFromId(placeId) {
    if (placesData[placeId]?.title) return placesData[placeId].title;
    return String(placeId || '')
      .replace(/-/g, ' ')
      .replace(/\bisland\b/gi, '')
      .replace(/\s+/g, ' ')
      .trim()
      .replace(/\b\w/g, (char) => char.toUpperCase());
  }

  function updateDestinationUrlV2(placeId) {
    const url = new URL(window.location.href);
    url.searchParams.set('destination', placeId);
    history.replaceState({ ...(history.state || {}), destination: placeId }, '', url);
  }

  function clearDestinationUrlV2() {
    const url = new URL(window.location.href);
    url.searchParams.delete('destination');
    const nextState = { ...(history.state || {}) };
    delete nextState.destination;
    history.replaceState(nextState, '', url);
  }

  function normalizePlaceImageSources() {
    Object.values(placesData).forEach((place) => {
      place.heroImage = place.heroImage || place.image || '';
      place.cardImage = place.cardImage || place.heroImage || (Array.isArray(place.gallery) && place.gallery[0]) || '';
    });
  }

  function destinationHeroSourceV2(place) {
    if (!place) return '';
    return window.matchMedia('(max-width: 640px)').matches
      ? (place.cardImage || place.heroImage || place.image || '')
      : (place.heroImage || place.cardImage || place.image || '');
  }

  function syncResponsiveDestinationHeroV2() {
    if (!currentPlaceIdLocal || !placesData[currentPlaceIdLocal]) return;
    const heroImg = document.getElementById('des_pageHeaderImg');
    if (heroImg) heroImg.src = destinationHeroSourceV2(placesData[currentPlaceIdLocal]);
  }

  function destinationThumbnailV2(src) {
    const value = String(src || '');
    if (!/^imagess\//i.test(value)) return value;
    const filename = value.split('/').pop().replace(/\.[^.]+$/, '.jpg');
    return `imagess/destination-thumbs/${filename}`;
  }

  function toParagraphs(text, maxSentences = 4) {
    if (!text) return '';
    const sentences = String(text)
      .replace(/PequeÃ±a/g, 'Pequeña')
      .replace(/Â·|â€¢/g, '·')
      .replace(/\s+/g, ' ')
      .replace(/([a-z])([A-Z])/g, '$1. $2')
      .split(/(?<=[.!?])\s+/)
      .map((s) => s.trim())
      .filter(Boolean);
    let output = '';
    for (let i = 0; i < sentences.length; i += maxSentences) {
      output += `<p>${sentences.slice(i, i + maxSentences).join(' ')}</p>`;
    }
    return output;
  }

  function createGalleryThumb(src, alt, onClick) {
    const img = document.createElement('img');
    img.className = 'des_gallery-img';
    const thumbnailSrc = destinationThumbnailV2(src);
    img.src = thumbnailSrc;
    img.alt = alt || '';
    img.loading = 'lazy';
    img.decoding = 'async';
    const reveal = () => img.classList.add('loaded');
    img.addEventListener('load', reveal, { once: true });
    img.addEventListener('error', () => {
      if (img.src !== new URL(src, document.baseURI).href) img.src = src;
      reveal();
    }, { once: true });
    if (img.complete) reveal();
    img.addEventListener('click', onClick);
    return img;
  }

  function renderEditorialGalleryV2() {
    const gallery = document.getElementById('des_imageGallery');
    const galleryCount = document.getElementById('des_galleryCount');
    const arrows = document.querySelectorAll('.des_gallery-arrows button');
    const total = currentGalleryImagesLocal.length;
    const pageSize = 3;
    const pageCount = Math.max(1, Math.ceil(total / pageSize));

    currentGalleryPageLocal = ((currentGalleryPageLocal % pageCount) + pageCount) % pageCount;
    if (gallery) {
      gallery.innerHTML = '';
      const start = currentGalleryPageLocal * pageSize;
      const visibleIndexes = [];
      for (let offset = 0; offset < Math.min(pageSize, total); offset += 1) {
        visibleIndexes.push((start + offset) % total);
      }
      visibleIndexes.forEach((imageIndex) => {
        const thumb = createGalleryThumb(
          currentGalleryImagesLocal[imageIndex],
          `${placesData[currentPlaceIdLocal]?.name || 'Destination'} photo ${imageIndex + 1}`,
          () => openImageModalV2(imageIndex)
        );
        thumb.loading = 'eager';
        gallery.appendChild(thumb);
      });
    }

    if (galleryCount) {
      if (!total) galleryCount.textContent = 'No local photos';
      else galleryCount.textContent = `Set ${currentGalleryPageLocal + 1} of ${pageCount} \u00b7 ${total} photos`;
    }
    arrows.forEach((button) => {
      button.disabled = total <= pageSize;
    });
  }

  function changeGallerySetV2(direction) {
    if (currentGalleryImagesLocal.length <= 3) return;
    currentGalleryPageLocal += direction;
    renderEditorialGalleryV2();
  }

  function createActivityItemV2(activity) {
    const div = document.createElement('div');
    div.className = 'des_activity-item';
    const iconSrc = iconMap[activity];
    div.innerHTML = `${iconSrc ? `<img src="${iconSrc}" alt="${activity}" class="activity-icon">` : ''}${activity}`;
    return div;
  }

  function buildMapSrcV2(lat, lng, zoom) {
    return `https://www.google.com/maps?q=${lat},${lng}&z=${zoom}&output=embed`;
  }

  function hidePlaceMapV2() {
    const mapSection = document.getElementById('placeMapSection');
    const iframe = document.getElementById('des_placeMap');
    if (iframe) iframe.src = '';
    if (mapSection) mapSection.setAttribute('aria-hidden', 'true');
  }

  function setPlaceMapV2(coords, zoom = 14) {
    const mapSection = document.getElementById('placeMapSection');
    const iframe = document.getElementById('des_placeMap');
    if (!mapSection || !iframe || !coords || !coords.lat || !coords.lng) {
      hidePlaceMapV2();
      return;
    }

    currentMapZoomLocal = zoom;
    iframe.src = buildMapSrcV2(coords.lat, coords.lng, zoom);
    mapSection.setAttribute('aria-hidden', 'false');

    let meta = mapSection.querySelector('.map-meta');
    if (!meta) {
      meta = document.createElement('div');
      meta.className = 'map-meta';
      mapSection.appendChild(meta);
    }
    meta.textContent = `Coordinates: ${coords.lat.toFixed(5)}, ${coords.lng.toFixed(5)} • Interactive map available.`;
  }

  function mappedDestinationsV2() {
    return Object.entries(placesData)
      .filter(([, place]) => place.coords?.lat && place.coords?.lng)
      .map(([id, place], index) => ({
        id,
        place,
        index,
        label: placeLabelFromId(id),
        lat: place.coords.lat,
        lng: place.coords.lng
      }));
  }

  function destinationMarkerIconV2(index, imageSrc, active = false) {
    return L.divIcon({
      className: 'des_destination-marker-shell',
      html: `<span class="des_destination-marker${active ? ' active' : ''}"><img src="${imageSrc}" alt=""><b>${String(index + 1).padStart(2, '0')}</b></span>`,
      iconSize: [48, 48],
      iconAnchor: [24, 24],
      popupAnchor: [0, -25]
    });
  }

  function addDestinationTilesV2(map) {
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
  }

  function initDestinationPreviewMapV2() {
    const canvas = document.getElementById('destinationMapPreview');
    const destinations = mappedDestinationsV2();
    if (!canvas || !destinations.length || typeof L === 'undefined') return;

    if (!destinationPreviewMapLocal) {
      destinationPreviewMapLocal = L.map(canvas, {
        zoomControl: false,
        attributionControl: false,
        dragging: false,
        scrollWheelZoom: false,
        doubleClickZoom: false,
        boxZoom: false,
        keyboard: false,
        tap: false
      });
      addDestinationTilesV2(destinationPreviewMapLocal);
    }

    if (destinationPreviewLayerLocal) destinationPreviewLayerLocal.clearLayers();
    destinationPreviewLayerLocal = L.layerGroup().addTo(destinationPreviewMapLocal);
    const bounds = [];
    destinations.forEach((destination) => {
      L.marker([destination.lat, destination.lng], {
        icon: destinationMarkerIconV2(destination.index, destination.place.cardImage || destination.place.heroImage || destination.place.image, destination.id === currentPlaceIdLocal),
        interactive: false
      }).addTo(destinationPreviewLayerLocal);
      bounds.push([destination.lat, destination.lng]);
    });
    destinationPreviewMapLocal.fitBounds(bounds, { padding: [18, 18], maxZoom: 11 });
    setTimeout(() => destinationPreviewMapLocal?.invalidateSize(), 80);
  }

  function destinationPopupV2(destination) {
    return `<div class="des_destination-popup"><span>${/island/i.test(destination.id) ? 'Island escape' : 'Local landmark'}</span><strong>${destination.label}</strong><button type="button" onclick="selectDestinationFromMap('${destination.id}')">View destination</button></div>`;
  }

  function renderDestinationMapResultsV2(destinations) {
    const results = document.getElementById('destinationMapResults');
    if (!results) return;
    results.innerHTML = destinations.map((destination) => `
      <button type="button" class="des_destination-map-result${destination.id === currentPlaceIdLocal ? ' active' : ''}" data-destination-id="${destination.id}" onclick="focusDestinationOnMap('${destination.id}')">
        <img src="${destination.place.cardImage || destination.place.heroImage || destination.place.image}" alt="">
        <span><small>${/island/i.test(destination.id) ? 'Island escape' : 'Local landmark'}</small><strong>${destination.label}</strong><b>${(destination.place.activities || []).length} things to do</b></span>
      </button>
    `).join('');
  }

  function openDestinationMapV2() {
    const modal = document.getElementById('destinationMapModal');
    const canvas = document.getElementById('destinationMapCanvas');
    const destinations = mappedDestinationsV2();
    if (!modal || !canvas || !destinations.length) return;

    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    renderDestinationMapResultsV2(destinations);

    if (typeof L === 'undefined') return;
    if (!destinationFullMapLocal) {
      destinationFullMapLocal = L.map(canvas, { zoomControl: true });
      addDestinationTilesV2(destinationFullMapLocal);
    }
    if (destinationFullLayerLocal) destinationFullLayerLocal.clearLayers();
    destinationMarkerLookupLocal.clear();
    destinationFullLayerLocal = L.layerGroup().addTo(destinationFullMapLocal);
    const bounds = [];
    destinations.forEach((destination) => {
      const marker = L.marker([destination.lat, destination.lng], {
        icon: destinationMarkerIconV2(destination.index, destination.place.cardImage || destination.place.heroImage || destination.place.image, destination.id === currentPlaceIdLocal)
      }).bindPopup(destinationPopupV2(destination));
      marker.addTo(destinationFullLayerLocal);
      destinationMarkerLookupLocal.set(destination.id, marker);
      bounds.push([destination.lat, destination.lng]);
    });
    destinationFullMapLocal.fitBounds(bounds, { padding: [38, 38], maxZoom: 12 });
    setTimeout(() => {
      destinationFullMapLocal?.invalidateSize();
      if (currentPlaceIdLocal) focusDestinationOnMapV2(currentPlaceIdLocal, false);
    }, 100);
  }

  function closeDestinationMapV2() {
    const modal = document.getElementById('destinationMapModal');
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
  }

  function focusDestinationOnMapV2(placeId, animate = true) {
    const destination = mappedDestinationsV2().find((item) => item.id === placeId);
    const marker = destinationMarkerLookupLocal.get(placeId);
    if (!destination || !destinationFullMapLocal) return;
    destinationFullMapLocal.setView([destination.lat, destination.lng], 14, { animate });
    marker?.openPopup();
    document.querySelectorAll('.des_destination-map-result').forEach((card) => {
      card.classList.toggle('active', card.dataset.destinationId === placeId);
    });
    document.querySelector(`.des_destination-map-result[data-destination-id="${placeId}"]`)?.scrollIntoView({ block: 'nearest', behavior: animate ? 'smooth' : 'auto' });
  }

  function selectDestinationFromMapV2(placeId) {
    closeDestinationMapV2();
    openPlacePageV2(placeId);
  }

  function loadPlacesV2() {
    const container = document.getElementById('placeGridContainer');
    if (!container) return;

    normalizePlaceImageSources();
    container.innerHTML = '';

    Object.entries(placesData).forEach(([placeId, place], index) => {
      const placeLabel = placeLabelFromId(placeId);
      const placeType = place.type || (/island/i.test(placeId) ? 'Island escape' : 'Local landmark');
      const activityCount = Array.isArray(place.activities) ? place.activities.length : 0;
      const previewActivities = (place.activities || []).slice(0, 2);
      const card = document.createElement('article');
      card.className = 'des_place-card';
      card.setAttribute('role', 'button');
      card.setAttribute('tabindex', '0');
      card.dataset.search = `${placeLabel} ${place.name || ''} ${(place.activities || []).join(' ')}`.toLowerCase();
      card.innerHTML = `
        <div class="des_place-image-wrap">
          <img src="${place.cardImage}" alt="${placeLabel}">
          <span class="des_place-index">${String(index + 1).padStart(2, '0')}</span>
          <span class="des_place-type">${placeType}</span>
        </div>
        <div class="des_place-content">
          <span class="des_place-location">${place.location || 'Mercedes, Camarines Norte'}</span>
          <h3 class="des_place-caption">${placeLabel}</h3>
          <p class="des_place-subcaption">${place.name || 'View destination details and nearby resorts'}</p>
          <div class="des_place-preview">
            ${previewActivities.map((activity) => `<span>${activity}</span>`).join('')}
          </div>
          <div class="des_place-footer">
            <span>${activityCount} ${activityCount === 1 ? 'activity' : 'activities'}</span>
            <strong>Explore <i aria-hidden="true">→</i></strong>
          </div>
        </div>
      `;

      const openCard = () => openPlacePageV2(placeId);
      card.addEventListener('click', openCard);
      card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openCard();
        }
      });

      container.appendChild(card);
    });

    const count = document.getElementById('destinationResultCount');
    if (count) count.textContent = String(Object.keys(placesData).length);
  }

  function initDestinationSearchV2() {
    const form = document.getElementById('destinationSearchForm');
    const input = document.getElementById('destinationSearch');
    const panel = document.getElementById('destinationSearchSuggestions');
    const count = document.getElementById('destinationResultCount');
    const emptyState = document.getElementById('destinationEmptyState');
    const grid = document.getElementById('placeGridContainer');
    const hero = form?.closest('.des_page-intro');
    if (!form || !input || !panel || !grid) return;

    const storageKey = 'itourMercedesRecentDestinationSearches';
    const historyIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 12a8.5 8.5 0 1 0 2.1-5.6"></path><path d="M3.5 4.5v4h4"></path><path d="M12 7.5V12l3 2"></path></svg>';
    const locationIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>';
    const destinations = Object.entries(placesData).map(([placeId, place]) => ({
      label: placeLabelFromId(placeId),
      subtitle: place.name || 'Island destination · Mercedes',
      searchText: `${placeLabelFromId(placeId)} ${place.name || ''} ${(place.activities || []).join(' ')}`
    }));
    let options = [];
    let activeIndex = -1;
    let fitTimer = 0;

    const normalize = (value) => String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .trim();

    const readRecent = () => {
      try {
        const stored = JSON.parse(localStorage.getItem(storageKey) || '[]');
        return Array.isArray(stored)
          ? stored.filter((value) => typeof value === 'string' && value.trim()).slice(0, 2)
          : [];
      } catch (error) {
        return [];
      }
    };

    const saveRecent = (value) => {
      const cleanValue = String(value || '').trim();
      if (!cleanValue) return;
      const normalizedValue = normalize(cleanValue);
      const next = [
        cleanValue,
        ...readRecent().filter((item) => normalize(item) !== normalizedValue)
      ].slice(0, 2);
      try {
        localStorage.setItem(storageKey, JSON.stringify(next));
      } catch (error) {
        // Searching still works when local storage is unavailable.
      }
    };

    const applyFilter = () => {
      const query = normalize(input.value);
      const cards = Array.from(grid.querySelectorAll('.des_place-card'));
      let visibleCount = 0;
      cards.forEach((card) => {
        const visible = !query || normalize(card.dataset.search).includes(query);
        card.hidden = !visible;
        if (visible) visibleCount += 1;
      });
      if (count) count.textContent = String(visibleCount);
      if (emptyState) emptyState.hidden = visibleCount !== 0;
    };

    const closePanel = () => {
      panel.hidden = true;
      panel.style.removeProperty('max-height');
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      hero?.classList.remove('des-search-open');
      activeIndex = -1;
    };

    const fitPanelInViewport = (behavior = 'smooth') => {
      if (panel.hidden) return;
      // Reset the height from the previous query before measuring the new
      // result set, so clearing a short query expands the full menu again.
      panel.style.removeProperty('max-height');
      const formRect = form.getBoundingClientRect();
      const panelRect = panel.getBoundingClientRect();
      const fixedNavigation = Array.from(document.querySelectorAll('.head-nav-main-header, .head-subnav'));
      const safeTop = fixedNavigation.reduce((bottom, element) => {
        const rect = element.getBoundingClientRect();
        return getComputedStyle(element).position === 'fixed' ? Math.max(bottom, rect.bottom) : bottom;
      }, 0) + 12;
      const visibleHeight = window.visualViewport?.height || window.innerHeight;
      const visibleBottom = visibleHeight - 14;
      const panelGap = 9;
      const idealTop = Math.max(safeTop, visibleBottom - formRect.height - panelRect.height - panelGap);
      const availablePanelHeight = Math.max(180, visibleBottom - Math.max(safeTop, idealTop) - formRect.height - panelGap);
      panel.style.maxHeight = `${Math.min(410, availablePanelHeight)}px`;

      const fittedPanelHeight = panel.getBoundingClientRect().height;
      const fittedTop = Math.max(safeTop, visibleBottom - formRect.height - fittedPanelHeight - panelGap);
      const delta = formRect.top < safeTop
        ? formRect.top - safeTop
        : Math.max(0, formRect.top - fittedTop);
      if (Math.abs(delta) > 7) window.scrollBy({ top: delta, behavior });
    };

    const scheduleViewportFit = () => {
      window.clearTimeout(fitTimer);
      window.requestAnimationFrame(() => fitPanelInViewport('smooth'));
      fitTimer = window.setTimeout(() => fitPanelInViewport('smooth'), 280);
    };

    const refreshOptions = () => {
      options = Array.from(panel.querySelectorAll('.hero-suggestion-option'));
      options.forEach((option, index) => {
        option.id = `destinationSuggestion${index}`;
        option.classList.remove('is-active');
        option.setAttribute('aria-selected', 'false');
      });
      activeIndex = -1;
    };

    const openPanel = () => {
      panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      hero?.classList.add('des-search-open');
      scheduleViewportFit();
    };

    const selectOption = (option) => {
      input.value = option.dataset.value || '';
      applyFilter();
      closePanel();
      input.focus({ preventScroll: true });
    };

    const setActive = (index) => {
      if (!options.length) return;
      activeIndex = (index + options.length) % options.length;
      options.forEach((option, optionIndex) => {
        const active = optionIndex === activeIndex;
        option.classList.toggle('is-active', active);
        option.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      input.setAttribute('aria-activedescendant', options[activeIndex].id);
      options[activeIndex].scrollIntoView({ block: 'nearest' });
    };

    const createSection = (title, items, kind) => {
      if (!items.length) return null;
      const section = document.createElement('section');
      section.className = 'hero-suggestion-section';
      const heading = document.createElement('h3');
      heading.className = 'hero-suggestion-heading';
      heading.textContent = title;
      section.appendChild(heading);

      items.forEach((item) => {
        const value = typeof item === 'string' ? item : item.label;
        const subtitle = typeof item === 'string' ? 'Previously searched destination' : item.subtitle;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'hero-suggestion-option';
        button.setAttribute('role', 'option');
        button.setAttribute('aria-selected', 'false');
        button.dataset.value = value;
        button.innerHTML = `<span class="hero-suggestion-icon">${kind === 'recent' ? historyIcon : locationIcon}</span><span class="hero-suggestion-copy"><strong></strong><small></small></span>`;
        button.querySelector('strong').textContent = value;
        button.querySelector('small').textContent = subtitle;
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => selectOption(button));
        section.appendChild(button);
      });
      return section;
    };

    const renderSuggestions = () => {
      const query = normalize(input.value);
      panel.style.removeProperty('max-height');
      panel.scrollTop = 0;
      panel.replaceChildren();
      if (!query) {
        const recentSection = createSection('Your recent searches', readRecent(), 'recent');
        if (recentSection) panel.appendChild(recentSection);
        const suggestedSection = createSection('Suggested destinations', destinations.slice(0, 5), 'destination');
        if (suggestedSection) panel.appendChild(suggestedSection);
      } else {
        const prefixMatches = destinations.filter((destination) =>
          normalize(destination.label).split(/\s+/).some((word) => word.startsWith(query))
        );
        const matches = prefixMatches.length
          ? prefixMatches
          : destinations.filter((destination) => normalize(destination.searchText).includes(query));
        const suggestedSection = createSection('Suggested destinations', matches.slice(0, 6), 'destination');
        if (suggestedSection) {
          panel.appendChild(suggestedSection);
        } else {
          const empty = document.createElement('p');
          empty.className = 'hero-suggestion-empty';
          empty.textContent = `No destination suggestions for “${input.value.trim()}”. You can still search this term.`;
          panel.appendChild(empty);
        }
      }
      refreshOptions();
      openPanel();
    };

    input.addEventListener('focus', renderSuggestions);
    input.addEventListener('input', () => {
      applyFilter();
      renderSuggestions();
    });
    input.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (panel.hidden) renderSuggestions();
        setActive(activeIndex + 1);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (panel.hidden) renderSuggestions();
        setActive(activeIndex - 1);
      } else if (event.key === 'Enter' && activeIndex >= 0) {
        event.preventDefault();
        selectOption(options[activeIndex]);
      } else if (event.key === 'Escape') {
        closePanel();
      }
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      saveRecent(input.value);
      closePanel();
      applyFilter();
      grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    document.addEventListener('pointerdown', (event) => {
      if (!form.contains(event.target)) closePanel();
    });
    window.addEventListener('resize', () => {
      if (!panel.hidden) scheduleViewportFit();
    });
    window.visualViewport?.addEventListener('resize', () => {
      if (!panel.hidden) scheduleViewportFit();
    });
  }

  function showSectionV2(section) {
    const about = document.getElementById('sectionAbout');
    const resorts = document.getElementById('sectionResorts');
    const navAbout = document.getElementById('navAbout');
    const navResorts = document.getElementById('navResorts');
    if (!about || !resorts || !navAbout || !navResorts) return;

    about.classList.remove('show');
    resorts.classList.remove('show');
    navAbout.classList.remove('active');
    navResorts.classList.remove('active');

    if (section === 'resort') {
      resorts.classList.add('show');
      navResorts.classList.add('active');
      loadResortsForCurrentPlaceV2();
      return;
    }

    about.classList.add('show');
    navAbout.classList.add('active');
  }

  function loadResortsForCurrentPlaceV2() {
    const resortList = document.getElementById('resortList');
    if (!resortList || !currentPlaceIdLocal || !placesData[currentPlaceIdLocal]) return;

    const resorts = placesData[currentPlaceIdLocal].resorts || [];
    resortList.innerHTML = '';

    if (!resorts.length) {
      resortList.innerHTML = '<div class="des_info-section"><p>No resorts available for this destination yet.</p></div>';
      return;
    }

    resorts.forEach((resort, resortIndex) => {
      const card = document.createElement('article');
      card.className = 'des_resort-card';
      card.innerHTML = `
        <h3 class="resort-name-outside">${resort.name}</h3>
        <div class="des_info-section">${toParagraphs(resort.description || '')}</div>
        <div class="des_activities-section-outside">
          <h4>Things To Do</h4>
          <div class="des_activities-grid"></div>
        </div>
        <div class="des_image-gallery-outside"></div>
      `;

      const activitiesGrid = card.querySelector('.des_activities-grid');
      (resort.activities || []).forEach((activity) => {
        activitiesGrid.appendChild(createActivityItemV2(activity));
      });

      const gallery = card.querySelector('.des_image-gallery-outside');
      (resort.gallery || []).forEach((src, imageIndex) => {
        gallery.appendChild(createGalleryThumb(src, resort.name, () => openResortImageModalV2(resortIndex, imageIndex)));
      });

      resortList.appendChild(card);
    });
  }

  function openPlacePageV2(placeId, options = {}) {
    const place = placesData[placeId];
    if (!place) return;
    const placeLabel = placeLabelFromId(placeId);

    currentPlaceIdLocal = placeId;
    currentGalleryImagesLocal = Array.isArray(place.gallery) ? place.gallery.slice() : [];
    currentGalleryPageLocal = 0;
    currentMapZoomLocal = 14;

    const pageTitle = document.getElementById('des_pageTitle');
    const heroImg = document.getElementById('des_pageHeaderImg');
    const description = document.getElementById('des_pageDescription');
    const activitiesGrid = document.getElementById('des_activitiesGrid');
    const gallery = document.getElementById('des_imageGallery');
    const aboutFeatureImage = document.getElementById('des_aboutFeatureImage');
    const aboutSupportImage = document.getElementById('des_aboutSupportImage');
    const page = document.getElementById('des_placePage');
    const factType = document.getElementById('des_factType');
    const factActivities = document.getElementById('des_factActivities');
    const factGallery = document.getElementById('des_factGallery');
    const factCoordinates = document.getElementById('des_factCoordinates');
    const galleryCount = document.getElementById('des_galleryCount');

    if (pageTitle) pageTitle.textContent = place.title || placeLabel;
    if (heroImg) {
      heroImg.src = destinationHeroSourceV2(place);
      heroImg.alt = placeLabel;
    }
    if (description) description.innerHTML = toParagraphs(place.description);

    const featureSource = currentGalleryImagesLocal[0] || place.heroImage || place.image || '';
    const supportSource = currentGalleryImagesLocal[1] || featureSource;
    if (aboutFeatureImage) {
      aboutFeatureImage.src = featureSource;
      aboutFeatureImage.alt = `${placeLabel} featured view`;
    }
    if (aboutSupportImage) {
      aboutSupportImage.src = supportSource;
      aboutSupportImage.alt = `${placeLabel} closer view`;
    }

    if (activitiesGrid) {
      activitiesGrid.innerHTML = '';
      (place.activities || []).forEach((activity) => activitiesGrid.appendChild(createActivityItemV2(activity)));
    }

    if (gallery) renderEditorialGalleryV2();

    const activityCount = Array.isArray(place.activities) ? place.activities.length : 0;
    const photoCount = currentGalleryImagesLocal.length;
    if (factType) factType.textContent = place.type || (/island/i.test(placeId) ? 'Island escape' : 'Heritage landmark');
    if (factActivities) factActivities.textContent = `${activityCount} ${activityCount === 1 ? 'activity' : 'activities'}`;
    if (factGallery) factGallery.textContent = `${photoCount} ${photoCount === 1 ? 'photo' : 'photos'}`;
    if (factCoordinates) {
      factCoordinates.textContent = place.coords
        ? `${place.coords.lat.toFixed(3)}, ${place.coords.lng.toFixed(3)}`
        : 'Not available';
    }
    if (galleryCount && !gallery) galleryCount.textContent = `${photoCount} local ${photoCount === 1 ? 'photo' : 'photos'}`;

    showSectionV2('about');

    if (page) {
      page.classList.add('open');
      page.setAttribute('aria-hidden', 'false');
      page.scrollTop = 0;
      page.classList.remove('des-tabs-docked');
    }
    if (options.updateUrl !== false) updateDestinationUrlV2(placeId);
    requestAnimationFrame(() => {
      setTimeout(initDestinationPreviewMapV2, 60);
    });
    document.body.style.overflow = 'hidden';
  }

  function closePlacePageV2() {
    closeDestinationMapV2();
    const page = document.getElementById('des_placePage');
    if (page?.contains(document.activeElement)) {
      document.activeElement.blur();
    }
    if (page) {
      page.classList.remove('open');
      page.setAttribute('aria-hidden', 'true');
    }
    currentPlaceIdLocal = null;
    clearDestinationUrlV2();
    document.body.style.overflow = '';
  }

  function syncDestinationTabsDockedState() {
    const page = document.getElementById('des_placePage');
    const navbar = page?.querySelector('.des_place-navbar');
    if (!page || !navbar) return;

    const pageTop = page.getBoundingClientRect().top;
    const navbarTop = navbar.getBoundingClientRect().top;
    const isDocked = page.scrollTop > 0 && navbarTop <= pageTop + 1;
    page.classList.toggle('des-tabs-docked', isDocked);
  }

  function openImageModalV2(index) {
    if (!currentGalleryImagesLocal.length) return;
    currentImageIndexLocal = index;

    const modal = document.getElementById('des_imageModal');
    const image = document.getElementById('des_imageModalImg');
    const counter = document.getElementById('des_imageCounter');
    if (!modal || !image || !counter) return;

    image.src = currentGalleryImagesLocal[index];
    counter.textContent = `${index + 1}/${currentGalleryImagesLocal.length}`;
    modal.classList.add('visible');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeImageModalV2() {
    const modal = document.getElementById('des_imageModal');
    if (!modal) return;
    if (modal.contains(document.activeElement)) document.activeElement.blur();
    modal.classList.remove('visible');
    modal.setAttribute('aria-hidden', 'true');
  }

  function changeImageV2(direction) {
    if (!currentGalleryImagesLocal.length) return;
    currentImageIndexLocal += direction;
    if (currentImageIndexLocal < 0) currentImageIndexLocal = currentGalleryImagesLocal.length - 1;
    if (currentImageIndexLocal >= currentGalleryImagesLocal.length) currentImageIndexLocal = 0;
    document.getElementById('des_imageModalImg').src = currentGalleryImagesLocal[currentImageIndexLocal];
    document.getElementById('des_imageCounter').textContent = `${currentImageIndexLocal + 1}/${currentGalleryImagesLocal.length}`;
  }

  function openResortImageModalV2(resortIndex, imageIndex) {
    if (!currentPlaceIdLocal || !placesData[currentPlaceIdLocal]) return;
    const resort = placesData[currentPlaceIdLocal].resorts?.[resortIndex];
    if (!resort || !Array.isArray(resort.gallery) || !resort.gallery.length) return;

    currentResortImagesLocal = resort.gallery.slice();
    currentResortIndexLocal = imageIndex;

    const modal = document.getElementById('des_resortModal');
    const image = document.getElementById('des_resortModalImg');
    const counter = document.getElementById('des_resortCounter');
    if (!modal || !image || !counter) return;

    image.src = currentResortImagesLocal[imageIndex];
    counter.textContent = `${imageIndex + 1}/${currentResortImagesLocal.length}`;
    modal.classList.add('visible');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeResortImageModalV2() {
    const modal = document.getElementById('des_resortModal');
    if (!modal) return;
    if (modal.contains(document.activeElement)) document.activeElement.blur();
    modal.classList.remove('visible');
    modal.setAttribute('aria-hidden', 'true');
  }

  function changeResortImageV2(direction) {
    if (!currentResortImagesLocal.length) return;
    currentResortIndexLocal += direction;
    if (currentResortIndexLocal < 0) currentResortIndexLocal = currentResortImagesLocal.length - 1;
    if (currentResortIndexLocal >= currentResortImagesLocal.length) currentResortIndexLocal = 0;
    document.getElementById('des_resortModalImg').src = currentResortImagesLocal[currentResortIndexLocal];
    document.getElementById('des_resortCounter').textContent = `${currentResortIndexLocal + 1}/${currentResortImagesLocal.length}`;
  }

  function mapZoomChangeV2(delta) {
    currentMapZoomLocal = Math.max(3, Math.min(20, currentMapZoomLocal + delta));
    if (!currentPlaceIdLocal || !placesData[currentPlaceIdLocal]) return;
    const coords = placesData[currentPlaceIdLocal].coords;
    const map = document.getElementById('des_placeMap');
    if (coords && map) {
      map.src = buildMapSrcV2(coords.lat, coords.lng, currentMapZoomLocal);
    }
  }

  function openInGoogleMapsV2() {
    if (!currentPlaceIdLocal || !placesData[currentPlaceIdLocal]) return;
    const coords = placesData[currentPlaceIdLocal].coords;
    if (!coords) return;
    window.open(`https://www.google.com/maps/search/?api=1&query=${coords.lat},${coords.lng}`, '_blank');
  }

  document.addEventListener('keydown', (event) => {
    const placeModalVisible = document.getElementById('des_imageModal')?.classList.contains('visible');
    const resortModalVisible = document.getElementById('des_resortModal')?.classList.contains('visible');
    const destinationMapVisible = document.getElementById('destinationMapModal')?.classList.contains('open');
    const pageOpen = document.getElementById('des_placePage')?.classList.contains('open');

    if (event.key === 'Escape') {
      if (placeModalVisible) return closeImageModalV2();
      if (resortModalVisible) return closeResortImageModalV2();
      if (destinationMapVisible) return closeDestinationMapV2();
      if (pageOpen) return closePlacePageV2();
    }
    if (event.key === 'ArrowLeft') {
      if (placeModalVisible) changeImageV2(-1);
      if (resortModalVisible) changeResortImageV2(-1);
    }
    if (event.key === 'ArrowRight') {
      if (placeModalVisible) changeImageV2(1);
      if (resortModalVisible) changeResortImageV2(1);
    }
  });

  document.getElementById('des_imageModal')?.addEventListener('click', (event) => {
    if (event.target.id === 'des_imageModal') closeImageModalV2();
  });
  document.getElementById('des_resortModal')?.addEventListener('click', (event) => {
    if (event.target.id === 'des_resortModal') closeResortImageModalV2();
  });
  document.getElementById('destinationMapModal')?.addEventListener('click', (event) => {
    if (event.target.id === 'destinationMapModal') closeDestinationMapV2();
  });
  document.getElementById('des_placePage')?.addEventListener('scroll', syncDestinationTabsDockedState, { passive: true });
  window.addEventListener('resize', syncDestinationTabsDockedState);
  window.addEventListener('resize', syncResponsiveDestinationHeroV2);

  window.loadPlaces = loadPlacesV2;
  window.openPlacePage = openPlacePageV2;
  window.closePlacePage = closePlacePageV2;
  window.showSection = showSectionV2;
  window.loadResortsForCurrentPlace = loadResortsForCurrentPlaceV2;
  window.openImageModal = openImageModalV2;
  window.closeImageModal = closeImageModalV2;
  window.changeImage = changeImageV2;
  window.changeGallerySet = changeGallerySetV2;
  window.openResortImageModal = openResortImageModalV2;
  window.closeResortImageModal = closeResortImageModalV2;
  window.changeResortImage = changeResortImageV2;
  window.mapZoomChange = mapZoomChangeV2;
  window.openInGoogleMaps = openInGoogleMapsV2;
  window.openDestinationMap = openDestinationMapV2;
  window.closeDestinationMap = closeDestinationMapV2;
  window.focusDestinationOnMap = focusDestinationOnMapV2;
  window.selectDestinationFromMap = selectDestinationFromMapV2;

  window.onload = () => {
    loadPlacesV2();
    initDestinationSearchV2();
    const requestedDestination = new URLSearchParams(window.location.search).get('destination');
    if (requestedDestination && placesData[requestedDestination]) {
      openPlacePageV2(requestedDestination, { updateUrl: false });
    } else if (requestedDestination) {
      clearDestinationUrlV2();
    }
  };
})();
