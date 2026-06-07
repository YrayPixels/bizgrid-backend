<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSchoolAccess;
use App\Models\FeeAssignment;
use App\Models\FeeCategory;
use App\Models\FeeTemplate;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SchoolOsFinanceController extends Controller
{
    use AuthorizesSchoolAccess;

    public function summary(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);

        $data = $request->validate([
            'academicSessionId' => [
                'nullable',
                'integer',
                Rule::exists('academic_sessions', 'id')->where('school_id', $school->id),
            ],
            'academicTermId' => [
                'nullable',
                'integer',
                Rule::exists('academic_terms', 'id')->where('school_id', $school->id),
            ],
        ]);

        $invoiceScope = Invoice::query()
            ->where('school_id', $school->id)
            ->when(! empty($data['academicSessionId']), fn ($q) => $q->where('academic_session_id', $data['academicSessionId']))
            ->when(! empty($data['academicTermId']), fn ($q) => $q->where('academic_term_id', $data['academicTermId']));

        $collected = InvoicePayment::query()
            ->where('school_id', $school->id)
            ->whereHas('invoice', function ($q) use ($data) {
                $q->when(! empty($data['academicSessionId']), fn ($query) => $query->where('academic_session_id', $data['academicSessionId']))
                    ->when(! empty($data['academicTermId']), fn ($query) => $query->where('academic_term_id', $data['academicTermId']));
            })
            ->sum('amount');
        $invoiced = (clone $invoiceScope)
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->sum('amount');
        $paidInvoiceIds = InvoicePayment::query()
            ->where('school_id', $school->id)
            ->whereHas('invoice', function ($q) use ($data) {
                $q->when(! empty($data['academicSessionId']), fn ($query) => $query->where('academic_session_id', $data['academicSessionId']))
                    ->when(! empty($data['academicTermId']), fn ($query) => $query->where('academic_term_id', $data['academicTermId']));
            })
            ->select('invoice_id', DB::raw('sum(amount) as paid'))
            ->groupBy('invoice_id')
            ->pluck('paid', 'invoice_id');

        $outstanding = (clone $invoiceScope)
            ->whereNotIn('status', ['cancelled', 'draft', 'paid'])
            ->get()
            ->sum(function (Invoice $invoice) use ($paidInvoiceIds) {
                $paid = (float) ($paidInvoiceIds[$invoice->id] ?? 0);

                return max(0, (float) $invoice->amount - $paid);
            });

        $rate = $invoiced > 0 ? round(((float) $collected / (float) $invoiced) * 100, 1) : null;

        return response()->json([
            'collected' => (float) $collected,
            'outstanding' => (float) $outstanding,
            'invoiced' => (float) $invoiced,
            'collection_rate' => $rate,
        ]);
    }

    public function feeCategories(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);

        $categories = $school->feeCategories()->orderBy('name')->get();

        return response()->json(['fee_categories' => $categories]);
    }

    public function storeFeeCategory(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('fee_categories', 'name')->where('school_id', $school->id),
            ],
            'amount' => ['required', 'numeric', 'min:0'],
            'billingCycle' => ['required', Rule::in(['term', 'monthly', 'one_time'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $category = $school->feeCategories()->create([
            'name' => $data['name'],
            'amount' => $data['amount'],
            'billing_cycle' => $data['billingCycle'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['isActive'] ?? true,
        ]);

        return response()->json(['fee_category' => $category], 201);
    }

    public function updateFeeCategory(Request $request, School $school, FeeCategory $feeCategory): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);
        abort_unless((int) $feeCategory->school_id === (int) $school->id, 404);

        $data = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:80',
                Rule::unique('fee_categories', 'name')->where('school_id', $school->id)->ignore($feeCategory->id),
            ],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'billingCycle' => ['sometimes', Rule::in(['term', 'monthly', 'one_time'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $feeCategory->update([
            'name' => $data['name'] ?? $feeCategory->name,
            'amount' => $data['amount'] ?? $feeCategory->amount,
            'billing_cycle' => $data['billingCycle'] ?? $feeCategory->billing_cycle,
            'description' => array_key_exists('description', $data) ? $data['description'] : $feeCategory->description,
            'is_active' => $data['isActive'] ?? $feeCategory->is_active,
        ]);

        return response()->json(['fee_category' => $feeCategory->fresh()]);
    }

    public function deleteFeeCategory(Request $request, School $school, FeeCategory $feeCategory): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);
        abort_unless((int) $feeCategory->school_id === (int) $school->id, 404);

        $feeCategory->delete();

        return response()->json(['ok' => true]);
    }

    public function feeTemplates(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);

        $templates = $school->feeTemplates()
            ->with('feeCategory:id,name')
            ->orderBy('name')
            ->get();

        return response()->json(['fee_templates' => $templates]);
    }

    public function storeFeeTemplate(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);

        $data = $request->validate([
            'feeCategoryId' => [
                'nullable',
                'integer',
                Rule::exists('fee_categories', 'id')->where('school_id', $school->id),
            ],
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('fee_templates', 'name')->where('school_id', $school->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'isRecurring' => ['nullable', 'boolean'],
            'isOptional' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $template = $school->feeTemplates()->create([
            'fee_category_id' => $data['feeCategoryId'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency'] ?? 'NGN'),
            'is_recurring' => $data['isRecurring'] ?? false,
            'is_optional' => $data['isOptional'] ?? false,
            'status' => $data['status'] ?? 'active',
        ]);

        return response()->json(['fee_template' => $template->load('feeCategory:id,name')], 201);
    }

    public function updateFeeTemplate(Request $request, School $school, FeeTemplate $feeTemplate): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);
        abort_unless((int) $feeTemplate->school_id === (int) $school->id, 404);

        $data = $request->validate([
            'feeCategoryId' => [
                'nullable',
                'integer',
                Rule::exists('fee_categories', 'id')->where('school_id', $school->id),
            ],
            'name' => [
                'sometimes',
                'string',
                'max:120',
                Rule::unique('fee_templates', 'name')->where('school_id', $school->id)->ignore($feeTemplate->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'isRecurring' => ['nullable', 'boolean'],
            'isOptional' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $feeTemplate->update([
            'fee_category_id' => array_key_exists('feeCategoryId', $data) ? $data['feeCategoryId'] : $feeTemplate->fee_category_id,
            'name' => $data['name'] ?? $feeTemplate->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $feeTemplate->description,
            'amount' => $data['amount'] ?? $feeTemplate->amount,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $feeTemplate->currency,
            'is_recurring' => $data['isRecurring'] ?? $feeTemplate->is_recurring,
            'is_optional' => $data['isOptional'] ?? $feeTemplate->is_optional,
            'status' => $data['status'] ?? $feeTemplate->status,
        ]);

        return response()->json(['fee_template' => $feeTemplate->fresh()->load('feeCategory:id,name')]);
    }

    public function deleteFeeTemplate(Request $request, School $school, FeeTemplate $feeTemplate): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);
        abort_unless((int) $feeTemplate->school_id === (int) $school->id, 404);

        $feeTemplate->delete();

        return response()->json(['ok' => true]);
    }

    public function assignFeeTemplate(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);

        $data = $request->validate([
            'feeTemplateId' => [
                'required',
                'integer',
                Rule::exists('fee_templates', 'id')->where('school_id', $school->id),
            ],
            'academicSessionId' => [
                'required',
                'integer',
                Rule::exists('academic_sessions', 'id')->where('school_id', $school->id),
            ],
            'academicTermId' => [
                'nullable',
                'integer',
                Rule::exists('academic_terms', 'id')->where('school_id', $school->id),
            ],
            'assignmentType' => ['required', Rule::in(['school', 'class', 'student'])],
            'assignmentId' => ['nullable', 'integer'],
            'dueDate' => ['required', 'date'],
        ]);

        $template = FeeTemplate::where('school_id', $school->id)->findOrFail($data['feeTemplateId']);
        $studentIds = $this->assignedStudentIds($school, $data);

        if ($studentIds->isEmpty()) {
            return response()->json(['message' => 'No enrolled students match this fee assignment.'], 422);
        }

        $result = DB::transaction(function () use ($request, $school, $template, $data, $studentIds) {
            $assignment = FeeAssignment::create([
                'school_id' => $school->id,
                'fee_template_id' => $template->id,
                'academic_session_id' => $data['academicSessionId'],
                'academic_term_id' => $data['academicTermId'] ?? null,
                'assignment_type' => $data['assignmentType'],
                'assignment_id' => $data['assignmentId'] ?? null,
                'due_date' => $data['dueDate'],
                'created_by' => $request->user()->id,
            ]);

            $created = 0;

            foreach ($studentIds as $studentId) {
                $invoice = $school->invoices()->create([
                    'student_id' => $studentId,
                    'fee_category_id' => $template->fee_category_id,
                    'academic_session_id' => $data['academicSessionId'],
                    'academic_term_id' => $data['academicTermId'] ?? null,
                    'fee_assignment_id' => $assignment->id,
                    'invoice_number' => $this->nextInvoiceNumber($school),
                    'amount' => $template->amount,
                    'due_date' => $data['dueDate'],
                    'status' => 'pending',
                    'notes' => $template->name,
                    'created_by' => $request->user()->id,
                ]);

                $invoice->items()->create([
                    'fee_template_id' => $template->id,
                    'description' => $template->name,
                    'amount' => $template->amount,
                ]);

                $created++;
            }

            return ['assignment' => $assignment, 'invoices_created' => $created];
        });

        return response()->json($result, 201);
    }

    public function invoices(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);

        $data = $request->validate([
            'academicSessionId' => [
                'nullable',
                'integer',
                Rule::exists('academic_sessions', 'id')->where('school_id', $school->id),
            ],
            'academicTermId' => [
                'nullable',
                'integer',
                Rule::exists('academic_terms', 'id')->where('school_id', $school->id),
            ],
        ]);

        $invoices = $school->invoices()
            ->when(! empty($data['academicSessionId']), fn ($q) => $q->where('academic_session_id', $data['academicSessionId']))
            ->when(! empty($data['academicTermId']), fn ($q) => $q->where('academic_term_id', $data['academicTermId']))
            ->with([
                'student:id,first_name,last_name,admission_number',
                'feeCategory:id,name',
                'academicSession:id,name',
                'academicTerm:id,name',
                'items:id,invoice_id,description,amount,fee_template_id',
            ])
            ->withSum('payments as paid_total', 'amount')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn (Invoice $invoice) => $this->invoicePayload($invoice));

        return response()->json(['invoices' => $invoices]);
    }

    public function storeInvoice(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);

        $data = $request->validate([
            'studentId' => [
                'required',
                'integer',
                Rule::exists('students', 'id')->where('school_id', $school->id),
            ],
            'feeCategoryId' => [
                'nullable',
                'integer',
                Rule::exists('fee_categories', 'id')->where('school_id', $school->id),
            ],
            'academicSessionId' => [
                'nullable',
                'integer',
                Rule::exists('academic_sessions', 'id')->where('school_id', $school->id),
            ],
            'academicTermId' => [
                'nullable',
                'integer',
                Rule::exists('academic_terms', 'id')->where('school_id', $school->id),
            ],
            'amount' => ['required', 'numeric', 'min:0'],
            'dueDate' => ['required', 'date'],
            'status' => ['required', Rule::in(['draft', 'pending', 'paid', 'partial', 'overdue', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $invoiceNumber = $this->nextInvoiceNumber($school);

        $invoice = $school->invoices()->create([
            'student_id' => $data['studentId'],
            'fee_category_id' => $data['feeCategoryId'] ?? null,
            'academic_session_id' => $data['academicSessionId'] ?? null,
            'academic_term_id' => $data['academicTermId'] ?? null,
            'invoice_number' => $invoiceNumber,
            'amount' => $data['amount'],
            'due_date' => $data['dueDate'],
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'invoice' => $this->invoicePayload($invoice->load(['student', 'feeCategory', 'academicSession', 'academicTerm', 'items'])),
        ], 201);
    }

    public function updateInvoice(Request $request, School $school, Invoice $invoice): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);
        abort_unless((int) $invoice->school_id === (int) $school->id, 404);

        $data = $request->validate([
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'dueDate' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::in(['draft', 'pending', 'paid', 'partial', 'overdue', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $invoice->update([
            'amount' => $data['amount'] ?? $invoice->amount,
            'due_date' => $data['dueDate'] ?? $invoice->due_date,
            'status' => $data['status'] ?? $invoice->status,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $invoice->notes,
        ]);

        return response()->json([
            'invoice' => $this->invoicePayload($invoice->fresh()->load(['student', 'feeCategory', 'academicSession', 'academicTerm', 'items'])->loadSum('payments as paid_total', 'amount')),
        ]);
    }

    public function deleteInvoice(Request $request, School $school, Invoice $invoice): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);
        abort_unless((int) $invoice->school_id === (int) $school->id, 404);

        $invoice->delete();

        return response()->json(['ok' => true]);
    }

    public function storePayment(Request $request, School $school, Invoice $invoice): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);
        abort_unless((int) $invoice->school_id === (int) $school->id, 404);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paidAt' => ['required', 'date'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'online', 'other'])],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payment = DB::transaction(function () use ($request, $school, $invoice, $data) {
            $payment = InvoicePayment::create([
                'school_id' => $school->id,
                'invoice_id' => $invoice->id,
                'amount' => $data['amount'],
                'paid_at' => $data['paidAt'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $request->user()->id,
            ]);

            $paidTotal = (float) $invoice->payments()->sum('amount');
            if ($paidTotal >= (float) $invoice->amount) {
                $invoice->update(['status' => 'paid']);
            } elseif ($paidTotal > 0) {
                $invoice->update(['status' => 'partial']);
            }

            return $payment;
        });

        return response()->json(['payment' => $payment], 201);
    }

    public function payments(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::FINANCE_ROLES);

        $payments = InvoicePayment::query()
            ->where('school_id', $school->id)
            ->with(['invoice:id,invoice_number,student_id', 'invoice.student:id,first_name,last_name'])
            ->orderByDesc('paid_at')
            ->limit(100)
            ->get();

        return response()->json(['payments' => $payments]);
    }

    private function nextInvoiceNumber(School $school): string
    {
        $count = $school->invoices()->count() + 1;

        return 'INV-'.str_pad((string) $count, 5, '0', STR_PAD_LEFT);
    }

    private function assignedStudentIds(School $school, array $data)
    {
        if ($data['assignmentType'] === 'student') {
            $studentId = $data['assignmentId'] ?? null;
            abort_unless($studentId && Student::where('school_id', $school->id)->where('id', $studentId)->exists(), 422);

            return collect([(int) $studentId]);
        }

        $query = StudentEnrollment::query()
            ->where('school_id', $school->id)
            ->where('academic_session_id', $data['academicSessionId'])
            ->where('status', 'active');

        if ($data['assignmentType'] === 'class') {
            $classId = $data['assignmentId'] ?? null;
            abort_unless($classId, 422);
            $query->where('school_class_id', $classId);
        }

        return $query->pluck('student_id')->unique()->values();
    }

    private function invoicePayload(Invoice $invoice): array
    {
        return [
            'id' => (string) $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'student_id' => (string) $invoice->student_id,
            'student' => $invoice->relationLoaded('student') ? [
                'id' => (string) $invoice->student->id,
                'first_name' => $invoice->student->first_name,
                'last_name' => $invoice->student->last_name,
                'admission_number' => $invoice->student->admission_number,
            ] : null,
            'fee_category_id' => $invoice->fee_category_id ? (string) $invoice->fee_category_id : null,
            'fee_category' => $invoice->relationLoaded('feeCategory') && $invoice->feeCategory ? [
                'id' => (string) $invoice->feeCategory->id,
                'name' => $invoice->feeCategory->name,
            ] : null,
            'academic_session' => $invoice->relationLoaded('academicSession') && $invoice->academicSession ? [
                'id' => (string) $invoice->academicSession->id,
                'name' => $invoice->academicSession->name,
            ] : null,
            'academic_term' => $invoice->relationLoaded('academicTerm') && $invoice->academicTerm ? [
                'id' => (string) $invoice->academicTerm->id,
                'name' => $invoice->academicTerm->name,
            ] : null,
            'items' => $invoice->relationLoaded('items') ? $invoice->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'description' => $item->description,
                'amount' => (float) $item->amount,
                'fee_template_id' => $item->fee_template_id ? (string) $item->fee_template_id : null,
            ])->values() : [],
            'amount' => (float) $invoice->amount,
            'due_date' => $invoice->due_date?->toDateString(),
            'status' => $invoice->status,
            'notes' => $invoice->notes,
            'paid_total' => (float) ($invoice->paid_total ?? $invoice->payments()->sum('amount')),
            'created_at' => $invoice->created_at?->toIso8601String(),
        ];
    }
}
