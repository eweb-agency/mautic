/**
 * Panneaux Styles CONTEXTUELS du builder de landing pages — chantier D, P3a.
 *
 * Fini les 6 secteurs génériques anglais (General/Flex/Dimension…) : à la
 * sélection d'un composant, UN SEUL panneau pertinent s'affiche (« Paramètres
 * du texte », « … du bouton », etc.), rien de sélectionné = « Paramètres de
 * la page ». Matrice validée par le proprio le 10/08, panneau par panneau,
 * en direct sur le vrai éditeur avant d'être gravée ici.
 *
 * Enregistré via l'accroche OFFICIELLE `window.MauticGrapesJsPlugins`,
 * contexte ['page'] : l'éditeur d'e-mails ne voit rien de tout ceci.
 * Fichier agrégé dans app.js — aucun rebuild Parcel.
 *
 * Architecture retenue (chaque piège ci-dessous a été CONSTATÉ en direct) :
 * - Les 10 secteurs sont enregistrés UNE FOIS au chargement, puis la bascule
 *   se fait en PUR CSS : le conteneur `.gjs-sm-sectors` porte
 *   `data-sendly-kind="<kind>"` et le thème n'affiche que
 *   `.gjs-sm-sector__s-<kind>`. AUCUNE manipulation de vues à la sélection —
 *   le va-et-vient removeSector/addSector laisse des vues fantômes
 *   dupliquées (constaté), et l'attribut `visible` des secteurs n'est pas
 *   câblé dans cette build.
 * - FAIL-OPEN : la règle CSS qui masque les secteurs exige l'attribut
 *   `[data-sendly-kind]`. Si ce script ne tourne pas, l'attribut n'existe
 *   pas et le Style Manager reste pleinement visible (comportement d'avant).
 * - `addSector` à chaud REND CHAQUE SECTEUR DEUX FOIS (constaté 5 modèles →
 *   10 vues) : dedupeSectorDom() garde le dernier rendu de chaque id.
 * - Le type « champ + slider » (contrôle signature de la maquette) n'existe
 *   pas dans GrapesJS : type custom `sendly-slider` (input number + range
 *   synchronisés, `partial: true` pendant le glissement).
 * - `{ extend: 'font-family' }` hérite la liste de polices de la définition
 *   NATIVE (un select vide sinon — constaté).
 */
(function () {
  'use strict';

  if (!window.MauticGrapesJsPlugins) {
    window.MauticGrapesJsPlugins = [];
  }

  var KINDS = ['texte', 'bouton', 'image', 'section', 'page', 'video', 'carte', 'rebours', 'separateur', 'navbar', 'formulaire'];

  var TRAITS_FR = {
    href: 'Lien',
    title: 'Info-bulle',
    alt: 'Texte alternatif (SEO)',
    target: 'Ouvrir dans',
    id: 'Id',
    provider: 'Source',
    videoId: 'Identifiant de la vidéo',
    src: 'Adresse du média',
    poster: "Image d'attente",
    autoplay: 'Lecture auto',
    loop: 'Boucle',
    controls: 'Contrôles',
    rel: 'Suggestions à la fin',
    modestbranding: 'Marque YouTube réduite',
    address: 'Adresse',
    zoom: 'Zoom',
    mapType: 'Type de carte',
    'data-form': 'Formulaire',
  };

  // `extend` du natif OBLIGATOIRE : un composite nu ({type:'composite'})
  // n'a AUCUN sous-champ — lignes vides constatées par le proprio 11/08.
  function margeInterne() { return { extend: 'padding', name: 'Marge interne' }; }
  function margeExterne() { return { extend: 'margin', name: 'Marge externe' }; }
  function police() { return { extend: 'font-family', name: 'Police' }; }
  function graisse() {
    return { type: 'select', property: 'font-weight', name: 'Épaisseur', options: [
      { id: '300', label: 'Fine' }, { id: '400', label: 'Normale' },
      { id: '600', label: 'Demi-grasse' }, { id: '700', label: 'Grasse' }] };
  }
  function alignement() {
    return { type: 'radio', property: 'text-align', name: 'Alignement', options: [
      { id: 'left', label: 'G' }, { id: 'center', label: 'C' }, { id: 'right', label: 'D' }] };
  }
  function slider(property, name, units, min, max, step) {
    // `sendlyUnit` et non `units` : le champ `units` déclenche le découpage
    // valeur/unité du modèle de propriété de base, qui ne sait pas recoller
    // -> « 63px » stocké « 63 », CSS invalide, curseurs SANS EFFET (défaut
    // proprio 11/08 : taille et espacement muets). Champ opaque = intact.
    return { type: 'sendly-slider', property: property, name: name, sendlyUnit: units && units.length ? units[0] : '', min: min, max: max, step: step };
  }

  // La matrice validée : un secteur par famille, id = s-<kind>.
  var SETS = {
    texte: { id: 's-texte', name: 'Paramètres du texte', open: true, properties: [
      police(),
      slider('font-size', 'Taille', ['px'], 8, 72, 1),
      graisse(),
      slider('line-height', 'Hauteur de ligne', [], 0.8, 3, 0.1),
      slider('letter-spacing', 'Espacement des lettres', ['px'], -3, 12, 0.5),
      alignement(),
      { type: 'color', property: 'color', name: 'Couleur' },
      { type: 'color', property: 'background-color', name: "Couleur d'arrière-plan" },
      margeInterne(), margeExterne(),
    ] },
    bouton: { id: 's-bouton', name: 'Paramètres du bouton', open: true, properties: [
      { type: 'color', property: 'color', name: 'Couleur du texte' },
      { type: 'color', property: 'background-color', name: "Couleur d'arrière-plan" },
      police(), slider('font-size', 'Taille', ['px'], 8, 48, 1), graisse(),
      margeInterne(), margeExterne(),
      { extend: 'border-radius', name: 'Rayon de bordure' },
      { extend: 'border', name: 'Bordure' },
      { extend: 'box-shadow', name: 'Ombre portée' },
    ] },
    image: { id: 's-image', name: "Paramètres de l'image", open: true, properties: [
      slider('width', 'Largeur', ['%', 'px'], 0, 100, 1),
      { extend: 'border-radius', name: 'Rayon de bordure' },
      { extend: 'box-shadow', name: 'Ombre portée' },
      margeExterne(),
    ] },
    section: { id: 's-section', name: 'Paramètres de la section', open: true, properties: [
      { type: 'color', property: 'background-color', name: "Couleur d'arrière-plan" },
      { extend: 'background', name: 'Image / dégradé de fond' },
      slider('min-height', 'Hauteur minimale', ['px', 'vh'], 0, 800, 10),
      { type: 'select', property: 'align-items', name: 'Alignement vertical', options: [
        { id: 'flex-start', label: 'Haut' }, { id: 'center', label: 'Centre' }, { id: 'flex-end', label: 'Bas' }] },
      margeInterne(), margeExterne(),
      { extend: 'border', name: 'Bordure' },
    ] },
    page: { id: 's-page', name: 'Paramètres de la page', open: true, properties: [
      { type: 'color', property: 'background-color', name: "Couleur d'arrière-plan" },
      police(),
      { type: 'color', property: 'color', name: 'Couleur du texte' },
      slider('max-width', 'Largeur du contenu', ['px'], 480, 1400, 10),
    ] },
    video: { id: 's-video', name: 'Paramètres de la vidéo', open: true, properties: [
      slider('width', 'Largeur', ['%'], 10, 100, 1),
      slider('height', 'Hauteur', ['px'], 100, 800, 10),
      margeExterne(),
    ] },
    carte: { id: 's-carte', name: 'Paramètres de la carte', open: true, properties: [
      slider('height', 'Hauteur', ['px'], 100, 800, 10),
      margeExterne(),
      { extend: 'border-radius', name: 'Rayon de bordure' },
    ] },
    rebours: { id: 's-rebours', name: 'Paramètres du compte à rebours', open: true, properties: [
      police(), slider('font-size', 'Taille', ['px'], 10, 96, 1),
      { type: 'color', property: 'color', name: 'Couleur' },
      { type: 'color', property: 'background-color', name: "Couleur d'arrière-plan" },
      alignement(), margeInterne(),
    ] },
    separateur: { id: 's-separateur', name: 'Paramètres du séparateur', open: true, properties: [
      slider('border-top-width', 'Épaisseur', ['px'], 1, 20, 1),
      { type: 'select', property: 'border-top-style', name: 'Style', options: [
        { id: 'solid', label: 'Plein' }, { id: 'dashed', label: 'Tirets' }, { id: 'dotted', label: 'Points' }] },
      { type: 'color', property: 'border-top-color', name: 'Couleur' },
      slider('width', 'Largeur', ['%'], 10, 100, 5),
      margeExterne(),
    ] },
    navbar: { id: 's-navbar', name: 'Paramètres de la barre de navigation', open: true, properties: [
      { type: 'color', property: 'background-color', name: "Couleur d'arrière-plan" },
      { type: 'color', property: 'color', name: 'Couleur des liens' },
      police(), margeInterne(),
    ] },
    formulaire: { id: 's-formulaire', name: 'Paramètres du formulaire', open: true, properties: [
      margeExterne(),
    ] },
  };

  /** Le contrôle signature de la maquette : champ numérique + slider liés.
   *  L'écriture passe par `upValue` du MODÈLE de propriété — la SEULE voie
   *  qui préserve l'unité : le `change()` de l'API des types custom finit
   *  dans `updateStyle`, qui AVALE le suffixe (« 63px » stocké « 63 »,
   *  CSS invalide -> curseurs sans le moindre effet, défaut proprio 11/08,
   *  prouvé en pilotant les deux voies dans la même session). */
  function registerSliderType(editor) {
    function modeleDe(propriete) {
      var trouve = null;
      editor.StyleManager.getSectors().forEach(function (s) {
        (s.get('properties') || []).forEach(function (p) {
          if (!trouve && propriete === p.get('property') && 'sendly-slider' === p.get('type')) { trouve = p; }
        });
      });
      return trouve;
    }
    editor.StyleManager.addType('sendly-slider', {
      create: function (arg) {
        var props = arg.props;
        var el = document.createElement('div');
        el.className = 'sendly-sldin';
        el.innerHTML = '<input type="number" class="sldin-num"><input type="range" class="sldin-range">';
        var range = el.querySelector('.sldin-range');
        var num = el.querySelector('.sldin-num');
        var unit = props.sendlyUnit || '';
        range.min = num.min = props.min != null ? props.min : 0;
        range.max = num.max = props.max != null ? props.max : 100;
        range.step = num.step = props.step != null ? props.step : 1;
        var pousse = function (v) {
          // DIFFÉRÉ : GrapesJS attache AUSSI son écouteur générique à nos
          // champs et écrit la valeur BRUTE (sans unité) en différé — notre
          // écriture synchrone perdait la course (constaté en prod : « 34 »
          // nu au modèle). En écrivant APRÈS lui, l'unité gagne (motif
          // validé en greffe live la veille, revalidé aujourd'hui : 42px).
          setTimeout(function () {
            var m = modeleDe(props.property);
            if (m) { m.upValue(String(v) + unit); }
          }, 150);
        };
        range.addEventListener('input', function () { num.value = range.value; });
        range.addEventListener('change', function (e) { e.stopPropagation(); pousse(range.value); });
        num.addEventListener('change', function (e) { e.stopPropagation(); range.value = num.value; pousse(num.value); });
        return el;
      },
      update: function (arg) {
        var v = parseFloat(arg.value);
        if (!isNaN(v) && arg.el) {
          var r = arg.el.querySelector('.sldin-range');
          var n = arg.el.querySelector('.sldin-num');
          if (r) { r.value = v; }
          if (n) { n.value = v; }
        }
      },
    });
  }

  /** Les sous-champs hérités du natif arrivent en ANGLAIS (Top, Right,
   *  Blur…) et les couches de pile (ombres, fonds) se rendent À LA VOLÉE :
   *  traduction au niveau du DOM (le texte vit dans `.gjs-sm-icon`, et
   *  re-libeller les MODÈLES ne re-rend pas les vues — constaté), passe
   *  initiale + observateur pour tout ce qui apparaît ensuite. */
  var SOUS_LIBELLES_FR = {
    'Top': 'Haut', 'Right': 'Droite', 'Bottom': 'Bas', 'Left': 'Gauche',
    'Top Left': 'Haut gauche', 'Top Right': 'Haut droit', 'Bottom Left': 'Bas gauche', 'Bottom Right': 'Bas droit',
    'Width': 'Épaisseur', 'Color': 'Couleur',
    'X position': 'Position X', 'Y position': 'Position Y', 'Blur': 'Flou', 'Spread': 'Étendue',
    'Image': 'Image', 'Repeat': 'Répétition', 'Attachment': 'Attache', 'Size': 'Taille',
    'Background repeat': 'Répétition', 'Background position': 'Position',
    'Background attachment': 'Attache', 'Background size': 'Taille',
  };

  function franciserSousLibelles() {
    var conteneur = document.querySelector('.builder-panel');
    if (!conteneur) { return; }
    var traduireIcone = function (span) {
      Array.prototype.forEach.call(span.childNodes, function (n) {
        if (3 === n.nodeType) {
          var t = n.textContent.trim();
          if (SOUS_LIBELLES_FR[t]) { n.textContent = n.textContent.replace(t, SOUS_LIBELLES_FR[t]); }
        }
      });
    };
    var passe = function (racine) {
      if (racine.querySelectorAll) {
        Array.prototype.forEach.call(racine.querySelectorAll('.gjs-sm-label .gjs-sm-icon'), traduireIcone);
      }
    };
    passe(conteneur);
    new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        Array.prototype.forEach.call(m.addedNodes, function (n) {
          if (1 === n.nodeType) { passe(n); }
        });
      });
    }).observe(conteneur, { subtree: true, childList: true });
  }

  /** Les thèmes importés sont truffés de variantes PRÉFIXÉES
   *  (-webkit-border-radius…) que le panneau n'édite jamais : placées
   *  après la propriété standard dans la règle, elles l'ÉCRASENT en
   *  cascade (alias navigateur) — « Rayon de bordure ne fonctionne pas »,
   *  proprio 12/08, élucidé règle en main. À chaque édition d'une règle,
   *  ses doublons préfixés des propriétés standards présentes sautent. */
  function purgerPrefixes(regle) {
    var st = {};
    Object.keys(regle.getStyle()).forEach(function (k) { st[k] = regle.getStyle()[k]; });
    var modif = false;
    Object.keys(st).forEach(function (k) {
      if ('-' !== k[0]) {
        ['-webkit-', '-moz-', '-o-', '-ms-'].forEach(function (p) {
          if (undefined !== st[p + k]) { delete st[p + k]; modif = true; }
        });
      }
    });
    if (modif) { regle.setStyle(st); }
  }

  /** addSector à chaud rend chaque secteur deux fois : on garde le dernier. */
  function dedupeSectorDom() {
    var vus = {};
    var els = Array.prototype.slice.call(document.querySelectorAll('.builder-panel .gjs-sm-sector')).reverse();
    els.forEach(function (el) {
      var id = Array.prototype.find.call(el.classList, function (c) { return 0 === c.indexOf('gjs-sm-sector__'); });
      if (vus[id]) { el.remove(); } else { vus[id] = true; }
    });
  }

  function kindOf(c) {
    if (!c || 'wrapper' === c.get('type')) { return 'page'; }
    var t = c.get('type');
    var tag = (c.get('tagName') || '').toLowerCase();
    var cls = (c.getClasses() || []).join(' ');
    var attrs = c.getAttributes ? c.getAttributes() : {};
    if ('form' === attrs['data-sendly']) { return 'formulaire'; }
    if ('image' === t) { return 'image'; }
    if ('video' === t) { return 'video'; }
    if ('map' === t) { return 'carte'; }
    if (/countdown/.test(t) || /countdown/.test(cls)) { return 'rebours'; }
    if (/navbar/.test(t) || /navbar/.test(cls)) { return 'navbar'; }
    if ('hr' === tag) { return 'separateur'; }
    if ('a' === tag && /button|btn/i.test(cls)) { return 'bouton'; }
    if ('text' === t || 'a' === tag) { return 'texte'; }
    return 'section';
  }

  function applyKind(kind) {
    var cont = document.querySelector('.builder-panel .gjs-sm-sectors');
    if (cont) {
      cont.setAttribute('data-sendly-kind', -1 !== KINDS.indexOf(kind) ? kind : 'page');
    }
  }

  function franciserTraits(c) {
    (c.getTraits() || []).forEach(function (t) {
      var fr = TRAITS_FR[t.get('name')];
      if (fr) { t.set('label', fr); }
      if ('target' === t.get('name')) {
        var opts = t.get('options') || [];
        opts.forEach(function (o) {
          if (/this window/i.test(o.name || o.label || '')) { o.name = 'Cette fenêtre'; o.label = 'Cette fenêtre'; }
          if (/new window/i.test(o.name || o.label || '')) { o.name = 'Nouvel onglet'; o.label = 'Nouvel onglet'; }
        });
        t.set('options', opts);
      }
    });
  }

  /**
   * Tuile Formulaire : le composant porte data-sendly="form" (posé par
   * builder-composants.js). À la sélection, un trait « Formulaire » liste les
   * formulaires publiés (endpoint EwebSaasBundle, requête UNIQUE mise en
   * cache) ; le choix écrit data-form et le contenu devient le jeton
   * {form=N} que Mautic remplace au rendu de la page.
   */
  var formsCache = null;
  function chargerFormulaires() {
    if (formsCache) { return Promise.resolve(formsCache); }
    return fetch(mauticBasePath + '/sendly/builder-forms', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    }).then(function (r) { return r.ok ? r.json() : { forms: [] }; })
      .then(function (j) { formsCache = j.forms || []; return formsCache; })
      .catch(function () { return []; });
  }

  function equiperFormulaire(c) {
    chargerFormulaires().then(function (forms) {
      var options = forms.map(function (f) { return { id: String(f.id), name: f.name }; });
      if (!options.length) {
        options = [{ id: '', name: 'Aucun formulaire publié' }];
      }
      c.addTrait({
        type: 'select', name: 'data-form', label: 'Formulaire', options: options,
      });
    });
  }

  function poserJetonFormulaire(c) {
    var n = parseInt(c.getAttributes()['data-form'], 10);
    if (n > 0) {
      c.components('{form=' + n + '}');
    }
  }

  window.MauticGrapesJsPlugins.push({
    name: 'sendly-styles',
    context: ['page'],
    plugin: function (editor) {
      registerSliderType(editor);
      editor.on('load', function () {
        var sm = editor.StyleManager;
        Object.keys(SETS).forEach(function (kind) {
          var def = SETS[kind];
          if (!sm.getSector(def.id)) { sm.addSector(def.id, def); }
        });
        dedupeSectorDom();
        franciserSousLibelles();
        // Seules les règles ÉDITÉES sont purgées de leurs préfixes : les
        // règles du thème jamais touchées rendent comme à l'import.
        editor.Css.getAll().on('change:style', purgerPrefixes);
        applyKind('page');
      });
      editor.on('component:selected', function (c) {
        applyKind(kindOf(c));
        franciserTraits(c);
        if ('formulaire' === kindOf(c)) { equiperFormulaire(c); }
      });
      editor.on('component:deselected', function () {
        if (!editor.getSelected()) { applyKind('page'); }
      });
      // Changer d'appareil laissait l'overlay de sélection à la géométrie
      // de l'appareil PRÉCÉDENT (badge + contour orphelins — défaut proprio
      // 11/08) : on désélectionne, l'utilisateur resélectionne dans la
      // nouvelle vue. Et le CADRE SE CENTRE à chaque format (demande proprio
      // 11/08) : par la propriété LEFT en inline — jamais par transformation CSS,
      // qui désynchronise le calque d'outils (constaté en P1).
      function centrerCadre() {
        var cadre = document.querySelector('.builder-panel .gjs-frame-wrapper');
        var canvas = document.querySelector('.builder-panel .gjs-cv-canvas');
        if (!cadre || !canvas) { return; }
        cadre.style.left = Math.max(0, (canvas.clientWidth - cadre.offsetWidth) / 2) + 'px';
      }
      // RAFALE : le re-rendu du changement d'appareil écrase une application
      // unique (constaté : posé à +60ms, écrasé avant +500ms).
      function centrerEnRafale() { [100, 400, 800].forEach(function (d) { setTimeout(centrerCadre, d); }); }
      editor.on('change:device', function () {
        editor.select();
        centrerEnRafale();
      });
      editor.on('load', centrerEnRafale);
      window.addEventListener('resize', centrerCadre);
      editor.on('component:update:attributes', function (c) {
        if ('formulaire' === kindOf(c)) { poserJetonFormulaire(c); }
      });
    },
  });
})();
