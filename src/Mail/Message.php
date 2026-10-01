<?php

declare(strict_types=1);

namespace Naluz\Mail;

final class Message
{
    private ?Address $from = null;
    /** @var list<Address> */
    private array $to = [];
    /** @var list<Address> */
    private array $cc = [];
    /** @var list<Address> */
    private array $bcc = [];
    private ?Address $replyTo = null;
    private string $subject = '';
    private ?string $text = null;
    private ?string $html = null;
    /** @var list<array{name:string,mime:string,data:string}> */
    private array $attachments = [];
    /** @var array<string,string> */
    private array $headers = [];

    public function from(Address|string $email, ?string $name = null): self
    {
        $this->from = $email instanceof Address ? $email : new Address($email, $name);
        return $this;
    }

    public function to(Address|string $email, ?string $name = null): self
    {
        $this->to[] = $email instanceof Address ? $email : new Address($email, $name);
        return $this;
    }

    public function cc(Address|string $email, ?string $name = null): self
    {
        $this->cc[] = $email instanceof Address ? $email : new Address($email, $name);
        return $this;
    }

    public function bcc(Address|string $email, ?string $name = null): self
    {
        $this->bcc[] = $email instanceof Address ? $email : new Address($email, $name);
        return $this;
    }

    public function replyTo(Address|string $email, ?string $name = null): self
    {
        $this->replyTo = $email instanceof Address ? $email : new Address($email, $name);
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = self::clean($subject, 'subject');
        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        if (!preg_match('/^[A-Za-z0-9-]+$/', $name)) {
            throw new MailException('Invalid header name.');
        }
        $this->headers[$name] = self::clean($value, $name);
        return $this;
    }

    public function attachData(string $data, string $name, string $mime = 'application/octet-stream'): self
    {
        $this->attachments[] = ['name' => self::clean($name, 'attachment name'), 'mime' => self::clean($mime, 'mime'), 'data' => $data];
        return $this;
    }

    public function attach(string $path, ?string $name = null, string $mime = 'application/octet-stream'): self
    {
        $data = is_file($path) ? file_get_contents($path) : false;
        if ($data === false) {
            throw new MailException('Attachment is not readable.');
        }
        return $this->attachData($data, $name ?? basename($path), $mime);
    }

    private static function clean(string $value, string $what): string
    {
        if (preg_match('/[\r\n\x00]/', $value)) {
            throw new MailException("Illegal line break in {$what}.");
        }
        return $value;
    }

    public function getFrom(): ?Address
    {
        return $this->from;
    }

    /** @return list<Address> */
    public function getTo(): array
    {
        return $this->to;
    }

    /** @return list<Address> every envelope recipient (to + cc + bcc) */
    public function recipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    /** @return list<array{name:string,mime:string,data:string}> */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /** @return array<string,string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function toArray(): array
    {
        $a = fn (?Address $x) => $x === null ? null : ['email' => $x->email, 'name' => $x->name];
        return [
            'from' => $a($this->from),
            'to' => array_map($a, $this->to),
            'cc' => array_map($a, $this->cc),
            'bcc' => array_map($a, $this->bcc),
            'reply_to' => $a($this->replyTo),
            'subject' => $this->subject,
            'text' => $this->text,
            'html' => $this->html,
            'headers' => $this->headers,
            'attachments' => array_map(fn ($x) => ['name' => $x['name'], 'mime' => $x['mime'], 'data' => base64_encode($x['data'])], $this->attachments),
        ];
    }

    public static function fromArray(array $d): self
    {
        $m = new self();
        $addr = fn (?array $x) => $x === null ? null : new Address((string) $x['email'], $x['name'] ?? null);
        if ($d['from'] ?? null) {
            $m->from = $addr($d['from']);
        }
        foreach (['to', 'cc', 'bcc'] as $k) {
            foreach ($d[$k] ?? [] as $x) {
                $m->{$k}[] = $addr($x);
            }
        }
        $m->replyTo = $addr($d['reply_to'] ?? null);
        $m->subject((string) ($d['subject'] ?? ''));
        $m->text = $d['text'] ?? null;
        $m->html = $d['html'] ?? null;
        foreach ($d['headers'] ?? [] as $k => $v) {
            $m->header((string) $k, (string) $v);
        }
        foreach ($d['attachments'] ?? [] as $x) {
            $m->attachData((string) base64_decode((string) $x['data'], true), (string) $x['name'], (string) $x['mime']);
        }
        return $m;
    }

    /** Full RFC 5322 / MIME message (the DATA payload, without dot-stuffing). */
    public function toMime(?string $messageId = null): string
    {
        $from = $this->from ?? throw new MailException('A message needs a From address.');
        if ($this->to === [] && $this->cc === [] && $this->bcc === []) {
            throw new MailException('A message needs at least one recipient.');
        }
        $host = substr(strrchr($from->email, '@') ?: '@localhost', 1);
        $h = [
            'Date' => date(DATE_RFC2822),
            'From' => (string) $from,
            'To' => implode(', ', array_map('strval', $this->to)),
            'Subject' => preg_match('/[^\x20-\x7E]/', $this->subject) ? '=?UTF-8?B?' . base64_encode($this->subject) . '?=' : $this->subject,
            'Message-ID' => '<' . ($messageId ?? bin2hex(random_bytes(12))) . '@' . $host . '>',
            'MIME-Version' => '1.0',
        ];
        if ($this->cc !== []) {
            $h['Cc'] = implode(', ', array_map('strval', $this->cc));
        }
        if ($this->replyTo !== null) {
            $h['Reply-To'] = (string) $this->replyTo;
        }
        $h = array_filter($h, fn ($v) => $v !== '') + $this->headers; // Bcc is never written to headers

        $text = $this->text ?? ($this->html !== null ? trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/h\d)[^>]*>#i', "\n", $this->html) ?? $this->html), ENT_QUOTES, 'UTF-8')) : '');
        $body = $this->html !== null
            ? $this->part('multipart/alternative', [$this->leaf('text/plain', $text), $this->leaf('text/html', $this->html)])
            : $this->leaf('text/plain', $text);
        if ($this->attachments !== []) {
            $parts = [$body];
            foreach ($this->attachments as $a) {
                $name = preg_match('/[^\x20-\x7E]/', $a['name']) ? '=?UTF-8?B?' . base64_encode($a['name']) . '?=' : addcslashes($a['name'], '"\\');
                $parts[] = [
                    'headers' => ["Content-Type: {$a['mime']}; name=\"{$name}\"", 'Content-Transfer-Encoding: base64', "Content-Disposition: attachment; filename=\"{$name}\""],
                    'body' => chunk_split(base64_encode($a['data']), 76, "\r\n"),
                ];
            }
            $body = $this->part('multipart/mixed', $parts);
        }

        $out = '';
        foreach ($h as $k => $v) {
            $out .= "{$k}: {$v}\r\n";
        }
        foreach ($body['headers'] as $line) {
            $out .= $line . "\r\n";
        }
        return $out . "\r\n" . $body['body'];
    }

    /** @return array{headers:list<string>,body:string} */
    private function leaf(string $type, string $content): array
    {
        return [
            'headers' => ["Content-Type: {$type}; charset=UTF-8", 'Content-Transfer-Encoding: quoted-printable'],
            // normalise to CRLF *before* encoding, otherwise bare LFs are encoded as "=0A" and lines are lost
            'body' => quoted_printable_encode(preg_replace('/\r\n|\r|\n/', "\r\n", $content) ?? $content) . "\r\n",
        ];
    }

    /** @param list<array{headers:list<string>,body:string}> $parts @return array{headers:list<string>,body:string} */
    private function part(string $type, array $parts): array
    {
        $boundary = '=_naluz_' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($parts as $p) {
            $body .= "--{$boundary}\r\n" . implode("\r\n", $p['headers']) . "\r\n\r\n" . $p['body'] . "\r\n";
        }
        return ['headers' => ["Content-Type: {$type}; boundary=\"{$boundary}\""], 'body' => $body . "--{$boundary}--\r\n"];
    }
}
