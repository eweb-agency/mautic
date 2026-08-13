/**
 * Builder de landing pages sur TÉLÉPHONE — chantier D, P8 (maquette
 * validée par le proprio le 12/08, décisions P8-a et P8-b acceptées).
 *
 * Ce que fait le module quand l'écran est étroit (< 768px) :
 * 1. Le panneau de gauche devient une FEUILLE qui glisse du bas
 *    (poignée, coins arrondis) ; les onglets Composants | Styles | Options
 *    passent dans une barre de navigation EN BAS, au pouce.
 * 2. L'ajout de composants se fait par TOUCHE : taper une tuile l'insère
 *    après le bloc sélectionné (le glisser-déposer est peu fiable au
 *    doigt — décision P8-b). La tuile Assistant IA garde son propre
 *    parcours (invite en place) : on ne fait que refermer la feuille.
 * 3. La barre du haut reste légère : Annuler + Terminer visibles, le
 *    reste (Enregistrer, Aperçu, annuler/rétablir, Effacer) part dans un
 *    menu « ⋯ » (décision P8-a).
 *
 * Tout est FAIL-OPEN : si une pièce attendue manque, le module ne fait
 * rien et le bureau reste intact. La bascule est pilotée par matchMedia
 * (+ la classe body `sendly-mobile-force` pour les recettes en fenêtre
 * large) ; le CSS vit dans builder-sendly.css sous `.sendly-mobile`.
 */
(function () {
  'use strict';

  if (!window.MauticGrapesJsPlugins) {
    window.MauticGrapesJsPlugins = [];
  }

  var ONGLETS = [
    { cible: 'open-blocks', libelle: 'Composants' },
    { cible: 'open-sm', libelle: 'Styles' },
    { cible: 'sendly-options', libelle: 'Options' },
  ];

  window.MauticGrapesJsPlugins.push({
    name: 'sendly-builder-mobile',
    context: ['page'],
    plugin: function (editor) {
      editor.on('load', function () {
        var racines = document.querySelectorAll('.builder, .builder-panel');
        var panneau = document.querySelector('.builder-panel');
        var conteneurVues = document.querySelector('.builder-panel .gjs-pn-views-container');
        if (!racines.length || !panneau || !conteneurVues) { return; }

        var mq = window.matchMedia('(max-width: 767px)');

        function actif() {
          return mq.matches || document.body.classList.contains('sendly-mobile-force');
        }

        function evaluer() {
          var on = actif();
          racines.forEach(function (r) { r.classList.toggle('sendly-mobile', on); });
          if (!on) { fermerFeuille(); fermerMenu(); }
        }

        function ouvrirFeuille() {
          racines.forEach(function (r) { r.classList.add('sendly-feuille-ouverte'); });
        }

        function fermerFeuille() {
          racines.forEach(function (r) { r.classList.remove('sendly-feuille-ouverte'); });
        }

        function feuilleOuverte() {
          return panneau.classList.contains('sendly-feuille-ouverte');
        }

        /* ── Poignée de la feuille : taper referme ─────────────────── */
        var poignee = document.createElement('button');
        poignee.type = 'button';
        poignee.className = 'sendly-poignee';
        poignee.setAttribute('aria-label', 'Fermer le panneau');
        poignee.addEventListener('click', fermerFeuille);
        conteneurVues.insertBefore(poignee, conteneurVues.firstChild);

        /* ── Barre de navigation du bas ────────────────────────────── */
        var nav = document.createElement('nav');
        nav.className = 'sendly-mobile-nav';
        ONGLETS.forEach(function (o) {
          var b = document.createElement('button');
          b.type = 'button';
          b.textContent = o.libelle;
          b.setAttribute('data-cible', o.cible);
          b.addEventListener('click', function () {
            var courant = nav.querySelector('.sendly-nav-actif');
            if (courant === b && feuilleOuverte()) { fermerFeuille(); return; }
            var bouton = editor.Panels.getButton('views', o.cible);
            if (bouton) { bouton.set('active', 1); }
            if (courant) { courant.classList.remove('sendly-nav-actif'); }
            b.classList.add('sendly-nav-actif');
            ouvrirFeuille();
          });
          nav.appendChild(b);
        });
        var premier = nav.querySelector('[data-cible="open-blocks"]');
        if (premier) { premier.classList.add('sendly-nav-actif'); }
        panneau.appendChild(nav);

        /* ── Ajout par TOUCHE (décision P8-b) ──────────────────────── */
        conteneurVues.addEventListener('click', function (e) {
          if (!actif()) { return; }
          var tuile = e.target.closest ? e.target.closest('.gjs-block') : null;
          if (!tuile || !conteneurVues.contains(tuile)) { return; }
          var libelleEl = tuile.querySelector('.gjs-block-label');
          var libelle = libelleEl ? libelleEl.textContent.trim() : '';
          // La tuile Assistant IA a son propre parcours (invite en place,
          // P7) : on referme seulement la feuille pour dégager le canvas.
          if ('Assistant IA' === libelle) { fermerFeuille(); return; }
          var bloc = editor.BlockManager.getAll().find(function (b) {
            var l = String(b.get('label') || '').replace(/<[^>]*>/g, '').trim();
            return l === libelle;
          });
          if (!bloc || !bloc.get('content')) { return; }
          var sel = editor.getSelected();
          var parent = sel && sel.parent && sel.parent() ? sel.parent() : editor.getWrapper();
          var index = sel && sel.index ? sel.index() + 1 : parent.components().length;
          var ajout = parent.append(bloc.get('content'), { at: index });
          var comp = ajout && ajout[0];
          if (comp) {
            editor.select(comp);
            var el = comp.view && comp.view.el;
            if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
          }
          fermerFeuille();
        });

        /* ── Menu « ⋯ » de la barre du haut (décision P8-a) ────────── */
        var optionsPanneau = document.querySelector('.builder-panel .gjs-pn-options');
        var menu = null;

        function fermerMenu() {
          if (menu) { menu.remove(); menu = null; }
        }

        function cliquerBouton(id) {
          var b = editor.Panels.getButton('options', id);
          if (b && b.view && b.view.el) { b.view.el.click(); }
        }

        var ENTREES = [
          { libelle: 'Enregistrer', action: function () { cliquerBouton('sendly-enregistrer'); } },
          { libelle: 'Aperçu', action: function () {
            var ap = document.getElementById('btn-views-Preview');
            if (ap) { ap.click(); }
          } },
          { libelle: 'Annuler la dernière action', action: function () { editor.UndoManager.undo(); } },
          { libelle: 'Rétablir', action: function () { editor.UndoManager.redo(); } },
          { libelle: 'Effacer la page', action: function () { cliquerBouton('sendly-effacer'); } },
        ];

        if (optionsPanneau) {
          // Chaque bouton du panneau reçoit son id en attribut : le CSS
          // mobile choisit QUI reste visible (Annuler, Terminer, ⋯).
          var boutons = editor.Panels.getPanel('options');
          if (boutons && boutons.get('buttons')) {
            boutons.get('buttons').forEach(function (b) {
              if (b.view && b.view.el) { b.view.el.setAttribute('data-sendly-id', b.get('id')); }
            });
          }
          var plus = document.createElement('button');
          plus.type = 'button';
          plus.className = 'sendly-menu-plus';
          plus.textContent = '⋯';
          plus.setAttribute('aria-label', "Plus d'actions");
          plus.addEventListener('click', function (e) {
            e.stopPropagation();
            if (menu) { fermerMenu(); return; }
            menu = document.createElement('div');
            menu.className = 'sendly-menu-mobile';
            ENTREES.forEach(function (entree) {
              var item = document.createElement('button');
              item.type = 'button';
              item.textContent = entree.libelle;
              item.addEventListener('click', function () { fermerMenu(); entree.action(); });
              menu.appendChild(item);
            });
            optionsPanneau.appendChild(menu);
          });
          optionsPanneau.appendChild(plus);
          document.addEventListener('click', function (e) {
            if (menu && !menu.contains(e.target) && e.target !== plus) { fermerMenu(); }
          });
        }

        /* ── Bascule ───────────────────────────────────────────────── */
        if (mq.addEventListener) { mq.addEventListener('change', evaluer); }
        else if (mq.addListener) { mq.addListener(evaluer); }
        window.__sendlyMobileEval = evaluer;
        evaluer();
      });
    },
  });
})();
