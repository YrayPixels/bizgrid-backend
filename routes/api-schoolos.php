<?php

use App\Http\Controllers\SchoolOsAcademicsController;
use App\Http\Controllers\SchoolOsAttendanceController;
use App\Http\Controllers\SchoolOsAuthController;
use App\Http\Controllers\SchoolOsCalendarController;
use App\Http\Controllers\SchoolOsFinanceController;
use App\Http\Controllers\SchoolOsImportController;
use App\Http\Controllers\SchoolOsMessagesController;
use App\Http\Controllers\SchoolOsSchoolController;
use App\Http\Controllers\SchoolOsTimetableController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [SchoolOsAuthController::class, 'register']);
    Route::post('/login', [SchoolOsAuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [SchoolOsAuthController::class, 'me']);
        Route::post('/logout', [SchoolOsAuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->prefix('schools')->group(function () {
    Route::post('/check-slug', [SchoolOsSchoolController::class, 'checkSlug']);
    Route::get('/mine', [SchoolOsSchoolController::class, 'mine']);
    Route::post('/by-slug', [SchoolOsSchoolController::class, 'bySlug']);
    Route::post('/', [SchoolOsSchoolController::class, 'store']);

    Route::prefix('{school}')->group(function () {
        Route::post('/onboarding-import', [SchoolOsImportController::class, 'store']);

        Route::get('/students', [SchoolOsSchoolController::class, 'students']);
        Route::post('/students', [SchoolOsSchoolController::class, 'storeStudent']);
        Route::patch('/students', [SchoolOsSchoolController::class, 'updateStudent']);
        Route::delete('/students/{student}', [SchoolOsSchoolController::class, 'deleteStudent']);

        Route::get('/employees', [SchoolOsSchoolController::class, 'employees']);
        Route::post('/employees', [SchoolOsSchoolController::class, 'storeEmployee']);
        Route::patch('/employees', [SchoolOsSchoolController::class, 'updateEmployee']);
        Route::delete('/employees/{employee}', [SchoolOsSchoolController::class, 'deleteEmployee']);
        Route::get('/employee-roles', [SchoolOsSchoolController::class, 'employeeRoles']);
        Route::post('/employee-roles', [SchoolOsSchoolController::class, 'storeEmployeeRole']);
        Route::get('/employee-departments', [SchoolOsSchoolController::class, 'employeeDepartments']);
        Route::post('/employee-departments', [SchoolOsSchoolController::class, 'storeEmployeeDepartment']);

        Route::get('/sessions', [SchoolOsAcademicsController::class, 'sessions']);
        Route::post('/sessions', [SchoolOsAcademicsController::class, 'storeSession']);
        Route::patch('/sessions/{session}', [SchoolOsAcademicsController::class, 'updateSession']);

        Route::get('/classes', [SchoolOsAcademicsController::class, 'classes']);
        Route::post('/classes', [SchoolOsAcademicsController::class, 'storeClass']);
        Route::patch('/classes/{schoolClass}', [SchoolOsAcademicsController::class, 'updateClass']);
        Route::delete('/classes/{schoolClass}', [SchoolOsAcademicsController::class, 'deleteClass']);

        Route::get('/subjects', [SchoolOsAcademicsController::class, 'subjects']);
        Route::post('/subjects', [SchoolOsAcademicsController::class, 'storeSubject']);
        Route::patch('/subjects/{subject}', [SchoolOsAcademicsController::class, 'updateSubject']);
        Route::delete('/subjects/{subject}', [SchoolOsAcademicsController::class, 'deleteSubject']);

        Route::get('/terms', [SchoolOsAcademicsController::class, 'terms']);
        Route::post('/terms', [SchoolOsAcademicsController::class, 'storeTerm']);
        Route::patch('/terms/{term}', [SchoolOsAcademicsController::class, 'updateTerm']);
        Route::delete('/terms/{term}', [SchoolOsAcademicsController::class, 'deleteTerm']);

        Route::get('/events', [SchoolOsCalendarController::class, 'index']);
        Route::post('/events', [SchoolOsCalendarController::class, 'store']);
        Route::patch('/events/{event}', [SchoolOsCalendarController::class, 'update']);
        Route::delete('/events/{event}', [SchoolOsCalendarController::class, 'destroy']);

        Route::get('/timetable-periods', [SchoolOsTimetableController::class, 'index']);
        Route::post('/timetable-periods', [SchoolOsTimetableController::class, 'store']);
        Route::patch('/timetable-periods/{period}', [SchoolOsTimetableController::class, 'update']);
        Route::delete('/timetable-periods/{period}', [SchoolOsTimetableController::class, 'destroy']);

        Route::get('/messages', [SchoolOsMessagesController::class, 'index']);
        Route::post('/messages', [SchoolOsMessagesController::class, 'store']);

        Route::get('/class-subjects', [SchoolOsAcademicsController::class, 'classSubjects']);
        Route::post('/class-subjects', [SchoolOsAcademicsController::class, 'assignClassSubject']);
        Route::delete('/class-subjects/{assignment}', [SchoolOsAcademicsController::class, 'unassignClassSubject']);

        Route::get('/enrollments', [SchoolOsAcademicsController::class, 'enrollments']);
        Route::post('/enrollments', [SchoolOsAcademicsController::class, 'enrollStudent']);

        Route::get('/attendance/summary', [SchoolOsAttendanceController::class, 'summary']);
        Route::get('/attendance/classes', [SchoolOsAttendanceController::class, 'classesWithCounts']);
        Route::get('/attendance', [SchoolOsAttendanceController::class, 'index']);
        Route::post('/attendance', [SchoolOsAttendanceController::class, 'store']);

        Route::get('/finance/summary', [SchoolOsFinanceController::class, 'summary']);
        Route::get('/fee-categories', [SchoolOsFinanceController::class, 'feeCategories']);
        Route::post('/fee-categories', [SchoolOsFinanceController::class, 'storeFeeCategory']);
        Route::patch('/fee-categories/{feeCategory}', [SchoolOsFinanceController::class, 'updateFeeCategory']);
        Route::delete('/fee-categories/{feeCategory}', [SchoolOsFinanceController::class, 'deleteFeeCategory']);

        Route::get('/fee-templates', [SchoolOsFinanceController::class, 'feeTemplates']);
        Route::post('/fee-templates', [SchoolOsFinanceController::class, 'storeFeeTemplate']);
        Route::patch('/fee-templates/{feeTemplate}', [SchoolOsFinanceController::class, 'updateFeeTemplate']);
        Route::delete('/fee-templates/{feeTemplate}', [SchoolOsFinanceController::class, 'deleteFeeTemplate']);
        Route::post('/fee-assignments', [SchoolOsFinanceController::class, 'assignFeeTemplate']);

        Route::get('/invoices', [SchoolOsFinanceController::class, 'invoices']);
        Route::post('/invoices', [SchoolOsFinanceController::class, 'storeInvoice']);
        Route::patch('/invoices/{invoice}', [SchoolOsFinanceController::class, 'updateInvoice']);
        Route::delete('/invoices/{invoice}', [SchoolOsFinanceController::class, 'deleteInvoice']);

        Route::get('/payments', [SchoolOsFinanceController::class, 'payments']);
        Route::post('/invoices/{invoice}/payments', [SchoolOsFinanceController::class, 'storePayment']);
    });
});
