/**
 * Écran de déblocage « Sendly Copilot » — décision produit 13/08 : l'IA est
 * réservée aux plans payants ; en plan gratuit les points d'entrée restent
 * VISIBLES (teaser) et chaque action ouvre CET écran.
 *
 * Design composé À PARTIR DE LA DIRECTION ARTISTIQUE Sendly (validé par le
 * proprio le 14/08 après itérations en direct, captures comparées) :
 *  - héros sur la vague satinée de la marque + voile navy (le motif des
 *    e-mails transactionnels de la DA), tracé au pixel dans son onglet ;
 *  - pilule « ✦ Sendly Copilot » en VERRE DÉPOLI : fond quasi transparent,
 *    liseré en DÉGRADÉ qui accroche la lumière (masque xor), ombre douce —
 *    l'effet exact du badge des heros de la DA (fichier Frame 26) ;
 *  - typographie HELVENA (la fonte du portail SaaS, embarquée en asset
 *    même-origine : le portail ne sert pas de CORS) — titre en Light, la
 *    signature de la marque : grand et léger, jamais lourd ;
 *  - coches rondes pleines bleues (les listes de la DA), rangée « Plan
 *    Pro » reprise de la carte « Pro plain » (couronne #DFEAFF + € 29/mois),
 *    CTA en pilule intégrale avec le libellé DA « Essayez Pro pendant
 *    14 jours ↗ » ;
 *  - responsive : ≤1024 carte 480, ≤640 FEUILLE DU BAS pleine largeur
 *    (le langage tactile du builder P8).
 *
 * Consommé par les quatre surfaces teaser via `SendlyAiUpsell.ouvrir()`.
 * Le lien vient de `SendlyAiConfig.upgradeUrl` — sans URL, pas de CTA.
 */
(function () {
  'use strict';

  var CHEMIN_ASSETS = (window.mauticBasePath || '') + '/plugins/EwebAiBundle/Assets';

  var ETINCELLES = '<svg viewBox="0 0 24 24" width="15" height="15" fill="#fff" aria-hidden="true"><path d="M9.1071 5.448C9.7051 3.698 12.1231 3.645 12.8321 5.289L12.8921 5.449L13.6991 7.809C13.884 8.35023 14.1829 8.84551 14.5755 9.26142C14.9682 9.67734 15.4454 10.0042 15.9751 10.22L16.1921 10.301L18.5521 11.107C20.3021 11.705 20.3551 14.123 18.7121 14.832L18.5521 14.892L16.1921 15.699C15.6507 15.8838 15.1552 16.1826 14.7391 16.5753C14.323 16.9679 13.996 17.4452 13.7801 17.975L13.6991 18.191L12.8931 20.552C12.2951 22.302 9.8771 22.355 9.1691 20.712L9.1071 20.552L8.3011 18.192C8.11628 17.6506 7.81748 17.1551 7.42485 16.739C7.03221 16.3229 6.5549 15.9959 6.0251 15.78L5.8091 15.699L3.4491 14.893C1.6981 14.295 1.6451 11.877 3.2891 11.169L3.4491 11.107L5.8091 10.301C6.35034 10.1161 6.84562 9.81719 7.26153 9.42457C7.67744 9.03195 8.00432 8.55469 8.2201 8.025L8.3011 7.809L9.1071 5.448ZM19.0001 2C19.1872 2 19.3705 2.05248 19.5293 2.15147C19.688 2.25046 19.8158 2.392 19.8981 2.56L19.9461 2.677L20.2961 3.703L21.3231 4.053C21.5106 4.1167 21.6749 4.23462 21.7953 4.39182C21.9157 4.54902 21.9867 4.73842 21.9994 4.93602C22.012 5.13362 21.9657 5.33053 21.8663 5.50179C21.7669 5.67304 21.6189 5.81094 21.4411 5.898L21.3231 5.946L20.2971 6.296L19.9471 7.323C19.8833 7.51043 19.7653 7.6747 19.608 7.79499C19.4508 7.91529 19.2613 7.98619 19.0637 7.99872C18.8662 8.01125 18.6693 7.96484 18.4981 7.86538C18.3269 7.76591 18.1891 7.61787 18.1021 7.44L18.0541 7.323L17.7041 6.297L16.6771 5.947C16.4896 5.8833 16.3253 5.76538 16.2049 5.60819C16.0845 5.45099 16.0135 5.26158 16.0008 5.06398C15.9882 4.86638 16.0345 4.66947 16.1339 4.49821C16.2333 4.32696 16.3813 4.18906 16.5591 4.102L16.6771 4.054L17.7031 3.704L18.0531 2.677C18.1205 2.47943 18.2481 2.30791 18.4179 2.1865C18.5878 2.06509 18.7913 1.99987 19.0001 2Z"/></svg>';
  var COURONNE = '<svg width="46" height="46" viewBox="0 0 49 49" fill="none" aria-hidden="true"><rect width="48.6437" height="48.6437" rx="12" fill="#DFEAFF"/><path d="M17.3223 33.3218H31.3223" stroke="#004FFF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M24.4473 25.0718H24.3223M24.5723 25.0718C24.5723 25.2099 24.4604 25.3218 24.3223 25.3218C24.1842 25.3218 24.0723 25.2099 24.0723 25.0718C24.0723 24.9337 24.1842 24.8218 24.3223 24.8218C24.4604 24.8218 24.5723 24.9337 24.5723 25.0718Z" stroke="#004FFF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M27.2375 19.9327L26.1301 17.7036C25.3413 16.1158 24.9469 15.3218 24.3223 15.3218C23.6977 15.3218 23.3033 16.1158 22.5145 17.7036L21.4071 19.9327C20.9033 20.9468 20.6515 21.4538 20.202 21.5679C20.1708 21.5758 20.1392 21.5823 20.1073 21.5872C19.6491 21.6575 19.2215 21.2886 18.3662 20.5508C16.3347 18.7982 15.3189 17.9219 14.7026 18.2708C14.6627 18.2934 14.6244 18.3187 14.5879 18.3465C14.0242 18.7762 14.4177 20.0632 15.2046 22.6371L16.3701 26.4497C16.7932 27.8339 17.0048 28.526 17.5401 28.9239C18.0754 29.3218 18.7949 29.3218 20.2337 29.3218L28.4109 29.3217C29.8497 29.3217 30.5691 29.3217 31.1044 28.9238C31.6398 28.5259 31.8513 27.8338 32.2745 26.4497L33.44 22.6371C34.2269 20.0632 34.6203 18.7762 34.0567 18.3465C34.0202 18.3187 33.9818 18.2934 33.942 18.2708C33.3257 17.9219 32.3099 18.7982 30.2784 20.5508C29.4231 21.2886 28.9955 21.6575 28.5373 21.5872C28.5054 21.5823 28.4738 21.5758 28.4425 21.5679C27.9931 21.4538 27.7412 20.9468 27.2375 19.9327Z" stroke="#004FFF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var COCHE = '<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';

  function injecterStyles() {
    if (document.getElementById('sendly-ia-upsell-css')) { return; }
    var st = document.createElement('style');
    st.id = 'sendly-ia-upsell-css';
    st.textContent = ''
      /* Helvena : la fonte du portail, servie MÊME ORIGINE (pas de CORS). */
      + '@font-face { font-family: "Helvena"; font-weight: 300; font-display: swap; src: url("' + CHEMIN_ASSETS + '/fonts/Helvena_Light.woff2") format("woff2"); }'
      + '@font-face { font-family: "Helvena"; font-weight: 500; font-display: swap; src: url("' + CHEMIN_ASSETS + '/fonts/Helvena_Medium.woff2") format("woff2"); }'
      + '@font-face { font-family: "Helvena"; font-weight: 700; font-display: swap; src: url("' + CHEMIN_ASSETS + '/fonts/Helvena_Bold.woff2") format("woff2"); }'
      /* Entrée orchestrée : voile en fondu, carte en fondu-glissé, puis les
       * lignes de la liste en cascade. Rejouée à chaque ouverture (le
       * passage par display:none réarme les animations). */
      + '@keyframes sendlyUpsellVoile { from { opacity: 0; } to { opacity: 1; } }'
      + '@keyframes sendlyUpsellCarte { from { opacity: 0; transform: translateY(18px) scale(.98); } to { opacity: 1; transform: none; } }'
      + '@keyframes sendlyUpsellLigne { from { opacity: 0; transform: translateY(9px); } to { opacity: 1; transform: none; } }'
      + '#sendly-ia-upsell { position: fixed; inset: 0; z-index: 10050; display: flex; align-items: center; justify-content: center; background: rgba(0,6,23,.55); font-family: "Helvena", "Helvetica Neue", -apple-system, "Segoe UI", sans-serif; padding: 16px; animation: sendlyUpsellVoile .25s ease-out; }'
      + '#sendly-ia-upsell .carte { background: #fff; border-radius: 24px; box-shadow: 0 40px 100px rgba(0,6,23,.5); width: min(500px, 100%); max-height: calc(100vh - 32px); overflow-y: auto; animation: sendlyUpsellCarte .5s cubic-bezier(.22,.9,.3,1); }'
      /* Héros : la vague de la marque + voile navy (motif e-mails DA). */
      + '#sendly-ia-upsell .heros { position: relative; border-radius: 24px 24px 0 0; padding: 22px 30px 24px; background-image: linear-gradient(180deg, rgba(0,18,72,.62), rgba(0,18,72,.18) 55%, rgba(0,79,255,.16)), url("' + CHEMIN_ASSETS + '/img/copilot-vague.jpg"); background-size: cover, cover; background-position: center, center 30%; text-align: center; }'
      /* Le ✕ parle le même langage que la pilule : verre dépoli, liseré
       * dégradé par masque xor, ombre douce. */
      + '#sendly-ia-upsell .heros .fermer { position: absolute; top: 16px; right: 18px; border: 0; background: linear-gradient(120deg, rgba(255,255,255,.10), rgba(255,255,255,.04) 40%, rgba(255,255,255,.09)); border-radius: 99px; width: 30px; height: 30px; color: #fff; font-size: 14px; cursor: pointer; line-height: 1; backdrop-filter: blur(8px); box-shadow: 0 6px 16px rgba(0,6,23,.22), inset 0 1px 0 rgba(255,255,255,.16); }'
      + '#sendly-ia-upsell .heros .fermer::before { content: ""; position: absolute; inset: 0; border-radius: 99px; padding: 1px; background: linear-gradient(130deg, rgba(255,255,255,.65), rgba(255,255,255,.08) 35%, rgba(255,255,255,.06) 62%, rgba(255,255,255,.45)); -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0); -webkit-mask-composite: xor; mask-composite: exclude; pointer-events: none; }'
      + '#sendly-ia-upsell .heros .fermer:hover { background: linear-gradient(120deg, rgba(255,255,255,.20), rgba(255,255,255,.10) 40%, rgba(255,255,255,.18)); }'
      /* Pilule « ✦ Sendly Copilot » en VERRE DÉPOLI (Frame 26) : fond quasi
       * transparent, liseré en dégradé par masque xor, ombre douce. */
      + '#sendly-ia-upsell .pilule-badge { position: relative; display: inline-flex; align-items: center; gap: 9px; background: linear-gradient(120deg, rgba(255,255,255,.10), rgba(255,255,255,.03) 40%, rgba(255,255,255,.08)); border: 0; backdrop-filter: blur(8px); border-radius: 999px; padding: 8px 18px; font-size: 13px; font-weight: 500; color: #fff; margin-bottom: 14px; box-shadow: 0 10px 26px rgba(0,6,23,.28), inset 0 1px 0 rgba(255,255,255,.18); }'
      + '#sendly-ia-upsell .pilule-badge::before { content: ""; position: absolute; inset: 0; border-radius: 999px; padding: 1px; background: linear-gradient(130deg, rgba(255,255,255,.75), rgba(255,255,255,.08) 35%, rgba(255,255,255,.06) 62%, rgba(255,255,255,.55)); -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0); -webkit-mask-composite: xor; mask-composite: exclude; pointer-events: none; }'
      + '#sendly-ia-upsell .pilule-badge svg { flex: none; }'
      /* Titre GRAND et LÉGER : la signature typographique Sendly. */
      + '#sendly-ia-upsell .heros h2 { margin: 0 0 10px; font-size: 29px; line-height: 1.14; font-weight: 300; color: #fff; letter-spacing: -.015em; }'
      + '#sendly-ia-upsell .heros p { margin: 0 auto; max-width: 36ch; font-size: 14px; line-height: 1.5; font-weight: 500; color: #DFEAFF; }'
      /* Coches rondes pleines (les listes de la DA), rythme régulier. */
      + '#sendly-ia-upsell ul { list-style: none; margin: 0; padding: 20px 36px 6px; display: grid; gap: 0; }'
      + '#sendly-ia-upsell li { display: flex; align-items: center; gap: 13px; font-size: 14px; font-weight: 500; color: #001248; padding: 7px 0; letter-spacing: .002em; animation: sendlyUpsellLigne .45s cubic-bezier(.22,.9,.3,1) both; }'
      + '#sendly-ia-upsell li:nth-child(1) { animation-delay: .14s; }'
      + '#sendly-ia-upsell li:nth-child(2) { animation-delay: .21s; }'
      + '#sendly-ia-upsell li:nth-child(3) { animation-delay: .28s; }'
      + '#sendly-ia-upsell li:nth-child(4) { animation-delay: .35s; }'
      + '#sendly-ia-upsell li .rond { width: 22px; height: 22px; border-radius: 99px; background: #004FFF; display: inline-flex; align-items: center; justify-content: center; flex: none; }'
      + '#sendly-ia-upsell .filet { height: 1px; background: #F5F7F9; margin: 14px 34px; }'
      /* Rangée plan : la carte « Pro plain » de la DA (couronne + prix). */
      + '#sendly-ia-upsell .plan { display: flex; align-items: center; gap: 14px; padding: 0 34px; }'
      + '#sendly-ia-upsell .plan .txt { flex: 1; min-width: 0; }'
      + '#sendly-ia-upsell .plan .nom { display: block; font-size: 15px; font-weight: 700; color: #000617; letter-spacing: -.005em; }'
      + '#sendly-ia-upsell .plan .det { display: block; font-size: 12px; color: #7b8698; margin-top: 2px; }'
      + '#sendly-ia-upsell .plan .prix { flex: none; white-space: nowrap; color: #7b8698; font-size: 13px; }'
      + '#sendly-ia-upsell .plan .prix b { font-size: 27px; font-weight: 700; color: #000617; letter-spacing: -.02em; margin: 0 1px 0 3px; }'
      /* CTA : pilule intégrale bleue, flèche ↗ (le bouton DA). */
      + '#sendly-ia-upsell .actions { padding: 16px 34px 10px; }'
      + '#sendly-ia-upsell a.cta { display: flex; align-items: center; justify-content: center; gap: 9px; width: 100%; border-radius: 999px; background: #004FFF; color: #fff; font-size: 14.5px; font-weight: 700; padding: 14px 0; text-decoration: none; box-shadow: 0 2px 8px rgba(0,79,255,.18); transition: background .15s, transform .18s, box-shadow .18s; }'
      + '#sendly-ia-upsell a.cta:hover { background: #0033B8; color: #fff; text-decoration: none; transform: translateY(-1px); box-shadow: 0 12px 26px rgba(0,79,255,.34); }'
      + '#sendly-ia-upsell a.cta:active { transform: translateY(0); box-shadow: 0 4px 12px rgba(0,79,255,.24); }'
      + '#sendly-ia-upsell a.cta .fl { font-size: 13px; }'
      + '#sendly-ia-upsell .tard { display: block; margin: 0 auto; border: 0; background: none; color: #9aa3b2; font-size: 12.5px; font-weight: 500; cursor: pointer; padding: 4px 12px 14px; }'
      + '#sendly-ia-upsell .tard:hover { color: #001248; }'
      /* Tablette puis mobile : feuille du bas (langage P8). */
      + '@media (max-width: 1024px) { #sendly-ia-upsell .carte { width: min(480px, 100%); } }'
      + '@media (max-width: 640px) {'
      + '#sendly-ia-upsell { padding: 10px; align-items: flex-end; }'
      + '#sendly-ia-upsell .carte { width: 100%; border-radius: 22px 22px 0 0; max-height: calc(100vh - 20px); }'
      + '#sendly-ia-upsell .heros { padding: 20px 22px 22px; border-radius: 22px 22px 0 0; }'
      + '#sendly-ia-upsell .heros h2 { font-size: 24px; }'
      + '#sendly-ia-upsell .heros p { font-size: 13px; }'
      + '#sendly-ia-upsell ul { padding: 16px 24px 4px; }'
      + '#sendly-ia-upsell .filet { margin: 12px 24px; }'
      + '#sendly-ia-upsell .plan { padding: 0 24px; }'
      + '#sendly-ia-upsell .plan .det { white-space: normal; }'
      + '#sendly-ia-upsell .actions { padding: 14px 24px 8px; }'
      + '}'
      + '@media (prefers-reduced-motion: reduce) {'
      + '#sendly-ia-upsell, #sendly-ia-upsell .carte, #sendly-ia-upsell li { animation: none; }'
      + '#sendly-ia-upsell a.cta, #sendly-ia-upsell a.cta:hover, #sendly-ia-upsell a.cta:active { transform: none; }'
      + '}';
    document.head.appendChild(st);
  }

  function ouvrir() {
    injecterStyles();
    var existant = document.getElementById('sendly-ia-upsell');
    if (existant) { existant.style.display = 'flex'; return; }
    var conf = window.SendlyAiConfig || {};
    var cta = conf.upgradeUrl
      ? '<div class="actions"><a class="cta" href="' + conf.upgradeUrl + '" target="_blank" rel="noopener">Essayez Pro pendant 14 jours <span class="fl">↗</span></a></div>'
      : '';
    var voile = document.createElement('div');
    voile.id = 'sendly-ia-upsell';
    voile.innerHTML = '<div class="carte" role="dialog" aria-modal="true">'
      + '<div class="heros">'
      + '<button type="button" class="fermer" aria-label="Fermer">✕</button>'
      + '<span class="pilule-badge">' + ETINCELLES + 'Sendly Copilot</span>'
      + '<h2>Passez à la vitesse<br>supérieure.</h2>'
      + '<p>L\'IA Sendly rédige, cible et améliore vos campagnes — directement là où vous travaillez.</p>'
      + '</div>'
      + '<ul>'
      + '<li><span class="rond">' + COCHE + '</span>Sections de page générées à la demande</li>'
      + '<li><span class="rond">' + COCHE + '</span>Rédaction et traduction d\'e-mails</li>'
      + '<li><span class="rond">' + COCHE + '</span>Segments en langage naturel</li>'
      + '<li><span class="rond">' + COCHE + '</span>Aide personnalisée dans chaque écran</li>'
      + '</ul>'
      + '<div class="filet"></div>'
      + '<div class="plan">' + COURONNE + '<span class="txt"><span class="nom">Plan Pro</span><span class="det">Toutes les fonctionnalités avancées incluses</span></span><span class="prix">€<b>29</b>/mois</span></div>'
      + cta
      + '<button type="button" class="tard">Plus tard</button>'
      + '</div>';
    function fermer() { voile.style.display = 'none'; }
    voile.addEventListener('click', function (e) { if (e.target === voile) { fermer(); } });
    voile.querySelector('.tard').addEventListener('click', fermer);
    voile.querySelector('.fermer').addEventListener('click', fermer);
    document.body.appendChild(voile);
  }

  function fermer() {
    var voile = document.getElementById('sendly-ia-upsell');
    if (voile) { voile.style.display = 'none'; }
  }

  window.SendlyAiUpsell = { ouvrir: ouvrir, fermer: fermer };
})();
