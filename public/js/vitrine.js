// Vitrine: troca a foto principal ao clicar nas miniaturas. JavaScript puro.
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-gallery]').forEach(function (gallery) {
            var main = gallery.querySelector('[data-gallery-main]');
            gallery.querySelectorAll('[data-gallery-thumb]').forEach(function (thumb) {
                thumb.addEventListener('click', function () {
                    if (main) main.src = thumb.getAttribute('data-gallery-thumb');
                    gallery.querySelectorAll('[data-gallery-thumb]').forEach(function (t) { t.classList.remove('active'); });
                    thumb.classList.add('active');
                });
            });
        });
    });
})();
