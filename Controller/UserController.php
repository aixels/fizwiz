<?php

namespace App\Http\Controllers;

use App\Helpers\ApiJsonResponseHelper;
use App\Models\Category;
use App\Models\Question;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\UserFutherCategories;
use App\Models\UserQuestionAnswer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use TCG\Voyager\Models\Role;
use Tymon\JWTAuth\Facades\JWTAuth;
use Validator;

class UserController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/register",
     *     summary="Register a new user",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="email",
     *                 type="string",
     *                 example="johndoe@example.com"
     *             ),
     *             @OA\Property(
     *                 property="password",
     *                 type="string",
     *                 format="password",
     *                 example="secretpassword"
     *             ),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success message",
     *     ),
     * )
     */

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6',
        ]);
        if ($validator->fails()) {
            return ApiJsonResponseHelper::apiValidationFailResponse($validator);
        }
        try {
            $role = Role::where('name', 'user')->first();
            $user = new User([
                'role_id' => $role ? $role->id : null,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);
            $user->save();
            $token = JWTAuth::fromUser($user);
            $this->mapMainCategories($user->id);
            $message = "User Register Successfully";
            return ApiJsonResponseHelper::apiJsonAuthResponse($user, $token, $message);
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::apiUserNotFoundResponse($e->getMessage());
        }
    }

    public function mapMainCategories($user_id)
    {
        try {
            $category = Category::where('parent_id', null)->get();
            if (sizeof($category)) {
                foreach ($category as $value) {
                    $userCategory = UserCategory::create([
                        'user_id' => $user_id,
                        'category_name' => $value->name,
                        'limitation' => 0,
                        'month' => Carbon::now()->format('F'),
                    ]);
                    $userCategory->categories()->attach($value->id);
                    for ($i = 1; $i < 3; $i++) {
                        UserFutherCategories::create([
                            'user_id' => $user_id,
                            'category_id' => $value->id,
                            'category_name' => $value->name,
                            'limitation' => 0,
                            'max_limit' => 0,
                            'month' => Carbon::now()->addMonth($i)->format('F'),
                        ]);
                    }
                }
                return true;
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @OA\Post(
     *     path="/api/user/update-user",
     *     summary="Register a new user",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="name",
     *                 type="string",
     *                 example="John Doe"
     *             ),
     *             @OA\Property(
     *                 property="phone_number",
     *                 type="string",
     *                 example="123-456-7890"
     *             ),
     *             @OA\Property(
     *                 property="date_of_birth",
     *                 type="string",
     *                 example="18/11/1999"
     *             ),
     *             @OA\Property(
     *                 property="address",
     *                 type="string",
     *                 example="408-j DHA Lahore"
     *             ),
     *             @OA\Property(
     *                 property="employment_status",
     *                 type="string",
     *                 example="employed"
     *             ),
     *             @OA\Property(
     *                 property="years_of_working",
     *                 type="string",
     *                 example="less than 6 months"
     *             ),
     *             @OA\Property(
     *                 property="retire",
     *                 type="string",
     *                 example="more than 5 years"
     *             ),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success message",
     *     ),
     * )
     */

    public function updateUser(Request $request)
    {
        try {
            $user = auth()->user();
            foreach ($request->all() as $key => $value) {
                if ($key == 'password' || $key == 'email') {
                    continue;
                } else {
                    $user->$key = $value;
                }
            }
            $user->save();
            $message = "User Updated Successfully";
            return ApiJsonResponseHelper::successResponse($user, $message);
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::apiUserNotFoundResponse($e->getMessage());
        }
    }
    /**
     * @OA\Post(
     *     path="/api/login",
     *     summary="User login",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="email",
     *                 type="string",
     *                 example="johndoe@example.com"
     *             ),
     *             @OA\Property(
     *                 property="password",
     *                 type="string",
     *                 format="password",
     *                 example="secretpassword"
     *             ),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success message",
     *     ),
     * )
     */

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);
        if ($validator->fails()) {
            return ApiJsonResponseHelper::apiValidationFailResponse($validator);
        }
        try {
            $credentials = $request->only('email', 'password');
            if (!$token = JWTAuth::attempt($credentials)) {
                return ApiJsonResponseHelper::apiUserNotFoundResponse('Invalid credentials');
            }
            $user = User::where('email', $request->email)->first();
            $message = "User Login Successfully";
            return ApiJsonResponseHelper::apiJsonAuthResponse($user, $token, $message);
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::apiUserNotFoundResponse($e->getMessage());
        }
    }

    /**
     * @OA\Get(
     *     path="/api/user/auth-user",
     *     summary="Get Login User",
     *     security={{"bearer_token":{}}},
     *     tags={"Authentication"},
     *     @OA\Response(
     *         response=200,
     *         description="Success message",
     *     ),
     * )
     */

    public function getAuthUser()
    {
        $user = User::where('id', auth()->user()->id)->with('auth')->get();
        return ApiJsonResponseHelper::successResponse($user, "Success");

    }

    /**
     * @OA\Post(
     *     path="/api/user/add-or-update-user-question",
     *     summary="Add user question answer in array",
     *     security={{"bearer_token":{}}},
     *     tags={"User Question Answers"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="question_id",
     *                 type="integer",
     *                 example="2"
     *             ),
     *             @OA\Property(
     *                  property="answer",
     *                  type="array",
     *                  @OA\Items(
     *                      type="string",
     *                      example="Updated answer"
     *                  ),
     *             ),
     *             @OA\Property(
     *                 property="type",
     *                 type="string",
     *                 example="array"
     *             ),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success message",
     *     ),
     * )
     */
    public function addOrUpdateUserQuestion(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'question_id' => 'required|integer|exists:questions,id',
            'answer' => 'required',
            'type' => 'required',
        ]);
        if ($validator->fails()) {
            return ApiJsonResponseHelper::apiValidationFailResponse($validator);
        }
        $userId = auth()->user()->id;
        $answer = $request->answer;
        if ($request->type == 'array') {
            $answer = json_encode($answer);
        }

        $userQuestionAnswer = UserQuestionAnswer::where('user_id', $userId)->where('question_id', $request->question_id)->first();
        if (isset($userQuestionAnswer)) {
            $userQuestionAnswer->update([
                'answer' => $answer,
                'type' => $request->type,
            ]);
            $message = "User question answer updated successfully";
        } else {
            $userQuestionAnswer = UserQuestionAnswer::create([
                'user_id' => $userId,
                'question_id' => $request->question_id,
                'answer' => $answer,
                'type' => $request->type,
            ]);
            $message = "User question answer stored successfully";
        }

        return ApiJsonResponseHelper::successResponse($userQuestionAnswer, $message);
    }

    /**
     * @OA\Get(
     *     path="/api/user/get-user-question",
     *     summary="Get User Question Answer",
     *     security={{"bearer_token":{}}},
     *     tags={"User Question Answers"},
     *     @OA\Response(
     *         response=200,
     *         description="Success message",
     *     ),
     * )
     */

    public function getAllUserQuestion()
    {
        $data = [];
        $userQuestionAnswer = UserQuestionAnswer::where('user_id', auth()->user()->id)->with('question')->get();
        $questions = Question::all();
        $data['user_question_answer_count'] = $userQuestionAnswer->count();
        $data['total_questions'] = $questions->count();
        $data['user_question_answer'] = $userQuestionAnswer;
        return ApiJsonResponseHelper::successResponse($data, "User question answers");
    }

    /**
     * @OA\Get(
     *     path="/api/user/get-user-question/{question_id}",
     *     summary="Get User Question Answer with question id",
     *     security={{"bearer_token":{}}},
     *     tags={"User Question Answers"},
     *    @OA\Parameter(
     *         name="question_id",
     *         in="path",
     *         description="Question Id",
     *         required=true,
     *         @OA\Schema(
     *             type="string",
     *             format="integer",
     *             default="1",
     *         ),
     *     ),
     *     @OA\Response(
     *         response="default",
     *         description="Get Question",
     *     ),
     * )
     */

    public function getUserQuestion($id)
    {
        $userQuestionAnswer = UserQuestionAnswer::where('user_id', auth()->user()->id)
            ->where('question_id', $id)
            ->with('question')
            ->orderBy('created_at', 'desc')
            ->first();

        return ApiJsonResponseHelper::successResponse($userQuestionAnswer, "User question answer ");
    }

    /**
     * @OA\Post(
     *     path="/api/user/send-otp",
     *     summary="Send otp message",
     *     tags={"User Phone Number Verification"},
     *     @OA\Response(
     *         response=200,
     *         description="Success message",
     *     ),
     * )
     */
    public function sendOtp()
    {
        $user = auth()->user();
        if ($user->phone_number) {
            $user->otp = "12345";
            $user->save();
            return ApiJsonResponseHelper::successResponse($user, "Otp sent successfully");
        }
        return ApiJsonResponseHelper::errorResponse("Phone No not found");
    }

    /**
     * @OA\Post(
     *     path="/api/user/verify-otp",
     *     summary="Verify OTP for User",
     *     tags={"User Phone Number Verification"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"otp"},
     *             @OA\Property(property="otp", type="string", example="12345"),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successfully verified.",
     *     ),
     * )
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'otp' => 'required',
        ]);

        if ($validator->fails()) {
            return ApiJsonResponseHelper::apiValidationFailResponse($validator);
        }

        $user = auth()->user();
        if (!$user->otp) {
            return ApiJsonResponseHelper::errorResponse("OTP not available");
        }
        if ($request->otp === $user->otp) {
            $user->email_verified_at = Carbon::now();
            $user->verified = true;
            $user->otp = null;
            $user->save();

            return ApiJsonResponseHelper::successResponse([], "Verified");
        }

        return ApiJsonResponseHelper::errorResponse("OTP does not match");
    }

    public function testAwsS3(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return ApiJsonResponseHelper::apiValidationFailResponse($validator);
        }
        $imageName = time() . '.' . $request->image->extension();

        $path = Storage::disk('s3')->put('images', $request->image);
        $path = Storage::disk('s3')->url($path);

        return ApiJsonResponseHelper::successResponse($path, "Success");
    }

    /**
     * @OA\Post(
     *     path="/api/user/reset-password",
     *     summary="Reset User's Password",
     *     security={{"bearer_token":{}}},
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="old_password",
     *                     type="password",
     *                     format="string",
     *                     description="1234abc"
     *               ),
     *                 @OA\Property(
     *                     property="password",
     *                     type="password",
     *                     format="string",
     *                     description="1234abc"
     *               ),
     *                 @OA\Property(
     *                     property="password_confirmation",
     *                     type="password",
     *                     format="string",
     *                     description="1234abc"
     *               ),
     *             ),
     *         ),
     *
     *     ),
     *    @OA\Response(
     *         response=200,
     *         description="Success response",
     *     ),
     * )
     */
    public function passwordReset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'old_password' => 'required|string|min:6',
            'password' => 'required|confirmed|min:6',
            'password_confirmation' => 'required',
        ]);
        if ($validator->fails()) {
            return ApiJsonResponseHelper::apiValidationFailResponse($validator);
        }
        try {
            $user = auth()->user();
            if (Hash::check($request->old_password, $user->password)) {
                $user->password = Hash::make($request->password);
                $user->save();
                $token = JWTAuth::fromUser($user);
                $message = "password updated successfully";
                return ApiJsonResponseHelper::successResponse($token, $user, $message);
            }
            return ApiJsonResponseHelper::errorResponse("password updated failed");
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::apiUserNotFoundResponse($e->getMessage());
        }
    }
    /**
     * @OA\Post(
     *     path="/api/user/upload-user-avatar",
     *     summary="Update a user's avatar",
     *     security={{"bearer_token":{}}},
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="avatar",
     *                     type="file",
     *                     format="binary",
     *                     description="Image file to upload"
     *               ),
     *             ),
     *         ),
     *
     *     ),
     *    @OA\Response(
     *         response=200,
     *         description="Success response",
     *     ),
     * )
     */
    public function uploadAvatar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return ApiJsonResponseHelper::apiValidationFailResponse($validator);
        }
        try {
            $user = auth()->user();
            $path = Storage::disk('s3')->put('avatar', $request->avatar);
            $path = Storage::disk('s3')->url($path);
            $user->avatar = $path;
            $user->save();
            return ApiJsonResponseHelper::successResponse($user, "Avatar Uploaded successfully");
            return ApiJsonResponseHelper::errorResponse("Avatar unable to upload");
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }
    /**
     * @OA\Post(
     *     path="/api/otp-forget-password",
     *     summary="Sending OTP for updating password",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *          @OA\Property(
     *                 property="email",
     *                 type="email",
     *                 example="johndoe@example.com"
     *             ),
     *           ),
     *         ),
     *
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success response",
     *
     *      )
     * )
     */
    public function forgetPasswordOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users',
        ]);
        if ($validator->fails()) {
            return ApiJsonResponseHelper::apiValidationFailResponse($validator);
        }
        try {
            $otp = rand(100000, 999999);
            $user = User::where('email', $request->email)->first();
            if ($user) {
                $data['email'] = $request->email;
                $data['title'] = 'Forgot Password';
                $data['body'] = 'Your OTP is:-' . $otp;
                Mail::send('mail', ['data' => $data], function ($message) use ($data) {
                    $message->to($data['email'])->subject($data['title']);
                });
                $user->otp = $otp;
                $user->save();
                return ApiJsonResponseHelper::successResponse("OTP send Successfully");
            }
            return ApiJsonResponseHelper::errorResponse("Unable to Send OTP");
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::apiUserNotFoundResponse($e->getMessage());
        }
    }
    /**
     * @OA\Post(
     *     path="/api/otp-verify",
     *     summary="Reset User's Password by verifying OTP",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *              @OA\Property(
     *                 property="otp",
     *                 type="number",
     *                 example="123456"
     *                ),
     *                @OA\Property(
     *                 property="email",
     *                 type="email",
     *                 example="johndoe@example.com"
     *             ),
     *             ),
     *         ),
     *
     *     ),
     *    @OA\Response(
     *         response=200,
     *         description="Success response",
     *     ),
     * )
     */
    public function OtpVerification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'otp' => 'required|min:6|exists:users',
            'email' => 'required|email|exists:users',
        ]);
        if ($validator->fails()) {
            return ApiJsonResponseHelper::apiValidationFailResponse($validator);
        }
        try {
            $user = User::where('otp', $request->otp)->where('email', $request->email)->first();
            if ($user) {
                $user->otp = "";
                $user->save();
                return ApiJsonResponseHelper::successResponse("Successfully match");
            }
            return ApiJsonResponseHelper::errorResponse("OTP not match");
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::apiUserNotFoundResponse($e->getMessage());
        }
    }
    /**
     * @OA\Post(
     *     path="/api/update-password",
     *     summary="Reset User's Password by verifying OTP",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                  @OA\Property(
     *                 property="email",
     *                 type="email",
     *                 example="johndoe@example.com"
     *             ),
     *                 @OA\Property(
     *                     property="password",
     *                     type="password",
     *                     format="string",
     *                     description="1234abc"
     *               ),
     *                 @OA\Property(
     *                     property="password_confirmation",
     *                     type="password",
     *                     format="string",
     *                     description="1234abc"
     *               ),
     *             ),
     *         ),
     *
     *     ),
     *    @OA\Response(
     *         response=200,
     *         description="Success response",
     *     ),
     * )
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|confirmed|min:6',
            'password_confirmation' => 'required',
            'email' => 'required|email|exists:users',
        ]);
        if ($validator->fails()) {
            return ApiJsonResponseHelper::apiValidationFailResponse($validator);
        }
        try {
            $user = User::where('email', $request->email)->first();
            if ($user) {
                $user->password = Hash::make($request->password);
                $user->save();
                return ApiJsonResponseHelper::successResponse([], "Password updated successfully");
            }
            return ApiJsonResponseHelper::errorResponse("Unable to update password");
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::apiUserNotFoundResponse($e->getMessage());
        }
    }

}
