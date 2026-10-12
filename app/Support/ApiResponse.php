<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Return a success JSON response.
     *
     * @param  mixed  $data
     */
    protected function successResponse($data = null, array $meta = [], int $code = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => empty($meta) ? (object) [] : $meta,
        ], $code);
    }

    /**
     * Return an error JSON response.
     */
    protected function errorResponse(string $message, string $errorCode = 'ERROR', array $errors = [], int $code = 400): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => $errorCode,
            'errors' => empty($errors) ? (object) [] : $errors,
        ], $code);
    }
}
