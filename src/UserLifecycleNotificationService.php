<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/SettingsService.php';
require_once __DIR__ . '/EmailNotificationService.php';
require_once __DIR__ . '/TimezoneService.php';

final class UserLifecycleNotificationService
{
    private $pdo;
    private $settings;
    private $email;

    public function __construct(PDO $pdo, $captureDirectory = null)
    {
        $this->pdo = $pdo;
        $this->settings = new SettingsService($pdo);
        $this->email = new EmailNotificationService($captureDirectory);
    }

    public function requireRegistrationDeliveryConfiguration()
    {
        if (!$this->settings->get('mail.enabled')) {
            throw new RuntimeException('Registration email delivery is not configured.');
        }
        if (!filter_var($this->settings->get('mail.sender_address'), FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Registration email delivery is not configured.');
        }
        $origin = rtrim(trim((string) $this->settings->get('general.public_origin')), '/');
        $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
        if ($origin === '' || !filter_var($origin, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Registration email delivery is not configured.');
        }
        return $origin;
    }

    public function sendActivation($userId, $token, $expiresAt)
    {
        $origin = $this->requireRegistrationDeliveryConfiguration();
        $user = $this->user($userId);
        $timezone = TimezoneService::resolve($user['timezone'], $this->settings->get('general.default_timezone'));
        return $this->deliver('registration_activation', $user, [
            'display_name' => $user['display_name'],
            'activation_url' => $origin . '/#activate=' . rawurlencode((string) $token),
            'expires_at' => (string) $expiresAt . ' UTC',
            'timezone' => $timezone,
        ]);
    }

    public function sendWelcome($userId)
    {
        if (!$this->settings->get('mail.enabled')) {
            return ['status' => 'skipped', 'reason' => 'mail_disabled'];
        }
        $origin = rtrim(trim((string) $this->settings->get('general.public_origin')), '/');
        $senderAddress = trim((string) $this->settings->get('mail.sender_address'));
        $user = $this->user($userId);
        $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
        if ($origin === '' || !filter_var($origin, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)
            || !filter_var($senderAddress, FILTER_VALIDATE_EMAIL)
            || !filter_var($user['normalized_email'], FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'skipped', 'reason' => 'mail_unavailable'];
        }
        return $this->deliver('welcome', $user, [
            'display_name' => $user['display_name'],
            'application_url' => $origin . '/',
        ]);
    }

    public function retryWelcomeIfScheduled($userId)
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM user_lifecycle_notifications WHERE user_id = ? AND event_code = 'welcome'"
        );
        $statement->execute([(int) $userId]);
        if ((int) $statement->fetchColumn() === 0) {
            return ['status' => 'not_scheduled'];
        }
        return $this->sendWelcome($userId);
    }

    private function deliver($eventCode, array $user, array $data)
    {
        $userId = (int) $user['id'];
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare(
                'SELECT id, status FROM user_lifecycle_notifications WHERE user_id = ? AND event_code = ? FOR UPDATE'
            );
            $existing->execute([$userId, $eventCode]);
            $row = $existing->fetch();
            if ($row && $row['status'] === 'succeeded') {
                $this->pdo->commit();
                return ['status' => 'already_sent'];
            }
            if ($row && $row['status'] === 'sending') {
                $this->pdo->commit();
                return ['status' => 'pending'];
            }
            if ($row) {
                $this->pdo->prepare(
                    "UPDATE user_lifecycle_notifications SET status = 'sending', attempt_count = attempt_count + 1,
                     last_error = NULL, updated_at = ? WHERE id = ?"
                )->execute([$now, $row['id']]);
            } else {
                $this->pdo->prepare(
                    "INSERT INTO user_lifecycle_notifications
                     (user_id, event_code, status, attempt_count, created_at, updated_at)
                     VALUES (?, ?, 'sending', 1, ?, ?)"
                )->execute([$userId, $eventCode, $now, $now]);
            }
            $this->pdo->commit();
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }

        try {
            $envelope = [
                'to_name' => $user['display_name'],
                'to_email' => $user['normalized_email'],
                'from_name' => (string) $this->settings->get('mail.sender_name'),
                'from_email' => (string) $this->settings->get('mail.sender_address'),
                'reply_to' => (string) $this->settings->get('mail.reply_to_address'),
            ];
            $delivery = $eventCode === 'registration_activation'
                ? $this->email->sendRegistrationActivation($envelope, $data)
                : $this->email->sendWelcome($envelope, $data);
            $this->pdo->prepare(
                "UPDATE user_lifecycle_notifications SET status = 'succeeded', delivered_at = ?, last_error = NULL,
                 updated_at = ? WHERE user_id = ? AND event_code = ? AND status = 'sending'"
            )->execute([$now, $now, $userId, $eventCode]);
            return $delivery;
        } catch (Exception $exception) {
            $this->pdo->prepare(
                "UPDATE user_lifecycle_notifications SET status = 'failed', last_error = ?, updated_at = ?
                 WHERE user_id = ? AND event_code = ? AND status = 'sending'"
            )->execute([substr($exception->getMessage(), 0, 500), Db::now(), $userId, $eventCode]);
            throw $exception;
        }
    }

    private function user($userId)
    {
        $statement = $this->pdo->prepare(
            "SELECT id, normalized_email, display_name, timezone FROM users
             WHERE id = ? AND status = 'active' AND deleted_at IS NULL LIMIT 1"
        );
        $statement->execute([(int) $userId]);
        $user = $statement->fetch();
        if (!$user || !filter_var($user['normalized_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Lifecycle email recipient is unavailable.');
        }
        return $user;
    }
}
