<?php

namespace App\Services\Learning;

use App\Models\UserCertificate;
use App\Models\UserContentEnrollment;
use App\Notifications\CertificateIssuedNotification;
use App\Services\Notifications\UserNotifier;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CertificateService
{
    public function __construct(private readonly UserNotifier $notifier) {}

    public function issueFor(UserContentEnrollment $enrollment): ?UserCertificate
    {
        if (! Schema::hasTable('user_certificates')
            || ! $enrollment->completed_at
            || ! $enrollment->content?->isCourse()
            || ! $enrollment->content->courseDetails?->has_certificate) {
            return null;
        }

        $certificate = UserCertificate::firstOrCreate(
            [
                'user_id' => $enrollment->user_id,
                'content_id' => $enrollment->content_id,
            ],
            [
                'verification_code' => (string) Str::uuid(),
                'enrollment_id' => $enrollment->id,
                'final_score' => $enrollment->final_score,
                'issued_at' => now(),
            ],
        );

        if ($certificate->wasRecentlyCreated) {
            $this->notifier->send($enrollment->user, new CertificateIssuedNotification($certificate));
        }

        return $certificate;
    }
}
