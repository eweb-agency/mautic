/**
 * Onglet « Options » du builder de landing pages — chantier D, P3b.
 *
 * Le 3e onglet de la maquette validée (Composants | Styles | Options) :
 * les réglages de la PAGE sans quitter l'éditeur, synchronisés avec le
 * formulaire natif qui vit sous l'éditeur — aucune donnée nouvelle, aucun
 * POST : les valeurs partent avec « Terminer » comme si elles avaient été
 * saisies dans l'écran natif.
 *
 * Enregistré via `window.MauticGrapesJsPlugins`, contextes ['page',
 * 'email-mjml', 'email-html'] depuis le lot E3 (07/09) : l'e-mail a SA vue
 * (objet, pré-en-tête, expéditeur, publication, UTM) branchée sur le
 * formulaire natif `emailform`, la page garde la sienne. Fichier agrégé dans app.js, aucun
 * rebuild Parcel. S'exécute APRÈS builder-composants.js (ordre alphabétique
 * d'agrégation = ordre d'enregistrement des plugins = ordre des écouteurs
 * 'load') : les retraits de boutons de la P2 précèdent nos ajouts.
 *
 * Contenu (décisions 10/08) :
 * - Page : titre, langue, catégorie, description meta, noIndex.
 * - Publication : publiée, publier le / dépublier le.
 * - Redirection : type + URL. Scripts : head + pied de page.
 * - Éditeur : case « Contours des blocs » (OFF par défaut — le préréglage
 *   les activait au chargement, coupés par la P2) et retour du mode Code
 *   (le crayon retiré de la barre en P2 revit ici).
 *
 * ⚠️ Piège constaté en direct : exécuter la commande code-edit du preset
 * DIRECTEMENT via l'API des commandes PLANTE (« sender.set is not a
 * function ») — elle exige un BOUTON comme sender. D'où le bouton proxy
 * invisible dont on bascule `active` : le mécanisme natif au complet,
 * modale CodeMirror comprise.
 */
(function () {
  'use strict';

  if (!window.MauticGrapesJsPlugins) {
    window.MauticGrapesJsPlugins = [];
  }

  /** page | email — le formulaire hôte fait foi (même lecture que
   *  builder-shell.js) ; il porte le nom du formulaire ET le préfixe des
   *  champs (page[…] / emailform[…]). */
  function formulaire() {
    return mQuery('form[name="page"]').length ? 'page' : 'emailform';
  }

  /** `utmTags.utmSource` -> `emailform[utmTags][utmSource]`. */
  function selecteur(nom) {
    var f = formulaire();
    return 'form[name="' + f + '"] [name="' + f + '[' + nom.split('.').join('][') + ']"]';
  }

  function champNatif(nom) {
    return document.querySelector(selecteur(nom));
  }

  function tousLesChampsNatifs(nom) {
    return document.querySelectorAll(selecteur(nom));
  }

  function optionsDe(sel) {
    if (!sel) { return ''; }
    return Array.prototype.map.call(sel.options, function (o) {
      return '<option value="' + o.value + '">' + (o.textContent.trim() || '—') + '</option>';
    }).join('');
  }

  function construireVue(cont) {
    var vue = document.createElement('div');
    vue.id = 'sendly-options-view';
    vue.style.display = 'none';
    vue.innerHTML = 'page' === formulaire() ? vuePage() : vueEmail();
    cont.appendChild(vue);
    return vue;
  }

  /** Lot E3 : les réglages de l'E-MAIL sans quitter l'éditeur. */
  function vueEmail() {
    return '<div class="sendly-opt-titre">E-mail</div>'
      + '<label class="sendly-opt-row"><span>Nom interne</span><input data-nat="name" type="text"></label>'
      + '<label class="sendly-opt-row"><span>Objet</span><input data-nat="subject" type="text"></label>'
      + '<label class="sendly-opt-row"><span>Pré-en-tête</span><input data-nat="preheaderText" type="text" placeholder="le texte visible avant l\'ouverture"></label>'
      + '<label class="sendly-opt-row"><span>Langue</span><select data-nat="language">' + optionsDe(champNatif('language')) + '</select></label>'
      + '<label class="sendly-opt-row"><span>Catégorie</span><select data-nat="category">' + optionsDe(champNatif('category')) + '</select></label>'
      + '<div class="sendly-opt-titre">Expéditeur</div>'
      + '<label class="sendly-opt-row"><span>Nom</span><input data-nat="fromName" type="text"></label>'
      + '<label class="sendly-opt-row"><span>Adresse</span><input data-nat="fromAddress" type="text"></label>'
      + '<label class="sendly-opt-row"><span>Répondre à</span><input data-nat="replyToAddress" type="text"></label>'
      + '<div class="sendly-opt-titre">Publication</div>'
      + '<label class="sendly-opt-check"><input data-nat-radio="isPublished" type="checkbox"><span>E-mail publié</span></label>'
      + '<label class="sendly-opt-row"><span>Publier le</span><input data-nat="publishUp" type="text" placeholder="2026-09-15 09:00"></label>'
      + '<label class="sendly-opt-row"><span>Dépublier le</span><input data-nat="publishDown" type="text" placeholder="laisser vide = jamais"></label>'
      + '<div class="sendly-opt-titre">Suivi des liens (UTM)</div>'
      + '<label class="sendly-opt-row"><span>Source</span><input data-nat="utmTags.utmSource" type="text"></label>'
      + '<label class="sendly-opt-row"><span>Support</span><input data-nat="utmTags.utmMedium" type="text"></label>'
      + '<label class="sendly-opt-row"><span>Campagne</span><input data-nat="utmTags.utmCampaign" type="text"></label>'
      + '<label class="sendly-opt-row"><span>Contenu</span><input data-nat="utmTags.utmContent" type="text"></label>'
      + '<div class="sendly-opt-titre">Éditeur</div>'
      + '<label class="sendly-opt-check"><input id="sendly-opt-contours" type="checkbox"><span>Contours des blocs</span></label>'
      + '<button id="sendly-opt-code" type="button" class="sendly-opt-bouton">Modifier le code de l\'e-mail</button>';
  }

  function vuePage() {
    return '<div class="sendly-opt-titre">Page</div>'
      + '<label class="sendly-opt-row"><span>Titre</span><input data-nat="title" type="text"></label>'
      + '<label class="sendly-opt-row"><span>Langue</span><select data-nat="language">' + optionsDe(champNatif('language')) + '</select></label>'
      + '<label class="sendly-opt-row"><span>Catégorie</span><select data-nat="category">' + optionsDe(champNatif('category')) + '</select></label>'
      + '<label class="sendly-opt-row"><span>Description meta (SEO)</span><textarea data-nat="metaDescription" rows="2"></textarea></label>'
      + '<label class="sendly-opt-check"><input data-nat-radio="noIndex" type="checkbox"><span>Demander aux moteurs de ne pas indexer</span></label>'
      + '<div class="sendly-opt-titre">Publication</div>'
      + '<label class="sendly-opt-check"><input data-nat-radio="isPublished" type="checkbox"><span>Page publiée</span></label>'
      + '<label class="sendly-opt-row"><span>Publier le</span><input data-nat="publishUp" type="text" placeholder="2026-08-15 09:00"></label>'
      + '<label class="sendly-opt-row"><span>Dépublier le</span><input data-nat="publishDown" type="text" placeholder="laisser vide = jamais"></label>'
      + '<div class="sendly-opt-titre">Redirection</div>'
      + '<label class="sendly-opt-row"><span>Type</span><select data-nat="redirectType">' + optionsDe(champNatif('redirectType')) + '</select></label>'
      + '<label class="sendly-opt-row"><span>URL de destination</span><input data-nat="redirectUrl" type="text" placeholder="https://…"></label>'
      + '<div class="sendly-opt-titre">Scripts</div>'
      + '<label class="sendly-opt-row"><span>Script d\'en-tête (&lt;head&gt;)</span><textarea data-nat="headScript" rows="3"></textarea></label>'
      + '<label class="sendly-opt-row"><span>Script de pied de page</span><textarea data-nat="footerScript" rows="3"></textarea></label>'
      + '<div class="sendly-opt-titre">Éditeur</div>'
      + '<label class="sendly-opt-check"><input id="sendly-opt-contours" type="checkbox"><span>Contours des blocs</span></label>'
      + '<button id="sendly-opt-code" type="button" class="sendly-opt-bouton">Modifier le code de la page</button>';
  }

  /** Lecture : le formulaire natif fait foi à chaque ouverture de l'onglet. */
  function lire(vue) {
    vue.querySelectorAll('[data-nat]').forEach(function (el) {
      var n = champNatif(el.dataset.nat);
      if (n) { el.value = n.value; }
    });
    vue.querySelectorAll('[data-nat-radio]').forEach(function (el) {
      var coche = false;
      tousLesChampsNatifs(el.dataset.natRadio).forEach(function (r) {
        if (r.checked && '1' === r.value) { coche = true; }
      });
      el.checked = coche;
    });
  }

  function brancherEcriture(vue, editor) {
    vue.addEventListener('change', function (e) {
      var el = e.target;
      if (el.dataset.nat) {
        var n = champNatif(el.dataset.nat);
        if (n) {
          n.value = el.value;
          n.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
      if (el.dataset.natRadio) {
        // Les booléens natifs Mautic = paire de radios yes(1)/no(0).
        tousLesChampsNatifs(el.dataset.natRadio).forEach(function (r) {
          if ('1' === r.value) { r.checked = el.checked; }
          if ('0' === r.value) { r.checked = !el.checked; }
        });
      }
      if ('sendly-opt-contours' === el.id) {
        if (el.checked) { editor.runCommand('sw-visibility'); } else { editor.stopCommand('sw-visibility'); }
      }
    });
  }

  window.MauticGrapesJsPlugins.push({
    name: 'sendly-options',
    context: ['page', 'email-mjml', 'email-html'],
    plugin: function (editor) {
      editor.on('load', function () {
        var pm = editor.Panels;
        var cont = document.querySelector('.builder-panel .gjs-pn-views-container');
        if (!cont) { return; }

        var vue = construireVue(cont);
        brancherEcriture(vue, editor);

        // Le proxy invisible qui porte la commande du preset (cf. en-tête).
        if (!pm.getButton('options', 'sendly-code-proxy')) {
          pm.addButton('options', { id: 'sendly-code-proxy', command: 'preset-mautic:code-edit', className: 'sendly-cache', attributes: { style: 'display:none' } });
        }
        vue.querySelector('#sendly-opt-code').addEventListener('click', function () {
          pm.getButton('options', 'sendly-code-proxy').set('active', 1);
        });

        if (!pm.getButton('views', 'sendly-options')) {
          pm.addButton('views', {
            id: 'sendly-options', label: 'Options', className: 'sendly-tab', attributes: { title: 'Options' },
            command: {
              run: function () { lire(vue); vue.style.display = 'block'; },
              stop: function () { vue.style.display = 'none'; },
            },
          });
        }
      });
    },
  });
})();
