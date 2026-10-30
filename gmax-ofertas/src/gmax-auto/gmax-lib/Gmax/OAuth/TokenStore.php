<?php
declare(strict_types=1);

namespace Gmax\OAuth;

use Gmax\Time;
use RuntimeException;

/**
 * Armazena os tokens CIFRADOS (libsodium secretbox, chave derivada do client_secret) em
 * gmax-secrets/mercadolivre-token.enc com permissão 0600 e gravação atômica (arquivo
 * temporário + rename). Sem o client_secret o arquivo é ilegível.
 */
final class TokenStore
{
    private const FILE = 'mercadolivre-token.enc';
    private const LOCK = 'mercadolivre-token.lock';

    public function __construct(private string $dir, private string $secret)
    {
        if (strlen($secret) < 16) {
            throw new RuntimeException('Segredo do aplicativo ausente: não é possível cifrar tokens');
        }
    }

    private function key(): string
    {
        return hash_hkdf('sha256', $this->secret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'gmax-ml-token-v1');
    }

    public function path(): string
    {
        return $this->dir . '/' . self::FILE;
    }

    public function save(array $token): void
    {
        $token += ['obtained_at' => Time::now()->getTimestamp()];
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $blob = 'GMX1' . base64_encode($nonce . sodium_crypto_secretbox((string)json_encode($token), $nonce, $this->key()));
        $tmp = $this->path() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $old = umask(0077);
        try {
            if (file_put_contents($tmp, $blob, LOCK_EX) === false) {
                throw new RuntimeException('Não foi possível gravar o token (permissão da pasta privada)');
            }
            @chmod($tmp, 0600);
            if (!rename($tmp, $this->path())) {
                @unlink($tmp);
                throw new RuntimeException('Não foi possível gravar o token');
            }
        } finally {
            umask($old);
        }
    }

    public function load(): ?array
    {
        $raw = @file_get_contents($this->path());
        if (!is_string($raw) || !str_starts_with($raw, 'GMX1')) {
            return null;
        }
        $bin = base64_decode(substr($raw, 4), true);
        if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
        if ($plain === false) {
            return null; // segredo trocado ou arquivo adulterado: exige nova autorização
        }
        $t = json_decode($plain, true);
        return is_array($t) && isset($t['access_token'], $t['expires_at']) ? $t : null;
    }

    public function markInvalid(string $reason): void
    {
        $t = $this->load();
        if ($t !== null) {
            $this->save(['invalid' => mb_substr($reason, 0, 160)] + $t);
        }
    }

    public function delete(): void
    {
        @unlink($this->path());
    }

    public function withLock(callable $fn): mixed
    {
        $fh = @fopen($this->dir . '/' . self::LOCK, 'c');
        if ($fh === false) {
            throw new RuntimeException('Pasta privada sem permissão de escrita');
        }
        try {
            flock($fh, LOCK_EX); // espera: a renovação é rápida e não pode acontecer em paralelo
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
