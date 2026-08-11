/**
 * Édition de texte INLINE du builder de landing pages — chantier D, P4.
 *
 * La modale « Edit » disparaît : double-cliquer un texte monte CKEditor 5
 * SUR le composant, dans la page (toolbar complète au-dessus du bloc) —
 * le motif maquette/Webmecanik. Clic à l'extérieur = sauvegarder,
 * Échap = abandonner. AUCUNE capacité perdue : mêmes polices, tailles,
 * palettes de couleurs, tableaux, lien « nouvel onglet », bouton Jetons et
 * autocomplétion `{` que la modale d'origine (config par le même helper
 * core `Mautic.GetCkEditorConfigOptions`).
 *
 * INTÉGRATION OFFICIELLE `editor.setCustomRte` : GrapesJS appelle NOTRE
 * enable/disable et synchronise le contenu via NOTRE getContent, avec
 * `parseContent: true` le contenu redevient un ARBRE de composants.
 * Décision arrachée à une course constatée en direct : en gérant nous-mêmes
 * rte:enable + listeners de fermeture, un clic-extérieur pendant un montage
 * ENCORE EN VOL laissait le RTE par défaut synchroniser un élément remplacé
 * par CKE → CONTENU VIDÉ. Avec setCustomRte, GrapesJS ne connaît qu'un seul
 * RTE : le nôtre — plus de double gestion, plus de course.
 *
 * Enregistré via `window.MauticGrapesJsPlugins`, contexte ['page'] :
 * l'éditeur d'e-mails garde sa modale. Aucun rebuild Parcel.
 *
 * Recette et pièges — chacun CONSTATÉ en direct le 11/08 :
 * - Bundle CKE5 chargé DANS L'IFRAME du canvas (script tag, URL relevée sur
 *   le document principal). Iframe recréée à chaque ouverture : le cache de
 *   chargement vit dans la fermeture du plugin, ré-instancié à chaque init.
 * - Une config du realm principal fait PLANTER `ClassicEditor.create` de
 *   l'iframe (« Cannot convert undefined or null to object ») — et l'échec
 *   VIDE l'élément édité. Clonage realm : `iwin.JSON.parse(JSON.stringify)`
 *   puis rebranchement des fonctions (feed, itemRenderer, MentionLinks) :
 *   appelées, pas introspectées, ça passe.
 * - `dynamicToken` doit être le TABLEAU `Mautic.builderTokensForCkEditor`
 *   ([{id, name}]) : le bouton Jetons fait `.map` dessus — l'OBJET
 *   builderTokens le fait planter (« e.map is not a function », constaté).
 * - L'aide native des jetons du core fait un ajax BLOQUANT (gel d'UI) :
 *   préchargement ASYNCHRONE maison des mêmes globaux, et la config est
 *   bâtie SANS `TokenPlugin` dans la toolbar passée au helper (c'est ce
 *   mot-clé qui déclenche l'appel bloquant) — le bouton est réinjecté
 *   ensuite dans `toolbar.items`.
 * - `editor.off('rte:enable')` au load retire le handler modale du preset
 *   (enregistré avant nous) ; un filtre sur `Modal.open` avale en
 *   ceinture-bretelles toute `cke-modal` résiduelle.
 */
(function () {
  'use strict';

  if (!window.MauticGrapesJsPlugins) {
    window.MauticGrapesJsPlugins = [];
  }

  // Barre COMPACTE (demande proprio 11/08) : les essentiels visibles, le
  // reste se replie dans le « ⋯ » natif de CKE (groupement au débordement,
  // forcé par le max-width de STYLE_CKE) — zéro capacité perdue.
  var TOOLBAR = ['bold', 'italic', 'underline', '|', 'link', 'bulletedList', 'numberedList', '|', 'TokenPlugin', '|',
    'fontSize', 'fontFamily', 'fontColor', 'fontBackgroundColor', '|',
    'alignment', 'outdent', 'indent', '|', 'blockQuote', 'insertTable', '|',
    'strikethrough', 'undo', 'redo'];

  var TOOLBAR_SANS_TOKEN = TOOLBAR.filter(function (i) { return 'TokenPlugin' !== i; });

  var STYLE_CKE = '.ck.ck-toolbar { border-radius: 10px; border-color: #e5e7eb; box-shadow: 0 8px 22px rgba(22, 35, 59, .14); }'
    + ' .ck.ck-editor__editable:not(.ck-editor__nested-editable).ck-focused { border-color: #004FFF; box-shadow: none; }'
    /* le max-width déclenche le repli « ⋯ » natif des items excédentaires ;
       borné aussi à la LARGEUR VISIBLE de la frame : en vue mobile, un bloc
       plus large que la frame fait scroller l'iframe et emportait la barre
       hors-champ (défaut proprio 11/08 — barre coupée, contour débordant) */
    + ' .ck.ck-editor__top .ck-toolbar { max-width: min(460px, calc(100vw - 16px)); }'
    + ' .ck.ck-editor__top { position: sticky; left: 0; z-index: 5; }';

  window.MauticGrapesJsPlugins.push({
    name: 'sendly-rte',
    context: ['page'],
    plugin: function (editor) {
      var chargement = null;

      function frameWin() { var f = document.querySelector('.builder-panel .gjs-frame'); return f ? f.contentWindow : null; }
      function frameDoc() { var f = document.querySelector('.builder-panel .gjs-frame'); return f ? f.contentDocument : null; }

      function urlBundle() {
        var s = document.querySelector('script[src*="ckeditor"]');
        return s ? s.src : null;
      }

      /** Charge le bundle CKE5 dans l'iframe du canvas (idempotent). */
      function chargerBundle() {
        var iwin = frameWin();
        var idoc = frameDoc();
        if (!iwin || !idoc) { return Promise.reject(new Error('canvas introuvable')); }
        if ('undefined' !== typeof iwin.ClassicEditor) { return Promise.resolve(); }
        if (!chargement) {
          chargement = new Promise(function (res, rej) {
            var s = idoc.createElement('script');
            s.src = urlBundle();
            s.onload = function () {
              var st = idoc.createElement('style');
              st.textContent = STYLE_CKE;
              idoc.head.appendChild(st);
              res();
            };
            s.onerror = rej;
            idoc.head.appendChild(s);
          });
        }
        return chargement;
      }

      /** Réplique ASYNCHRONE de l'aide native des jetons (qui gèle l'UI). */
      function prechargerJetons() {
        mQuery.ajax({
          url: mauticAjaxUrl,
          data: 'action=page:getBuilderTokens',
          success: function (response) {
            if ('object' === typeof response.tokens) {
              Mautic.builderTokens = response.tokens;
              Mautic.configureDynamicContentAtWhoTokens();
              mQuery.extend(Mautic.builderTokens, Mautic.dynamicContentTokens);
              Mautic.builderTokensForCkEditor = mQuery.map(Mautic.builderTokens, function (value, i) {
                return { id: i, name: value };
              });
            }
          },
        });
      }

      function construireConfig(iwin) {
        // SANS TokenPlugin dans la toolbar passée au helper : c'est ce
        // mot-clé qui déclenche l'ajax bloquant du core.
        var base = Mautic.GetCkEditorConfigOptions(TOOLBAR_SANS_TOKEN, 'page:getBuilderTokens');
        delete base.autosave;
        base.toolbar.items = TOOLBAR;
        var iconf = iwin.JSON.parse(JSON.stringify(base, function (k, v) {
          return 'function' === typeof v ? undefined : v;
        }));
        iconf.toolbar.shouldNotGroupWhenFull = false;
        iconf.dynamicTokenLabel = 'Insérer un jeton';
        iconf.dynamicToken = Mautic.builderTokensForCkEditor || [];
        // Mention ENTIÈREMENT même-realm (les jetons au `{` restaient muets :
        // feed en Promise du realm principal + renderer fabriquant des nœuds
        // du document principal = classe de bugs cross-realm entière) :
        // feed SYNCHRONE renvoyant des objets clonés dans le realm de
        // l'iframe, renderer construit avec le document de l'iframe.
        var idoc = frameDoc();
        iconf.mention = { feeds: [{
          marker: '{',
          minimumCharacters: 0,
          feed: function (queryText) {
            var q = (queryText || '').toLowerCase();
            var source = Mautic.builderTokensForCkEditor || [];
            var retenus = source.filter(function (item) {
              return item.name.toLowerCase().indexOf(q) !== -1 || item.id.toLowerCase().indexOf(q) !== -1;
            }).slice(0, 5);
            return iwin.JSON.parse(JSON.stringify(retenus));
          },
          itemRenderer: function (item) {
            // Réplique du renderer d'items du core, réalisée dans le
            // DOCUMENT DE L'IFRAME (nœuds du bon realm).
            var tokenId = item.id;
            var tokenName = item.name;
            var itemElement = idoc.createElement('span');
            var idElement = idoc.createElement('span');
            idElement.classList.add('custom-item-id');
            itemElement.classList.add('custom-item');
            if (0 === tokenName.indexOf('a:')) { tokenName = tokenName.substring(2); }
            if (/dwc=/i.test(tokenId)) {
              tokenName = tokenName + ' (' + tokenId.substr(5, tokenId.length - 6) + ')';
            } else if (/contactfield=company/i.test(tokenId) && !/company/i.test(tokenName)) {
              tokenName = 'Company ' + tokenName;
            }
            itemElement.textContent = tokenName;
            idElement.textContent = tokenId;
            itemElement.appendChild(idElement);
            return itemElement;
          },
        }] };
        iconf.extraPlugins = [Mautic.MentionLinks];
        return iconf;
      }

      /** Échap = abandonner : restaurer la donnée d'origine puis SIMULER
       *  le clic-extérieur natif — la SEULE voie de fermeture prouvée
       *  (disableEditing sur la vue et editor.select() laissent l'éditeur
       *  monté, constaté ; le clic-extérieur géré par GrapesJS ferme et
       *  synchronise — ici la donnée restaurée = abandon). */
      function surTouche(e) {
        if ('Escape' !== e.key) { return; }
        var rte = editor.RichTextEditor.customRte;
        if (!rte || !rte.__cke || 'string' !== typeof rte.__origine) { return; }
        e.stopPropagation();
        rte.__cke.setData(rte.__origine);
        var idoc = frameDoc();
        var iwin = idoc.defaultView;
        ['mousedown', 'mouseup', 'click'].forEach(function (t) {
          idoc.body.dispatchEvent(new iwin.MouseEvent(t, { bubbles: true, cancelable: true, clientX: 5, clientY: 5, view: iwin }));
        });
      }

      editor.on('load', function () {
        prechargerJetons();
        // PRÉCHAUFFAGE du bundle CKE dans l'iframe : sans lui, le premier
        // double-clic subissait 1-2 s de chargement — la fenêtre où le clic
        // impatient perdait du contenu.
        chargerBundle().catch(function () { /* retentera au premier montage */ });

        // Retire le handler modale du preset (enregistré avant nous)...
        editor.off('rte:enable');
        // ...et avale en ceinture-bretelles toute cke-modal résiduelle.
        var openOrig = editor.Modal.open;
        editor.Modal.open = function (opts) {
          if (opts && opts.attributes && 'cke-modal' === opts.attributes.class) {
            return this;
          }
          return openOrig.call(editor.Modal, opts);
        };

        var idoc = frameDoc();
        if (idoc) { idoc.addEventListener('keydown', surTouche, true); }

        /** Le composant GrapesJS propriétaire d'un élément du canvas. */
        function compDe(el) {
          try { return el.id ? editor.getWrapper().find('#' + el.id)[0] : null; } catch (e) { return null; }
        }

        /** La donnée de l'élément, par fraîcheur : instance vivante, capture
         *  de fermeture, origine du montage, DOM. TOUT est porté par
         *  l'ÉLÉMENT — jamais par l'objet RTE partagé : répondre à GrapesJS
         *  avec l'état d'un autre composant était LE croisement qui écrasait
         *  ou vidait les textes (défaut proprio 11/08, 2e vague, reproduit
         *  et journalisé en direct). */
        function contenuDe(el) {
          try {
            if (el.__sendlyCke) {
              var d = el.__sendlyCke.getData();
              if ('string' === typeof d) { return d; }
            }
          } catch (e) { /* instance déjà détruite */ }
          if (null !== el.__sendlyDonnee && undefined !== el.__sendlyDonnee) { return el.__sendlyDonnee; }
          return 'string' === typeof el.__sendlyOrigine ? el.__sendlyOrigine : el.innerHTML;
        }

        /** CKE rend `<p>…</p>` même quand le composant EST un <p> : sans
         *  déballage, chaque cycle d'édition imbriquait un <p> de plus. */
        function deballer(el, html) {
          if (el.tagName && 'P' === el.tagName) {
            var m = /^<p>([\s\S]*)<\/p>$/.exec((html || '').trim());
            if (m && -1 === m[1].indexOf('<p>')) { return m[1]; }
          }
          return html;
        }

        /** CKE laisse l'élément VIDE après destroy — et si le contenu n'a
         *  pas changé, GrapesJS ne re-rend PAS le composant (modèle
         *  identique) : texte intact dans le modèle mais INVISIBLE à
         *  l'écran. C'était LE « composant qui disparaît » du proprio.
         *  Le DOM est donc TOUJOURS restauré par nos soins après
         *  destruction. */
        function detruireEtRestaurer(cke, el) {
          var contenu = null;
          try { contenu = cke.getData(); } catch (e) { /* déjà détruite */ }
          return cke.destroy().catch(function () { /* déjà démonté */ }).then(function () {
            var c = 'string' === typeof contenu && '' !== contenu ? contenu : contenuDe(el);
            if ('string' === typeof c && '' !== c && '' === el.innerHTML) {
              el.innerHTML = deballer(el, c);
            }
          });
        }

        /** LE bouclier des clics réels (recette proprio 11/08, 3e vague) :
         *  GrapesJS ferme la session dès qu'un clic du canvas ne vise pas
         *  l'élément d'ORIGINE — or CKE l'a REMPLACÉ par sa propre
         *  interface : cliquer dans l'éditable ou sur un bouton de la barre
         *  était « dehors » pour GrapesJS → l'éditeur sautait au premier
         *  clic. (Indétectable aux événements synthétiques, qui visaient
         *  l'élément d'origine — seule la souris réelle passe par le DOM
         *  de CKE.) On coupe la REMONTÉE des événements souris depuis
         *  l'enveloppe CKE : ses boutons fonctionnent (phase cible), et
         *  GrapesJS ne voit plus ces clics — validé en clics réels. */
        function poserBouclier(cke) {
          try {
            var enveloppe = cke.ui.element;
            if (enveloppe && !enveloppe.__sendlyBouclier) {
              enveloppe.__sendlyBouclier = true;
              ['mousedown', 'mouseup', 'click', 'dblclick'].forEach(function (t) {
                enveloppe.addEventListener(t, function (e) { e.stopPropagation(); });
              });
            }
          } catch (e) { /* enveloppe indisponible : la session reste utilisable au clavier */ }
        }

        /** Fermeture d'office d'une session encore ouverte : GrapesJS ACTIVE
         *  le texte suivant AVANT de désactiver le précédent, et son disable
         *  tardif n'est PAS suivi d'un getContent (constaté au journal) — on
         *  rend donc ses données au modèle du propriétaire NOUS-MÊMES. */
        function fermerSession(el) {
          el.__sendlyGen = (el.__sendlyGen || 0) + 1;
          var contenu = contenuDe(el);
          if (el.__sendlyCke) {
            var cke = el.__sendlyCke;
            el.__sendlyCke = null;
            el.__sendlyDonnee = contenu;
            detruireEtRestaurer(cke, el);
          }
          var comp = el.__sendlyComp || compDe(el);
          if (comp && 'string' === typeof contenu && '' !== contenu) {
            comp.components(deballer(el, contenu));
          }
        }

        // L'INTÉGRATION OFFICIELLE : GrapesJS ne connaît que ce RTE.
        editor.setCustomRte({
          // Le contenu synchronisé redevient un ARBRE de composants — jamais
          // du contenu plat (piège historique de la modale).
          parseContent: true,

          // L'élément dont la session est ouverte (pointeur de session ;
          // l'ÉTAT, lui, vit sur chaque élément).
          __actif: null,

          enable: function (el, rte) {
            var self = this;
            editor.RichTextEditor.hideToolbar();
            // La mini-barre composant (déplacer/supprimer) flotte PILE sous
            // la barre CKE : un clic pour elle fermait la session — voire
            // SUPPRIMAIT le composant (corbeille). Masquée pendant la
            // session via cette classe (règle du thème).
            document.body.classList.add('sendly-rte-active');
            // Chevauchement GrapesJS : B s'active AVANT la désactivation
            // de A — sans cette fermeture, les données de A partaient en B.
            if (self.__actif && self.__actif !== el) { fermerSession(self.__actif); }
            self.__actif = el;
            var iwin = frameWin();
            // L'ORIGINE est capturée SYNCHRONEMENT, avant tout chargement :
            // pendant le vol du montage, l'élément est remplacé par CKE.
            el.__sendlyOrigine = el.innerHTML;
            el.__sendlyDonnee = null;
            el.__sendlyCke = null;
            el.__sendlyComp = compDe(el);
            // Jeton de génération PAR ÉLÉMENT : une fermeture pendant le vol
            // invalide le montage, l'instance tardive est détruite.
            var gen = el.__sendlyGen = (el.__sendlyGen || 0) + 1;
            // Miroirs de la session ACTIVE pour la touche Échap (surTouche).
            self.__origine = el.__sendlyOrigine;
            self.__cke = null;
            return chargerBundle().then(function () {
              return iwin.ClassicEditor.create(el, construireConfig(iwin));
            }).then(function (cke) {
              if (gen !== el.__sendlyGen || self.__actif !== el) {
                return detruireEtRestaurer(cke, el).then(function () { return self; });
              }
              el.__sendlyCke = cke;
              el.__sendlyOrigine = cke.getData();
              self.__cke = cke;
              self.__origine = el.__sendlyOrigine;
              poserBouclier(cke);
              // Un bloc plus large que la frame a pu la faire scroller :
              // on recale pour que la barre soit visible au montage.
              iwin.scrollTo({ left: 0 });
              cke.editing.view.focus();
              return self;
            });
          },

          disable: function (el, rte) {
            var self = this;
            if (self.__actif === el) { self.__actif = null; self.__cke = null; }
            document.body.classList.remove('sendly-rte-active');
            el.__sendlyGen = (el.__sendlyGen || 0) + 1;
            if (!el.__sendlyCke) { return; }
            // La donnée est capturée AVANT destruction : getContent est
            // appelé par GrapesJS après coup, l'instance n'existera plus.
            el.__sendlyDonnee = el.__sendlyCke.getData();
            var cke = el.__sendlyCke;
            el.__sendlyCke = null;
            return detruireEtRestaurer(cke, el);
          },

          getContent: function (el, rte) {
            return deballer(el, contenuDe(el));
          },
        });

        // Tout clic dans le CHROME du générateur (barre haute, panneaux —
        // le canvas vit dans l'iframe, il n'est jamais concerné) ferme
        // D'ABORD la session d'édition : la saisie en cours est rendue au
        // modèle AVANT l'action du bouton (sinon « Terminer » appliquait la
        // page sans elle, et l'éditeur restait flottant par-dessus les
        // panneaux — recette proprio 11/08). GrapesJS ferme déjà la session
        // sur la plupart des mousedown ; cette ceinture couvre les chemins
        // où son ordre d'écouteurs ne le garantit pas (validé en direct).
        // Le document survit aux recyclages du builder : chaque ouverture
        // enregistre SON écouteur, seul celui de l'éditeur COURANT agit.
        window.__sendlyEditeurCourant = editor;
        document.addEventListener('mousedown', function (e) {
          if (editor !== window.__sendlyEditeurCourant) { return; }
          // ⚠️ GrapesJS RE-DIFFUSE chaque clic du canvas sur le document
          // principal avec l'IFRAME pour cible (constaté aux sondes) : sans
          // cette garde, cliquer DANS le texte édité fermait la session.
          // Seuls les clics du vrai chrome (barre haute, panneaux) ferment.
          if (e.target && ('IFRAME' === e.target.tagName
            || (e.target.closest && e.target.closest('.gjs-cv-canvas')))) { return; }
          var rteObj = editor.RichTextEditor.customRte;
          if (rteObj && rteObj.__actif) {
            var el = rteObj.__actif;
            rteObj.__actif = null;
            rteObj.__cke = null;
            document.body.classList.remove('sendly-rte-active');
            fermerSession(el);
          }
        }, true);
      });
    },
  });
})();
