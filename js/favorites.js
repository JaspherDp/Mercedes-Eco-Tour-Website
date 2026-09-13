(function () {
  'use strict';

  const config = window.FavoritesConfig || {};
  const endpoint = config.endpoint || 'php/favorites_api.php';
  const csrfToken = config.csrfToken || '';
  let toastTimer;

  function showToast(message) {
    let toast = document.querySelector('.favorite-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.className = 'favorite-toast';
      toast.setAttribute('role', 'status');
      document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 2300);
  }

  function setButtonState(button, favorited) {
    button.classList.toggle('is-favorite', favorited);
    button.setAttribute('aria-pressed', favorited ? 'true' : 'false');
    button.setAttribute('aria-label', favorited ? 'Remove from favorites' : 'Add to favorites');
    button.title = favorited ? 'Remove from favorites' : 'Add to favorites';
  }

  function requestLogin() {
    const loginButton = document.getElementById('openModalBtn');
    if (loginButton) {
      loginButton.click();
      return;
    }
    window.location.href = config.loginUrl || 'login.php';
  }

  async function toggleFavorite(button) {
    if (button.classList.contains('is-loading')) return;

    const type = button.dataset.favoriteType;
    const id = Number(button.dataset.favoriteId);
    if (!type || !id) return;

    button.classList.add('is-loading');
    try {
      const response = await fetch(button.dataset.favoriteEndpoint || endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({
          action: button.dataset.favoriteAction || 'toggle',
          type,
          id,
          csrf_token: button.dataset.favoriteCsrf || csrfToken
        })
      });
      const data = await response.json().catch(() => ({}));
      if (response.status === 401 || data.code === 'AUTH_REQUIRED') {
        requestLogin();
        return;
      }
      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Unable to update favorites.');
      }

      document.querySelectorAll(
        `.favorite-toggle[data-favorite-type="${CSS.escape(type)}"][data-favorite-id="${id}"]`
      ).forEach(item => setButtonState(item, Boolean(data.favorited)));
      showToast(data.message || (data.favorited ? 'Added to favorites.' : 'Removed from favorites.'));
      document.dispatchEvent(new CustomEvent('favorite:changed', {
        detail: {type, id, favorited: Boolean(data.favorited), source: button}
      }));
    } catch (error) {
      showToast(error.message || 'Unable to update favorites.');
    } finally {
      button.classList.remove('is-loading');
    }
  }

  document.addEventListener('click', function (event) {
    const button = event.target.closest('.favorite-toggle');
    if (!button) return;
    event.preventDefault();
    event.stopPropagation();
    toggleFavorite(button);
  });

  document.querySelectorAll('.favorite-toggle').forEach(button => {
    setButtonState(button, button.classList.contains('is-favorite'));
  });
})();

