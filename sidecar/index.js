'use strict';

const path = require('path');
// Load .env from project root (one level up from sidecar/)
require('dotenv').config({ path: path.resolve(__dirname, '..', '.env') });

const express = require('express');
const {
    Client,
    GatewayIntentBits,
} = require('discord.js');
const {
    joinVoiceChannel,
    createAudioPlayer,
    createAudioResource,
    AudioPlayerStatus,
    VoiceConnectionStatus,
    entersState,
} = require('@discordjs/voice');
const fs = require('fs');

// ── Config ────────────────────────────────────────────────────────────────────
const PORT         = parseInt(process.env.VOICE_SIDECAR_PORT  || '3001', 10);
const TOKEN        = process.env.VOICE_BOT_TOKEN;
const VOICE_SECRET = process.env.VOICE_SIDECAR_TOKEN || '';

if (!TOKEN) {
    console.error('[sidecar] DISCORD_TOKEN is not set. Exiting.');
    process.exit(1);
}

if (!VOICE_SECRET) {
    console.warn('[sidecar] WARNING: VOICE_SIDECAR_TOKEN is not set. Endpoint is unprotected!');
}

// ── Discord client ─────────────────────────────────────────────────────────────
const client = new Client({
    intents: [
        GatewayIntentBits.Guilds,
        GatewayIntentBits.GuildVoiceStates,
    ],
});

client.once('ready', () => {
    console.log(`[sidecar] Logged in as ${client.user.tag}`);
});

client.login(TOKEN).catch((err) => {
    console.error('[sidecar] Discord login failed:', err.message);
    process.exit(1);
});

// ── Express ───────────────────────────────────────────────────────────────────
const app = express();
app.use(express.json());

/**
 * Auth middleware – checks X-Voice-Token header when VOICE_SIDECAR_TOKEN is set.
 */
app.use((req, res, next) => {
    if (!VOICE_SECRET) return next();
    const header = req.headers['x-voice-token'] || '';
    if (header !== VOICE_SECRET) {
        return res.status(401).json({ error: 'Unauthorized' });
    }
    next();
});

/**
 * POST /play
 * Body: { guildId: string, channelId: string, filePath: string }
 *
 * Joins the voice channel, plays the file, then leaves and deletes the file.
 */
app.post('/play', async (req, res) => {
    const { guildId, channelId, filePath } = req.body || {};

    if (!guildId || !channelId || !filePath) {
        return res.status(400).json({ error: 'guildId, channelId en filePath zijn verplicht.' });
    }

    if (!fs.existsSync(filePath)) {
        return res.status(404).json({ error: `Bestand niet gevonden: ${filePath}` });
    }

    // Respond immediately so Laracord is not blocked.
    res.json({ ok: true });

    try {
        // Use cached guild (from gateway) instead of REST fetch.
        // voiceAdapterCreator only works correctly with a gateway-cached guild.
        let guild = client.guilds.cache.get(guildId);
        if (!guild) {
            console.log(`[sidecar] Guild niet in cache, probeer via gateway te fetchen...`);
            guild = await client.guilds.fetch(guildId);
            // Force cache the guild members/channels so adapter works
            await guild.fetch();
        }
        console.log(`[sidecar] Guild gevonden: ${guild.name}`);

        const connection = joinVoiceChannel({
            channelId,
            guildId,
            adapterCreator: guild.voiceAdapterCreator,
            selfDeaf: false,
        });

        connection.on('stateChange', (oldState, newState) => {
            console.log(`[sidecar] Voice connectie: ${oldState.status} -> ${newState.status}`);
            if (newState.networking) {
                newState.networking.on('stateChange', (o, n) => {
                    console.log(`[sidecar] Networking: ${o.code ?? o.constructor.name} -> ${n.code ?? n.constructor.name}`);
                });
                newState.networking.on('error', (e) => {
                    console.error(`[sidecar] Networking error: ${e.message}`);
                });
            }
        });

        connection.on('error', (err) => {
            console.error(`[sidecar] Voice connectie error: ${err.message}`);
        });

        console.log(`[sidecar] Wacht op Ready...`);
        await entersState(connection, VoiceConnectionStatus.Ready, 15_000);
        console.log(`[sidecar] Ready! Start afspelen: ${filePath}`);

        const player   = createAudioPlayer();
        const resource = createAudioResource(filePath);

        connection.subscribe(player);
        player.play(resource);

        await entersState(player, AudioPlayerStatus.Idle, 5 * 60_000);

        connection.destroy();
    } catch (err) {
        console.error('[sidecar] /play error:', err.message);
    } finally {
        try { fs.unlinkSync(filePath); } catch (_) { /* ignore */ }
    }
});

/**
 * GET /health – simple liveness probe.
 */
app.get('/health', (_req, res) => {
    res.json({ ok: true, bot: client.user?.tag ?? 'not ready' });
});

app.listen(PORT, '127.0.0.1', () => {
    console.log(`[sidecar] HTTP server luistert op http://127.0.0.1:${PORT}`);
});
