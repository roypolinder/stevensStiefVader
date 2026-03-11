<?php

namespace App;

use App\Logging\VerboseLogger;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Laracord\Logging\Logger;
use Laracord\Laracord;
use Throwable;

class Bot extends Laracord
{
    public function getLogger(): Logger
    {
        return $this->logger ??= VerboseLogger::make($this->console);
    }

    public function unregisterApplicationCommand(string $id, ?string $guildId = null): void
    {
        cache()->forget('laracord.application-commands');

        $onError = function ($error) use ($id, $guildId) {
            if (! $error instanceof Throwable) {
                return;
            }

            Log::error('Discord command delete failed', [
                'command_id' => $id,
                'guild_id' => $guildId,
                'message' => $error->getMessage(),
                'trace' => $error->getTraceAsString(),
            ]);

            $message = $error->getMessage();

            if (str_contains($message, 'Unknown application command') || str_contains($message, '10063')) {
                $scope = $guildId ? "guild {$guildId}" : 'global';
                $this->console()->warn("Skip delete for missing {$scope} command {$id}.");

                return;
            }

            $this->console()->error($message);
        };

        if ($guildId) {
            $guild = $this->discord()->guilds->get('id', $guildId);

            if (! $guild) {
                $this->console()->warn("The command with ID <fg=yellow>{$id}</> failed to unregister because the guild <fg=yellow>{$guildId}</> could not be found.");

                return;
            }

            $guild->commands->delete($id)->then(null, $onError);

            return;
        }

        $this->discord()->application->commands->delete($id)->then(null, $onError);
    }

    /**
     * The HTTP routes.
     */
    public function routes(): void
    {
        Route::middleware('web')->group(function () {
            // Route::get('/', fn () => 'Hello world!');
        });

        Route::middleware('api')->group(function () {
            // Route::get('/commands', fn () => collect($this->registeredCommands)->map(fn ($command) => [
            //     'signature' => $command->getSignature(),
            //     'description' => $command->getDescription(),
            // ]));
        });
    }
}
