/**
 * Assistant de page EN PLACE — chantier D, P7 (maquette validée 12/08).
 *
 * Plus AUCUN panneau flottant : tout se passe À L'ENDROIT du dépôt.
 * - La tuile « Assistant IA » se GLISSE dans la page (ligne d'insertion
 *   native) ou se CLIQUE (insertion après la sélection, sinon en fin de
 *   page) → un BLOC DE SAISIE apparaît en place (champ + raccourcis).
 * - Pendant la génération : squelette animé à la place du bloc.
 * - La section générée arrive SUR PLACE, coiffée d'une barre contextuelle
 *   Régénérer / Ajuster / Garder / Annuler. « Ajuster » rouvre la saisie
 *   PRÉ-REMPLIE de la dernière consigne. Après « Garder », la section est
 *   un bloc 100 % normal.
 * - « Améliorer / Traduire » vivent sur la MINI-BARRE du composant texte
 *   sélectionné (décision P7-a), plus sur un panneau.
 *
 * Toute l'interface vit DANS L'IFRAME du canvas (même realm, motif prouvé
 * par le RTE P4) et chaque élément porte le BOUCLIER stopPropagation :
 * GrapesJS re-diffuse les clics du canvas et ferme/sélectionne sinon
 * (leçons P4/P6, constatées en clics réels).
 *
 * Parle à /s/ai/generate avec surface=page (prompt landing dédié côté
 * serveur — les textes « façon e-mail » étaient le défaut relevé en P5).
 * Gated par la clé de l'instance : sans SendlyAiConfig, la tuile n'existe
 * pas (builder-composants) et ce module reste dormant.
 *
 * Lot E5 (07/09) — l'ÉDITEUR D'E-MAILS aussi (contextes email-mjml et
 * email-html) : même tuile, même invite, même barre. Différences tenues
 * par le MODE : surface=email-section (une <mj-section> ou une rangée
 * table, jamais un document), marqueurs d'interface en <mj-raw> (seul
 * enfant libre d'un mj-body), insertion au niveau des SECTIONS (une
 * section ne vit pas dans une colonne), retouches en FRAGMENT (le contenu
 * du bloc, pas un document). L'✨ de la barre disparaît, comme sur le
 * webpage.
 */
(function () {
  'use strict';

  if (!window.MauticGrapesJsPlugins) {
    window.MauticGrapesJsPlugins = [];
  }

  // Chaque idée : [pictogramme, libellé de la puce, consigne envoyée]. Le
  // pictogramme distingue les idées d'un coup d'œil ; elles défilent sur UNE
  // rangée (retours du propriétaire du 26/11 : un carrousel, jamais une idée
  // seule sur sa ligne).
  var RACCOURCIS = [
    ['entete', 'En-tête avec titre et bouton', 'Une section d’en-tête avec un titre fort et un bouton'],
    ['colonnes', 'Trois avantages', 'Une section « 3 avantages » avec icônes'],
    ['bulle', 'Témoignages clients', 'Une bande de témoignages clients'],
    ['clic', 'Appel à l’action final', 'Un appel à l\'action final avec bouton'],
  ];

  // Les dessins de l'interface en place (plus aucun caractère en guise
  // d'icône : flèche, crayon, coche et croix rendaient différemment d'un
  // poste à l'autre).
  function dessin(chemins, taille, epaisseur) {
    return '<svg width="' + taille + '" height="' + taille + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (epaisseur || 2) +
      '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + chemins + '</svg>';
  }
  var DESSINS = {
    entete: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M7 15h6"/>',
    colonnes: '<rect x="3" y="5" width="4.6" height="14" rx="1"/><rect x="9.7" y="5" width="4.6" height="14" rx="1"/><rect x="16.4" y="5" width="4.6" height="14" rx="1"/>',
    bulle: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/><path d="M8.5 10.5h7M8.5 14h4.5"/>',
    clic: '<path d="M9.5 9.5l4.6 11 1.7-4.9 4.9-1.7z"/><path d="M7.5 2.5v2.5M2.5 7.5H5M3.9 3.9l1.8 1.8"/>',
    refaire: '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/>',
    plume: '<path d="M12 20h9"/><path d="M16.4 3.6a2.1 2.1 0 0 1 3 3L7.4 18.6 3 20l1.4-4.4z"/>',
    coche: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    croix: '<path d="M6 6l12 12M18 6L6 18"/>',
    suite: '<path d="M9 6l6 6-6 6"/>'
  };
  var CHEMIN_ASSETS = (window.mauticBasePath || '') + '/plugins/EwebAiBundle/Assets';

  var LANGUES = ['anglais', 'espagnol', 'allemand', 'italien', 'néerlandais'];

  /** Le libellé de la tuile de la bibliothèque (builder-composants.js). */
  var LIBELLE_TUILE = /sendly copilot|assistant ia/i;

  /** Ce que fait le clic sur la tuile, posé par l'éditeur ouvert. */
  var auClicTuile = null;

  /** Le clic vise-t-il la tuile du Copilot dans la bibliothèque ? */
  function tuileCopilot(cible) {
    var el = cible && cible.closest ? cible.closest('.gjs-block') : null;
    return !!(el && el.closest('.builder-panel') && LIBELLE_TUILE.test(el.textContent || ''));
  }

  // La TUILE au CLIC. La tuile est ajoutée à la bibliothèque par
  // builder-composants.js, dans SON « load », qui passe APRÈS celui de ce
  // fichier (EwebAiBundle est chargé avant EwebSaasBundle) : la chercher au
  // « load » ne trouvait donc rien, et le clic n'était jamais câblé (constat
  // en production du 27/11, après un premier correctif qui ne traitait que
  // le libellé). Le clic s'écoute sur le document et la tuile se reconnaît
  // AU MOMENT du clic : ni l'ordre des greffons ni un redessin de la
  // bibliothèque n'y changent rien.
  document.addEventListener('click', function (e) {
    if (auClicTuile && tuileCopilot(e.target)) { auClicTuile(); }
  }, true);

  // La MÊME étincelle que la tuile « Assistant IA » (identité visuelle
  // unique de l'IA — question proprio 12/08), en currentColor pour suivre
  // la couleur du contexte ; et une icône « langues » du même trait Lucide.
  var ICONE_IA = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:-2px"><path d="M9.1071 5.448C9.7051 3.698 12.1231 3.645 12.8321 5.289L12.8921 5.449L13.6991 7.809C13.884 8.35023 14.1829 8.84551 14.5755 9.26142C14.9682 9.67734 15.4454 10.0042 15.9751 10.22L16.1921 10.301L18.5521 11.107C20.3021 11.705 20.3551 14.123 18.7121 14.832L18.5521 14.892L16.1921 15.699C15.6507 15.8838 15.1552 16.1826 14.7391 16.5753C14.323 16.9679 13.996 17.4452 13.7801 17.975L13.6991 18.191L12.8931 20.552C12.2951 22.302 9.8771 22.355 9.1691 20.712L9.1071 20.552L8.3011 18.192C8.11628 17.6506 7.81748 17.1551 7.42485 16.739C7.03221 16.3229 6.5549 15.9959 6.0251 15.78L5.8091 15.699L3.4491 14.893C1.6981 14.295 1.6451 11.877 3.2891 11.169L3.4491 11.107L5.8091 10.301C6.35034 10.1161 6.84562 9.81719 7.26153 9.42457C7.67744 9.03195 8.00432 8.55469 8.2201 8.025L8.3011 7.809L9.1071 5.448Z"/></svg>';
  var ICONE_LANGUES = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/></svg>';

  // Le libellé écrit d'un outil de la mini-barre (deux pictogrammes muets
  // au bout de la barre native : constat du 26/11 dans l'éditeur).
  var LIBELLE_TB = '<span style="margin-left:5px;font-size:12px;font-weight:500;white-space:nowrap;vertical-align:1px">%s</span>';

  function endpoint() {
    return (window.SendlyAiConfig && window.SendlyAiConfig.endpoint)
      || (window.mauticBasePath || '') + '/s/ai/generate';
  }

  /** page | email-mjml | email-html — le formulaire hôte et le textarea
   *  MJML font foi (même lecture que builder-shell.js). Posé à l'init. */
  var MODE = 'page';
  function modeEditeur() {
    if (mQuery('form[name="page"]').length) { return 'page'; }
    var mjml = document.querySelector('textarea.builder-mjml');
    return mjml && mjml.value.length ? 'email-mjml' : 'email-html';
  }

  /** Les marqueurs d'interface (invite, barre) : un div sur le webpage, un
   *  <mj-raw> dans l'e-mail MJML — le seul enfant libre d'un mj-body. */
  function marqueur(nom) {
    var attr = 'invite' === nom ? 'data-sendly-invite="1"' : 'data-sendly-barre="1"';
    return 'email-mjml' === MODE
      ? '<mj-raw ' + attr + '></mj-raw>'
      : '<div ' + attr + '></div>';
  }

  function appelIa(corps) {
    if ('page' === MODE) {
      corps.format = 'html';
      corps.surface = 'page';
    } else {
      corps.format = 'email-mjml' === MODE ? 'mjml' : 'html';
      corps.surface = 'email-section';
      if ('generate' !== corps.mode) { corps.fragment = true; }
    }
    return new Promise(function (res, rej) {
      mQuery.ajax({
        url: endpoint(),
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(corps),
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).done(function (rep) {
        if (rep && rep.text) { res(rep.text); } else { rej(new Error('vide')); }
      }).fail(function (xhr) {
        rej(new Error(xhr && 503 === xhr.status ? 'desactive' : 'reseau'));
      });
    });
  }

  function messageErreur(err) {
    return 'desactive' === err.message
      ? 'Sendly Copilot n’est pas activé sur votre espace.'
      : 'La génération a échoué. Réessayez dans un instant.';
  }

  window.MauticGrapesJsPlugins.push({
    name: 'sendly-ai-page',
    context: ['page', 'email-mjml', 'email-html'],
    plugin: function (editor) {
      MODE = modeEditeur();
      function frameDoc() { var f = document.querySelector('.builder-panel .gjs-frame'); return f ? f.contentDocument : null; }
      function frameWin() { var f = document.querySelector('.builder-panel .gjs-frame'); return f ? f.contentWindow : null; }

      /** BOUCLIER : aucun événement souris/clavier de notre interface ne
       *  doit remonter à GrapesJS (re-diffusion des clics du canvas +
       *  raccourcis clavier globaux — leçons P4/P6). */
      function blinder(el) {
        ['mousedown', 'mouseup', 'click', 'dblclick', 'keydown', 'keyup', 'keypress'].forEach(function (t) {
          el.addEventListener(t, function (e) { e.stopPropagation(); });
        });
        return el;
      }

      /** Feuille de style de l'interface en place, injectée dans l'iframe. */
      function injecterStyles() {
        var idoc = frameDoc();
        if (!idoc || idoc.getElementById('sendly-ia-css')) { return; }
        var st = idoc.createElement('style');
        st.id = 'sendly-ia-css';
        var POLICE = '"Helvena", "Helvetica Neue", -apple-system, "Segoe UI", sans-serif';
        var VERRE_BLEU = 'linear-gradient(120deg, rgba(255,255,255,.10), rgba(255,255,255,.03) 40%, rgba(255,255,255,.08)), linear-gradient(180deg, #2E6BFF, #004FFF 70%, #0043E0)';
        st.textContent = ''
          // La famille du Copilot (refonte du 26/11) : Helvena, bleu, glacé,
          // perle, rayons 12 et 999. La police se charge DANS l'iframe.
          + '@font-face { font-family: "Helvena"; font-weight: 400; font-display: swap; src: url("' + CHEMIN_ASSETS + '/fonts/Helvena_Regular.woff2") format("woff2"); }'
          + '@font-face { font-family: "Helvena"; font-weight: 500; font-display: swap; src: url("' + CHEMIN_ASSETS + '/fonts/Helvena_Medium.woff2") format("woff2"); }'
          + '.sendly-ia-invite, .sendly-ia-squelette, .sendly-ia-barre, .sendly-ia-menu { font-family: ' + POLICE + '; font-size: 14px; font-weight: 400; line-height: normal; letter-spacing: -.005em; text-align: left; text-transform: none; -webkit-font-smoothing: antialiased; }'
          + '.sendly-ia-invite *, .sendly-ia-barre *, .sendly-ia-menu * { box-sizing: border-box; }'
          + '.sendly-ia-invite button, .sendly-ia-barre button, .sendly-ia-menu button { font-family: inherit; letter-spacing: inherit; text-transform: none; cursor: pointer; margin: 0; }'
          + '.sendly-ia-invite svg, .sendly-ia-barre svg, .sendly-ia-squelette svg { flex: none; display: block; }'
          + '.sendly-ia-invite { position: relative; border: 0; border-radius: 12px; background: #fff; padding: 14px; margin: 10px 2px; box-shadow: 0 0 0 1.5px #004FFF, 0 14px 34px rgba(0,79,255,.16); }'
          + '.sendly-ia-invite .ligne { display: flex; align-items: center; gap: 8px; }'
          + '.sendly-ia-invite input { flex: 1; min-width: 0; height: 48px; margin: 0; border: 1px solid transparent; background: #F5F7F9; border-radius: 999px; padding: 0 18px; font: 400 15px ' + POLICE + '; color: #000617; outline: none; box-shadow: none; }'
          + '.sendly-ia-invite input::placeholder { color: #5B6478; opacity: 1; }'
          + '.sendly-ia-invite input:focus { border-color: #004FFF; background: #fff; }'
          + '.sendly-ia-invite button.envoyer { flex: none; display: inline-flex; align-items: center; gap: 8px; height: 48px; border: 0; border-radius: 999px; padding: 0 18px; color: #fff; font-weight: 500; font-size: 13.5px; background: ' + VERRE_BLEU + '; box-shadow: 0 4px 12px rgba(0,79,255,.22), inset 0 1px 0 rgba(255,255,255,.3); }'
          + '.sendly-ia-invite .fermer { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; padding: 0; border: 0; border-radius: 50%; background: #F5F7F9; color: #001248; }'
          + '.sendly-ia-invite .fermer:hover { background: #EAF0FA; }'
          // Les idées en carrousel : une seule rangée qui défile, un fondu à
          // droite et une flèche pour avancer.
          + '.sendly-ia-invite .chips { display: flex; flex-wrap: nowrap; gap: 8px; margin-top: 12px; overflow-x: auto; scrollbar-width: none; scroll-snap-type: x proximity; padding-right: 60px; -webkit-mask-image: linear-gradient(90deg, #000 calc(100% - 84px), transparent calc(100% - 40px)); mask-image: linear-gradient(90deg, #000 calc(100% - 84px), transparent calc(100% - 40px)); }'
          + '.sendly-ia-invite .chips::-webkit-scrollbar { display: none; }'
          + '.sendly-ia-invite .chip { flex: none; display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; scroll-snap-align: start; border: 0; border-radius: 999px; background: #F5F7F9; color: #001248; font-size: 13.5px; font-weight: 500; padding: 7px 14px 7px 8px; }'
          + '.sendly-ia-invite .chip:hover { background: #EAF0FA; }'
          + '.sendly-ia-invite .chip .ico { flex: none; width: 24px; height: 24px; border-radius: 8px; background: #DFEAFF; color: #004FFF; display: inline-flex; align-items: center; justify-content: center; }'
          + '.sendly-ia-invite .suite { position: absolute; right: 14px; bottom: 14px; width: 36px; height: 36px; padding: 0; border-radius: 50%; border: 1px solid #D5DCED; background: #fff; color: #001248; display: inline-flex; align-items: center; justify-content: center; }'
          + '.sendly-ia-invite .suite:hover { border-color: #004FFF; color: #004FFF; }'
          + '.sendly-ia-invite.sans-suite .suite { display: none; }'
          + '.sendly-ia-invite.sans-suite .chips { padding-right: 0; -webkit-mask-image: none; mask-image: none; }'
          + '.sendly-ia-invite .erreur { color: #B42318; font-size: 13px; margin-top: 10px; }'
          + '.sendly-ia-invite :focus-visible, .sendly-ia-barre :focus-visible, .sendly-ia-menu :focus-visible { outline: 3px solid rgba(0,79,255,.45); outline-offset: 2px; }'
          /* Squelette DISCRET et adaptatif (capture proprio 13/08 : la carte
           * blanche à pointillés flashait comme un bloc vide sur les sections
           * à fond sombre). Teintes translucides : lisibles sur clair ET
           * foncé, et un libellé dit ce qui se passe. */
          + '.sendly-ia-squelette { border-radius: 12px; background: rgba(127,140,160,.10); padding: 18px 20px; margin: 10px 0; }'
          + '.sendly-ia-squelette .etat { display: flex; align-items: center; gap: 9px; font-size: 13.5px; font-weight: 500; color: rgba(127,140,160,.95); margin-bottom: 14px; }'
          + '.sendly-ia-squelette .etat svg { animation: sendly-respire 1.4s ease-in-out infinite; }'
          + '.sendly-ia-squelette .os { height: 10px; border-radius: 5px; margin-bottom: 11px; background: linear-gradient(90deg, rgba(127,140,160,.18) 30%, rgba(127,140,160,.38) 50%, rgba(127,140,160,.18) 70%); background-size: 200% 100%; animation: sendly-shimmer 1.2s linear infinite; }'
          + '@keyframes sendly-shimmer { to { background-position: -200% 0; } }'
          + '@keyframes sendly-respire { 0%, 100% { transform: scale(.88); opacity: .6; } 50% { transform: scale(1.08); opacity: 1; } }'
          // La barre : une pastille blanche, « Garder » en bleu (c'est le
          // geste attendu), le reste sans cadre.
          + '.sendly-ia-barre { display: inline-flex; flex-wrap: wrap; gap: 6px; align-items: center; border: 0; border-radius: 999px; background: #fff; padding: 6px; margin: 8px 2px; box-shadow: 0 12px 30px rgba(0,6,23,.18), 0 0 0 1px rgba(0,18,72,.07); }'
          + '.sendly-ia-barre button { display: inline-flex; align-items: center; gap: 7px; min-height: 36px; border: 0; background: none; color: #001248; border-radius: 999px; padding: 0 14px; font-size: 13.5px; font-weight: 500; white-space: nowrap; }'
          + '.sendly-ia-barre button:hover { background: #F5F7F9; }'
          + '.sendly-ia-barre button.garder { color: #fff; background-color: #004FFF; background-image: linear-gradient(120deg, rgba(255,255,255,.14), rgba(255,255,255,0) 45%), linear-gradient(180deg, #2E6BFF, #004FFF 60%, #0043E0); box-shadow: 0 4px 14px rgba(0,79,255,.20), inset 0 1px 0 rgba(255,255,255,.35); }'
          + '.sendly-ia-menu { position: absolute; z-index: 60; border: 0; border-radius: 12px; background: #fff; box-shadow: 0 16px 40px rgba(0,6,23,.2), 0 0 0 1px rgba(0,18,72,.07); padding: 6px; min-width: 190px; }'
          + '.sendly-ia-menu button { display: block; width: 100%; text-align: left; border: 0; background: none; padding: 9px 12px; font-size: 13.5px; color: #000617; border-radius: 8px; }'
          + '.sendly-ia-menu button:hover { background: #F5F7F9; color: #004FFF; }'
          + '.sendly-ia-menu .titre { font-size: 12.5px; color: #5B6478; padding: 8px 12px 4px; }'
          + '@media (prefers-reduced-motion: reduce) { .sendly-ia-squelette *, .sendly-ia-invite *, .sendly-ia-barre * { animation-duration: .01s !important; animation-iteration-count: 1 !important; scroll-behavior: auto !important; } }';
        idoc.head.appendChild(st);
      }

      /* ------------------------------------------------------------------ *
       *  LE BLOC DE SAISIE EN PLACE                                         *
       * ------------------------------------------------------------------ */

      /** Insère un composant-invite à `index` chez `parent`, monte la saisie
       *  dedans et rend le composant. valeurInitiale pré-remplit (Ajuster). */
      function ouvrirInvite(parent, index, valeurInitiale) {
        injecterStyles();
        var ajout = parent.append(marqueur('invite'), { at: index });
        var comp = ajout && ajout[0];
        if (!comp) { return; }
        comp.set({ editable: false, droppable: false, copyable: false, selectable: false, hoverable: false });
        var el = comp.view && comp.view.el;
        if (!el) { comp.remove(); return; }
        monterSaisie(comp, el, valeurInitiale || '');
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }

      function monterSaisie(comp, el, valeur) {
        var idoc = frameDoc();
        el.innerHTML = '';
        var boite = blinder(idoc.createElement('div'));
        boite.className = 'sendly-ia-invite';
        boite.innerHTML = '<div class="ligne"><input type="text" aria-label="Section à générer" placeholder="Décrivez la section à générer…">'
          + '<button type="button" class="envoyer">' + ICONE_IA + '<span>Générer</span></button>'
          + '<button type="button" class="fermer" aria-label="Fermer" title="Fermer">' + dessin(DESSINS.croix, 14, 2.4) + '</button></div>'
          + '<div class="chips"></div>'
          + '<button type="button" class="suite" aria-label="Voir d’autres idées">' + dessin(DESSINS.suite, 16, 2.2) + '</button>';
        var champ = boite.querySelector('input');
        champ.value = valeur;
        var chips = boite.querySelector('.chips');
        RACCOURCIS.forEach(function (r) {
          var c = idoc.createElement('button');
          c.type = 'button';
          c.className = 'chip';
          c.innerHTML = '<span class="ico">' + dessin(DESSINS[r[0]], 14) + '</span><span></span>';
          c.lastChild.textContent = r[1];
          c.addEventListener('click', function () { champ.value = r[2]; lancer(); });
          chips.appendChild(c);
        });
        // La flèche avance d'une vue ; au bout, elle revient au début.
        boite.querySelector('.suite').addEventListener('click', function () {
          var fin = chips.scrollLeft + chips.clientWidth >= chips.scrollWidth - 4;
          chips.scrollTo({ left: fin ? 0 : chips.scrollLeft + Math.round(chips.clientWidth * 0.7), behavior: 'smooth' });
        });
        function lancer() {
          var consigne = champ.value.trim();
          if (!consigne) { champ.focus(); return; }
          generer(comp, consigne);
        }
        boite.querySelector('.envoyer').addEventListener('click', lancer);
        champ.addEventListener('keydown', function (e) { if ('Enter' === e.key) { lancer(); } });
        boite.querySelector('.fermer').addEventListener('click', function () { comp.remove(); });
        el.appendChild(boite);
        // Toutes les idées tiennent sur la rangée (canevas large) : ni flèche
        // ni fondu, il n'y a rien d'autre à voir.
        if (chips.scrollWidth <= chips.clientWidth + 2) { boite.classList.add('sans-suite'); }
        setTimeout(function () { champ.focus(); }, 60);
      }

      /** Remplace l'invite par le squelette, appelle l'IA, insère la
       *  section coiffée de sa barre contextuelle. */
      function generer(compInvite, consigne) {
        var idoc = frameDoc();
        var el = compInvite.view && compInvite.view.el;
        if (el) {
          el.innerHTML = '';
          var sq = blinder(idoc.createElement('div'));
          sq.className = 'sendly-ia-squelette';
          sq.setAttribute('role', 'status');
          sq.innerHTML = '<div class="etat">' + ICONE_IA + '<span>Génération en cours…</span></div>'
            + '<div class="os" style="width:70%"></div><div class="os" style="width:50%"></div><div class="os" style="width:35%; margin-bottom:0"></div>';
          el.appendChild(sq);
        }
        return appelIa({ mode: 'generate', instruction: consigne }).then(function (html) {
          var parent = compInvite.parent();
          var index = compInvite.index();
          if (!parent) { return; }
          var ajoutes = parent.append(html, { at: index });
          compInvite.remove();
          poserBarre(parent, index, ajoutes, consigne);
        }).catch(function (err) {
          var elv = compInvite.view && compInvite.view.el;
          if (elv) { monterSaisie(compInvite, elv, consigne); var e2 = idoc.createElement('div'); e2.className = 'erreur'; e2.textContent = messageErreur(err); elv.querySelector('.sendly-ia-invite').appendChild(e2); }
          throw err;
        });
      }

      /* ------------------------------------------------------------------ *
       *  LA BARRE CONTEXTUELLE  Régénérer / Ajuster / Garder / Annuler      *
       * ------------------------------------------------------------------ */

      function poserBarre(parent, index, sections, consigne) {
        injecterStyles();
        var ajout = parent.append(marqueur('barre'), { at: index });
        var compBarre = ajout && ajout[0];
        if (!compBarre) { return; }
        compBarre.set({ editable: false, droppable: false, copyable: false, selectable: false, hoverable: false });
        var el = compBarre.view && compBarre.view.el;
        if (!el) { compBarre.remove(); return; }
        var idoc = frameDoc();
        el.innerHTML = '';
        var barre = blinder(idoc.createElement('div'));
        barre.className = 'sendly-ia-barre';
        barre.innerHTML = '<button type="button" class="regenerer">' + dessin(DESSINS.refaire, 14) + '<span>Régénérer</span></button>'
          + '<button type="button" class="ajuster">' + dessin(DESSINS.plume, 14) + '<span>Ajuster</span></button>'
          + '<button type="button" class="annuler" title="Supprimer la section">Annuler</button>'
          + '<button type="button" class="garder">' + dessin(DESSINS.coche, 14, 2.6) + '<span>Garder</span></button>';
        function retirerSections() { (sections || []).forEach(function (c) { if (c && c.remove) { c.remove(); } }); }
        barre.querySelector('.garder').addEventListener('click', function () { compBarre.remove(); });
        barre.querySelector('.annuler').addEventListener('click', function () { retirerSections(); compBarre.remove(); });
        barre.querySelector('.regenerer').addEventListener('click', function () {
          var p = compBarre.parent();
          var i = compBarre.index();
          retirerSections();
          compBarre.remove();
          if (p) {
            var inv = p.append(marqueur('invite'), { at: i });
            if (inv && inv[0]) { inv[0].set({ editable: false, droppable: false, copyable: false, selectable: false, hoverable: false }); generer(inv[0], consigne); }
          }
        });
        barre.querySelector('.ajuster').addEventListener('click', function () {
          var p = compBarre.parent();
          var i = compBarre.index();
          retirerSections();
          compBarre.remove();
          if (p) { ouvrirInvite(p, i, consigne); }
        });
        el.appendChild(barre);
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }

      /* ------------------------------------------------------------------ *
       *  AMÉLIORER / TRADUIRE — mini-barre du composant sélectionné         *
       * ------------------------------------------------------------------ */

      function retoucher(mode, lang) {
        var sel = editor.getSelected();
        if (!sel || !sel.view || !sel.view.el) { return; }
        var avant = sel.components().map(function (c) { return c.toHTML(); }).join('');
        // E-mail : on retouche le CONTENU du bloc (fragment), le mj-text
        // garde ses attributs ; webpage : comportement d'origine.
        var contenu = 'page' === MODE ? sel.toHTML() : avant;
        var corps = { mode: mode, instruction: '', content: contenu };
        if (lang) { corps.lang = lang; }
        appelIa(corps).then(function (html) {
          sel.components(html);
          poserBarreRetouche(sel, avant);
        }).catch(function (err) {
          editor.log(messageErreur(err), { level: 'error' });
        });
      }

      /** Après une retouche : petite barre Garder / Annuler sous le composant. */
      function poserBarreRetouche(comp, avant) {
        var parent = comp.parent();
        if (!parent) { return; }
        poserBarreSimple(parent, comp.index() + 1, function annule() { comp.components(avant); });
      }

      function poserBarreSimple(parent, index, surAnnule) {
        injecterStyles();
        var ajout = parent.append(marqueur('barre'), { at: index });
        var compBarre = ajout && ajout[0];
        if (!compBarre) { return; }
        compBarre.set({ editable: false, droppable: false, copyable: false, selectable: false, hoverable: false });
        var el = compBarre.view && compBarre.view.el;
        if (!el) { compBarre.remove(); return; }
        var idoc = frameDoc();
        var barre = blinder(idoc.createElement('div'));
        barre.className = 'sendly-ia-barre';
        barre.innerHTML = '<button type="button" class="annuler">Annuler</button>'
          + '<button type="button" class="garder">' + dessin(DESSINS.coche, 14, 2.6) + '<span>Garder</span></button>';
        barre.querySelector('.garder').addEventListener('click', function () { compBarre.remove(); });
        barre.querySelector('.annuler').addEventListener('click', function () { surAnnule(); compBarre.remove(); });
        el.innerHTML = '';
        el.appendChild(barre);
      }

      /** Menu des langues, ancré près de l'élément du composant. */
      function ouvrirMenuTraduction(comp) {
        injecterStyles();
        var idoc = frameDoc();
        var iwin = frameWin();
        var ancien = idoc.querySelector('.sendly-ia-menu');
        if (ancien) { ancien.remove(); }
        var menu = blinder(idoc.createElement('div'));
        menu.className = 'sendly-ia-menu';
        var titre = idoc.createElement('div');
        titre.className = 'titre';
        titre.textContent = 'Traduire en…';
        menu.appendChild(titre);
        LANGUES.forEach(function (l) {
          var b = idoc.createElement('button');
          b.textContent = l.charAt(0).toUpperCase() + l.slice(1);
          b.addEventListener('click', function () { menu.remove(); retoucher('translate', l); });
          menu.appendChild(b);
        });
        var r = comp.view.el.getBoundingClientRect();
        menu.style.left = Math.max(8, r.left) + 'px';
        menu.style.top = (r.bottom + iwin.scrollY + 6) + 'px';
        idoc.body.appendChild(menu);
        setTimeout(function () {
          idoc.addEventListener('mousedown', function fermer() { menu.remove(); idoc.removeEventListener('mousedown', fermer); });
        }, 50);
      }

      /** Ajoute Améliorer (étincelle) et Traduire (langues) à la mini-barre. */
      function equiperMiniBarre(comp) {
        // En teaser, la mini-barre reste NUE : la tuile est le point
        // d'entrée du teaser, pas besoin de verrous partout.
        if (window.SendlyAiConfig && window.SendlyAiConfig.teaser) { return; }
        if (!comp || ('text' !== comp.get('type') && 'mj-text' !== comp.get('type'))) { return; }
        var barre = comp.get('toolbar') || [];
        if (barre.some(function (b) { return 'sendly-ia-ameliorer' === b.command; })) { return; }
        comp.set('toolbar', barre.concat([
          { attributes: { class: 'sendly-tb-ia', title: 'Améliorer avec Sendly Copilot' }, command: 'sendly-ia-ameliorer', label: ICONE_IA + LIBELLE_TB.replace('%s', 'Améliorer') },
          { attributes: { class: 'sendly-tb-ia', title: 'Traduire' }, command: 'sendly-ia-traduire', label: ICONE_LANGUES + LIBELLE_TB.replace('%s', 'Traduire') },
        ]));
      }

      /** Où ouvrir l'invite : après la sélection sur le webpage ; dans
       *  l'e-mail MJML, au niveau des SECTIONS (on remonte de la sélection au
       *  premier enfant direct du mj-body / mj-wrapper), sinon en fin de
       *  corps. */
      function cibleSection(comp) {
        var cur = comp;
        while (cur && cur.parent && cur.parent()) {
          var p = cur.parent();
          var t = p.get('type');
          if ('mj-body' === t || 'mj-wrapper' === t) { return { parent: p, index: cur.index() + 1 }; }
          cur = p;
        }
        return null;
      }
      /** Le conteneur où vivent les SECTIONS : celui de la dernière
       *  mj-section existante, sinon le mj-body. ⚠️ Par TYPE (findType),
       *  jamais par sélecteur : dans le canevas les balises mj-* sont
       *  rendues en div, un sélecteur `mj-body` ne trouve rien et la section
       *  partait À CÔTÉ de <mjml> — MJML malformé, builder infermable
       *  (recette proprio 09/09). */
      function corpsMjml() {
        var w = editor.getWrapper();
        var sections = w.findType('mj-section');
        var derniere = sections[sections.length - 1];
        if (derniere && derniere.parent()) { return derniere.parent(); }
        return w.findType('mj-body')[0] || w;
      }
      function ouvrirApres(sel, valeur) {
        if ('email-mjml' === MODE) {
          var c = sel ? cibleSection(sel) : null;
          if (c) { ouvrirInvite(c.parent, c.index, valeur || ''); return; }
          var b = corpsMjml();
          ouvrirInvite(b, b.components().length, valeur || '');
          return;
        }
        if (sel && sel.parent()) { ouvrirInvite(sel.parent(), sel.index() + 1, valeur || ''); }
        else { ouvrirInvite(editor.getWrapper(), editor.getWrapper().components().length, valeur || ''); }
      }
      function contexteUpsell() { return 'page' === MODE ? 'page' : 'email'; }

      // La TUILE au CLIC : invite après la sélection, sinon en fin de page.
      // Posé dès la création de l'éditeur, sans attendre la tuile (l'écoute
      // du clic est en tête de fichier, et dit pourquoi).
      auClicTuile = function () {
        if (window.SendlyAiConfig && window.SendlyAiConfig.teaser) {
          if (window.SendlyAiUpsell) { window.SendlyAiUpsell.ouvrir(contexteUpsell()); }
          return;
        }
        // Une invite déjà ouverte : on y revient, on n'en empile pas une autre.
        var idoc = frameDoc();
        var ouverte = idoc ? idoc.querySelector('.sendly-ia-invite input') : null;
        if (ouverte) {
          ouverte.scrollIntoView({ block: 'center', behavior: 'smooth' });
          ouverte.focus();
          return;
        }
        ouvrirApres(editor.getSelected(), '');
      };

      /* ------------------------------------------------------------------ *
       *  CÂBLAGE                                                            *
       * ------------------------------------------------------------------ */

      editor.Commands.add('sendly-ia-ameliorer', { run: function () { retoucher('improve'); } });
      editor.Commands.add('sendly-ia-traduire', { run: function (ed) { var s = ed.getSelected(); if (s) { ouvrirMenuTraduction(s); } } });

      editor.on('component:selected', equiperMiniBarre);

      editor.on('load', function () {
        injecterStyles();

        // ── Relais de l'assistant global (lot 2, 27/08) : « Crée-moi une
        //    landing page » — l'écran formulaire a nommé la page, choisi le
        //    thème et ouvert le builder ; ICI on consomme le brief et on
        //    génère les sections du plan, l'une après l'autre, chacune avec
        //    sa barre Régénérer / Ajuster / Garder. Une section en échec
        //    remonte son invite pré-remplie et la suite continue.
        var briefPage = null;
        try {
          var brutPage = sessionStorage.getItem('sendlyAiPageBrief');
          if (brutPage) {
            sessionStorage.removeItem('sendlyAiPageBrief');
            briefPage = JSON.parse(brutPage);
          }
        } catch (e) { briefPage = null; }
        if (briefPage && (!briefPage.ts || Date.now() - briefPage.ts > 180000)) { briefPage = null; }
        if ('page' === MODE && briefPage && Array.isArray(briefPage.sections) && briefPage.sections.length
            && !(window.SendlyAiConfig && window.SendlyAiConfig.teaser)) {
          // La page naît d'un brief : toile VIERGE d'abord — le thème blank
          // sème un bloc d'accueil (« Hello there!… ») qui restait en tête
          // au-dessus des sections générées (recette du lot 2).
          try { editor.DomComponents.clear(); } catch (e) { /* toile déjà vide */ }
          var suite = Promise.resolve();
          // La pastille du Copilot compte les sections, sans rien couvrir.
          var aFaire = briefPage.sections.slice(0, 6);
          var SA = window.SendlyAssistant;
          aFaire.forEach(function (consigneSection, rang) {
            suite = suite.then(function () {
              if (SA && typeof SA.etape === 'function') { SA.etape('Création de la page', rang + 1, aFaire.length); }
              var w = editor.getWrapper();
              var ajout = w.append(marqueur('invite'), { at: w.components().length });
              var inv = ajout && ajout[0];
              if (!inv) { return; }
              inv.set({ editable: false, droppable: false, copyable: false, selectable: false, hoverable: false });
              return generer(inv, String(consigneSection));
            }).catch(function () { /* l'invite en échec reste en place — on continue */ });
          });
          // Compteur de temps gagné : à la FIN de la composition.
          suite = suite.then(function () {
            if (window.SendlyAssistant) {
              window.SendlyAssistant.credit('create_landing_page', 1);
              if (typeof window.SendlyAssistant.termine === 'function') {
                window.SendlyAssistant.termine('Page prête, en brouillon', {
                  type: 'create_landing_page',
                  etapes: aFaire.map(function (x) { return String(x).slice(0, 60); })
                });
              }
            }
          });
        }

        // La TUILE au DÉPÔT : l'invite s'ouvre À L'ENDROIT du dépôt.
        editor.on('block:drag:stop', function (composant, bloc) {
          if (!bloc || 'sendly-ia' !== bloc.get('id')) { return; }
          var premiers = (Array.isArray(composant) ? composant : [composant]).filter(Boolean);
          var repere = premiers[0];
          var parent = repere && repere.parent ? repere.parent() : null;
          var index = repere && repere.index ? repere.index() : 0;
          // E-mail MJML : un dépôt dans une colonne remonte au niveau des
          // sections — une section ne vit pas dans une colonne.
          if ('email-mjml' === MODE && repere) {
            var cible = cibleSection(repere);
            if (cible) { parent = cible.parent; index = cible.index - 1; }
          }
          premiers.forEach(function (c) { if (c && c.remove) { c.remove(); } });
          if (window.SendlyAiConfig && window.SendlyAiConfig.teaser) {
            if (window.SendlyAiUpsell) { window.SendlyAiUpsell.ouvrir(contexteUpsell()); }
            return;
          }
          if (parent) { ouvrirInvite(parent, index, ''); }
          else { ouvrirApres(null, ''); }
        });

        // Les résidus d'interface ne survivent JAMAIS à une sauvegarde :
        // invites et barres sont purgées du HTML exporté.
        var exportOrig = editor.getHtml.bind(editor);
        editor.getHtml = function (opts) {
          var html = exportOrig(opts);
          return html.replace(/<(div|mj-raw) data-sendly-(?:invite|barre)="1">[\s\S]*?<\/\1>/g, '');
        };
      });
    },
  });

  /* ─── Relais côté FORMULAIRE : /s/pages/new ────────────────────────────
   * L'assistant global a déposé un brief : on nomme la page, on choisit un
   * thème (« blank » de préférence — le bouton Builder est désactivé tant
   * qu'aucun thème n'est choisi) et on ouvre le builder. Le brief n'est
   * consommé QUE dans le builder (étage ci-dessus) ; ici on ne fait que le
   * lire — et on le purge s'il est périmé (3 minutes). */
  mQuery(function () {
    var brut = null;
    try { brut = sessionStorage.getItem('sendlyAiPageBrief'); } catch (e) {}
    if (!brut) { return; }
    var brief = null;
    try { brief = JSON.parse(brut); } catch (e) {}
    if (!brief || !brief.ts || Date.now() - brief.ts > 180000) {
      try { sessionStorage.removeItem('sendlyAiPageBrief'); } catch (e) {}
      return;
    }
    // L'activation IA se vérifie DANS la boucle : sur un chargement complet
    // lent, SendlyAiConfig arrive parfois APRÈS le ready — purger ici était
    // une course qui jetait le brief en silence (constaté 28/08 : thème posé
    // par défaut, titre vide, builder jamais ouvert).
    var essais = 0;
    var minuterie = setInterval(function () {
      essais += 1;
      if (essais > 40) { clearInterval(minuterie); return; }
      if (!/\/s\/pages\/new/.test(window.location.pathname)) { return; }
      if (!(window.SendlyAiConfig && window.SendlyAiConfig.enabled)) { return; }
      var $titre = mQuery('#page_title');
      var $theme = mQuery('#page_template');
      if (!$titre.length || !$theme.length || 'function' !== typeof window.Mautic.launchBuilder) { return; }
      clearInterval(minuterie);
      if (!$titre.val()) {
        $titre.val(brief.name || '').trigger('change').trigger('keyup');
      }
      if (!$theme.val()) {
        var v = $theme.find('option[value="blank"]').length ? 'blank'
          : $theme.find('option').filter(function () { return '' !== this.value; }).first().val();
        if (v) { $theme.val(v).trigger('change').trigger('chosen:updated'); }
      }
      // Petit délai : laisser le formulaire digérer le thème avant le builder.
      setTimeout(function () { window.Mautic.launchBuilder('page'); }, 500);
    }, 500);
  });
})();
