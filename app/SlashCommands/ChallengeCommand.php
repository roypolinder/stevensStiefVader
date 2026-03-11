<?php

namespace App\SlashCommands;

class ChallengeCommand extends AiVoiceCommand
{
    protected $name = 'challenge';

    protected $description = 'Verzin een korte Nederlandse challenge.';

    public function handle($interaction)
    {
        $this->handleAiResponse(
            $interaction,
            fn () => $this->ai()->challenge(),
            'Challenge'
        );
    }
}
