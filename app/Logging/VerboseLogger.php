<?php

namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laracord\Logging\Logger as BaseLogger;

class VerboseLogger extends BaseLogger
{
    public function handle(string|\Stringable $message, array $context = [], string $type = 'info'): void
    {
        $type = match ($type) {
            'error' => 'error',
            'warn', 'warning' => 'warning',
            default => 'info',
        };

        if (Str::of($message)->lower()->contains($this->except)) {
            return;
        }

        $plainMessage = ucfirst((string) $message);

        if (! empty($context)) {
            $plainMessage .= ' | '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (method_exists($this->console, 'log')) {
            $consoleType = $type === 'warning' ? 'warn' : $type;
            $this->console->log($plainMessage, $consoleType);
        } else {
            $consoleType = $type === 'warning' ? 'warn' : $type;
            $this->console->outputComponents()->{$consoleType}($plainMessage);
        }

        Log::{$type}($message, $context);
    }
}
