<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends mail through the Cloudflare Email Service REST API
 * (`POST /accounts/{account}/email/sending/send`), so no extra mail package
 * is needed.
 */
class CloudflareEmailTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $accountId,
        private readonly string $apiToken,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $response = Http::withToken($this->apiToken)
            ->acceptJson()
            ->post("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/email/sending/send", $this->payload($email, $message->getEnvelope()));

        if (! $response->successful() || $response->json('success') === false) {
            throw new TransportException('Cloudflare Email Service rejected the message: '.$response->body());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Email $email, Envelope $envelope): array
    {
        $from = $email->getFrom()[0] ?? $envelope->getSender();

        $payload = [
            'from' => $this->address($from),
            'to' => array_map(fn (Address $to): string => $to->getAddress(), $email->getTo()),
            'subject' => (string) $email->getSubject(),
        ];

        if ($email->getHtmlBody() !== null) {
            $payload['html'] = (string) $email->getHtmlBody();
        }

        if ($email->getTextBody() !== null) {
            $payload['text'] = (string) $email->getTextBody();
        }

        if ($email->getReplyTo() !== []) {
            $payload['reply_to'] = $email->getReplyTo()[0]->getAddress();
        }

        return $payload;
    }

    /**
     * @return array{address: string, name?: string}
     */
    private function address(Address $address): array
    {
        return array_filter([
            'address' => $address->getAddress(),
            'name' => $address->getName() ?: null,
        ]);
    }

    public function __toString(): string
    {
        return 'cloudflare';
    }
}
