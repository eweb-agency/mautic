/**
 * Écran de passage au plan payant — décision produit 13/08 : l'IA est
 * réservée aux plans payants ; en plan gratuit les points d'entrée restent
 * VISIBLES (teaser) et chaque action ouvre CET écran.
 *
 * Consommé par les quatre surfaces (tuile du builder, boutons e-mail,
 * lanceur de l'aide, segments via le lanceur) : `SendlyAiUpsell.ouvrir()`.
 * Le lien « Voir les plans » vient de `SendlyAiConfig.upgradeUrl` (portail,
 * paramètre saas_portal_url) — sans URL, seul « Plus tard » s'affiche.
 */
(function () {
  'use strict';

  var ICONE = '<svg viewBox="0 0 24 24" width="26" height="26" fill="#004FFF" aria-hidden="true"><path d="M9.1071 5.448C9.7051 3.698 12.1231 3.645 12.8321 5.289L12.8921 5.449L13.6991 7.809C13.884 8.35023 14.1829 8.84551 14.5755 9.26142C14.9682 9.67734 15.4454 10.0042 15.9751 10.22L16.1921 10.301L18.5521 11.107C20.3021 11.705 20.3551 14.123 18.7121 14.832L18.5521 14.892L16.1921 15.699C15.6507 15.8838 15.1552 16.1826 14.7391 16.5753C14.323 16.9679 13.996 17.4452 13.7801 17.975L13.6991 18.191L12.8931 20.552C12.2951 22.302 9.8771 22.355 9.1691 20.712L9.1071 20.552L8.3011 18.192C8.11628 17.6506 7.81748 17.1551 7.42485 16.739C7.03221 16.3229 6.5549 15.9959 6.0251 15.78L5.8091 15.699L3.4491 14.893C1.6981 14.295 1.6451 11.877 3.2891 11.169L3.4491 11.107L5.8091 10.301C6.35034 10.1161 6.84562 9.81719 7.26153 9.42457C7.67744 9.03195 8.00432 8.55469 8.2201 8.025L8.3011 7.809L9.1071 5.448Z"/></svg>';

  function injecterStyles() {
    if (document.getElementById('sendly-ia-upsell-css')) { return; }
    var st = document.createElement('style');
    st.id = 'sendly-ia-upsell-css';
    st.textContent = ''
      + '#sendly-ia-upsell { position: fixed; inset: 0; z-index: 10050; display: flex; align-items: center; justify-content: center; background: rgba(22,35,59,.4); font-family: -apple-system, "Segoe UI", sans-serif; }'
      + '#sendly-ia-upsell .carte { background: #fff; border-radius: 16px; box-shadow: 0 24px 60px rgba(22,35,59,.24); width: min(430px, calc(100vw - 32px)); padding: 26px 26px 22px; text-align: center; }'
      + '#sendly-ia-upsell .pastille { width: 54px; height: 54px; border-radius: 16px; background: #eef4ff; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 14px; }'
      + '#sendly-ia-upsell h3 { margin: 0 0 8px; font-size: 17px; font-weight: 700; color: #16233b; }'
      + '#sendly-ia-upsell p { margin: 0 0 18px; font-size: 13.5px; line-height: 1.55; color: #6a7486; }'
      + '#sendly-ia-upsell .actions { display: flex; gap: 10px; justify-content: center; }'
      + '#sendly-ia-upsell a.voir { display: inline-flex; align-items: center; border: 0; border-radius: 10px; background: #004FFF; color: #fff; font-size: 13.5px; font-weight: 600; padding: 10px 18px; text-decoration: none; box-shadow: 0 4px 12px rgba(0,79,255,.32); }'
      + '#sendly-ia-upsell button.plustard { border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; color: #24303f; font-size: 13.5px; font-weight: 600; padding: 10px 16px; cursor: pointer; }';
    document.head.appendChild(st);
  }

  function ouvrir() {
    injecterStyles();
    var existant = document.getElementById('sendly-ia-upsell');
    if (existant) { existant.style.display = 'flex'; return; }
    var conf = window.SendlyAiConfig || {};
    var voile = document.createElement('div');
    voile.id = 'sendly-ia-upsell';
    var cta = conf.upgradeUrl
      ? '<a class="voir" href="' + conf.upgradeUrl + '" target="_blank" rel="noopener">Voir les plans</a>'
      : '';
    voile.innerHTML = '<div class="carte" role="dialog" aria-modal="true">'
      + '<span class="pastille">' + ICONE + '</span>'
      + '<h3>L\'Assistant IA fait partie des plans payants</h3>'
      + '<p>Génération de sections de page, rédaction et traduction d\'e-mails, segments en langage naturel, aide personnalisée&nbsp;: passez à un plan payant pour tout débloquer.</p>'
      + '<div class="actions">' + cta + '<button type="button" class="plustard">Plus tard</button></div>'
      + '</div>';
    voile.addEventListener('click', function (e) { if (e.target === voile) { fermer(); } });
    voile.querySelector('.plustard').addEventListener('click', fermer);
    document.body.appendChild(voile);
  }

  function fermer() {
    var voile = document.getElementById('sendly-ia-upsell');
    if (voile) { voile.style.display = 'none'; }
  }

  window.SendlyAiUpsell = { ouvrir: ouvrir, fermer: fermer };
})();
