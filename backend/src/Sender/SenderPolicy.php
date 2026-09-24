<?php

namespace App\Sender;

use App\Entity\Application;
use App\Entity\SenderAddress;
use App\Entity\User;
use App\Repository\SenderAddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Mime\Address;

/**
 * Decides which "From" addresses a user may send from:
 * - the sender addresses of the settings (one is the default, seeded from MAILER_DEFAULT_FROM at install);
 * - the user's own address, unless disabled in the settings;
 * - when sending through an application, any address matching that application's allowed senders.
 */
final class SenderPolicy
{
    public function __construct(
        private readonly SenderAddressRepository $senders,
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
        #[Autowire(env: 'MAILER_DEFAULT_FROM')] private readonly string $installDefault,
    ) {
    }

    /**
     * Addresses offered in the composer, the default first.
     *
     * @return list<array{from: string, email: string, name: string|null, default: bool, source: 'settings'|'personal'}>
     */
    public function options(User $user): array
    {
        $options = array_map(static fn (SenderAddress $sender) => [
            'from' => AddressFormatter::format($sender->toAddress()),
            'email' => $sender->getEmail(),
            'name' => $sender->getName(),
            'default' => $sender->isDefault(),
            'source' => 'settings',
        ], $this->senders());

        $hasSettingsDefault = [] !== array_filter($options, static fn (array $o) => $o['default']);
        if ($this->settings->isPersonalFromAllowed() && !\in_array($user->getEmail(), array_column($options, 'email'), true)) {
            $options[] = [
                'from' => AddressFormatter::format(self::personal($user)),
                'email' => $user->getEmail(),
                'name' => $user->getDisplayName(),
                'default' => !$hasSettingsDefault,
                'source' => 'personal',
            ];
        }

        usort($options, static fn (array $a, array $b) => $b['default'] <=> $a['default']);

        return $options;
    }

    /** Resolves the requested "From" (or the default when empty), or refuses it. */
    public function resolve(?string $requested, User $user, ?Application $application): Address
    {
        $requested = trim((string) $requested);
        if ('' === $requested) {
            return $this->defaultFor($user);
        }

        try {
            $address = Address::create($requested);
        } catch (\Throwable) {
            throw new UnprocessableEntityHttpException(\sprintf('"%s" is not a valid sender address.', $requested));
        }

        $email = mb_strtolower($address->getAddress());
        $name = $address->getName();

        foreach ($this->senders() as $sender) {
            if ($sender->getEmail() === $email) {
                return new Address($email, '' !== $name ? $name : ($sender->getName() ?? ''));
            }
        }

        if ($email === $user->getEmail() && $this->settings->isPersonalFromAllowed()) {
            return new Address($email, '' !== $name ? $name : $user->getDisplayName());
        }

        if (null !== $application && $application->allowsSender($email)) {
            return new Address($email, $name);
        }

        throw new UnprocessableEntityHttpException(\sprintf('Sending from "%s" is not allowed.', $email));
    }

    private function defaultFor(User $user): Address
    {
        foreach ($this->senders() as $sender) {
            if ($sender->isDefault()) {
                return $sender->toAddress();
            }
        }

        if ($this->settings->isPersonalFromAllowed()) {
            return self::personal($user);
        }

        throw new UnprocessableEntityHttpException('No sender address is configured: add one in the settings.');
    }

    /** @return list<SenderAddress> */
    private function senders(): array
    {
        $this->initialize();

        return $this->senders->findBy([], ['isDefault' => 'DESC', 'email' => 'ASC']);
    }

    /** On first use, the address from MAILER_DEFAULT_FROM becomes the default sender (once: admins may then remove it). */
    private function initialize(): void
    {
        if ($this->settings->get(Settings::SENDERS_INITIALIZED, false)) {
            return;
        }

        if ('' !== trim($this->installDefault) && 0 === $this->senders->count([])) {
            $this->em->persist(SenderAddress::fromAddress(Address::create(trim($this->installDefault)), true));
        }
        $this->settings->set(Settings::SENDERS_INITIALIZED, true);
        $this->em->flush();
    }

    private static function personal(User $user): Address
    {
        return new Address($user->getEmail(), $user->getDisplayName());
    }
}
