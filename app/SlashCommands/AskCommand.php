<?php

namespace App\SlashCommands;

use Discord\Parts\Interactions\Command\Option;

class AskCommand extends AiVoiceCommand
{
    protected $name = 'ask';

    protected $description = 'Stel een vraag aan de AI in het Nederlands.';

    protected $options = [
        [
            'name' => 'vraag',
            'description' => 'Je vraag voor de AI',
            'type' => Option::STRING,
            'required' => true,
        ],
    ];

    public function handle($interaction)
    {
        $question = trim((string) $this->value('vraag'));

        if ($question === '') {
            $this->message('Geef een vraag mee, bijvoorbeeld: /ask Waarom is de lucht blauw?')
                ->title('Ask')
                ->warning()
                ->reply($interaction, ephemeral: true);

            return;
        }

        $this->handleAiResponse(
            $interaction,
            fn () => $this->ai()->answer($question),
            'Ask'
        );
    }
}
