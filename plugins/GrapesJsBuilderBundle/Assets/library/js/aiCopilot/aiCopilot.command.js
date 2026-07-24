import ContentService from 'grapesjs-preset-mautic/dist/content.service';
import MjmlService from 'grapesjs-preset-mautic/dist/mjml/mjml.service';

/**
 * Commande du copilote IA dans l'éditeur d'e-mail (GrapesJS).
 *
 * Ouvre une modale « consigne → Générer / Améliorer », appelle l'endpoint
 * serveur (window.SendlyAiConfig.endpoint) et écrit la réponse dans le canvas
 * en respectant le mode courant (HTML vs MJML). La clé API n'est jamais côté
 * navigateur : tout passe par l'endpoint /s/ai/generate.
 *
 * L'éditeur n'auto-sauvegarde pas (storageManager:false) : après insertion, on
 * rappelle à l'utilisateur d'Enregistrer/Appliquer.
 */
export default class AiCopilotCommand {
  static name = 'sendly:ai-generate';

  static launch(editor, sender) {
    if (!editor) {
      throw new Error('no editor');
    }
    if (sender) {
      sender.set('active', 0);
    }
    AiCopilotCommand.openModal(editor);
  }

  static isMjml(editor) {
    return ContentService.isMjmlMode(editor);
  }

  /**
   * Lit le contenu courant de l'éditeur (MJML ou HTML selon le mode).
   */
  static readContent(editor) {
    return AiCopilotCommand.isMjml(editor)
      ? MjmlService.getEditorMjmlContent(editor)
      : ContentService.getEditorHtmlContent(editor);
  }

  /**
   * Écrit le contenu généré dans le canvas — même discipline que
   * codeEditor.updateCode (valider le MJML AVANT d'effacer le canvas, puis
   * re-parser pour contourner le bug des balises auto-fermantes mjml #149).
   */
  static writeContent(editor, code) {
    const content = (code || '').trim();
    if (AiCopilotCommand.isMjml(editor)) {
      // Lève si le MJML est invalide → on n'a pas encore touché au canvas.
      MjmlService.mjmlToHtml(content);
    }
    editor.DomComponents.getWrapper().set('content', '');
    editor.setComponents(content);
    if (AiCopilotCommand.isMjml(editor)) {
      const parsed = MjmlService.getEditorMjmlContent(editor);
      editor.setComponents(parsed);
    }
  }

  static openModal(editor) {
    const cfg = window.SendlyAiConfig;
    if (!cfg || !cfg.enabled) {
      return;
    }
    const t = (key) => Mautic.translate(key);
    const format = AiCopilotCommand.isMjml(editor) ? 'mjml' : 'html';

    const wrap = document.createElement('div');
    wrap.className = 'sendly-ai-modal';
    wrap.innerHTML = `
      <textarea class="sendly-ai-instruction form-control" rows="4"
        placeholder="${t('grapesjsbuilder.aiInstructionPlaceholder')}"></textarea>
      <p class="sendly-ai-hint text-muted" style="margin:8px 0 0;font-size:12px">
        ${t('grapesjsbuilder.aiSaveHint')}
      </p>
      <div class="sendly-ai-error alert alert-danger" style="display:none;margin-top:10px"></div>
      <div class="sendly-ai-actions" style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button type="button" class="btn btn-default sendly-ai-improve">
          ${t('grapesjsbuilder.aiImproveBtn')}
        </button>
        <button type="button" class="btn btn-primary sendly-ai-generate">
          ${t('grapesjsbuilder.aiGenerateBtn')}
        </button>
        <span style="flex-basis:100%;height:0"></span>
        <select class="sendly-ai-lang" style="height:34px;border:1px solid #d1d5db;border-radius:4px;padding:0 8px">
          <option value="French">Français</option>
          <option value="English">English</option>
          <option value="Spanish">Español</option>
          <option value="German">Deutsch</option>
          <option value="Italian">Italiano</option>
          <option value="Portuguese">Português</option>
          <option value="Dutch">Nederlands</option>
        </select>
        <button type="button" class="btn btn-default sendly-ai-translate">
          ${t('grapesjsbuilder.aiTranslateBtn')}
        </button>
      </div>`;

    const $instruction = wrap.querySelector('.sendly-ai-instruction');
    const $error = wrap.querySelector('.sendly-ai-error');
    const $improve = wrap.querySelector('.sendly-ai-improve');
    const $generate = wrap.querySelector('.sendly-ai-generate');
    const $translate = wrap.querySelector('.sendly-ai-translate');
    const $lang = wrap.querySelector('.sendly-ai-lang');

    const btnFor = { generate: $generate, improve: $improve, translate: $translate };
    const setBusy = (busy, mode) => {
      [$improve, $generate, $translate].forEach((b) => {
        b.disabled = busy;
      });
      $generate.textContent = t('grapesjsbuilder.aiGenerateBtn');
      $improve.textContent = t('grapesjsbuilder.aiImproveBtn');
      $translate.textContent = t('grapesjsbuilder.aiTranslateBtn');
      if (busy && btnFor[mode]) {
        btnFor[mode].textContent = t('grapesjsbuilder.aiGenerating');
      }
    };

    const showError = () => {
      $error.textContent = t('grapesjsbuilder.aiError');
      $error.style.display = 'block';
    };

    const run = (mode) => {
      $error.style.display = 'none';
      setBusy(true, mode);

      const payload = {
        mode,
        format,
        instruction: $instruction.value || '',
        content:
          'improve' === mode || 'translate' === mode
            ? AiCopilotCommand.readContent(editor)
            : '',
        lang: 'translate' === mode ? $lang.value || '' : '',
      };

      AiCopilotCommand.request(cfg.endpoint, payload)
        .then((text) => {
          try {
            AiCopilotCommand.writeContent(editor, text);
            editor.Modal.close();
          } catch (e) {
            // MJML invalide renvoyé par le modèle : le canvas est intact.
            $error.textContent = `${t('grapesjsbuilder.sourceSyntaxError')} ${e.message}`;
            $error.style.display = 'block';
            setBusy(false);
          }
        })
        .catch(() => {
          showError();
          setBusy(false);
        });
    };

    $generate.addEventListener('click', () => run('generate'));
    $improve.addEventListener('click', () => run('improve'));
    $translate.addEventListener('click', () => run('translate'));

    editor.Modal.setTitle(t('grapesjsbuilder.aiModalTitle'));
    editor.Modal.setContent(wrap);
    editor.Modal.open();
  }

  /**
   * POST JSON vers l'endpoint serveur ; résout avec le texte généré.
   */
  static request(endpoint, payload) {
    return fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        // fetch() ne passe pas par mQuery.ajaxSetup : il faut poser le jeton
        // à la main, sinon le garde CSRF global de Mautic (RequestSubscriber)
        // court-circuite tout POST XHR vers /s/ avant le contrôleur.
        'X-CSRF-Token': mauticAjaxCsrf, // global (script.html.twig), cf. builder.service.js
      },
      body: JSON.stringify(payload),
    }).then((res) => {
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      return res.json();
    }).then((data) => {
      if (!data || !data.text) {
        throw new Error('empty');
      }
      return data.text;
    });
  }
}
