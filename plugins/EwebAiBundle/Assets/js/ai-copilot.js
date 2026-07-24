/**
 * Copilote IA — bouton « Suggérer un objet » du champ objet de l'e-mail.
 *
 * Ce fichier est auto-agrégé dans app.js (AssetGenerationHelper scanne
 * plugins/ *​/Assets/js) et donc présent sur TOUTES les pages admin. Il reste
 * totalement inerte tant que window.SendlyAiConfig n'est pas défini — ce
 * drapeau n'est injecté (AiConfigAssetsSubscriber) que si une clé Anthropic
 * est configurée. Sans clé : aucune surface, aucun appel.
 *
 * La clé API n'est JAMAIS ici : la génération passe par l'endpoint serveur
 * /s/ai/generate qui la lit côté PHP. mQuery.ajax hérite du jeton CSRF via
 * l'ajaxSetup global de Mautic.
 */
(function () {
  'use strict';

  // Spinner autonome (Remix Icon n'a pas de classe d'animation).
  if (!document.getElementById('sendly-ai-style')) {
    var style = document.createElement('style');
    style.id = 'sendly-ai-style';
    style.textContent =
      '@keyframes sendly-ai-spin{to{transform:rotate(360deg)}}' +
      '.sendly-ai-spin{display:inline-block;animation:sendly-ai-spin .8s linear infinite}' +
      '#email-subject-ai{cursor:pointer}' +
      '.sendly-ai-error{color:#c0392b}';
    document.head.appendChild(style);
  }

  /**
   * Appelée par l'icône postaddon du champ objet (onclick, EmailType).
   * @param {*} $subject élément jQuery/mQuery du champ #emailform_subject
   */
  Mautic.suggestEmailSubject = function ($subject) {
    var cfg = window.SendlyAiConfig;
    if (!cfg || !cfg.enabled) {
      return;
    }

    if (!$subject || !$subject.length) {
      $subject = mQuery('#emailform_subject');
    }
    // L'id vit sur le <span> conteneur ; l'icône est le <i> enfant. On verrouille
    // sur le span, mais on n'échange la classe QUE sur le glyphe.
    var $addon = mQuery('#email-subject-ai');
    if ($addon.data('aiBusy')) {
      return;
    }
    var $glyph = $addon.find('i');
    if (!$glyph.length) {
      $glyph = $addon;
    }
    var glyphClass = $glyph.attr('class') || 'ri-sparkling-2-line';

    // Corps courant : la source la plus fiable disponible sur le formulaire.
    var body = '';
    var candidates = ['textarea.builder-mjml', 'textarea.builder-html', '#emailform_customHtml'];
    for (var i = 0; i < candidates.length; i++) {
      var val = mQuery(candidates[i]).val();
      if (val && val.trim() !== '') {
        body = val;
        break;
      }
    }
    var format = mQuery('textarea.builder-mjml').val() ? 'mjml' : 'html';

    $addon.data('aiBusy', true);
    $glyph.attr('class', 'ri-loader-4-line sendly-ai-spin');

    mQuery
      .ajax({
        url: cfg.endpoint,
        method: 'POST',
        contentType: 'application/json; charset=UTF-8',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        data: JSON.stringify({
          mode: 'subject',
          subject: $subject.val() || '',
          content: body,
          format: format
        })
      })
      .done(function (resp) {
        if (resp && resp.text) {
          $subject.val(resp.text).trigger('blur');
          $glyph.attr('class', glyphClass);
        } else {
          Mautic.aiSubjectError($addon, $glyph, glyphClass);
        }
      })
      .fail(function () {
        Mautic.aiSubjectError($addon, $glyph, glyphClass);
      })
      .always(function () {
        // Le verrou seulement : la restauration du glyphe est faite par
        // done/fail (sinon .always écraserait l'état d'erreur avant affichage).
        $addon.data('aiBusy', false);
      });
  };

  /**
   * Retour d'erreur discret : glyphe rouge + titre traduit, ~2,5 s, puis
   * restauration de l'icône et du libellé d'origine.
   */
  Mautic.aiSubjectError = function ($addon, $glyph, glyphClass) {
    $glyph.attr('class', glyphClass + ' sendly-ai-error');
    $addon.attr('title', Mautic.translate('mautic.email.subject.ai_error'));
    setTimeout(function () {
      $glyph.attr('class', glyphClass);
      $addon.attr('title', Mautic.translate('mautic.email.subject.ai_suggest'));
    }, 2500);
  };
})();
