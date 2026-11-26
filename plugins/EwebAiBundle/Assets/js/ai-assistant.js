/**
 * Sendly Copilot : la COQUILLE UNIQUE (directive proprio 07/08), dans sa
 * direction « Pastille » (refonte validée par le propriétaire le 26/11/2026).
 *
 * Le bouton : au repos un rond, l'icône seule sur un fond qui vit. Il ne
 * s'allonge en pastille que pour dire un état (« Création de l'e-mail · 3 sur
 * 5 ») ou porter un reçu (« Segment préparé · Annuler »). La carte s'ancre en
 * bas à droite et prend la hauteur de ce qu'elle a à dire : l'écran reste
 * visible. Chaque action rend un REÇU (réussite, échec, annulé) ; ce que le
 * Copilot a touché s'allume dans la page ; la conversation suit d'un écran à
 * l'autre. Au téléphone, la carte est une feuille du bas.
 *
 * UN seul assistant sur toute la plateforme : même design, même structure,
 * même lanceur flottant partout. Seul le CONTENU s'adapte à l'onglet courant —
 * titre, message d'accueil, raccourcis, et ce que l'assistant sait FAIRE.
 * Chaque écran capable dépose un « contexte » dans le registre
 * window.SendlyAssistantContexts ; l'aide générale (posée ici même) est le
 * contexte par défaut, toujours disponible. Le registre est consulté au clic
 * et à chaque navigation ajax : rien à ré-enregistrer.
 *
 * Contrat d'un contexte :
 *   {
 *     id: 'segment',            // identifiant stable (conversation par id)
 *     priority: 10,             // le plus haut disponible gagne (défaut = 0)
 *     available(): bool,        // suis-je pertinent sur l'écran courant ?
 *     title(): string,          // titre du panneau ET info-bulle du lanceur
 *     hero()?: string,          // titre de l'accueil, deux lignes (« \n ») ;
 *                               // à défaut, welcome()
 *     welcome(): string,        // message d'accueil (texte brut)
 *     placeholder(): string,    // placeholder du champ de saisie
 *     thinking(): string,       // libellé d'attente (« Analyse… »)
 *     shortcuts(): Array,       // raccourcis : une phrase, ou [libellé court
 *                               // (36 car. au plus), demande, type d'action]
 *     onSend(text, api),        // traite UN tour ; api : voir plus bas
 *     onUndo(turnId, api)?      // annulation d'un tour (si le contexte en pose)
 *   }
 * L'api passée à onSend : { history(n), ia(html, extra), note(tour, texte),
 * finish(), fail(msg), arrete() }
 * — ia() pousse un tour assistant (html DÉJÀ échappé par le contexte ; extra
 * = {turnId, undoable} pour poser un bouton « Annuler les modifications »).
 * L'api passée à onUndo : { markUndone(noteHtml) }.
 *
 * ⚠️ MÊMES PIÈGES QUE TOUTES LES SURFACES IA :
 *  1. mQuery.ajax, JAMAIS fetch() — Mautic intercepte tout POST XHR vers /s/
 *     sans jeton CSRF ; mQuery.ajax porte le jeton automatiquement.
 *  2. Surface GATÉE par la clé : sans window.SendlyAiConfig, ni lanceur ni
 *     panneau — le fichier est agrégé mais inerte.
 */
(function () {
  'use strict';

  function cfg() {
    var c = window.SendlyAiConfig;
    return c && c.enabled ? c : null;
  }

  function t(key, fallback) {
    var s = Mautic.translate(key);
    return !s || s === key ? fallback : s;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /** Rendu SÛR des réponses d'aide : tout est échappé d'abord, puis un
   *  balisage LÉGER est reconstruit — paragraphes, listes ordonnées ou à
   *  puces, **gras**, et les chemins d'interface « A → B » en pastilles.
   *  Aucun HTML du modèle n'est jamais interprété. */
  function rendreAide(texte) {
    var brut = String(texte == null ? '' : texte).replace(/\r\n/g, '\n').trim();
    var html = '';
    var liste = null;
    var para = [];
    function enLigne(l) {
      var s = esc(l);
      s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      s = s.replace(/«\s*([^»]*?→[^»]*?)\s*»/g, function (tout, chemin) {
        return '<span class="sendly-assist-chemin">' + chemin.trim() + '</span>';
      });
      return s;
    }
    function fermeListe() {
      if (liste) { html += '</' + liste + '>'; liste = null; }
    }
    function videPara() {
      if (para.length) { html += '<p>' + para.map(enLigne).join('<br>') + '</p>'; para = []; }
    }
    brut.split('\n').forEach(function (l) {
      var ligne = l.trim();
      if (!ligne) { videPara(); fermeListe(); return; }
      var num = ligne.match(/^\d+[.)]\s+(.*)$/);
      var puce = ligne.match(/^[-•]\s+(.*)$/);
      if (num || puce) {
        videPara();
        var type = num ? 'ol' : 'ul';
        if (liste !== type) { fermeListe(); html += '<' + type + '>'; liste = type; }
        html += '<li>' + enLigne((num || puce)[1]) + '</li>';
        return;
      }
      fermeListe();
      para.push(ligne);
    });
    videPara();
    fermeListe();
    return html || '<p></p>';
  }

  // ── Dessins : tout est tracé, aucune police d'icônes dans la coquille ───

  var ECLAT = 'M9.1071 5.448C9.7051 3.698 12.1231 3.645 12.8321 5.289L12.8921 5.449L13.6991 7.809C13.884 8.35023 14.1829 8.84551 14.5755 9.26142C14.9682 9.67734 15.4454 10.0042 15.9751 10.22L16.1921 10.301L18.5521 11.107C20.3021 11.705 20.3551 14.123 18.7121 14.832L18.5521 14.892L16.1921 15.699C15.6507 15.8838 15.1552 16.1826 14.7391 16.5753C14.323 16.9679 13.996 17.4452 13.7801 17.975L13.6991 18.191L12.8931 20.552C12.2951 22.302 9.8771 22.355 9.1691 20.712L9.1071 20.552L8.3011 18.192C8.11628 17.6506 7.81748 17.1551 7.42485 16.739C7.03221 16.3229 6.5549 15.9959 6.0251 15.78L5.8091 15.699L3.4491 14.893C1.6981 14.295 1.6451 11.877 3.2891 11.169L3.4491 11.107L5.8091 10.301C6.35034 10.1161 6.84562 9.81719 7.26153 9.42457C7.67744 9.03195 8.00432 8.55469 8.2201 8.025L8.3011 7.809L9.1071 5.448ZM19.0001 2C19.1872 2 19.3705 2.05248 19.5293 2.15147C19.688 2.25046 19.8158 2.392 19.8981 2.56L19.9461 2.677L20.2961 3.703L21.3231 4.053C21.5106 4.1167 21.6749 4.23462 21.7953 4.39182C21.9157 4.54902 21.9867 4.73842 21.9994 4.93602C22.012 5.13362 21.9657 5.33053 21.8663 5.50179C21.7669 5.67304 21.6189 5.81094 21.4411 5.898L21.3231 5.946L20.2971 6.296L19.9471 7.323C19.8833 7.51043 19.7653 7.6747 19.608 7.79499C19.4508 7.91529 19.2613 7.98619 19.0637 7.99872C18.8662 8.01125 18.6693 7.96484 18.4981 7.86538C18.3269 7.76591 18.1891 7.61787 18.1021 7.44L18.0541 7.323L17.7041 6.297L16.6771 5.947C16.4896 5.8833 16.3253 5.76538 16.2049 5.60819C16.0845 5.45099 16.0135 5.26158 16.0008 5.06398C15.9882 4.86638 16.0345 4.66947 16.1339 4.49821C16.2333 4.32696 16.3813 4.18906 16.5591 4.102L16.6771 4.054L17.7031 3.704L18.0531 2.677C18.1205 2.47943 18.2481 2.30791 18.4179 2.1865C18.5878 2.06509 18.7913 1.99987 19.0001 2Z';

  function eclat(taille) {
    return '<svg class="sendly-assist-eclat" viewBox="0 0 24 24" width="' + taille + '" height="' + taille +
      '" fill="currentColor" aria-hidden="true"><path d="' + ECLAT + '"></path></svg>';
  }

  var TRACES = {
    coche: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    croix: '<path d="M6 6l12 12M18 6L6 18"/>',
    haut: '<path d="M12 19V5M5.5 11.5L12 5l6.5 6.5"/>',
    retour: '<path d="M9 14L4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
    corbeille: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    contacts: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    lettre: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    formulaire: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
    rapport: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    page: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M7 15h6"/>',
    campagne: '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M6 8.5v4a3.5 3.5 0 0 0 3.5 3.5h6"/>',
    ouvrir: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    horloge: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ab: '<rect x="3" y="5" width="7" height="14" rx="1.5"/><rect x="14" y="5" width="7" height="14" rx="1.5"/>'
  };

  function trace(nom, taille, epaisseur) {
    return '<svg viewBox="0 0 24 24" width="' + taille + '" height="' + taille + '" fill="none" stroke="currentColor" stroke-width="' +
      (epaisseur || 2) + '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + TRACES[nom] + '</svg>';
  }

  /** Le même tracé en image de fond (le signe d'un reçu garde son caractère
   *  « ✓ » ou « ✗ » dans le texte, pour les lecteurs d'écran et pour les
   *  tests ; c'est le dessin qui se voit). */
  function fondTrace(nom, couleur, epaisseur) {
    return 'url("data:image/svg+xml,' + encodeURIComponent(
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="' + couleur + '" stroke-width="' +
      (epaisseur || 2.6) + '" stroke-linecap="round" stroke-linejoin="round">' + TRACES[nom] + '</svg>') + '")';
  }

  // ── Feuille de style : la direction « Pastille » (validée le 26/11/2026) ──
  // Tout est préfixé par la carte ou le bouton : la coquille ne compte sur
  // aucun style de l'écran qui l'accueille, et ne lui en impose aucun.

  var CHEMIN_ASSETS = (window.mauticBasePath || '') + '/plugins/EwebAiBundle/Assets';

  function ensureStyles() {
    if (document.getElementById('sendly-assist-style')) {
      return;
    }
    var P = '#sendly-assist-panel';
    var F = '#sendly-assist-fab';
    var NUIT = '#001248', GLACE = '#DFEAFF', ENCRE = '#000617', PERLE = '#F5F7F9', GRIS = '#5B6478', ROUGE = '#B42318';
    var COURBE = 'cubic-bezier(.22,.9,.3,1)';
    var VERRE = 'linear-gradient(120deg,rgba(255,255,255,.10),rgba(255,255,255,.03) 40%,rgba(255,255,255,.08))';
    var PLEIN = 'linear-gradient(180deg,#2E6BFF,#004FFF 70%,#0043E0)';
    var LISERE = 'background:linear-gradient(130deg,rgba(255,255,255,.75),rgba(255,255,255,.08) 35%,rgba(255,255,255,.06) 62%,rgba(255,255,255,.55));' +
      '-webkit-mask:linear-gradient(#fff 0 0) content-box,linear-gradient(#fff 0 0);-webkit-mask-composite:xor;mask-composite:exclude;pointer-events:none';
    var POLICE = '"Helvena","Helvetica Neue",-apple-system,"Segoe UI",sans-serif';
    var css = [
      '@font-face{font-family:"Helvena";font-weight:300;font-display:swap;src:url("' + CHEMIN_ASSETS + '/fonts/Helvena_Light.woff2") format("woff2")}',
      '@font-face{font-family:"Helvena";font-weight:400;font-display:swap;src:url("' + CHEMIN_ASSETS + '/fonts/Helvena_Regular.woff2") format("woff2")}',
      '@font-face{font-family:"Helvena";font-weight:500;font-display:swap;src:url("' + CHEMIN_ASSETS + '/fonts/Helvena_Medium.woff2") format("woff2")}',
      '@keyframes sendly-assist-monte{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}',
      '@keyframes sendly-assist-fondu{from{opacity:0}to{opacity:1}}',
      '@keyframes sendly-assist-tourne{to{transform:rotate(360deg)}}',
      '@keyframes sendly-assist-respire{0%,100%{transform:scale(.88);opacity:.6}50%{transform:scale(1.08);opacity:1}}',
      '@keyframes sendly-assist-luit{to{background-position:-200% 0}}',
      '@keyframes sendly-assist-coule{from{background-position:0% 0}to{background-position:-300% 0}}',
      '@keyframes sendly-assist-halo{0%{box-shadow:0 0 0 2px rgba(0,79,255,0),0 0 0 7px rgba(0,79,255,0)}' +
        '6%,72%{box-shadow:0 0 0 2px #004FFF,0 0 0 7px rgba(0,79,255,.16)}100%{box-shadow:0 0 0 1.5px rgba(0,79,255,.55),0 0 0 0 rgba(0,79,255,0)}}',

      // ── Le bouton : au repos un rond de 56 px, l'icône seule sur un fond
      //    qui vit (retour du propriétaire, 25/11). Il ne s'allonge en
      //    pastille que pour dire un état ou porter un reçu.
      F + '{position:fixed;right:24px;bottom:24px;z-index:1035;box-sizing:border-box;display:inline-flex;align-items:center;gap:10px;' +
        'min-height:48px;padding:0 22px 0 18px;border:0;border-radius:999px;color:#fff;cursor:pointer;white-space:nowrap;' +
        'font:500 14.5px/1 ' + POLICE + ';letter-spacing:-.005em;-webkit-font-smoothing:antialiased;' +
        'background:' + VERRE + ',' + PLEIN + ';box-shadow:0 10px 26px rgba(0,6,23,.28),inset 0 1px 0 rgba(255,255,255,.18);' +
        'transform-origin:100% 100%;transition:transform .18s ' + COURBE + ',box-shadow .18s ease,opacity .16s ease;' +
        '-webkit-user-select:none;user-select:none;-webkit-tap-highlight-color:transparent}',
      F + ' *{box-sizing:border-box}',
      F + ' svg{flex:none;display:block}',
      F + '::before{content:"";position:absolute;inset:0;border-radius:inherit;padding:1px;' + LISERE + '}',
      F + ':hover{transform:translateY(-1px);box-shadow:0 14px 32px rgba(0,6,23,.34),inset 0 1px 0 rgba(255,255,255,.22)}',
      F + ':active{transform:scale(.97)}',
      F + ':focus-visible{outline:3px solid rgba(0,79,255,.45);outline-offset:3px}',
      F + '.sendly-assist-parti{opacity:0;transform:scale(.92);pointer-events:none}',
      F + '.sendly-assist-rond{width:56px;height:56px;min-height:56px;padding:0;justify-content:center;border-radius:50%;overflow:hidden;isolation:isolate;' +
        'background:#004FFF;box-shadow:0 12px 28px rgba(0,18,72,.36),inset 0 1px 0 rgba(255,255,255,.24)}',
      F + '.sendly-assist-rond:hover{transform:scale(1.06);box-shadow:0 16px 34px rgba(0,18,72,.42),inset 0 1px 0 rgba(255,255,255,.28)}',
      F + '.sendly-assist-rond:active{transform:scale(.95)}',
      F + ' .sendly-assist-vivant{position:absolute;inset:-40%;z-index:-1;border-radius:50%;filter:blur(8px);' +
        'background:conic-gradient(from 0deg,#001248 0%,#004FFF 20%,#7BA6FF 38%,#2E6BFF 52%,#004FFF 66%,#0036C7 82%,#001248 100%);' +
        'animation:sendly-assist-tourne 9s linear infinite}',
      F + '.sendly-assist-rond::after{content:"";position:absolute;inset:0;z-index:-1;border-radius:50%;pointer-events:none;' +
        'background:radial-gradient(circle at 50% 4%,rgba(255,255,255,.30),rgba(255,255,255,0) 58%)}',
      F + '.sendly-assist-coule{background:linear-gradient(100deg,#0043E0 0%,#2E6BFF 25%,#7BA6FF 50%,#2E6BFF 75%,#0043E0 100%);' +
        'background-size:300% 100%;animation:sendly-assist-coule 2.6s linear infinite}',
      F + ' .sendly-assist-txt{display:inline-flex;align-items:center;gap:10px;animation:sendly-assist-fondu .22s ease both}',
      F + ' .sendly-assist-compte{opacity:.75;font-weight:400;font-variant-numeric:tabular-nums}',
      F + ' .sendly-assist-anneau{width:16px;height:16px;border-radius:50%;border:2px solid rgba(255,255,255,.28);border-top-color:#fff;' +
        'animation:sendly-assist-tourne .9s linear infinite;flex:none}',
      F + ' .sendly-assist-dedans{position:relative;border:0;background:rgba(255,255,255,.16);color:#fff;border-radius:999px;padding:0 13px;' +
        'min-height:32px;margin:0 -12px 0 0;font:500 13px/1 ' + POLICE + ';letter-spacing:inherit;text-transform:none;cursor:pointer}',
      F + ' .sendly-assist-dedans:hover{background:rgba(255,255,255,.26)}',
      // Dans l'éditeur, le rond reste masqué (la tuile de la bibliothèque est
      // l'entrée) ; la pastille d'état, elle, se voit : elle compte les étapes.
      'body.sendly-builder-ouvert ' + F + '.sendly-assist-rond{display:none}',
      'body.sendly-builder-ouvert ' + F + '{z-index:100002}',

      // ── La carte : elle prend la hauteur de ce qu'elle a à dire ──────────
      '#sendly-assist-voile{display:none}',
      P + '{position:fixed;right:24px;bottom:24px;z-index:1040;width:464px;max-width:calc(100vw - 32px);max-height:calc(100vh - 72px);' +
        'display:flex;flex-direction:column;overflow:hidden;background:#fff;border:0;border-radius:24px;' +
        'box-shadow:0 30px 80px rgba(0,6,23,.26),0 2px 8px rgba(0,6,23,.08),0 0 0 1px rgba(0,18,72,.06);' +
        'transform-origin:calc(100% - 28px) 100%;transition:transform .28s ' + COURBE + ',opacity .16s ease;' +
        'font-family:' + POLICE + ';font-size:16px;font-weight:400;font-style:normal;line-height:normal;letter-spacing:-.005em;word-spacing:normal;' +
        'text-align:left;text-transform:none;color:' + ENCRE + ';-webkit-font-smoothing:antialiased}',
      '@supports (height:100dvh){' + P + '{max-height:calc(100dvh - 72px)}}',
      P + '.sendly-assist-surgit{transform:translateY(12px) scale(.94);opacity:0;pointer-events:none;transition:transform .18s ease-in,opacity .16s ease-in}',
      P + ' *,' + P + ' *::before,' + P + ' *::after{box-sizing:border-box}',
      P + ' button{font:inherit;letter-spacing:inherit;text-transform:none;cursor:pointer;margin:0}',
      P + ' h2,' + P + ' h3,' + P + ' p,' + P + ' dl,' + P + ' dt,' + P + ' dd{margin:0;padding:0;font:inherit;letter-spacing:inherit}',
      P + ' [hidden]{display:none!important}',
      P + ' svg{flex:none;display:block}',
      P + ' :focus-visible{outline:3px solid rgba(0,79,255,.45);outline-offset:2px}',
      P + ' .sendly-assist-head :focus-visible{outline-color:rgba(255,255,255,.7)}',
      P + ' .sendly-assist-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}',
      P + ' .sendly-assist-poignee{display:none}',

      // En-tête : la vague et son grain, la matière de la fenêtre de l'offre.
      P + ' .sendly-assist-head{position:relative;flex:none;color:#fff;padding:18px 20px;overflow:hidden;background-color:' + NUIT + ';' +
        'background-image:linear-gradient(180deg,rgba(0,18,72,.62),rgba(0,18,72,.18) 55%,rgba(0,79,255,.16)),url("' + CHEMIN_ASSETS + '/img/copilot-vague.jpg");' +
        'background-size:cover,cover;background-position:center,center;border:0}',
      P + ' .sendly-assist-head::after{content:"";position:absolute;inset:0;background:url("' + CHEMIN_ASSETS + '/img/copilot-grain.jpg") repeat;' +
        'background-size:240px;mix-blend-mode:overlay;opacity:.3;pointer-events:none}',
      P + ' .sendly-assist-head>*{position:relative;z-index:1}',
      P + ' .sendly-assist-tete-ligne{display:flex;align-items:center;gap:8px}',
      P + ' .sendly-assist-title{position:relative;display:inline-flex;align-items:center;gap:9px;min-width:0;border-radius:999px;padding:8px 18px;' +
        'font-size:13px;font-weight:500;line-height:1.3;color:#fff;background:' + VERRE + ';-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);' +
        'box-shadow:0 10px 26px rgba(0,6,23,.28),inset 0 1px 0 rgba(255,255,255,.18)}',
      P + ' .sendly-assist-title::before{content:"";position:absolute;inset:0;border-radius:999px;padding:1px;' + LISERE + '}',
      P + ' .sendly-assist-title>span{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      P + ' .sendly-assist-r{font-size:8px;vertical-align:top;margin-left:1px;opacity:.85}',
      P + ' .sendly-assist-iconbtn{position:relative;flex:none;width:36px;height:36px;padding:0;border:0;border-radius:50%;color:#fff;' +
        'background:' + VERRE + ';-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);display:inline-flex;align-items:center;justify-content:center;' +
        'box-shadow:0 6px 16px rgba(0,6,23,.22),inset 0 1px 0 rgba(255,255,255,.16)}',
      P + ' .sendly-assist-iconbtn::before{content:"";position:absolute;inset:0;border-radius:50%;padding:1px;opacity:.85;' + LISERE + '}',
      P + ' .sendly-assist-iconbtn:hover{background:linear-gradient(120deg,rgba(255,255,255,.2),rgba(255,255,255,.1) 40%,rgba(255,255,255,.18))}',
      P + ' #sendly-assist-clear{margin-left:auto}',
      // À l'accueil la corbeille est masquée : la croix se range tout à droite.
      P + ' #sendly-assist-clear[hidden]+#sendly-assist-close{margin-left:auto}',
      P + ' .sendly-assist-temps{display:inline-flex;align-items:center;gap:6px;flex:none;border-radius:999px;padding:5px 12px;background:' + GLACE + ';' +
        'color:' + NUIT + ';font-size:12px;font-weight:500;line-height:1.3;white-space:nowrap;font-variant-numeric:tabular-nums}',
      P + ' .sendly-assist-temps::before{content:"";width:6px;height:6px;border-radius:50%;background:#004FFF}',
      P + ' .sendly-assist-heros{display:grid;grid-template-rows:1fr;transition:grid-template-rows .32s ' + COURBE + ',opacity .16s ease}',
      P + ' .sendly-assist-heros>div{overflow:hidden}',
      P + ' .sendly-assist-heros h2{margin:22px 2px 0;font-family:inherit;font-size:28px;line-height:1.14;font-weight:300;letter-spacing:-.018em;' +
        'color:#fff;text-transform:none;white-space:pre-line}',
      P + ' .sendly-assist-heros .sendly-assist-temps{margin:16px 2px 6px}',
      P + ' .sendly-assist-heros h2:last-child{margin-bottom:6px}',
      P + ' .sendly-assist-compacte .sendly-assist-heros{grid-template-rows:0fr;opacity:0}',

      // Corps : raccourcis à l'accueil, conversation ensuite. Rien ne se
      // tasse : si le contenu dépasse, la carte défile.
      P + ' .sendly-assist-conv{flex:1;min-height:0;overflow-y:auto;padding:20px 20px 8px;display:flex;flex-direction:column;gap:16px;scrollbar-width:thin}',
      P + ' .sendly-assist-conv>*{flex:none}',
      P + '.sendly-assist-sans-saisie .sendly-assist-conv{padding-bottom:20px}',
      P + ' .sendly-assist-entre{animation:sendly-assist-monte .32s ' + COURBE + ' both}',
      P + ' .sendly-assist-ex{display:grid;grid-template-columns:minmax(0,1fr);gap:10px}',
      P + ' .sendly-assist-ex button{display:flex;align-items:center;gap:14px;width:100%;text-align:left;border:0;border-radius:12px;background:' + PERLE + ';' +
        'padding:12px 16px;min-height:56px;font-size:15px;font-weight:500;line-height:1.25;color:' + NUIT + ';transition:background .14s ease}',
      P + ' .sendly-assist-ex button:hover{background:#EAF0FA}',
      P + ' .sendly-assist-ico{flex:none;width:34px;height:34px;border-radius:8px;background:' + GLACE + ';color:#004FFF;display:inline-flex;align-items:center;justify-content:center}',
      // Chaque raccourci tient sur une seule ligne (retour du 25/11).
      P + ' .sendly-assist-ex .sendly-assist-t{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      P + ' .sendly-assist-gain{flex:none;padding-left:10px;font-size:13px;font-weight:400;color:' + GRIS + ';white-space:nowrap}',
      P + ' .sendly-assist-me{align-self:flex-end;max-width:86%;background:' + GLACE + ';color:' + NUIT + ';border-radius:12px 12px 4px 12px;' +
        'padding:12px 16px;font-size:15px;line-height:1.4;white-space:pre-wrap;overflow-wrap:anywhere}',
      P + ' .sendly-assist-turn{display:grid;gap:12px;margin:0 2px;font-size:15px;line-height:1.5;color:' + ENCRE + ';min-width:0}',
      P + ' .sendly-assist-turn>*{min-width:0}',
      P + ' .sendly-assist-turn ol,' + P + ' .sendly-assist-turn ul{margin:0;padding-left:20px;display:grid;gap:5px}',
      P + ' .sendly-assist-turn strong{color:' + NUIT + ';font-weight:500}',
      P + ' .sendly-assist-chemin{display:inline-block;background:' + GLACE + ';color:' + NUIT + ';border-radius:6px;padding:0 6px;font-weight:500;' +
        'font-size:13px;line-height:1.7;white-space:nowrap}',
      P + ' .sendly-assist-mut{font-size:14px;line-height:1.45;color:' + GRIS + '}',
      P + ' .sendly-assist-note{font-size:13px;color:#7A4B00;background:#FFF3D6;border-radius:6px;padding:1px 7px}',
      P + ' .sendly-assist-encours{display:flex;align-items:center;gap:10px}',
      P + ' .sendly-assist-encours::before{content:"";flex:none;width:14px;height:14px;border-radius:50%;border:2px solid #C9D6F2;border-top-color:#004FFF;' +
        'animation:sendly-assist-tourne .9s linear infinite}',
      P + ' .sendly-assist-attente{display:flex;align-items:center;gap:10px;margin:2px;min-height:28px;font-size:15px;color:' + GRIS + '}',
      P + ' .sendly-assist-attente .sendly-assist-eclat{color:#004FFF;animation:sendly-assist-respire 1.4s ease-in-out infinite}',
      P + ' .sendly-assist-luit{background:linear-gradient(90deg,' + GRIS + ' 30%,#A9B4C8 50%,' + GRIS + ' 70%);background-size:200% 100%;' +
        '-webkit-background-clip:text;background-clip:text;color:transparent;animation:sendly-assist-luit 1.6s linear infinite}',
      P + ' .sendly-assist-attente .sendly-assist-lien{margin-left:auto}',
      P + ' .sendly-assist-lien{border:0;background:none;padding:6px 4px;font-size:14px;font-weight:500;color:#004FFF;text-decoration:none;cursor:pointer}',
      P + ' .sendly-assist-lien:hover{text-decoration:underline}',

      // Le reçu : ce qui a été fait, ce que cela a fait gagner, comment
      // revenir en arrière. Trois formes : réussite, échec, annulé.
      P + ' .sendly-assist-recu{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;column-gap:12px;' +
        'border:1px solid #E4E8EF;border-radius:12px;background:#fff;overflow:hidden;padding:16px 18px;font-size:14.5px;line-height:1.35;color:' + ENCRE + '}',
      P + ' .sendly-assist-signe{grid-column:1;grid-row:1;width:26px;height:26px;border-radius:50%;background-color:#004FFF;background-repeat:no-repeat;' +
        'background-position:center;background-size:13px 13px;background-image:' + fondTrace('coche', '#fff') + ';font-size:0;color:transparent;overflow:hidden}',
      P + ' .sendly-assist-recu-titre{grid-column:2;grid-row:1;font-size:16px;font-weight:500;line-height:1.25;color:' + NUIT + ';min-width:0}',
      P + ' .sendly-assist-recu>.sendly-assist-temps{grid-column:3;grid-row:1}',
      P + ' .sendly-assist-sep{display:none}',
      P + ' .sendly-assist-motif{grid-column:2/-1;grid-row:2;margin-top:6px;font-size:14.5px;line-height:1.45;color:' + ENCRE + ';overflow-wrap:anywhere}',
      P + ' .sendly-assist-motif::first-letter{text-transform:uppercase}',
      P + ' .sendly-assist-lignes{grid-column:1/-1;margin:14px -18px 0;padding:6px 18px;border-top:1px solid #EEF1F6}',
      P + ' .sendly-assist-lignes:last-child{margin-bottom:-12px}',
      P + ' .sendly-assist-ligne{display:grid;grid-template-columns:108px minmax(0,1fr);gap:12px;padding:10px 0;font-size:14.5px;line-height:1.35}',
      P + ' .sendly-assist-ligne+.sendly-assist-ligne{border-top:1px solid #EEF1F6}',
      P + ' .sendly-assist-ligne dt{color:' + GRIS + ';font-weight:400}',
      P + ' .sendly-assist-ligne dd{color:' + ENCRE + ';font-weight:500;min-width:0;overflow-wrap:anywhere}',
      P + ' .sendly-assist-recu-pied{grid-column:1/-1;display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:14px -18px -16px;padding:14px 18px 16px;' +
        'border-top:1px solid #EEF1F6}',
      P + ' .sendly-assist-lignes+.sendly-assist-recu-pied{margin-top:0}',
      P + ' .sendly-assist-recu-pied:empty{display:none}',
      P + ' .sendly-assist-lignes:has(+.sendly-assist-recu-pied:empty){margin-bottom:-12px}',
      P + ' .sendly-assist-recu-note{font-size:13px;line-height:1.4;color:' + GRIS + '}',
      P + ' .sendly-assist-bouton{display:inline-flex;align-items:center;justify-content:center;gap:7px;border-radius:999px;min-height:42px;padding:0 18px;' +
        'font-size:14.5px;font-weight:500;line-height:1;white-space:nowrap;text-decoration:none;' +
        'transition:transform .16s ' + COURBE + ',box-shadow .16s ease,background .14s ease,border-color .14s ease,color .14s ease}',
      P + ' .sendly-assist-bouton[disabled]{opacity:.6;pointer-events:none}',
      P + ' .sendly-assist-undo,' + P + ' .sendly-assist-renoncer,' + P + ' .sendly-assist-fantome{background:#fff;border:1px solid #D5DCED;color:' + NUIT + '}',
      P + ' .sendly-assist-undo:hover,' + P + ' .sendly-assist-renoncer:hover,' + P + ' .sendly-assist-fantome:hover{border-color:#004FFF;color:#004FFF}',
      P + ' .sendly-assist-confirmer{border:0;color:#fff;background-color:#004FFF;' +
        'background-image:linear-gradient(120deg,rgba(255,255,255,.14),rgba(255,255,255,0) 45%),linear-gradient(180deg,#2E6BFF,#004FFF 60%,#0043E0);' +
        'box-shadow:0 4px 14px rgba(0,79,255,.20),inset 0 1px 0 rgba(255,255,255,.35)}',
      P + ' .sendly-assist-confirmer:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(0,79,255,.28),inset 0 1px 0 rgba(255,255,255,.35)}',
      P + ' .sendly-assist-confirmer:active{transform:none}',
      P + ' .sendly-assist-echec .sendly-assist-signe{background-color:#fff;box-shadow:inset 0 0 0 1.5px ' + ROUGE + ';background-image:' + fondTrace('croix', ROUGE) + '}',
      P + ' .sendly-assist-annule .sendly-assist-signe{background-color:#fff;box-shadow:inset 0 0 0 1.5px #C4CCDB;background-image:' + fondTrace('retour', GRIS, 2.2) + '}',
      P + ' .sendly-assist-annule .sendly-assist-recu-titre{color:' + GRIS + '}',
      P + ' .sendly-assist-annule .sendly-assist-lignes{opacity:.5}',
      P + ' .sendly-assist-annule .sendly-assist-ligne dd{text-decoration:line-through;text-decoration-color:rgba(0,6,23,.35)}',
      P + ' .sendly-assist-annule>.sendly-assist-temps{display:none}',

      // La carte de confirmation : le seul objet à liseré bleu du Copilot.
      P + ' .sendly-assist-carte{border:1.5px solid #004FFF;border-radius:12px;background:#fff;overflow:hidden;box-shadow:0 12px 32px rgba(0,79,255,.14);padding:18px 18px 16px}',
      P + ' .sendly-assist-etiquette{display:inline-flex;align-items:center;border-radius:999px;background:' + GLACE + ';color:' + NUIT + ';font-size:11.5px;font-weight:500;padding:4px 11px}',
      P + ' .sendly-assist-carte h3{margin:14px 0 0;font-family:inherit;font-size:22px;font-weight:400;letter-spacing:-.015em;line-height:1.2;color:' + NUIT + ';text-transform:none}',
      P + ' .sendly-assist-grand{display:flex;align-items:baseline;gap:10px;margin-top:14px}',
      P + ' .sendly-assist-grand b{font-size:48px;font-weight:300;line-height:1;letter-spacing:-.035em;color:' + NUIT + ';font-variant-numeric:tabular-nums}',
      P + ' .sendly-assist-grand span{font-size:14.5px;color:' + GRIS + '}',
      P + ' .sendly-assist-carte .sendly-assist-lignes{margin:14px -18px 0;padding:4px 18px}',
      P + ' .sendly-assist-carte .sendly-assist-ligne{padding:8px 0}',
      P + ' .sendly-assist-carte-actions{display:flex;flex-wrap:wrap;gap:10px;margin:0 -18px;padding:14px 18px 0;border-top:1px solid #EEF1F6}',
      P + ' .sendly-assist-carte-actions .sendly-assist-bouton{min-height:46px;padding:0 20px}',
      P + ' .sendly-assist-carte-actions .sendly-assist-confirmer{flex:1}',

      // Les étapes d'une création, rendues visibles.
      P + ' .sendly-assist-etapes{grid-column:1/-1;list-style:none;margin:14px -18px -8px;padding:8px 18px 0;border-top:1px solid #EEF1F6;display:block}',
      P + ' .sendly-assist-etapes li{display:flex;align-items:center;gap:12px;padding:8px 0;font-size:14.5px;line-height:1.3;color:' + ENCRE + '}',
      P + ' .sendly-assist-pt{flex:none;width:20px;height:20px;border-radius:50%;background:#004FFF;color:#fff;display:inline-flex;align-items:center;justify-content:center}',

      // Saisie et pied.
      P + ' .sendly-assist-foot{flex:none;display:flex;align-items:center;gap:10px;padding:12px 18px 8px 20px;margin:0;border:0;background:none}',
      P + ' #sendly-assist-input{flex:1;min-width:0;width:auto;height:52px;margin:0;border:1px solid transparent;border-radius:999px;background:' + PERLE + ';padding:0 20px;' +
        'font:400 16px ' + POLICE + ';letter-spacing:-.005em;color:' + ENCRE + ';outline:none;box-shadow:none;transition:border-color .14s ease,background .14s ease}',
      P + ' #sendly-assist-input::placeholder{color:' + GRIS + ';opacity:1}',
      P + ' #sendly-assist-input:focus{border-color:#004FFF;background:#fff}',
      P + ' #sendly-assist-send{flex:none;width:52px;height:52px;padding:0;border:0;border-radius:50%;color:#fff;display:inline-flex;align-items:center;justify-content:center;' +
        'background:radial-gradient(circle at 50% 18%,#0a52d8 0%,#0640a8 42%,#05215c 74%,#021030 100%);box-shadow:0 6px 16px rgba(0,18,72,.28);' +
        'transition:transform .14s ' + COURBE + '}',
      P + ' #sendly-assist-send:hover{transform:scale(1.05)}',
      P + ' #sendly-assist-send:active{transform:scale(.95)}',
      P + ' #sendly-assist-send[disabled]{opacity:.5;pointer-events:none}',
      P + ' .sendly-assist-meta{flex:none;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:4px 24px 18px;font-size:13px;line-height:1.35;color:' + GRIS + '}',
      P + ' .sendly-assist-meta .sendly-assist-lien{padding:0;font-size:13px}',

      // ── Dans la page : ce que le Copilot a touché s'allume ───────────────
      '.sendly-assist-halo{border-radius:3px;animation:sendly-assist-halo 3.4s ease both}',
      '.sendly-assist-marque{position:absolute;z-index:1034;display:inline-flex;align-items:center;gap:5px;height:22px;padding:0 9px;border-radius:999px;' +
        'background:#004FFF;color:#fff;font:500 11.5px/1 ' + POLICE + ';white-space:nowrap;pointer-events:none;animation:sendly-assist-fondu .2s ease both}',
      '.sendly-assist-point{position:relative}',
      '.sendly-assist-point::after{content:"";position:absolute;top:6px;right:4px;width:9px;height:9px;border-radius:50%;background:#004FFF;' +
        'box-shadow:0 0 0 3px #fff;animation:sendly-assist-fondu .2s ease both}',

      // ── Téléphone : une feuille du bas, des cibles de 44 px au moins, un
      //    champ en 16 px (pas de zoom à la saisie).
      '@media (max-width:600px){' +
        F + '{right:16px;bottom:20px}' +
        F + ':not(.sendly-assist-rond){min-height:56px;max-width:calc(100vw - 32px)}' +
        F + ' .sendly-assist-dedans{min-height:44px;padding:0 16px}' +
        '#sendly-assist-voile{display:block;position:fixed;inset:0;z-index:1039;background:rgba(0,6,23,.45);opacity:1;transition:opacity .22s ease}' +
        '#sendly-assist-voile.sendly-assist-surgit{opacity:0;pointer-events:none}' +
        P + '{left:0;right:0;bottom:0;width:auto;max-width:none;max-height:88vh;border-radius:24px 24px 0 0;transform-origin:50% 100%;transition:transform .36s ' + COURBE + '}' +
        P + '.sendly-assist-surgit{transform:translateY(100%);opacity:1;transition:transform .24s ease-in}' +
        P + ' .sendly-assist-poignee{display:block;position:absolute;z-index:2;top:7px;left:50%;width:36px;height:4px;margin-left:-18px;border-radius:4px;background:rgba(255,255,255,.5)}' +
        P + ' .sendly-assist-iconbtn{width:44px;height:44px}' +
        P + ' .sendly-assist-heros h2{font-size:23px}' +
        P + ' .sendly-assist-ex button{min-height:60px}' +
        P + ' .sendly-assist-section{display:none}' +
        P + ' .sendly-assist-gain,' + P + ' .sendly-assist-long,' + P + ' .sendly-assist-clavier{display:none}' +
        P + ' .sendly-assist-ligne{grid-template-columns:88px minmax(0,1fr)}' +
        P + ' .sendly-assist-bouton{min-height:44px}' +
        P + ' .sendly-assist-recu-pied{flex-direction:column;align-items:stretch}' +
        P + ' .sendly-assist-lien{min-height:44px;padding:0 10px}' +
        P + ' .sendly-assist-meta{padding-bottom:22px}' +
      '}',
      '@supports (height:100dvh){@media (max-width:600px){' + P + '{max-height:88dvh}}}',
      // Téléphone étroit : la ligne gagne de la place ; sous 340 px, un libellé
      // se replie sur deux lignes plutôt que de perdre son sens dans une ellipse.
      '@media (max-width:400px){' +
        P + ' .sendly-assist-conv{padding-left:16px;padding-right:16px}' +
        P + ' .sendly-assist-ex button{padding:12px;gap:10px;font-size:14px}' +
        P + ' .sendly-assist-foot{padding-left:16px;padding-right:14px}' +
        P + ' .sendly-assist-meta{padding-left:20px;padding-right:20px}' +
      '}',
      '@media (max-width:340px){' + P + ' .sendly-assist-ex .sendly-assist-t{white-space:normal}}',

      // Mouvement réduit : l'état final tout de suite, rien ne tourne.
      '@media (prefers-reduced-motion:reduce){' +
        P + ',' + P + ' *,' + P + ' *::before,' + P + ' *::after,' + F + ',' + F + ' *,.sendly-assist-halo,.sendly-assist-marque,.sendly-assist-point::after' +
        '{animation-duration:.01s!important;animation-delay:0s!important;animation-iteration-count:1!important;transition-duration:.01s!important}' +
        P + ' .sendly-assist-luit{color:' + GRIS + ';background:none}' +
      '}'
    ].join('');
    var style = document.createElement('style');
    style.id = 'sendly-assist-style';
    style.textContent = css;
    document.head.appendChild(style);
  }

  // ── Registre des contextes ─────────────────────────────────────────────

  function contexts() {
    return window.SendlyAssistantContexts || [];
  }

  function activeContext() {
    var best = null;
    contexts().forEach(function (ctx) {
      try {
        if (!ctx.available()) {
          return;
        }
        if (!best || (ctx.priority || 0) > (best.priority || 0)) {
          best = ctx;
        }
      } catch (e) {
        // Un contexte cassé ne doit jamais tuer le lanceur.
      }
    });
    return best;
  }

  // ── Temps gagné : le barème vient du serveur, la coquille le met en mots ──

  /** 600 → « ≈ 10 min », 5400 → « ≈ 1 h 30 ». Vide sous la minute. */
  function dureeLisible(secondes) {
    var s = Math.round(Number(secondes) || 0);
    if (s < 60) { return ''; }
    var h = Math.floor(s / 3600), m = Math.round((s % 3600) / 60);
    if (m === 60) { h += 1; m = 0; }
    return h > 0 ? (h + '\u00a0h' + (m ? '\u00a0' + (m < 10 ? '0' : '') + m : '')) : (m + '\u00a0min');
  }

  /** Les secondes que vaut une action, d'après le barème exposé par le
   *  serveur (SendlyAiConfig.bareme). Sans barème, rien n'est affiché. */
  function secondesAction(type, quantite) {
    var c = cfg();
    var unite = c && c.bareme ? Number(c.bareme[type]) || 0 : 0;
    return unite * (quantite || 1);
  }

  /** « gagnée » pour une minute ou une heure tout rond, « gagnées » sinon. */
  function gagnees(d) {
    return /^1\u00a0(?:min|h)$/.test(d) ? 'gagnée' : 'gagnées';
  }

  function pastilleTemps(secondes) {
    var d = dureeLisible(secondes);
    return d ? '<span class="sendly-assist-temps"><span>≈\u00a0' + d + '<span class="sendly-assist-long">\u00a0' + gagnees(d) + '</span></span></span>' : '';
  }

  // ── Reçus ──────────────────────────────────────────────────────────────

  function lignesHtml(lignes) {
    if (!lignes || !lignes.length) { return ''; }
    return '<dl class="sendly-assist-lignes">' + lignes.map(function (l) {
      return '<div class="sendly-assist-ligne"><dt>' + esc(l[0]) + '</dt><dd>' + esc(l[1]) + '</dd></div>';
    }).join('') + '</dl>';
  }

  /** Un reçu. o = {etat: 'fait'|'echec'|'annule', titre, motif, lignes,
   *  secondes, annulable, suite (html), pied (html), note}. Le signe et le titre restent du
   *  TEXTE (« ✓ 2 champs remplis », « ✗ Titre : motif ») : c'est ce que
   *  lisent un lecteur d'écran et l'historique ; le dessin est en CSS. */
  function recuHtml(o) {
    var etat = o.etat || 'fait';
    var signe = etat === 'echec' ? '✗' : (etat === 'annule' ? '↩' : '✓');
    var pied = (o.annulable ? '<!--sendly-annuler-->' : '') + (o.pied || '') +
      (o.note ? '<span class="sendly-assist-recu-note">' + esc(o.note) + '</span>' : '');
    return '<div class="sendly-assist-recu sendly-assist-' + etat + (o.annulable ? ' sendly-assist-annulable' : '') + '">' +
      '<span class="sendly-assist-signe">' + signe + '</span> ' +
      '<span class="sendly-assist-recu-titre">' + esc(o.titre) + '</span>' +
      (o.motif ? '<span class="sendly-assist-sep"> : </span><span class="sendly-assist-motif">' + esc(o.motif) + '</span>' : '') +
      (etat === 'fait' ? pastilleTemps(o.secondes) : '') +
      lignesHtml(o.lignes) + (o.suite || '') +
      (pied ? '<div class="sendly-assist-recu-pied">' + pied + '</div>' : '') +
      '</div>';
  }

  /** Une ligne d'état écrite en clair (« ✓ … », « ✗ Titre : motif », une
   *  attente, une remarque) devient son reçu. Le texte est conservé tel quel. */
  function ligneRecu(texte, o) {
    var s = String(texte == null ? '' : texte);
    var opts = o || {};
    var signe = s.charAt(0);
    if (signe !== '✓' && signe !== '✗') {
      var enCours = s.indexOf('Création en cours') === 0 || s.indexOf('→') === 0 || opts.encours;
      return '<div class="sendly-assist-mut' + (enCours ? ' sendly-assist-encours' : '') + '">' + esc(s) + '</div>';
    }
    var reste = s.slice(1).replace(/^\s+/, '');
    var coupe = reste.indexOf(' : ');
    var titre = coupe === -1 ? reste : reste.slice(0, coupe);
    var motif = coupe === -1 ? '' : reste.slice(coupe + 3);
    return recuHtml({
      etat: opts.etat || (signe === '✓' ? 'fait' : 'echec'),
      titre: titre, motif: motif,
      lignes: opts.lignes, secondes: opts.secondes, annulable: opts.annulable, pied: opts.pied, note: opts.note
    });
  }

  // ── Dans la page : champs allumés, repère sur l'onglet ─────────────────

  /** halos[turnId] = [éléments allumés] : éteints à l'annulation. */
  var halos = {};

  /** L'élément VISIBLE d'un champ : lui-même, l'habillage Chosen d'un
   *  select, ou l'éditeur riche posé à la place d'un textarea. */
  function facadeChamp(el) {
    if (!el || !el.classList) { return null; }
    if (el.tagName === 'SELECT' && el.id && document.getElementById(el.id + '_chosen')) {
      return document.getElementById(el.id + '_chosen');
    }
    if (el.tagName === 'TEXTAREA' && el.nextElementSibling && el.nextElementSibling.classList &&
      el.nextElementSibling.classList.contains('ck-editor')) {
      return el.nextElementSibling;
    }
    return el;
  }

  function allumer(el, turnId, marque) {
    var cible = facadeChamp(el);
    if (!cible || !cible.getBoundingClientRect) { return; }
    try {
      cible.classList.remove('sendly-assist-halo');
      void cible.offsetWidth; // relance l'animation si le champ était déjà allumé
      cible.classList.add('sendly-assist-halo');
      (halos[turnId] = halos[turnId] || []).push(cible);
      // Le champ est dans un onglet qui n'est pas affiché : un point sur l'onglet.
      var volet = cible.closest ? cible.closest('.tab-pane') : null;
      if (volet && volet.id && !volet.classList.contains('active')) {
        var onglet = document.querySelector('a[href="#' + volet.id + '"]');
        if (onglet) {
          onglet.classList.add('sendly-assist-point');
          halos[turnId].push(onglet);
          onglet.addEventListener('click', function () { onglet.classList.remove('sendly-assist-point'); });
        }
      } else if (marque && document.body) {
        var r = cible.getBoundingClientRect();
        if (r.width > 0) {
          var badge = document.createElement('span');
          badge.className = 'sendly-assist-marque';
          badge.innerHTML = eclat(11) + '<span>' + esc(marque) + '</span>';
          badge.style.top = Math.max(4, r.top + window.pageYOffset - 26) + 'px';
          badge.style.right = Math.max(4, document.documentElement.clientWidth - r.right - window.pageXOffset) + 'px';
          document.body.appendChild(badge);
          var retirer = function () {
            if (badge.parentNode) { badge.parentNode.removeChild(badge); }
            document.removeEventListener('scroll', retirer, true);
          };
          document.addEventListener('scroll', retirer, true);
          setTimeout(retirer, 3400);
        }
      }
    } catch (e) { /* l'allumage est un repère, jamais du chemin critique */ }
  }

  function eteindre(turnId) {
    (halos[turnId] || []).forEach(function (el) {
      try { el.classList.remove('sendly-assist-halo', 'sendly-assist-point'); } catch (e) {}
    });
    delete halos[turnId];
  }

  function eteindreTout() {
    Object.keys(halos).forEach(eteindre);
  }

  // ── État : une conversation PAR contexte ───────────────────────────────

  /** convStore[id] = [{role:'user', text} | {role:'ia', html, turnId?, undoable?}] */
  var convStore = {};
  var busy = false;
  var enCours = null;
  var openCtx = null;
  var raccourcisOuverts = false;
  var tempsMois = 0;
  /** Les tours déjà montrés : seul un tour nouveau entre en mouvement. */
  var dejaVus = 0;

  function conv() {
    if (!openCtx) {
      return [];
    }
    if (!convStore[openCtx.id]) {
      convStore[openCtx.id] = [];
    }
    return convStore[openCtx.id];
  }

  function panel() {
    return document.getElementById('sendly-assist-panel');
  }

  // ── Mémoire de session : la conversation suit d'un écran à l'autre ─────
  // Une navigation demandée au Copilot recharge la page : sans cette mémoire,
  // tout était perdu à l'arrivée (constat du 12/11). Même patron que les
  // relais de création : sessionStorage, durée de vie courte.

  // Seule la conversation GÉNÉRALE voyage : c'est elle qui navigue. Celle d'un
  // écran de travail (segment) parle de lignes de CET écran ; elle ne vaut plus
  // rien après un rechargement (constat en direct du 26/11).
  var MEMOIRE_CONTEXTES = ['help'];
  var CLE_MEMOIRE = 'sendlyAssistConv';
  var CLE_ARRIVEE = 'sendlyAssistArrivee';
  var VIE_MEMOIRE = 1800000; // 30 min
  var MOTIF_ENCOURS = /<div class="sendly-assist-mut sendly-assist-encours">[^<]*<\/div>/;

  function memoriser() {
    try {
      var copie = {};
      MEMOIRE_CONTEXTES.forEach(function (id) {
        if (!convStore[id] || !convStore[id].length) { return; }
        copie[id] = convStore[id].slice(-12).map(function (turn) {
          if (turn.role === 'user') { return { role: 'user', text: turn.text }; }
          // Ni annulation ni confirmation ne survivent à un rechargement :
          // l'instantané et la demande en attente vivent dans la page.
          var html = String(turn.html || '')
            .replace(/<div class="sendly-assist-carte" data-carte="[^"]*">[\s\S]*?<\/div><\/div>/g,
              '<div class="sendly-assist-mut">' + esc('Confirmation interrompue par le changement d’écran. Redemandez-la si besoin.') + '</div>');
          return { role: 'ia', html: html, plain: turn.plain || '', result: turn.result || '' };
        });
      });
      sessionStorage.setItem(CLE_MEMOIRE, JSON.stringify({ ts: Date.now(), convs: copie }));
    } catch (e) { /* mémoire indisponible : la conversation reste en page */ }
  }

  function restaurer() {
    var brut = null, memo = null;
    try { brut = sessionStorage.getItem(CLE_MEMOIRE); } catch (e) {}
    if (!brut) { return; }
    try { memo = JSON.parse(brut); } catch (e) {}
    if (!memo || !memo.ts || Date.now() - memo.ts > VIE_MEMOIRE || !memo.convs) {
      try { sessionStorage.removeItem(CLE_MEMOIRE); } catch (e) {}
      return;
    }
    MEMOIRE_CONTEXTES.forEach(function (id) {
      if (!convStore[id] && memo.convs[id] && memo.convs[id].length) {
        // Un reçu d'avant le rechargement ne revient pas dans la pastille.
        convStore[id] = memo.convs[id].map(function (turn) { turn.range = true; return turn; });
      }
    });
  }

  /** Ce qu'une navigation ou une création a promis avant de quitter l'écran,
   *  lu à l'arrivée : la ligne d'attente devient un reçu, la pastille le dit. */
  function promettreArrivee(texte, type) {
    try {
      sessionStorage.setItem(CLE_ARRIVEE, JSON.stringify({ texte: texte, type: type || '', ts: Date.now() }));
    } catch (e) {}
  }

  function accueillirArrivee() {
    var brut = null, a = null;
    try { brut = sessionStorage.getItem(CLE_ARRIVEE); sessionStorage.removeItem(CLE_ARRIVEE); } catch (e) {}
    try { a = brut ? JSON.parse(brut) : null; } catch (e) {}
    var fraiche = a && a.texte && a.ts && Date.now() - a.ts < 30000;
    // La ligne d'attente du dernier tour : reçu si l'on est arrivé, retirée sinon.
    Object.keys(convStore).forEach(function (id) {
      convStore[id].forEach(function (turn, i, tours) {
        if (turn.role !== 'ia' || !MOTIF_ENCOURS.test(turn.html || '')) { return; }
        var dernier = i === tours.length - 1;
        turn.html = turn.html.replace(MOTIF_ENCOURS, fraiche && dernier && id === 'help'
          ? recuHtml({ titre: a.texte, secondes: a.type ? secondesAction(a.type, 1) : 0 })
          : '');
      });
    });
    if (fraiche) {
      memoriser();
      poserPastille({ mode: 'recu', texte: a.texte, duree: 5200 });
    }
  }

  // ── La pastille : le bouton dit un état, ou porte un reçu ──────────────

  var pastille = { mode: 'repos' };
  var pastilleMinuterie = null;

  function fabEl() {
    return document.getElementById('sendly-assist-fab');
  }

  function rendrePastille() {
    var fab = fabEl();
    if (!fab) { return; }
    var p = pastille;
    fab.classList.toggle('sendly-assist-rond', p.mode === 'repos');
    fab.classList.toggle('sendly-assist-coule', p.mode === 'travail');
    if (p.mode === 'repos') {
      fab.innerHTML = '<span class="sendly-assist-vivant"></span>' + eclat(24);
    } else {
      fab.innerHTML = '<span class="sendly-assist-txt">' +
        (p.mode === 'travail' ? '<span class="sendly-assist-anneau"></span>' : trace('coche', 16, 2.6)) +
        '<span>' + esc(p.texte) + '</span>' +
        (p.compte ? '<span class="sendly-assist-compte">' + esc(p.compte) + '</span>' : '') + '</span>' +
        (p.bouton ? '<button type="button" class="sendly-assist-dedans">' + esc(p.bouton) + '</button>' : '');
    }
    fab.setAttribute('aria-live', p.mode === 'repos' ? 'off' : 'polite');
    refreshLabel();
  }

  /** p = {mode: 'repos'|'travail'|'recu', texte, compte, bouton, action, duree}. */
  function poserPastille(p) {
    clearTimeout(pastilleMinuterie);
    pastille = p && p.mode ? p : { mode: 'repos' };
    rendrePastille();
    if (pastille.mode !== 'repos' && pastille.duree) {
      pastilleMinuterie = setTimeout(function () { poserPastille(null); }, pastille.duree);
    }
  }

  // ── Rendu de la conversation ───────────────────────────────────────────

  var MOTS_RACCOURCIS = [
    [/^ouvrir\b/i, 'ouvrir', 'navigate'],
    [/^programmer\b|\benvoi\b/i, 'horloge', 'schedule_email'],
    [/\ba\/b\b/i, 'ab', 'create_ab_test'],
    [/\bsegment|\bcibl|\bcontacts?\b|\bceux\b/i, 'contacts', 'create_segment'],
    [/\bformulaire/i, 'formulaire', 'create_form'],
    [/\brapport/i, 'rapport', 'create_report'],
    [/\bcampagne/i, 'campagne', 'create_campaign'],
    [/\bpage\b/i, 'page', 'create_landing_page'],
    [/\be-mail|\bobjet\b/i, 'lettre', 'create_email']
  ];
  var ICONES_TYPES = {
    navigate: 'ouvrir', schedule_email: 'horloge', create_ab_test: 'ab', create_segment: 'contacts', create_form: 'formulaire',
    create_report: 'rapport', create_campaign: 'campagne', create_landing_page: 'page', create_email: 'lettre'
  };

  /** Un raccourci, quelle que soit sa forme d'origine : une phrase (contrat
   *  historique), ou [libellé court, demande complète, type d'action]. */
  function lireRaccourci(r) {
    var libelle = typeof r === 'string' ? r : String(r[0] || '');
    var demande = typeof r === 'string' ? r : String(r[1] || r[0] || '');
    var type = typeof r === 'string' ? '' : String(r[2] || '');
    var icone = ICONES_TYPES[type] || '';
    if (!icone) {
      for (var i = 0; i < MOTS_RACCOURCIS.length; i++) {
        if (MOTS_RACCOURCIS[i][0].test(demande)) { icone = MOTS_RACCOURCIS[i][1]; type = type || MOTS_RACCOURCIS[i][2]; break; }
      }
    }
    return { libelle: libelle, demande: demande, type: type, icone: icone || 'ouvrir' };
  }

  function raccourcisHtml(ctx) {
    var liste = ctx.shortcuts().map(lireRaccourci);
    // Un pictogramme n'a de rôle que s'il distingue : des raccourcis du même
    // genre s'en passent.
    var memes = liste.length > 1 && liste.every(function (r) { return r.icone === liste[0].icone; });
    return '<div class="sendly-assist-ex" id="sendly-assist-ex">' + liste.map(function (r) {
      var d = r.type === 'navigate' ? '' : dureeLisible(secondesAction(r.type, 1));
      return '<button type="button" data-demande="' + esc(r.demande) + '">' +
        (memes ? '' : '<span class="sendly-assist-ico">' + trace(r.icone, 18) + '</span>') +
        '<span class="sendly-assist-t">' + esc(r.libelle) + '</span>' +
        (d ? '<span class="sendly-assist-gain">≈\u00a0' + d + '</span>' : '') + '</button>';
    }).join('') + '</div>';
  }

  /** Le pied d'un reçu annulable reçoit son bouton, ou la trace de
   *  l'annulation ; le marqueur disparaît dans tous les cas. */
  function poserAnnulation(turn) {
    var html = String(turn.html || '');
    var bouton = '';
    if (turn.turnId && turn.undoable) {
      bouton = '<button type="button" class="sendly-assist-bouton sendly-assist-undo" data-turn="' + esc(turn.turnId) + '">' +
        trace('retour', 14) + '<span>' + esc(t('mautic.core.ai.assistant.undo.short', 'Annuler')) + '</span></button>';
    }
    if (html.indexOf('<!--sendly-annuler-->') !== -1) {
      return html.replace(/<!--sendly-annuler-->/g, bouton);
    }
    // Un contexte qui ne compose pas de reçu : le bouton suit sa réponse.
    return bouton ? html + '<div>' + bouton + '</div>' : html;
  }

  function renderHead() {
    var p = panel();
    if (!p || !openCtx) {
      return;
    }
    var enFil = conv().length > 0 || busy;
    // « Sendly Copilot · Segments » : la section suit l'écran ; au téléphone
    // elle se retire (la place est comptée), jamais une ellipse « · … ».
    var morceaux = String(openCtx.title()).split(' · ');
    var titre = esc(morceaux[0]).replace(/^Sendly Copilot/, 'Sendly Copilot<sup class="sendly-assist-r">®</sup>') +
      (morceaux.length > 1 ? '<span class="sendly-assist-section"> · ' + esc(morceaux.slice(1).join(' · ')) + '</span>' : '');
    p.querySelector('#sendly-assist-title').innerHTML = eclat(15) + '<span>' + titre + '</span>';
    p.setAttribute('aria-label', openCtx.title());
    p.querySelector('.sendly-assist-head').classList.toggle('sendly-assist-compacte', enFil);
    p.querySelector('.sendly-assist-heros h2').textContent = typeof openCtx.hero === 'function' ? openCtx.hero() : openCtx.welcome();
    p.querySelector('#sendly-assist-clear').hidden = !enFil;
    var d = dureeLisible(tempsMois);
    var grand = p.querySelector('.sendly-assist-heros .sendly-assist-temps');
    var mini = p.querySelector('.sendly-assist-tete-ligne .sendly-assist-temps');
    grand.hidden = !d;
    grand.textContent = d ? d + ' ' + gagnees(d) + ' ce mois-ci' : '';
    mini.hidden = !d || !enFil;
    mini.textContent = d;
    mini.setAttribute('title', d ? d + ' ' + gagnees(d) + ' ce mois-ci' : '');
  }

  function renderConv() {
    var el = document.getElementById('sendly-assist-conv');
    if (!el || !openCtx) {
      return;
    }
    var tours = conv();
    var html = '';
    if (!tours.length && !busy) {
      dejaVus = 0;
      html = raccourcisHtml(openCtx);
    } else {
      tours.forEach(function (turn, i) {
        var neuf = i >= dejaVus ? ' sendly-assist-entre' : '';
        if (turn.role === 'user') {
          html += '<div class="sendly-assist-me' + neuf + '">' + esc(turn.text) + '</div>';
          return;
        }
        html += '<div class="sendly-assist-turn' + neuf + '">' + poserAnnulation(turn) + '</div>';
      });
      dejaVus = tours.length;
      if (busy) {
        html += '<div class="sendly-assist-attente" role="status">' + eclat(18) +
          '<span class="sendly-assist-luit">' + esc(openCtx.thinking()) + '</span>' +
          '<button type="button" class="sendly-assist-lien" id="sendly-assist-stop">' + esc(t('mautic.core.ai.assistant.stop', 'Arrêter')) + '</button></div>';
      } else if (raccourcisOuverts) {
        html += raccourcisHtml(openCtx);
      }
    }
    el.innerHTML = html;

    var p = panel();
    if (p) {
      // Une confirmation en attente : la seule chose à faire est d'y
      // répondre, la saisie se retire.
      var aConfirmer = html.indexOf('data-carte="') !== -1;
      p.classList.toggle('sendly-assist-sans-saisie', aConfirmer);
      p.querySelector('.sendly-assist-foot').hidden = aConfirmer;
      p.querySelector('.sendly-assist-meta').hidden = aConfirmer;
      p.querySelector('#sendly-assist-shortcuts').hidden = !tours.length || busy;
      p.querySelector('.sendly-assist-clavier').hidden = tours.length > 0 || busy;
      p.querySelector('#sendly-assist-send').disabled = busy;
    }
    renderHead();
    // Le calage en bas vient en dernier : l'en-tête et la saisie viennent de
    // prendre (ou de rendre) leur place, la hauteur utile a pu changer.
    el.scrollTop = el.scrollHeight;
    memoriser();
  }

  /** L'api donnée aux contextes pour un tour d'envoi.
   *
   *  La conversation du tour est CAPTURÉE à l'envoi : fermer le panneau
   *  pendant la requête ne perd plus la réponse (avant le 12/11, elle partait
   *  dans un tableau jetable et l'on pouvait renvoyer la même demande).
   *  `jeton.arrete` : l'utilisateur a cliqué « Arrêter » ; la réponse tardive
   *  est ignorée, et le contexte le sait par api.arrete() pour ne rien jouer. */
  function sendApi(jeton) {
    var maConv = conv();
    return {
      history: function (n) {
        return maConv
          .filter(function (turn) { return turn.role === 'user'; })
          .slice(-n)
          .map(function (turn) { return turn.text; });
      },
      arrete: function () {
        return !!jeton.arrete;
      },
      /** Pousse un tour assistant ; extra = {turnId, undoable, plain, result}.
       *  Renvoie le tour, que l'appelant peut compléter plus tard (le
       *  résultat d'une création arrive après la réponse). */
      ia: function (html, extra) {
        var turn = { role: 'ia', html: html };
        if (extra && extra.turnId) {
          turn.turnId = extra.turnId;
          turn.undoable = !!extra.undoable;
        }
        if (extra && extra.plain) { turn.plain = extra.plain; }
        if (extra && extra.result) { turn.result = extra.result; }
        if (!jeton.arrete) { maConv.push(turn); memoriser(); }
        return turn;
      },
      /** Ligne d'état ajoutée au tour (échec de création arrivé plus tard) :
       *  « ✗ Titre : motif » devient un reçu d'échec, avec ses sorties. */
      note: function (turn, texte, opts) {
        turn.html += ligneRecu(texte, opts);
        renderConv();
        memoriser();
      },
      finish: function () {
        if (jeton.arrete) { return; }
        busy = false;
        renderConv();
        memoriser();
        rangerSiFerme(maConv);
      },
      fail: function (msg) {
        if (jeton.arrete) { return; }
        busy = false;
        maConv.push({ role: 'ia', html: '<p>' + esc(msg) + '</p>' });
        renderConv();
        memoriser();
      }
    };
  }

  /** Le titre du dernier reçu réussi d'un tour, ou ''. */
  function titreRecu(turn) {
    var m = /sendly-assist-recu sendly-assist-fait[^"]*"><span class="sendly-assist-signe">✓<\/span> <span class="sendly-assist-recu-titre">([^<]*)</.exec(String(turn && turn.html || ''));
    if (!m) { return ''; }
    var div = document.createElement('div');
    div.innerHTML = m[1];
    return div.textContent || '';
  }

  /** Carte fermée pendant que le Copilot travaillait : le résultat se range
   *  dans la pastille, avec son annulation à un clic. */
  function rangerSiFerme(tours) {
    if (panel() || !tours.length) { return; }
    tours[tours.length - 1].range = true;
    rangerDansPastille(tours[tours.length - 1]);
  }

  function rangerDansPastille(turn) {
    var titre = titreRecu(turn);
    if (!titre) { return; }
    var annulable = !!(turn.turnId && turn.undoable);
    poserPastille({
      mode: 'recu', texte: titre, duree: 30000,
      bouton: annulable ? t('mautic.core.ai.assistant.undo.short', 'Annuler') : '',
      action: annulable ? function () { annulerDepuis(turn); } : null
    });
  }

  function send(text) {
    var q = String(text || '').trim();
    if (!q || busy || !openCtx) {
      return;
    }
    // L'api capture le contexte du tour : si l'utilisateur navigue pendant la
    // requête, la réponse rejoint la BONNE conversation, pas celle du nouvel
    // écran.
    var jeton = {};
    var api = sendApi(jeton);
    conv().push({ role: 'user', text: q });
    busy = true;
    raccourcisOuverts = false;
    // Filet : une requête qui ne revient jamais ne bloque pas le panneau.
    enCours = jeton;
    setTimeout(function () {
      if (busy && enCours === jeton) { busy = false; renderConv(); }
    }, 120000);
    renderConv();
    var input = document.getElementById('sendly-assist-input');
    if (input) {
      input.value = '';
    }
    openCtx.onSend(q, api);
  }

  /** « Arrêter » : la demande en cours est abandonnée. Ce qui est déjà parti
   *  au serveur ne se rattrape pas (une création lancée va au bout) ; rien de
   *  ce qui revient ensuite n'est joué à l'écran. */
  function arreter() {
    if (!busy || !enCours) { return; }
    enCours.arrete = true;
    enCours = null;
    busy = false;
    conv().push({ role: 'ia', html: '<div class="sendly-assist-mut">' + esc(t('mautic.core.ai.assistant.stopped', 'Demande arrêtée. Rien n’a été modifié à l’écran.')) + '</div>' });
    renderConv();
  }

  /** L'annulation d'un tour, depuis la carte ou depuis la pastille. */
  function annulerDepuis(turn) {
    var ctx = openCtx || activeContext();
    if (!ctx || typeof ctx.onUndo !== 'function' || !turn || !turn.turnId) {
      return;
    }
    ctx.onUndo(turn.turnId, {
      markUndone: function (noteHtml) {
        turn.undoable = false;
        eteindre(turn.turnId);
        // Le reçu passe à l'état « annulé » : signe, titre, lignes barrées.
        turn.html = String(turn.html || '')
          .replace(/<div class="sendly-assist-recu sendly-assist-fait sendly-assist-annulable"><span class="sendly-assist-signe">✓<\/span> <span class="sendly-assist-recu-titre">[^<]*<\/span>/g,
            '<div class="sendly-assist-recu sendly-assist-annule"><span class="sendly-assist-signe">↩</span> <span class="sendly-assist-recu-titre">' + String(noteHtml).replace(/\.$/, '') + '</span>')
          .replace(/<!--sendly-annuler-->/g, '<span class="sendly-assist-recu-note">' +
            esc(t('mautic.core.ai.assistant.undone.note', 'Le formulaire est revenu à son état d’avant.')) + '</span>')
          // Ce qui a été défait ne se « voit » plus : le lien part avec.
          .replace(/<button type="button" class="sendly-assist-lien sendly-assist-voir"[^>]*>[^<]*<\/button>/g, '');
        if (turn.html.indexOf('sendly-assist-annule') === -1) {
          turn.html += '<div class="sendly-assist-mut">' + noteHtml + '</div>';
        }
        if (pastille.mode === 'recu') { poserPastille(null); }
        renderConv();
        memoriser();
      }
    });
  }

  function closePanel(sansRanger) {
    var p = panel();
    var tours = openCtx ? conv() : [];
    if (p) {
      p.classList.add('sendly-assist-surgit');
      p.id = ''; // le mourant ne répond plus aux sélecteurs (réouverture immédiate)
      var voile = document.getElementById('sendly-assist-voile');
      if (voile) { voile.classList.add('sendly-assist-surgit'); voile.id = ''; }
      setTimeout(function () {
        if (p.parentNode) { p.parentNode.removeChild(p); }
        if (voile && voile.parentNode) { voile.parentNode.removeChild(voile); }
      }, 260);
      document.removeEventListener('mousedown', horsCarte, true);
    }
    openCtx = null;
    raccourcisOuverts = false;
    var fab = fabEl();
    if (fab) { fab.classList.remove('sendly-assist-parti'); }
    // Un reçu tout juste rendu se range dans la pastille (annulation à un clic).
    if (p && !sansRanger && !busy && tours.length) {
      var dernier = tours[tours.length - 1];
      if (dernier.role === 'ia' && !dernier.range) { dernier.range = true; rangerDansPastille(dernier); }
    }
    // `busy` reste tel quel : une requête en cours n'est pas annulée par la
    // fermeture, et rouvrir le panneau ne doit pas permettre de la renvoyer.
  }

  /** Dès que l'on reprend la main dans la page après un reçu, la carte se
   *  range. Tant qu'elle attend (une réponse, une confirmation, une demande
   *  pas encore faite), un clic dans la page ne la ferme pas. */
  function horsCarte(e) {
    var p = panel();
    if (!p || p.contains(e.target) || busy) { return; }
    var fab = fabEl();
    if (fab && fab.contains(e.target)) { return; }
    var tours = conv();
    var dernier = tours[tours.length - 1];
    if (!dernier || dernier.role !== 'ia' || String(dernier.html).indexOf('data-carte="') !== -1) { return; }
    if (!titreRecu(dernier)) { return; }
    closePanel();
  }

  function openPanel(ctx) {
    if (panel()) {
      closePanel(true);
    }
    ensureStyles();
    openCtx = ctx;
    dejaVus = conv().length;
    poserPastille(null);

    var voile = document.createElement('div');
    voile.id = 'sendly-assist-voile';
    voile.className = 'sendly-assist-surgit';
    var el = document.createElement('section');
    el.id = 'sendly-assist-panel';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'false');
    el.className = 'sendly-assist-surgit';
    el.innerHTML =
      '<span class="sendly-assist-poignee"></span>' +
      '<header class="sendly-assist-head">' +
      '<div class="sendly-assist-tete-ligne">' +
      '<span class="sendly-assist-title" id="sendly-assist-title">' + esc(ctx.title()) + '</span>' +
      '<span class="sendly-assist-temps" hidden></span>' +
      '<button type="button" class="sendly-assist-iconbtn" id="sendly-assist-clear" hidden aria-label="' +
      esc(t('mautic.core.ai.assistant.clear', 'Vider la conversation')) + '">' + trace('corbeille', 15) + '</button>' +
      '<button type="button" class="sendly-assist-iconbtn" id="sendly-assist-close" aria-label="' +
      esc(t('mautic.core.form.close', 'Fermer')) + '">' + trace('croix', 14, 2.4) + '</button>' +
      '</div>' +
      '<div class="sendly-assist-heros"><div><h2></h2><span class="sendly-assist-temps" hidden></span></div></div>' +
      '</header>' +
      // Raccourcis d'emblée tant que la conversation est vide : un clic suffit
      // pour lancer une action (ensuite, sous « Raccourcis »).
      '<div class="sendly-assist-conv" id="sendly-assist-conv" aria-live="polite"></div>' +
      '<div class="sendly-assist-foot">' +
      '<input id="sendly-assist-input" type="text" autocomplete="off" aria-label="' + esc(ctx.placeholder()) +
      '" placeholder="' + esc(ctx.placeholder()) + '">' +
      '<button type="button" id="sendly-assist-send" aria-label="' +
      esc(t('mautic.core.ai.assistant.send', 'Envoyer')) + '">' + trace('haut', 18, 2.2) + '</button>' +
      '</div>' +
      '<div class="sendly-assist-meta">' +
      '<span>' + esc(t('mautic.core.ai.assistant.draft', 'Tout naît en brouillon. Rien ne part sans votre accord.')) + '</span>' +
      '<span class="sendly-assist-clavier">⌘J</span>' +
      '<button type="button" class="sendly-assist-lien" id="sendly-assist-shortcuts" hidden>' +
      esc(t('mautic.core.ai.assistant.shortcuts', 'Raccourcis')) + '</button>' +
      '</div>';

    document.body.appendChild(voile);
    document.body.appendChild(el);
    var fab = fabEl();
    if (fab) { fab.classList.add('sendly-assist-parti'); }
    // Deux cadres : l'état initial doit peindre avant la transition.
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        el.classList.remove('sendly-assist-surgit');
        voile.classList.remove('sendly-assist-surgit');
      });
    });

    // Le temps gagné ce mois-ci, l'argument du plan rendu visible : la même
    // pilule que la tuile du tableau de bord, seulement s'il y a de quoi dire.
    mQuery.ajax({ url: '/s/ai/credit/stats', type: 'GET', dataType: 'json' })
      .done(function (rep) {
        tempsMois = (rep && rep.seconds_month) || 0;
        renderHead();
      });

    voile.addEventListener('click', function () { closePanel(); });
    el.querySelector('#sendly-assist-close').addEventListener('click', function () { closePanel(); });
    el.querySelector('#sendly-assist-clear').addEventListener('click', function () {
      convStore[openCtx.id] = [];
      raccourcisOuverts = false;
      renderConv();
      var champ = document.getElementById('sendly-assist-input');
      if (champ) { champ.focus(); }
    });
    el.querySelector('#sendly-assist-send').addEventListener('click', function () {
      send(document.getElementById('sendly-assist-input').value);
    });
    el.querySelector('#sendly-assist-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        send(this.value);
      }
    });
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.stopPropagation();
        closePanel();
        var bouton = fabEl();
        if (bouton) { bouton.focus(); }
      }
    });
    el.querySelector('#sendly-assist-shortcuts').addEventListener('click', function () {
      raccourcisOuverts = !raccourcisOuverts;
      renderConv();
    });
    mQuery(el).on('click', '.sendly-assist-ex button', function () {
      send(this.getAttribute('data-demande') || this.textContent);
    });
    mQuery(el).on('click', '#sendly-assist-stop', arreter);
    mQuery(el).on('click', '.sendly-assist-confirmer, .sendly-assist-renoncer', function () {
      repondreConfirmation(String(mQuery(this).data('confirm')), mQuery(this).hasClass('sendly-assist-confirmer'));
    });
    mQuery(el).on('click', '.sendly-assist-annuler-envoi', function () {
      demanderAnnulationEnvoi(parseInt(this.getAttribute('data-email'), 10), String(this.getAttribute('data-tour')));
    });
    mQuery(el).on('click', '.sendly-assist-voir', function () {
      // « Voir le critère » : l'onglet s'ouvre, la carte se range.
      var onglet = document.querySelector('a[href="' + String(this.getAttribute('data-cible')).replace(/[^#\w-]/g, '') + '"]');
      if (onglet) { onglet.click(); }
      closePanel();
    });
    mQuery(el).on('click', '.sendly-assist-reformuler', function () {
      // La dernière demande revient dans le champ, prête à être corrigée.
      var demandes = conv().filter(function (turn) { return turn.role === 'user'; });
      var champ = document.getElementById('sendly-assist-input');
      if (champ && demandes.length) {
        champ.value = demandes[demandes.length - 1].text;
        champ.focus();
      }
    });
    mQuery(el).on('click', '.sendly-assist-undo', function () {
      var turnId = String(mQuery(this).data('turn'));
      var turn = conv().filter(function (x) { return String(x.turnId) === turnId; })[0];
      annulerDepuis(turn);
    });
    document.addEventListener('mousedown', horsCarte, true);

    renderConv();
    var input = document.getElementById('sendly-assist-input');
    if (input) {
      input.focus();
    }
  }

  function toggle() {
    if (panel()) {
      closePanel();
      return;
    }
    var ctx = activeContext();
    if (ctx) {
      openPanel(ctx);
    }
  }

  // Le nom accessible du lanceur suit l'onglet (« Sendly Copilot · Segment »
  // sur l'écran segment, « Sendly Copilot » ailleurs), comme le titre de la
  // carte ; en pastille, il dit l'état ou le reçu.
  function refreshLabel() {
    var fab = fabEl();
    if (!fab) {
      return;
    }
    var ctx = activeContext();
    var label = ctx ? ctx.title() : t('mautic.core.ai.assistant.button', 'Sendly Copilot');
    if (pastille.mode !== 'repos') {
      fab.setAttribute('aria-label', pastille.texte + (pastille.compte ? ', ' + pastille.compte : '') + '. ' + label);
      fab.removeAttribute('title');
      return;
    }
    fab.setAttribute('aria-label', label + ' (⌘J)');
    fab.setAttribute('title', label + ' (⌘J)');
  }

  /** Le compteur de la tuile « temps gagné » du tableau de bord suit à
   *  l'instant d'un gain (elle porte data-sendly-temps-mois). */
  function rafraichirTuile() {
    if (!document.querySelector || !document.querySelector('[data-sendly-temps-mois]')) { return; }
    mQuery.ajax({ url: '/s/ai/credit/stats', type: 'GET', dataType: 'json' }).done(function (rep) {
      var s = (rep && rep.seconds_month) || 0;
      tempsMois = s;
      var cible = document.querySelector('[data-sendly-temps-mois]');
      var d = dureeLisible(s);
      // « 27 min » → 27<small>min</small> : la forme du chiffre de la tuile.
      var neuf = d.replace(/\u00a0(h|min)/, '<small>$1</small>').replace(/\u00a0/g, ' ');
      if (cible && d && cible.innerHTML !== neuf) {
        cible.innerHTML = neuf;
        var tuile = cible.closest ? cible.closest('.sendly-tg') : null;
        if (tuile) { tuile.classList.remove('sendly-tg-neuf'); void tuile.offsetWidth; tuile.classList.add('sendly-tg-neuf'); }
      }
      renderHead();
    });
  }

  /** Petite façade publique pour les contextes (reset après navigation…). */
  window.SendlyAssistant = {
    /** Crédite le compteur de temps gagné (barème CÔTÉ SERVEUR — le client
     *  dit ce qui s'est passé, jamais combien ça vaut). Feu-et-oublie. */
    credit: function (type, quantite) {
      try {
        var appel = mQuery.ajax({
          url: '/s/ai/credit', type: 'POST', dataType: 'json',
          data: JSON.stringify({ type: type, quantite: quantite || 1 }),
          contentType: 'application/json'
        });
        if (appel && typeof appel.done === 'function') { appel.done(rafraichirTuile); }
      } catch (e) { /* le crédit est du bonus, jamais du chemin critique */ }
    },
    /** Une ligne d'état dans la conversation ouverte (échecs de création :
     *  la raison est DITE — référence introuvable, horaire invalide…). */
    note: function (texte) {
      var conv = document.getElementById('sendly-assist-conv');
      if (!conv) { return; }
      var div = document.createElement('div');
      div.className = 'sendly-assist-mut';
      div.textContent = texte;
      conv.appendChild(div);
      conv.scrollTop = conv.scrollHeight;
    },
    /** Vide la conversation d'un contexte ; ferme le panneau s'il l'affiche. */
    reset: function (ctxId) {
      delete convStore[ctxId];
      if (openCtx && openCtx.id === ctxId) {
        closePanel(true);
      }
      memoriser();
    },
    /** Ouvre le panneau sur un contexte précis (chantier D, P5 : la tuile
     *  « Assistant IA » de l'éditeur est la seule entrée IA — le lanceur
     *  flottant y est masqué, il fallait une porte programmatique). */
    open: function (ctxId) {
      var ctx = contexts().filter(function (c) { return c.id === ctxId && c.available(); })[0];
      if (ctx) {
        openPanel(ctx);
      }
    },
    /** Une création en plusieurs gestes s'annonce : la pastille compte les
     *  étapes sans rien couvrir (« Création de l’e-mail · 3 sur 5 »). */
    etape: function (libelle, n, total) {
      if (!fabEl()) { return; }
      poserPastille({ mode: 'travail', texte: libelle, compte: n && total ? n + '\u00a0sur\u00a0' + total : '', duree: 90000 });
    },
    /** La création a abouti : la pastille porte le reçu, puis se repose ; le
     *  détail (étapes, temps gagné) rejoint la conversation, où il se relit.
     *  o = {type, etapes: [libellés]}. */
    termine: function (libelle, o) {
      if (!fabEl()) { return; }
      var opts = o || {};
      var etapes = (opts.etapes || []).map(function (e) {
        return '<li><span class="sendly-assist-pt">' + trace('coche', 10, 3) + '</span><span>' + esc(e) + '</span></li>';
      }).join('');
      var html = recuHtml({
        titre: libelle, secondes: opts.type ? secondesAction(opts.type, 1) : 0,
        suite: etapes ? '<ol class="sendly-assist-etapes">' + etapes + '</ol>' : ''
      });
      var tours = convStore.help = convStore.help || [];
      var dernier = tours[tours.length - 1];
      if (dernier && dernier.role === 'ia') {
        dernier.html += html;
      } else {
        dernier = { role: 'ia', html: html };
        tours.push(dernier);
      }
      dernier.range = true;
      memoriser();
      if (panel()) { renderConv(); }
      // Dans l'éditeur, la pastille informe sans rien ouvrir.
      var ouvrable = !(document.body && document.body.classList.contains('sendly-builder-ouvert'));
      poserPastille({
        mode: 'recu', texte: libelle, duree: 12000,
        bouton: ouvrable ? t('mautic.core.ai.assistant.detail', 'Détail') : '',
        action: ouvrable ? function () { window.SendlyAssistant.open('help'); } : null
      });
    },
    /** La création s'est interrompue : la pastille se repose sans rien affirmer. */
    repos: function () {
      if (fabEl()) { poserPastille(null); }
    },
    /** Composition d'un reçu pour les contextes (même forme partout). */
    recu: recuHtml,
    ligne: ligneRecu,
    /** « ≈ 10 min » pour un type d'action, d'après le barème du serveur. */
    duree: function (type, quantite) {
      return dureeLisible(secondesAction(type, quantite));
    },
    secondes: secondesAction,
    allumer: allumer,
    eteindre: eteindre,
    /** Les dessins de la coquille, pour les autres surfaces du Copilot. */
    dessin: { eclat: eclat, trace: trace }
  };

  // ── Contexte PAR DÉFAUT : l'aide générale (priorité 0) ─────────────────
  // L'ACCOMPAGNEMENT SUIT L'ÉCRAN (exigence produit 12/08 — « tout le but
  // de cet assistant ») : le titre, l'accueil et les raccourcis de l'aide
  // se calquent sur la section où l'utilisateur se trouve, et la section
  // part AU SERVEUR pour des réponses contextualisées.
  // Raccourcis à l'IMPÉRATIF, et seulement ce que le Copilot sait FAIRE
  // (directive du 26/08 : exécuter, pas guider). Avant le 12/11 c'étaient
  // une trentaine de « Comment … ? », le contre-modèle exact.
  // Chaque raccourci : [libellé, demande envoyée, type d'action]. Le libellé
  // tient sur UNE ligne de la carte (36 caractères au plus, retour du
  // propriétaire du 25/11) ; la demande, elle, reste complète pour le modèle
  // (vide = le libellé). Le type donne le pictogramme et la durée estimée.
  var R_MOIS = ['Cibler les contacts ajoutés ce mois', 'Créer un segment des contacts ajoutés ce mois-ci', 'create_segment'];
  var R_JAMAIS = ['Cibler ceux qui n’ont jamais cliqué', 'Créer un segment des contacts qui n’ont jamais cliqué', 'create_segment'];
  var R_BIENVENUE = ['Rédiger un e-mail de bienvenue', '', 'create_email'];
  var R_INSCRIPTION = ['Créer un formulaire d’inscription', 'Créer un formulaire d’inscription à la newsletter', 'create_form'];
  var R_DEVIS = ['Créer un formulaire de devis', 'Créer un formulaire de demande de devis', 'create_form'];
  var SECTIONS_AIDE = [
    [/\/s\/contacts/, 'Contacts', [['Ouvrir l’import de contacts', '', 'navigate'], R_MOIS, R_INSCRIPTION]],
    [/\/s\/companies/, 'Sociétés', [['Ouvrir la liste des contacts', '', 'navigate'], ['Rapport des contacts créés ce mois', 'Créer un rapport des contacts créés ce mois-ci', 'create_report']]],
    [/\/s\/segments/, 'Segments', [R_JAMAIS, R_MOIS, ['Cibler les contacts sans ville', 'Créer un segment des contacts sans ville renseignée', 'create_segment']]],
    [/\/s\/campaigns/, 'Campagnes', [['Ouvrir une nouvelle campagne', '', 'navigate'], R_BIENVENUE, R_MOIS]],
    [/\/s\/emails/, 'E-mails', [R_BIENVENUE, ['Programmer cet e-mail demain à 9 h', 'Programmer l’envoi de cet e-mail demain à 9 h', 'schedule_email'], ['Tester l’objet de cet e-mail en A/B', 'Créer un test A/B de l’objet de cet e-mail', 'create_ab_test']]],
    [/\/s\/sms/, 'SMS', [['Ouvrir la liste des SMS', '', 'navigate'], ['Cibler les contacts avec un mobile', 'Créer un segment des contacts qui ont un numéro de mobile', 'create_segment']]],
    [/\/s\/forms/, 'Formulaires', [R_DEVIS, R_INSCRIPTION]],
    [/\/s\/pages/, 'Pages', [['Créer une page pour un webinaire', 'Créer une page d’inscription à un webinaire', 'create_landing_page'], ['Créer une page pour mon offre', 'Créer une page de présentation de mon offre', 'create_landing_page']]],
    [/\/s\/assets/, 'Ressources', [['Créer une page pour un livre blanc', 'Créer une page de téléchargement d’un livre blanc', 'create_landing_page']]],
    [/\/s\/dwc/, 'Contenu web dynamique', [['Ouvrir la liste des segments', '', 'navigate'], R_MOIS]],
    [/\/s\/points/, 'Points', [['Cibler au-delà de 50 points', 'Créer un segment des contacts de plus de 50 points', 'create_segment'], ['Ouvrir la liste des points', '', 'navigate']]],
    [/\/s\/stages/, 'Stages', [['Ouvrir la liste des stages', '', 'navigate']]],
    [/\/s\/reports/, 'Rapports', [['Rapport des ouvertures et des clics', 'Créer un rapport des ouvertures et des clics d’e-mails', 'create_report'], ['Rapport des formulaires remplis', 'Créer un rapport des soumissions de formulaires', 'create_report']]],
  ];
  function sectionCourante() {
    var chemin = window.location.pathname;
    for (var i = 0; i < SECTIONS_AIDE.length; i++) {
      if (SECTIONS_AIDE[i][0].test(chemin)) {
        return { nom: SECTIONS_AIDE[i][1], raccourcis: SECTIONS_AIDE[i][2] };
      }
    }
    return null;
  }

  // ── MODE EXÉCUTANT (directive proprio 26/08 : « un assistant qui FAIT
  //    gagner du temps, pas un guide ») ────────────────────────────────────
  // Le panneau décrit l'écran au serveur (screen_state) et déclare ce qu'il
  // sait EXÉCUTER ; le serveur renvoie des actions validées ; on les joue
  // ici, avec instantané par tour pour « Annuler les modifications ».

  /** Cible → chemin (le serveur porte la même liste blanche). */
  var NAV_CIBLES = {
    segments: '/s/segments', segments_new: '/s/segments/new',
    emails: '/s/emails', emails_new: '/s/emails/new',
    campaigns: '/s/campaigns', campaigns_new: '/s/campaigns/new',
    contacts: '/s/contacts', contacts_import: '/s/contacts/import/new',
    companies: '/s/companies',
    forms: '/s/forms', forms_new: '/s/forms/new',
    pages: '/s/pages', pages_new: '/s/pages/new',
    assets: '/s/assets', points: '/s/points', stages: '/s/stages',
    reports: '/s/reports', sms: '/s/sms'
  };

  /** Le formulaire de TRAVAIL de l'écran : le plus riche en champs visibles,
   *  hors panneau assistant et hors recherche. */
  /** Un champ compte s'il est visible — OU si c'est un select habillé par
   *  Chosen (le <select> natif est masqué, seul son habillage se voit) :
   *  recette import CSV 09/09, l'écran de correspondance n'exposait AUCUN
   *  champ et l'assistant « associait » dans le vide. */
  function champVisible(el) {
    var $el = mQuery(el);
    if ($el.is(':visible')) { return true; }
    // Éditeur de texte enrichi (Description d'un formulaire, d'un segment…) :
    // le <textarea> est masqué, l'éditeur affiché à sa place. Recette du
    // 12/11 : « cet écran n'affiche aucun champ Description ».
    if (el.tagName === 'TEXTAREA' && editeurRiche(el)) { return true; }
    if (el.tagName !== 'SELECT') { return false; }
    var chosen = el.id ? document.getElementById(el.id + '_chosen') : null;
    return !!(chosen && mQuery(chosen).is(':visible')) || $el.next('.chosen-container').is(':visible');
  }

  /** L'éditeur riche attaché à un <textarea>, ou null. Le moteur range ses
   *  éditeurs dans `ckEditors` (Map textarea → éditeur, 1a.content.js) ;
   *  à défaut, l'élément éditable porte lui-même son instance. */
  function editeurRiche(el) {
    try {
      /* global ckEditors */
      if (typeof ckEditors !== 'undefined' && ckEditors && ckEditors.get(el)) { return ckEditors.get(el); }
    } catch (e) { /* registre absent : repli ci-dessous */ }
    var suivant = el.nextElementSibling;
    var editable = suivant && suivant.querySelector ? suivant.querySelector('.ck-editor__editable') : null;
    return editable && editable.ckeditorInstance ? editable.ckeditorInstance : null;
  }

  /** Texte brut d'un éditeur riche (le relevé ne transporte pas de HTML). */
  function texteRiche(ed) {
    var div = document.createElement('div');
    div.innerHTML = String(ed.getData() || '');
    return (div.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function formulaireTravail() {
    var meilleur = null, score = 0;
    mQuery('form').each(function () {
      if (mQuery(this).closest('#sendly-assist-panel').length) { return; }
      var n = mQuery(this).find('input[name], select[name], textarea[name]')
        .filter(':not([type=hidden]):not([type=submit]):not([type=button]):not([name*="csrf"]):not([name*="_token"])')
        .filter(function () { return champVisible(this); }).length;
      if (n > score) { score = n; meilleur = this; }
    });
    return meilleur;
  }

  function libelleChamp(el) {
    var id = el.getAttribute('id');
    if (id) {
      var lab = document.querySelector('label[for="' + id + '"]');
      if (lab) { return lab.textContent.trim().slice(0, 60); }
    }
    return '';
  }

  // ── CONFIDENTIALITÉ DES FICHES (décision proprio 29/10, option A) ──────
  // Le relevé d'écran part au serveur, puis au modèle, un tiers. Sur une
  // fiche contact ou société (création comme édition), la VALEUR des champs
  // ne part plus : le modèle reçoit le nom, le libellé et « (filled) » ou
  // « (empty) ». Il sait ce qui est rempli sans voir la donnée, et peut
  // toujours PROPOSER une valeur (poserChamp lit et écrit le DOM, jamais
  // le relevé).
  // Règle PRUDENTE : sur ces fiches, toute valeur est masquée par défaut,
  // sauf une courte liste blanche de champs non personnels. Un champ
  // personnalisé inconnu (date de naissance, adresse, n° client…) est donc
  // masqué sans qu'on ait à le prévoir. Les coordonnées connues et les types
  // email/tel/url restent masqués même si quelqu'un les ajoute un jour à la
  // liste blanche. Le serveur rejoue la même règle (ScreenStatePrivacy) :
  // un onglet resté ouvert sur l'ANCIEN app.js (navigation ajax, aucun
  // rechargement) ne fait pas partir de valeur pour autant. Les listes
  // ci-dessous et celles du serveur sont tenues égales par un test.
  var FICHE_CHEMINS = /\/s\/(?:contacts\/(?:new|edit)|companies)(?:\/|$)/;
  var FICHE_FORMULAIRES = ['lead', 'company'];
  var FICHE_PREFIXE = /^(?:lead|company)\[([^\]]*)\]/;
  var FICHE_COORDONNEES = [
    'firstname', 'lastname', 'email', 'phone', 'mobile', 'fax', 'address1', 'address2',
    'city', 'zipcode', 'country', 'state', 'website', 'facebook', 'foursquare',
    'instagram', 'linkedin', 'skype', 'twitter', 'ip', 'ipaddress', 'ip_address',
    'companyname', 'companyemail', 'companyphone', 'companyfax', 'companyaddress1',
    'companyaddress2', 'companycity', 'companyzipcode', 'companycountry', 'companystate',
    'companywebsite'
  ];
  var FICHE_TYPES_COORDONNEES = ['email', 'tel', 'url'];
  var FICHE_NON_PERSONNELS = [
    'owner', 'stage', 'tags', 'points', 'projects', 'preferred_locale',
    'preferred_profile_image', 'score', 'companyindustry',
    'companynumber_of_employees', 'companyannual_revenue'
  ];

  /** `lead[email]` → `email`, `lead[tags][]` → `tags`, `search` → `search`. */
  function aliasFiche(name) {
    var m = FICHE_PREFIXE.exec(String(name || ''));
    return (m ? m[1] : String(name || '')).toLowerCase();
  }

  /** Vrai quand la VALEUR du champ ne doit pas quitter le navigateur.
   *  champ = {name, type (attribut HTML), chemin (pathname), formulaire
   *  (attribut name du <form>)}. Fonction pure : testée telle quelle. */
  function valeurMasquee(champ) {
    var name = String(champ.name || '');
    var surFiche = FICHE_CHEMINS.test(String(champ.chemin || '')) ||
      FICHE_FORMULAIRES.indexOf(String(champ.formulaire || '')) !== -1 ||
      FICHE_PREFIXE.test(name);
    if (!surFiche) { return false; }
    var alias = aliasFiche(name);
    if (FICHE_COORDONNEES.indexOf(alias) !== -1) { return true; }
    if (FICHE_TYPES_COORDONNEES.indexOf(String(champ.type || '').toLowerCase()) !== -1) { return true; }
    return FICHE_NON_PERSONNELS.indexOf(alias) === -1;
  }

  /** La ligne d'un champ masqué : nom, libellé, rempli ou vide. Jamais de
   *  valeur, jamais de liste d'options (un select chargé en ajax ne porte
   *  que l'option choisie : sa liste serait la valeur). */
  function ligneMasquee(name, lab, rempli) {
    return '- name="' + name + '"' + (lab ? ' label="' + lab + '"' : '') +
      (rempli ? ' (filled)' : ' (empty)');
  }

  window.SendlyAssistant.confidentialite = { masquee: valeurMasquee, ligne: ligneMasquee };

  /** L'état de l'écran, en texte sobre : un champ par ligne. */
  function etatEcran() {
    var form = formulaireTravail();
    if (!form) { return { texte: '', champs: 0 }; }
    var lignes = [], count = 0;
    // selects : leurs OPTIONS sont le vocabulaire — sans elles le modèle
    // devine des valeurs invalides. Les listes identiques ne partent qu'UNE
    // fois (l'écran d'import répète la même liste sur chaque colonne).
    var listes = {}, ordreListes = [];
    function refListe(sel) {
      var opts = [];
      mQuery(sel).find('option').each(function () {
        // 120 et non 40 : l'écran d'import liste ~70 champs contact+société
        // et « Nom de la société » (companyname) tombait hors liste — le
        // modèle remplissait avec une valeur voisine en prétendant l'inverse
        // (recette 10/09). Les listes identiques ne partent qu'une fois.
        if (opts.length >= 120) { return false; }
        var val = this.value;
        if ('' === val) { return; }
        var lib = (this.textContent || '').trim().slice(0, 30);
        opts.push(val === lib ? val : val + '=' + lib);
      });
      if (!opts.length) { return ''; }
      var signature = opts.join('|');
      if (!listes[signature]) {
        listes[signature] = 'L' + (ordreListes.length + 1);
        ordreListes.push({ id: listes[signature], opts: opts });
      }
      return listes[signature];
    }
    mQuery(form).find('input[name], select[name], textarea[name]')
      .filter(':not([type=hidden]):not([type=submit]):not([type=button]):not([type=password]):not([name*="csrf"]):not([name*="_token"])')
      .filter(function () { return champVisible(this); })
      .each(function () {
        if (count >= 25) { return false; }
        var champ = {
          name: this.name,
          type: this.getAttribute('type') || '',
          chemin: window.location.pathname,
          formulaire: form.getAttribute('name') || ''
        };
        if (valeurMasquee(champ)) {
          var edMasque = this.tagName === 'TEXTAREA' ? editeurRiche(this) : null;
          var brut = edMasque ? texteRiche(edMasque) : mQuery(this).val();
          var rempli = (this.type === 'checkbox' || this.type === 'radio')
            ? !!this.checked
            : String(brut == null ? '' : brut) !== '';
          lignes.push(ligneMasquee(this.name, libelleChamp(this), rempli));
          count++;
          return;
        }
        var ed = this.tagName === 'TEXTAREA' ? editeurRiche(this) : null;
        var v = (ed ? texteRiche(ed) : String(mQuery(this).val() == null ? '' : mQuery(this).val())).slice(0, 160);
        var lab = libelleChamp(this);
        var ref = this.tagName === 'SELECT' ? refListe(this) : '';
        lignes.push('- name="' + this.name + '"' + (lab ? ' label="' + lab + '"' : '') +
          (ref ? ' options=@' + ref : '') +
          (v ? ' value="' + v + '"' : ' (empty)'));
        count++;
      });
    ordreListes.forEach(function (l) {
      lignes.push('@' + l.id + ': ' + l.opts.join(' | '));
    });
    return {
      texte: count ? 'Screen: ' + window.location.pathname + '\nForm fields:\n' + lignes.join('\n') : '',
      champs: count
    };
  }

  /** snapshots[turnId] = [{name, old}] pour l'annulation. */
  var snapshots = {};

  /** Sur un select, une valeur qui n'est pas une option est cherchée par
   *  LIBELLÉ (sans casse ni accents) : le modèle répond parfois « Nom de la
   *  société » au lieu de `companyname`. Sans correspondance, on ne touche
   *  à rien — jamais un « champ rempli » qui n'a rien rempli. */
  function normaliser(t) {
    return String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLowerCase();
  }
  function resoudreValeurSelect(sel, value) {
    var opts = Array.prototype.slice.call(sel.options || []);
    var exacte = opts.filter(function (o) { return o.value === value; })[0];
    if (exacte) { return value; }
    var cible = normaliser(value);
    var parLibelle = opts.filter(function (o) { return o.value !== '' && normaliser(o.textContent) === cible; })[0]
      || opts.filter(function (o) { return o.value !== '' && normaliser(o.value) === cible; })[0];
    return parLibelle ? parLibelle.value : null;
  }

  function poserChamp(form, name, value, releve) {
    var el = mQuery(form).find('[name="' + name + '"]').filter(':not([type=hidden])').first();
    if (!el.length) { return false; }
    var ed = el.is('textarea') ? editeurRiche(el.get(0)) : null;
    if (ed) {
      // La valeur du modèle est du TEXTE : échappée, un paragraphe par ligne.
      releve.push({ name: name, old: ed.getData(), riche: true });
      ed.setData(String(value).split(/\n+/).map(function (l) { return '<p>' + esc(l) + '</p>'; }).join(''));
      if (typeof ed.updateSourceElement === 'function') { ed.updateSourceElement(); }
      return true;
    }
    if (el.is('select')) {
      value = resoudreValeurSelect(el.get(0), value);
      if (null === value) { return false; }
    }
    releve.push({ name: name, old: el.val() });
    el.val(value).trigger('change').trigger('keyup');
    // Chosen (selects Mautic) : rafraîchir le rendu.
    if (el.is('select')) { el.trigger('chosen:updated'); }
    return true;
  }

  /** Ce qu'une action aurait fait, en mots de client : pour dire ce qui n'a
   *  PAS été fait (recette du 11/11 : « je crée » sans aucun effet). */
  var LIBELLES_ACTIONS = {
    fill_field: 'remplir un champ',
    navigate: 'ouvrir l’écran demandé',
    create_segment: 'créer le segment',
    create_email: 'créer l’e-mail',
    create_form: 'créer le formulaire',
    create_campaign: 'créer la campagne',
    create_report: 'créer le rapport',
    create_landing_page: 'créer la page',
    schedule_email: 'programmer l’envoi',
    create_ab_test: 'créer le test A/B'
  };

  /** Motif d'échec d'une création, par code renvoyé par le serveur. Tout
   *  code inconnu a un motif générique : plus jamais d'échec muet. */
  var MOTIFS_CREATION = {
    email_not_found: 'e-mail introuvable',
    email_ambiguous: 'plusieurs e-mails portent ce nom',
    segment_not_found: 'segment introuvable',
    segment_ambiguous: 'plusieurs segments portent ce nom',
    bad_send_at: 'horaire invalide (passé ou trop lointain)',
    forbidden: 'votre compte n’a pas le droit de créer cet élément',
    save_failed: 'l’enregistrement a échoué',
    source_unavailable: 'cette source de données n’est pas disponible sur votre espace',
    entitlement: 'cette action fait partie de l’offre Pro',
    disabled: 'Sendly Copilot n’est pas activé sur votre espace',
    bad_request: 'la demande était incomplète',
    not_found: 'élément introuvable',
    not_segment_email: 'seul un e-mail de segment se programme (un e-mail modèle part par une campagne)',
    no_segment: 'cet e-mail n’a aucun segment destinataire',
    is_variant: 'c’est une variante A/B : programmez l’e-mail principal',
    publish_down_before: 'sa date de fin de publication tombe avant l’envoi',
    invalid_parent: 'cet e-mail est déjà une variante ou une traduction',
    no_traffic_budget: 'il n’y a plus de place pour de nouvelles variantes'
  };

  /** Message d'échec d'un appel au Copilot, selon la cause : l'offre, le
   *  service, la demande ou le réseau. Exposé aux autres contextes. */
  function messageErreur(xhr) {
    var code = xhr && xhr.responseJSON ? xhr.responseJSON.error : null;
    var statut = xhr ? xhr.status : 0;
    if ('entitlement' === code || 403 === statut) {
      return 'Sendly Copilot fait partie de l’offre Pro de votre espace.';
    }
    if ('disabled' === code || 503 === statut) {
      return 'Sendly Copilot n’est pas activé sur votre espace.';
    }
    if ('bad_request' === code || 400 === statut) {
      return 'Je n’ai pas compris la demande. Reformulez-la en une phrase.';
    }
    if (!statut) {
      return 'La connexion a été interrompue. Vérifiez votre réseau, puis réessayez.';
    }
    return t('mautic.core.ai.assistant.error', 'Le service est momentanément indisponible. Réessayez dans un instant.');
  }
  window.SendlyAssistant.messageErreur = messageErreur;

  /** L'e-mail ouvert à l'écran (édition ou fiche), ou 0. */
  function emailCourant() {
    var m = /\/s\/emails\/(edit|view)\/(\d+)/.exec(window.location.pathname);
    return m ? parseInt(m[2], 10) : 0;
  }
  function emailEnEdition() {
    return /\/s\/emails\/edit\/\d+/.test(window.location.pathname) ? emailCourant() : 0;
  }

  /** Confirmations en attente : id → {payload, tour, carte}. Une action qui
   *  part chez de vrais contacts n'est JAMAIS exécutée sans ce clic
   *  (décision du propriétaire du 11/11). */
  var confirmations = {};
  var confirmationSeq = 0;
  /** Les tours qui portent un bouton « Annuler l'envoi » : clé → tour. */
  var toursParCle = {};
  var tourSeq = 0;

  /** 5464 → « 5 464 ». */
  function nombre(n) {
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
  }

  /** La carte de confirmation : quoi, quand, à qui, combien. Une information
   *  par ligne, le nombre de destinataires en grand. `rep` est la
   *  prévisualisation renvoyée par le serveur. */
  function carteConfirmation(id, rep) {
    var annulation = rep.mode === 'cancel';
    var segments = rep.segments || [];
    var connu = !(rep.recipients === null || rep.recipients === undefined);
    var lignes = [['E-mail', rep.name]];
    if (!annulation) {
      lignes.push(['Objet', rep.subject]);
      lignes.push(['Départ', rep.send_at_label]);
      lignes.push([segments.length > 1 ? 'Segments' : 'Segment', segments.join(', ')]);
    }
    return '<div class="sendly-assist-carte" data-carte="' + id + '">' +
      '<span class="sendly-assist-etiquette">À confirmer</span>' +
      '<h3>' + (annulation ? 'Annuler cet envoi ?' : 'Programmer cet envoi ?') + '</h3>' +
      (annulation
        ? '<p class="sendly-assist-mut">L’e-mail sera dépublié et ne partira plus.</p>'
        : '<p class="sendly-assist-grand">' + (connu
          ? '<b>' + esc(nombre(rep.recipients)) + '</b> <span>destinataire' + (rep.recipients > 1 ? 's' : '') + ' à ce jour</span>'
          : '<span>Nombre de destinataires indisponible.</span>') + '</p>') +
      lignesHtml(lignes) +
      '<div class="sendly-assist-carte-actions">' +
      '<button type="button" class="sendly-assist-bouton sendly-assist-confirmer" data-confirm="' + id + '">' +
      (annulation ? 'Confirmer l’annulation' : 'Confirmer l’envoi') + '</button>' +
      '<button type="button" class="sendly-assist-bouton sendly-assist-renoncer" data-confirm="' + id + '">Ne rien faire</button>' +
      '</div></div>';
  }

  /** La prévisualisation est arrivée : la carte attend le clic. */
  function ouvrirConfirmation(rep, payload) {
    var id = 'c' + (++confirmationSeq);
    payload.email_id = rep.id;
    delete payload.email;
    payload.mode = rep.mode;
    confirmations[id] = { id: id, payload: payload, recipients: rep.recipients };
    return id;
  }

  /** Remplace la carte d'une confirmation par une ligne d'état. */
  function fermerCarte(c, ligneHtml, suite) {
    var motif = new RegExp('<div class="sendly-assist-carte" data-carte="' + c.id + '">[\\s\\S]*?</div></div>');
    c.tour.html = c.tour.html.replace(motif, ligneHtml);
    if (suite) { c.tour.result = (c.tour.result ? c.tour.result + '; ' : '') + suite; }
    renderConv();
    memoriser();
  }

  /** Après une programmation, l'écran d'édition du même e-mail reflète
   *  l'état réel : un « Enregistrer » ultérieur ne doit pas l'écraser. */
  function synchroniserFormulaireEmail(rep) {
    if (emailEnEdition() !== rep.id) { return; }
    var publie = rep.mode === 'schedule' ? '1' : '0';
    mQuery('input[name="emailform[isPublished]"]').each(function () {
      this.checked = (this.value === publie);
    }).trigger('change');
    if (rep.mode === 'schedule') {
      mQuery('input[name="emailform[publishUp]"]').val(rep.send_at).trigger('change');
    }
  }

  /** Le reçu d'un envoi programmé porte son annulation à un clic (elle
   *  repasse par la même confirmation que la demande écrite). */
  function recuProgrammation(c, rep) {
    if (rep.mode === 'cancel') {
      return recuHtml({ titre: 'Envoi annulé', lignes: [['E-mail', rep.name]], note: 'L’e-mail est dépublié : il ne partira plus.' });
    }
    var cle = c.tour.cle || (c.tour.cle = 't' + (++tourSeq));
    toursParCle[cle] = c.tour;
    var lignes = [['E-mail', rep.name], ['Départ', rep.send_at_label]];
    if (!(c.recipients === null || c.recipients === undefined)) {
      lignes.push(['Destinataires', nombre(c.recipients) + ' à ce jour']);
    }
    return recuHtml({
      titre: 'Envoi programmé', lignes: lignes, secondes: secondesAction('schedule_email', 1),
      pied: '<button type="button" class="sendly-assist-bouton sendly-assist-fantome sendly-assist-annuler-envoi" data-email="' +
        parseInt(rep.id, 10) + '" data-tour="' + cle + '">Annuler l’envoi</button>',
      note: 'Annulable jusqu’au départ.'
    });
  }

  function repondreConfirmation(id, ok) {
    var c = confirmations[id];
    if (!c || c.repondu) { return; }
    c.repondu = true;
    var annulation = c.payload.mode === 'cancel';
    if (!ok) {
      fermerCarte(c, recuHtml({
        etat: 'annule', titre: annulation ? 'Rien n’a été annulé' : 'Rien n’a été programmé',
        motif: 'vous avez choisi de ne rien faire.'
      }), 'user declined: ' + c.payload.mode);
      return;
    }
    fermerCarte(c, '<div class="sendly-assist-mut sendly-assist-encours">' + esc(annulation ? 'Annulation en cours…' : 'Programmation en cours…') + '</div>');
    mQuery.ajax({
      url: cfg().scheduleEndpoint, type: 'POST', dataType: 'json', contentType: 'application/json',
      data: JSON.stringify(mQuery.extend({}, c.payload, { confirm: true })),
      success: function (rep) {
        var html = rep && rep.done
          ? recuProgrammation(c, rep)
          : ligneRecu('✗ La programmation n’a pas abouti : la réponse du serveur était incomplète.');
        c.tour.html = c.tour.html.replace(MOTIF_ENCOURS, html);
        c.tour.result = (c.tour.result ? c.tour.result + '; ' : '') + (rep && rep.done ? (rep.mode === 'cancel' ? 'send cancelled' : 'send scheduled ' + rep.send_at) : 'schedule failed');
        if (rep && rep.done) { synchroniserFormulaireEmail(rep); rafraichirTuile(); }
        renderConv();
        memoriser();
      },
      error: function (xhr) {
        var code = xhr && xhr.responseJSON ? xhr.responseJSON.error : null;
        c.tour.html = c.tour.html.replace(MOTIF_ENCOURS,
          ligneRecu('✗ La programmation n’a pas abouti : ' + ((code && MOTIFS_CREATION[code]) || 'le serveur a refusé la demande') + '.'));
        c.tour.result = (c.tour.result ? c.tour.result + '; ' : '') + 'schedule failed: ' + (code || (xhr ? xhr.status : 0));
        renderConv();
        memoriser();
      }
    });
  }

  // Exposée pour les tests et les autres contextes : le panneau l'appelle
  // depuis les boutons de la carte. N'ouvre aucun droit nouveau (l'endpoint
  // revalide tout, droits compris).
  window.SendlyAssistant.repondreConfirmation = repondreConfirmation;

  /** « Annuler l'envoi », depuis le reçu : la même prévisualisation et la
   *  même confirmation que la demande écrite, sans avoir à la taper. */
  function demanderAnnulationEnvoi(emailId, cle) {
    if (!emailId || !cfg() || !cfg().scheduleEndpoint) { return; }
    var tour = toursParCle[cle];
    if (!tour) {
      // Reçu d'avant un rechargement : la suite s'écrit dans un nouveau tour.
      tour = { role: 'ia', html: '' };
      conv().push(tour);
    }
    if (String(tour.html).indexOf('data-carte="') !== -1) { return; }
    var payload = { cancel: true, email_id: emailId };
    mQuery.ajax({
      url: cfg().scheduleEndpoint, type: 'POST', dataType: 'json', contentType: 'application/json',
      data: JSON.stringify(payload),
      success: function (rep) {
        if (!rep || !rep.id) {
          tour.html += ligneRecu('✗ Annulation impossible : la réponse du serveur était incomplète.');
        } else {
          var id = ouvrirConfirmation(rep, payload);
          confirmations[id].tour = tour;
          tour.html += carteConfirmation(id, rep);
          tour.result = (tour.result ? tour.result + '; ' : '') + 'confirmation card shown: cancel « ' + rep.name + ' »';
        }
        renderConv();
        memoriser();
      },
      error: function (xhr) {
        var code = xhr && xhr.responseJSON ? xhr.responseJSON.error : null;
        tour.html += ligneRecu('✗ Annulation impossible : ' + ((code && MOTIFS_CREATION[code]) || 'le serveur a refusé la demande') + '.');
        renderConv();
        memoriser();
      }
    });
  }
  window.SendlyAssistant.demanderAnnulationEnvoi = demanderAnnulationEnvoi;

  /** Ce que dit le reçu à l'arrivée sur l'écran demandé. */
  var NAV_ARRIVEES = {
    segments: 'Liste des segments ouverte', segments_new: 'Nouveau segment ouvert',
    emails: 'Liste des e-mails ouverte', emails_new: 'Nouvel e-mail ouvert',
    campaigns: 'Liste des campagnes ouverte', campaigns_new: 'Nouvelle campagne ouverte',
    contacts: 'Liste des contacts ouverte', contacts_import: 'Import de contacts ouvert',
    companies: 'Liste des sociétés ouverte',
    forms: 'Liste des formulaires ouverte', forms_new: 'Nouveau formulaire ouvert',
    pages: 'Liste des pages ouverte', pages_new: 'Nouvelle page ouverte',
    assets: 'Liste des ressources ouverte', points: 'Liste des points ouverte', stages: 'Liste des stages ouverte',
    reports: 'Liste des rapports ouverte', sms: 'Liste des SMS ouverte'
  };
  var CREATIONS_ARRIVEES = {
    create_campaign: 'Campagne créée, en brouillon',
    create_report: 'Rapport créé',
    create_form: 'Formulaire créé, en brouillon'
  };

  /** Les sorties d'un échec : aller voir ce qui existe, ou reformuler. */
  function sortiesEchec(suite) {
    var quoi = /(email|segment)_(?:not_found|ambiguous)/.exec(String(suite || ''));
    return (quoi ? '<a class="sendly-assist-bouton sendly-assist-fantome" href="' + (quoi[1] === 'email' ? '/s/emails' : '/s/segments') + '">' +
      (quoi[1] === 'email' ? 'Voir mes e-mails' : 'Voir mes segments') + '</a>' : '') +
      '<button type="button" class="sendly-assist-lien sendly-assist-reformuler">Reformuler</button>';
  }

  /** Joue les actions du serveur.
   *
   *  Renvoie {note, undoable, resume} : `note` ne dit QUE ce qui est fait ou
   *  en cours (jamais un ✓ d'avance), `resume` raconte au modèle, au tour
   *  suivant, ce qui s'est réellement passé. `apresCoup(texte, resume)` est
   *  appelé si une création échoue après la réponse. */
  function executerActions(actions, turnId, apresCoup, ajoutHtml) {
    var form = formulaireTravail();
    var tentes = 0, remplis = 0, releve = [], differes = [], creationsEntite = [], details = [];
    var resume = [];
    var notes = [];
    (actions || []).forEach(function (a) {
      if (a.type === 'fill_field') {
        tentes++;
        if (form && poserChamp(form, a.field, a.value, releve)) {
          remplis++;
          // Le reçu nomme chaque champ rempli, et le champ s'allume dans la page.
          var pose = mQuery(form).find('[name="' + a.field + '"]').filter(':not([type=hidden])').first().get(0);
          details.push([(pose && libelleChamp(pose)) || a.field, String(a.value).slice(0, 120)]);
          allumer(pose, turnId, 1 === details.length ? 'Rempli par Copilot' : '');
        }
      } else if (a.type === 'navigate' && NAV_CIBLES[a.target]) {
        differes.push({ url: NAV_CIBLES[a.target], quoi: 'navigate', cible: a.target });
      } else if (a.type === 'create_segment' && a.description) {
        try { sessionStorage.setItem('sendlyAiSegmentBrief', JSON.stringify({ brief: a.description, ts: Date.now() })); } catch (e) {}
        differes.push({ url: NAV_CIBLES.segments_new, quoi: 'create_segment' });
      } else if (a.type === 'create_email' && a.description && a.name && a.subject) {
        // Même patron que la landing page : le brief voyage jusqu'à l'écran
        // « nouvel e-mail », qui choisit le type segment, remplit nom/objet,
        // fait correspondre le segment et ouvre l'éditeur (ai-email-builder).
        try {
          sessionStorage.setItem('sendlyAiEmailBrief', JSON.stringify({
            name: a.name, subject: a.subject, brief: a.description,
            segment: a.segment || '', ts: Date.now()
          }));
        } catch (e) {}
        differes.push({ url: NAV_CIBLES.emails_new, quoi: 'create_email' });
      } else if (a.type === 'create_campaign' && a.name && a.email && a.segment) {
        // Endpoint gardé, entité en BROUILLON, écran d'édition ouvert pour
        // relecture du canvas.
        creationsEntite.push({ url: '/s/ai/campaign/create', spec: a, edition: '/s/campaigns/edit/', quoi: 'create_campaign' });
      } else if (a.type === 'create_report' && a.name && a.source) {
        creationsEntite.push({ url: '/s/ai/report/create', spec: a, edition: '/s/reports/edit/', quoi: 'create_report' });
      } else if (a.type === 'create_form' && a.name && a.fields) {
        creationsEntite.push({ url: (cfg().formCreateEndpoint || '/s/ai/form/create'), spec: a, edition: '/s/forms/edit/', quoi: 'create_form' });
      } else if (a.type === 'schedule_email') {
        // Jamais exécuté d'emblée : PRÉVISUALISATION, puis carte de
        // confirmation. C'est le clic de l'utilisateur qui programme.
        var payload = { cancel: a.cancel === '1' };
        if (a.email) { payload.email = a.email; } else if (emailCourant()) { payload.email_id = emailCourant(); }
        if (a.send_at) { payload.send_at = a.send_at; }
        resume.push((payload.cancel ? 'cancel' : 'schedule') + ' prepared, waiting for the user confirmation');
        mQuery.ajax({
          url: cfg().scheduleEndpoint, type: 'POST', dataType: 'json', contentType: 'application/json',
          data: JSON.stringify(payload),
          success: function (rep) {
            if (!rep || !rep.id) {
              apresCoup('✗ La programmation n’a pas abouti : la réponse du serveur était incomplète.', 'schedule preview failed');
              return;
            }
            var id = ouvrirConfirmation(rep, payload);
            ajoutHtml(carteConfirmation(id, rep),
              'confirmation card shown: ' + rep.mode + ' « ' + rep.name + ' »', function (tour) { confirmations[id].tour = tour; });
          },
          error: function (xhr) {
            var rep = xhr && xhr.responseJSON;
            var code = rep && rep.error;
            apresCoup('✗ Programmation impossible : ' + ((code && MOTIFS_CREATION[code]) || 'le serveur a refusé la demande') +
              (rep && rep.ref ? ' (« ' + rep.ref + ' »)' : '') + '.', 'schedule refused: ' + (code || xhr.status));
          }
        });
      } else if (a.type === 'create_ab_test' && a.subjects && a.subjects.length && emailEnEdition()) {
        resume.push('A/B test requested (' + a.subjects.length + ' subject(s))');
        mQuery.ajax({
          url: cfg().abtestEndpoint, type: 'POST', dataType: 'json',
          data: { emailId: emailEnEdition(), subjects: a.subjects },
          success: function (rep) {
            var n = rep && rep.created ? rep.created.length : 0;
            if (!n) {
              apresCoup('✗ Aucune variante créée : ces objets existent déjà ou sont identiques à l’objet actuel.', 'A/B test: nothing created');
              return;
            }
            if (window.SendlyAssistant) { window.SendlyAssistant.credit('create_ab_test', 1); }
            ajoutHtml(ligneRecu('✓ Test A/B créé : ' + n + ' variante' + (n > 1 ? 's' : '') + ' d’objet' +
              (rep && false === rep.published ? ' (en brouillon : votre compte n’a pas le droit de les publier)' : '') + '.',
              { secondes: secondesAction('create_ab_test', 1) }),
              'A/B test created: ' + n + ' variant(s)');
          },
          error: function (xhr) {
            var code = xhr && xhr.responseJSON ? xhr.responseJSON.error : null;
            apresCoup('✗ Le test A/B n’a pas été créé : ' + ((code && MOTIFS_CREATION[code]) || 'le serveur a refusé la demande') + '.', 'A/B test failed: ' + (code || xhr.status));
          }
        });
      } else if (a.type === 'create_ab_test') {
        notes.push('✗ Test A/B non créé : ouvrez d’abord l’e-mail concerné en édition.');
        resume.push('A/B test not created: no email open in edition');
      } else if (a.type === 'create_landing_page' && a.description && a.name) {
        // Le brief voyage jusqu'à l'écran « nouvelle page », qui crée la
        // page, ouvre le builder et génère les sections (ai-page-builder).
        try {
          sessionStorage.setItem('sendlyAiPageBrief', JSON.stringify({
            name: a.name, brief: a.description, sections: a.sections || [], ts: Date.now()
          }));
        } catch (e) {}
        differes.push({ url: NAV_CIBLES.pages_new, quoi: 'create_landing_page' });
      }
    });
    if (releve.length) { snapshots[turnId] = { champs: releve }; }
    if (remplis && window.SendlyAssistant) { window.SendlyAssistant.credit('fill_field', remplis); }

    if (tentes) {
      if (!remplis) {
        notes.push('✗ Aucun champ rempli : le champ demandé est introuvable sur cet écran.');
      } else {
        notes.push('✓ ' + remplis + ' ' + (remplis > 1 ? 'champs remplis' : 'champ rempli') +
          (tentes > remplis ? ' · ' + (tentes - remplis) + ' introuvable' + (tentes - remplis > 1 ? 's' : '') : ''));
      }
      resume.push('filled ' + remplis + ' of ' + tentes + ' field(s)');
    }

    // UNE création ou UNE navigation par message (elles quittent l'écran) :
    // les suivantes sont DITES non lancées, jamais tues.
    var enAttente = creationsEntite.concat(differes);
    var lancee = enAttente[0];
    enAttente.slice(1).forEach(function (x) {
      notes.push('✗ Non lancé dans ce message : ' + (LIBELLES_ACTIONS[x.quoi] || x.quoi) + '. Redemandez-le ensuite.');
      resume.push('not started: ' + x.quoi);
    });

    if (lancee && lancee.spec) {
      notes.push('Création en cours (en brouillon), ouverture de l’écran…');
      resume.push('creation requested: ' + lancee.quoi);
      mQuery.ajax({
        url: lancee.url,
        type: 'POST',
        dataType: 'json',
        data: JSON.stringify(lancee.spec),
        contentType: 'application/json',
        success: function (rep) {
          if (rep && rep.id) {
            // Le reçu attend à l'arrivée, sur l'écran d'édition.
            promettreArrivee(CREATIONS_ARRIVEES[lancee.quoi] || 'Création terminée, en brouillon', lancee.quoi);
            window.location.assign(lancee.edition + rep.id);
            return;
          }
          apresCoup('✗ La création n’a pas abouti : la réponse du serveur était incomplète.', 'creation failed: no id');
        },
        error: function (xhr) {
          var rep = xhr && xhr.responseJSON;
          var code = rep && rep.error;
          var motif = (code && MOTIFS_CREATION[code]) || 'le serveur a refusé la demande';
          apresCoup('✗ La création n’a pas abouti : ' + motif + (rep && rep.ref ? ' (« ' + rep.ref + ' »)' : '') + '.',
            'creation failed: ' + (code || xhr.status));
        }
      });
    } else if (lancee) {
      // Une seule navigation par tour, après un court instant de lecture.
      // Une simple navigation rend son reçu à l'arrivée ; une création par
      // relais (segment, e-mail, page) l'écrira elle-même, étape par étape.
      if ('navigate' === lancee.quoi) { promettreArrivee(NAV_ARRIVEES[lancee.cible] || 'Écran ouvert', ''); }
      setTimeout(function () { window.location.assign(lancee.url); }, 1200);
      notes.push('→ ' + t('mautic.core.ai.assistant.opening', 'Ouverture de l’écran…'));
      resume.push('opening screen' + (lancee.cible ? ' ' + lancee.cible : '') + ' for ' + lancee.quoi);
    }

    return { note: notes.join(' · '), notes: notes, undoable: releve.length > 0, resume: resume.join('; '), remplis: details };
  }

  function annulerTour(turnId) {
    var snap = snapshots[turnId];
    if (!snap) { return false; }
    var form = formulaireTravail();
    (snap.champs || []).forEach(function (c) {
      var el = mQuery(form || document).find('[name="' + c.name + '"]').first();
      var ed = c.riche && el.length ? editeurRiche(el.get(0)) : null;
      if (ed) {
        ed.setData(c.old);
        if (typeof ed.updateSourceElement === 'function') { ed.updateSourceElement(); }
        return;
      }
      if (el.length) {
        el.val(c.old).trigger('change');
        if (el.is('select')) { el.trigger('chosen:updated'); }
      }
    });
    delete snapshots[turnId];
    return true;
  }

  /** L'historique envoyé au serveur : les 6 derniers tours, sans le tour
   *  courant (le serveur reçoit la question à part). Fonction pure, testée. */
  function historiqueAide(tours, q) {
    var history = [];
    tours.slice(-6).forEach(function (turn) {
      if (turn.role === 'user') {
        history.push({ role: 'user', content: turn.text });
      } else if (turn.plain || turn.result) {
        history.push({
          role: 'assistant',
          content: (turn.plain || '') + (turn.result ? '\n[What actually happened on screen: ' + turn.result + ']' : '')
        });
      }
    });
    return history.filter(function (h, i, arr) {
      return !(i === arr.length - 1 && h.role === 'user' && h.content === q);
    });
  }
  window.SendlyAssistant.historiqueAide = historiqueAide;

  /** L'écran courant, pour ne jamais exécuter une réponse sur un AUTRE
   *  écran que celui de la question (réponse lente + navigation). */
  function ecranCourant() {
    return window.location.pathname + window.location.search;
  }

  window.SendlyAssistantContexts = window.SendlyAssistantContexts || [];
  window.SendlyAssistantContexts.push({
    id: 'help',
    priority: 0,
    available: function () {
      var c = cfg();
      return !!(c && c.assistEndpoint);
    },
    title: function () {
      var s = sectionCourante();
      var nom = t('mautic.core.ai.assistant.button', 'Sendly Copilot');
      return s ? nom + ' · ' + s.nom : nom;
    },
    /** Le titre de l'accueil, sur deux lignes : la promesse, pas un bonjour. */
    hero: function () {
      return t('mautic.core.ai.assistant.hero1', 'Dites ce qu’il faut faire.') + '\n' +
        t('mautic.core.ai.assistant.hero2', 'Copilot s’en occupe.');
    },
    welcome: function () {
      var s = sectionCourante();
      if (s) {
        return 'Bonjour ! Dites-moi ce que vous voulez faire dans « ' + s.nom + ' », je m’en occupe.';
      }
      return t('mautic.core.ai.assistant.welcome',
        'Bonjour ! Dites-moi ce que vous voulez faire, je m’en occupe : un segment, un e-mail, un formulaire, une page…');
    },
    placeholder: function () {
      return t('mautic.core.ai.assistant.placeholder', 'Que voulez-vous faire ?');
    },
    thinking: function () {
      return t('mautic.core.ai.assistant.thinking', 'Je m’en occupe…');
    },
    shortcuts: function () {
      var s = sectionCourante();
      if (s) { return s.raccourcis; }
      return [
        [R_JAMAIS[0], t('mautic.core.ai.assistant.suggest1', 'Créer un segment des contacts qui n’ont jamais cliqué'), 'create_segment'],
        [R_BIENVENUE[0], t('mautic.core.ai.assistant.suggest2', 'Rédiger un e-mail de bienvenue'), 'create_email'],
        [R_DEVIS[0], t('mautic.core.ai.assistant.suggest3', 'Créer un formulaire de demande de devis'), 'create_form']
      ];
    },
    onSend: function (q, api) {
      // L'historique alterne user/assistant. Un tour assistant porte sa
      // réponse ET ce qui s'est réellement passé à l'écran (`result`) : sans
      // cette trace, le modèle relisait « j'ai créé » et se croyait passé à
      // l'acte, même quand rien n'avait été fait.
      var history = historiqueAide(conv(), q);
      var etat = etatEcran();
      var ecranDepart = ecranCourant();
      var capacites = ['navigate', 'create_segment', 'create_landing_page', 'create_email', 'create_form', 'create_campaign', 'create_report'];
      if (etat.champs > 0) { capacites.push('fill_field'); }
      if (cfg().scheduleEndpoint) { capacites.push('schedule_email'); }
      if (emailEnEdition() && cfg().abtestEndpoint) { capacites.push('create_ab_test'); }
      mQuery.ajax({
        url: cfg().assistEndpoint,
        type: 'POST',
        dataType: 'json',
        data: {
          question: q, history: history,
          lang: document.documentElement.lang || 'fr',
          section: (sectionCourante() || {}).nom || '',
          context: etat.texte,
          actions: capacites
        },
        success: function (res) {
          // « Arrêter » pendant l'attente : rien de la réponse n'est joué.
          if (typeof api.arrete === 'function' && api.arrete()) { return; }
          var actions = (res && res.actions) || [];
          var ecartees = (res && res.dropped) || [];
          var answer = String((res && res.answer) || '');
          if (!answer && !actions.length && !ecartees.length) {
            api.fail(messageErreur(null));
            return;
          }
          var tour = null;
          var lignes = [];
          var resultat = { notes: [], undoable: false, resume: '', remplis: [] };
          if (ecranCourant() !== ecranDepart) {
            lignes.push('Rien n’a été exécuté : vous avez changé d’écran pendant ma réponse. Redemandez ici si besoin.');
            resultat.resume = 'nothing executed: the user changed screen meanwhile';
          } else {
            resultat = executerActions(actions, res.turnId || null, function (texte, suite) {
              if (!tour) { return; }
              tour.result = (tour.result ? tour.result + '; ' : '') + suite;
              // La ligne « Création en cours » ne reste pas au-dessus d'un échec.
              tour.html = tour.html.replace(MOTIF_ENCOURS, '');
              api.note(tour, texte, { pied: sortiesEchec(suite) });
            }, function (html, suite, quandPose) {
              if (!tour) { return; }
              tour.result = (tour.result ? tour.result + '; ' : '') + suite;
              tour.html += html;
              if (quandPose) { quandPose(tour); }
              renderConv();
              memoriser();
            });
            lignes = resultat.notes.slice();
          }
          if (ecartees.length) {
            lignes.push('✗ Non exécuté : ' + ecartees.map(function (x) { return LIBELLES_ACTIONS[x] || x; }).join(', ') +
              '. Précisez votre demande (nom, contenu, destinataires…).');
            resultat.resume = (resultat.resume ? resultat.resume + '; ' : '') + 'rejected as incomplete: ' + ecartees.join(', ');
          }
          var html = answer ? rendreAide(answer) : '';
          var remplis = resultat.remplis || [];
          lignes.forEach(function (l) {
            // Le reçu d'un remplissage détaille chaque champ, dit le temps
            // gagné et porte l'annulation.
            var remplissage = l.charAt(0) === '✓' && remplis.length && l.indexOf('rempli') !== -1;
            html += ligneRecu(l, remplissage
              ? { lignes: remplis, secondes: secondesAction('fill_field', remplis.length), annulable: resultat.undoable }
              : null);
          });
          tour = api.ia(html, {
            turnId: resultat.undoable ? res.turnId : null,
            undoable: resultat.undoable,
            plain: answer,
            result: resultat.resume
          });
          api.finish();
        },
        error: function (xhr) {
          api.fail(messageErreur(xhr));
        }
      });
    },
    onUndo: function (turnId, api) {
      if (annulerTour(turnId)) {
        api.markUndone(esc(t('mautic.core.ai.assistant.undone', 'Modifications annulées.')));
      }
    }
  });

  /** Le lanceur : le MÊME partout, en bas à droite. Au repos un rond, l'icône
   *  seule sur un fond qui vit ; en pastille quand il a quelque chose à dire. */
  function teaserActif() {
    return !!(window.SendlyAiConfig && window.SendlyAiConfig.teaser);
  }

  function editeurOuvert() {
    return !!(document.body && document.body.classList.contains('sendly-builder-ouvert'));
  }

  var dernierEcran = '';

  function injectLauncher() {
    if (!cfg() && !teaserActif()) {
      return;
    }
    if (fabEl()) {
      return;
    }
    ensureStyles();
    // Un <div role="button"> et non un <button> : en pastille, il porte un
    // vrai bouton (« Annuler », « Détail ») qu'un bouton ne peut pas contenir.
    var fab = document.createElement('div');
    fab.id = 'sendly-assist-fab';
    fab.setAttribute('role', 'button');
    fab.setAttribute('tabindex', '0');
    document.body.appendChild(fab);

    function activer(e) {
      if (e && e.target && e.target.closest && e.target.closest('.sendly-assist-dedans')) {
        var action = pastille.action;
        if (typeof action === 'function') { action(); }
        return;
      }
      // Teaser (plan gratuit) : le lanceur existe, l'action propose le plan.
      if (teaserActif()) {
        if (window.SendlyAiUpsell) { window.SendlyAiUpsell.ouvrir('aide'); }
        return;
      }
      // Dans l'éditeur, la tuile de la bibliothèque reste l'unique entrée
      // (décision du 10/08) : la pastille informe, elle n'ouvre rien.
      if (editeurOuvert()) {
        return;
      }
      toggle();
    }
    fab.addEventListener('click', activer);
    fab.addEventListener('keydown', function (e) {
      if ((e.key === 'Enter' || e.key === ' ') && e.target === fab) {
        e.preventDefault();
        activer(e);
      }
    });

    if (cfg()) {
      restaurer();
      accueillirArrivee();
    }
    rendrePastille();
    dernierEcran = ecranCourant();

    // Navigation interne = ajax : l'onglet change sans rechargement — le
    // libellé du lanceur suit, la carte ouverte se met à l'heure du nouvel
    // écran (ou se referme si son contexte n'est plus celui de l'écran ; sa
    // conversation reste en mémoire).
    mQuery(document).ajaxComplete(function () {
      refreshLabel();
      var ecran = ecranCourant();
      if (ecran === dernierEcran) {
        return;
      }
      dernierEcran = ecran;
      // Une annulation ne vaut que sur l'écran où le geste a été fait.
      snapshots = {};
      eteindreTout();
      Object.keys(convStore).forEach(function (id) {
        convStore[id].forEach(function (turn) { turn.undoable = false; });
      });
      if (pastille.mode === 'recu' && pastille.bouton) { poserPastille(null); }
      if (openCtx) {
        var ctx = activeContext();
        if (!ctx || ctx.id !== openCtx.id) {
          closePanel(true);
        } else {
          renderConv();
        }
      }
    });
  }

  mQuery(function () {
    injectLauncher();

    // ⌘J / Ctrl+J : ouvre la carte du contexte courant, champ en saisie.
    document.addEventListener('keydown', function (e) {
      if ((e.metaKey || e.ctrlKey) && !e.shiftKey && !e.altKey && (e.key === 'j' || e.key === 'J')) {
        if (!cfg() || editeurOuvert()) {
          return;
        }
        e.preventDefault();
        toggle();
      }
    });
  });
})();
