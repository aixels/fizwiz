<?php
namespace App\Helpers;

use Response;

class ApiJsonResponseHelper
{
    /**
     * Default Response function.
     */
    public static function apiJsonResponse($responseData = [], $code = '', $message = "", $status)
    {
        $data = array(
            'message' => $message,
            'status' => $status,
            'data' => $responseData,
        );
        return Response::json($data, $code);
    }
    /**
     * Default Response function.
     */
    public static function apiJsonAuthResponse($responseData, $token, $message = "")
    {
        return response([
            'message' => $message,
            'status' => true,
            'data' => $responseData,
            'token' => $token,
        // ])->header('Authorization', $token);
        ]);
    }

    /**
     * Success response function.
     */
    public static function successResponse($responseData = [], $message = "")
    {
        $statusCodes = config("finwiz.status_codes");
        return ApiJsonResponseHelper::apiJsonResponse($responseData, $statusCodes['success'], $message, 'true');
    }

    /**
     * Normal error response function.
     */
    public static function errorResponse($message = "")
    {
        $statusCodes = config("finwiz.status_codes");
        return ApiJsonResponseHelper::apiJsonResponse([], $statusCodes['normal_error'], $message, 'false');
    }

    /**
     * Validation error response  function.
     */
    public static function apiValidationFailResponse($validator)
    {
        $statusCodes = config("finwiz.status_codes");
        $messages = $validator->errors();
        if (is_object($messages)) {
            $messages = $messages->toArray();
        }
        return ApiJsonResponseHelper::apiJsonResponse($messages, $statusCodes['form_validation'], "Validation Error", 'false');
    }

    /**
     * Authentication fail response function.
     */
    public static function apiUserNotFoundResponse($message)
    {
        $statusCodes = config("finwiz.status_codes");
        return ApiJsonResponseHelper::apiJsonResponse([], $statusCodes['auth_fail'], $message, 'false');
    }
}
