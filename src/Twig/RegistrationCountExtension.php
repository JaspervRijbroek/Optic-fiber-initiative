<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\RegistrationRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class RegistrationCountExtension extends AbstractExtension
{
    public function __construct(
        private readonly RegistrationRepository $repository,
        private readonly CacheInterface $cache,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('registration_count', $this->getRegistrationCount(...)),
        ];
    }

    public function getRegistrationCount(): int
    {
        return $this->cache->get('registration_count', function (ItemInterface $item): int {
            $item->expiresAfter(300);

            return $this->repository->countAll();
        });
    }
}
