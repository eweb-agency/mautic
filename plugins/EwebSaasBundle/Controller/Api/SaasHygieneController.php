<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Controller\Api;

use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\DoNotContact;
use MauticPlugin\EwebSaasBundle\Service\StatsAggregator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Hygiène de liste — PREMIER endpoint d'ÉCRITURE de l'API saas.
 *
 * POST /api/saas/v1/hygiene/dnc  {"emails": ["a@b.c", ...], "reason": "..."}
 *
 * Désinscrit (DNC unsubscribed, canal email) les contacts correspondant
 * aux adresses fournies — utilisé par le dashboard après validation
 * Mailgun des adresses (P1.1). Contrat volontairement étroit :
 *  - écriture limitée au DNC (aucune suppression, aucune édition) ;
 *  - unsubscribed plutôt que bounced : réversible par le contact,
 *    et addDncForContact ne « rétrograde » jamais un unsubscribed
 *    existant (checkCurrentStatus) — idempotent ;
 *  - plafond dur par appel, adresses inconnues simplement comptées.
 *
 * Même pare-feu OAuth2 que le reste de /api/saas/v1 ; le cache de stats
 * est invalidé (les compteurs d'audience changent).
 */
final class SaasHygieneController
{
    public const MAX_EMAILS = 500;

    public function __construct(
        private readonly DoNotContact $doNotContact,
        private readonly LeadRepository $leadRepository,
        private readonly StatsAggregator $aggregator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function dncAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !isset($payload['emails']) || !is_array($payload['emails'])) {
            return new JsonResponse(['error' => 'invalid_payload'], 400);
        }

        $emails = array_values(array_unique(array_filter(
            $payload['emails'],
            static fn ($email): bool => is_string($email) && false !== filter_var($email, FILTER_VALIDATE_EMAIL),
        )));

        if ([] === $emails) {
            return new JsonResponse(['error' => 'no_valid_emails'], 400);
        }
        if (count($emails) > self::MAX_EMAILS) {
            return new JsonResponse(['error' => 'too_many_emails', 'max' => self::MAX_EMAILS], 422);
        }

        $reason  = isset($payload['reason']) && is_string($payload['reason'])
            ? mb_substr($payload['reason'], 0, 191)
            : 'Hygiène de liste (validation d\'adresses)';

        $matched  = 0;
        $dncAdded = 0;

        foreach ($emails as $email) {
            $contacts = $this->leadRepository->getContactsByEmail($email);
            if ([] === $contacts) {
                continue;
            }
            ++$matched;
            foreach ($contacts as $contact) {
                $result = $this->doNotContact->addDncForContact(
                    $contact->getId(),
                    'email',
                    DNC::UNSUBSCRIBED,
                    $reason,
                );
                if (false !== $result) {
                    ++$dncAdded;
                }
            }
        }

        $this->aggregator->invalidateCache();
        $this->logger->info('[saas.hygiene] dnc', [
            'requested' => count($emails),
            'matched'   => $matched,
            'dncAdded'  => $dncAdded,
        ]);

        return new JsonResponse([
            'requested' => count($emails),
            'matched'   => $matched,
            'dncAdded'  => $dncAdded,
        ]);
    }
}
