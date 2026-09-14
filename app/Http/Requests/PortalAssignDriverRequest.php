<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class PortalAssignDriverRequest extends AssignDriverRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator);
    }

    protected function failedAuthorization(): void
    {
        abort(403);
    }
}
