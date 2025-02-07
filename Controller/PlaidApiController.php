<?php

namespace App\Http\Controllers;

use App\Helpers\ApiJsonResponseHelper;
use App\Models\Auth;
use App\Models\Balance;
use App\Models\Category;
use App\Models\Enrich;
use App\Models\Identity;
use App\Models\InvestmentsHolding;
use App\Models\InvestmentsTransaction;
use App\Models\Liability;
use App\Models\Notification;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\UserFutherCategories;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Validator;

class PlaidApiController extends Controller
{

    public function __construct()
    {
        $data = config('services.plaid');
        $this->clientId = $data['client_id'];
        $this->secret = $data['plaid_secret'];
        $this->PlaidEnv = $data['plaid_env'];
    }

    /**
     * @OA\Post(
     *     path="/api/user/plaid-get-access-token",
     *     summary="Get Access Token",
     *     tags={"Plaid"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="public_token", type="string", example="public-****************"),
     *         )
     *     ),
     *     @OA\Response(
     *         response="default",
     *         description="Get plaid auth api details",
     *     )
     * )
     */

    public function getAccessToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'public_token' => 'required',
        ]);
        if ($validator->fails()) {
            return ApiJsonResponseHelper::apiValidationFailResponse($validator);
        }

        try {
            $client = new Client();

            $response = $client->post('https://sandbox.plaid.com/item/public_token/exchange', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'client_id' => $this->clientId,
                    'secret' => $this->secret,
                    'public_token' => $request->input('public_token'), // You can get this from the request
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $user = auth()->user();
            $user->plaid_access_token = $data['access_token'];
            $user->save();
            return ApiJsonResponseHelper::successResponse([], "Access Token generated and stored successfully");
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }
    /**
     * @OA\Get(
     *     path="/api/user/plaid-auth",
     *     summary="Get Plaid auth",
     *     tags={"Plaid"},
     *     @OA\Parameter(
     *         name="count",
     *         in="path",
     *         description="Number of transactions to retrieve",
     *         required=false,
     *         @OA\Schema(
     *             type="integer",
     *             format="int32",
     *             default=5
     *         )
     *     ),
     *     @OA\Response(
     *         response="default",
     *         description="Get plaid auth api details",
     *     )
     * )
     */
    public function plaidAuthGet()
    {
        $user_id = auth()->user()->id;
        $plaid_access_token = auth()->user()->plaid_access_token;
        $client = new Client();
        try {
            if (isset($plaid_access_token)) {
                $response = $client->post('https://sandbox.plaid.com/auth/get', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'client_id' => $this->clientId,
                        'secret' => $this->secret,
                        'access_token' => $plaid_access_token,
                    ],
                ]);

                $body = $response->getBody();
                $data = json_decode($body, true);
                foreach ($data['accounts'] as $key => $value) {
                    $check = Auth::where('user_id', $user_id)
                        ->where('account_id', $value['account_id'])
                        ->first();

                    if ($check) {
                        $check->update([
                            'balances_available' => $value['balances']['available'],
                            'balances_current' => $value['balances']['current'],
                            'balances_iso_currency_code' => $value['balances']['iso_currency_code'],
                            'balances_limit' => $value['balances']['limit'],
                            'balances_unofficial_currency_code' => $value['balances']['unofficial_currency_code'],
                            'mask' => $value['mask'],
                            'name' => $value['name'],
                            'official_name' => $value['official_name'],
                            'subtype' => $value['subtype'],
                            'type' => $value['type'],
                            'numbers_ach' => isset($data['numbers']['ach'][0]) ? json_encode($data['numbers']['ach'][0]) : null,
                            'numbers_bacs' => isset($data['numbers']['bacs'][0]) ? json_encode($data['numbers']['bacs'][0]) : null,
                            'numbers_eft' => isset($data['numbers']['eft'][0]) ? json_encode($data['numbers']['eft'][0]) : null,
                            'numbers_international' => isset($data['numbers']['international'][0]) ? json_encode($data['numbers']['international'][0]) : null,
                            'json_response' => json_encode($value),
                        ]);
                    } else {
                        Auth::create([
                            'user_id' => auth()->user()->id,
                            'account_id' => $value['account_id'],
                            'balances_available' => $value['balances']['available'],
                            'balances_current' => $value['balances']['current'],
                            'balances_iso_currency_code' => $value['balances']['iso_currency_code'],
                            'balances_limit' => $value['balances']['limit'],
                            'balances_unofficial_currency_code' => $value['balances']['unofficial_currency_code'],
                            'mask' => $value['mask'],
                            'name' => $value['name'],
                            'official_name' => $value['official_name'],
                            'subtype' => $value['subtype'],
                            'type' => $value['type'],
                            'numbers_ach' => isset($data['numbers']['ach'][0]) ? json_encode($data['numbers']['ach'][0]) : null,
                            'numbers_bacs' => isset($data['numbers']['bacs'][0]) ? json_encode($data['numbers']['bacs'][0]) : null,
                            'numbers_eft' => isset($data['numbers']['eft'][0]) ? json_encode($data['numbers']['eft'][0]) : null,
                            'numbers_international' => isset($data['numbers']['international'][0]) ? json_encode($data['numbers']['international'][0]) : null,
                            'json_response' => json_encode($value),
                        ]);
                    }
                }
                self::plaidTransactionGet($user_id, $plaid_access_token);
                self::plaidInvestmentHoldingsGet();

                $end_date = Carbon::now();
                $start_date = Carbon::now();
                $start_date = $start_date->subYears(2)->startOfYear();
                $start_date = $start_date->format('Y-m-d');
                $end_date = $end_date->format('Y-m-d');

                self::plaidInvestmentTransactionGet($start_date, $end_date);
                $transactionInsightController = new TransactionInsightController();
                $response = $transactionInsightController->syncAllOldTransactions('api');

                self::calculateCategoryLimit($user_id);
                return ApiJsonResponseHelper::successResponse($data, "Auth Data saved Successfully");
            }
            return ApiJsonResponseHelper::errorResponse("Access token not found.");
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    public function plaidTransactionGet($user_id, $plaid_access_token, int $count = 250, $cursor = null)
    {
        $client = new Client();
        try {
            $response = $client->post('https://sandbox.plaid.com/transactions/sync', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'client_id' => $this->clientId,
                    'secret' => $this->secret,
                    'access_token' => $plaid_access_token,
                    'count' => $count,
                    'cursor' => $cursor,
                    'options' => [
                        'include_personal_finance_category' => true,
                    ],
                ],
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);
            foreach ($data['added'] as $key => $value) {
                $oldAmount = 0;
                $status = null;
                $count = count($value['category']);
                $count = $count - 1;
                $category_id = null;
                foreach ($value['category'] as $key => $category) {
                    $checkAccount = Auth::where('account_id', $value['account_id'])->first();
                    if ($checkAccount->type == 'loan' || $checkAccount->subtype == 'cd' || $checkAccount->subtype == 'money market' || $checkAccount->subtype == 'ira' || $checkAccount->subtype == '401k') {
                        $category = $checkAccount->subtype;
                    }
                    $category = Category::where('name', $category)->first();
                    if ($category) {
                        if ($category->parent_id !== null) {
                            $category_id = $category['id'];
                            break;
                        }
                        if ($count == $key) {
                            $childCategory = Category::where('parent_id', $category['id'])->first();
                            $category_id = $childCategory['id'];
                        }
                    }
                }

                if ($category_id) {
                    $transaction = Transaction::where('user_id', $user_id)
                        ->where('account_id', $value['account_id'])
                        ->where('transaction_id', $value['transaction_id'])
                        ->first();
                    $transaction_type = ($value['amount'] < 0) ? 'expense' : 'income';
                    if ($transaction) {
                        $status = "updated";
                        $oldAmount = $transaction->amount;
                        $transaction->update([
                            'account_id' => $value['account_id'],
                            'user_id' => $user_id,
                            'account_owner' => $value['account_owner'],
                            'amount' => $value['amount'],
                            'authorized_date' => $value['authorized_date'],
                            'authorized_datetime' => $value['authorized_datetime'],
                            'category' => json_encode($value['category']),
                            'category_id' => $category_id,
                            'check_number' => $value['check_number'],
                            'date' => $value['date'],
                            'datetime' => $value['datetime'],
                            'iso_currency_code' => $value['iso_currency_code'],
                            'location' => json_encode($value['location']),
                            'merchant_name' => $value['merchant_name'],
                            'name' => $value['name'],
                            'payment_channel' => $value['payment_channel'],
                            'payment_meta' => json_encode($value['payment_meta']),
                            'pending' => $value['pending'],
                            'pending_transaction_id' => $value['pending_transaction_id'],
                            'personal_finance_category' => json_encode($value['personal_finance_category']),
                            'transaction_code' => $value['transaction_code'],
                            'transaction_id' => $value['transaction_id'],
                            'transaction_type' => $transaction_type,
                            'unofficial_currency_code' => $value['unofficial_currency_code'],
                            'json_response' => json_encode($value),
                            'entry_type' => "automatic",
                        ]);
                    } else {
                        $transaction = Transaction::create([
                            'account_id' => $value['account_id'],
                            'user_id' => $user_id,
                            'account_owner' => $value['account_owner'],
                            'amount' => $value['amount'],
                            'authorized_date' => $value['authorized_date'],
                            'authorized_datetime' => $value['authorized_datetime'],
                            'category' => json_encode($value['category']),
                            'category_id' => $category_id,
                            'check_number' => $value['check_number'],
                            'date' => $value['date'],
                            'datetime' => $value['datetime'],
                            'iso_currency_code' => $value['iso_currency_code'],
                            'location' => json_encode($value['location']),
                            'merchant_name' => $value['merchant_name'],
                            'name' => $value['name'],
                            'payment_channel' => $value['payment_channel'],
                            'payment_meta' => json_encode($value['payment_meta']),
                            'pending' => $value['pending'],
                            'pending_transaction_id' => $value['pending_transaction_id'],
                            'personal_finance_category' => json_encode($value['personal_finance_category']),
                            'transaction_code' => $value['transaction_code'],
                            'transaction_id' => $value['transaction_id'],
                            'transaction_type' => $transaction_type,
                            'unofficial_currency_code' => $value['unofficial_currency_code'],
                            'json_response' => json_encode($value),
                            'entry_type' => "automatic",
                        ]);
                    }
                    if (isset($transaction)) {
                        self::updateLimit($transaction, $status, $oldAmount);
                    }
                }
            }

            // Process the response data as needed
            if ($data['has_more'] == true) {
                self::plaidTransactionGet($user_id, $plaid_access_token, $count, $data['next_cursor']);
            }
            return ApiJsonResponseHelper::successResponse([], "Transaction Data saved Successfully");
            return ApiJsonResponseHelper::errorResponse("Access token not found.");
        } catch (\Exception $e) {

            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }
    public function updateLimit($data, $status, $oldAmount)
    {
        $userId = $data->user_id;
        $parentCategory = Category::where('id', $data->category_id)->first();
        $parentCategory = $parentCategory->parent_id;
        $transaction_type = $data->transaction_type;
        $amount = $data->amount;
        $user = User::where('id', $userId)->first();
        $auth = Auth::where('user_id', $userId)->where('account_id', $data->account_id)->first();

        $userCategory = UserCategory::where('user_id', $userId)
            ->whereHas('userCategoryPivots', function ($query) use ($parentCategory) {
                $query->where('category_id', $parentCategory);
            })
            ->first();
        if ($userCategory) {
            \Log::error('$userCategory: ' . $userCategory->id);
            \Log::error('amount : ' . $amount);
            if ($oldAmount != 0) {
                $userCategory->manual_spending += $oldAmount;
                $userCategory->spending += $oldAmount;
                $userCategory->update();
            }
            $userCategory->manual_spending -= $amount;
            $userCategory->spending -= $amount;
            $userCategory->update();
        }
        if ($user) {
            $user->balance += $amount;
            $user->manual_balance += $amount;
            $user->update();
        }
        Notification::addNotifications($userCategory);
    }
    public function calculateCategoryLimit($user_id, $future = 1, $onlyGet = 0)
    {
        try {
            $lastMonthRecords = config("finwiz.month_calculation");
            $need = config("finwiz.category_percent.need");
            $want = config("finwiz.category_percent.want");

            $lastMonthStartDate = Carbon::now()->subMonth($lastMonthRecords)->startOfMonth();
            $lastMonthEndDate = Carbon::now()->subMonth()->endOfMonth();
            $totalIncome = self::getTotalIncome($user_id, $lastMonthStartDate, $lastMonthEndDate);

            $idealNeed = $totalIncome * ($need / 100);
            $idealWant = $totalIncome * ($want / 100);

            $temp = self::getTotalExpenseAndAverage($user_id, $lastMonthStartDate, $lastMonthEndDate);
            $totalExpense = $temp['totalExpense'];
            $totalExpenseAverage = $temp['averageExpense'];
            $categoriesExpenseArray = self::getCategoriesExpenseArray($user_id, $lastMonthStartDate, $lastMonthEndDate);

            $needCategory = self::getAverageAndTotalOfAllNeedAndWantCategory($user_id, 'need', $lastMonthStartDate, $lastMonthEndDate, $lastMonthRecords);
            $needCategoryTotal = $needCategory['total'];
            $needCategoryAverage = $needCategory['average'];

            $wantCategory = self::getAverageAndTotalOfAllNeedAndWantCategory($user_id, 'want', $lastMonthStartDate, $lastMonthEndDate, $lastMonthRecords);
            $wantCategoryTotal = $wantCategory['total'];
            $wantCategoryAverage = $wantCategory['average'];
            $getValue = [];
            foreach ($categoriesExpenseArray as $key => $value) {
                if ($value['average'] != 0) {
                    $userCategory = UserCategory::where('id', $value['user_category'])->first();
                    if (isset($userCategory) && $userCategory->fixed == 0) {

                        if ($value['category_type'] == 'need') {
                            $limitPercent = $value['average'] / $needCategoryAverage;
                            $limit = $idealNeed * ($limitPercent / 100);
                        } else {
                            $limitPercent = $value['average'] / $wantCategoryAverage;
                            $limit = $idealWant * ($limitPercent / 100);
                        }

                       
                        if ($onlyGet == 1) {
                            $getValue[$key]['user_category_id'] = $userCategory->id;
                            $getValue[$key]['category_name'] = $userCategory->category_name;
                            // Existing calculations
                            $originalLimitation = $userCategory->limitation;
                            $newLimitation = $userCategory->limitation + $limit;

                            $getValue[$key]['limitation'] = $newLimitation;
                            $getValue[$key]['max_limit'] = $userCategory->max_limit + $newLimitation + ($newLimitation * (45 / 100));

                            // Percentage change calculation
                            if ($originalLimitation != 0) {
                                $percentageChange = (($newLimitation - $originalLimitation) / $originalLimitation) * 100;
                                $getValue[$key]['limitation_percentage_change'] = $percentageChange; // Add percentage change to output
                            } else {
                                $getValue[$key]['limitation_percentage_change'] = 'N/A'; // Handle division by zero
                            }
                        } else {
                            $userCategory->limitation += $limit;
                            $userCategory->max_limit += $userCategory->limitation + ($userCategory->limitation * (45 / 100));
    
                            for ($i = 1; $i < 3; $i++) {
                                $getFuture = UserFutherCategories::where('user_id', $user_id)
                                    ->where('category_id', $value['category_id'])
                                    ->where('month', Carbon::now()->addMonth($i)->format('F'))
                                    ->first();
                                if ($getFuture) {
                                    $getFuture->limitation = $limit;
                                    $getFuture->max_limit = $limit + ($limit * (45 / 100));
                                    $getFuture->save();
                                    unset($getFuture);
                                } else {
                                    UserFutherCategories::create([
                                        'user_id' => $user_id,
                                        'category_id' => $value['category_id'],
                                        'category_name' => $userCategory->category_name,
                                        'limitation' => $limit,
                                        'max_limit' => $limit + ($limit * (45 / 100)),
                                        'month' => Carbon::now()->addMonth($i)->format('F'),
                                    ]);
                                }
                            }
                            $userCategory->update();
                        }
                    }
                }
            }
            return $getValue;

            $userCategory = UserCategory::where('user_id', $user_id)->get();
            foreach ($userCategory as $key => $value) {
                $value->month = Carbon::now()->format('F');
                $value->update();
            }
            return ApiJsonResponseHelper::successResponse([], "Success");
        } catch (\Exception $e) {
            dd($e);
        }
    }
    public function getCategoriesExpenseArray($user_id, $startDate, $endDate)
    {
        $data = [];
        $count = 0;
        $category = Category::where('parent_id', null)->get();
        $userCategories = UserCategory::where('user_id', $user_id)->with('userCategoryPivots.category')->get();
        foreach ($category as $key => $value) {

            $userCategory = UserCategory::where('user_id', $user_id)
                ->whereHas('userCategoryPivots', function ($query) use ($value) {
                    $query->where('category_id', $value['id']);
                })
                ->first();
            $temp = self::getUserTransactionTotalAndAverageAccordingCategory($value['id'], $user_id, $startDate, $endDate);
            if (!empty($userCategory['id'])) {
                $data[$count]['total'] = $temp['total'];
                $data[$count]['average'] = $temp['average'];
                $data[$count]['user_category'] = $userCategory['id'];
                $data[$count]['category_id'] = $value['id'];
                $data[$count]['category_type'] = $value['type'];
                $count++;
            }
        }
        return $data;
    }

    public function getAverageAndTotalOfAllNeedAndWantCategory($user_id, $type, $startDate, $endDate, $lastMonthRecords = 1)
    {
        $total = 0;
        $totalCount = 0;
        $categories = Category::where('parent_id', null)->where('type', $type)->get();
        foreach ($categories as $category) {
            $subCategory = Category::where('parent_id', $category['id'])->get();

            if ($subCategory->isNotEmpty()) {
                foreach ($subCategory as $value) {
                    $subcategoryTotal = Transaction::where('user_id', $user_id)
                        ->where('category_id', $value->id)
                        ->whereBetween('date', [$startDate, $endDate])
                        ->where('transaction_type', 'expense')
                        ->sum('amount');
                    $total += $subcategoryTotal;
                    $totalCount += Transaction::where('user_id', $user_id)
                        ->where('category_id', $value->id)
                        ->whereBetween('date', [$startDate, $endDate])
                        ->where('transaction_type', 'expense')
                        ->count();
                }
            }
        }
        $total = abs($total);
        $total = round($total, 2);

        $average = $total / $lastMonthRecords;
        $average = round($average, 2);
        return [
            'total' => $total,
            'average' => $average,
        ];
    }
    public function getUserTransactionTotalAndAverageAccordingCategory($category_id, $user_id, $startDate, $endDate)
    {
        $total = 0;
        $totalCount = 0;
        $subCategory = Category::where('parent_id', $category_id)->get();

        if ($subCategory->isNotEmpty()) {
            foreach ($subCategory as $value) {
                $subcategoryTotal = Transaction::where('user_id', $user_id)
                    ->where('category_id', $value->id)
                    ->whereBetween('date', [$startDate, $endDate])
                    ->where('transaction_type', 'expense')
                    ->sum('amount');

                $total += $subcategoryTotal;
                $totalCount += Transaction::where('user_id', $user_id)
                    ->where('category_id', $value->id)
                    ->whereBetween('date', [$startDate, $endDate])
                    ->where('transaction_type', 'expense')
                    ->count();
            }
        }
        $total = abs($total);
        $total = round($total, 2);
        $average = $totalCount > 0 ? $total / $totalCount : 0;
        $average = round($average, 2);

        return [
            'total' => $total,
            'average' => $average,
        ];
    }

    public function getTotalIncome($user_id, $startDate, $endDate)
    {
        $totalIncome = Transaction::where('transaction_type', 'income')->where('user_id', $user_id)
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('amount');
        $totalIncome = round($totalIncome, 2);
        return $totalIncome;
    }

    public function getTotalExpenseAndAverage($user_id, $startDate, $endDate)
    {
        $startDate = Carbon::now()->subMonth()->startOfMonth();
        $endDate = Carbon::now()->subMonth()->endOfMonth();
        $totalExpense = Transaction::where('transaction_type', 'expense')
            ->where('user_id', $user_id)
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('amount');

        $expenseCount = Transaction::where('transaction_type', 'expense')
            ->where('user_id', $user_id)
            ->whereBetween('date', [$startDate, $endDate])
            ->count();
        $totalExpense = abs($totalExpense);
        $totalExpense = round($totalExpense, 2);
        $averageExpense = $expenseCount > 0 ? $totalExpense / $expenseCount : 0;
        $averageExpense = round($averageExpense, 2);

        return [
            'totalExpense' => $totalExpense,
            'averageExpense' => $averageExpense,
        ];
    }

    /**
     * @OA\Get(
     *     path="/api/user/plaid-balance",
     *     summary="Get Plaid balance",
     *     tags={"Plaid"},
     *     @OA\Response(
     *         response="default",
     *         description="Get plaid balance api details",
     *     )
     * )
     */
    public function plaidBalanceGet()
    {
        $client = new Client();
        try {
            if (isset(auth()->user()->plaid_access_token)) {
                $response = $client->post('https://sandbox.plaid.com/accounts/balance/get', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'client_id' => $this->clientId,
                        'secret' => $this->secret,
                        'access_token' => auth()->user()->plaid_access_token,
                    ],
                ]);

                $body = $response->getBody();
                $data = json_decode($body, true);

                // Process the response data as needed
                foreach ($data['accounts'] as $key => $value) {
                    $check = Balance::where('user_id', auth()->user()->id)
                        ->where('account_id', $value['account_id'])
                        ->first();

                    if ($check) {
                        $check->update([
                            'user_id' => auth()->user()->id,
                            'account_id' => $value['account_id'],
                            'balances' => json_encode($value['balances']),
                            'mask' => $value['mask'],
                            'name' => $value['name'],
                            'official_name' => $value['official_name'],
                            'subtype' => $value['subtype'],
                            'type' => $value['type'],
                            'json_response' => json_encode($value),
                        ]);
                    } else {
                        Balance::create([
                            'user_id' => auth()->user()->id,
                            'account_id' => $value['account_id'],
                            'balances' => json_encode($value['balances']),
                            'mask' => $value['mask'],
                            'name' => $value['name'],
                            'official_name' => $value['official_name'],
                            'subtype' => $value['subtype'],
                            'type' => $value['type'],
                            'json_response' => json_encode($value),
                        ]);
                    }
                }
                return ApiJsonResponseHelper::successResponse($data, "Data saved Successfully");
                // return response()->json($data);
            }
            return ApiJsonResponseHelper::errorResponse("Access token not found.");
        } catch (\Exception $e) {

            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }
    /**
     * @OA\Get(
     *     path="/api/user/plaid-identity",
     *     summary="Get Plaid identity",
     *     tags={"Plaid"},
     *     @OA\Response(
     *         response="default",
     *         description="Get plaid identity api details",
     *     )
     * )
     */
    public function plaidIdentityGet()
    {
        $client = new Client();
        try {
            if (isset(auth()->user()->plaid_access_token)) {
                $response = $client->post('https://sandbox.plaid.com/identity/get', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'client_id' => $this->clientId,
                        'secret' => $this->secret,
                        'access_token' => auth()->user()->plaid_access_token,
                    ],
                ]);

                $body = $response->getBody();
                $data = json_decode($body, true);

                // Process the response data as needed
                foreach ($data['accounts'] as $key => $value) {
                    $check = Identity::where('user_id', auth()->user()->id)
                        ->where('account_id', $value['account_id'])
                        ->first();

                    if ($check) {
                        $check->update([
                            'user_id' => auth()->user()->id,
                            'account_id' => $value['account_id'],
                            'balances' => json_encode($value['balances']),
                            'mask' => $value['mask'],
                            'name' => $value['name'],
                            'official_name' => $value['official_name'],
                            'owners' => json_encode($value['owners']),
                            'subtype' => $value['subtype'],
                            'type' => $value['type'],
                            'json_response' => json_encode($value),
                        ]);
                    } else {
                        Identity::create([
                            'user_id' => auth()->user()->id,
                            'account_id' => $value['account_id'],
                            'balances' => json_encode($value['balances']),
                            'mask' => $value['mask'],
                            'name' => $value['name'],
                            'official_name' => $value['official_name'],
                            'owners' => json_encode($value['owners']),
                            'subtype' => $value['subtype'],
                            'type' => $value['type'],
                            'json_response' => json_encode($value),
                        ]);
                    }
                }
                return ApiJsonResponseHelper::successResponse($data, "Data saved Successfully");
                // return response()->json($data);
            }
            return ApiJsonResponseHelper::errorResponse("Access token not found.");
        } catch (\Exception $e) {

            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }
    /**
     * @OA\Get(
     *     path="/api/user/plaid-investment-holding",
     *     summary="Get Plaid investment holding",
     *     tags={"Plaid"},
     *     @OA\Response(
     *         response="default",
     *         description="Get plaid investment holding api details",
     *     )
     * )
     */
    public function plaidInvestmentHoldingsGet()
    {
        $client = new Client();

        try {
            if (isset(auth()->user()->plaid_access_token)) {
                $response = $client->post('https://sandbox.plaid.com/investments/holdings/get', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'client_id' => $this->clientId,
                        'secret' => $this->secret,
                        'access_token' => auth()->user()->plaid_access_token,
                    ],
                ]);

                $body = $response->getBody();
                $data = json_decode($body, true);

                foreach ($data['holdings'] as $key => $value) {
                    $check = InvestmentsHolding::where('user_id', auth()->user()->id)
                        ->where('account_id', $value['account_id'])
                        ->where('security_id', $value['security_id'])
                        ->first();

                    if ($check) {
                        $check->update([
                            'cost_basis' => $value['cost_basis'],
                            'institution_price' => $value['institution_price'],
                            'institution_price_as_of' => $value['institution_price_as_of'],
                            'institution_price_datetime' => $value['institution_price_datetime'],
                            'institution_value' => $value['institution_value'],
                            'iso_currency_code' => $value['iso_currency_code'],
                            'quantity' => $value['quantity'],
                            'unofficial_currency_code' => $value['unofficial_currency_code'],
                        ]);
                    } else {
                        InvestmentsHolding::create([
                            'user_id' => auth()->user()->id,
                            'account_id' => $value['account_id'],
                            'cost_basis' => $value['cost_basis'],
                            'institution_price' => $value['institution_price'],
                            'institution_price_as_of' => $value['institution_price_as_of'],
                            'institution_price_datetime' => $value['institution_price_datetime'],
                            'institution_value' => $value['institution_value'],
                            'iso_currency_code' => $value['iso_currency_code'],
                            'quantity' => $value['quantity'],
                            'security_id' => $value['security_id'],
                            'unofficial_currency_code' => $value['unofficial_currency_code'],
                        ]);
                    }
                }
                return ApiJsonResponseHelper::successResponse($data, "Data saved Successfully");
                // return response()->json($data);
            }
            return ApiJsonResponseHelper::errorResponse("Access token not found.");
        } catch (\Exception $e) {

            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Get(
     *     path="/api/user/plaid-investment-transaction/{start_date}/{end_date}",
     *     summary="Get Plaid investment transaction",
     *     tags={"Plaid"},
     *     @OA\Parameter(
     *         name="start_date",
     *         in="path",
     *         description="Start date in the format of YYYY-MM-DD",
     *         required=true,
     *         @OA\Schema(
     *             type="string",
     *             format="date",
     *             default="2019-01-01",
     *         ),
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="path",
     *         description="End date in the format of YYYY-MM-DD",
     *         required=true,
     *         @OA\Schema(
     *             type="string",
     *             format="date",
     *             default="2021-01-01",
     *         ),
     *     ),
     *     @OA\Response(
     *         response="default",
     *         description="Get Plaid investment transaction API details",
     *     ),
     * )
     */
    public function plaidInvestmentTransactionGet($start_date, $end_date)
    {
        $client = new Client();

        try {
            if (isset(auth()->user()->plaid_access_token)) {
                $response = $client->post('https://sandbox.plaid.com/investments/transactions/get', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'client_id' => $this->clientId,
                        'secret' => $this->secret,
                        'access_token' => auth()->user()->plaid_access_token,
                        'start_date' => $start_date,
                        'end_date' => $end_date,
                    ],
                ]);

                $body = $response->getBody();
                $data = json_decode($body, true);

                // Process the response data as needed
                foreach ($data['investment_transactions'] as $key => $value) {
                    $check = InvestmentsTransaction::where('user_id', auth()->user()->id)
                        ->where('account_id', $value['account_id'])
                        ->where('investment_transaction_id', $value['investment_transaction_id'])
                        ->first();

                    if ($check) {
                        $check->update([
                            'amount' => $value['amount'],
                            'cancel_transaction_id' => $value['cancel_transaction_id'],
                            'date' => $value['date'],
                            'fees' => $value['fees'],
                            'iso_currency_code' => $value['iso_currency_code'],
                            'name' => $value['name'],
                            'price' => $value['price'],
                            'quantity' => $value['quantity'],
                            'security_id' => $value['security_id'],
                            'subtype' => $value['subtype'],
                            'type' => $value['type'],
                            'unofficial_currency_code' => $value['unofficial_currency_code'],
                        ]);
                    } else {
                        InvestmentsTransaction::create([
                            'user_id' => auth()->user()->id,
                            'account_id' => $value['account_id'],
                            'amount' => $value['amount'],
                            'cancel_transaction_id' => $value['cancel_transaction_id'],
                            'date' => $value['date'],
                            'fees' => $value['fees'],
                            'investment_transaction_id' => $value['investment_transaction_id'],
                            'iso_currency_code' => $value['iso_currency_code'],
                            'name' => $value['name'],
                            'price' => $value['price'],
                            'quantity' => $value['quantity'],
                            'security_id' => $value['security_id'],
                            'subtype' => $value['subtype'],
                            'type' => $value['type'],
                            'unofficial_currency_code' => $value['unofficial_currency_code'],
                        ]);
                    }
                }
                return ApiJsonResponseHelper::successResponse($data, "Data saved Successfully");
                // return response()->json($data);
            }
            return ApiJsonResponseHelper::errorResponse("Access token not found.");
        } catch (\Exception $e) {

            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Get(
     *     path="/api/user/plaid-liabilities",
     *     summary="Get Plaid liabilities",
     *     tags={"Plaid"},
     *     @OA\Response(
     *         response="default",
     *         description="Get plaid liabilities api details",
     *     )
     * )
     */
    public function plaidLiabilitiesGet()
    {
        $client = new Client();
        // dd(auth()->user()->plaid_access_token);
        try {
            if (isset(auth()->user()->plaid_access_token)) {
                $response = $client->post('https://sandbox.plaid.com/liabilities/get', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'client_id' => $this->clientId,
                        'secret' => $this->secret,
                        'access_token' => auth()->user()->plaid_access_token,
                    ],
                ]);

                $body = $response->getBody();
                $data = json_decode($body, true);

                // Process the response data as needed
                foreach ($data['accounts'] as $key => $value) {
                    $check = Liability::where('user_id', auth()->user()->id)
                        ->where('account_id', $value['account_id'])
                        ->first();

                    if ($check) {
                        $check->update([
                            'user_id' => auth()->user()->id,
                            'account_id' => $value['account_id'],
                            'balances' => json_encode($value['balances']),
                            'mask' => $value['mask'],
                            'name' => $value['name'],
                            'official_name' => $value['official_name'],
                            'subtype' => $value['subtype'],
                            'type' => $value['type'],
                            'json_response' => json_encode($value),
                        ]);
                    } else {
                        Liability::create([
                            'user_id' => auth()->user()->id,
                            'account_id' => $value['account_id'],
                            'balances' => json_encode($value['balances']),
                            'mask' => $value['mask'],
                            'name' => $value['name'],
                            'official_name' => $value['official_name'],
                            'subtype' => $value['subtype'],
                            'type' => $value['type'],
                            'json_response' => json_encode($value),
                        ]);
                    }
                }
                return ApiJsonResponseHelper::successResponse($data, "Data saved Successfully");
                return response()->json($data);
            }
            return ApiJsonResponseHelper::errorResponse("Access token not found.");
        } catch (\Exception $e) {

            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/api/user/plaid-enrich",
     *     summary="Enrich Plaid transactions",
     *     tags={"Plaid"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="account_type",
     *                 type="string",
     *                 example="credit"
     *             ),
     *             @OA\Property(
     *                 property="transactions",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(
     *                         property="id",
     *                         type="string",
     *                         example="6135818adda16500147e7c1d"
     *                     ),
     *                     @OA\Property(
     *                         property="description",
     *                         type="string",
     *                         example="PURCHASE WM SUPERCENTER #1700"
     *                     ),
     *                     @OA\Property(
     *                         property="amount",
     *                         type="number",
     *                         format="float",
     *                         example=72.10
     *                     ),
     *                     @OA\Property(
     *                         property="direction",
     *                         type="string",
     *                         example="OUTFLOW"
     *                     ),
     *                     @OA\Property(
     *                         property="iso_currency_code",
     *                         type="string",
     *                         example="USD"
     *                     ),
     *                     @OA\Property(
     *                         property="location",
     *                         type="object",
     *                         @OA\Property(
     *                             property="city",
     *                             type="string",
     *                             example="Poway"
     *                         ),
     *                         @OA\Property(
     *                             property="region",
     *                             type="string",
     *                             example="CA"
     *                         ),
     *                     ),
     *                     @OA\Property(
     *                         property="mcc",
     *                         type="string",
     *                         example="5310"
     *                     ),
     *                     @OA\Property(
     *                         property="date_posted",
     *                         type="string",
     *                         format="date",
     *                         example="2022-07-05"
     *                     ),
     *                 ),
     *             ),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Enriched Plaid transactions response",
     *     ),
     * )
     */
    public function plaidEnrich(Request $request)
    {
        $client = new Client();
        try {
            $response = $client->post('https://sandbox.plaid.com/transactions/enrich', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'client_id' => $this->clientId,
                    'secret' => $this->secret,
                    'account_type' => $request->account_type,
                    'transactions' => $request->transactions,
                    // [
                    //     [
                    //         'id' => '6135818adda16500147e7c1d',
                    //         'description' => 'PURCHASE WM SUPERCENTER #1700',
                    //         'amount' => 72.10,
                    //         'direction' => 'OUTFLOW',
                    //         'iso_currency_code' => 'USD',
                    //         'location' => [
                    //             'city' => 'Poway',
                    //             'region' => 'CA',
                    //         ],
                    //         'mcc' => '5310',
                    //         'date_posted' => '2022-07-05',
                    //     ],
                    //     [
                    //         'id' => '3958434bhde9384bcmeo3401',
                    //         'description' => 'DD DOORDASH BURGERKIN 855-123-4567 CA',
                    //         'amount' => 28.34,
                    //         'direction' => 'OUTFLOW',
                    //         'iso_currency_code' => 'USD',
                    //     ],
                    // ],
                ],
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            // Process the response data as needed
            foreach ($data['enriched_transactions'] as $key => $value) {
                $check = Enrich::where('user_id', auth()->user()->id)
                    ->first();

                if ($check) {
                    $check->update([
                        'user_id' => auth()->user()->id,
                        //'account_id'=>$value['account_id'],   Not present in get request but present in database
                        'amount' => $value['amount'],
                        'description' => $value['description'],
                        'direction' => $value['direction'],
                        'enrichments' => json_encode($value['enrichments']),
                        'plaid_id' => $value['id'],
                        'iso_currency_code' => $value['iso_currency_code'],
                        'json_response' => json_encode($value),
                    ]);
                } else {
                    Enrich::create([
                        'user_id' => auth()->user()->id,
                        //  'account_id'=>$value['account_id'], // Not present in get request but present in database
                        'amount' => $value['amount'],
                        'description' => $value['description'],
                        'direction' => $value['direction'],
                        'enrichments' => json_encode($value['enrichments']),
                        'plaid_id' => $value['id'],
                        'iso_currency_code' => $value['iso_currency_code'],
                        'json_response' => json_encode($value),
                    ]);
                }
            }
            return ApiJsonResponseHelper::successResponse($data, "Data saved Successfully");
            return response()->json($data);
        } catch (\Exception $e) {

            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/api/user/plaid-generate-link-token",
     *     summary="Generate Plaid Link token",
     *     tags={"Plaid"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="client_name",
     *                 type="string",
     *                 example="Muhammad Izhan"
     *             ),
     *             @OA\Property(
     *                 property="country_codes",
     *                 type="array",
     *                 @OA\Items(
     *                     type="string",
     *                     example="US"
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="language",
     *                 type="string",
     *                 example="en"
     *             ),
     *             @OA\Property(
     *                 property="user",
     *                 type="object",
     *                 @OA\Property(
     *                     property="client_user_id",
     *                     type="string",
     *                     example="1"
     *                 ),
     *             ),
     *             @OA\Property(
     *                 property="products",
     *                 type="array",
     *                 @OA\Items(
     *                     type="string",
     *                     example={"auth", "transactions", "identity", "investments"}
     *                 )
     *             ),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Generated Plaid Link token",
     *     ),
     * )
     */
    public function plaidGenerateLinkToken(Request $request)
    {
        $client = new Client();

        try {
            $response = $client->post('https://sandbox.plaid.com/link/token/create', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'client_id' => $this->clientId,
                    'secret' => $this->secret,
                    'client_name' => $request->client_name,
                    'country_codes' => $request->country_codes,
                    'language' => $request->language,
                    'user' => $request->user,
                    'products' => ['auth', 'transactions', 'identity', 'investments'],
                ],
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            // Process the response data as needed

            return response()->json($data);
        } catch (\Exception $e) {

            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/api/user/plaid-user-generate-token",
     *     summary="Create Plaid user",
     *     tags={"Plaid Income"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="client_user_id",
     *                 type="string",
     *                 example="5"
     *             ),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Plaid user created successfully",
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Error message"
     *             ),
     *         ),
     *     ),
     * )
     */
    public function plaidGenerateUserToken(Request $request)
    {
        $client = new Client();
        $payload = [];

        try {
            $payload = $request->all();
            $payload['client_id'] = $this->clientId;
            $payload['secret'] = $this->secret;

            $response = $client->post('https://sandbox.plaid.com/user/create', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            // Process the response data as needed
            return response()->json($data);
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/api/user/plaid-generate-public-token-for-payroll-income",
     *     summary="Create Plaid sandbox public token for sandbox only",
     *     tags={"Plaid Income"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="user_token",
     *                 type="string",
     *                 example="user-sandbox-d7e4ec88-dac7-4f37-92d6-81402c1a53cd"
     *             ),
     *             @OA\Property(
     *                 property="institution_id",
     *                 type="string",
     *                 example="ins_90"
     *             ),
     *             @OA\Property(
     *                 property="initial_products",
     *                 type="array",
     *                 @OA\Items(
     *                     type="string",
     *                     example="income_verification"
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="options",
     *                 type="object",
     *                 @OA\Property(
     *                     property="webhook",
     *                     type="string",
     *                     example="https://www.genericwebhookurl.com/webhook"
     *                 )
     *             )
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Plaid sandbox public token created successfully",
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Error message"
     *             ),
     *         ),
     *     ),
     * )
     */
    public function plaidSandboxPublicTokenForPayrollCreate(Request $request)
    {
        $client = new Client();

        $payload = [];

        try {
            $payload = $request->all();
            $payload['client_id'] = $this->clientId;
            $payload['secret'] = $this->secret;

            $response = $client->post('https://sandbox.plaid.com/sandbox/public_token/create', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            // Process the response data as needed

            return response()->json($data);
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/api/user/plaid-generate-payroll-income-link-token",
     *     summary="Generate Plaid Link token",
     *     tags={"Plaid Income"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="user_token",
     *                 type="string",
     *                 example="user-sandbox-d7e4ec88-dac7-4f37-92d6-81402c1a53cd"
     *             ),
     *             @OA\Property(
     *                 property="client_name",
     *                 type="string",
     *                 example="Insert Client name here"
     *             ),
     *             @OA\Property(
     *                 property="country_codes",
     *                 type="array",
     *                 @OA\Items(
     *                     type="string",
     *                     example="US"
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="language",
     *                 type="string",
     *                 example="en"
     *             ),
     *             @OA\Property(
     *                 property="user",
     *                 type="object",
     *                 @OA\Property(
     *                     property="client_user_id",
     *                     type="string",
     *                     example="unique_user_id"
     *                 ),
     *             ),
     *             @OA\Property(
     *                 property="products",
     *                 type="array",
     *                 @OA\Items(
     *                     type="string",
     *                     example="income_verification"
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="income_verification",
     *                 type="object",
     *                 @OA\Property(
     *                     property="income_source_types",
     *                     type="array",
     *                     @OA\Items(
     *                         type="string",
     *                         example="payroll"
     *                     )
     *                 ),
     *                 @OA\Property(
     *                     property="payroll_income",
     *                     type="object",
     *                     @OA\Property(
     *                         property="flow_types",
     *                         type="array",
     *                         @OA\Items(
     *                             type="string",
     *                             example="digital"
     *                         )
     *                     )
     *                 )
     *             ),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Generated Plaid Link token",
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Error message"
     *             ),
     *         ),
     *     ),
     * )
     */
    public function generatePayrollIncomeToken(Request $request)
    {
        $client = new Client();

        $payload = [];
        try {
            $payload = $request->all();
            $payload['client_id'] = $this->clientId;
            $payload['secret'] = $this->secret;

            $response = $client->post('https://sandbox.plaid.com/link/token/create', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            // Process the response data as needed

            return response()->json($data);
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/api/user/plaid-credit-payroll-income",
     *     summary="Get Plaid credit payroll income",
     *     tags={"Plaid Income"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="user_token",
     *                 type="string",
     *                 example="user-sandbox-d7e4ec88-dac7-4f37-92d6-81402c1a53cd"
     *             ),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Plaid credit payroll income retrieved successfully",
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Error message"
     *             ),
     *         ),
     *     ),
     * )
     */
    public function plaidCreditPayrollIncome(Request $request)
    {
        $client = new Client();
        $userToken = "user-sandbox-d7e4ec88-dac7-4f37-92d6-81402c1a53cd";

        try {
            $response = $client->post('https://sandbox.plaid.com/credit/payroll_income/get', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'client_id' => $this->clientId,
                    'secret' => $this->secret,
                    'user_token' => $request->user_token,
                ],
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            // Process the response data as needed

            return response()->json($data);
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/api/user/plaid-generate-public-token-for-bank-income",
     *     summary="Create Plaid sandbox public token",
     *     tags={"Plaid"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="user_token",
     *                 type="string",
     *                 example="user-sandbox-d7e4ec88-dac7-4f37-92d6-81402c1a53cd"
     *             ),
     *             @OA\Property(
     *                 property="institution_id",
     *                 type="string",
     *                 example="ins_20"
     *             ),
     *             @OA\Property(
     *                 property="initial_products",
     *                 type="array",
     *                 @OA\Items(
     *                     type="string",
     *                     example="income_verification"
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="options",
     *                 type="object",
     *                 @OA\Property(
     *                     property="webhook",
     *                     type="string",
     *                     example="https://www.genericwebhookurl.com/webhook"
     *                 ),
     *                 @OA\Property(
     *                     property="override_username",
     *                     type="string",
     *                     example="user_bank_income"
     *                 ),
     *                 @OA\Property(
     *                     property="override_password",
     *                     type="string",
     *                     example="{}"
     *                 ),
     *                 @OA\Property(
     *                     property="income_verification",
     *                     type="object",
     *                     @OA\Property(
     *                         property="income_source_types",
     *                         type="array",
     *                         @OA\Items(
     *                             type="string",
     *                             example="bank"
     *                         )
     *                     ),
     *                     @OA\Property(
     *                         property="bank_income",
     *                         type="object",
     *                         @OA\Property(
     *                             property="days_requested",
     *                             type="integer",
     *                             example=180
     *                         )
     *                     )
     *                 )
     *             )
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Plaid sandbox public token created successfully",
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Error message"
     *             ),
     *         ),
     *     ),
     * )
     */
    public function plaidSandboxPublicTokenForBankCreate(Request $request)
    {
        $client = new Client();
        $payload = [];
        try {

            $payload = $request->all();
            $payload['client_id'] = $this->clientId;
            $payload['secret'] = $this->secret;
            $response = $client->post('https://sandbox.plaid.com/sandbox/public_token/create', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            // Process the response data as needed

            return response()->json($data);
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/api/user/plaid-generate-bank-income-link-token",
     *     summary="Create Plaid link token for bank income",
     *     tags={"Plaid Income"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="user_token",
     *                 type="string",
     *                 example="user-sandbox-d7e4ec88-dac7-4f37-92d6-81402c1a53cd"
     *             ),
     *             @OA\Property(
     *                 property="client_name",
     *                 type="string",
     *                 example="Insert Client name here"
     *             ),
     *             @OA\Property(
     *                 property="country_codes",
     *                 type="array",
     *                 @OA\Items(
     *                     type="string",
     *                     example="US"
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="language",
     *                 type="string",
     *                 example="en"
     *             ),
     *             @OA\Property(
     *                 property="user",
     *                 type="object",
     *                 @OA\Property(
     *                     property="client_user_id",
     *                     type="string",
     *                     example="5"
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="products",
     *                 type="array",
     *                 @OA\Items(
     *                     type="string",
     *                     example="income_verification"
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="income_verification",
     *                 type="object",
     *                 @OA\Property(
     *                     property="income_source_types",
     *                     type="array",
     *                     @OA\Items(
     *                         type="string",
     *                         example="bank"
     *                     )
     *                 ),
     *                 @OA\Property(
     *                     property="bank_income",
     *                     type="object",
     *                     @OA\Property(
     *                         property="days_requested",
     *                         type="integer",
     *                         example=365
     *                     )
     *                 )
     *             )
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Plaid link token created successfully",
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Error message"
     *             ),
     *         ),
     *     ),
     * )
     */
    public function generateBankIncomeToken(Request $request)
    {
        $client = new Client();
        $payload = [];
        try {

            $payload = $request->all();
            $payload['client_id'] = $this->clientId;
            $payload['secret'] = $this->secret;

            $response = $client->post('https://sandbox.plaid.com/link/token/create', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            // Process the response data as needed

            return response()->json($data);
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/api/user/plaid-credit-bank-income",
     *     summary="Get Plaid credit bank income",
     *     tags={"Plaid Income"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="user_token",
     *                 type="string",
     *                 example="user-sandbox-d7e4ec88-dac7-4f37-92d6-81402c1a53cd"
     *             ),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Plaid credit bank income retrieved successfully",
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Error message"
     *             ),
     *         ),
     *     ),
     * )
     */
    public function plaidCreditBankIncome(Request $request)
    {
        $client = new Client();

        try {
            $response = $client->post('https://sandbox.plaid.com/credit/bank_income/get', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'client_id' => $this->clientId,
                    'secret' => $this->secret,
                    'user_token' => $request->user_token,
                ],
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            // Process the response data as needed

            return response()->json($data);
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/api/user/plaid-asset-report-create",
     *     summary="Create Plaid asset report",
     *     tags={"Plaid Asset Report"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="days_requested",
     *                 type="integer",
     *                 example=30
     *             ),
     *             @OA\Property(
     *                 property="options",
     *                 type="object",
     *                 @OA\Property(
     *                     property="client_report_id",
     *                     type="string",
     *                     example="ENTER_CLIENT_REPORT_ID_HERE"
     *                 ),
     *                 @OA\Property(
     *                     property="webhook",
     *                     type="string",
     *                     example="https://www.example.com/webhook"
     *                 ),
     *                 @OA\Property(
     *                     property="user",
     *                     type="object",
     *                     @OA\Property(
     *                         property="client_user_id",
     *                         type="string",
     *                         example="ENTER_USER_ID_HERE"
     *                     ),
     *                     @OA\Property(
     *                         property="first_name",
     *                         type="string",
     *                         example="ENTER_FIRST_NAME_HERE"
     *                     ),
     *                     @OA\Property(
     *                         property="middle_name",
     *                         type="string",
     *                         example="ENTER_MIDDLE_NAME_HERE"
     *                     ),
     *                     @OA\Property(
     *                         property="last_name",
     *                         type="string",
     *                         example="ENTER_LAST_NAME_HERE"
     *                     ),
     *                     @OA\Property(
     *                         property="ssn",
     *                         type="string",
     *                         example="111-22-1234"
     *                     ),
     *                     @OA\Property(
     *                         property="phone_number",
     *                         type="string",
     *                         example="1-415-867-5309"
     *                     ),
     *                     @OA\Property(
     *                         property="email",
     *                         type="string",
     *                         example="ENTER_EMAIL_HERE"
     *                     ),
     *                 ),
     *             ),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Plaid asset report created successfully",
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Error message"
     *             ),
     *         ),
     *     ),
     * )
     */
    public function plaidAssetReportCreate(Request $request)
    {
        $client = new Client();
        try {
            if (isset(auth()->user()->plaid_access_token)) {
                $response = $client->post('https://sandbox.plaid.com/asset_report/create', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'client_id' => $this->clientId,
                        'secret' => $this->secret,
                        'access_tokens' => [auth()->user()->plaid_access_token],
                        'days_requested' => $request->days_requested,
                        'options' => $request->options,
                    ],
                ]);

                $body = $response->getBody();
                $data = json_decode($body, true);

                // Process the response data as needed

                return response()->json($data);
            }
            return ApiJsonResponseHelper::errorResponse("Access token not found.");
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/api/user/plaid-asset-report-get",
     *     summary="Get Plaid asset report",
     *     tags={"Plaid Asset Report"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="asset_report_token",
     *                 type="string",
     *                 example="assets-sandbox-8343df3a-a3e0-4d40-99aa-8c23e45161c3"
     *             )
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Plaid asset report retrieved successfully",
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Error message"
     *             ),
     *         ),
     *     ),
     * )
     */
    public function plaidAssetReportGet(Request $request)
    {
        $client = new Client();

        $payload = [];
        try {

            $payload = $request->all();
            $payload['client_id'] = $this->clientId;
            $payload['secret'] = $this->secret;

            $response = $client->post('https://sandbox.plaid.com/asset_report/get', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            // Process the response data as needed

            return response()->json($data);
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }
    /**
     * @OA\Post(
     *     path="/api/user/plaid-asset-report-pdf-get",
     *     summary="Get Plaid asset report PDF",
     *     tags={"Plaid Asset Report"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="asset_report_token",
     *                 type="string",
     *                 example="assets-sandbox-8343df3a-a3e0-4d40-99aa-8c23e45161c3"
     *             )
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Plaid asset report PDF retrieved successfully",
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Error message"
     *             ),
     *         ),
     *     ),
     * )
     */
    public function plaidAssetReportPDFGet(Request $request)
    {
        $client = new Client();

        $payload = [];
        try {

            $payload = $request->all();
            $payload['client_id'] = $this->clientId;
            $payload['secret'] = $this->secret;

            $response = $client->post('https://sandbox.plaid.com/asset_report/pdf/get', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            // Process the PDF response as needed

            $pdfContents = $response->getBody()->getContents();

            return new Response($pdfContents, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="asset_report.pdf"',
            ]);
        } catch (\Exception $e) {
            return ApiJsonResponseHelper::errorResponse($e->getMessage());
        }
    }
}
