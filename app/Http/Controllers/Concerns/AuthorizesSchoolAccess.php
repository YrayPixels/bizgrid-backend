<?php

namespace App\Http\Controllers\Concerns;

use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait AuthorizesSchoolAccess
{
    /** @var list<string> */
    public const ADMIN_ROLES = ['owner', 'principal', 'administrator'];

    /** @var list<string> */
    public const STAFF_ROLES = ['owner', 'principal', 'administrator', 'teacher', 'accountant'];

    /** @var list<string> */
    public const FINANCE_ROLES = ['owner', 'principal', 'administrator', 'accountant'];

    protected function authorizeSchool(Request $request, School $school, ?array $roles = null): string
    {
        $membership = DB::table('school_user')
            ->where('school_id', $school->id)
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless($membership, 404);

        if ($roles !== null && ! in_array($membership->role, $roles, true)) {
            abort(403, 'Insufficient permissions for this action.');
        }

        return $membership->role;
    }
}
