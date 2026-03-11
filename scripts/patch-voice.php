<?php

declare(strict_types=1);

$wsTarget = __DIR__.'/../vendor/discord-php-helpers/voice/src/Discord/Voice/Client/WS.php';
$packetTarget = __DIR__.'/../vendor/discord-php-helpers/voice/src/Discord/Voice/Client/Packet.php';

if (! file_exists($wsTarget) || ! file_exists($packetTarget)) {
    fwrite(STDOUT, "[patch-voice] Skipped: vendor voice files not found.\n");
    exit(0);
}

$patches = [
    [
        'file' => $wsTarget,
        'find' => <<<'TXT'
public const MAX_DAVE_PROTOCOL_VERSION = 0;
TXT,
        'replace' => <<<'TXT'
public const MAX_DAVE_PROTOCOL_VERSION = 1;
TXT,
        'label' => 'DAVE protocol version',
    ],
    [
        'file' => $wsTarget,
        'find' => <<<'TXT'
public string $mode = 'aead_aes256_gcm_rtpsize';
TXT,
        'replace' => <<<'TXT'
public string $mode = 'aead_xchacha20_poly1305_rtpsize';
TXT,
        'label' => 'WS default mode',
    ],
    [
        'file' => $wsTarget,
        'find' => <<<'TXT'
$this->mode = $sd->mode === $this->mode ? $this->mode : 'aead_aes256_gcm_rtpsize';
TXT,
        'replace' => <<<'TXT'
$this->mode = $sd->mode === $this->mode ? $this->mode : 'aead_xchacha20_poly1305_rtpsize';
TXT,
        'label' => 'WS fallback mode',
    ],
    [
        'file' => $wsTarget,
        'find' => <<<'TXT'
if (($data = json_decode($message->getPayload(), true)) === false) {
                return;
            }
            $data = Payload::fromArray($data);
TXT,
        'replace' => <<<'TXT'
$data = json_decode($message->getPayload(), true);

            if (! is_array($data)) {
                $this->discord->logger->warning('voice websocket returned non-array payload', [
                    'payload' => $message->getPayload(),
                ]);

                return;
            }

            $data = Payload::fromArray($data);
TXT,
        'label' => 'WS null payload guard',
    ],
    [
        'file' => $packetTarget,
        'find' => <<<'TXT'
$header = $this->getHeader();

        // pad nonce to 12 bytes for AES 256 GCM
        $nonce = pack('V', $this->seq - 1);
TXT,
        'replace' => <<<'TXT'
$header = $this->getHeader();

        $keyLength = $this->key ? strlen($this->key) : 0;

        if ($keyLength === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            $nonce = pack('V', $this->seq - 1);
            $paddedNonce = str_pad($nonce, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES, "\0", STR_PAD_RIGHT);
            $this->encryptedAudio = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($this->decryptedAudio, $header, $paddedNonce, $this->key);
            $this->rawData = $header.$this->encryptedAudio.$nonce;

            return;
        }

        // pad nonce to 12 bytes for AES 256 GCM
        $nonce = pack('V', $this->seq - 1);
TXT,
        'label' => 'Packet XChaCha encrypt',
    ],
    [
        'file' => $packetTarget,
        'find' => <<<'TXT'
// 4. Pad the nonce to 12 bytes
        $nonceBuffer = str_pad($nonce, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES, "\0", STR_PAD_RIGHT);
TXT,
        'replace' => <<<'TXT'
$keyLength = $this->key ? strlen($this->key) : 0;

        // XChaCha20-Poly1305 mode (24-byte nonce)
        if ($keyLength === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            $nonceBuffer = str_pad($nonce, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES, "\0", STR_PAD_RIGHT);

            $encryptedLength = $len - $this->headerSize - HeaderValuesEnum::AUTH_TAG_LENGTH->value - HeaderValuesEnum::TIMESTAMP_OR_NONCE_INDEX->value;
            $cipherText = substr($message, $this->headerSize, $encryptedLength);
            $authTag = substr($message, $this->headerSize + $encryptedLength, HeaderValuesEnum::AUTH_TAG_LENGTH->value);
            $combined = "$cipherText$authTag";

            try {
                return $this->decryptedAudio = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    $combined,
                    $header,
                    $nonceBuffer,
                    $this->key
                );
            } catch (\Throwable) {
                return false;
            }
        }

        // 4. Pad the nonce to 12 bytes (AES-GCM mode)
        $nonceBuffer = str_pad($nonce, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES, "\0", STR_PAD_RIGHT);
TXT,
        'label' => 'Packet XChaCha decrypt',
    ],
];

$applied = 0;

foreach ($patches as $patch) {
    $contents = file_get_contents($patch['file']);

    if ($contents === false) {
        fwrite(STDERR, "[patch-voice] Failed to read {$patch['file']}\n");
        exit(1);
    }

    if (! str_contains($contents, $patch['find'])) {
        fwrite(STDOUT, "[patch-voice] Skip {$patch['label']} (already patched or upstream changed).\n");
        continue;
    }

    $contents = str_replace($patch['find'], $patch['replace'], $contents, $count);

    if ($count < 1) {
        fwrite(STDERR, "[patch-voice] Failed applying {$patch['label']}.\n");
        exit(1);
    }

    if (file_put_contents($patch['file'], $contents) === false) {
        fwrite(STDERR, "[patch-voice] Failed writing {$patch['file']}\n");
        exit(1);
    }

    $applied++;
    fwrite(STDOUT, "[patch-voice] Applied {$patch['label']}.\n");
}

fwrite(STDOUT, "[patch-voice] Done ({$applied} patch(es) applied).\n");
