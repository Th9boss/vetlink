(() => {
  const baseUrl = typeof window.VETLINK_BASE_URL === 'string' && window.VETLINK_BASE_URL
    ? window.VETLINK_BASE_URL
    : '/';

  if (!('serviceWorker' in navigator)) {
    return;
  }

  window.addEventListener('load', () => {
    navigator.serviceWorker.register(baseUrl + 'sw.js', { scope: baseUrl }).catch(() => {});
  });
})();
