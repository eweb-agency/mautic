/**
 * Retouche d'image au format de l'écran — chantier retouche (maquette
 * validée 13/08, décisions R-a et R-b).
 *
 * La modale tui est INITIALISÉE à 650px de haut (uiSize du plugin) : sur
 * téléphone elle débordait de l'écran, barre d'outils coupée et bouton
 * « Apply » flottant (capture proprio 13/08). Le sprite d'icônes et les
 * libellés viennent de la config du plugin (builder.service.js) ; la mise
 * en forme vit dans builder-sendly.css ; ICI on re-calepine le CANVAS aux
 * dimensions réelles de la modale — la seule chose que le CSS ne sait pas
 * faire, car tui fige ses dimensions à l'initialisation.
 */
(function () {
  'use strict';

  if (!window.MauticGrapesJsPlugins) {
    window.MauticGrapesJsPlugins = [];
  }

  window.MauticGrapesJsPlugins.push({
    name: 'sendly-retouche-image',
    context: ['page'],
    plugin: function (editor) {
      function ajuster() {
        var cmd = editor.Commands.get('tui-image-editor');
        var inst = cmd && cmd.imageEditor;
        var dlg = document.querySelector('.gjs-mdl-dialog');
        if (!inst || !inst.ui || !inst.ui.resizeEditor || !dlg) { return; }
        var header = dlg.querySelector('.gjs-mdl-header');
        var hHeader = header ? Math.round(header.getBoundingClientRect().height) : 0;
        var mobile = window.innerWidth < 768;
        // Bureau : au plus 650px, borné par la fenêtre ; mobile : tout
        // l'écran sous l'en-tête (la modale est en plein écran, CSS R-b).
        var h = mobile
          ? Math.round(window.innerHeight) - hHeader
          : Math.min(650, Math.round(window.innerHeight) - hHeader - 28);
        var w = Math.round(dlg.getBoundingClientRect().width);
        if (h < 200 || w < 200) { return; }
        inst.ui.resizeEditor({ uiSize: { width: w + 'px', height: h + 'px' } });
      }

      var suivi = null;

      editor.on('run:tui-image-editor', function () {
        // L'instance naît pendant le run (et le bundle tui peut arriver du
        // réseau au premier appel) : deux passes couvrent les deux cas.
        setTimeout(ajuster, 80);
        setTimeout(ajuster, 450);
        if (!suivi) {
          suivi = function () { ajuster(); };
          window.addEventListener('resize', suivi);
        }
      });

      editor.on('stop:tui-image-editor', function () {
        if (suivi) {
          window.removeEventListener('resize', suivi);
          suivi = null;
        }
      });
    },
  });
})();
