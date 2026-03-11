<?php

namespace App\SlashCommands;

use App\Support\AiVoiceResponder;
use App\Commands\SlashCommand;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Interactions\Interaction;
use Discord\Voice\VoiceClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class AiVoiceCommand extends SlashCommand
{
    protected function handleAiResponse(Interaction $interaction, callable $resolver, string $title): void
    {
        $interaction->acknowledgeWithResponse()->then(function () use ($interaction, $resolver, $title) {
            try {
                $text = trim((string) $resolver());

                if ($text === '') {
                    throw new \RuntimeException('Leeg antwoord ontvangen.');
                }
            } catch (Throwable $e) {
                $this->console()->error('AI command failed: '.$e->getMessage());
                Log::error('AI voice command failed', [
                    'command' => $this->getName(),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $interaction->sendFollowUpMessage(
                    $this->message('Er ging iets mis bij het ophalen van een AI-antwoord. Probeer het opnieuw.')
                        ->title($title)
                        ->error()
                        ->build()
                );

                return;
            }

            $interaction->sendFollowUpMessage(
                $this->message($text)
                    ->title($title)
                    ->build()
            );

            $channel = $this->resolveUserVoiceChannel($interaction);

            if (! $channel) {
                $interaction->sendFollowUpMessage(
                    $this->message('Ik kon geen voice channel vinden voor jou. Daarom staat het antwoord alleen in de chat.')
                        ->title('Voice fallback')
                        ->warning()
                        ->build()
                );

                return;
            }

            $this->playVoice($interaction, $text, $channel);
        });
    }

    protected function ai(): AiVoiceResponder
    {
        return app(AiVoiceResponder::class);
    }

    protected function resolveUserVoiceChannel(Interaction $interaction): ?Channel
    {
        if (! $interaction->guild || ! $interaction->member) {
            return null;
        }

        $state = $interaction->guild->voice_states->get('user_id', $interaction->member->id);
        $channelId = $state?->channel_id;

        if (! $channelId) {
            return null;
        }

        $channel = $interaction->guild->channels->get('id', $channelId) ?? $this->discord()->getChannel($channelId);

        if (! $channel instanceof Channel) {
            return null;
        }

        if ($channel->type !== Channel::TYPE_GUILD_VOICE) {
            return null;
        }

        return $channel;
    }

    protected function playVoice(Interaction $interaction, string $text, Channel $channel): void
    {
        try {
            $ttsPath = $this->ai()->ttsToFile($text);
        } catch (Throwable) {
            return;
        }

        if (! $this->canPlayVoice()) {
            if (File::exists($ttsPath)) {
                File::delete($ttsPath);
            }

            $interaction->sendFollowUpMessage(
                $this->message('Voice is niet beschikbaar op deze server (FFI/Opus ontbreekt). Antwoord staat wel in de chat.')
                    ->title('TTS fallback')
                    ->warning()
                    ->build()
            );

            return;
        }

        try {
            $this->discord()->joinVoiceChannel($channel)->then(
                function (VoiceClient $voice) use ($ttsPath) {
                    $voice->playFile($ttsPath)->then(
                        fn () => $this->cleanupVoicePlayback($voice, $ttsPath),
                        fn () => $this->cleanupVoicePlayback($voice, $ttsPath)
                    );
                },
                function () use ($interaction, $ttsPath) {
                    if (File::exists($ttsPath)) {
                        File::delete($ttsPath);
                    }

                    $interaction->sendFollowUpMessage(
                        $this->message('Voice afspelen lukte niet, maar je antwoord staat in de chat.')
                            ->title('TTS fallback')
                            ->warning()
                            ->build()
                    );
                }
            );
        } catch (Throwable $e) {
            if (File::exists($ttsPath)) {
                File::delete($ttsPath);
            }

            $this->console()->warn('Voice playback unavailable: '.$e->getMessage());
            Log::error('Voice playback unavailable', [
                'command' => $this->getName(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $interaction->sendFollowUpMessage(
                $this->message('Voice is tijdelijk niet beschikbaar. Antwoord staat wel in de chat.')
                    ->title('TTS fallback')
                    ->warning()
                    ->build()
            );
        }
    }

    protected function canPlayVoice(): bool
    {
        if (! extension_loaded('FFI')) {
            return false;
        }

        $ffiEnabled = strtolower((string) ini_get('ffi.enable'));

        return in_array($ffiEnabled, ['1', 'true', 'preload'], true);
    }

    protected function cleanupVoicePlayback(VoiceClient $voice, string $ttsPath): void
    {
        if (File::exists($ttsPath)) {
            File::delete($ttsPath);
        }

        try {
            if ($voice->isReady()) {
                $voice->close();
            }
        } catch (Throwable) {
            // ignore cleanup failures
        }
    }
}
