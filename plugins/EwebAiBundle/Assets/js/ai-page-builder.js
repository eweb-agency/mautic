/**
 * Assistant de page — chantier D, P5 : la tuile « Assistant IA » de la
 * bibliothèque de composants ouvre LE panneau conversationnel unique
 * (décision proprio 10/08 : tuile = seule entrée IA de l'éditeur, le
 * lanceur flottant y est masqué par builder-shell.js).
 *
 * Le contexte « Assistant de page » (priorité 20) parle à l'endpoint
 * EXISTANT /s/ai/generate (modes generate / improve / translate, format
 * html) — gated par la clé Anthropic de l'instance comme tout le copilote.
 *
 * Ce que sait faire la conversation :
 * - « Génère une section … »  → generate : le HTML produit est INSÉRÉ dans
 *   la page (après la sélection, sinon en fin de page), annulable.
 * - « Améliore … » avec un composant sélectionné → improve : le contenu du
 *   composant est remplacé, annulable.
 * - « Traduis … en <langue> » avec une sélection → translate : idem.
 *
 * Enregistré via `window.MauticGrapesJsPlugins` (contexte ['page']) pour
 * capturer l'instance GrapesJS, et via `window.SendlyAssistantContexts`
 * pour le panneau. Fichier agrégé dans app.js — aucun rebuild.
 */
(function () {
  'use strict';

  if (!window.MauticGrapesJsPlugins) {
    window.MauticGrapesJsPlugins = [];
  }
  if (!window.SendlyAssistantContexts) {
    window.SendlyAssistantContexts = [];
  }

  var etat = { editor: null };

  function t(cle, defaut) {
    try {
      var v = Mautic.translate ? Mautic.translate(cle) : null;
      return v && v !== cle ? v : defaut;
    } catch (e) { return defaut; }
  }

  function endpoint() {
    // La config exposée par AiConfigAssetsSubscriber nomme la route de
    // génération `endpoint` (relevé) ; repli sur le chemin en dur.
    return (window.SendlyAiConfig && window.SendlyAiConfig.endpoint)
      || (window.mauticBasePath || '') + '/s/ai/generate';
  }

  function builderOuvert() {
    return !!document.querySelector('.builder-active.gjs-mode-page');
  }

  /** Le mode se déduit du message : traduire / améliorer / générer. */
  function analyser(texte) {
    var bas = texte.toLowerCase();
    var langue = (bas.match(/tradui[st]?e?s?.*?\ben\s+([a-zà-ÿ-]+)/) || [])[1] || '';
    if (/tradui/.test(bas)) { return { mode: 'translate', lang: langue || 'anglais' }; }
    if (/améliore|ameliore|reformule|corrige|raccourcis|allonge/.test(bas)) { return { mode: 'improve' }; }
    return { mode: 'generate' };
  }

  function selectionHtml() {
    var sel = etat.editor ? etat.editor.getSelected() : null;
    return sel ? { model: sel, html: sel.toHTML() } : null;
  }

  function envoyer(texte, api) {
    var plan = analyser(texte);
    var selection = selectionHtml();
    var corps = { mode: plan.mode, format: 'html' };

    if ('generate' === plan.mode) {
      corps.instruction = texte;
    } else {
      if (!selection) {
        api.ia('Sélectionne d\'abord le composant à ' + ('translate' === plan.mode ? 'traduire' : 'améliorer') + ' dans la page, puis renvoie ta demande.');
        api.finish();
        return;
      }
      corps.content = selection.html;
      corps.instruction = texte;
      if (plan.lang) { corps.lang = plan.lang; }
    }

    mQuery.ajax({
      url: endpoint(),
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify(corps),
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).done(function (rep) {
      // Le contrôleur répond { text: … } (relevé dans AiController).
      var html = rep && rep.text;
      if (!html) {
        api.fail(t('mautic.core.ai.error', 'La génération n\'a rien renvoyé — réessaie en reformulant.'));
        return;
      }
      var ed = etat.editor;
      if ('generate' === plan.mode) {
        // Insertion APRÈS la sélection, sinon en fin de page — annulable.
        var ajoutes = selection
          ? selection.model.parent().append(html, { at: selection.model.index() + 1 })
          : ed.getWrapper().append(html);
        var turnId = 'gen-' + Date.now();
        etat['undo-' + turnId] = ajoutes;
        api.ia('Section insérée dans la page — regarde le canvas. Tu peux la retoucher comme n\'importe quel bloc, ou annuler ci-dessous.', { turnId: turnId, undoable: true });
      } else {
        var avant = selection.model.components().map(function (c) { return c.toHTML(); }).join('');
        selection.model.components(html);
        var turnId2 = 'mod-' + Date.now();
        etat['undo-' + turnId2] = { model: selection.model, avant: avant };
        api.ia(('translate' === plan.mode ? 'Traduction appliquée' : 'Texte amélioré') + ' sur le composant sélectionné — annulable ci-dessous.', { turnId: turnId2, undoable: true });
      }
      api.finish();
    }).fail(function (xhr) {
      if (xhr && 503 === xhr.status) {
        api.fail('L\'assistant n\'est pas activé sur cette instance.');
        return;
      }
      api.fail(t('mautic.core.ai.error', 'Le service n\'a pas répondu — réessaie dans un instant.'));
    });
  }

  function annuler(turnId, api) {
    var memo = etat['undo-' + turnId];
    if (!memo) { return; }
    if (Array.isArray(memo)) {
      memo.forEach(function (c) { c.remove(); });
    } else if (memo.model) {
      memo.model.components(memo.avant);
    }
    delete etat['undo-' + turnId];
    if (api && api.markUndone) { api.markUndone(turnId); }
  }

  window.SendlyAssistantContexts.push({
    id: 'page-builder',
    priority: 20,
    available: builderOuvert,
    title: function () { return 'Assistant de page'; },
    welcome: function () {
      return 'Je travaille directement dans ta page : demande-moi de <b>générer une section</b> (« Génère une section hero pour un cabinet d\'architectes »), d\'<b>améliorer</b> le composant sélectionné, ou de le <b>traduire</b>.';
    },
    placeholder: function () { return 'Décris la section à générer, ou sélectionne un bloc…'; },
    thinking: function () { return 'Je compose…'; },
    shortcuts: function () {
      return [
        'Génère une section hero avec un titre fort et un bouton',
        'Génère une section « 3 avantages » avec icônes',
        'Améliore le texte du composant sélectionné',
        'Traduis le composant sélectionné en anglais',
      ];
    },
    onSend: envoyer,
    onUndo: annuler,
  });

  window.MauticGrapesJsPlugins.push({
    name: 'sendly-ai-page',
    context: ['page'],
    plugin: function (editor) {
      editor.on('load', function () {
        etat.editor = editor;

        // La TUILE ouvre le panneau : au clic…
        var tuile = Array.prototype.find.call(
          document.querySelectorAll('.builder-panel .gjs-block'),
          function (el) { return /assistant ia/i.test(el.textContent || ''); }
        );
        if (tuile) {
          tuile.addEventListener('click', function () {
            if (window.SendlyAssistant && window.SendlyAssistant.open) {
              window.SendlyAssistant.open('page-builder');
            }
          });
        }
        // …et au dépôt dans le canvas (la tuile n'insère rien : contenu vide,
        // on retire le résidu et on ouvre le panneau).
        editor.on('block:drag:stop', function (composant, bloc) {
          if (!bloc || 'sendly-ia' !== bloc.get('id')) { return; }
          (Array.isArray(composant) ? composant : [composant]).forEach(function (c) {
            if (c && c.remove) { c.remove(); }
          });
          if (window.SendlyAssistant && window.SendlyAssistant.open) {
            window.SendlyAssistant.open('page-builder');
          }
        });
      });
    },
  });
})();
