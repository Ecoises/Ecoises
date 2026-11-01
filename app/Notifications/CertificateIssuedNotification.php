<?php

namespace App\Notifications;

use App\Models\UserCertificate;

class CertificateIssuedNotification extends EcoNotification
{
    public function __construct(public UserCertificate $certificate) {}

    public function type(): string
    {
        return 'learning.certificate';
    }

    protected function message(object $notifiable): array
    {
        $course = $this->certificate->content?->title;

        return [
            'category' => 'learning',
            'title' => 'Tu certificado está listo',
            'body' => $course ? "Completaste «{$course}». ¡Felicitaciones!" : '¡Felicitaciones por terminar el curso!',
            'action_url' => "/certificates/{$this->certificate->verification_code}",
            'meta' => ['certificate_id' => $this->certificate->id],
        ];
    }
}
