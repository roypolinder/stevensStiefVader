<?php

namespace App\SlashCommands;

use App\Support\AiVoiceResponder;
use App\Commands\SlashCommand;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Interactions\Interaction;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
        $sidecarUrl = rtrim((string) config('ai_voice.sidecar_url', ''), '/');

        if (! $sidecarUrl) {
            $interaction->sendFollowUpMessage(
                $this->message('Voice sidecar is niet geconfigureerd (`VOICE_SIDECAR_URL`). Antwoord staat wel in de chat.')
                    ->title('TTS fallback')
                    ->warning()
                    ->build()
            );

            return;
        }

        try {
            $ttsPath = $this->ai()->ttsToFile($text);
        } catch (Throwable $e) {
            Log::error('TTS generatie mislukt', [
                'command' => $this->getName(),
                'message' => $e->getMessage(),
            ]);

            return;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Voice-Token' => (string) config('ai_voice.sidecar_token', ''),
                ])
                ->post("{$sidecarUrl}/play", [
                    'guildId'   => $channel->guild_id,
                    'channelId' => $channel->id,
                    'filePath'  => $ttsPath,
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Sidecar antwoordde met HTTP '.$response->status().': '.$response->body());
            }
        } catch (Throwable $e) {
            if (File::exists($ttsPath)) {
                File::delete($ttsPath);
            }

            $this->console()->warn('Voice sidecar aanroep mislukt: '.$e->getMessage());
            Log::error('Voice sidecar aanroep mislukt', [
                'command' => $this->getName(),
                'message' => $e->getMessage(),
            ]);

            $interaction->sendFollowUpMessage(
                $this->message('Voice is tijdelijk niet beschikbaar. Antwoord staat wel in de chat.')
                    ->title('TTS fallback')
                    ->warning()
                    ->build()
            );
        }
    }
}
