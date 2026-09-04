<?php

namespace App\Services;

use App\Models\UserCertificate;
use App\Models\UserContentEnrollment;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CertificateService
{
    public function issueFor(UserContentEnrollment $enrollment): ?UserCertificate
    {
        if (! Schema::hasTable('user_certificates')
            || ! $enrollment->completed_at
            || ! $enrollment->content?->isCourse()
            || ! $enrollment->content->courseDetails?->has_certificate) {
            return null;
        }

        return UserCertificate::firstOrCreate(
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
    }
}
