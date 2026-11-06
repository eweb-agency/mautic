<?php

namespace Mautic\EmailBundle\EventListener;

use Mautic\ChannelBundle\ChannelEvents;
use Mautic\ChannelBundle\Entity\MessageQueue;
use Mautic\ChannelBundle\Event\MessageQueueBatchProcessEvent;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Model\EmailModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MessageQueueSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EmailModel $emailModel,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelEvents::PROCESS_MESSAGE_QUEUE_BATCH => ['onProcessMessageQueueBatch', 0],
        ];
    }

    /**
     * Sends campaign emails.
     */
    public function onProcessMessageQueueBatch(MessageQueueBatchProcessEvent $event): void
    {
        if (!$event->checkContext('email')) {
            return;
        }

        $messages = $event->getMessages();
        $emailId  = $event->getChannelId();
        $email    = $this->emailModel->getEntity($emailId);

        $sendTo            = [];
        $messagesByContact = [];
        // Sendly : désabonné entre-temps, le message échoue au lieu de passer
        // pour envoyé. Marqué envoyé sans stat, le contact redevenait « en
        // attente » et un plafond atteint faisait boucler le broadcast.
        $options           = [
            'email_type'   => MailHelper::EMAIL_TYPE_MARKETING,
            'dnc_as_error' => true,
        ];

        /** @var MessageQueue $message */
        foreach ($messages as $message) {
            if (!($email && $message->getLead() && $email->isPublished())) {
                $message->setFailed();
                continue;
            }

            $contact = $message->getLead()->getProfileFields();
            if (empty($contact['email'])) {
                // No email so just let this slide
                $message->setProcessed();
                $message->setSuccess();
            }
            $sendTo[$contact['id']]            = $contact;
            $messagesByContact[$contact['id']] = $message;
        }

        if (count($sendTo)) {
            $options['resend_message_queue'] = $messagesByContact;
            $errors                          = $this->emailModel->sendEmail($email, $sendTo, $options);

            // Let's see who was successful
            foreach ($messagesByContact as $contactId => $message) {
                // If the message is processed, it was rescheduled by sendEmail
                if ($message->isProcessed()) {
                    continue;
                }

                $message->setProcessed();
                if (empty($errors[$contactId])) {
                    $message->setSuccess();
                    continue;
                }

                // Setting it to failed so it could be rescheduled
                // by MessageQueueModel::processMessageQueue.
                // We will get job loops otherwise.
                $message->setFailed();
            }
        }

        $event->stopPropagation();
    }
}
