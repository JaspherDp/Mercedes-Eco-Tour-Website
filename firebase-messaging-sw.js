/* global firebase, __ITOUR_FIREBASE_PUBLIC_CONFIG__, __ITOUR_FIREBASE_CONFIGURED__, __ITOUR_APP_URL__ */
importScripts('./firebase-public-config.php');

self.addEventListener('install', event => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', event => {
  event.waitUntil(self.clients.claim());
});

const configuredAppUrl = (() => {
  try {
    return new URL(self.__ITOUR_APP_URL__ || self.location.origin);
  } catch (error) {
    return new URL(self.location.origin);
  }
})();

const safeInternalUrl = candidate => {
  if (typeof candidate !== 'string' || candidate.length > 2048) return null;
  try {
    const target = new URL(candidate, configuredAppUrl.href);
    const allowedOrigin = target.origin === self.location.origin || target.origin === configuredAppUrl.origin;
    if (!allowedOrigin || !['https:', 'http:'].includes(target.protocol)) return null;
    return target.href;
  } catch (error) {
    return null;
  }
};

// Register the click handler before Firebase Messaging is loaded. Firebase's
// worker SDK also registers notification handlers, and loading it first can
// prevent a custom data-notification click from reaching this listener.
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const notificationData = event.notification?.data && typeof event.notification.data === 'object'
    ? event.notification.data
    : {};
  const firebaseMessage = notificationData.FCM_MSG && typeof notificationData.FCM_MSG === 'object'
    ? notificationData.FCM_MSG
    : {};
  const candidateUrl = notificationData.url
    || notificationData.link
    || firebaseMessage?.data?.url
    || firebaseMessage?.data?.link
    || firebaseMessage?.fcmOptions?.link
    || firebaseMessage?.webpush?.fcm_options?.link
    || '';
  const targetUrl = safeInternalUrl(String(candidateUrl)) || configuredAppUrl.href;

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
      const matchingClient = clientList.find(client => {
        try {
          return new URL(client.url).href === new URL(targetUrl).href;
        } catch (error) {
          return false;
        }
      });
      if (matchingClient && 'focus' in matchingClient) return matchingClient.focus();
      return self.clients.openWindow ? self.clients.openWindow(targetUrl) : undefined;
    })
  );
});

importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js');

if (self.__ITOUR_FIREBASE_CONFIGURED__) {
  firebase.initializeApp(self.__ITOUR_FIREBASE_PUBLIC_CONFIG__);
  const messaging = firebase.messaging();

  messaging.onBackgroundMessage(payload => {
    // Notification payloads are displayed by Firebase itself. Data-only test
    // messages are rendered here so this worker remains ready for Phase 2.
    if (payload?.notification) return;

    const data = payload?.data && typeof payload.data === 'object' ? payload.data : {};
    const title = String(data.title || 'iTour Mercedes').slice(0, 120);
    const body = String(data.body || 'You have a new Administrator notification.').slice(0, 300);
    const targetUrl = safeInternalUrl(String(data.url || '')) || configuredAppUrl.href;
    const iconUrl = new URL('img/newlogo.png', configuredAppUrl.href.endsWith('/') ? configuredAppUrl.href : `${configuredAppUrl.href}/`).href;

    return self.registration.showNotification(title, {
      body,
      icon: iconUrl,
      badge: iconUrl,
      tag: String(data.tag || 'itour-admin-notification').slice(0, 120),
      data: { url: targetUrl }
    });
  });
}
