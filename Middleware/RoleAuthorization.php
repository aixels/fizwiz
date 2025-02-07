<?php

namespace App\Http\Middleware;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use App\Helpers\ApiJsonResponseHelper;

use Closure;

class RoleAuthorization
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next,...$roles)
    {
        try {
            //Access token from the request
            $token = JWTAuth::parseToken();
            //Try authenticating user
            $user = $token->authenticate();
        } catch (TokenExpiredException $e) {
            //Thrown if token has expired
            return ApiJsonResponseHelper::apiUserNotFoundResponse('Your token has expired. Please, login again.');
        } catch (TokenInvalidException $e) {
            //Thrown if token invalid
            return ApiJsonResponseHelper::apiUserNotFoundResponse('Your token is invalid. Please, login again.');
        }catch (JWTException $e) {
            //Thrown if token was not found in the request.
            return ApiJsonResponseHelper::apiUserNotFoundResponse('Please, attach a Bearer Token to your request');
        }
        if ($user && in_array($user->role->name, $roles)) {
            return $next($request);
        }

            return ApiJsonResponseHelper::apiUserNotFoundResponse('You are unauthorized to access this resource');
    }
}
