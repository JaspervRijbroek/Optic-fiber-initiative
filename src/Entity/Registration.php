<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RegistrationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RegistrationRepository::class)]
#[ORM\Table(name: 'registrations')]
#[ORM\UniqueConstraint(name: 'idx_registrations_cadastral_reference', columns: ['cadastral_reference'])]
#[ORM\UniqueConstraint(name: 'idx_registrations_token', columns: ['unsubscribe_token'])]
class Registration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $nombre;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(name: 'cadastral_reference', type: 'string', length: 255, unique: true)]
    private string $cadastralReference;

    #[ORM\Column(name: 'unsubscribe_token', type: 'string', length: 255, unique: true)]
    private string $unsubscribeToken;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'coordinate_x', type: 'float', nullable: true)]
    private ?float $coordinateX = null;

    #[ORM\Column(name: 'coordinate_y', type: 'float', nullable: true)]
    private ?float $coordinateY = null;

    public function __construct(
        string $nombre,
        string $email,
        string $cadastralReference,
        string $unsubscribeToken,
        ?float $coordinateX = null,
        ?float $coordinateY = null,
    )
    {
        $this->nombre = $nombre;
        $this->email = $email;
        $this->cadastralReference = $cadastralReference;
        $this->unsubscribeToken = $unsubscribeToken;
        $this->coordinateX = $coordinateX;
        $this->coordinateY = $coordinateY;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCadastralReference(): string
    {
        return $this->cadastralReference;
    }

    public function getUnsubscribeToken(): string
    {
        return $this->unsubscribeToken;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCoordinateX(): ?float
    {
        return $this->coordinateX;
    }

    public function getCoordinateY(): ?float
    {
        return $this->coordinateY;
    }
}
