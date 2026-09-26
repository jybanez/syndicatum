<?php

require_once __DIR__ . '/EmailTemplateRenderer.php';
require_once __DIR__ . '/DevelopmentEmailTransport.php';

final class EmailNotificationService
{
    private $renderer;
    private $transport;

    public function __construct($captureDirectory = null)
    {
        $this->renderer = new EmailTemplateRenderer();
        $this->transport = new DevelopmentEmailTransport($captureDirectory);
    }

    public function sendProjectInvitation(array $envelope, array $data)
    {
        foreach (['to_email', 'from_email'] as $key) {
            if (!filter_var(isset($envelope[$key]) ? $envelope[$key] : '', FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Email envelope contains an invalid address.');
            }
        }
        $message = $this->renderer->render('project_invitation', $data);
        return $this->transport->send($envelope, $message);
    }
}
