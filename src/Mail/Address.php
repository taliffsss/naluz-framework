<?php

declare(strict_types=1);

namespace Naluz\Mail;

/** A validated mailbox. Control characters are rejected outright, which makes header injection impossible. */
final class Address implements \Stringable
{
    public readonly string $email;
    public readonly ?string $name;

    public function __construct(string $email, ?string $name = null)
    {
        if (preg_match('/[\x00-\x1F\x7F<>,;"\\\\]/', $email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new MailException('Invalid e-mail address.');
        }
        if ($name !== null && preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $name)) {
            throw new MailException('Invalid display name.');
        }
        $this->email = $email;
        $this->name = $name === '' ? null : $name;
    }

    public static function from(self|string $address): self
    {
        return $address instanceof self ? $address : new self($address);
    }

    /** RFC 5322 header form: `"Name" <a@b.c>`, with RFC 2047 encoding for non-ASCII names. */
    public function __toString(): string
    {
        if ($this->name === null) {
            return $this->email;
        }
        $name = preg_match('/[^\x20-\x7E]/', $this->name)
            ? '=?UTF-8?B?' . base64_encode($this->name) . '?='
            : '"' . addcslashes($this->name, '"\\') . '"';
        return "{$name} <{$this->email}>";
    }
}
