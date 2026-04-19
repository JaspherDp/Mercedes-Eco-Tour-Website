<?php
chdir(__DIR__ . '/..');
session_start();
require 'php/db_connection.php';

$user = null;
if (isset($_SESSION['tourist_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM tourist WHERE tourist_id = ?");
    $stmt->execute([$_SESSION['tourist_id']]);
    $tourist = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tourist) {
        // Try to split full_name into first + last
        $nameParts = explode(" ", $tourist['full_name'], 2);
        $firstName = $nameParts[0] ?? '';
        $lastName  = $nameParts[1] ?? '';

        $user = [
            "id"       => $tourist['tourist_id'],
            "first_name" => $firstName,
            "last_name"  => $lastName,
            "email"    => $tourist['email'],
            "phone"    => $tourist['phone'] ?? "",   // optional column
            "profile_picture" => $tourist['profile_picture'] ?? null
        ];
    }
}

// Fetch operators and their packages
$operatorsStmt = $pdo->query("
    SELECT o.operator_id, o.fullname, p.package_id, p.package_title, p.package_image
    FROM operators o
    LEFT JOIN tour_packages p ON o.operator_id = p.operator_id
    WHERE o.status = 'active'
    ORDER BY o.operator_id, p.package_id
");

$operators = [];
while($row = $operatorsStmt->fetch(PDO::FETCH_ASSOC)) {
    $opId = $row['operator_id'];
    if(!isset($operators[$opId])) {
        $operators[$opId] = [
            'fullname' => $row['fullname'],
            'packages' => []
        ];
    }
    if($row['package_id']) {
    $operators[$opId]['packages'][] = [
        'id'    => $row['package_id'],
        'title' => $row['package_title'],
        'image' => $row['package_image']
    ];
}
}

$stmt = $pdo->query("SELECT * FROM faqs ORDER BY created_at DESC");
$faqs = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta content="width=device-width, initial-scale=1" name="viewport"/>
  <title>iTour Mercedes</title>
  <link rel="icon" type="image/png" href="img/newlogo.png">
  <link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="styles/tours.css">
  <link rel="stylesheet" href="styles/homepage.css">
</head>

<body>
    <!-- Placeholder for Header (includes nav inside header.html) -->
    <div id="header"></div>
 
  <main class="tmodal-main">

    <!-- Second Navbar -->
    <nav class="tmodal-second-navbar">
      <ul>
        <li><a href="tboats.html" data-page="boats">BOATS</a></li>
        <li><a href="ttourguides.html" data-page="tourguides">TOUR GUIDES</a></li>
        <li><a href="ttours.html" data-page="sectours">TOURS</a></li>
      </ul>
      <div class="tmodal-sec-nav-btn">
        <a id="actionBtn" class="btn" href="#">INQUIRE NOW</a>
      </div>
    </nav>

    <!-- Dynamic Content -->
    <section id="content-area">

    <!-- 🚤 BOATS SECTION -->
    <div id="boats-section" class="content-section">
        <h2>Available Boats</h2>
        <p>List of boats will go here.</p>
        <!-- add your boat cards here -->
    </div>

    <!-- 🧑‍✈️ TOUR GUIDES SECTION -->
    <div id="tourguides-section" class="content-section" style="display:none;">
        <h2>Our Tour Guides</h2>
        <p>List of tour guides will go here.</p>
        <!-- add your guide cards here -->
    </div>

    <!-- 🎒 TOUR PACKAGES SECTION -->
<div id="tours-section" class="content-section">
    <div class="tour-layout-container">

        <!-- ✅ Left: FAQ -->       
        <div class="faq-panel">
            <div class="scroll-inner">
                <h3 class="section-title">Frequently Asked Questions</h3>
                <ul class="faq-list">
                    <?php foreach ($faqs as $faq): ?>
                        <li>
                            <strong><?= htmlspecialchars($faq['question']) ?></strong><br>
                            <?= nl2br(htmlspecialchars($faq['answer'])) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- ✅ Right: Operators -->
        <div class="operators-panel">
            <div class="scroll-inner">
                <?php foreach($operators as $operator): ?>
                    <div class="operator-block">
                        <div class="operator-header">
                            <span class="line"></span>
                            <h4><?= htmlspecialchars($operator['fullname']) ?></h4>
                        </div>
                        <div class="package-grid">
                            <?php foreach($operator['packages'] as $package): ?>
                              <div class="package-card" data-package-id="<?= $package['id'] ?>">
                                  <img src="<?php 
                                      if (!empty($package['image'])) {
                                          echo (strpos($package['image'], 'img/') === 0)
                                              ? htmlspecialchars($package['image'])
                                              : 'uploads/packages/' . htmlspecialchars($package['image']);
                                      } else {
                                          echo 'img/sampleimage.png';
                                      }
                                  ?>" alt="<?= htmlspecialchars($package['title']) ?>">
                                  <p><?= htmlspecialchars($package['title']) ?></p>
                              </div>
                          <?php endforeach; ?>

                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>



</section>

  </main>

  <!-- Placeholder for Login/Signup Modal -->
  <div id="loginModal"></div>

  <!-- ================== MODALS ================== -->
  <!-- Inquire Modal -->
  <!-- Inquire Modal -->
<div id="inquireModal" class="tmodal-modal">
  <div class="tmodal-modal-content">
    <span class="tmodal-close" onclick="closeModal('inquireModal')">&times;</span>
    <h2 class="tmodal-modal-title">Inquire Now</h2>
    <form id="inquireForm">
      <div class="tmodal-input-group">
        <input type="date" id="inqDate" name="inqDate" required placeholder=" ">
        <label for="inqDate">Date</label>
      </div>
      <p class="tmodal-note">Note: We will use your account data for this inquiry.</p>

      <div class="tmodal-input-group">
        <input type="tel" id="inqPhone" name="inqPhone" required placeholder=" ">
        <label for="inqPhone">Contact Number</label>
      </div>

      <div class="tmodal-input-group">
          <select id="inqPackage" name="package_id" required>
              <option value="" disabled selected>Choose Package</option>

              <?php
              // Fetch all packages with operator_id
              $pkg = $pdo->query("
                  SELECT package_id, package_title, operator_id
                  FROM tour_packages
                  ORDER BY package_title ASC
              ");

              while ($p = $pkg->fetch(PDO::FETCH_ASSOC)) {
                  echo '<option value="'.$p['package_id'].'" data-operator="'.$p['operator_id'].'">'.
                          htmlspecialchars($p['package_title']).
                      '</option>';
              }
              ?>
          </select>
      </div>


      <div class="tmodal-input-group">
        <input type="number" id="inqAdults" name="num_adults" min="0" required placeholder=" ">
        <label for="inqAdults">Number of Teens/Adults (13+)</label>
      </div>

      <div class="tmodal-input-group">
        <input type="number" id="inqChildren" name="num_children" min="0" required placeholder=" ">
        <label for="inqChildren">Number of Children (1–12)</label>
      </div>

      <div class="tmodal-checkbox">
        <input type="checkbox" id="inqPrivacy" required>
        <label for="inqPrivacy">
          Submitting this form means you accept our 
          <a href="privacypolicy.php" target="_blank">Privacy Policy</a>.
        </label>
      </div>

      <button type="submit" class="tmodal-modal-btn">Submit</button>
    </form>
  </div>
</div>

<!-- Boat Modal -->
<div id="boatModal" class="tmodal-modal">
  <div class="tmodal-modal-content">
    <span class="tmodal-close" onclick="closeModal('boatModal')">&times;</span>
    <h2 class="tmodal-modal-title">Boat Booking</h2>
    <form id="boatForm">
      <input type="hidden" name="booking_type" value="boat">
      <div class="tmodal-input-group">
        <input type="date" id="boatDate" name="booking_date" required placeholder=" ">
        <label for="boatDate">Date</label>
      </div>
      <p class="tmodal-note">Note: We will use your account data for this booking.</p>

      <div class="tmodal-input-group">
        <input type="tel" id="boatPhone" name="phone_number" required placeholder=" ">
        <label for="boatPhone">Phone Number</label>
      </div>

      <div class="tmodal-input-group">
        <select id="boatLocation" name="location" required>
          <option value="" disabled selected>Choose Location</option>
          <option value="Location1">Location 1</option>
          <option value="Location2">Location 2</option>
          <option value="Location3">Location 3</option>
        </select>
      </div>

      <div class="tmodal-input-group">
        <input type="number" id="boatAdults" name="num_adults" min="0" required placeholder=" ">
        <label for="boatAdults">Number of Teens/Adults (13+)</label>
      </div>

      <div class="tmodal-input-group">
        <input type="number" id="boatChildren" name="num_children" min="0" required placeholder=" ">
        <label for="boatChildren">Number of Children (1–12)</label>
      </div>

      <div class="tmodal-checkbox">
        <input type="checkbox" id="boatPrivacy" required>
        <label for="boatPrivacy">
          Submitting this form means you accept our 
          <a href="privacypolicy.php" target="_blank">Privacy Policy</a>.
        </label>
      </div>

      <button type="submit" class="tmodal-modal-btn">Book Now</button>
    </form>
  </div>
</div>

<!-- Tour Guide Modal -->
<div id="guideModal" class="tmodal-modal">
  <div class="tmodal-modal-content">
    <span class="tmodal-close" onclick="closeModal('guideModal')">&times;</span>
    <h2 class="tmodal-modal-title">Tour Guide Booking</h2>
    <form id="guideForm">
      <input type="hidden" name="booking_type" value="tourguide">
      <div class="tmodal-input-group">
        <input type="date" id="guideDate" name="booking_date" required placeholder=" ">
        <label for="guideDate">Date</label>
      </div>
      <p class="tmodal-note">Note: We will use your account data for this booking.</p>

      <div class="tmodal-input-group">
        <input type="tel" id="guidePhone" name="phone_number" required placeholder=" ">
        <label for="guidePhone">Phone Number</label>
      </div>

      <div class="tmodal-input-group">
        <select id="guideLocation" name="location" required>
          <option value="" disabled selected>Choose Location</option>
          <option value="Location1">Location 1</option>
          <option value="Location2">Location 2</option>
          <option value="Location3">Location 3</option>
        </select>
      </div>

      <div class="tmodal-input-group">
        <input type="number" id="guideAdults" name="num_adults" min="0" required placeholder=" ">
        <label for="guideAdults">Number of Teens/Adults (13+)</label>
      </div>

      <div class="tmodal-input-group">
        <input type="number" id="guideChildren" name="num_children" min="0" required placeholder=" ">
        <label for="guideChildren">Number of Children (1–12)</label>
      </div>

      <div class="tmodal-checkbox">
        <input type="checkbox" id="guidePrivacy" required>
        <label for="guidePrivacy">
          Submitting this form means you accept our 
          <a href="privacypolicy.php" target="_blank">Privacy Policy</a>.
        </label>
      </div>

      <button type="submit" class="tmodal-modal-btn">Book Now</button>
    </form>
  </div>
</div>




  <!-- Footer -->
  <footer class="tmodal-footer">
    <div class="tmodal-footer-container">
      <!-- Left -->
      <div class="tmodal-footer-left">
        <div class="tmodal-footer-logos">
          <img src="img/mercedeslogo.png" alt="Municipality Logo" width="100" height="100" />
          <img src="img/TourismLogo.png" alt="Tourism Logo" width="102" height="100" />
        </div>
        <p>Catering to travel agencies, tour operators, or vacation planning services</p>
      </div>

      <!-- Middle -->
      <div class="tmodal-footer-middle">
        <h3>Quick Links</h3>
        <ul>
          <li><a href="homepage.html">Home</a></li>
          <li><a href="destination.html">Destination</a></li>
          <li><a href="packages.html">Packages</a></li>
          <li><a href="about.html">About</a></li>
        </ul>
      </div>

      <!-- Right -->
      <div class="tmodal-footer-right">
        <h3>Follow Us</h3>

        <div class="tmodal-follow-us">
          <img src="img/facebookicon.png" alt="Facebook Icon" width="20" height="20" />
          <a href="https://www.facebook.com/mercedes.tourism.2024" target="_blank">
            Municipal Tourism Office - LGU Mercedes
          </a>
        </div>

        <div class="tmodal-follow-us">
          <img src="img/phoneicon.png" alt="Phone Icon" width="20" height="20" />
          <a href="tel:+639123456789">+63 912 345 6789</a>
        </div>

        <div class="tmodal-follow-us">
          <img src="img/emailicon.png" alt="Email Icon" width="20" height="20" />
          <a href="mailto:baliksiglamercedes@gmail.com">baliksiglamercedes@gmail.com</a>
        </div>

        <div class="tmodal-follow-us">
          <img src="img/locationicon.png" alt="Location Icon" width="20" height="20" />
          <a href="https://maps.app.goo.gl/cfcCT3yHPvnsGvvx9" target="_blank">
            Municipal Hall, Mercedes, Camarines Norte
          </a>
        </div>

        <div class="tmodal-scroll-top">
          <button id="scroll-to-top-btn" aria-label="Scroll to top">
            <img src="img/arrowup.png" alt="Scroll to top" width="16" height="16" />
          </button>
        </div>
      </div>
    </div>
    
     <!-- Copyright Bar -->
    <div class="copyright-bar">
      <p>© 2025 Mercedes Tourism Office. All rights reserved.</p>
    </div>
  </footer>

  <script src="js/header.js"></script>
  <script>
  window.currentUser = <?php echo $user ? json_encode($user) : 'null'; ?>;
  document.addEventListener("DOMContentLoaded", () => {
  // Load header first, then modal
  fetch("php/header.php")
    .then(res => res.text())
    .then(html => {
      document.getElementById("header").innerHTML = html;
      initHeader();

      // Highlight active nav link
      const current = location.pathname.split("/").pop();
      document.querySelectorAll("#header nav ul li a").forEach(link => {
        link.classList.remove("active");
        if (link.getAttribute("href") === current) {
          link.classList.add("active");
        }
      });

    // Mobile nav toggle
    const toggle = document.querySelector('#header .menu-toggle');
    const navLinks = document.querySelector('#header nav ul');
    toggle?.addEventListener('click', () => {
      navLinks.classList.toggle('show');
    });

    // Load modal after header exists
    return fetch("logsign-modal.html");
  })
  .then(res => res.text())
  .then(html => {
    document.getElementById("loginModal").innerHTML = html;

    // Load logsign.js only after modal is added
    const script = document.createElement("script");
    script.src = "logsign.js";
    script.onload = () => {
      if (typeof initLogSignEvents === "function") {
        initLogSignEvents();
      } else {
        console.error("initLogSignEvents not found in logsign.js");
      }
    };
    document.body.appendChild(script);
  })
  .catch(err => console.error("Error loading header or modal:", err));


    // ===== Scroll to Top =====
    const scrollToTopBtn = document.getElementById("scroll-to-top-btn");
    window.addEventListener("scroll", () => {
        scrollToTopBtn.style.display = window.scrollY > 200 ? "flex" : "none";
    });
    scrollToTopBtn?.addEventListener("click", () => {
        window.scrollTo({ top: 0, behavior: "smooth" });
    });

    // ===== Second Navbar =====
    const buttonTextMap = {
        boats: "BOOK NOW",
        tourguides: "BOOK NOW",
        sectours: "INQUIRE NOW"
    };
    const contentMap = {
        boats: "<p>Boats content goes here.</p>",
        tourguides: "<p>Tour Guides content goes here.</p>",
        sectours: "<p>Tour content goes here.</p>"
    };

    // ===== Second Navbar =====
function loadSecondNavbarContent(page) {
    const btn = document.getElementById("actionBtn");

    // Hide all sections first
    document.querySelectorAll(".content-section").forEach(sec => sec.style.display = "none");

    // Show the selected section
    if (page === "boats") {
        document.getElementById("boats-section").style.display = "block";
        btn.textContent = "BOOK NOW";
    } else if (page === "tourguides") {
        document.getElementById("tourguides-section").style.display = "block";
        btn.textContent = "BOOK NOW";
    } else if (page === "sectours") {
        document.getElementById("tours-section").style.display = "block";
        btn.textContent = "INQUIRE NOW";
    }

    // Update active styling
    document.querySelectorAll(".tmodal-second-navbar li a").forEach(a => {
        a.classList.toggle("active", a.getAttribute("data-page") === page);
    });
}

// Handle navbar clicks
document.querySelector(".tmodal-second-navbar")?.addEventListener("click", e => {
    const link = e.target.closest("a[data-page]");
    if (!link) return;
    e.preventDefault();
    loadSecondNavbarContent(link.getAttribute("data-page"));
});

// Default view on load
loadSecondNavbarContent("sectours");


    // Prevent past dates
    const today = new Date().toISOString().split("T")[0];
    document.querySelectorAll('input[type="date"]').forEach(el => el.setAttribute("min", today));

    // ===== Modals =====
    const actionBtn = document.getElementById("actionBtn");
    const inquireModal = document.getElementById("inquireModal");
    const boatModal = document.getElementById("boatModal");
    const guideModal = document.getElementById("guideModal");

    actionBtn?.addEventListener("click", e => {
        e.preventDefault();

        if (!window.currentUser) {
            alert("Please login first to continue.");
            return;
        }

        const activeLink = document.querySelector(".tmodal-second-navbar li a.active");
        const page = activeLink?.getAttribute("data-page");
        if (page === "sectours") inquireModal?.style.setProperty("display", "flex");
        else if (page === "boats") boatModal?.style.setProperty("display", "flex");
        else if (page === "tourguides") guideModal?.style.setProperty("display", "flex");
    });

    document.addEventListener("click", e => {
        if (e.target.classList.contains("tmodal-close")) {
            inquireModal?.style.setProperty("display", "none");
            boatModal?.style.setProperty("display", "none");
            guideModal?.style.setProperty("display", "none");
        }
        if (e.target.classList.contains("tmodal-modal")) e.target.style.display = "none";
    });

    document.getElementById("inqPackage").addEventListener("change", function() {
    const selectedOption = this.options[this.selectedIndex];
    const operatorId = selectedOption.getAttribute("data-operator");

    // Store operator_id in a hidden field
    let hidden = document.getElementById("inqOperatorId");
    if (!hidden) {
        hidden = document.createElement("input");
        hidden.type = "hidden";
        hidden.name = "operator_id";
        hidden.id = "inqOperatorId";
        document.getElementById("inquireForm").appendChild(hidden);
    }
    hidden.value = operatorId;
});


    // ===== AJAX Form Submission =====
    const forms = [
        {id: "boatForm", modal: boatModal, url: "php/submit_booking.php"},
        {id: "guideForm", modal: guideModal, url: "php/submit_booking.php"},
        {id: "inquireForm", modal: inquireModal, url: "php/submit_inquiry.php"}
    ];

    forms.forEach(({id, modal, url}) => {
        const form = document.getElementById(id);
        if (!form) return;

        form.addEventListener("submit", function(e) {
            e.preventDefault();
            const formData = new FormData(form);

            fetch(url, { method: "POST", body: formData })
            .then(res => res.text())
            .then(data => {
                alert(data);
                form.reset();
                modal.style.display = "none";
            })
            .catch(err => {
                alert("Error: " + err);
            });
        });
    });

    // ===== Checkbox Autofill =====
    document.getElementById("inqUseUser")?.addEventListener("change", function () {
        if (this.checked && window.currentUser) {
            document.getElementById("inqFname").value = window.currentUser.first_name || "";
            document.getElementById("inqLname").value = window.currentUser.last_name || "";
            document.getElementById("inqEmail").value = window.currentUser.email || "";
            document.getElementById("inqPhone").value = window.currentUser.phone || "";
        } else {
            document.getElementById("inqFname").value = "";
            document.getElementById("inqLname").value = "";
            document.getElementById("inqEmail").value = "";
            document.getElementById("inqPhone").value = "";
        }
    });

    document.getElementById("boatUseUser")?.addEventListener("change", function () {
        if (this.checked && window.currentUser) {
            document.getElementById("boatFname").value = window.currentUser.first_name || "";
            document.getElementById("boatLname").value = window.currentUser.last_name || "";
            document.getElementById("boatEmail").value = window.currentUser.email || "";
            document.getElementById("boatPhone").value = window.currentUser.phone || "";
        } else {
            document.getElementById("boatFname").value = "";
            document.getElementById("boatLname").value = "";
            document.getElementById("boatEmail").value = "";
            document.getElementById("boatPhone").value = "";
        }
    });

    document.getElementById("guideUseUser")?.addEventListener("change", function () {
        if (this.checked && window.currentUser) {
            document.getElementById("guideFname").value = window.currentUser.first_name || "";
            document.getElementById("guideLname").value = window.currentUser.last_name || "";
            document.getElementById("guideEmail").value = window.currentUser.email || "";
            document.getElementById("guidePhone").value = window.currentUser.phone || "";
        } else {
            document.getElementById("guideFname").value = "";
            document.getElementById("guideLname").value = "";
            document.getElementById("guideEmail").value = "";
            document.getElementById("guidePhone").value = "";
        }
    });
});

document.querySelectorAll('.faq-panel, .operators-panel').forEach(panel => {
    let timeout;
    panel.addEventListener('scroll', () => {
        panel.classList.add('scrolling');
        clearTimeout(timeout);
        timeout = setTimeout(() => panel.classList.remove('scrolling'), 1000);
    });
});

</script>

</body>
</html>
