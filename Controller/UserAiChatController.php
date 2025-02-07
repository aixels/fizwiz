<?php

namespace App\Http\Controllers;

use App\Helpers\ApiJsonResponseHelper;
use App\Models\Auth;
use App\Models\UserAiChat;
use App\Models\UserCategory;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Validator;

class UserAiChatController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/user/user-ai-chat",
     *     summary="Get users all AI chat messages",
     *     security={{"bearer_token":{}}},
     *     tags={"AI Chat"},
     *      @OA\Response(
     *         response=200,
     *         description="Success response",
     *     ),
     * )
     */
    public function getUserMessages()
    {
        $messages = UserAiChat::where('user_id', auth()->user()->id)->orderBy('id', 'desc')->paginate(25);
        return ApiJsonResponseHelper::successResponse($messages, "Success");
    }
    /**
     * @OA\Post(
     *     path="/api/user/send-ai-message",
     *     summary="Send a message to AI and save to database",
     *     security={{"bearer_token":{}}},
     *     tags={"AI Chat"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="My monthly income totals $3500. I am aiming to save at least 35 percent of this amount, with the remainder allocated for monthly expenses. In the previous month, my bills amounted to $500, while expenses for fuel and travel reached $1000. I allocated $400 for food expenses, and an additional $500 was dedicated to loan payments, leaving me with five more months to complete the loan payments. Shopping expenses accounted for $500. I also need to cover ongoing bills and daily necessities. Could you provide guidance on how I should distribute my monthly spending to reach my savings goal?"
     *             ),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successfully",
     *     )
     * )
     */

    public function sendAiMessage(Request $request)
    {
        $secretKey = config('services.open_api.secret_key');

        $validator = Validator::make($request->all(), [
            'message' => 'required',
        ]);
        if ($validator->fails()) {
            return ApiJsonResponseHelper::apiValidationFailResponse($validator);
        }

        try {
            $message = [];
            $message[0]['role'] = "user";
            // $check = UserAiChat::where('user_id', auth()->user()->id)->first();
            // if (empty($check)) {
                $accounts = Auth::where('user_id', auth()->user()->id)->select('name', 'type', 'balances_available')->get();
                $userCategory = UserCategory::where('user_id', auth()->user()->id)->select('category_name', 'limitation', 'manual_spending')->get();

                $detailMessage = "\n" . 'Accounts with current balances: ';
                foreach ($accounts as $key => $value) {
                    $detailMessage .= "\n" . $value->name . ' (type: ' . $value->type . ') - balance: $' . $value->balances_available . '. ';
                }
                $detailMessage .= "\n" . 'Categories with limits: ';
                foreach ($userCategory as $key => $value) {
                    $detailMessage .= "\n" . $value->category_name . ' - lim`it: $' . $value->limitation . ' - used: $' . $value->manual_spending . '. ';
                }

                $message[0]['content'] = $request->message . $detailMessage;
            // } else {
            //     $message[0]['content'] = $request->message;
            // }
            $data = [
                'model' => "gpt-3.5-turbo",
                'messages' => $message,
                'temperature' => 1,
                'n' => 1,
                'top_p' => 1,
                'stream' => false,
                'max_tokens' => 500,
                'frequency_penalty' => 0,
                'presence_penalty' => 0,
            ];
            $client = new Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'headers' => [
                    'Authorization' => 'Bearer ' . $secretKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response = $client->post('chat/completions', [
                'json' => $data,
            ]);

            $responseData = json_decode($response->getBody(), true);

            self::addMessage($request->message, 'user');
            self::addMessage($responseData['choices'][0]['message']['content'], 'ai');

            return ApiJsonResponseHelper::successResponse($responseData['choices'][0]['message']['content'], "Success");
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    public function addMessage($message, $type)
    {
        UserAiChat::create([
            'user_id' => auth()->user()->id,
            'type' => $type,
            'message' => $message,
        ]);
    }

}
