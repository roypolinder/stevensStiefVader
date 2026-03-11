<?php

namespace App\SlashCommands;

use Discord\Parts\Interactions\Command\Option;

class RoastCommand extends AiVoiceCommand
{
    protected $name = 'roast';

    protected $description = 'Maak een korte Nederlandse roast.';

    protected $options = [
        [
            'name' => 'user',
            'description' => 'Wie wil je laten roasten?',
            'type' => Option::USER,
            'required' => true,
        ],
    ];

    public function handle($interaction)
    {
        $userId = (string) $this->value('user');
        $username = $this->resolveUsername($userId);

        $this->handleAiResponse(
            $interaction,
            fn () => $this->ai()->roast($username),
            'Roast'
        );
    }

    protected function resolveUsername(string $userId): string
    {
        $user = $this->discord()->users->get('id', $userId);

        if ($user?->username) {
            return $user->username;
        }

        return 'deze gebruiker';
    }
}
