<?php

namespace App\Modules\Workflow\Engine;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A workflow command that cannot be carried out, with a message fit for the
 * person who asked. Rendered as a validation-style redirect for Inertia
 * requests, JSON otherwise.
 */
class WorkflowException extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(string $message, public int $status = 422, public array $errors = [])
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $this->getMessage(), 'errors' => $this->errors], $this->status);
        }

        return back()->withErrors($this->errors + ['workflow' => $this->getMessage()])->with('error', $this->getMessage());
    }
}
