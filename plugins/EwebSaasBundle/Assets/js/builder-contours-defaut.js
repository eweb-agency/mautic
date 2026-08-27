/**
 * Contours des blocs ACTIFS PAR DÉFAUT dans tous les builders (directive
 * proprio 27/08) : sans les pointillés, on ne « voit » pas la structure
 * de la page ou de l'e-mail et on dépose à l'aveugle.
 *
 * Sans contexte : pages, e-mails HTML et MJML. La commande GrapesJS est
 * `sw-visibility` (celle que pilote la case « Contours des blocs » du
 * panneau Options des pages) — on l'active au chargement si elle ne
 * l'est pas déjà, et on coche la case du panneau quand elle existe
 * (elle se construit aussi au 'load', d'où le petit délai).
 */
(function () {
  'use strict';

  if (!window.MauticGrapesJsPlugins) {
    window.MauticGrapesJsPlugins = [];
  }

  window.MauticGrapesJsPlugins.push({
    name: 'sendly-contours-defaut',
    plugin: function (editor) {
      editor.on('load', function () {
        try {
          if (!editor.Commands.isActive('sw-visibility')) {
            editor.runCommand('sw-visibility');
          }
        } catch (e) { /* préset sans la commande : rien à faire */ }
        setTimeout(function () {
          var case_ = document.getElementById('sendly-opt-contours');
          if (case_) { case_.checked = true; }
        }, 600);
      });
    },
  });
})();
