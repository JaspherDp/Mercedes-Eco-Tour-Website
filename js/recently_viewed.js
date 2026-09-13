(function (global) {
  "use strict";

  const STORAGE_KEY_PREFIX = "itour_recently_viewed_v1";
  const ACCOUNT_ID = String(global.RecentlyViewedConfig?.accountId ?? "").trim();
  const STORAGE_KEY = ACCOUNT_ID ? `${STORAGE_KEY_PREFIX}:${ACCOUNT_ID}` : "";
  const TTL_MS = 2 * 24 * 60 * 60 * 1000;
  const MAX_ITEMS = 20;

  try {
    global.localStorage.removeItem(STORAGE_KEY_PREFIX);
  } catch (error) {
    // Browsing can continue when storage is disabled.
  }

  function readRaw() {
    if (!STORAGE_KEY) return [];
    try {
      const value = JSON.parse(global.localStorage.getItem(STORAGE_KEY) || "[]");
      return Array.isArray(value) ? value : [];
    } catch (error) {
      return [];
    }
  }

  function write(items) {
    if (!STORAGE_KEY) return;
    try {
      global.localStorage.setItem(STORAGE_KEY, JSON.stringify(items.slice(0, MAX_ITEMS)));
    } catch (error) {
      // Browsing can continue when storage is disabled or full.
    }
  }

  function normalize(item, viewedAt) {
    const type = String(item?.type || "").trim().toLowerCase();
    const id = String(item?.id ?? "").trim();
    if (!type || !id) return null;

    return {
      key: `${type}:${id}`,
      type,
      id,
      name: String(item.name || "Recently viewed"),
      image: String(item.image || "img/sampleimage.png"),
      subtitle: String(item.subtitle || ""),
      price: Number(item.price || 0),
      priceUnit: String(item.priceUnit || ""),
      rating: Number(item.rating || 0),
      reviewCount: Number(item.reviewCount ?? item.totalReviews ?? item.total_reviews ?? 0),
      href: String(item.href || ""),
      viewedAt,
      expiresAt: viewedAt + TTL_MS
    };
  }

  function getAll() {
    const now = Date.now();
    const items = readRaw()
      .filter(item => item && Number(item.expiresAt || 0) > now)
      .sort((first, second) => Number(second.viewedAt || 0) - Number(first.viewedAt || 0));
    write(items);
    return items;
  }

  function add(item) {
    if (!STORAGE_KEY) return [];
    const now = Date.now();
    const normalized = normalize(item, now);
    if (!normalized) return getAll();

    const items = getAll().filter(entry => entry.key !== normalized.key);
    items.unshift(normalized);
    write(items);
    return items;
  }

  function remove(key) {
    if (!STORAGE_KEY) return [];
    const items = getAll().filter(item => item.key !== String(key || ""));
    write(items);
    return items;
  }

  function clear() {
    if (!STORAGE_KEY) return;
    try {
      global.localStorage.removeItem(STORAGE_KEY);
    } catch (error) {
      // No action is required when storage is unavailable.
    }
  }

  global.RecentlyViewed = Object.freeze({
    accountId: ACCOUNT_ID,
    add,
    clear,
    enabled: Boolean(ACCOUNT_ID),
    getAll,
    remove,
    storageKey: STORAGE_KEY,
    ttlMs: TTL_MS
  });
})(window);
