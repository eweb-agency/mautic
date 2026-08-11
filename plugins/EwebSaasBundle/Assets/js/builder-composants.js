/**
 * Onglet Composants du builder de landing pages — chantier D, P2.
 *
 * Enregistré via l'accroche OFFICIELLE `window.MauticGrapesJsPlugins`
 * (builder.service.js la consomme à chaque ouverture, filtrée par contexte) :
 * AUCUNE reconstruction du bundle Parcel n'est nécessaire, ce fichier est
 * agrégé dans app.js comme builder-shell.js. Contexte ['page'] : l'éditeur
 * d'e-mails ne voit RIEN de tout ceci.
 *
 * Ce que fait le plugin (tout validé en direct dans l'éditeur le 10/08
 * avant d'être gravé ici) :
 * 1. Les blocs existants sont renommés en FRANÇAIS, reçoivent les icônes
 *    Lucide de la maquette validée et sont rangés en DEUX catégories
 *    (BASIQUE / MISE EN PAGE) dans l'ordre de la maquette. ZÉRO bloc
 *    supprimé (principe gravé : aucune régression de composants).
 * 2. Quatre tuiles s'ajoutent : Séparateur, Réseaux sociaux, Formulaire
 *    (encart provisoire — le sélecteur arrive en P3) et Assistant IA
 *    (visuelle — branchement au panneau conversationnel en P5).
 * 3. La barre haute prend le motif maquette : onglets TEXTE
 *    « Composants | Styles » à gauche (réordonnés dans le DOM : la vue des
 *    panels ne re-rend NI l'ordre de collection NI les attributs), boutons
 *    « Effacer / Annuler / Terminer » à droite. Terminer = appliquer PUIS
 *    fermer (arbitrage 10/08) : l'apply écrit le textarea en synchrone
 *    avant son POST, le chaînage est sûr.
 * 4. Sorties de barre (décisions 10/08) : plein écran (l'éditeur est déjà
 *    plein écran), crayon « Modifier le code » (reviendra dans l'onglet
 *    Options en P3), étincelles IA (absorbée par la tuile), et le toggle
 *    contours — le préréglage l'ACTIVE au chargement, on coupe donc la
 *    commande sw-visibility après retrait du bouton (contours OFF par
 *    défaut ; la case « Contours des blocs » arrive dans Options en P3).
 */
(function () {
  'use strict';

  if (!window.MauticGrapesJsPlugins) {
    window.MauticGrapesJsPlugins = [];
  }

  var ICONS = {
"TEXTE": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"4 7 4 4 20 4 20 7\"/><line x1=\"9\" y1=\"20\" x2=\"15\" y2=\"20\"/><line x1=\"12\" y1=\"4\" x2=\"12\" y2=\"20\"/></svg>",
"CITATION": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M16 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z\"/><path d=\"M5 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z\"/></svg>",
"LIEN": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71\"/><path d=\"M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71\"/></svg>",
"IMAGE": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"3\" y=\"3\" width=\"18\" height=\"18\" rx=\"2\"/><circle cx=\"9\" cy=\"9\" r=\"2\"/><path d=\"m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21\"/></svg>",
"VIDÉO": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"3\" y=\"3\" width=\"18\" height=\"18\" rx=\"2\"/><path d=\"m10 8 6 4-6 4Z\"/></svg>",
"BOUTON": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"3\" y=\"8\" width=\"18\" height=\"8\" rx=\"4\"/><path d=\"M12 20v1\"/></svg>",
"SÉPARATEUR": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><line x1=\"3\" y1=\"12\" x2=\"21\" y2=\"12\"/><polyline points=\"8 7 12 3 16 7\"/><polyline points=\"16 17 12 21 8 17\"/></svg>",
"CARTE": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M14.106 5.553a2 2 0 0 0 1.788 0l3.659-1.83A1 1 0 0 1 21 4.619v12.764a1 1 0 0 1-.553.894l-4.553 2.277a2 2 0 0 1-1.788 0l-4.212-2.106a2 2 0 0 0-1.788 0l-3.659 1.83A1 1 0 0 1 3 19.381V6.618a1 1 0 0 1 .553-.894l4.553-2.277a2 2 0 0 1 1.788 0z\"/><path d=\"M15 5.764v15\"/><path d=\"M9 3.236v15\"/></svg>",
"COMPTE À REBOURS": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><line x1=\"10\" y1=\"2\" x2=\"14\" y2=\"2\"/><line x1=\"12\" y1=\"14\" x2=\"15\" y2=\"11\"/><circle cx=\"12\" cy=\"14\" r=\"8\"/></svg>",
"RÉSEAUX SOCIAUX": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><circle cx=\"18\" cy=\"5\" r=\"3\"/><circle cx=\"6\" cy=\"12\" r=\"3\"/><circle cx=\"18\" cy=\"19\" r=\"3\"/><line x1=\"8.59\" y1=\"13.51\" x2=\"15.42\" y2=\"17.49\"/><line x1=\"15.41\" y1=\"6.51\" x2=\"8.59\" y2=\"10.49\"/></svg>",
"CODE": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"16 18 22 12 16 6\"/><polyline points=\"8 6 2 12 8 18\"/></svg>",
"BARRE DE NAVIGATION": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"18\" height=\"7\" x=\"3\" y=\"3\" rx=\"1\"/><rect width=\"7\" height=\"7\" x=\"3\" y=\"14\" rx=\"1\"/><rect width=\"7\" height=\"7\" x=\"14\" y=\"14\" rx=\"1\"/></svg>",
"ASSISTANT IA": "<svg style=\"fill:#fff\" viewBox=\"0 0 24 24\" fill=\"#fff\"><path d=\"M9.1071 5.448C9.7051 3.698 12.1231 3.645 12.8321 5.289L12.8921 5.449L13.6991 7.809C13.884 8.35023 14.1829 8.84551 14.5755 9.26142C14.9682 9.67734 15.4454 10.0042 15.9751 10.22L16.1921 10.301L18.5521 11.107C20.3021 11.705 20.3551 14.123 18.7121 14.832L18.5521 14.892L16.1921 15.699C15.6507 15.8838 15.1552 16.1826 14.7391 16.5753C14.323 16.9679 13.996 17.4452 13.7801 17.975L13.6991 18.191L12.8931 20.552C12.2951 22.302 9.8771 22.355 9.1691 20.712L9.1071 20.552L8.3011 18.192C8.11628 17.6506 7.81748 17.1551 7.42485 16.739C7.03221 16.3229 6.5549 15.9959 6.0251 15.78L5.8091 15.699L3.4491 14.893C1.6981 14.295 1.6451 11.877 3.2891 11.169L3.4491 11.107L5.8091 10.301C6.35034 10.1161 6.84562 9.81719 7.26153 9.42457C7.67744 9.03195 8.00432 8.55469 8.2201 8.025L8.3011 7.809L9.1071 5.448Z\"/></svg>",
"1 COLONNE": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"18\" height=\"18\" x=\"3\" y=\"3\" rx=\"2\"/></svg>",
"2 COLONNES": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"18\" height=\"18\" x=\"3\" y=\"3\" rx=\"2\"/><path d=\"M12 3v18\"/></svg>",
"3 COLONNES": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"18\" height=\"18\" x=\"3\" y=\"3\" rx=\"2\"/><path d=\"M9 3v18\"/><path d=\"M15 3v18\"/></svg>",
"30 / 70": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"18\" height=\"18\" x=\"3\" y=\"3\" rx=\"2\"/><path d=\"M9.5 3v18\"/></svg>",
"FORMULAIRE": "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"4\" y=\"3\" width=\"16\" height=\"18\" rx=\"2\"/><line x1=\"8\" y1=\"8\" x2=\"16\" y2=\"8\"/><line x1=\"8\" y1=\"12\" x2=\"16\" y2=\"12\"/><line x1=\"8\" y1=\"16\" x2=\"12\" y2=\"16\"/></svg>"
};

  var BASIQUE = { id: 'sendly-basique', label: 'BASIQUE' };
  var LAYOUT = { id: 'sendly-layout', label: 'MISE EN PAGE' };

  /** id de bloc -> [libellé, icône, catégorie B|L, ordre maquette] */
  var MAP = {
    'text': ['Texte', 'TEXTE', 'B', 1],
    'quote': ['Citation', 'CITATION', 'B', 2],
    'link': ['Lien', 'LIEN', 'B', 3],
    'link-block': ['Lien encadré', 'LIEN', 'B', 4],
    'image': ['Image', 'IMAGE', 'B', 5],
    'video': ['Vidéo', 'VIDÉO', 'B', 6],
    'text-sect': ['Section de texte', 'TEXTE', 'B', 7],
    'map': ['Carte', 'CARTE', 'B', 9],
    'countdown': ['Compte à rebours', 'COMPTE À REBOURS', 'B', 10],
    'custom-code': ['Code', 'CODE', 'B', 12],
    'navbar': ['Barre de navigation', 'BARRE DE NAVIGATION', 'B', 13],
    'column1': ['1 colonne', '1 COLONNE', 'L', 1],
    'column2': ['2 colonnes', '2 COLONNES', 'L', 2],
    'column3': ['3 colonnes', '3 COLONNES', 'L', 3],
    'column3-7': ['30 / 70', '30 / 70', 'L', 4],
  };

  function remapBlocks(editor) {
    var bm = editor.Blocks;
    var releve = bm.getAll().map(function (b) {
      return { id: b.get('id'), label: b.get('label'), attrs: Object.assign({}, b.attributes) };
    });

    releve.forEach(function (item) {
      var m = MAP[item.id];
      // le bloc bouton du thème et « Text section » ont des ids variables :
      // rattrapage par libellé (relevé le 10/08 : gjs-fonts gjs-f-button)
      if (!m && /button|bouton/i.test(String(item.label))) { m = ['Bouton', 'BOUTON', 'B', 8]; }
      if (!m && /text section/i.test(String(item.label))) { m = ['Section de texte', 'TEXTE', 'B', 7]; }
      if (!m) { return; }
      bm.remove(item.id);
      bm.add(item.id, Object.assign({}, item.attrs, {
        label: m[0],
        media: ICONS[m[1]] || item.attrs.media,
        category: m[2] === 'B' ? BASIQUE : LAYOUT,
        order: m[3],
      }));
    });

    bm.add('sendly-separator', { label: 'Séparateur', media: ICONS['SÉPARATEUR'], category: BASIQUE, order: 14,
      content: '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0"/>' });
    bm.add('sendly-social', { label: 'Réseaux sociaux', media: ICONS['RÉSEAUX SOCIAUX'], category: BASIQUE, order: 15,
      content: '<div style="display:flex;gap:16px;justify-content:center;padding:12px"><a href="#">Facebook</a><a href="#">Instagram</a><a href="#">LinkedIn</a></div>' });
    // data-sendly="form" = le marqueur que builder-styles.js (P3a) détecte
    // pour proposer le sélecteur de formulaires dans le panneau Styles.
    bm.add('sendly-form', { label: 'Formulaire', media: ICONS['FORMULAIRE'], category: BASIQUE, order: 16,
      content: '<div data-sendly="form" style="padding:16px;text-align:center;color:#6a7486">Formulaire — choisissez le formulaire dans l\'onglet Styles</div>' });
    // La tuile IA n'apparaît QUE si le copilote est activé sur l'instance
    // (même gating 3 niveaux que les boutons IA des e-mails) : sans clé,
    // le panneau n'existe pas — une tuile qui n'ouvre rien serait une
    // promesse cassée (constaté sur un tenant sans clé, P5).
    if (window.SendlyAiConfig && window.SendlyAiConfig.enabled) {
      bm.add('sendly-ia', { label: 'Assistant IA', media: ICONS['ASSISTANT IA'], category: BASIQUE, order: 17, content: '' });
    }

    // Tri = ordre visuel (la vue rend dans l'ordre de collection)…
    var tous = bm.getAll().map(function (b) {
      var cat = b.get('category');
      var catId = (cat && cat.id) || cat || '';
      var catRank = catId === 'sendly-basique' ? 0 : catId === 'sendly-layout' ? 1 : 2;
      return { id: b.get('id'), attrs: Object.assign({}, b.attributes), r: catRank * 100 + (b.get('order') || 99) };
    });
    tous.sort(function (a, b) { return a.r - b.r; });
    tous.forEach(function (i) { bm.remove(i.id); });
    tous.forEach(function (i) { bm.add(i.id, i.attrs); });

    // …et les catégories héritées, désormais vides, quittent le modèle
    // (leurs conteneurs DOM restent : masqués par le thème, règle :has).
    var cats = bm.getCategories();
    cats.models.slice().forEach(function (c) {
      if (c.id !== 'sendly-basique' && c.id !== 'sendly-layout') { cats.remove(c); }
    });
  }

  function remapPanels(editor) {
    var pm = editor.Panels;

    var bBlocks = pm.getButton('views', 'open-blocks');
    var bSm = pm.getButton('views', 'open-sm');
    if (bBlocks) { bBlocks.set({ label: 'Composants', className: 'sendly-tab', attributes: { title: 'Composants' } }); }
    if (bSm) { bSm.set({ label: 'Styles', className: 'sendly-tab', attributes: { title: 'Styles' } }); }

    pm.removeButton('views', 'views-apply');
    pm.removeButton('views', 'close');

    pm.addButton('options', { id: 'sendly-effacer', label: 'Effacer', className: 'sendly-btn-ghost',
      command: 'core:canvas-clear', attributes: { title: 'Vider la page' } });
    pm.addButton('options', { id: 'sendly-annuler', label: 'Annuler', className: 'sendly-btn-ghost',
      command: function (ed) { ed.runCommand('mautic-editor-page-html-close'); }, attributes: { title: 'Fermer sans appliquer' } });
    pm.addButton('options', { id: 'sendly-terminer', label: 'Terminer', className: 'sendly-btn-primary',
      command: function (ed) {
        ed.runCommand('mautic-editor-page-html-apply');
        ed.runCommand('mautic-editor-page-html-close');
      }, attributes: { title: 'Appliquer et fermer' } });

    ['fullscreen', 'code-edit', 'ai-generate', 'sw-visibility'].forEach(function (id) {
      if (pm.getButton('options', id)) { pm.removeButton('options', id); }
    });
    // Le préréglage active les contours AVANT ce retrait : couper la commande,
    // sinon ils restent affichés sans plus aucun interrupteur.
    editor.stopCommand('sw-visibility');

    // Composants AVANT Styles : la vue des panels ne re-rend pas la
    // collection, on réordonne donc le DOM rendu.
    var wrap = document.querySelector('.builder-panel .gjs-pn-views .gjs-pn-buttons')
      || document.querySelector('.builder-panel .gjs-pn-views');
    if (wrap) {
      var boutons = wrap.querySelectorAll('.gjs-pn-btn');
      for (var i = 0; i < boutons.length; i += 1) {
        if (/composants/i.test(boutons[i].textContent)) { wrap.prepend(boutons[i]); break; }
      }
    }
  }

  window.MauticGrapesJsPlugins.push({
    name: 'sendly-composants',
    context: ['page'],
    plugin: function (editor) {
      editor.on('load', function () {
        remapBlocks(editor);
        remapPanels(editor);
      });
    },
  });
})();
