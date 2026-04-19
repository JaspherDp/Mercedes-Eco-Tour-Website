// header.js
function headNavDismissQuick(evt) {
  if (evt) evt.stopPropagation();
  const quick = document.getElementById("headNavNotifQuick");

  if (quick) quick.style.display = "none";
}

function readNotifStore() {
  try {
    const raw = localStorage.getItem("headNavBookingNotifList");
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function writeNotifStore(items) {
  localStorage.setItem("headNavBookingNotifList", JSON.stringify(items));
}

function headNavMarkAllRead() {
  const items = readNotifStore();
  if (!items.length) return;
  const hasUnread = items.some((it) => !!it.unread);
  if (!hasUnread) return;
  const next = items.map((it) => ({ ...it, unread: false, seenAt: it.seenAt || Date.now() }));
  writeNotifStore(next);
}

function headNavToggleNotif(evt) {
  if (evt) {
    evt.preventDefault();
    evt.stopPropagation();
  }
  const notifBtn = document.getElementById("headNavNotifBtn");
  const notifPanel = document.getElementById("headNavNotifPanel");
  const notifBadge = document.getElementById("headNavNotifBadge");
  const notifQuick = document.getElementById("headNavNotifQuick");
  const notifList = document.getElementById("headNavNotifList");
  const notifOldList = document.getElementById("headNavNotifOldList");
  const notifNewTitle = document.getElementById("headNavNotifNewTitle");
  const notifOldTitle = document.getElementById("headNavNotifOldTitle");
  const notifEmpty = document.getElementById("headNavNotifEmpty");
  if (!notifBtn || !notifPanel) return;

  const isOpen = notifPanel.classList.toggle("show");
  notifBtn.setAttribute("aria-expanded", isOpen ? "true" : "false");
  if (isOpen) {
    if (notifBadge) notifBadge.remove();
    if (notifQuick) notifQuick.style.display = "none";
  } else {
    // First open shows items as New; they become Old after closing once.
    headNavMarkAllRead();
    if (typeof window.initHeader === "function") window.initHeader();
  }
}

window.headNavDismissQuick = headNavDismissQuick;
window.headNavToggleNotif = headNavToggleNotif;

function initHeader() {
  const attachListeners = () => {
    // Profile dropdown
    const profileBtn = document.getElementById("profileBtn");
    const profileDropdown = document.getElementById("profileDropdown");
    const notifBtn = document.getElementById("headNavNotifBtn");
    const notifPanel = document.getElementById("headNavNotifPanel");
    const notifBadge = document.getElementById("headNavNotifBadge");
    const notifQuick = document.getElementById("headNavNotifQuick");
    const notifQuickClose = document.getElementById("headNavNotifQuickClose");
    const notifWrap = document.querySelector(".head-nav-notif-wrap");
    const notifList = document.getElementById("headNavNotifList");
    const notifOldList = document.getElementById("headNavNotifOldList");
    const notifNewTitle = document.getElementById("headNavNotifNewTitle");
    const notifOldTitle = document.getElementById("headNavNotifOldTitle");
    const notifEmpty = document.getElementById("headNavNotifEmpty");
    const notifKey = notifWrap?.dataset?.notifKey || "";
    const notifActive = notifWrap?.dataset?.notifActive === "1";
    const notifTitle = notifWrap?.dataset?.notifTitle || "Booking Submitted";
    const notifMessage = notifWrap?.dataset?.notifMessage || "";

    if (profileBtn && profileDropdown) {
      profileBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        profileDropdown.classList.toggle("show");
      });

      // Close dropdown when clicking outside
      document.addEventListener("click", (e) => {
        if (!profileDropdown.contains(e.target) && e.target !== profileBtn) {
          profileDropdown.classList.remove("show");
        }
        if (notifPanel && notifBtn) {
          const notifWrap = document.querySelector(".head-nav-notif-wrap");
          if (notifWrap && !notifWrap.contains(e.target)) {
            const wasOpen = notifPanel.classList.contains("show");
            notifPanel.classList.remove("show");
            notifBtn.setAttribute("aria-expanded", "false");
            if (wasOpen) {
              headNavMarkAllRead();
              if (typeof window.initHeader === "function") window.initHeader();
            }
          }
        }
      });
    }

    if (notifBtn && notifPanel && notifBtn.dataset.notifBound !== "1") {
      notifBtn.dataset.notifBound = "1";
      notifBtn.addEventListener("click", headNavToggleNotif);
    }

    if (notifPanel && notifPanel.dataset.notifPanelBound !== "1") {
      notifPanel.dataset.notifPanelBound = "1";
      notifPanel.addEventListener("click", (e) => e.stopPropagation());
    }

    if (notifQuick && notifQuick.dataset.notifQuickBound !== "1") {
      notifQuick.dataset.notifQuickBound = "1";
      notifQuick.addEventListener("click", (e) => e.stopPropagation());
    }

    if (notifQuickClose && notifQuick && notifQuickClose.dataset.notifCloseBound !== "1") {
      notifQuickClose.dataset.notifCloseBound = "1";
      notifQuickClose.addEventListener("click", headNavDismissQuick);
    }

    if (notifQuick && notifQuick.dataset.notifTimerSet !== "1") {
      notifQuick.dataset.notifTimerSet = "1";
      setTimeout(() => {
        headNavDismissQuick();
      }, 15000);
    }

    // Keep all booking success notifications in the panel (persistent list).
    let items = readNotifStore();
    if (notifMessage && notifActive) {
      const exists = items.some((it) => String(it.key) === String(notifKey));
      if (!exists) {
        items.unshift({
          key: notifKey || `booking-success-${Date.now()}`,
          title: notifTitle,
          message: notifMessage,
          unread: true,
          createdAt: Date.now()
        });
        writeNotifStore(items);
      }
    }

    if (notifMessage && notifActive && window.Swal) {
      const swalSeenKey = `headNavBookingSwalSeen:${notifKey || notifMessage}`;
      if (sessionStorage.getItem(swalSeenKey) !== "1") {
        sessionStorage.setItem(swalSeenKey, "1");
        Swal.fire({
          icon: "success",
          title: notifTitle || "Booking Submitted",
          text: notifMessage,
          confirmButtonColor: "#2B7066"
        });
      }
    }

    items = readNotifStore();
    if (notifList) {
      notifList.innerHTML = "";
    }
    if (notifOldList) {
      notifOldList.innerHTML = "";
    }

    const newItems = items.filter((it) => !!it.unread);
    const oldItems = items.filter((it) => !it.unread);

    const appendItem = (container, it, isNew) => {
      if (!container) return;
      const row = document.createElement("div");
      row.className = `head-nav-notif-item${isNew ? " new" : ""}`;
      row.innerHTML = `<strong></strong><span></span>`;
      row.querySelector("strong").textContent = it.title || "Booking Submitted";
      row.querySelector("span").textContent = it.message || "";
      container.appendChild(row);
    };

    newItems.forEach((it) => appendItem(notifList, it, true));
    oldItems.forEach((it) => appendItem(notifOldList, it, false));

    if (notifNewTitle) notifNewTitle.style.display = newItems.length > 0 ? "" : "none";
    if (notifOldTitle) notifOldTitle.style.display = oldItems.length > 0 ? "" : "none";
    if (notifList) notifList.style.display = newItems.length > 0 ? "" : "none";
    if (notifOldList) notifOldList.style.display = oldItems.length > 0 ? "" : "none";

    if (notifEmpty) {
      notifEmpty.style.display = items.length > 0 ? "none" : "";
    }

    const unreadCount = newItems.length;
    if (unreadCount > 0 && notifBtn) {
      const existingBadge = document.getElementById("headNavNotifBadge");
      if (existingBadge) {
        existingBadge.textContent = String(unreadCount);
      } else {
        const badge = document.createElement("span");
        badge.className = "head-nav-notif-badge";
        badge.id = "headNavNotifBadge";
        badge.textContent = String(unreadCount);
        notifBtn.appendChild(badge);
      }
    } else if (notifBadge) {
      notifBadge.remove();
    }

    if (!notifActive && notifQuick) {
      notifQuick.style.display = "none";
    }

    // Login / Signup modal
    const loginBtn = document.getElementById("loginBtn");
    const signupBtn = document.getElementById("signupBtn");
    const loginModal = document.getElementById("loginModal");
    const signupModal = document.getElementById("signupModal");

    loginBtn?.addEventListener("click", (e) => {
      e.preventDefault();
      loginModal?.classList.add("show");
    });

    signupBtn?.addEventListener("click", (e) => {
      e.preventDefault();
      signupModal?.classList.add("show");
    });

    // Logout
    const logoutBtn = document.getElementById("logoutBtn");
    logoutBtn?.addEventListener("click", async (e) => {
      e.preventDefault();

      Swal.fire({
        title: 'Are you sure you want to log out?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, logout',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#2B7066',
        cancelButtonColor: '#D33'
      }).then(async (result) => {
        if (result.isConfirmed) {
          try {
            const response = await fetch("php/logout.php", {
              method: "POST",
              credentials: "same-origin",
            });
            if (response.ok) {
              Swal.fire({
                icon: 'success',
                title: 'Logged Out',
                text: 'You have been logged out.',
                confirmButtonColor: '#2B7066'
              }).then(() => {
                window.location.href = "homepage.php";
              });
            } else {
              Swal.fire({
                icon: 'error',
                title: 'Logout Failed',
                text: 'Please try again.',
                confirmButtonColor: '#2B7066'
              });
            }
          } catch (error) {
            console.error("Logout error:", error);
            Swal.fire({
              icon: 'error',
              title: 'Error',
              text: 'An error occurred while logging out.',
              confirmButtonColor: '#2B7066'
            });
          }
        }
      });
    });

    // Mobile nav toggle
    const toggle = document.querySelector("#header .menu-toggle");
    const navLinks = document.querySelector("#header nav ul");
    toggle?.addEventListener("click", () => {
      navLinks?.classList.toggle("show");
    });

    // Scroll to top
    const scrollToTopBtn = document.getElementById("scroll-to-top-btn");
    window.addEventListener("scroll", () => {
      if (scrollToTopBtn)
        scrollToTopBtn.style.display = window.scrollY > 200 ? "flex" : "none";
    });
    scrollToTopBtn?.addEventListener("click", () => {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  };

  // Run immediately when called
  attachListeners();
}

// ✅ Make initHeader callable manually (don’t auto-run)
window.initHeader = initHeader;
