<?php

namespace App\Sender;

use App\Entity\Application;
use App\Entity\Mailbox;
use App\Entity\SenderAddress;
use App\Entity\User;
use App\Repository\MailboxRepository;
use App\Repository\SenderAddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Mime\Address;

/**
 * Decides which "From" addresses a user may send from.
 *
 * In Rocket Mailer itself:
 * - the sender addresses of the settings (one is the default, seeded from MAILER_DEFAULT_FROM at install);
 * - the user's own address, unless disabled in the settings;
 * - the sending mailboxes available to all users.
 *
 * Through an application (API, embedded composer), never the platform's addresses, only what the application configured:
 * - its own sender (the default);
 * - any address matching its allowed senders (imposed with "from");
 * - its sending mailboxes.
 */
final class SenderPolicy
{
    public function __construct(
        private readonly SenderAddressRepository $senders,
        private readonly MailboxRepository $mailboxes,
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
        #[Autowire(env: 'MAILER_DEFAULT_FROM')] private readonly string $installDefault,
    ) {
    }

    /**
     * Addresses offered in the composer, the default first.
     *
     * @return list<array{from: string, email: string, name: string|null, default: bool, source: 'settings'|'personal'|'mailbox', mailbox?: string, mailboxName?: string}>
     */
    public function options(User $user, ?Application $application = null): array
    {
        if (null !== $application) {
            return $this->applicationOptions($application);
        }

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

        // Sending mailboxes shared with all users, after the addresses.
        foreach ($this->mailboxes->usableBy(null) as $mailbox) {
            $options[] = self::mailboxOption($mailbox, false);
        }

        return $options;
    }

    /** @return list<array<string, mixed>> The application's sender first, then its mailboxes; empty when nothing is configured. */
    private function applicationOptions(Application $application): array
    {
        $options = [];
        $sender = $application->getSenderAddress();
        if (null !== $sender) {
            $options[] = [
                'from' => AddressFormatter::format($sender),
                'email' => $sender->getAddress(),
                'name' => $application->getSenderName(),
                'default' => true,
                'source' => 'application',
            ];
        }
        foreach ($this->mailboxes->usableBy($application) as $mailbox) {
            $options[] = self::mailboxOption($mailbox, [] === $options);
        }

        return $options;
    }

    /** @return array<string, mixed> */
    private static function mailboxOption(Mailbox $mailbox, bool $default): array
    {
        return [
            'from' => AddressFormatter::format($mailbox->toAddress()),
            'email' => $mailbox->getEmail(),
            'name' => $mailbox->getDisplayName(),
            'default' => $default,
            'source' => 'mailbox',
            'mailbox' => '/api/mailboxes/'.$mailbox->getId()->toRfc4122(),
            'mailboxName' => $mailbox->getName(),
        ];
    }

    /** Resolves the requested "From" (or the default when empty), or refuses it. */
    public function resolve(?string $requested, User $user, ?Application $application): Address
    {
        $requested = trim((string) $requested);
        if ('' === $requested) {
            return null === $application ? $this->defaultFor($user) : $this->applicationDefault($application);
        }

        try {
            $address = Address::create($requested);
        } catch (\Throwable) {
            throw new UnprocessableEntityHttpException(\sprintf('"%s" is not a valid sender address.', $requested));
        }

        $email = mb_strtolower($address->getAddress());
        $name = $address->getName();

        if (null !== $application) {
            if ($email === $application->getSenderEmail()) {
                return new Address($email, '' !== $name ? $name : ($application->getSenderName() ?? ''));
            }
            if ($application->allowsSender($email)) {
                return new Address($email, $name);
            }

            throw new UnprocessableEntityHttpException(\sprintf('Sending from "%s" is not allowed for the application "%s": use its sender, one of its allowed senders or one of its mailboxes.', $email, $application->getName()));
        }

        foreach ($this->senders() as $sender) {
            if ($sender->getEmail() === $email) {
                return new Address($email, '' !== $name ? $name : ($sender->getName() ?? ''));
            }
        }

        if ($email === $user->getEmail() && $this->settings->isPersonalFromAllowed()) {
            return new Address($email, '' !== $name ? $name : $user->getDisplayName());
        }

        throw new UnprocessableEntityHttpException(\sprintf('Sending from "%s" is not allowed.', $email));
    }

    private function applicationDefault(Application $application): Address
    {
        return $application->getSenderAddress() ?? throw new UnprocessableEntityHttpException(\sprintf(
            'The application "%s" has no sender configured: an administrator must set it (Applications), or pass "from" or "mailbox".',
            $application->getName(),
        ));
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
