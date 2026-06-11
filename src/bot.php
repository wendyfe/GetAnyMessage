<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    die("Autoload file not found. Please run 'composer install'.");
}

require_once $autoload;
require_once __DIR__ . '/MessageRelay.php';

use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Filter\FilterCommandCaseInsensitive;
use danog\MadelineProto\EventHandler\Message\ChannelMessage;
use danog\MadelineProto\EventHandler\Message\GroupMessage;
use danog\MadelineProto\EventHandler\Message\PrivateMessage;
use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
use danog\MadelineProto\EventHandler\SimpleFilter\IsNotEdited;
use danog\MadelineProto\ParseMode;
use danog\MadelineProto\Settings;
use danog\MadelineProto\SimpleEventHandler;

final class GetAnyMessage extends SimpleEventHandler
{
    use MessageRelay;

    private const LOGIN_STATE_FILE = 'login_state.json';

    public function onStart(): void
    {
        $this->sendMessageToAdminsSafe("<b>GetAnyMessage personal bot restarted.</b>");
    }

    public function getReportPeers(): array
    {
        $env = $this->env();
        if (!isset($env['ADMIN'])) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $env['ADMIN']))));
    }

    #[Handler]
    public function leaveChats(GroupMessage|ChannelMessage $message): void
    {
        try {
            $this->channels->leaveChannel(channel: $message->chatId);
        } catch (\Throwable $e) {}
    }

    #[FilterCommandCaseInsensitive('start')]
    public function startCommand(Incoming&PrivateMessage&IsNotEdited $message): void
    {
        if (!$this->ensureAllowed($message)) {
            return;
        }

        $session = $this->getUserClient($message->senderId);
        $status = $session === null ? 'not connected' : 'connected';
        $this->messages->sendMessage(
            peer: $message->senderId,
            message: "GetAnyMessage personal edition\n\nAccount: {$status}\n\nSend a Telegram message link here, or use /login, /account, /logout, /help.",
        );
    }

    #[FilterCommandCaseInsensitive('help')]
    public function helpCommand(Incoming&PrivateMessage&IsNotEdited $message): void
    {
        if (!$this->ensureAllowed($message)) {
            return;
        }

        $this->messages->sendMessage(
            peer: $message->senderId,
            message: "Supported links:\nhttps://t.me/channel/123\nhttps://t.me/c/1234567890/123\nhttps://t.me/c/1234567890/topic/123\n\nPrivate/internal links need /login first.",
            no_webpage: true,
        );
    }

    #[FilterCommandCaseInsensitive('account')]
    public function accountCommand(Incoming&PrivateMessage&IsNotEdited $message): void
    {
        if (!$this->ensureAllowed($message)) {
            return;
        }

        $client = $this->getUserClient($message->senderId);
        if ($client === null) {
            $this->messages->sendMessage(peer: $message->senderId, message: "No Telegram account is connected. Use /login.");
            return;
        }

        try {
            $me = $client->getSelf();
            $username = isset($me['username']) ? '@' . $me['username'] : '(none)';
            $premium = !empty($me['premium']) ? 'yes' : 'no';
            $this->messages->sendMessage(
                peer: $message->senderId,
                message: "Connected account\nID: {$me['id']}\nUsername: {$username}\nPhone: " . ($me['phone'] ?? '(hidden)') . "\nPremium: {$premium}",
            );
        } catch (\Throwable $e) {
            $this->messages->sendMessage(peer: $message->senderId, message: "Account check failed: " . $e->getMessage());
        }
    }

    #[FilterCommandCaseInsensitive('login')]
    public function loginCommand(Incoming&PrivateMessage&IsNotEdited $message): void
    {
        if (!$this->ensureAllowed($message)) {
            return;
        }

        if ($this->getUserClient($message->senderId) !== null) {
            $this->messages->sendMessage(peer: $message->senderId, message: "A Telegram account is already connected. Use /account or /logout.");
            return;
        }

        $this->writeLoginState($message->senderId, ['step' => 'phone']);
        $this->messages->sendMessage(peer: $message->senderId, message: "Send your phone number with country code, for example +19876543210.");
    }

    #[FilterCommandCaseInsensitive('cancel')]
    public function cancelCommand(Incoming&PrivateMessage&IsNotEdited $message): void
    {
        if (!$this->ensureAllowed($message)) {
            return;
        }

        $this->clearLoginState($message->senderId);
        $this->messages->sendMessage(peer: $message->senderId, message: "Cancelled.");
    }

    #[FilterCommandCaseInsensitive('logout')]
    public function logoutCommand(Incoming&PrivateMessage&IsNotEdited $message): void
    {
        $this->logoutUser($message);
    }

    #[FilterCommandCaseInsensitive('force_logout')]
    public function forceLogoutCommand(Incoming&PrivateMessage&IsNotEdited $message): void
    {
        $this->logoutUser($message);
    }

    private function logoutUser(PrivateMessage $message): void
    {
        if (!$this->ensureAllowed($message)) {
            return;
        }

        $sessionDir = $this->sessionDir($message->senderId);
        try {
            $client = new API($sessionDir);
            if ($client->getAuthorization() === API::LOGGED_IN) {
                $client->logout();
            }
        } catch (\Throwable $e) {}

        $this->deleteFolder($sessionDir);
        $this->clearLoginState($message->senderId);
        $this->messages->sendMessage(peer: $message->senderId, message: "Logged out and local session removed.");
    }

    #[Handler]
    public function handlePrivateMessage(Incoming&PrivateMessage&IsNotEdited $message): void
    {
        if (!$this->ensureAllowed($message)) {
            return;
        }

        $text = trim((string) $message->message);
        if ($text === '' || str_starts_with($text, '/')) {
            return;
        }

        if ($this->continueLogin($message, $text)) {
            return;
        }

        $replyTo = ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => $message->id];
        $userClient = $this->getUserClient($message->senderId);
        $sourceClient = $userClient ?? $this;
        if ($this->handleTelegramRelayRequest($message, $text, $replyTo, $sourceClient, $userClient !== null)) {
            return;
        }

        $this->messages->sendMessage(peer: $message->senderId, reply_to: $replyTo, message: "Unsupported format. Send /help for examples.");
    }

    private function continueLogin(PrivateMessage $message, string $text): bool
    {
        $state = $this->readLoginState($message->senderId);
        if ($state === null) {
            return false;
        }

        try {
            $step = $state['step'] ?? null;
            if ($step === 'phone') {
                if (!preg_match('/^\+\d{7,15}$/', $text)) {
                    $this->messages->sendMessage(peer: $message->senderId, message: "Invalid phone format. Send it like +19876543210, or /cancel.");
                    return true;
                }

                $this->ensureUserDir($message->senderId);
                $client = new API($this->sessionDir($message->senderId), $this->userSessionSettings());
                $client->phoneLogin($text);
                $this->writeLoginState($message->senderId, ['step' => 'code', 'phone' => $text]);
                $this->messages->sendMessage(peer: $message->senderId, message: "Send the login code you received. Spaces and dashes are OK.");
                return true;
            }

            if ($step === 'code') {
                $code = preg_replace('/\D+/', '', $text);
                if ($code === '') {
                    $this->messages->sendMessage(peer: $message->senderId, message: "Send the numeric login code, or /cancel.");
                    return true;
                }

                $client = new API($this->sessionDir($message->senderId), $this->userSessionSettings());
                $authorization = $client->completePhoneLogin($code);
                if (($authorization['_'] ?? null) === 'account.password') {
                    $this->writeLoginState($message->senderId, ['step' => 'password']);
                    $this->messages->sendMessage(peer: $message->senderId, message: "Two-step password is enabled. Send your 2FA password, or /cancel.");
                    return true;
                }

                return $this->finishLogin($message->senderId);
            }

            if ($step === 'password') {
                $client = new API($this->sessionDir($message->senderId), $this->userSessionSettings());
                $client->complete2falogin($text);
                return $this->finishLogin($message->senderId);
            }
        } catch (\Throwable $e) {
            $this->messages->sendMessage(peer: $message->senderId, message: "Login failed: " . $e->getMessage());
            return true;
        }

        $this->clearLoginState($message->senderId);
        return true;
    }

    private function finishLogin(int|string $senderId): bool
    {
        $this->clearLoginState($senderId);
        $this->messages->sendMessage(peer: $senderId, message: "Telegram account connected.");
        return true;
    }

    private function getUserClient(int|string $senderId): ?API
    {
        $sessionDir = $this->sessionDir($senderId);
        if (!is_dir($sessionDir)) {
            return null;
        }

        try {
            $client = new API($sessionDir, $this->userSessionSettings());
            return $client->getAuthorization() === API::LOGGED_IN ? $client : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function userSessionSettings(): Settings\AppInfo
    {
        $env = $this->env();
        return (new Settings\AppInfo())
            ->setApiId((int) ($env['API_ID'] ?? 0))
            ->setApiHash((string) ($env['API_HASH'] ?? ''));
    }

    private function ensureAllowed(PrivateMessage $message): bool
    {
        $admins = $this->getReportPeers();
        if ($admins === []) {
            return true;
        }

        $senderId = (string) $message->senderId;
        if (in_array($senderId, $admins, true)) {
            return true;
        }

        try {
            $info = $this->getInfo($message->senderId);
            $username = $info['User']['username'] ?? null;
            if ($username !== null && in_array('@' . $username, $admins, true)) {
                return true;
            }
            if ($username !== null && in_array($username, $admins, true)) {
                return true;
            }
        } catch (\Throwable $e) {}

        $this->messages->sendMessage(peer: $message->senderId, message: "This personal bot is not open to this account.");
        return false;
    }

    private function sendMessageToAdminsSafe(string $message): void
    {
        foreach ($this->getReportPeers() as $peer) {
            try {
                $this->messages->sendMessage(peer: $peer, message: $message, parse_mode: ParseMode::HTML);
            } catch (\Throwable $e) {}
        }
    }

    private function env(): array
    {
        $path = __DIR__ . '/.env';
        return file_exists($path) ? (parse_ini_file($path) ?: []) : [];
    }

    private function ensureUserDir(int|string $senderId): void
    {
        $dir = __DIR__ . "/data/$senderId";
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private function sessionDir(int|string $senderId): string
    {
        return __DIR__ . "/data/$senderId/user.madeline";
    }

    private function loginStatePath(int|string $senderId): string
    {
        return __DIR__ . "/data/$senderId/" . self::LOGIN_STATE_FILE;
    }

    private function readLoginState(int|string $senderId): ?array
    {
        $path = $this->loginStatePath($senderId);
        if (!is_file($path)) {
            return null;
        }

        $state = json_decode((string) file_get_contents($path), true);
        return is_array($state) ? $state : null;
    }

    private function writeLoginState(int|string $senderId, array $state): void
    {
        $this->ensureUserDir($senderId);
        file_put_contents($this->loginStatePath($senderId), json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function clearLoginState(int|string $senderId): void
    {
        $path = $this->loginStatePath($senderId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function deleteFolder(string $folderPath): void
    {
        if (!is_dir($folderPath)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folderPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }

        @rmdir($folderPath);
    }
}

function RunBot(): void
{
    try {
        $env = parse_ini_file(__DIR__ . '/.env') ?: [];
        if (!isset($env['API_ID'], $env['API_HASH'], $env['BOT_TOKEN'])) {
            die("Missing environment variables in .env\n");
        }

        $botName = $env['BOT_NAME'] ?? 'GetAnyMessage';
        $settings = new Settings();
        $settings->setAppInfo(
            (new Settings\AppInfo())
                ->setApiId((int) $env['API_ID'])
                ->setApiHash((string) $env['API_HASH'])
        );

        $settings->setConnection(
            (new Settings\Connection())
                ->setTimeout(600.0)
                ->setRetry(true)
                ->setMaxMediaSocketCount(1000)
        );
        $settings->setFiles(
            (new Settings\Files())
                ->setUploadParallelChunks(7)
                ->setDownloadParallelChunks(12)
        );
        $settings->setLogger((new Settings\Logger())->setLevel(\danog\MadelineProto\Logger::ERROR));

        if (($env['DB_FLAG'] ?? 'no') === 'yes') {
            $settings->setDb(
                (new Settings\Database\Mysql())
                    ->setUri("tcp://{$env['DB_HOST']}:{$env['DB_PORT']}")
                    ->setUsername((string) $env['DB_USER'])
                    ->setPassword((string) $env['DB_PASS'])
                    ->setDatabase((string) $env['DB_NAME'])
                    ->setEphemeralFilesystemPrefix("Session_{$botName}")
                    ->setMaxConnections(10000)
            );
        }

        GetAnyMessage::startAndLoopBot(__DIR__ . "/bot_{$botName}.madeline", (string) $env['BOT_TOKEN'], $settings);
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'bad_msg_notification')) {
            exit(1);
        }
        if ($e instanceof \Amp\TimeoutException || $e instanceof \Amp\CancelledException) {
            exit(1);
        }
        throw $e;
    }
}

RunBot();
