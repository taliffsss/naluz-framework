<?php

declare(strict_types=1);

namespace Naluz\Mail;

/**
 * SMTP client: implicit TLS (`ssl`, port 465), STARTTLS (`tls`, port 587) or plain (`none`, local relays only),
 * AUTH PLAIN / LOGIN, dot-stuffing, multi-line replies. Server certificates are verified.
 */
final class SmtpTransport implements Transport
{
    /** @var resource|null */
    private $stream = null;

    /** @param (\Closure(string,int,float):resource)|null $connector inject a stream (used by tests) */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly string $encryption = 'tls',   // tls | ssl | none
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly float $timeout = 10.0,
        private readonly ?\Closure $connector = null,
        private readonly string $helo = 'localhost',
    ) {
        if (!preg_match('/^[A-Za-z0-9.\-\[\]:]{1,255}$/', $helo)) {
            throw new \InvalidArgumentException('Invalid SMTP HELO name.'); // would otherwise allow SMTP command injection
        }
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            throw new \InvalidArgumentException("Unknown SMTP encryption [{$encryption}].");
        }
    }

    public function send(Message $message): void
    {
        $from = $message->getFrom() ?? throw new MailException('A message needs a From address.');
        $mime = $message->toMime();
        try {
            $this->open();
            $this->expect(220);
            $caps = $this->ehlo();
            if ($this->encryption === 'tls') {
                $this->cmd('STARTTLS', 220);
                if (!@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new MailException('STARTTLS negotiation failed.');
                }
                $caps = $this->ehlo();
            }
            if ($this->username !== null) {
                $this->authenticate($caps);
            }
            $this->cmd("MAIL FROM:<{$from->email}>", 250);
            foreach ($message->recipients() as $r) {
                $this->cmd("RCPT TO:<{$r->email}>", [250, 251]);
            }
            $this->cmd('DATA', 354);
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], "\n", $mime)); // dot-stuffing
            $this->write(str_replace("\n", "\r\n", (string) $data) . "\r\n.\r\n");
            $this->expect(250);
            $this->cmd('QUIT', 221, false);
        } finally {
            $this->close();
        }
    }

    private function open(): void
    {
        if ($this->connector !== null) {
            $this->stream = ($this->connector)($this->host, $this->port, $this->timeout);
            return;
        }
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host]]);
        $s = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errno, $err, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($s === false) {
            throw new MailException("Cannot connect to SMTP server {$this->host}:{$this->port}: {$err}");
        }
        stream_set_timeout($s, (int) $this->timeout);
        $this->stream = $s;
    }

    private function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    /** @return list<string> advertised capabilities */
    private function ehlo(): array
    {
        $lines = $this->cmd("EHLO {$this->helo}", 250);
        return array_map('strtoupper', array_slice($lines, 1));
    }

    private function authenticate(array $caps): void
    {
        $advertised = implode(' ', $caps);
        if (str_contains($advertised, 'AUTH') && str_contains($advertised, 'PLAIN')) {
            $this->cmd('AUTH PLAIN ' . base64_encode("\0{$this->username}\0{$this->password}"), 235);
        } elseif (str_contains($advertised, 'LOGIN')) {
            $this->cmd('AUTH LOGIN', 334);
            $this->cmd(base64_encode((string) $this->username), 334);
            $this->cmd(base64_encode((string) $this->password), 235);
        } else {
            throw new MailException('SMTP server offers no supported AUTH mechanism.');
        }
    }

    /** @param int|list<int> $expect @return list<string> reply lines */
    private function cmd(string $command, int|array $expect, bool $read = true): array
    {
        $this->write($command . "\r\n");
        return $read ? $this->expect($expect) : [];
    }

    private function write(string $data): void
    {
        if (@fwrite($this->stream, $data) === false) {
            throw new MailException('SMTP connection lost.');
        }
    }

    /** @param int|list<int> $codes @return list<string> */
    private function expect(int|array $codes): array
    {
        $lines = [];
        do {
            $line = fgets($this->stream, 1024);
            if ($line === false) {
                throw new MailException('SMTP connection lost.');
            }
            $line = rtrim($line);
            $lines[] = $line;
        } while (strlen($line) > 3 && $line[3] === '-');

        $code = (int) substr($line, 0, 3);
        if (!in_array($code, (array) $codes, true)) {
            throw new MailException("SMTP server replied {$line}");
        }
        return $lines;
    }
}
