document.addEventListener('DOMContentLoaded', function () {
  const offcanvasEl = document.getElementById('offcanvasNav');
  const toggler = document.querySelector('.navbar-toggler[data-bs-target="#offcanvasNav"]');

  if (!offcanvasEl || !toggler || typeof bootstrap === 'undefined' || !bootstrap.Offcanvas) {
    return;
  }

  const menu = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);

  toggler.addEventListener('click', function (event) {
    event.preventDefault();
    menu.show();
  });
});
