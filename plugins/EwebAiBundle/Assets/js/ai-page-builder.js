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
 */
(function () {
  'use strict';

  if (!window.MauticGrapesJsPlugins) {
    window.MauticGrapesJsPlugins = [];
  }

  var RACCOURCIS = [
    'Une section hero avec un titre fort et un bouton',
    'Une section « 3 avantages » avec icônes',
    'Une bande de témoignages clients',
    'Un appel à l\'action final avec bouton',
  ];

  var LANGUES = ['anglais', 'espagnol', 'allemand', 'italien', 'néerlandais'];

  // La MÊME étincelle que la tuile « Assistant IA » (identité visuelle
  // unique de l'IA — question proprio 12/08), en currentColor pour suivre
  // la couleur du contexte ; et une icône « langues » du même trait Lucide.
  var ICONE_IA = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:-2px"><path d="M9.1071 5.448C9.7051 3.698 12.1231 3.645 12.8321 5.289L12.8921 5.449L13.6991 7.809C13.884 8.35023 14.1829 8.84551 14.5755 9.26142C14.9682 9.67734 15.4454 10.0042 15.9751 10.22L16.1921 10.301L18.5521 11.107C20.3021 11.705 20.3551 14.123 18.7121 14.832L18.5521 14.892L16.1921 15.699C15.6507 15.8838 15.1552 16.1826 14.7391 16.5753C14.323 16.9679 13.996 17.4452 13.7801 17.975L13.6991 18.191L12.8931 20.552C12.2951 22.302 9.8771 22.355 9.1691 20.712L9.1071 20.552L8.3011 18.192C8.11628 17.6506 7.81748 17.1551 7.42485 16.739C7.03221 16.3229 6.5549 15.9959 6.0251 15.78L5.8091 15.699L3.4491 14.893C1.6981 14.295 1.6451 11.877 3.2891 11.169L3.4491 11.107L5.8091 10.301C6.35034 10.1161 6.84562 9.81719 7.26153 9.42457C7.67744 9.03195 8.00432 8.55469 8.2201 8.025L8.3011 7.809L9.1071 5.448Z"/></svg>';
  var ICONE_LANGUES = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/></svg>';

  function endpoint() {
    return (window.SendlyAiConfig && window.SendlyAiConfig.endpoint)
      || (window.mauticBasePath || '') + '/s/ai/generate';
  }

  function appelIa(corps) {
    corps.format = 'html';
    corps.surface = 'page';
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
      ? 'L\'assistant n\'est pas activé sur cette instance.'
      : 'La génération a échoué — réessaie dans un instant.';
  }

  window.MauticGrapesJsPlugins.push({
    name: 'sendly-ai-page',
    context: ['page'],
    plugin: function (editor) {
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
        st.textContent = ''
          + '.sendly-ia-invite { border: 1.5px solid #004FFF; border-radius: 12px; background: #fff; padding: 14px; margin: 10px 0; box-shadow: 0 10px 28px rgba(22,35,59,.12); font-family: -apple-system, "Segoe UI", sans-serif; }'
          + '.sendly-ia-invite .ligne { display: flex; gap: 8px; }'
          + '.sendly-ia-invite input { flex: 1; border: 1px solid #e5e7eb; background: #f7f8fa; border-radius: 9px; padding: 10px 12px; font-size: 14px; color: #24303f; outline: none; }'
          + '.sendly-ia-invite input:focus { border-color: #004FFF; background: #fff; }'
          + '.sendly-ia-invite button.envoyer { border: none; background: #004FFF; color: #fff; border-radius: 9px; padding: 10px 16px; font-weight: 600; font-size: 13.5px; cursor: pointer; }'
          + '.sendly-ia-invite .chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }'
          + '.sendly-ia-invite .chip { border: 1px solid #cfe0ff; color: #004FFF; background: #f2f6ff; border-radius: 99px; padding: 4px 10px; font-size: 12px; cursor: pointer; }'
          + '.sendly-ia-invite .chip:hover { background: #e3edff; }'
          + '.sendly-ia-invite .erreur { color: #c02942; font-size: 12.5px; margin-top: 8px; }'
          + '.sendly-ia-invite .fermer { float: right; border: none; background: none; color: #7b8698; font-size: 16px; cursor: pointer; margin: -4px -4px 0 8px; }'
          + '.sendly-ia-squelette { border: 1.5px dashed #b9c3d2; border-radius: 12px; background: #fff; padding: 18px; margin: 10px 0; }'
          + '.sendly-ia-squelette div { height: 10px; border-radius: 5px; margin-bottom: 10px; background: linear-gradient(90deg, #e8ecf2 30%, #f5f7fa 50%, #e8ecf2 70%); background-size: 200% 100%; animation: sendly-shimmer 1.2s infinite linear; }'
          + '@keyframes sendly-shimmer { to { background-position: -200% 0; } }'
          + '.sendly-ia-barre { display: flex; gap: 6px; align-items: center; border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; padding: 7px 9px; margin: 8px 0; box-shadow: 0 6px 18px rgba(22,35,59,.10); font-family: -apple-system, "Segoe UI", sans-serif; }'
          + '.sendly-ia-barre button { border: 1px solid #e5e7eb; background: #fff; color: #24303f; border-radius: 8px; padding: 5px 10px; font-size: 12.5px; cursor: pointer; }'
          + '.sendly-ia-barre button:hover { border-color: #004FFF; color: #004FFF; }'
          + '.sendly-ia-barre button.garder { background: #effaf4; border-color: #bfe3d1; color: #0d9455; font-weight: 600; }'
          + '.sendly-ia-menu { position: absolute; z-index: 60; border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; box-shadow: 0 12px 30px rgba(22,35,59,.16); padding: 6px; font-family: -apple-system, "Segoe UI", sans-serif; min-width: 190px; }'
          + '.sendly-ia-menu button { display: block; width: 100%; text-align: left; border: none; background: none; padding: 8px 10px; font-size: 13px; color: #24303f; border-radius: 7px; cursor: pointer; }'
          + '.sendly-ia-menu button:hover { background: #f2f6ff; color: #004FFF; }'
          + '.sendly-ia-menu .titre { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #7b8698; padding: 6px 10px 2px; }';
        idoc.head.appendChild(st);
      }

      /* ------------------------------------------------------------------ *
       *  LE BLOC DE SAISIE EN PLACE                                         *
       * ------------------------------------------------------------------ */

      /** Insère un composant-invite à `index` chez `parent`, monte la saisie
       *  dedans et rend le composant. valeurInitiale pré-remplit (Ajuster). */
      function ouvrirInvite(parent, index, valeurInitiale) {
        injecterStyles();
        var ajout = parent.append('<div data-sendly-invite="1"></div>', { at: index });
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
        boite.innerHTML = '<button class="fermer" title="Fermer">✕</button>'
          + '<div class="ligne"><input type="text" placeholder="Décris la section à générer…"><button class="envoyer">' + ICONE_IA + ' Générer</button></div>'
          + '<div class="chips"></div>';
        var champ = boite.querySelector('input');
        champ.value = valeur;
        var chips = boite.querySelector('.chips');
        RACCOURCIS.forEach(function (r) {
          var c = idoc.createElement('button');
          c.className = 'chip';
          c.textContent = r;
          c.addEventListener('click', function () { champ.value = r; lancer(); });
          chips.appendChild(c);
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
          sq.innerHTML = '<div style="width:70%"></div><div style="width:50%"></div><div style="width:35%; margin-bottom:0"></div>';
          el.appendChild(sq);
        }
        appelIa({ mode: 'generate', instruction: consigne }).then(function (html) {
          var parent = compInvite.parent();
          var index = compInvite.index();
          if (!parent) { return; }
          var ajoutes = parent.append(html, { at: index });
          compInvite.remove();
          poserBarre(parent, index, ajoutes, consigne);
        }).catch(function (err) {
          var elv = compInvite.view && compInvite.view.el;
          if (elv) { monterSaisie(compInvite, elv, consigne); var e2 = idoc.createElement('div'); e2.className = 'erreur'; e2.textContent = messageErreur(err); elv.querySelector('.sendly-ia-invite').appendChild(e2); }
        });
      }

      /* ------------------------------------------------------------------ *
       *  LA BARRE CONTEXTUELLE  Régénérer / Ajuster / Garder / Annuler      *
       * ------------------------------------------------------------------ */

      function poserBarre(parent, index, sections, consigne) {
        injecterStyles();
        var ajout = parent.append('<div data-sendly-barre="1"></div>', { at: index });
        var compBarre = ajout && ajout[0];
        if (!compBarre) { return; }
        compBarre.set({ editable: false, droppable: false, copyable: false, selectable: false, hoverable: false });
        var el = compBarre.view && compBarre.view.el;
        if (!el) { compBarre.remove(); return; }
        var idoc = frameDoc();
        el.innerHTML = '';
        var barre = blinder(idoc.createElement('div'));
        barre.className = 'sendly-ia-barre';
        barre.innerHTML = '<button class="regenerer">↻ Régénérer</button>'
          + '<button class="ajuster">✎ Ajuster</button>'
          + '<button class="garder">✓ Garder</button>'
          + '<button class="annuler" title="Supprimer la section">✕</button>';
        function retirerSections() { (sections || []).forEach(function (c) { if (c && c.remove) { c.remove(); } }); }
        barre.querySelector('.garder').addEventListener('click', function () { compBarre.remove(); });
        barre.querySelector('.annuler').addEventListener('click', function () { retirerSections(); compBarre.remove(); });
        barre.querySelector('.regenerer').addEventListener('click', function () {
          var p = compBarre.parent();
          var i = compBarre.index();
          retirerSections();
          compBarre.remove();
          if (p) {
            var inv = p.append('<div data-sendly-invite="1"></div>', { at: i });
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
        var contenu = sel.toHTML();
        var avant = sel.components().map(function (c) { return c.toHTML(); }).join('');
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
        var ajout = parent.append('<div data-sendly-barre="1"></div>', { at: index });
        var compBarre = ajout && ajout[0];
        if (!compBarre) { return; }
        compBarre.set({ editable: false, droppable: false, copyable: false, selectable: false, hoverable: false });
        var el = compBarre.view && compBarre.view.el;
        if (!el) { compBarre.remove(); return; }
        var idoc = frameDoc();
        var barre = blinder(idoc.createElement('div'));
        barre.className = 'sendly-ia-barre';
        barre.innerHTML = '<button class="garder">✓ Garder</button><button class="annuler">↩ Annuler</button>';
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
        if (!comp || 'text' !== comp.get('type')) { return; }
        var barre = comp.get('toolbar') || [];
        if (barre.some(function (b) { return 'sendly-ia-ameliorer' === b.command; })) { return; }
        comp.set('toolbar', barre.concat([
          { attributes: { class: 'sendly-tb-ia', title: 'Améliorer avec l\'IA' }, command: 'sendly-ia-ameliorer', label: ICONE_IA },
          { attributes: { class: 'sendly-tb-ia', title: 'Traduire' }, command: 'sendly-ia-traduire', label: ICONE_LANGUES },
        ]));
      }

      /* ------------------------------------------------------------------ *
       *  CÂBLAGE                                                            *
       * ------------------------------------------------------------------ */

      editor.Commands.add('sendly-ia-ameliorer', { run: function () { retoucher('improve'); } });
      editor.Commands.add('sendly-ia-traduire', { run: function (ed) { var s = ed.getSelected(); if (s) { ouvrirMenuTraduction(s); } } });

      editor.on('component:selected', equiperMiniBarre);

      editor.on('load', function () {
        injecterStyles();

        // La TUILE au CLIC : invite après la sélection, sinon en fin de page.
        var tuile = Array.prototype.find.call(
          document.querySelectorAll('.builder-panel .gjs-block'),
          function (el) { return /assistant ia/i.test(el.textContent || ''); }
        );
        if (tuile) {
          tuile.addEventListener('click', function () {
            var sel = editor.getSelected();
            if (sel && sel.parent()) { ouvrirInvite(sel.parent(), sel.index() + 1, ''); }
            else { ouvrirInvite(editor.getWrapper(), editor.getWrapper().components().length, ''); }
          });
        }

        // La TUILE au DÉPÔT : l'invite s'ouvre À L'ENDROIT du dépôt.
        editor.on('block:drag:stop', function (composant, bloc) {
          if (!bloc || 'sendly-ia' !== bloc.get('id')) { return; }
          var premiers = Array.isArray(composant) ? composant : [composant];
          var repere = premiers[0];
          var parent = repere && repere.parent ? repere.parent() : null;
          var index = repere && repere.index ? repere.index() : 0;
          premiers.forEach(function (c) { if (c && c.remove) { c.remove(); } });
          if (parent) { ouvrirInvite(parent, index, ''); }
        });

        // Les résidus d'interface ne survivent JAMAIS à une sauvegarde :
        // invites et barres sont purgées du HTML exporté.
        var exportOrig = editor.getHtml.bind(editor);
        editor.getHtml = function (opts) {
          var html = exportOrig(opts);
          return html.replace(/<div data-sendly-(?:invite|barre)="1">[\s\S]*?<\/div>/g, '');
        };
      });
    },
  });
})();
