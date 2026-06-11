<?php

declare(strict_types=1);

final class RelayTaskCancelled extends \RuntimeException {}

trait MessageRelay
{
    private const RELAY_UNSUPPORTED_MESSAGE = "Unsupported format!\nFor all supported formats /help";
    private const RELAY_LOGIN_REQUIRED_MESSAGE = "<i>❌ To use this feature you need to log in with your own account.</i>";
    private const RELAY_DIRECT_FAIL_MESSAGE = "<i>❌ This message cannot be sent directly in bot chat.</i>";
    private const RELAY_ALBUM_FAIL_MESSAGE = "<i>❌ This album cannot be sent directly in bot chat.</i>";
    private const RELAY_CANCELLED_MESSAGE = "Relay task cancelled.";

    private function parseTelegramMessageLink(string $url, object $sourceClient): ?array
    {
        if (!preg_match('/^https?:\/\/(?:www\.)?(?:t\.me|telegram\.me)\/.+\/?$/i', trim($url))) {
            return null;
        }

        $path = parse_url(trim($url), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return ['error' => self::RELAY_UNSUPPORTED_MESSAGE];
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn($part) => $part !== ''));
        if (count($segments) < 2 || count($segments) > 4 || preg_match('/^\+/', $segments[0])) {
            return ['error' => self::RELAY_UNSUPPORTED_MESSAGE];
        }

        $kind = strtolower($segments[0]);
        if (in_array($kind, ['joinchat', 'addstickers', 'share'], true)) {
            return ['error' => self::RELAY_UNSUPPORTED_MESSAGE];
        }

        $messageId = end($segments);
        if (!is_string($messageId) || !preg_match('/^\d+$/', $messageId)) {
            return ['error' => self::RELAY_UNSUPPORTED_MESSAGE];
        }

        if ($kind === 'c') {
            if (!isset($segments[1]) || !preg_match('/^\d+$/', $segments[1])) {
                return ['error' => self::RELAY_UNSUPPORTED_MESSAGE];
            }

            return [
                'peer' => $this->resolveInternalTelegramPeer($sourceClient, $segments[1]),
                'message_id' => (int) $messageId,
                'requires_user_session' => true,
            ];
        }

        if (in_array($kind, ['b', 'u'], true)) {
            if (!isset($segments[1])) {
                return ['error' => self::RELAY_UNSUPPORTED_MESSAGE];
            }

            return [
                'peer' => preg_match('/^\d+$/', $segments[1]) ? $segments[1] : '@' . $segments[1],
                'message_id' => (int) $messageId,
                'requires_user_session' => true,
            ];
        }

        $peer = preg_match('/^\d+$/', $segments[0]) ? $segments[0] : '@' . $segments[0];

        return [
            'peer' => $peer,
            'message_id' => (int) $messageId,
            'requires_user_session' => count($segments) > 2 || $this->publicPeerRequiresUserSession($sourceClient, $peer),
        ];
    }

    private function publicPeerRequiresUserSession(object $sourceClient, string $peer): bool
    {
        if (!str_starts_with($peer, '@')) {
            return false;
        }

        try {
            $info = $sourceClient->getInfo($peer);
            $type = $info['type'] ?? null;
            $chat = $info['Chat'] ?? [];
            return in_array($type, ['chat', 'supergroup'], true)
                || !empty($chat['megagroup'])
                || !empty($chat['gigagroup']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function resolveInternalTelegramPeer(object $sourceClient, string $internalId): string
    {
        try {
            $info = $sourceClient->getInfo($internalId);
            $type = $info['type'] ?? null;
            return $type === 'channel' ? "-100$internalId" : "-$internalId";
        } catch (\Throwable $e) {
            return "-100$internalId";
        }
    }

    private function handleTelegramRelayRequest(
        object $message,
        string $url,
        array $replyTo,
        object $sourceClient,
        bool $hasUserSession
    ): bool {
        $target = $this->parseTelegramMessageLink($url, $sourceClient);
        if ($target === null) {
            return false;
        }

        $peer = $message->senderId;
        if (isset($target['error'])) {
            $this->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: $target['error'], parse_mode: 'HTML');
            return true;
        }

        if (($target['requires_user_session'] ?? false) && !$hasUserSession) {
            $this->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: self::RELAY_LOGIN_REQUIRED_MESSAGE, parse_mode: 'HTML');
            return true;
        }

        $requiresUserSession = (bool) ($target['requires_user_session'] ?? false);
        $relayClient = $requiresUserSession ? $sourceClient : $this;
        $taskToken = null;

        try {
            $albumMessages = $this->fetchAlbumMessages($relayClient, (string) $target['peer'], (int) $target['message_id']);
            if (!$requiresUserSession && $hasUserSession && !$this->hasRelayableMessage($albumMessages)) {
                $relayClient = $sourceClient;
                $albumMessages = $this->fetchAlbumMessages($relayClient, (string) $target['peer'], (int) $target['message_id']);
            }

            $firstMessage = $albumMessages[0] ?? null;
            if (($firstMessage['_'] ?? null) !== 'message') {
                $this->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: "<i>❌ MESSAGE_EMPTY</i>\nThe message was not returned by Telegram. If it is visible in your account, try /login or make sure the connected account can open the link.", parse_mode: 'HTML');
                return true;
            }

            if (count($albumMessages) > 1) {
                $status = $this->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: "Processing album... Please wait.\nSend /cancel_task to cancel.");
                $statusId = $this->relayMessageId($status);
                $taskToken = $this->startRelayTask($peer, $statusId, $url);
                if (!$this->tryReplyAlbumToChat($peer, $albumMessages, $replyTo, $relayClient, $statusId, $taskToken)) {
                    $this->editRelayStatus($peer, $statusId, self::RELAY_ALBUM_FAIL_MESSAGE);
                    return true;
                }

                $this->deleteRelayStatus($statusId);
                return true;
            }

            $statusId = null;
            if (isset($firstMessage['media'])) {
                $status = $this->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: "Processing media... Please wait.\nSend /cancel_task to cancel.");
                $statusId = $this->relayMessageId($status);
                $taskToken = $this->startRelayTask($peer, $statusId, $url);
            }

            if (!$this->tryReplyMessageToChat($peer, $firstMessage, $replyTo, $relayClient, $statusId, $taskToken)) {
                if ($statusId !== null) {
                    $this->editRelayStatus($peer, $statusId, self::RELAY_DIRECT_FAIL_MESSAGE);
                } else {
                    $this->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: self::RELAY_DIRECT_FAIL_MESSAGE, parse_mode: 'HTML');
                }
                return true;
            }

            $this->deleteRelayStatus($statusId);
            return true;
        } catch (RelayTaskCancelled $e) {
            $this->editRelayStatus($peer, $this->activeRelayStatusId($peer), self::RELAY_CANCELLED_MESSAGE);
            return true;
        } catch (\Throwable $e) {
            $this->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: '<i>❌ ' . $e->getMessage() . '</i>', parse_mode: 'HTML');
            return true;
        } finally {
            if ($taskToken !== null) {
                $this->clearActiveRelayTask($peer, $taskToken);
            }
        }
    }

    private function fetchAlbumMessages(object $client, string $channel, int|string $messageId): array
    {
        $messageId = (int) $messageId;
        $messages = $this->fetchMessagesFromPeer($client, $channel, [$messageId]);
        $first = $messages['messages'][0] ?? null;
        $groupedId = $first['grouped_id'] ?? $first['groupedId'] ?? null;
        if (!$groupedId) {
            return $first ? [$first] : [];
        }

        $ids = range(max(1, $messageId - 10), $messageId + 10);
        $nearby = $this->fetchMessagesFromPeer($client, $channel, $ids);
        $album = [];
        foreach (($nearby['messages'] ?? []) as $item) {
            $itemGroupedId = $item['grouped_id'] ?? $item['groupedId'] ?? null;
            if ($itemGroupedId && (string) $itemGroupedId === (string) $groupedId) {
                $album[] = $item;
            }
        }

        usort($album, fn($a, $b) => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0)));
        return $album ?: [$first];
    }

    private function hasRelayableMessage(array $messages): bool
    {
        foreach ($messages as $message) {
            if (($message['_'] ?? null) === 'message') {
                return true;
            }
        }

        return false;
    }

    private function fetchMessagesFromPeer(object $client, string $peer, array $ids): array
    {
        try {
            return $client->channels->getMessages(channel: $peer, id: $ids);
        } catch (\Throwable $e) {
            return $client->messages->getMessages(id: $ids);
        }
    }

    private function sendAlbumMessages(object $client, int|string $peer, array $album, ?array $replyTo = null): int
    {
        $multiMedia = [];
        foreach ($album as $item) {
            if (($item['_'] ?? null) !== 'message' || !isset($item['media'])) {
                continue;
            }

            $media = $item['media'];
            $mediaType = $media['_'] ?? '';
            if ($mediaType === 'messageMediaPhoto' && isset($media['photo'])) {
                $inputMedia = ['_' => 'inputMediaPhoto', 'id' => $media['photo']];
            } elseif ($mediaType === 'messageMediaDocument' && isset($media['document'])) {
                $inputMedia = ['_' => 'inputMediaDocument', 'id' => $media['document']];
            } else {
                $inputMedia = $media;
            }

            $multiMedia[] = [
                '_' => 'inputSingleMedia',
                'media' => $inputMedia,
                'message' => (string) ($item['message'] ?? ''),
                'entities' => $item['entities'] ?? [],
            ];
        }

        if ($multiMedia) {
            $sent = 0;
            try {
                foreach (array_chunk($multiMedia, 10) as $chunk) {
                    $client->messages->sendMultiMedia(peer: $peer, reply_to: $replyTo, multi_media: $chunk);
                    $sent += count($chunk);
                }
                return $sent;
            } catch (\Throwable $e) {}
        }

        $sent = 0;
        foreach ($album as $item) {
            if (($item['_'] ?? null) !== 'message') {
                continue;
            }

            $text = (string) ($item['message'] ?? '');
            $entities = $item['entities'] ?? null;
            $media = $item['media'] ?? null;
            if ($media !== null) {
                $client->messages->sendMedia(peer: $peer, reply_to: $replyTo, message: $text, entities: $entities, media: $media);
            } else {
                $client->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: $text, entities: $entities);
            }
            $sent++;
        }
        return $sent;
    }

    private function sendDownloadedMessageToChat(object $sourceClient, int|string $peer, array $item, ?array $replyTo = null, ?int $statusMessageId = null, ?string $taskToken = null): bool
    {
        if (($item['_'] ?? null) !== 'message' || !isset($item['media'])) {
            return false;
        }

        $path = null;
        try {
            $media = $item['media'];
            $info = $sourceClient->getDownloadInfo($media);
            $extension = (string) ($info['ext'] ?? '');
            $path = $this->prepareRelayUploadDir() . '/' . uniqid('relay_', true) . ($extension === '' ? '.bin' : $extension);

            $lastDownloadUpdate = 0;
            $this->throwIfRelayTaskCancelled($peer, $taskToken);
            $this->editRelayStatus($peer, $statusMessageId, 'Downloading media...');
            $sourceClient->downloadToFile(
                $media,
                new \danog\MadelineProto\FileCallback(
                    $path,
                    function ($progress, $speed, $time) use ($peer, $statusMessageId, $taskToken, &$lastDownloadUpdate) {
                        $this->throwIfRelayTaskCancelled($peer, $taskToken);
                        $now = time();
                        if ($now - $lastDownloadUpdate >= 3 || $progress >= 100) {
                            $lastDownloadUpdate = $now;
                            $this->editRelayStatus($peer, $statusMessageId, 'Downloading media (' . number_format((float) $progress, 1) . "%)...\nSend /cancel_task to cancel.");
                        }
                    }
                )
            );

            $this->throwIfRelayTaskCancelled($peer, $taskToken);
            $lastUploadUpdate = 0;
            $inputMedia = $this->buildUploadedInputMedia(
                $media,
                $path,
                function ($progress, $speed, $time) use ($peer, $statusMessageId, $taskToken, &$lastUploadUpdate) {
                    $this->throwIfRelayTaskCancelled($peer, $taskToken);
                    $now = time();
                    if ($now - $lastUploadUpdate >= 3 || $progress >= 100) {
                        $lastUploadUpdate = $now;
                        $this->editRelayStatus($peer, $statusMessageId, 'Uploading media (' . number_format((float) $progress, 1) . "%)...\nSend /cancel_task to cancel.");
                    }
                }
            );
            if ($inputMedia === null) {
                return false;
            }

            $this->throwIfRelayTaskCancelled($peer, $taskToken);
            $this->messages->sendMedia(
                peer: $peer,
                reply_to: $replyTo,
                message: (string) ($item['message'] ?? ''),
                entities: $item['entities'] ?? null,
                media: $inputMedia
            );
            return true;
        } catch (RelayTaskCancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            return false;
        } finally {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function editRelayStatus(int|string $peer, ?int $statusMessageId, string $message): void
    {
        if ($statusMessageId === null) {
            return;
        }

        try {
            $this->messages->editMessage(peer: $peer, id: $statusMessageId, message: $message);
        } catch (\Throwable $e) {}
    }

    private function deleteRelayStatus(?int $statusMessageId): void
    {
        if ($statusMessageId === null) {
            return;
        }

        try {
            $this->messages->deleteMessages(revoke: true, id: [$statusMessageId]);
        } catch (\Throwable $e) {}
    }

    private function prepareRelayUploadDir(): string
    {
        $dir = __DIR__ . '/data/_relay_uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $expireBefore = time() - 86400;
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (is_file($path) && filemtime($path) !== false && filemtime($path) < $expireBefore) {
                @unlink($path);
            }
        }

        return $dir;
    }

    private function buildUploadedInputMedia(array $media, string $path, ?callable $progressCallback = null): ?array
    {
        $file = $progressCallback === null
            ? new \danog\MadelineProto\LocalFile($path)
            : new \danog\MadelineProto\FileCallback($path, $progressCallback);
        $mediaType = $media['_'] ?? '';
        if ($mediaType === 'messageMediaPhoto') {
            return ['_' => 'inputMediaUploadedPhoto', 'file' => $file];
        }

        if ($mediaType === 'messageMediaDocument') {
            $document = $media['document'] ?? [];
            return [
                '_' => 'inputMediaUploadedDocument',
                'file' => $file,
                'mime_type' => $document['mime_type'] ?? 'application/octet-stream',
                'attributes' => $document['attributes'] ?? [],
            ];
        }

        return null;
    }

    private function relayMessageId(mixed $message): ?int
    {
        if (is_array($message)) {
            return isset($message['id']) ? (int) $message['id'] : null;
        }

        if (is_object($message) && isset($message->id)) {
            return (int) $message->id;
        }

        return null;
    }

    private function relayTaskPath(int|string $peer): string
    {
        return __DIR__ . "/data/$peer/active_relay_task.json";
    }

    private function startRelayTask(int|string $peer, ?int $statusMessageId, string $url): string
    {
        $dir = __DIR__ . "/data/$peer";
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $token = bin2hex(random_bytes(12));
        file_put_contents(
            $this->relayTaskPath($peer),
            json_encode([
                'token' => $token,
                'status_message_id' => $statusMessageId,
                'url' => $url,
                'cancelled' => false,
                'started_at' => time(),
            ], JSON_THROW_ON_ERROR)
        );

        return $token;
    }

    private function readActiveRelayTask(int|string $peer): ?array
    {
        $path = $this->relayTaskPath($peer);
        if (!is_file($path)) {
            return null;
        }

        $state = json_decode((string) file_get_contents($path), true);
        return is_array($state) ? $state : null;
    }

    private function activeRelayStatusId(int|string $peer): ?int
    {
        $state = $this->readActiveRelayTask($peer);
        return isset($state['status_message_id']) ? (int) $state['status_message_id'] : null;
    }

    private function cancelActiveRelayTask(int|string $peer): bool
    {
        $state = $this->readActiveRelayTask($peer);
        if ($state === null) {
            return false;
        }

        $state['cancelled'] = true;
        $state['cancelled_at'] = time();
        file_put_contents($this->relayTaskPath($peer), json_encode($state, JSON_THROW_ON_ERROR));
        $this->editRelayStatus($peer, $this->activeRelayStatusId($peer), 'Cancelling relay task...');
        return true;
    }

    private function clearActiveRelayTask(int|string $peer, string $token): void
    {
        $state = $this->readActiveRelayTask($peer);
        if (($state['token'] ?? null) !== $token) {
            return;
        }

        @unlink($this->relayTaskPath($peer));
    }

    private function throwIfRelayTaskCancelled(int|string $peer, ?string $token): void
    {
        if ($token === null) {
            return;
        }

        $state = $this->readActiveRelayTask($peer);
        if (($state['token'] ?? null) === $token && !empty($state['cancelled'])) {
            throw new RelayTaskCancelled();
        }
    }

    private function sendDownloadedAlbumToChat(object $sourceClient, int|string $peer, array $album, ?array $replyTo = null, ?int $statusMessageId = null, ?string $taskToken = null): bool
    {
        $mediaItems = array_values(array_filter($album, fn($item) => (($item['_'] ?? null) === 'message') && isset($item['media'])));
        $total = count($mediaItems);
        if ($total === 0) {
            return false;
        }

        $paths = [];
        $multiMedia = [];
        try {
            foreach ($mediaItems as $index => $item) {
                $position = $index + 1;
                $media = $item['media'];
                $info = $sourceClient->getDownloadInfo($media);
                $extension = (string) ($info['ext'] ?? '');
                $path = $this->prepareRelayUploadDir() . '/' . uniqid('album_', true) . ($extension === '' ? '.bin' : $extension);
                $paths[] = $path;

                $lastDownloadUpdate = 0;
                $this->throwIfRelayTaskCancelled($peer, $taskToken);
                $this->editRelayStatus($peer, $statusMessageId, "Downloading media $position/$total...");
                $sourceClient->downloadToFile(
                    $media,
                    new \danog\MadelineProto\FileCallback(
                        $path,
                        function ($progress, $speed, $time) use ($peer, $statusMessageId, $position, $total, $taskToken, &$lastDownloadUpdate) {
                            $this->throwIfRelayTaskCancelled($peer, $taskToken);
                            $now = time();
                            if ($now - $lastDownloadUpdate >= 3 || $progress >= 100) {
                                $lastDownloadUpdate = $now;
                                $this->editRelayStatus($peer, $statusMessageId, "Downloading media $position/$total (" . number_format((float) $progress, 1) . "%)...\nSend /cancel_task to cancel.");
                            }
                        }
                    )
                );

                $this->throwIfRelayTaskCancelled($peer, $taskToken);
                $lastUploadUpdate = 0;
                $inputMedia = $this->buildUploadedInputMedia(
                    $media,
                    $path,
                    function ($progress, $speed, $time) use ($peer, $statusMessageId, $position, $total, $taskToken, &$lastUploadUpdate) {
                        $this->throwIfRelayTaskCancelled($peer, $taskToken);
                        $now = time();
                        if ($now - $lastUploadUpdate >= 3 || $progress >= 100) {
                            $lastUploadUpdate = $now;
                            $this->editRelayStatus($peer, $statusMessageId, "Uploading media $position/$total (" . number_format((float) $progress, 1) . "%)...\nSend /cancel_task to cancel.");
                        }
                    }
                );
                if ($inputMedia === null) {
                    return false;
                }

                $multiMedia[] = [
                    '_' => 'inputSingleMedia',
                    'media' => $inputMedia,
                    'message' => (string) ($item['message'] ?? ''),
                    'entities' => $item['entities'] ?? [],
                ];
            }

            $sent = 0;
            $chunks = array_chunk($multiMedia, 10);
            $chunkTotal = count($chunks);
            foreach ($chunks as $chunkIndex => $chunk) {
                $this->throwIfRelayTaskCancelled($peer, $taskToken);
                $this->editRelayStatus($peer, $statusMessageId, 'Uploading album ' . ($chunkIndex + 1) . '/' . $chunkTotal . '...');
                $this->messages->sendMultiMedia(peer: $peer, reply_to: $replyTo, multi_media: $chunk);
                $sent += count($chunk);
            }
            return $sent > 0;
        } catch (RelayTaskCancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            return false;
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function tryReplyMessageToChat(int|string $peer, array $item, ?array $replyTo = null, ?object $sourceClient = null, ?int $statusMessageId = null, ?string $taskToken = null): bool
    {
        if (($item['_'] ?? null) !== 'message') {
            return false;
        }

        try {
            $this->throwIfRelayTaskCancelled($peer, $taskToken);
            $text = (string) ($item['message'] ?? '');
            $entities = $item['entities'] ?? null;
            $media = $item['media'] ?? null;
            if ($media !== null) {
                $this->messages->sendMedia(peer: $peer, reply_to: $replyTo, message: $text, entities: $entities, media: $media);
            } else {
                $this->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: $text, entities: $entities);
            }
            return true;
        } catch (RelayTaskCancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($sourceClient !== null && isset($media)) {
                return $this->sendDownloadedMessageToChat($sourceClient, $peer, $item, $replyTo, $statusMessageId, $taskToken);
            }
            return false;
        }
    }

    private function tryReplyAlbumToChat(int|string $peer, array $album, ?array $replyTo = null, ?object $sourceClient = null, ?int $statusMessageId = null, ?string $taskToken = null): bool
    {
        try {
            $this->throwIfRelayTaskCancelled($peer, $taskToken);
            return $this->sendAlbumMessages($this, $peer, $album, $replyTo) > 0;
        } catch (RelayTaskCancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($sourceClient === null) {
                return false;
            }

            return $this->sendDownloadedAlbumToChat($sourceClient, $peer, $album, $replyTo, $statusMessageId, $taskToken);
        }
    }
}
