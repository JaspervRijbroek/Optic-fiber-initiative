<?php

declare(strict_types=1);

namespace App\Message;

final class SendConfirmationEmail
{
    public function __construct(
        public readonly string $nombre,
        public readonly string $email,
        public readonly string $unsubscribeToken,
        public readonly string $siteUrl,
    ) {}
}
