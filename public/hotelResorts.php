<?php
chdir(__DIR__ . '/..');
session_start();
require_once 'php/db_connection.php';

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>iTour Mercedes</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="styles/hotelResorts.css" />
</head>

<body>

<!-- Header -->
<div id="header"></div>
<!-- Login/Signup Modal -->
<div id="loginModal"></div>

<!-- HERO -->
<div class="hero">
  <div class="hero-overlay"></div>
  <div class="hero-content">
    <h1>Find Your Perfect Stay</h1>
    <p>Hotels & Resorts across the islands</p>
  </div>
</div>

<!-- SEARCH -->
<div class="search-wrapper">
  <div class="search-box">
    <input type="text" placeholder="Search island or hotel">
    <input id="dateRange" placeholder="Check-in / Check-out" readonly>
    <input id="roomsInput" placeholder="Rooms" readonly onclick="toggleRooms()">
    <input id="guestsInput" placeholder="Guests" readonly onclick="toggleGuests()">
    <div id="daysText">0 days</div>
    <button>Search</button>
  </div>

  <!-- ROOMS POPUP -->
  <div id="roomsPopup" class="popup">
    <div class="counter">
      <span>Rooms</span>
      <div>
        <button onclick="change('rooms',-1)">-</button>
        <span id="roomsVal">1</span>
        <button onclick="change('rooms',1)">+</button>
      </div>
    </div>
  </div>

  <!-- GUESTS POPUP -->
  <div id="guestsPopup" class="popup">
    <div class="counter">
      <span>Adults</span>
      <div>
        <button onclick="change('adults',-1)">-</button>
        <span id="adultsVal">2</span>
        <button onclick="change('adults',1)">+</button>
      </div>
    </div>
    <div class="counter">
      <span>Children</span>
      <div>
        <button onclick="change('children',-1)">-</button>
        <span id="childrenVal">0</span>
        <button onclick="change('children',1)">+</button>
      </div>
    </div>
  </div>
</div>

<!-- RESULTS (placeholder structure for next step) -->
<div class="layout">
  <div class="filters">
    <h4>Filters</h4>
    <p>To be built next</p>
  </div>
  <div class="results" id="results"></div>
</div>

<section class="cards" id="cards"></section>

<?php include 'footer.php'; ?>

<script src="js/header.js"></script>
<script>
const data = [
  {name:"Paradise Resort",price:1200,img:"https://picsum.photos/200?1"},
  {name:"Blue Lagoon Hotel",price:1500,img:"https://picsum.photos/200?2"},
  {name:"Island Escape",price:1800,img:"https://picsum.photos/200?3"},
  {name:"Sunset Resort",price:2000,img:"https://picsum.photos/200?4"},
  {name:"Ocean Breeze",price:2200,img:"https://picsum.photos/200?5"},
  {name:"Palm Stay",price:1400,img:"https://picsum.photos/200?6"},
  {name:"Coral Bay",price:2100,img:"https://picsum.photos/200?7"},
  {name:"Lagoon View",price:1900,img:"https://picsum.photos/200?8"},
  {name:"Wave Resort",price:2500,img:"https://picsum.photos/200?9"},
  {name:"Seaside Hotel",price:1600,img:"https://picsum.photos/200?10"}
];

const container = document.getElementById('cards');

function render(){
  container.innerHTML = data.map(h => `
    <div class="card">
      <img src="${h.img}">
      <div class="card-content">
        <h4>${h.name}</h4>
        <div class="meta">⭐ 4.5 (120 reviews)</div>
        <div class="tags">
          <span class="tag">Pool</span>
          <span class="tag">Rooms</span>
        </div>
        <div class="price">
          <strong>₱${h.price}</strong>
          <button>Check</button>
        </div>
      </div>
    </div>
  `).join('');
}

let rooms=1, adults=2, children=0;

function toggleRooms(){
  document.getElementById('roomsPopup').classList.toggle('active');
}
function toggleGuests(){
  document.getElementById('guestsPopup').classList.toggle('active');
}

function change(type,val){
  if(type==='rooms') rooms=Math.max(1,rooms+val);
  if(type==='adults') adults=Math.max(1,adults+val);
  if(type==='children') children=Math.max(0,children+val);

  document.getElementById('roomsVal').innerText=rooms;
  document.getElementById('adultsVal').innerText=adults;
  document.getElementById('childrenVal').innerText=children;

  document.getElementById('roomsInput').value=rooms+" Room(s)";
  document.getElementById('guestsInput').value=`${adults} Adults, ${children} Children`;
}

// DATE RANGE PICKER
flatpickr("#dateRange", {
  mode: "range",
  minDate: "today",
  dateFormat: "Y-m-d",
  onChange: function(selectedDates){
    if(selectedDates.length===2){
      let diff = Math.round((selectedDates[1]-selectedDates[0])/(1000*60*60*24));
      document.getElementById('daysText').innerText = diff + " days";
    }
  }
});

// dummy results
const data=[...Array(10)].map((_,i)=>({
  name:"Resort "+(i+1),
  price:1000+i*200,
  img:"https://picsum.photos/300?"+i
}));

const results=document.getElementById('results');
results.innerHTML=data.map(h=>`
<div class="card">
<img src="${h.img}">
<div class="card-body">
<h4>${h.name}</h4>
<div class="tags"><span>Pool</span><span>Rooms</span></div>
<div class="price">
<strong>₱${h.price}</strong>
<button>Check</button>
</div>
</div>
</div>
`).join('');

render();
document.addEventListener("DOMContentLoaded", () => {
  // --- Load header dynamically ---
  fetch("php/header.php")
    .then(res => res.text())
    .then(html => {
      document.getElementById("header").innerHTML = html;
      if (typeof initHeader === "function") initHeader();

      // Scroll to Top Button
      const scrollToTopBtn = document.getElementById("scroll-to-top-btn");
      window.addEventListener("scroll", () => {
        if (scrollToTopBtn) scrollToTopBtn.style.display = window.scrollY > 200 ? "flex" : "none";
      });
      scrollToTopBtn?.addEventListener("click", () => {
        window.scrollTo({ top: 0, behavior: "smooth" });
      });

      // Mobile nav toggle
      const toggle = document.querySelector('#header .menu-toggle');
      const navLinks = document.querySelectorAll("#header nav a");
      toggle?.addEventListener('click', () => {
        navLinks.classList.toggle('show');
      });

      // Homepage nav scroll effect
      if (document.body.classList.contains("homepage")) {
        const nav = document.querySelector("#header nav");
        function checkNavScroll() {
          if (window.scrollY > 50) nav.classList.add("scrolled");
          else nav.classList.remove("scrolled");
        }
        window.addEventListener("scroll", checkNavScroll);
        window.scrollTo(0, 0);
        checkNavScroll();
      }

      // --- Load login/signup modal dynamically ---
      fetch("logsign-modal.html")
        .then(res => res.text())
        .then(html => {
          const modalContainer = document.getElementById("loginModal");
          if (!modalContainer) return console.error("loginModal container not found");
          modalContainer.innerHTML = html;

          // Load SweetAlert2 dynamically
          const swalScript = document.createElement("script");
          swalScript.src = "https://cdn.jsdelivr.net/npm/sweetalert2@11";
          swalScript.onload = () => {
            // Load logsign.js after SweetAlert2
            const logsignScript = document.createElement("script");
            logsignScript.src = "logsign.js";
            logsignScript.onload = () => {
              if (typeof initLogSignEvents === "function") {
                initLogSignEvents();
              } else {
                console.error("initLogSignEvents not found in logsign.js");
              }
            };
            document.body.appendChild(logsignScript);
          };
          document.body.appendChild(swalScript);
        })
        .catch(err => console.error("Modal load error:", err));
    })
    .catch(err => console.error("Header load error:", err));
});

</script>

</body>
</html>

