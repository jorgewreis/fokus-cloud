<?php

namespace App\Logging;

use App\Services\AuditSanitizer;
use Monolog\LogRecord;
use Illuminate\Log\Logger;

class SensitiveLogTap
{
    public function __invoke(Logger $logger): void
    {
        $sanitizer = app(AuditSanitizer::class);
        $logger->pushProcessor(static function (LogRecord|array $record) use ($sanitizer): LogRecord|array {
            if ($record instanceof LogRecord) {
                return $record->with(
                    message: (string) $sanitizer->sanitizeLogValue($record->message),
                    context: $sanitizer->sanitizeLogValue($record->context),
                    extra: $sanitizer->sanitizeLogValue($record->extra),
                );
            }
            $record['message'] = $sanitizer->sanitizeLogValue($record['message'] ?? '');
            $record['context'] = $sanitizer->sanitizeLogValue($record['context'] ?? []);
            $record['extra'] = $sanitizer->sanitizeLogValue($record['extra'] ?? []);
            return $record;
        });
    }
}
