<?php // includes/footer.php ?>
</main>
<footer class="border-top py-3 text-center small text-muted">
  &copy; <?= date('Y') ?> <?= h(SITE_NAME) ?> — Tous droits réservés
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>

<script src="<?= h(asset_url('assets/js/app.js')) ?>"></script>
<script src="<?= h(asset_url('assets/js/pwa-register.js')) ?>"></script>
<script>
  (function () {
    if (window.VETLINK_DEVICE_TOKEN) {
      try {
        localStorage.setItem('vetlink_device_token', window.VETLINK_DEVICE_TOKEN);
      } catch (_) {}
    }

    if (!document.body || !document.querySelector('nav')) return;

    let timer = null;

    function pingAuth() {
      fetch('api/auth_ping.php', {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store'
      }).catch(function () {});
    }

    function startPing() {
      if (timer) return;
      pingAuth();
      timer = window.setInterval(pingAuth, 120000);
    }

    function stopPing() {
      if (!timer) return;
      window.clearInterval(timer);
      timer = null;
    }

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') {
        startPing();
      } else {
        stopPing();
      }
    });

    window.addEventListener('focus', startPing);
    window.addEventListener('pageshow', startPing);
    window.addEventListener('pagehide', stopPing);

    startPing();
  })();
</script>
</body>
</html>
