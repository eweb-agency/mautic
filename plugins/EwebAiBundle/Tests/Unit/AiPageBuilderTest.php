<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Assistant de page (chantier D, P5) : la tuile « Assistant IA » du builder
 * de landing pages ouvre le panneau conversationnel UNIQUE (contrat
 * AssistantLauncherTest) avec un contexte 'page-builder' qui parle à
 * l'endpoint /s/ai/generate existant et INSÈRE le résultat dans la page,
 * de façon annulable. Surfaces JavaScript agrégées sans harnais de DOM :
 * contrat verrouillé au niveau des SOURCES.
 */
final class AiPageBuilderTest extends TestCase
{
    private function source(string $file): string
    {
        $path = __DIR__.'/../../Assets/js/'.$file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testLeContexteDePageExisteEtSInscritDansLePanneauUnique(): void
    {
        $js = $this->source('ai-page-builder.js');

        self::assertStringContainsString("id: 'page-builder'", $js);
        self::assertStringContainsString('priority: 20', $js);
        // Disponible UNIQUEMENT dans le builder de pages (pas d'e-mails).
        self::assertStringContainsString('.builder-active.gjs-mode-page', $js);
        foreach (['title:', 'welcome:', 'placeholder:', 'thinking:', 'shortcuts:', 'onSend:', 'onUndo:'] as $membre) {
            self::assertStringContainsString($membre, $js);
        }
        // AUCUN panneau propre : le contexte n'a pas le droit de bâtir un DOM
        // de conversation à lui (contrat du panneau unique).
        self::assertStringNotContainsString('sendly-assist-panel', $js);
    }

    public function testLaReponseLueEstLeChampTextDuControleur(): void
    {
        $js = $this->source('ai-page-builder.js');

        // AiController::generateAction répond {'text' => $text} — tout autre
        // champ lu serait un contrat imaginaire (bug attrapé avant commit).
        self::assertStringContainsString('rep.text', $js);
        self::assertStringNotContainsString('rep.result', $js);
        self::assertStringNotContainsString('rep.html', $js);
    }

    public function testLEndpointVientDeLaConfigExposeeAvecRepli(): void
    {
        $js = $this->source('ai-page-builder.js');

        // AiConfigAssetsSubscriber expose la route de génération sous la clé
        // `endpoint` (pas une clé inventée) ; repli sur le chemin en dur.
        self::assertStringContainsString('window.SendlyAiConfig.endpoint', $js);
        self::assertStringContainsString("'/s/ai/generate'", $js);
        // Garde XHR même-origine du contrôleur.
        self::assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $js);
    }

    public function testLesTroisModesEtLeurContrat(): void
    {
        $js = $this->source('ai-page-builder.js');

        foreach (["'translate'", "'improve'", "'generate'"] as $mode) {
            self::assertStringContainsString($mode, $js);
        }
        // improve/translate exigent une sélection : son HTML part en content.
        self::assertStringContainsString('corps.content = selection.html', $js);
        self::assertStringContainsString('corps.lang', $js);
        // Sans sélection, message d'aide — pas d'appel réseau à vide.
        self::assertStringContainsString("Sélectionne d\\'abord le composant", $js);
    }

    public function testLInsertionEstDansLArbreEtAnnulable(): void
    {
        $js = $this->source('ai-page-builder.js');

        // generate : insertion APRÈS la sélection, sinon en fin de page.
        self::assertStringContainsString('selection.model.index() + 1', $js);
        self::assertStringContainsString('ed.getWrapper().append(html)', $js);
        // improve/translate : remplacement du CONTENU du composant, avec
        // mémo de l'état d'avant pris AVANT le remplacement.
        self::assertStringContainsString('selection.model.components(html)', $js);
        self::assertStringContainsString('var avant = selection.model.components()', $js);
        // Chaque action porte un mémo d'annulation consommé par onUndo.
        self::assertStringContainsString("etat['undo-' + turnId]", $js);
        self::assertStringContainsString('undoable: true', $js);
        self::assertStringContainsString('markUndone', $js);
    }

    public function testLeSansCleRepondProprement(): void
    {
        $js = $this->source('ai-page-builder.js');

        // Relevé en réel sur un tenant sans clé : POST /s/ai/generate → 503
        // {"error":"disabled"} — le message doit rester en français client.
        self::assertStringContainsString('503 === xhr.status', $js);
        self::assertStringContainsString("L\\'assistant n\\'est pas activé sur cette instance.", $js);
    }

    public function testLaTuileOuvreLePanneauSansRienInserer(): void
    {
        $js = $this->source('ai-page-builder.js');

        // Clic sur la tuile ET dépôt dans le canvas mènent au panneau…
        self::assertStringContainsString("window.SendlyAssistant.open('page-builder')", $js);
        self::assertStringContainsString("'block:drag:stop'", $js);
        self::assertStringContainsString("'sendly-ia' !== bloc.get('id')", $js);
        // …et le dépôt ne laisse AUCUN résidu vide dans la page.
        self::assertStringContainsString('c.remove()', $js);
    }

    public function testLaFacadeSaitOuvrirUnContexteCible(): void
    {
        $js = $this->source('ai-assistant.js');

        // open(ctxId) : ouvre le panneau sur un contexte PRÉCIS s'il est
        // disponible — c'est la porte qu'emprunte la tuile du builder.
        self::assertStringContainsString('open: function (ctxId)', $js);
        self::assertStringContainsString('openPanel(ctx)', $js);
    }
}
