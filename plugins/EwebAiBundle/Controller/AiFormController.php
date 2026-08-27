<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Controller;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Model\FormModel;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Un formulaire devis : nom, e-mail, téléphone, message » — monté sur
 * /s/ai/form/create (lot 4 de l'assistant exécutant, audit du 27/08).
 *
 * Comme l'abtest (le précédent du bundle), cet endpoint CRÉE une entité :
 * la garde est celle de l'écran natif (`form:forms:create`). Deux
 * disciplines en plus :
 *  - la spécification passe par validateFormSpec — la MÊME barrière que
 *    l'action de l'assistant, jamais deux validations qui divergent ;
 *  - le formulaire naît DÉPUBLIÉ : rien ne collecte tant que l'utilisateur
 *    n'a pas relu et publié lui-même (le pendant du « l'assistant propose,
 *    l'utilisateur enregistre » des segments, transposé aux entités).
 *
 * Pas de jeton CSRF applicatif : même doctrine que l'abtest (garde XHR
 * même-origine, POST via mQuery qui porte le jeton global).
 */
final class AiFormController
{
    public function __construct(
        private readonly AiCopilotService $copilot,
        private readonly FormModel $formModel,
        private readonly CorePermissions $security,
    ) {
    }

    public function createAction(Request $request): JsonResponse
    {
        if (!$this->copilot->isEnabled()) {
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ('XMLHttpRequest' !== $request->headers->get('X-Requested-With')) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->security->isGranted('form:forms:create')) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $spec = $this->copilot->validateFormSpec($this->decode($request));
        if (null === $spec) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        $form = new Form();
        $form->setName($spec['name']);
        $form->setFormType('standalone');
        $form->setPostAction($spec['submit']['kind']);
        $form->setPostActionProperty($spec['submit']['value']);
        // DÉPUBLIÉ à la naissance : l'utilisateur relit, ajuste, publie.
        $form->setIsPublished(false);

        $ordre = 1;
        foreach ($spec['fields'] as $champ) {
            $field = new Field();
            $field->setLabel($champ['label']);
            $field->setShowLabel(true);
            $field->setType($champ['type']);
            $field->setAlias('champ_'.$ordre);
            $field->setIsRequired($champ['required']);
            $field->setOrder($ordre);
            if ('select' === $champ['type']) {
                $field->setProperties([
                    'list' => [
                        'list' => array_map(
                            static fn (string $o): array => ['label' => $o, 'value' => $o],
                            $champ['options'] ?? []
                        ),
                    ],
                ]);
            }
            // Seul mappage automatique : l'e-mail vers le contact — c'est lui
            // qui fait naître le contact à la soumission. Le reste est un
            // choix de l'utilisateur, pas une devinette de l'assistant.
            if ('email' === $champ['type']) {
                $field->setMappedObject('contact');
                $field->setMappedField('email');
            }
            $field->setForm($form);
            $form->addField($ordre, $field);
            ++$ordre;
        }

        // Le bouton d'envoi : un formulaire sans lui ne se soumet pas.
        $bouton = new Field();
        $bouton->setLabel('Envoyer');
        $bouton->setShowLabel(true);
        $bouton->setType('button');
        $bouton->setAlias('bouton_envoyer');
        $bouton->setOrder($ordre);
        $bouton->setForm($form);
        $form->addField($ordre, $bouton);

        try {
            $this->formModel->saveEntity($form);
        } catch (\Throwable) {
            // Le noyau a journalisé la cause ; jamais de détail interne au client.
            return new JsonResponse(['error' => 'save_failed'], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['id' => $form->getId()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Request $request): array
    {
        $raw = trim((string) $request->getContent());

        if ('' !== $raw && str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }
}
