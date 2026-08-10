/**
 * Coquille du builder — fondations de la REFONTE de l'éditeur (chantier D).
 *
 * P0 : deux responsabilités, volontairement posées HORS du bundle Parcel de
 * GrapesJsBuilderBundle (ce fichier est auto-agrégé dans app.js — aucun
 * `npm run build` nécessaire pour itérer) :
 *
 * 1. LA CLASSE DE MODE. Le namespace .gjs-* est GLOBAL aux trois éditeurs
 *    (page, e-mail HTML, e-mail MJML) : sans cloisonnement, tout style de la
 *    refonte landing page fuirait sur l'éditeur d'e-mails — qui doit rester
 *    intact. On pose `gjs-mode-page` ou `gjs-mode-email` sur `.builder` ET
 *    `.builder-panel` à chaque ouverture ; le thème (builder-sendly.css) ne
 *    s'applique QUE sous `.gjs-mode-page`.
 *
 * 2. LE BOUTON IA FLOTTANT SE MASQUE dans l'éditeur (décision proprio
 *    10/08) : la tuile « Assistant IA » de la bibliothèque devient l'unique
 *    entrée IA de l'éditeur. Éditeur fermé → le bouton revient.
 *
 * Le point d'accroche est PUBLIC et stable : builder.js déclenche
 * `builder:show` sur `.builder` (jQuery, bulle jusqu'au document) et
 * builder.service.js `builder:hide` à la fermeture.
 */
(function () {
  'use strict';

  /** page | email — le formulaire hôte fait foi (form[name="page"]). */
  function builderMode() {
    return mQuery('form[name="page"]').length ? 'page' : 'email';
  }

  function setModeClass(mode) {
    mQuery('.builder, .builder-panel')
      .removeClass('gjs-mode-page gjs-mode-email')
      .addClass('gjs-mode-' + mode);
  }

  function toggleFab(hidden) {
    var fab = document.getElementById('sendly-assist-fab');
    if (fab) {
      fab.style.display = hidden ? 'none' : '';
    }
  }

  mQuery(document).on('builder:show', '.builder', function () {
    setModeClass(builderMode());
    toggleFab(true);
  });

  mQuery(document).on('builder:hide', '.builder', function () {
    toggleFab(false);
  });
})();
