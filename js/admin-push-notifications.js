const enableButton = document.getElementById('enableAdminPaymentNotifications');
const statusElement = document.getElementById('adminPaymentNotificationStatus');

if (enableButton && statusElement) {
  const isDedicatedPhoneSetup = Boolean(document.querySelector('.phone-setup'));
  const accountLabel = enableButton.dataset.accountLabel || 'Administrator';
  const returnLabel = enableButton.dataset.returnLabel || 'admin bookings page';
  const permissionHelp = document.getElementById('notificationPermissionHelp');
  const permissionHelpMessage = document.getElementById('notificationPermissionHelpMessage');
  const showPermissionHelp = message => {
    if (permissionHelpMessage && message) permissionHelpMessage.textContent = message;
    if (permissionHelp) permissionHelp.hidden = false;
  };
  document.getElementById('reloadAfterPermissionChange')?.addEventListener('click', () => window.location.reload());
  const setStatus = (message, type = '') => {
    statusElement.textContent = message;
    statusElement.dataset.status = type;
  };

  const showRegistrationSuccess = (message, deviceName) => {
    const body = enableButton.closest('.phone-body');
    if (!body) return;

    body.querySelector('.phone-steps')?.setAttribute('hidden', '');
    body.querySelector('.phone-field')?.setAttribute('hidden', '');
    let success = body.querySelector('.admin-push-registration-success');
    if (!success) {
      success = document.createElement('section');
      success.className = 'admin-push-registration-success';
      success.tabIndex = -1;
      success.innerHTML = `
        <span class="admin-push-success-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="m6 12 4 4 8-9"></path></svg>
        </span>
        <div>
          <small>REGISTRATION SUCCESSFUL</small>
          <h2>Payment Phone Registered</h2>
          <p class="admin-push-success-message"></p>
          <strong class="admin-push-success-device"></strong>
          <p>You may now return to the ${returnLabel}. This phone is ready to receive PayMongo QR notifications.</p>
        </div>`;
      body.insertBefore(success, enableButton);
    }
    success.querySelector('.admin-push-success-message').textContent = message || 'Payment notifications are enabled on this phone.';
    success.querySelector('.admin-push-success-device').textContent = deviceName || 'Registered phone';
    success.hidden = false;
    success.focus();
    if (navigator.vibrate) navigator.vibrate([80, 45, 80]);
  };

  const readJson = async response => {
    const contentType = response.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) {
      throw new Error('The server returned an unexpected response.');
    }
    return response.json();
  };

  const appRelativeUrl = (appUrl, relativePath) => {
    const base = new URL(appUrl);
    const path = base.pathname.endsWith('/') ? base.pathname : `${base.pathname}/`;
    base.pathname = path;
    base.search = '';
    base.hash = '';
    return new URL(relativePath, base);
  };

  const describeDevice = () => {
    const customNameInputId = enableButton.dataset.deviceNameInput;
    const customName = customNameInputId ? document.getElementById(customNameInputId)?.value.trim() : '';
    if (customName) return customName.slice(0, 100);
    const platform = navigator.userAgentData?.platform || navigator.platform || 'Unknown platform';
    const browser = navigator.userAgentData?.brands?.map(item => item.brand).filter(brand => brand !== 'Not A(Brand').join(', ')
      || navigator.userAgent.match(/(Edg|Chrome|Firefox|Safari)\/[\d.]+/)?.[0]
      || 'Web browser';
    return `${browser} on ${platform}`.slice(0, 100);
  };

  const waitForActiveServiceWorker = async registration => {
    if (registration?.active) return registration;

    const activationTimeout = new Promise((_, reject) => {
      window.setTimeout(() => reject(new Error(
        'The notification service worker did not activate. Reload this page and try again.'
      )), 15000);
    });

    const readyRegistration = await Promise.race([
      navigator.serviceWorker.ready,
      activationTimeout
    ]);

    if (!readyRegistration?.active) {
      throw new Error('The notification service worker is not active. Reload this page and try again.');
    }
    return readyRegistration;
  };

  let firebaseSetup = null;
  enableButton.disabled = true;
  setStatus('Preparing notification support...');

  (async () => {
    if (!window.isSecureContext) {
      throw new Error(`Payment notifications require the ${accountLabel} panel to be opened over HTTPS.`);
    }
    if (!('Notification' in window) || !('serviceWorker' in navigator)) {
      throw new Error('This browser does not support web push notifications.');
    }
    if (Notification.permission === 'denied') {
      showPermissionHelp('Notifications are blocked for this website. Change the site permission to Allow, then reload this page.');
      throw new Error('Notifications are blocked in this browser. Follow the permission steps above, then reload.');
    }

    const configEndpoint = enableButton.dataset.configEndpoint;
    const configResponse = await fetch(`${configEndpoint}?format=json`, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    });
    const publicConfig = await readJson(configResponse);
    if (!configResponse.ok || !publicConfig.configured) {
      throw new Error('Firebase is not fully configured for this HTTPS website.');
    }

    const configuredAppUrl = new URL(publicConfig.app_url);
    if (configuredAppUrl.origin !== window.location.origin) {
      if (!isDedicatedPhoneSetup) {
        enableButton.hidden = true;
        setStatus('');
        return;
      }
      throw new Error('APP_URL must match the HTTPS origin currently open in this browser.');
    }

    const [{ initializeApp }, { getMessaging, getToken, isSupported, onMessage }] = await Promise.all([
      import('https://www.gstatic.com/firebasejs/10.14.1/firebase-app.js'),
      import('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging.js')
    ]);
    const messagingSupported = await isSupported();
    firebaseSetup = { publicConfig, initializeApp, getMessaging, getToken, onMessage, messagingSupported };
    if (!messagingSupported) {
      throw new Error('Firebase Messaging is not supported by this browser.');
    }

    enableButton.disabled = false;
    setStatus('');
  })().catch(error => {
    setStatus(error instanceof Error ? error.message : 'Notification support could not be prepared.', 'error');
    enableButton.disabled = true;
  });

  enableButton.addEventListener('click', async () => {
    if (enableButton.disabled) return;

    const originalLabel = enableButton.querySelector('span')?.textContent || 'Enable Payment Notifications';
    enableButton.disabled = true;
    enableButton.classList.add('is-loading');
    if (enableButton.querySelector('span')) enableButton.querySelector('span').textContent = 'Enabling...';
    setStatus('Checking notification support...');

    try {
      if (!window.isSecureContext) {
        throw new Error(`Payment notifications require the ${accountLabel} panel to be opened over HTTPS.`);
      }
      if (!('Notification' in window)) {
        throw new Error('This browser does not support notifications.');
      }
      if (!('serviceWorker' in navigator)) {
        throw new Error('This browser does not support service workers.');
      }
      if (!firebaseSetup?.messagingSupported) {
        throw new Error('Firebase Messaging is not supported by this browser.');
      }

      const { publicConfig, initializeApp, getMessaging, getToken, onMessage } = firebaseSetup;
      const saveEndpoint = enableButton.dataset.saveEndpoint;
      const csrfToken = enableButton.dataset.csrfToken;

      if (Notification.permission === 'denied') {
        showPermissionHelp('Notifications are blocked for this website. Change the site permission to Allow, then reload this page.');
        throw new Error('This site cannot ask again while notifications are blocked. Follow the permission steps above.');
      }
      const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
      if (permission !== 'granted') {
        showPermissionHelp('The browser did not allow the notification request. Enable notifications in the site settings, then reload this page.');
        throw new Error('Notification permission is blocked. Follow the permission steps above, then reload.');
      }
      if (permissionHelp) permissionHelp.hidden = true;

      setStatus(`Registering this ${accountLabel} device...`);
      const serviceWorkerUrl = appRelativeUrl(publicConfig.app_url, 'firebase-messaging-sw.js');
      const serviceWorkerScope = appRelativeUrl(publicConfig.app_url, './').pathname;
      let serviceWorkerRegistration = await navigator.serviceWorker.register(serviceWorkerUrl.href, {
        scope: serviceWorkerScope,
        updateViaCache: 'none'
      });
      serviceWorkerRegistration = await waitForActiveServiceWorker(serviceWorkerRegistration);

      const firebaseApp = initializeApp(publicConfig.firebase);
      const messaging = getMessaging(firebaseApp);
      onMessage(messaging, payload => {
        const data = payload?.data && typeof payload.data === 'object' ? payload.data : {};
        const title = String(data.title || 'iTour Mercedes').slice(0, 120);
        const body = String(data.body || 'A new payment QR is ready.').slice(0, 300);
        let targetUrl = publicConfig.app_url;
        try {
          const candidate = new URL(String(data.url || ''), publicConfig.app_url);
          const configuredOrigin = new URL(publicConfig.app_url).origin;
          if (candidate.origin === window.location.origin || candidate.origin === configuredOrigin) {
            targetUrl = candidate.href;
          }
        } catch (_) {}

        serviceWorkerRegistration.showNotification(title, {
          body,
          icon: appRelativeUrl(publicConfig.app_url, 'img/newlogo.png').href,
          badge: appRelativeUrl(publicConfig.app_url, 'img/newlogo.png').href,
          tag: String(data.tag || 'itour-admin-payment').slice(0, 120),
          data: { url: targetUrl }
        }).catch(() => {});
      });
      const deviceToken = await getToken(messaging, {
        vapidKey: publicConfig.vapid_public_key,
        serviceWorkerRegistration
      });
      if (!deviceToken) {
        throw new Error('Firebase did not return a device token. Check the browser notification settings and try again.');
      }

      const saveResponse = await fetch(saveEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          token: deviceToken,
          device_name: describeDevice(),
          csrf_token: csrfToken
        })
      });
      const saveResult = await readJson(saveResponse);
      if (!saveResponse.ok || !saveResult.success) {
        throw new Error(saveResult.message || `The ${accountLabel} device could not be registered.`);
      }

      const registeredDeviceName = describeDevice();
      setStatus(saveResult.message || 'Payment phone registered successfully.', 'success');
      enableButton.classList.remove('is-loading');
      enableButton.classList.add('is-enabled');
      if (enableButton.querySelector('span')) enableButton.querySelector('span').textContent = 'Phone Registered';
      showRegistrationSuccess(saveResult.message, registeredDeviceName);
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Payment notifications could not be enabled.', 'error');
      enableButton.disabled = false;
      enableButton.classList.remove('is-loading');
      if (enableButton.querySelector('span')) enableButton.querySelector('span').textContent = originalLabel;
    }
  });
}
