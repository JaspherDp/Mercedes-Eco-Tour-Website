<?php
chdir(__DIR__ . '/..');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../php/db_connection.php';

// === AJAX endpoint for unviewed notifications ===
if (isset($_GET['get_unviewed'])) {
    header('Content-Type: application/json'); // make sure it's JSON
    $booking_count = $pdo->query("SELECT COUNT(*) FROM bookings WHERE COALESCE(is_notif_viewed, 0)=0")->fetchColumn();
    $inquiry_count = $pdo->query("SELECT COUNT(*) FROM inquiries WHERE is_notif_viewed=0")->fetchColumn();
    echo json_encode(['count' => (int)$booking_count + (int)$inquiry_count]);
    exit; // stop the rest of the page from rendering
}

// === Admin info ===
$admin_username = 'Administrator';
$admin_email = 'admin@example.com';
$admin_profile_picture = '';

function resolveAdminProfileImage(?string $profilePicture): string {
    if (!$profilePicture) return '';
    $profilePicture = trim($profilePicture);
    if ($profilePicture === '') return '';
    if (preg_match('#^https?://#i', $profilePicture)) return $profilePicture;

    $candidates = [
        ltrim($profilePicture, '/'),
        'uploads/profile_pictures/' . basename($profilePicture),
        'uploads/profile_picture/' . basename($profilePicture),
        'uploads/profile/' . basename($profilePicture),
        'img/' . basename($profilePicture)
    ];
    foreach ($candidates as $candidate) {
        $full = getcwd() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $candidate);
        if (is_file($full)) return $candidate;
    }
    return '';
}

try {
    if (!empty($_SESSION['admin_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE admin_id = ? LIMIT 1');
        $stmt->execute([$_SESSION['admin_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $admin_username = htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8');
            $admin_email = htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8');
            $admin_profile_picture = resolveAdminProfileImage((string)($row['profile_picture'] ?? $row['profile_pic'] ?? $row['avatar'] ?? ''));
        }
    } elseif (!empty($_SESSION['username'])) {
        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE username = ? LIMIT 1');
        $stmt->execute([$_SESSION['username']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $admin_username = htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8');
            $admin_email = htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8');
            $admin_profile_picture = resolveAdminProfileImage((string)($row['profile_picture'] ?? $row['profile_pic'] ?? $row['avatar'] ?? ''));
        }
    } else {
        $stmt = $pdo->query('SELECT * FROM admin_users ORDER BY admin_id ASC LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $admin_username = htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8');
            $admin_email = htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8');
            $admin_profile_picture = resolveAdminProfileImage((string)($row['profile_picture'] ?? $row['profile_pic'] ?? $row['avatar'] ?? ''));
        }
    }
} catch (Exception $e) {}

$current_page = basename($_SERVER['PHP_SELF']);
$admin_initial = strtoupper(substr(trim(strip_tags($admin_username)) !== '' ? trim(strip_tags($admin_username)) : 'A', 0, 1));
$scriptDir = strtolower(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')));
$phpApiBase = (substr($scriptDir, -6) === '/admin') ? '../php' : 'php';

if (isset($_GET['get_pending_count'])) {
    header('Content-Type: application/json');
    $pending_count = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='pending'")->fetchColumn();
    echo json_encode(['count' => (int)$pending_count]);
    exit;
}


?>

<style>
.admin-sidebar {
  width: 250px;
  background: linear-gradient(180deg, #143a2d 0%, #102b22 100%);
  color: #daf7ea;
  display: flex;
  flex-direction: column;
  position: fixed;
  top: 0;
  bottom: 0;
  left: 0;
  border-right: 1px solid rgba(196, 243, 220, 0.14);
  box-shadow: 2px 0 16px rgba(0, 0, 0, 0.2);
  overflow: hidden;
  z-index: 100;
}

.admin-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 12px;
  text-decoration: none;
  min-height: 78px;
}
.admin-brand img {
  width: 42px;
  height: 42px;
  object-fit: contain;
  flex-shrink: 0;
}
.admin-brand .admin-brand-text {
  max-width: 140px;
  width: 100%;
  height: auto;
  filter: drop-shadow(0 0 1px rgba(208, 248, 229, 0.38));
}

.admin-navlinks {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 8px 10px 10px;
  align-content: start;
  scrollbar-width: thin;
  scrollbar-color: rgba(208, 250, 231, 0.35) transparent;
}
.admin-navlinks::-webkit-scrollbar { width: 8px; }
.admin-navlinks::-webkit-scrollbar-thumb {
  background: rgba(208, 250, 231, 0.3);
  border-radius: 10px;
}
.admin-navlinks a {
  text-decoration: none;
  color: #daf7ea;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 12px;
  border: 1px solid transparent;
  font-size: 0.85rem;
  font-weight: 600;
  transition: all 0.25s ease;
  white-space: nowrap;
  position: relative;
}
.admin-navlinks a:hover,
.admin-navlinks a.active {
  background: rgba(195, 245, 222, 0.16);
  color: #e8fff5;
  border-color: rgba(208, 250, 231, 0.2);
}
.admin-nav-icon {
  width: 18px;
  height: 18px;
  margin-right: 0;
  filter: brightness(0) saturate(100%) invert(91%) sepia(19%) saturate(309%) hue-rotate(96deg) brightness(106%) contrast(103%);
}

.admin-badge {
  margin-left: auto;
  background: #d8efe4;
  color: #1f614e;
  border-radius: 999px;
  font-size: 11px;
  padding: 0 6px;
  min-width: 20px;
  height: 20px;
  line-height: 1;
  text-align: center;
  font-weight: 700;
  display: none;
  align-items: center;
  justify-content: center;
}

.admin-navlinks a:hover .admin-badge,
.admin-navlinks a.active .admin-badge {
  background: rgba(232, 255, 245, 0.92);
  color: #1c5a48;
}

.admin-sidebar-bottom {
  padding: 10px;
  margin-top: auto;
}
.admin-account-card {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  border-radius: 14px;
  background: rgba(195, 245, 222, 0.1);
  border: 1px solid rgba(208, 250, 231, 0.14);
}
.admin-account-avatar {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 14px;
  font-weight: 800;
  background: linear-gradient(135deg, #2b7a66 0%, #1f614e 100%);
  border: 1px solid rgba(208, 250, 231, 0.35);
  flex-shrink: 0;
}
.admin-account-meta {
  min-width: 0;
}
.admin-account-meta strong {
  display: block;
  font-size: 13px;
  color: #e8fff5;
  font-weight: 700;
  line-height: 1.2;
}
.admin-account-meta small {
  display: block;
  margin-top: 2px;
  font-size: 12px;
  color: #b7d7ca;
  word-break: break-word;
  line-height: 1.2;
}

.admin-logout-divider {
  width: 100%;
  margin: 10px 0 6px;
  border: 0;
  border-top: 1px solid rgba(208, 250, 231, 0.24);
}
.admin-logout-btn {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 10px;
  padding: 10px 12px;
  color: #daf7ea;
  text-decoration: none;
  border-radius: 12px;
  border: 1px solid transparent;
  font-size: 0.85rem;
  font-weight: 600;
  transition: all 0.25s ease;
}
.admin-logout-btn:hover {
  background: rgba(195, 245, 222, 0.16);
  color: #e8fff5;
  border-color: rgba(208, 250, 231, 0.2);
}

.ap-header-right {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  margin-left: auto;
}

.main-content .admin-header,
.featured-main .featured-header {
  display: flex;
  align-items: center;
  gap: 12px;
}

.ap-header-notif-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
}

.ap-header-notif-btn {
  border: 1px solid #d8e6e0;
  background: #fff;
  border-radius: 10px;
  padding: 0 11px;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  color: #24434d;
  font-size: 12px;
  font-weight: 600;
  height: 36px;
  box-sizing: border-box;
}

.main-content .admin-header-profile,
.featured-main .admin-header-profile {
  width: 36px !important;
  height: 36px !important;
  border-radius: 50% !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  overflow: hidden !important;
  background: linear-gradient(135deg, #2b7a66 0%, #1f614e 100%) !important;
  color: #fff !important;
  font-size: 13px !important;
  font-weight: 800 !important;
  border: 1px solid rgba(43, 122, 102, 0.22) !important;
  box-shadow: 0 4px 10px rgba(28, 74, 62, 0.14) !important;
  text-transform: uppercase !important;
  flex-shrink: 0 !important;
}

.main-content .admin-header-profile img,
.featured-main .admin-header-profile img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.ap-header-notif-btn svg {
  width: 14px;
  height: 14px;
  fill: currentColor;
}

.ap-header-notif-badge {
  background: #d93025;
  color: #fff;
  border-radius: 999px;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  display: none;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 700;
  line-height: 1;
}

.ap-header-notif-panel {
  position: absolute;
  right: 0;
  top: calc(100% + 8px);
  width: 360px;
  max-height: 420px;
  overflow-y: auto;
  border-radius: 12px;
  border: 1px solid #d8e6e0;
  background: #fff;
  box-shadow: 0 12px 24px rgba(17, 67, 53, 0.12);
  z-index: 160;
  display: none;
}

.ap-header-notif-panel.open {
  display: block;
}

.ap-header-notif-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 12px;
  border-bottom: 1px solid #edf3f0;
}

.ap-header-notif-head h4 {
  margin: 0;
  font-size: 13px;
  color: #214952;
}

.ap-header-notif-head button {
  border: 0;
  background: transparent;
  color: #2b7a66;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}

.ap-header-notif-list {
  padding: 8px;
}

.ap-header-notif-item {
  position: relative;
  padding: 9px 10px;
  border-radius: 9px;
  background: #fff;
  border: 1px solid #edf3f0;
  margin-bottom: 8px;
  transition: background-color .2s ease, border-color .2s ease, box-shadow .2s ease;
}

.ap-header-notif-item.is-unread {
  background: linear-gradient(90deg, #e9fff3 0%, #f7fffb 100%);
  border-color: #8fd1b3;
  box-shadow: 0 0 0 1px rgba(43, 122, 102, 0.12);
}

.ap-header-notif-item.is-unread::before {
  content: "";
  position: absolute;
  left: 0;
  top: 6px;
  bottom: 6px;
  width: 4px;
  border-radius: 999px;
  background: #1f8a63;
}

.ap-header-notif-item strong {
  display: block;
  font-size: 12px;
  color: #203842;
  margin-bottom: 3px;
}

.ap-header-notif-item small {
  color: #5b6f78;
  font-size: 11px;
}

.ap-notif-unread-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-left: 6px;
  padding: 1px 7px;
  border-radius: 999px;
  background: #1f8a63;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .2px;
}
</style>

<aside class="admin-sidebar" id="adminSidebar" aria-label="Admin sidebar">
  <a class="admin-brand" href="adhomepage.php">
    <img src="img/newlogo.png" alt="iTour Mercedes logo">
    <img src="img/textlogo3.png" alt="iTour Mercedes" class="admin-brand-text">
  </a>
  <nav class="admin-navlinks">
    <a href="adhomepage.php" class="<?= $current_page=='adhomepage.php'?'active':'' ?>"><img src="img/homeicon2.png" class="admin-nav-icon"><span>Dashboard</span></a>
<a href="adbookings.php" class="<?= $current_page == 'adbookings.php' ? 'active' : '' ?>">
    <img src="img/bookingicon.png" class="admin-nav-icon">
    <span>Bookings</span>
    <span class="admin-badge" id="bookingBadge">0</span> <!-- New badge -->
</a>
    <a href="adfeatured.php" class="<?= $current_page == 'adfeatured.php' ? 'active' : '' ?>"><img src="img/featuredicon.png" class="admin-nav-icon"><span>Contents</span></a>
    <a href="adtourpackages.php" class="<?= $current_page == 'adtourpackages.php' ? 'active' : '' ?>"><img src="img/packagesicon.png" class="admin-nav-icon"><span>Tour Contents</span></a>
    <a href="adoperator.php" class="<?= $current_page == 'adoperator.php' ? 'active' : '' ?>"><img src="img/inquiryicon.png" class="admin-nav-icon"><span>Operators</span></a>
    <a href="adtourists.php" class="<?= $current_page == 'adtourists.php' ? 'active' : '' ?>"><img src="img/profileicon.png" class="admin-nav-icon"><span>Tourists</span></a>
    <a href="adtourists.php" class="<?= $current_page == 'adtourists.php' ? 'active' : '' ?>"><img src="img/noteicon.png" class="admin-nav-icon"><span>Reports</span></a>
    <a href="adactivitylog.php" class="<?= $current_page == 'adactivitylog.php' ? 'active' : '' ?>"><img src="img/activityicon.png" class="admin-nav-icon"><span>Activity Logs</span></a>
  </nav>

  <div class="admin-sidebar-bottom">
    <div class="admin-account-card">
      <span class="admin-account-avatar" aria-hidden="true"><?= htmlspecialchars($admin_initial) ?></span>
      <div class="admin-account-meta">
        <strong title="<?= $admin_username; ?>"><?= $admin_username; ?></strong>
        <small title="<?= $admin_email; ?>"><?= $admin_email !== '' ? $admin_email : 'Administrator account'; ?></small>
      </div>
    </div>
    <hr class="admin-logout-divider">
    <a href="#" class="admin-logout-btn" onclick="confirmAdminLogout(event)"><img src="img/logouticon.png" class="admin-nav-icon"><span>Logout</span></a>
  </div>
</aside>

<script>
function confirmAdminLogout(event) {
    event.preventDefault();
    if(confirm("Are you sure you want to logout?")){
        window.location.href = "adhomepage.php?action=logout";
    }
}
async function fetchPendingBookings() {
    try {
        const response = await fetch('<?= basename(__FILE__); ?>?get_pending_count=1');
        const data = await response.json();
        const badge = document.getElementById('bookingBadge');
        if (data.count > 0) {
            badge.style.display = 'inline-flex';
            badge.textContent = data.count;
        } else {
            badge.style.display = 'none';
        }
    } catch (err) {
        console.error('Failed to fetch pending bookings:', err);
    }
}


// Initial fetch + repeat every 5 seconds
fetchPendingBookings();
setInterval(fetchPendingBookings, 2000);

document.addEventListener('DOMContentLoaded', () => {
    const headers = document.querySelectorAll('.main-content .admin-header, .featured-main .featured-header');
    const notifApiBase = <?= json_encode($phpApiBase) ?>;
    const adminInitial = <?= json_encode($admin_initial) ?>;
    const adminName = <?= json_encode(trim(strip_tags($admin_username)) !== '' ? trim(strip_tags($admin_username)) : 'Website Admin') ?>;
    const adminProfileImage = <?= json_encode($admin_profile_picture) ?>;
    let notifCache = null;
    let markRequested = false;

    const renderNotifItems = (container, items) => {
        if (!container) return;
        if (!Array.isArray(items) || items.length === 0) {
            container.innerHTML = "<p style='margin:6px;color:#60707a;font-size:12px;'>No notifications yet.</p>";
            return;
        }
        container.innerHTML = items.map(item => {
            const isUnread = String(item?.is_notif_viewed ?? 0) === '0';
            const unreadClass = isUnread ? ' is-unread' : '';
            const unreadPill = isUnread ? '<span class="ap-notif-unread-pill">NEW</span>' : '';
            const bookingId = item.booking_id ?? '-';
            const fullName = item.full_name ?? 'Tourist';
            const bookingType = item.booking_type ?? 'Booking';
            const createdAt = item.created_at ?? '';
            return `
                <div class="ap-header-notif-item${unreadClass}">
                    <strong>#${bookingId} — ${fullName}${unreadPill}</strong>
                    <small>${bookingType} • ${createdAt}</small>
                </div>
            `;
        }).join('');
    };

    const fetchNotifications = () => {
        return fetch(`${notifApiBase}/fetch_notifications.php`, { cache: 'no-store' })
            .then(r => r.json())
            .then(payload => {
                notifCache = payload;
                return payload;
            })
            .catch(() => ({ unread: 0, data: [] }));
    };

    const renderNotificationState = (badgeEl, listEl, payload, forceHideBadge = false) => {
        const safePayload = payload || {};
        const unread = parseInt(safePayload.unread || 0, 10);
        renderNotifItems(listEl, safePayload.data || []);
        if (forceHideBadge) {
            badgeEl.style.display = 'none';
        } else if (unread > 0) {
            badgeEl.style.display = 'inline-flex';
            badgeEl.textContent = unread;
        } else {
            badgeEl.style.display = 'none';
        }
    };

    const markNotificationsRead = () => {
        if (markRequested) return Promise.resolve();
        markRequested = true;
        return fetch(`${notifApiBase}/mark_notif_badge.php`, { cache: 'no-store' })
            .then(() => {
                if (notifCache && Array.isArray(notifCache.data)) {
                    notifCache.data = notifCache.data.map(n => {
                        n.is_notif_viewed = 1;
                        return n;
                    });
                }
            })
            .catch(() => {})
            .finally(() => {
                markRequested = false;
            });
    };

    headers.forEach(header => {
        let left = header.querySelector('.admin-header-left');
        if (!left) {
            const title = header.querySelector('h1, h2, h3');
            if (title) {
                left = document.createElement('div');
                left.className = 'admin-header-left';
                title.parentNode.insertBefore(left, title);
                left.appendChild(title);
            }
        }
        if (left && !left.querySelector('.admin-header-subtitle')) {
            const subtitle = document.createElement('p');
            subtitle.className = 'admin-header-subtitle';
            subtitle.textContent = `Welcome, ${adminName}`;
            left.appendChild(subtitle);
        }

        const existingProfile = header.querySelector('.admin-header-profile');
        if (existingProfile && adminProfileImage && !existingProfile.querySelector('img')) {
            existingProfile.innerHTML = `<img src="${adminProfileImage}" alt="Admin profile" onerror="this.onerror=null;this.parentElement.textContent='${adminInitial || 'A'}';">`;
        }

        if (header.querySelector('.header-notification, #notificationIcon') && header.querySelector('.admin-header-profile')) {
            return;
        }

        let right = header.querySelector('.admin-header-right');
        if (!right) {
            right = document.createElement('div');
            right.className = 'admin-header-right';
            header.appendChild(right);
        }
        right.classList.add('ap-header-right');

        const headerNav = header.querySelector(':scope > nav, :scope > .nav-links');
        if (headerNav) {
            headerNav.classList.add('admin-header-nav');
            if (headerNav.parentElement !== right) {
                right.prepend(headerNav);
            }
        }

        let notifWrap = right.querySelector('.ap-header-notif-wrap');
        if (!notifWrap) {
            notifWrap = document.createElement('div');
            notifWrap.className = 'ap-header-notif-wrap';
            notifWrap.innerHTML = `
                <button type="button" class="ap-header-notif-btn" aria-expanded="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a6 6 0 0 0-6 6v3.6l-1.6 2.8A1 1 0 0 0 5.2 17h13.6a1 1 0 0 0 .8-1.6L18 12.6V9a6 6 0 0 0-6-6Zm0 19a3 3 0 0 0 2.8-2H9.2A3 3 0 0 0 12 22Z"></path></svg>
                    Notifications
                    <strong class="ap-header-notif-badge">0</strong>
                </button>
                <div class="ap-header-notif-panel" role="dialog" aria-label="Notifications">
                    <div class="ap-header-notif-head">
                        <h4>New Bookings</h4>
                        <button type="button" class="ap-header-mark-read">Mark as read</button>
                    </div>
                    <div class="ap-header-notif-list"></div>
                </div>
            `;
            right.appendChild(notifWrap);
        }

        if (!right.querySelector('.admin-header-profile')) {
            const profile = document.createElement('span');
            profile.className = 'admin-header-profile';
            profile.setAttribute('aria-label', 'Admin profile');
            if (adminProfileImage) {
                profile.innerHTML = `<img src="${adminProfileImage}" alt="Admin profile" onerror="this.onerror=null;this.parentElement.textContent='${adminInitial || 'A'}';">`;
            } else {
                profile.textContent = adminInitial || 'A';
            }
            right.appendChild(profile);
        }

        const btn = notifWrap.querySelector('.ap-header-notif-btn');
        const panel = notifWrap.querySelector('.ap-header-notif-panel');
        const badge = notifWrap.querySelector('.ap-header-notif-badge');
        const list = notifWrap.querySelector('.ap-header-notif-list');
        const markReadBtn = notifWrap.querySelector('.ap-header-mark-read');
        if (!btn || !panel || !badge || !list || !markReadBtn) return;

        const refreshNotifications = () => {
            return fetchNotifications().then(payload => {
                renderNotificationState(badge, list, payload, panel.classList.contains('open'));
                return payload;
            });
        };

        const closePanelAndMarkRead = () => {
            const wasOpen = panel.classList.contains('open');
            panel.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
            if (!wasOpen) return;

            const hasUnread = Array.isArray(notifCache?.data)
                ? notifCache.data.some(n => String(n?.is_notif_viewed ?? 0) === '0')
                : false;
            if (!hasUnread) return;

            markNotificationsRead().then(() => {
                badge.style.display = 'none';
                if (notifCache && Array.isArray(notifCache.data)) {
                    renderNotifItems(list, notifCache.data);
                }
            });
        };

        refreshNotifications();
        setInterval(refreshNotifications, 4000);

        btn.addEventListener('click', () => {
            const isOpen = !panel.classList.contains('open');
            if (!isOpen) {
                closePanelAndMarkRead();
                return;
            }
            panel.classList.add('open');
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            refreshNotifications().then(() => {
                badge.style.display = 'none';
                if (notifCache) {
                    renderNotifItems(list, notifCache.data || []);
                }
            });
        });

        markReadBtn.addEventListener('click', () => {
            markNotificationsRead().then(() => {
                badge.style.display = 'none';
                if (notifCache && Array.isArray(notifCache.data)) {
                    renderNotifItems(list, notifCache.data);
                }
            });
        });

        document.addEventListener('click', (e) => {
            if (!notifWrap.contains(e.target)) {
                closePanelAndMarkRead();
            }
        });
    });
});

</script>
