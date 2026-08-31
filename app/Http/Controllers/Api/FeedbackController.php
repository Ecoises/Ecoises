<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EducationalContent;
use App\Models\Report;
use App\Models\UserContentEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeedbackController extends Controller
{
    public function storeGeneral(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'comment' => ['required', 'string', 'max:3000'],
            'category' => ['required', 'string', 'in:suggestion,improvement,technical_issue,accessibility,other'],
            'context' => ['nullable', 'array'],
            'context.page' => ['nullable', 'string', 'max:500'],
        ]);

        $report = Report::create([
            'user_id' => $request->user()->id,
            'type' => Report::TYPE_GENERAL_FEEDBACK,
            'category' => $validated['category'],
            'subject' => $validated['subject'],
            'comment' => $validated['comment'],
            'status' => Report::STATUS_PENDING,
            'priority' => Report::PRIORITY_NORMAL,
            'metadata' => $validated['context'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Gracias. Tu mensaje fue enviado al equipo de Ecoises.',
            'data' => ['id' => $report->id, 'status' => $report->status],
        ], 201);
    }

    public function storeContent(Request $request, int $id): JsonResponse
    {
        $content = EducationalContent::published()->findOrFail($id);
        $validated = $request->validate([
            'rating' => ['nullable', 'integer', 'between:1,5', 'required_without:comment'],
            'comment' => ['nullable', 'string', 'max:3000', 'required_without:rating'],
        ]);

        DB::transaction(function () use ($request, $content, $validated): void {
            UserContentEnrollment::updateOrCreate(
                ['user_id' => $request->user()->id, 'content_id' => $content->id],
                [
                    'user_rating' => $validated['rating'] ?? null,
                    'user_feedback' => $validated['comment'] ?? null,
                    'last_accessed_at' => now(),
                ],
            );

            $ratings = UserContentEnrollment::query()
                ->where('content_id', $content->id)
                ->whereNotNull('user_rating');

            $content->update([
                'rating_average' => round((float) $ratings->avg('user_rating'), 2),
                'rating_count' => $ratings->count(),
            ]);

            if (filled($validated['comment'] ?? null)) {
                $report = Report::query()
                    ->where('user_id', $request->user()->id)
                    ->where('reportable_type', EducationalContent::class)
                    ->where('reportable_id', $content->id)
                    ->where('type', Report::TYPE_CONTENT_FEEDBACK)
                    ->open()
                    ->firstOrNew();

                $report->fill([
                    'user_id' => $request->user()->id,
                    'reportable_type' => EducationalContent::class,
                    'reportable_id' => $content->id,
                    'type' => Report::TYPE_CONTENT_FEEDBACK,
                    'status' => $report->exists ? $report->status : Report::STATUS_PENDING,
                    'category' => 'content_experience',
                    'subject' => $content->title,
                    'comment' => $validated['comment'],
                    'priority' => Report::PRIORITY_NORMAL,
                    'metadata' => ['rating' => $validated['rating'] ?? null],
                ])->save();
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Gracias por ayudarnos a mejorar este contenido.',
        ]);
    }
}
