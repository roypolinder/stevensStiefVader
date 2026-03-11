<?php

namespace App\Support;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use OpenAI\Client;
use RuntimeException;

class AiVoiceResponder
{
    public function roast(string $username): string
    {
        return $this->chat("Geef een korte grappige roast van {$username} in het Nederlands, max 1 zin.");
    }

    public function challenge(): string
    {
        return $this->chat('Verzin een korte challenge of opdracht voor een vriendenserver in het Nederlands.');
    }

    public function answer(string $question): string
    {
        return $this->chat($question);
    }

    public function chat(string $prompt): string
    {
        $response = $this->client()->chat()->create([
            'model' => config('ai_voice.chat_model'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Je bent een gezellige Discord-assistent. Antwoord altijd in het Nederlands. Houd het kort, natuurlijk en spreekbaar.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => config('ai_voice.temperature', 0.8),
            'max_tokens' => config('ai_voice.max_output_tokens', 120),
        ]);

        $text = trim((string) ($response->choices[0]->message->content ?? ''));

        if ($text === '') {
            throw new RuntimeException('Leeg antwoord ontvangen van OpenAI.');
        }

        return mb_substr($this->sanitizeForSpeech($text), 0, config('ai_voice.max_tts_chars', 300));
    }

    public function ttsToFile(string $text): string
    {
        $directory = storage_path('app/voice');

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $audio = $this->client()->audio()->speech([
            'model' => config('ai_voice.tts_model'),
            'voice' => config('ai_voice.tts_voice'),
            'input' => $text,
            'format' => 'mp3',
        ]);

        $path = $directory.DIRECTORY_SEPARATOR.'ai-'.uniqid().'.mp3';

        File::put($path, $audio);

        return $path;
    }

    protected function sanitizeForSpeech(string $text): string
    {
        $text = str_replace(['**', '__', '```', '`', '#'], '', $text);

        return preg_replace('/\s+/', ' ', $text) ?? $text;
    }

    protected function client(): Client
    {
        if (! env('OPENAI_API_KEY')) {
            Log::error('OPENAI_API_KEY ontbreekt in environment.');

            throw new RuntimeException('OPENAI_API_KEY ontbreekt.');
        }

        $factory = \OpenAI::factory()
            ->withApiKey((string) env('OPENAI_API_KEY'))
            ->withHttpClient(new GuzzleClient([
                'timeout' => (int) env('OPENAI_REQUEST_TIMEOUT', 30),
            ]));

        if ($organization = env('OPENAI_ORGANIZATION')) {
            $factory->withOrganization((string) $organization);
        }

        if ($project = env('OPENAI_PROJECT')) {
            $factory->withProject((string) $project);
        }

        if ($baseUri = env('OPENAI_BASE_URL')) {
            $factory->withBaseUri((string) $baseUri);
        }

        return $factory->make();
    }
}
