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
 *    entrée IA de l'éditeur. Éditeur fermé → le bouton revient. Seule
 *    exception (accord du 26/11) : la pastille d'ÉTAT du Copilot, qui compte
 *    les étapes d'une création en cours et n'ouvre rien.
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

  // Depuis la refonte du Copilot (26/11/2026) : dans l'éditeur, seul le
  // bouton AU REPOS se masque. Quand le Copilot y travaille (création d'un
  // e-mail ou d'une page demandée ailleurs), `#sendly-assist-fab` devient une
  // pastille qui compte les étapes, et elle doit se voir. La classe posée sur
  // <body> laisse la coquille (ai-assistant.js) en décider par son style.
  function toggleFab(hidden) {
    if (document.body) {
      document.body.classList.toggle('sendly-builder-ouvert', !!hidden);
    }
  }

  mQuery(document).on('builder:show', '.builder', function () {
    setModeClass(builderMode());
    toggleFab(true);
  });

  mQuery(document).on('builder:hide', '.builder', function () {
    toggleFab(false);
  });

  // Sortie du builder SANS `builder:hide` : « Enregistrer et fermer » depuis
  // l'éditeur, ou toute navigation ajax pendant que l'éditeur est ouvert,
  // retire `.builder` du DOM sans le déclencher — le bouton restait masqué
  // jusqu'au prochain rechargement complet (constat proprio 02/09). À chaque
  // fin de requête ajax, s'il n'y a plus d'éditeur actif à l'écran
  // (`.builder.builder-active`, posé par Mautic.launchBuilder), le bouton
  // revient.
  mQuery(document).ajaxComplete(function () {
    if (!mQuery('.builder.builder-active').length) {
      toggleFab(false);
    }
  });
})();
