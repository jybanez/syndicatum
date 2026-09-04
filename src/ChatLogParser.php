<?php

class ChatLogParser
{
    private $sourcePath;

    public function __construct($sourcePath)
    {
        $resolved = realpath($sourcePath);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('Chat log source was not found: ' . $sourcePath);
        }

        $this->sourcePath = $resolved;
    }

    public function getSourcePath()
    {
        return $this->sourcePath;
    }

    public function parse()
    {
        $content = (string) file_get_contents($this->sourcePath);
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];

        $section = null;
        $projects = [];
        $topics = [];
        $messages = [];
        $currentMessageIndex = null;

        foreach ($lines as $lineNumber => $line) {
            $trimmed = trim($line);

            if ($trimmed === '#Projects') {
                $section = 'projects';
                continue;
            }

            if ($trimmed === '#Active Topics') {
                $section = 'topics';
                continue;
            }

            if ($trimmed === '#Chat log') {
                $section = 'chat';
                continue;
            }

            if ($trimmed !== '' && strpos($trimmed, '#') === 0) {
                $section = null;
                continue;
            }

            if ($section === 'projects') {
                if (strpos($trimmed, '- ') === 0) {
                    $projects[] = $this->parseProjectBullet(substr($trimmed, 2));
                }
                continue;
            }

            if ($section === 'topics') {
                if (strpos($trimmed, '- ') === 0) {
                    $topics[] = substr($trimmed, 2);
                }
                continue;
            }

            if ($section !== 'chat') {
                continue;
            }

            if ($trimmed === '') {
                continue;
            }

            $message = $this->parseMessageLine($line, count($messages) + 1, $lineNumber + 1);
            if ($message !== null) {
                $messages[] = $message;
                $currentMessageIndex = count($messages) - 1;
                continue;
            }

            if ($currentMessageIndex === null) {
                continue;
            }

            $messages[$currentMessageIndex]['body'] .= "\n" . trim($line);
            $messages[$currentMessageIndex]['excerpt'] = $this->makeExcerpt($messages[$currentMessageIndex]['body']);
        }

        $participants = $this->buildParticipants($messages, $projects);
        $days = array_values(array_unique(array_map(function ($message) {
            return $message['day_key'];
        }, $messages)));
        $mtime = filemtime($this->sourcePath) ?: time();
        $etag = '"' . sha1($mtime . '|' . filesize($this->sourcePath) . '|' . count($messages)) . '"';

        return [
            'meta' => [
                'source_path' => $this->sourcePath,
                'source_name' => basename($this->sourcePath),
                'last_modified_unix' => $mtime,
                'last_modified_iso' => gmdate(DATE_ATOM, $mtime),
                'etag' => $etag,
                'message_count' => count($messages),
                'direct_count' => count(array_filter($messages, function ($message) {
                    return !empty($message['is_direct']);
                })),
                'participant_count' => count($participants),
                'day_count' => count($days),
            ],
            'projects' => $projects,
            'active_topics' => $topics,
            'participants' => array_values($participants),
            'messages' => $messages,
        ];
    }

    private function parseProjectBullet($raw)
    {
        $parts = explode(':', $raw, 2);
        $name = trim($parts[0]);
        $summary = isset($parts[1]) ? trim($parts[1]) : '';

        return [
            'name' => $name,
            'summary' => $summary,
        ];
    }

    private function parseMessageLine($line, $index, $lineNumber = null)
    {
        if (!preg_match('/^\[(?<timestamp>[^\]]+)\](?<header>[^:]+):(?<body>.*)$/', $line, $matches)) {
            return null;
        }

        $timestamp = trim((string) $matches['timestamp']);
        $header = trim((string) $matches['header']);
        $body = trim((string) $matches['body']);

        $sender = $header;
        $target = null;
        $dashPosition = strpos($header, '-');
        if ($dashPosition !== false) {
            $sender = trim(substr($header, 0, $dashPosition));
            $target = trim(substr($header, $dashPosition + 1));
        }

        $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timestamp);
        $unixTimestamp = $dateTime ? $dateTime->getTimestamp() : null;
        $dayKey = $dateTime ? $dateTime->format('Y-m-d') : substr($timestamp, 0, 10);

        return [
            'id' => sprintf('msg-%06d', $index),
            'index' => $index,
            'source_line' => $lineNumber,
            'source_order' => $index,
            'timestamp' => $timestamp,
            'timestamp_unix' => $unixTimestamp,
            'day_key' => $dayKey,
            'sender' => $sender,
            'target' => $target ?: null,
            'targets' => $target ? $this->splitTargetNames($target) : [],
            'is_direct' => $target !== null && $target !== '',
            'body' => $body,
            'excerpt' => $this->makeExcerpt($body),
        ];
    }

    private function makeExcerpt($text, $maxLength = 180)
    {
        $normalized = preg_replace('/\s+/', ' ', trim($text));
        if ($normalized === null) {
            $normalized = '';
        }
        if (mb_strlen($normalized) <= $maxLength) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, $maxLength - 1)) . '…';
    }

    private function buildParticipants($messages, $projects)
    {
        $participants = [];
        $projectNames = array_values(array_filter(array_map(function ($project) {
            return isset($project['name']) ? trim($project['name']) : '';
        }, $projects)));
        $projectLookup = array_fill_keys($projectNames, true);

        foreach ($projectNames as $projectName) {
            $participants[$projectName] = $this->newParticipantRecord($projectName);
        }

        foreach ($messages as $message) {
            $sender = $message['sender'];
            if (!isset($participants[$sender])) {
                continue;
            }

            $participants[$sender]['message_count']++;
            $participants[$sender]['last_message_at'] = $message['timestamp'];
            if ($message['is_direct']) {
                $participants[$sender]['sent_direct_count']++;
            }

            if ($message['target']) {
                foreach ($this->resolveTargetProjects($message['target'], $projectLookup) as $target) {
                    $participants[$target]['received_direct_count']++;
                    if ($participants[$target]['last_message_at'] === null) {
                        $participants[$target]['last_message_at'] = $message['timestamp'];
                    }
                }
            }
        }

        uasort(
            $participants,
            function ($left, $right) {
                if ($left['message_count'] === $right['message_count']) {
                    return strcmp($left['name'], $right['name']);
                }

                if ($right['message_count'] > $left['message_count']) {
                    return 1;
                }

                return -1;
            }
        );

        return $participants;
    }

    private function resolveTargetProjects($target, $projectLookup)
    {
        $target = trim((string) $target);
        if ($target === '') {
            return [];
        }

        if (isset($projectLookup[$target])) {
            return [$target];
        }

        $parts = $this->splitTargetNames($target);
        $resolved = [];
        foreach ($parts as $part) {
            if ($part !== '' && isset($projectLookup[$part])) {
                $resolved[$part] = true;
            }
        }

        return array_keys($resolved);
    }

    private function splitTargetNames($target)
    {
        $parts = preg_split('/\s*(?:\/|,|;|\+|&|\band\b)\s*/i', trim((string) $target)) ?: [];
        $seen = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $seen[$part] = true;
            }
        }

        return array_keys($seen);
    }

    private function newParticipantRecord($name)
    {
        return [
            'name' => $name,
            'message_count' => 0,
            'sent_direct_count' => 0,
            'received_direct_count' => 0,
            'last_message_at' => null,
            'color_seed' => substr(sha1($name), 0, 6),
        ];
    }
}
