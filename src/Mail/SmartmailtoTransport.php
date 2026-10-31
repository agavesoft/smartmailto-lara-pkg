<?php

namespace Agavesoft\Smartmailto\Mail;

use Agavesoft\Smartmailto\Attachment;
use Agavesoft\Smartmailto\Exceptions\SmartmailtoException;
use Agavesoft\Smartmailto\Identity;
use Agavesoft\Smartmailto\Smartmailto;
use Closure;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Fase 0: mailer `smartmailto` de Laravel (`MAIL_MAILER=smartmailto`). Cada correo de la app (Mailable,
 * Notification, Mail::raw) se manda a POST /api/send sin plantilla: asunto, html y text tal cual, el
 * primer To es el destinatario (externo: no se vuelve contacto), el resto de To, CC, BCC, Reply-To y From
 * viajan aparte y los adjuntos van en base64. La llave de idempotencia sale del Message-ID.
 */
final class SmartmailtoTransport extends AbstractTransport
{
    /**
     * @param  Closure(): Smartmailto  $client  se resuelve en cada envio (respeta Smartmailto::fake())
     * @param  array<string, mixed>  $config  la entrada `smartmailto` de config/mail.php
     */
    public function __construct(private readonly Closure $client, private readonly array $config = [])
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $to = array_map(fn (Address $a) => $a->getAddress(), $email->getTo());
        if ($to === []) {
            // Un correo solo con CC/BCC: el primero de ellos es el destinatario.
            $to = array_map(fn (Address $a) => $a->getAddress(), [...$email->getCc(), ...$email->getBcc()]);
        }
        if ($to === []) {
            throw new TransportException('Smartmailto mailer: the message has no recipients.');
        }
        $recipient = array_shift($to);
        $cc = array_values(array_diff(array_map(fn (Address $a) => $a->getAddress(), $email->getCc()), [$recipient]));
        $bcc = array_values(array_diff(array_map(fn (Address $a) => $a->getAddress(), $email->getBcc()), [$recipient]));

        $replyTo = $email->getReplyTo()[0] ?? null;
        $from = ($this->config['send_from'] ?? true) ? ($email->getFrom()[0] ?? null) : null;

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();

        $attachments = array_map(fn (DataPart $part) => Attachment::fromData(
            $part->getBody(),
            $part->getFilename() ?? 'adjunto',
            $part->getMediaType().'/'.$part->getMediaSubtype(),
        ), $email->getAttachments());

        $smartmailto = ($this->client)();
        $sendBefore = $smartmailto->outboxEnabled() ? now()->addMinutes((int) ($this->config['send_before_minutes'] ?? 60)) : null;

        try {
            $response = $smartmailto->sendRaw(
                identity: Identity::external($recipient),
                subject: (string) $email->getSubject(),
                html: is_string($html) ? $html : (is_resource($html) ? (string) stream_get_contents($html) : null),
                text: is_string($text) ? $text : (is_resource($text) ? (string) stream_get_contents($text) : null),
                idempotencyKey: self::idempotencyKey($message->getMessageId()),
                sendBefore: $sendBefore,
                attachments: $attachments,
                cc: $cc,
                bcc: $bcc,
                replyTo: $replyTo ? array_filter(['email' => $replyTo->getAddress(), 'name' => $replyTo->getName() ?: null]) : null,
                to: $to,
                from: $from ? array_filter(['email' => $from->getAddress(), 'name' => $from->getName() ?: null]) : null,
                messageHeaders: self::headersOf($email),
            );
        } catch (SmartmailtoException $e) {
            throw new TransportException('Smartmailto mailer: '.$e->getMessage(), 0, $e);
        }

        if (isset($response['id'])) {
            $message->setMessageId('smartmailto-'.$response['id']);
        }
    }

    /**
     * Encabezados del mensaje original para SmartmailtoMessageSent/Failed (p. ej. `X-Mi-Invitacion`), en
     * texto. Un nombre repetido se une con ", ". No viajan a Smartmailto.
     *
     * @return array<string, string>
     */
    public static function headersOf(\Symfony\Component\Mime\Email $email): array
    {
        $out = [];
        foreach ($email->getHeaders()->all() as $header) {
            $name = $header->getName();
            $value = $header->getBodyAsString();
            $out[$name] = isset($out[$name]) ? $out[$name].', '.$value : $value;
        }

        return $out;
    }

    /** `mail:` + Message-ID (sin <>); uno muy largo se resume (el servidor acepta hasta 191). */
    public static function idempotencyKey(string $messageId): string
    {
        $key = 'mail:'.trim($messageId, '<> ');

        return strlen($key) <= 191 ? $key : 'mail:sha256:'.hash('sha256', $messageId);
    }

    public function __toString(): string
    {
        return 'smartmailto://api';
    }
}
