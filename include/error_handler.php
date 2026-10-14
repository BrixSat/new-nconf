<?php
declare(strict_types=1);

##
##  Default error handling
##
##  Last-resort handler for uncaught exceptions and fatal errors: logs the
##  failure server-side keyed on a request id and answers with an
##  application/problem+json body that includes the underlying cause.
##  Non-fatal notices/warnings are left to PHP's normal handling.
##
##  Loaded first by main.php so failures while loading config/DB are covered.
##

final class RequestId {
    // Client ids are echoed in a header and written to logs, so only a
    // conservative charset is accepted to prevent header/log injection.
    private const ALLOWED_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    private function __construct(public readonly string $value) {}

    public static function resolve(): self {
        $header = $_SERVER['HTTP_X_CORRELATION_ID'] ?? '';

        if (is_string($header) && preg_match(self::ALLOWED_PATTERN, $header) === 1) {
            return new self($header);
        }

        return new self(self::uuid4());
    }

    // Generated from stdlib randomness to avoid adding a Composer dependency
    // to a project that has none.
    private static function uuid4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

class HttpException extends \RuntimeException {
    public function __construct(
        public readonly string $title,
        public readonly string $detail,
        public readonly int $httpStatus,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detail, $httpStatus, $previous);
    }

    public static function badRequest(string $detail): self {
        return new self('Bad Request', $detail, 400);
    }

    public static function notFound(string $detail = 'The requested resource was not found.'): self {
        return new self('Route Not Found', $detail, 404);
    }

    public static function unprocessableEntity(string $detail): self {
        return new self('Unprocessable Entity', $detail, 422);
    }

    public static function internalError(string $detail = 'An unexpected error occurred.'): self {
        return new self('Internal Server Error', $detail, 500);
    }
}

final class ErrorResponse {
    public static function send(RequestId $requestId, \Throwable $e): never {
        $status = $e instanceof HttpException ? $e->httpStatus : 500;
        $title  = $e instanceof HttpException ? $e->title : 'Internal Server Error';
        $detail = $e instanceof HttpException ? $e->detail : 'An unexpected error occurred.';

        // Logged for 4xx too, so every client-visible request_uid has a server log entry.
        error_log(sprintf(
            '[%s] HTTP %d %s: %s in %s:%d',
            $requestId->value,
            $status,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));

        // A page may have already streamed HTML; the body is still emitted so
        // the user sees the request_uid, but status/type can no longer change.
        if (!headers_sent()) {
            header('Content-Type: application/problem+json; charset=utf-8');
            header('X-Request-Id: ' . $requestId->value);
            http_response_code($status);
        }

        // The cause is exposed deliberately for troubleshooting; JSON_HEX_* keeps
        // a message containing markup from executing when appended to an HTML page.
        echo json_encode(
            [
                'title'       => $title,
                'detail'      => $detail,
                'request_uid' => $requestId->value,
                'status'      => $status,
                'cause'       => [
                    'class'   => $e::class,
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ],
            ],
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_QUOT
                | JSON_HEX_APOS
                | JSON_THROW_ON_ERROR,
        );
        exit;
    }
}

$requestId = RequestId::resolve();
if (!headers_sent()) {
    header('X-Request-Id: ' . $requestId->value);
}

set_exception_handler(
    static function (\Throwable $e) use ($requestId): void {
        ErrorResponse::send($requestId, $e);
    },
);

// Fatal errors bypass the exception handler, so they are caught at shutdown.
register_shutdown_function(
    static function () use ($requestId): void {
        $error = error_get_last();
        $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

        if ($error === null || ($error['type'] & $fatalTypes) === 0) {
            return;
        }

        ErrorResponse::send(
            $requestId,
            new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line'],
            ),
        );
    },
);
