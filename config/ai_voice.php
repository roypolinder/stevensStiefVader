<?php

return [
    'chat_model' => env('AI_VOICE_CHAT_MODEL', 'gpt-4.1-mini'),
    'tts_model' => env('AI_VOICE_TTS_MODEL', 'gpt-4o-mini-tts'),
    'tts_voice' => env('AI_VOICE_TTS_VOICE', 'alloy'),
    'temperature' => (float) env('AI_VOICE_TEMPERATURE', 0.8),
    'max_output_tokens' => (int) env('AI_VOICE_MAX_OUTPUT_TOKENS', 120),
    'max_tts_chars' => (int) env('AI_VOICE_MAX_TTS_CHARS', 300),
];
