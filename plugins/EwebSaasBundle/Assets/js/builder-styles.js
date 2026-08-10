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

  function margeInterne() { return { type: 'composite', property: 'padding', name: 'Marge interne' }; }
  function margeExterne() { return { type: 'composite', property: 'margin', name: 'Marge externe' }; }
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
    return { type: 'sendly-slider', property: property, name: name, units: units, min: min, max: max, step: step };
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
      { type: 'composite', property: 'border-radius', name: 'Rayon de bordure' },
      { type: 'composite', property: 'border', name: 'Bordure' },
      { type: 'stack', property: 'box-shadow', name: 'Ombre portée' },
    ] },
    image: { id: 's-image', name: "Paramètres de l'image", open: true, properties: [
      slider('width', 'Largeur', ['%', 'px'], 0, 100, 1),
      { type: 'composite', property: 'border-radius', name: 'Rayon de bordure' },
      { type: 'stack', property: 'box-shadow', name: 'Ombre portée' },
      margeExterne(),
    ] },
    section: { id: 's-section', name: 'Paramètres de la section', open: true, properties: [
      { type: 'color', property: 'background-color', name: "Couleur d'arrière-plan" },
      { type: 'stack', property: 'background', name: 'Image / dégradé de fond' },
      slider('min-height', 'Hauteur minimale', ['px', 'vh'], 0, 800, 10),
      { type: 'select', property: 'align-items', name: 'Alignement vertical', options: [
        { id: 'flex-start', label: 'Haut' }, { id: 'center', label: 'Centre' }, { id: 'flex-end', label: 'Bas' }] },
      margeInterne(), margeExterne(),
      { type: 'composite', property: 'border', name: 'Bordure' },
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
      { type: 'composite', property: 'border-radius', name: 'Rayon de bordure' },
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

  /** Le contrôle signature de la maquette : champ numérique + slider liés. */
  function registerSliderType(editor) {
    editor.StyleManager.addType('sendly-slider', {
      create: function (arg) {
        var props = arg.props;
        var change = arg.change;
        var el = document.createElement('div');
        el.className = 'sendly-sldin';
        el.innerHTML = '<input type="number" class="sldin-num"><input type="range" class="sldin-range">';
        var range = el.querySelector('.sldin-range');
        var num = el.querySelector('.sldin-num');
        var unit = (props.units && props.units[0]) || '';
        range.min = num.min = props.min != null ? props.min : 0;
        range.max = num.max = props.max != null ? props.max : 100;
        range.step = num.step = props.step != null ? props.step : 1;
        var pousse = function (v, partial) { change({ value: String(v) + unit, partial: partial }); };
        range.addEventListener('input', function () { num.value = range.value; pousse(range.value, true); });
        range.addEventListener('change', function () { pousse(range.value, false); });
        num.addEventListener('change', function () { range.value = num.value; pousse(num.value, false); });
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
      editor.on('component:update:attributes', function (c) {
        if ('formulaire' === kindOf(c)) { poserJetonFormulaire(c); }
      });
    },
  });
})();
