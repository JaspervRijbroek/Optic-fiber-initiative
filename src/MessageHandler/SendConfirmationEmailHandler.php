<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendConfirmationEmail;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Twig\Environment;

#[AsMessageHandler]
final class SendConfirmationEmailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly RateLimiterFactory $sesEmailLimiter,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SendConfirmationEmail $message): void
    {
        // Enforce the Amazon SES rate limit: 150 emails per 24 hours.
        $limiter = $this->sesEmailLimiter->create('ses_outbound');
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();
            $this->logger->warning(
                'SES email rate limit reached (150/24h). Message will be retried.',
                ['retry_after_seconds' => $retryAfter, 'recipient' => $message->email]
            );

            throw new \RuntimeException(
                sprintf('SES rate limit exceeded. Retry after %d seconds.', $retryAfter)
            );
        }

        $firstName = explode(' ', $message->nombre)[0];
        $unsubscribeUrl = rtrim($message->siteUrl, '/') . '/api/unsubscribe?token=' . urlencode($message->unsubscribeToken);

        $html = $this->twig->render('email/confirmation.html.twig', [
            'firstName' => $firstName,
            'unsubscribeUrl' => $unsubscribeUrl,
        ]);

        $email = (new Email())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($message->email)
            ->subject('¡Gracias por registrar tu interés en fibra óptica!')
            ->html($html);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Failed to send confirmation email.', [
                'recipient' => $message->email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
