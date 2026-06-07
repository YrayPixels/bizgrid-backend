<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSchoolAccess;
use App\Models\School;
use App\Models\SchoolMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class SchoolOsMessagesController extends Controller
{
    use AuthorizesSchoolAccess;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school);

        $messages = $school->messages()
            ->with('creator:id,name,email')
            ->latest('sent_at')
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['messages' => $messages]);
    }

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);

        $data = $request->validate([
            'channel' => ['required', Rule::in(['sms', 'whatsapp'])],
            'audience' => ['required', Rule::in(['parents', 'teachers', 'all'])],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $recipients = $this->recipientsForAudience($school, $data['audience']);

        if ($recipients->isEmpty()) {
            return response()->json([
                'message' => 'No recipients with phone numbers were found for this audience.',
            ], 422);
        }

        $message = $school->messages()->create([
            'channel' => $data['channel'],
            'audience' => $data['audience'],
            'title' => $data['title'],
            'body' => $data['body'],
            'recipient_count' => $recipients->count(),
            'recipients' => $recipients->values()->all(),
            'status' => 'sent',
            'sent_at' => now(),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['message' => $message->load('creator:id,name,email')], 201);
    }

    private function recipientsForAudience(School $school, string $audience): Collection
    {
        $recipients = collect();

        if ($audience === 'parents' || $audience === 'all') {
            $parentRecipients = $school->students()
                ->whereNotNull('guardian_phone')
                ->where('guardian_phone', '<>', '')
                ->where('status', 'enrolled')
                ->get(['id', 'first_name', 'last_name', 'guardian_name', 'guardian_phone'])
                ->map(function ($student) {
                    return [
                        'type' => 'parent',
                        'name' => $student->guardian_name ?: trim("{$student->first_name} {$student->last_name} parent"),
                        'phone' => $student->guardian_phone,
                        'student_id' => $student->id,
                        'student_name' => trim("{$student->first_name} {$student->last_name}"),
                    ];
                });

            $recipients = $recipients->concat($parentRecipients);
        }

        if ($audience === 'teachers' || $audience === 'all') {
            $teacherRecipients = $school->employees()
                ->whereNotNull('phone')
                ->where('phone', '<>', '')
                ->where('status', 'active')
                ->get(['id', 'first_name', 'last_name', 'phone', 'role'])
                ->map(function ($employee) {
                    return [
                        'type' => 'teacher',
                        'name' => trim("{$employee->first_name} {$employee->last_name}"),
                        'phone' => $employee->phone,
                        'employee_id' => $employee->id,
                        'role' => $employee->role,
                    ];
                });

            $recipients = $recipients->concat($teacherRecipients);
        }

        return $recipients
            ->filter(fn (array $recipient) => trim((string) $recipient['phone']) !== '')
            ->unique(fn (array $recipient) => $this->normalizePhone($recipient['phone']))
            ->values();
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: $phone;
    }
}
