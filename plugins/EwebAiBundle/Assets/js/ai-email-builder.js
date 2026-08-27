/**
 * « Écris-moi l'e-mail de relance… » — lot 3 de l'assistant exécutant
 * (audit du 27/08, même patron que la landing page du lot 2).
 *
 * L'assistant global dépose un brief (sessionStorage, TTL 3 min) puis
 * navigue vers l'écran « nouvel e-mail ». Ici, DEUX étages :
 *
 * ÉTAGE FORMULAIRE (/s/emails/new) :
 *   - ferme la modale de type en choisissant l'e-mail de SEGMENT
 *     (Mautic.selectEmailType('list') — le geste natif) ;
 *   - remplit le nom interne et l'OBJET proposés par l'assistant ;
 *   - fait correspondre le segment destinataire NOMMÉ PAR L'UTILISATEUR
 *     avec les vraies listes de l'écran (jamais inventé : sans
 *     correspondance, le champ reste vide et l'utilisateur choisit) ;
 *   - choisit le thème blank et ouvre l'éditeur.
 *
 * ÉTAGE ÉDITEUR (contextes email-html ET email-mjml — recette du 27/08 :
 * un NOUVEL e-mail naît toujours avec un squelette <mjml> pré-rempli,
 * quel que soit le thème ; l'hypothèse « blank = HTML » était fausse à
 * l'exécution et le premier gating email-html ne tournait jamais) :
 *   - consomme le brief et génère le CORPS via /s/ai/generate
 *     (mode generate, format mjml ou html selon le squelette présent) ;
 *   - insère avec la discipline du writeContent de la modale, SANS
 *     MjmlService (non exposé hors du dist) : instantané AVANT, pose,
 *     re-parse par resérialisation (balises auto-fermantes, bug #149),
 *     restauration de l'instantané au moindre échec — prototypé en
 *     conditions réelles dans l'éditeur du proprio avant d'être codé ;
 *   - l'itération (Régénérer, Améliorer, TRADUIRE) passe par la modale
 *     IA existante de l'éditeur — pas de machinerie doublée.
 *
 * Gated par la clé de l'instance : sans SendlyAiConfig.enabled, tout
 * reste dormant et le brief périmé est purgé.
 */
(function () {
  'use strict';

  var CLE = 'sendlyAiEmailBrief';
  var TTL = 180000;

  function cfg() { return window.SendlyAiConfig || null; }

  function lireBrief() {
    var brut = null;
    try { brut = sessionStorage.getItem(CLE); } catch (e) {}
    if (!brut) { return null; }
    var brief = null;
    try { brief = JSON.parse(brut); } catch (e) {}
    if (!brief || !brief.ts || Date.now() - brief.ts > TTL) {
      try { sessionStorage.removeItem(CLE); } catch (e) {}
      return null;
    }
    return brief;
  }

  /* ─── Étage FORMULAIRE : /s/emails/new ─────────────────────────────── */
  mQuery(function () {
    var brief = lireBrief();
    if (!brief) { return; }
    if (!(cfg() && cfg().enabled)) {
      try { sessionStorage.removeItem(CLE); } catch (e) {}
      return;
    }
    var essais = 0;
    var minuterie = setInterval(function () {
      essais += 1;
      if (essais > 20) { clearInterval(minuterie); return; }
      if (!/\/s\/emails\/new/.test(window.location.pathname)) { return; }
      var $nom = mQuery('#emailform_name');
      var $objet = mQuery('#emailform_subject');
      if (!$nom.length || !$objet.length || 'function' !== typeof window.Mautic.launchBuilder) { return; }
      clearInterval(minuterie);

      // Le type SEGMENT d'abord : le geste natif ferme aussi la modale.
      if ('function' === typeof window.Mautic.selectEmailType) {
        window.Mautic.selectEmailType('list');
      }

      if (!$nom.val()) { $nom.val(brief.name || '').trigger('change').trigger('keyup'); }
      if (!$objet.val()) { $objet.val(brief.subject || '').trigger('change').trigger('keyup'); }

      // Le segment destinataire : correspondance sur les VRAIES listes de
      // l'écran (contient, sans casse) — jamais inventé.
      if (brief.segment) {
        var $listes = mQuery('#emailform_lists');
        if ($listes.length) {
          var voulu = String(brief.segment).toLowerCase();
          var valeur = null;
          $listes.find('option').each(function () {
            if (null === valeur && (this.textContent || '').toLowerCase().indexOf(voulu) !== -1) {
              valeur = this.value;
            }
          });
          if (null !== valeur) {
            $listes.val([valeur]).trigger('change').trigger('chosen:updated');
          }
        }
      }

      // Thème blank de préférence — le bouton éditeur est inerte sans thème.
      var $theme = mQuery('#emailform_template');
      if ($theme.length && !$theme.val()) {
        var v = $theme.find('option[value="blank"]').length ? 'blank'
          : $theme.find('option').filter(function () { return '' !== this.value; }).first().val();
        if (v) { $theme.val(v).trigger('change').trigger('chosen:updated'); }
      }

      setTimeout(function () { window.Mautic.launchBuilder('emailform'); }, 500);
    }, 500);
  });

  /* ─── Étage ÉDITEUR : génération du corps (html ET mjml) ───────────── */
  if (!window.MauticGrapesJsPlugins) {
    window.MauticGrapesJsPlugins = [];
  }
  window.MauticGrapesJsPlugins.push({
    name: 'sendly-ai-email',
    context: ['email-html', 'email-mjml'],
    plugin: function (editor) {
      editor.on('load', function () {
        var brief = lireBrief();
        if (!brief) { return; }
        try { sessionStorage.removeItem(CLE); } catch (e) {}
        if (!(cfg() && cfg().enabled) || (cfg() && cfg().teaser)) { return; }

        // Le mode se lit sur le champ du formulaire : un nouvel e-mail
        // porte toujours un squelette <mjml> (constat de recette 27/08).
        var champMjml = document.querySelector('#emailform_customMjml');
        var enMjml = !!(champMjml && /<mjml/i.test(champMjml.value || ''));

        var consigne = 'Objet de l’e-mail : « ' + (brief.subject || '') + ' ». ' + (brief.brief || '');
        mQuery.ajax({
          url: cfg().endpoint,
          type: 'POST',
          dataType: 'json',
          data: { mode: 'generate', instruction: consigne, format: enMjml ? 'mjml' : 'html' },
          success: function (rep) {
            var corps = rep && rep.text;
            if (!corps) { return; }
            // Discipline writeContent sans MjmlService : instantané AVANT,
            // pose, re-parse par resérialisation, restauration si échec —
            // le canvas de départ n'est qu'un squelette, mais on ne laisse
            // JAMAIS un canvas cassé. L'utilisateur itère ensuite avec la
            // modale IA (Régénérer / Améliorer / Traduire).
            var avant = editor.getHtml();
            try {
              editor.DomComponents.getWrapper().set('content', '');
              editor.setComponents(corps);
              editor.setComponents(editor.getHtml());
            } catch (e) {
              try { editor.setComponents(avant); } catch (e2) { /* squelette perdu — canvas vide */ }
            }
          },
          error: function () { /* l'éditeur garde son squelette — la modale IA existante prend le relais */ }
        });
      });
    },
  });
})();
