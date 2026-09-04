<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserCertificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $certificates = $request->user()->certificates()
            ->whereNull('revoked_at')
            ->with('content:id,title,slug')
            ->latest('issued_at')
            ->get()
            ->map(fn (UserCertificate $certificate): array => $this->payload($certificate));

        return response()->json($certificates);
    }

    public function show(string $verificationCode): JsonResponse
    {
        $certificate = UserCertificate::query()
            ->where('verification_code', $verificationCode)
            ->with(['user:id,full_name', 'content:id,title,slug,estimated_duration'])
            ->firstOrFail();

        return response()->json($this->payload($certificate));
    }

    private function payload(UserCertificate $certificate): array
    {
        return [
            'verification_code' => $certificate->verification_code,
            'is_valid' => $certificate->revoked_at === null,
            'learner_name' => $certificate->user?->full_name,
            'content_title' => $certificate->content?->title,
            'content_slug' => $certificate->content?->slug,
            'final_score' => $certificate->final_score,
            'issued_at' => $certificate->issued_at,
            'revoked_at' => $certificate->revoked_at,
        ];
    }
}
