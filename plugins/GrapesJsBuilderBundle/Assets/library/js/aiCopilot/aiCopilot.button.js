import AiCopilotCommand from './aiCopilot.command';

/**
 * Bouton « Assistant IA » dans la barre d'options de l'éditeur d'e-mail.
 * Calqué sur codeMode.button. N'est instancié (builder.service) que si
 * window.SendlyAiConfig.enabled — donc absent sans clé Anthropic.
 */
export default class AiCopilotButton {
  editor;

  constructor(editor) {
    if (!editor) {
      throw new Error('no editor');
    }
    this.editor = editor;
  }

  addButton() {
    this.editor.Panels.addButton('options', [
      {
        id: 'ai-generate',
        className: 'ri-sparkling-2-line',
        attributes: {
          title: Mautic.translate('grapesjsbuilder.aiGenerate'),
        },
        command: AiCopilotCommand.name,
      },
    ]);
  }

  addCommand() {
    this.editor.Commands.add(AiCopilotCommand.name, {
      run: AiCopilotCommand.launch,
    });
  }
}
