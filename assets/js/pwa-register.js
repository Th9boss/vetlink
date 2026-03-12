(() => {
  const baseUrl = typeof window.VETLINK_BASE_URL === 'string' && window.VETLINK_BASE_URL
    ? window.VETLINK_BASE_URL
    : '/';
  const swUrl = typeof window.VETLINK_SW_URL === 'string' && window.VETLINK_SW_URL
    ? window.VETLINK_SW_URL
    : (baseUrl + 'sw.js');

  if (!('serviceWorker' in navigator)) {
    return;
  }

  window.addEventListener('load', () => {
    navigator.serviceWorker.register(swUrl, { scope: baseUrl }).catch(() => {});
  });
})();
