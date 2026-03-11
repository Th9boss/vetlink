<?php
// pages/login.php — page standalone (hors layout global)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (auth_login($email, $password)) {
        redirect('index.php?page=dashboard');
    }
    $loginError = 'Email ou mot de passe invalide.';
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
  <title><?= h(SITE_NAME) ?> - Connexion</title>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            ink: '#111111',
            mist: '#f5f5f7',
            steel: '#6e6e73',
            line: '#d2d2d7',
            accent: '#0071e3'
          },
          boxShadow: {
            panel: '0 38px 100px rgba(0,0,0,0.16), 0 0 0 1px rgba(255,255,255,1), 0 0 0 6px rgba(255,255,255,0.88), 0 0 0 10px rgba(15,23,42,0.06), 0 0 52px rgba(0,113,227,0.16)',
            soft: '0 10px 30px rgba(0,0,0,0.06)'
          },
          fontFamily: {
            sans: ['SF Pro Display', 'SF Pro Text', 'Helvetica Neue', 'Helvetica', 'Arial', 'sans-serif']
          }
        }
      }
    };
  </script>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-[100dvh] overflow-hidden bg-white font-sans text-ink antialiased">
  <div class="relative flex h-[100dvh] flex-col overflow-hidden bg-[radial-gradient(circle_at_top,_rgba(0,113,227,0.10),_transparent_28%),linear-gradient(180deg,#fbfbfd_0%,#f5f5f7_100%)]">
    <div class="pointer-events-none absolute inset-x-0 top-0 h-40 bg-gradient-to-b from-sky-100/80 to-transparent"></div>

    <header class="relative z-10">
      <div class="mx-auto flex max-w-6xl items-center justify-between px-5 py-5 lg:px-8">
        <a href="index.php?page=login" class="flex items-center gap-3">
          <img src="assets/img/logo-square.png" alt="VETLINK" class="h-11 w-11 rounded-[14px] object-cover shadow-soft">
          <div>
            <div class="text-[11px] font-semibold uppercase tracking-[0.24em] text-steel">Vetlink</div>
            <div class="text-sm font-medium text-ink">Version 2.0</div>
          </div>
        </a>
        <div class="hidden text-sm font-medium text-steel md:block">Gestion vétérinaire centralisée</div>
      </div>
    </header>

    <main class="relative z-10 flex min-h-0 flex-1 items-center justify-center px-5 py-2 lg:px-8">
      <div class="grid w-full max-w-6xl items-center gap-6 lg:grid-cols-[1fr_420px] lg:gap-10">
        <section class="hidden lg:block">
          <div class="max-w-2xl">
            <p class="text-sm font-medium text-steel">Vetlink simplifie l'organisation du cabinet.</p>
            <h1 class="mt-4 text-6xl font-semibold tracking-[-0.04em] text-ink">
              Gestion vétérinaire claire et rapide.
            </h1>
            <p class="mt-6 max-w-xl text-xl leading-8 text-steel">
              Patients, consultations et facturation dans un seul espace.
            </p>
            <div class="mt-10 grid max-w-lg grid-cols-2 gap-4">
              <div class="rounded-[28px] border border-white/70 bg-white/70 p-5 shadow-soft backdrop-blur">
                <div class="text-sm font-semibold text-ink">Dossiers centralisés</div>
              </div>
              <div class="rounded-[28px] border border-white/70 bg-white/70 p-5 shadow-soft backdrop-blur">
                <div class="text-sm font-semibold text-ink">Suivi clinique quotidien</div>
              </div>
            </div>
          </div>
        </section>

        <section class="mx-auto w-full max-w-md">
          <div class="rounded-[32px] border-2 border-white bg-white shadow-panel backdrop-blur-xl ring-1 ring-slate-300/95">
            <div class="px-5 py-6 sm:px-8 sm:py-8">
              <div class="mb-6">
                <h2 class="text-3xl font-semibold tracking-[-0.03em] text-ink">Connexion</h2>
                <p class="mt-2 text-sm leading-6 text-steel">Accédez à votre espace Vetlink.</p>
              </div>

              <?php if (!empty($loginError)): ?>
                <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                  <?= h($loginError) ?>
                </div>
              <?php endif; ?>

              <form method="post" novalidate class="space-y-4">
                <?= csrf_input() ?>

                <div>
                  <label for="email" class="mb-2 block text-sm font-medium text-ink">Adresse e-mail</label>
                  <input
                    id="email"
                    type="email"
                    name="email"
                    placeholder="veterinaire@exemple.ma"
                    autocomplete="email"
                    required
                    class="h-14 w-full rounded-2xl border border-line bg-mist px-4 text-base text-ink outline-none transition placeholder:text-steel focus:border-accent focus:bg-white focus:ring-4 focus:ring-sky-100"
                  >
                </div>

                <div>
                  <div class="mb-2 flex items-center justify-between gap-3">
                    <label for="password" class="block text-sm font-medium text-ink">Mot de passe</label>
                    <button type="button" id="togglePwd" class="text-xs font-semibold text-accent transition hover:opacity-80">
                      Afficher
                    </button>
                  </div>
                  <input
                    id="password"
                    type="password"
                    name="password"
                    placeholder="Mot de passe"
                    autocomplete="current-password"
                    required
                    class="h-14 w-full rounded-2xl border border-line bg-mist px-4 text-base text-ink outline-none transition placeholder:text-steel focus:border-accent focus:bg-white focus:ring-4 focus:ring-sky-100"
                  >
                </div>

                <button
                  type="submit"
                  class="inline-flex h-14 w-full items-center justify-center rounded-2xl bg-[#005fcc] text-base font-semibold text-white shadow-[0_16px_34px_rgba(0,95,204,0.32)] transition hover:bg-[#004fa9] focus:outline-none focus:ring-4 focus:ring-sky-200"
                >
                  Se connecter
                </button>
              </form>
            </div>
          </div>
        </section>
      </div>
    </main>

    <footer class="relative z-10">
      <div class="mx-auto flex max-w-6xl items-center justify-center gap-2 overflow-hidden px-4 py-3 text-[10px] text-steel sm:justify-between sm:px-6 sm:text-xs lg:px-8">
        <div class="truncate whitespace-nowrap">© <?= date('Y') ?> <?= h(SITE_NAME) ?>. Plateforme de gestion vétérinaire. Version 2.0.</div>
        <div class="flex shrink-0 items-center gap-2 whitespace-nowrap">
          <a href="https://vetex.ma" target="_blank" rel="noopener" class="font-semibold text-ink transition hover:text-accent">vetex.ma</a>
          <img src="assets/img/linear_logo.png" alt="VetExperts" class="h-4 w-auto object-contain opacity-80 sm:h-5">
        </div>
      </div>
    </footer>
  </div>

  <script>
    const toggleBtn = document.getElementById('togglePwd');
    const passwordInput = document.getElementById('password');

    if (toggleBtn && passwordInput) {
      toggleBtn.addEventListener('click', function () {
        const isHidden = passwordInput.type === 'password';
        passwordInput.type = isHidden ? 'text' : 'password';
        toggleBtn.textContent = isHidden ? 'Masquer' : 'Afficher';
      });
    }
  </script>
</body>
</html>
