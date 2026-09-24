<?php

namespace App\Sender;

use Symfony\Component\Mime\Address;

final class AddressFormatter
{
    /** "Name <email>" (quoted only when the name needs it), accepted back by Address::create(). */
    public static function format(Address $address): string
    {
        $name = $address->getName();
        if ('' === $name) {
            return $address->getAddress();
        }

        return preg_match('/[",;:<>()\[\]\\\\@]/', $name)
            ? $address->toString()
            : \sprintf('%s <%s>', $name, $address->getAddress());
    }
}
