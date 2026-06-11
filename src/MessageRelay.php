<?php

declare(strict_types=1);

trait MessageRelay
{
    private const RELAY_UNSUPPORTED_MESSAGE = "Unsupported format!\nFor all supported formats /help";
    private const RELAY_LOGIN_REQUIRED_MESSAGE = "<i>❌ To use this feature you need to log in with your own account.</i>";
    private const RELAY_DIRECT_FAIL_MESSAGE = "<i>❌ This message cannot be sent directly in bot chat.</i>";
    private const RELAY_ALBUM_FAIL_MESSAGE = "<i>❌ This album cannot be sent directly in bot chat.</i>";

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

        return [
            'peer' => preg_match('/^\d+$/', $segments[0]) ? $segments[0] : '@' . $segments[0],
            'message_id' => (int) $messageId,
            'requires_user_session' => count($segments) > 2,
        ];
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

        try {
            $albumMessages = $this->fetchAlbumMessages($relayClient, (string) $target['peer'], (int) $target['message_id']);
            $firstMessage = $albumMessages[0] ?? null;
            if (($firstMessage['_'] ?? null) !== 'message') {
                $this->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: "<i>❌ MESSAGE_EMPTY</i>", parse_mode: 'HTML');
                return true;
            }

            if (count($albumMessages) > 1) {
                $status = $this->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: "Processing album... Please wait.");
                $statusId = $this->extractMessageId($status);
                if (!$this->tryReplyAlbumToChat($peer, $albumMessages, $replyTo, $relayClient, $statusId)) {
                    $this->editRelayStatus($peer, $statusId, self::RELAY_ALBUM_FAIL_MESSAGE);
                    return true;
                }

                $this->deleteRelayStatus($statusId);
                return true;
            }

            $statusId = null;
            if (isset($firstMessage['media'])) {
                $status = $this->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: "Processing media... Please wait.");
                $statusId = $this->extractMessageId($status);
            }

            if (!$this->tryReplyMessageToChat($peer, $firstMessage, $replyTo, $relayClient, $statusId)) {
                if ($statusId !== null) {
                    $this->editRelayStatus($peer, $statusId, self::RELAY_DIRECT_FAIL_MESSAGE);
                } else {
                    $this->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: self::RELAY_DIRECT_FAIL_MESSAGE, parse_mode: 'HTML');
                }
                return true;
            }

            $this->deleteRelayStatus($statusId);
            return true;
        } catch (\Throwable $e) {
            $this->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: '<i>❌ ' . $e->getMessage() . '</i>', parse_mode: 'HTML');
            return true;
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

    private function sendDownloadedMessageToChat(object $sourceClient, int|string $peer, array $item, ?array $replyTo = null, ?int $statusMessageId = null): bool
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
            $this->editRelayStatus($peer, $statusMessageId, 'Downloading media...');
            $sourceClient->downloadToFile(
                $media,
                new \danog\MadelineProto\FileCallback(
                    $path,
                    function ($progress, $speed, $time) use ($peer, $statusMessageId, &$lastDownloadUpdate) {
                        $now = time();
                        if ($now - $lastDownloadUpdate >= 3 || $progress >= 100) {
                            $lastDownloadUpdate = $now;
                            $this->editRelayStatus($peer, $statusMessageId, 'Downloading media (' . number_format((float) $progress, 1) . '%)...');
                        }
                    }
                )
            );

            $lastUploadUpdate = 0;
            $inputMedia = $this->buildUploadedInputMedia(
                $media,
                $path,
                function ($progress, $speed, $time) use ($peer, $statusMessageId, &$lastUploadUpdate) {
                    $now = time();
                    if ($now - $lastUploadUpdate >= 3 || $progress >= 100) {
                        $lastUploadUpdate = $now;
                        $this->editRelayStatus($peer, $statusMessageId, 'Uploading media (' . number_format((float) $progress, 1) . '%)...');
                    }
                }
            );
            if ($inputMedia === null) {
                return false;
            }

            $this->messages->sendMedia(
                peer: $peer,
                reply_to: $replyTo,
                message: (string) ($item['message'] ?? ''),
                entities: $item['entities'] ?? null,
                media: $inputMedia
            );
            return true;
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

    private function sendDownloadedAlbumToChat(object $sourceClient, int|string $peer, array $album, ?array $replyTo = null, ?int $statusMessageId = null): bool
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
                $this->editRelayStatus($peer, $statusMessageId, "Downloading media $position/$total...");
                $sourceClient->downloadToFile(
                    $media,
                    new \danog\MadelineProto\FileCallback(
                        $path,
                        function ($progress, $speed, $time) use ($peer, $statusMessageId, $position, $total, &$lastDownloadUpdate) {
                            $now = time();
                            if ($now - $lastDownloadUpdate >= 3 || $progress >= 100) {
                                $lastDownloadUpdate = $now;
                                $this->editRelayStatus($peer, $statusMessageId, "Downloading media $position/$total (" . number_format((float) $progress, 1) . "%)...");
                            }
                        }
                    )
                );

                $lastUploadUpdate = 0;
                $inputMedia = $this->buildUploadedInputMedia(
                    $media,
                    $path,
                    function ($progress, $speed, $time) use ($peer, $statusMessageId, $position, $total, &$lastUploadUpdate) {
                        $now = time();
                        if ($now - $lastUploadUpdate >= 3 || $progress >= 100) {
                            $lastUploadUpdate = $now;
                            $this->editRelayStatus($peer, $statusMessageId, "Uploading media $position/$total (" . number_format((float) $progress, 1) . "%)...");
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
                $this->editRelayStatus($peer, $statusMessageId, 'Uploading album ' . ($chunkIndex + 1) . '/' . $chunkTotal . '...');
                $this->messages->sendMultiMedia(peer: $peer, reply_to: $replyTo, multi_media: $chunk);
                $sent += count($chunk);
            }
            return $sent > 0;
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

    private function tryReplyMessageToChat(int|string $peer, array $item, ?array $replyTo = null, ?object $sourceClient = null, ?int $statusMessageId = null): bool
    {
        if (($item['_'] ?? null) !== 'message') {
            return false;
        }

        try {
            $text = (string) ($item['message'] ?? '');
            $entities = $item['entities'] ?? null;
            $media = $item['media'] ?? null;
            if ($media !== null) {
                $this->messages->sendMedia(peer: $peer, reply_to: $replyTo, message: $text, entities: $entities, media: $media);
            } else {
                $this->messages->sendMessage(peer: $peer, reply_to: $replyTo, message: $text, entities: $entities);
            }
            return true;
        } catch (\Throwable $e) {
            if ($sourceClient !== null && isset($media)) {
                return $this->sendDownloadedMessageToChat($sourceClient, $peer, $item, $replyTo, $statusMessageId);
            }
            return false;
        }
    }

    private function tryReplyAlbumToChat(int|string $peer, array $album, ?array $replyTo = null, ?object $sourceClient = null, ?int $statusMessageId = null): bool
    {
        try {
            return $this->sendAlbumMessages($this, $peer, $album, $replyTo) > 0;
        } catch (\Throwable $e) {
            if ($sourceClient === null) {
                return false;
            }

            return $this->sendDownloadedAlbumToChat($sourceClient, $peer, $album, $replyTo, $statusMessageId);
        }
    }
}
