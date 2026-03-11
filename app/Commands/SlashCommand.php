<?php

namespace App\Commands;

use Discord\Builders\CommandBuilder;
use Discord\Parts\Interactions\Command\Command as DiscordCommand;

abstract class SlashCommand extends \Laracord\Commands\SlashCommand
{
    public function getGuild(): ?string
    {
        $guildId = parent::getGuild()
            ?? config('discord.guild_id')
            ?? env('DISCORD_GUILD_ID');

        if ($guildId) {
            return (string) $guildId;
        }

        return $this->discord()->guilds->first()?->id;
    }

    public function create(): DiscordCommand
    {
        $applicationId = $this->discord()->id;

        $command = CommandBuilder::new()
            ->setName($this->getName())
            ->setDescription($this->getDescription())
            ->setType($this->getType());

        if ($permissions = $this->getPermissions()) {
            $command = $command->setDefaultMemberPermissions($permissions);
        }

        if ($this->getRegisteredOptions()) {
            foreach ($this->getRegisteredOptions() as $option) {
                $command = $command->addOption($option);
            }
        }

        $command = collect($command->toArray())
            ->put('application_id', $applicationId)
            ->put('guild_id', $this->getGuild())
            ->filter(fn ($value) => $value !== null)
            ->all();

        return new DiscordCommand($this->discord(), $command);
    }
}
