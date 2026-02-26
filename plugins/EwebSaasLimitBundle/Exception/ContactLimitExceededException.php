<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasLimitBundle\Exception;

class ContactLimitExceededException extends \RuntimeException
{
    public function __construct(
        private readonly int $currentCount,
        private readonly int $maxLimit,
        ?\Throwable $previous = null,
    ) {
        $message = sprintf(
            'Limite de contacts atteinte (%d/%d). Votre abonnement ne permet pas de créer plus de contacts.',
            $this->currentCount,
            $this->maxLimit,
        );

        parent::__construct($message, 0, $previous);
    }

    public function getCurrentCount(): int
    {
        return $this->currentCount;
    }

    public function getMaxLimit(): int
    {
        return $this->maxLimit;
    }

    /**
     * Returns the user-facing message with a call-to-action to upgrade.
     */
    public function getUserMessage(): string
    {
        return sprintf(
            'Limite de contacts atteinte (%d/%d). Votre abonnement ne permet pas de créer plus de contacts. Veuillez augmenter votre abonnement depuis votre portail.',
            $this->currentCount,
            $this->maxLimit,
        );
    }
}
