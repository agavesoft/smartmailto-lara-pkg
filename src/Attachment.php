<?php

namespace Agavesoft\Smartmailto;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * F-008 (B3): adjunto de `Smartmailto::send()`.
 *
 * El contenido se lee al construirlo (no al entregar): con la cola el job lleva el base64 y no depende
 * de que el archivo siga en disco. El `content_type` sale del parametro o de la extension con la misma
 * tabla que el servidor (nunca de finfo ni del navegador: Smartmailto rechaza un tipo que no corresponde
 * a la extension).
 */
final class Attachment
{
    /** Extension => tipos aceptados por Smartmailto (el primero es el default). Espejo del servidor. */
    public const TYPES = [
        'pdf' => ['application/pdf'],
        'xml' => ['application/xml', 'text/xml'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'csv' => ['text/csv', 'text/plain'],
        'txt' => ['text/plain'],
    ];

    /**
     * Inline (`contents`), por URL que da tu app (`url`) o por un archivo de un disco de tu app (`disk` +
     * `path`, que se convierte en URL firmada temporal en cada intento).
     */
    private function __construct(
        public readonly string $filename,
        public readonly ?string $contents,
        public readonly string $contentType,
        public readonly ?string $url = null,
        public readonly ?string $sha256 = null,
        public readonly ?string $disk = null,
        public readonly ?string $path = null,
        private readonly ?int $bytes = null,
    ) {}

    /**
     * F-010 (J12): adjunto grande por una URL https que Smartmailto descarga al aceptar el envio. `sha256`
     * (64 hex) del archivo es obligatorio: Smartmailto lo verifica. La URL debe seguir viva mientras el
     * envio se reintenta; con el outbox usa fromDisk() (URL nueva en cada intento).
     */
    public static function fromUrl(string $url, string $filename, string $sha256, ?string $contentType = null): self
    {
        if (! str_starts_with(strtolower($url), 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Smartmailto attachment url must be https.');
        }
        if (! preg_match('/^[a-f0-9]{64}$/i', $sha256)) {
            throw new InvalidArgumentException('Smartmailto attachment sha256 must be 64 hex characters.');
        }
        $filename = self::validFilename($filename);

        return new self($filename, null, self::resolveType($filename, $contentType), url: $url, sha256: strtolower($sha256));
    }

    /**
     * F-010 (J12): adjunto grande desde un disco de tu app (S3 o uno con `temporaryUrl`). El SDK calcula
     * el sha256 ahora y genera una URL firmada nueva en cada intento (`attachments.signed_url_ttl`, 24 h).
     * El archivo debe seguir en el disco hasta que Smartmailto acuse el envio.
     */
    public static function fromDisk(string $disk, string $path, ?string $filename = null, ?string $contentType = null): self
    {
        $storage = app('filesystem')->disk($disk);
        // Al llamar, no en el worker: un disco sin URLs temporales fallaria cada intento hasta vencer.
        if (method_exists($storage, 'providesTemporaryUrls') && ! $storage->providesTemporaryUrls()) {
            throw new InvalidArgumentException("Smartmailto attachment disk {$disk} cannot create temporary URLs (use S3 or a disk with temporaryUrl).");
        }
        if (! $storage->exists($path)) {
            throw new InvalidArgumentException("Smartmailto attachment not found on disk {$disk}: {$path}");
        }

        $size = (int) $storage->size($path);
        $max = (int) config('smartmailto.attachments.url_max_bytes', 15 * 1024 * 1024);
        if ($size > $max) {
            throw new InvalidArgumentException("Smartmailto attachment {$path} has {$size} bytes, over the {$max} bytes limit for url attachments.");
        }

        $stream = $storage->readStream($path);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        fclose($stream);

        $filename = self::validFilename($filename ?? basename($path));

        return new self($filename, null, self::resolveType($filename, $contentType), sha256: hash_final($hash), disk: $disk, path: $path, bytes: $size);
    }

    /** @internal el worker del outbox reconstruye el adjunto guardado (toOutbox) para cada intento. */
    public static function fromOutbox(array $stored): ?self
    {
        if (! isset($stored['disk'], $stored['path'])) {
            return null;
        }

        return new self($stored['filename'], null, $stored['content_type'], sha256: $stored['sha256'], disk: $stored['disk'], path: $stored['path']);
    }

    public function isInline(): bool
    {
        return $this->contents !== null;
    }

    /** Desde un archivo en disco. `$filename` es el nombre que vera el destinatario (default: el del archivo). */
    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Smartmailto attachment not readable: {$path}");
        }
        self::assertSize((int) filesize($path), $path);

        return self::fromData((string) file_get_contents($path), $filename ?? basename($path), $contentType);
    }

    /** Desde el contenido crudo (bytes, no base64), por ejemplo un PDF generado en memoria. */
    public static function fromData(string $contents, string $filename, ?string $contentType = null): self
    {
        if ($contents === '') {
            throw new InvalidArgumentException("Smartmailto attachment {$filename} is empty.");
        }

        $filename = self::validFilename($filename);
        $type = self::resolveType($filename, $contentType);
        if (! self::matchesType($contents, strtolower(pathinfo($filename, PATHINFO_EXTENSION)))) {
            throw new InvalidArgumentException("Smartmailto attachment {$filename} does not look like a .".pathinfo($filename, PATHINFO_EXTENSION).' file.');
        }

        return new self($filename, $contents, $type);
    }

    /** Desde un archivo subido. El tipo sale de la extension del nombre, no del que mando el navegador. */
    public static function fromUpload(UploadedFile $file, ?string $filename = null, ?string $contentType = null): self
    {
        self::assertSize((int) $file->getSize(), $file->getClientOriginalName());

        return self::fromData((string) $file->get(), $filename ?? $file->getClientOriginalName(), $contentType);
    }

    /** Un Attachment, un UploadedFile o la ruta de un archivo. */
    public static function from(self|UploadedFile|string $attachment): self
    {
        return match (true) {
            $attachment instanceof self => $attachment,
            $attachment instanceof UploadedFile => self::fromUpload($attachment),
            default => self::fromPath($attachment),
        };
    }

    /** Bytes del adjunto (0 si es una URL externa: el tamano lo revisa Smartmailto al descargar). */
    public function size(): int
    {
        return $this->contents !== null ? strlen($this->contents) : (int) $this->bytes;
    }

    /**
     * Cuerpo del contrato: inline `{filename, content, content_type}` o por URL
     * `{filename, content_type, url, sha256}` (fromDisk firma la URL en este momento).
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        if ($this->contents !== null) {
            return ['filename' => $this->filename, 'content' => base64_encode($this->contents), 'content_type' => $this->contentType];
        }

        return ['filename' => $this->filename, 'content_type' => $this->contentType, 'url' => $this->url ?? $this->signedUrl(), 'sha256' => (string) $this->sha256];
    }

    /**
     * @internal lo que guarda el outbox: un fromDisk se guarda como referencia (nunca una URL firmada,
     * que caducaria antes del ultimo reintento) y se firma en cada intento.
     *
     * @return array<string, string>
     */
    public function toOutbox(): array
    {
        if ($this->disk !== null) {
            return ['filename' => $this->filename, 'content_type' => $this->contentType, 'disk' => $this->disk, 'path' => (string) $this->path, 'sha256' => (string) $this->sha256];
        }

        return $this->toArray();
    }

    private function signedUrl(): string
    {
        $ttl = (int) config('smartmailto.attachments.signed_url_ttl', 1440);

        return app('filesystem')->disk((string) $this->disk)->temporaryUrl((string) $this->path, now()->addMinutes($ttl));
    }

    /** Un archivo que solo ya rebasa el limite total no se carga en memoria. */
    private static function assertSize(int $size, string $name): void
    {
        $max = (int) config('smartmailto.attachments.max_bytes', 7 * 1024 * 1024);
        if ($size > $max) {
            throw new InvalidArgumentException("Smartmailto attachment {$name} has {$size} bytes, over the {$max} bytes limit.");
        }
    }

    /** Misma revision de firma que el servidor: un .pdf que no es PDF se rechaza aqui y no en el job. */
    private static function matchesType(string $contents, string $extension): bool
    {
        return match ($extension) {
            // La especificacion de PDF permite basura antes de la cabecera (hasta 1 KB).
            'pdf' => str_contains(substr($contents, 0, 1024), '%PDF-'),
            'zip' => str_starts_with($contents, "PK\x03\x04") || str_starts_with($contents, "PK\x05\x06"),
            'png' => str_starts_with($contents, "\x89PNG\r\n\x1a\n"),
            'jpg', 'jpeg' => str_starts_with($contents, "\xFF\xD8\xFF"),
            // xml, csv, txt: texto, sin bytes nulos.
            default => ! str_contains($contents, "\0"),
        };
    }

    private static function validFilename(string $filename): string
    {
        // Mismas reglas que el servidor: nombre simple, sin rutas ni caracteres de control.
        if ($filename === '' || mb_strlen($filename) > 150 || preg_match('/[\/\\\\]|\p{Cc}|^\.|\.\./u', $filename) || ! mb_check_encoding($filename, 'UTF-8')) {
            throw new InvalidArgumentException("Smartmailto attachment filename must be a plain file name (max 150): {$filename}");
        }

        return $filename;
    }

    private static function resolveType(string $filename, ?string $contentType): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $accepted = self::TYPES[$extension] ?? null;
        if ($accepted === null) {
            throw new InvalidArgumentException("Smartmailto attachment type .{$extension} not allowed (allowed: ".implode(', ', array_keys(self::TYPES)).').');
        }

        // Sin tipo, o el generico application/octet-stream (Laravel lo pone en attachData() sin mime): se
        // infiere por la extension permitida. Un tipo concreto que no corresponde se sigue rechazando.
        $type = strtolower(trim(explode(';', (string) $contentType)[0]));
        if ($type === '' || $type === 'application/octet-stream') {
            return $accepted[0];
        }
        if (! in_array($type, $accepted, true)) {
            throw new InvalidArgumentException("Smartmailto attachment content type {$type} does not match .{$extension} (expected ".implode(' or ', $accepted).').');
        }

        return $type;
    }
}
