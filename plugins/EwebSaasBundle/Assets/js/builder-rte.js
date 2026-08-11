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

  var TOOLBAR = ['undo', 'redo', '|', 'bold', 'italic', 'underline', 'strikethrough', '|',
    'fontSize', 'fontFamily', 'fontColor', 'fontBackgroundColor', '|',
    'alignment', 'outdent', 'indent', '|', 'blockQuote', 'insertTable', '|',
    'bulletedList', 'numberedList', '|', 'link', '|', 'TokenPlugin'];

  var TOOLBAR_SANS_TOKEN = TOOLBAR.filter(function (i) { return 'TokenPlugin' !== i; });

  var STYLE_CKE = '.ck.ck-toolbar { border-radius: 10px; border-color: #e5e7eb; box-shadow: 0 8px 22px rgba(22, 35, 59, .14); }'
    + ' .ck.ck-editor__editable:not(.ck-editor__nested-editable).ck-focused { border-color: #004FFF; box-shadow: none; }';

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
        iconf.dynamicTokenLabel = 'Insérer un jeton';
        iconf.dynamicToken = Mautic.builderTokensForCkEditor || [];
        iconf.mention = { feeds: [{ marker: '{', feed: Mautic.getFeedItems, itemRenderer: Mautic.customItemRenderer }] };
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

        // L'INTÉGRATION OFFICIELLE : GrapesJS ne connaît que ce RTE.
        editor.setCustomRte({
          // Le contenu synchronisé redevient un ARBRE de composants — jamais
          // du contenu plat (piège historique de la modale).
          parseContent: true,

          enable: function (el, rte) {
            editor.RichTextEditor.hideToolbar();
            var iwin = frameWin();
            var self = this;
            return chargerBundle().then(function () {
              return iwin.ClassicEditor.create(el, construireConfig(iwin));
            }).then(function (cke) {
              self.__cke = cke;
              self.__origine = cke.getData();
              self.__donnee = null;
              cke.editing.view.focus();
              return self;
            });
          },

          disable: function (el, rte) {
            var self = this;
            if (!self.__cke) { return; }
            // La donnée est capturée AVANT destruction : getContent est
            // appelé par GrapesJS après coup, l'instance n'existera plus.
            self.__donnee = self.__cke.getData();
            var cke = self.__cke;
            self.__cke = null;
            return cke.destroy().catch(function () { /* déjà démonté */ });
          },

          getContent: function (el, rte) {
            var self = this;
            if (self.__cke) { return self.__cke.getData(); }
            return null !== self.__donnee ? self.__donnee : el.innerHTML;
          },
        });
      });
    },
  });
})();
