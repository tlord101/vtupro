<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Constants\GlobalConst;
use App\Constants\NotificationConst;
use App\Constants\PaymentGatewayConst;
use App\Http\Controllers\Controller;
use App\Http\Helpers\PeyflexHelper;
use App\Http\Helpers\Api\helpers as Helpers;
use App\Http\Helpers\NotificationHelper;
use App\Models\Admin\ExchangeRate;
use App\Models\Admin\TransactionSetting;
use App\Models\Transaction;
use App\Models\UserNotification;
use App\Models\UserWallet;
use App\Notifications\Admin\ActivityNotification;
use App\Notifications\User\MobileTopup\TopupAutomaticMail;
use App\Providers\Admin\BasicSettingsProvider;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Http\Helpers\PushNotificationHelper;

class PeyflexAirtimeController extends Controller
{
    protected $basic_settings;

    public function __construct()
    {
        $this->basic_settings = BasicSettingsProvider::get();
    }

    /**
     * Get available airtime networks
     */
    public function getNetworks(Request $request)
    {
        try {
            $peyflex = new PeyflexHelper();
            $response = $peyflex->getAirtimeNetworks();

            if(!$response['status']) {
                return Helpers::error([__($response['message'])]);
            }

            $message = ['success' => [__('Airtime networks fetched successfully')]];
            return Helpers::success($response['data'] ?? [], $message);
        } catch (Exception $e) {
            $message = app()->environment() == "production" ? __("Failed to fetch networks") : $e->getMessage();
            return Helpers::error([$message], [], 500);
        }
    }

    /**
     * Check operator and get details
     */
    public function checkNetwork(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'network' => 'required|string',
            'mobile_number' => 'required|string',
        ]);

        if($validator->fails()) {
            $error = ['error' => $validator->errors()->all()];
            return Helpers::validation($error);
        }

        try {
            // Validate mobile number format
            $mobile_number = remove_special_char($request->mobile_number);
            
            if(strlen($mobile_number) < 10 || strlen($mobile_number) > 15) {
                return Helpers::error([__('Invalid mobile number length')]);
            }

            $message = ['success' => [__('Network validated successfully')]];
            return Helpers::success([
                'network' => $request->network,
                'mobile_number' => $mobile_number,
                'valid' => true
            ], $message);
        } catch (Exception $e) {
            return Helpers::error([__('Network validation failed')]);
        }
    }

    /**
     * Purchase airtime
     */
    public function purchase(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'network' => 'required|string',
            'mobile_number' => 'required|string|min:10|max:15',
            'amount' => 'required|numeric|gt:0',
        ]);

        if($validator->fails()) {
            $error = ['error' => $validator->errors()->all()];
            return Helpers::validation($error);
        }

        $validated = $validator->validate();
        $user = authGuardApi()['user'];
        $sender_wallet = UserWallet::where('user_id', $user->id)->first();

        if(!$sender_wallet) {
            $error = ['error' => [__('User wallet not found')]];
            return Helpers::error($error);
        }

        // Get transaction settings
        $topupCharge = TransactionSetting::where('slug', 'mobile_topup')->where('status', 1)->first();
        
        if(!$topupCharge) {
            return Helpers::error([__('Service temporarily unavailable')]);
        }

        // Calculate charges
        $charges = $this->calculateCharges(
            $validated['amount'],
            $topupCharge,
            $sender_wallet
        );

        // Check wallet balance
        if($charges['payable'] > $sender_wallet->balance) {
            $error = ['error' => [__('Insufficient balance')]];
            return Helpers::error($error);
        }

        try {
            // Call Peyflex API
            $peyflex = new PeyflexHelper();
            $topup_data = [
                'network' => $validated['network'],
                'mobile_number' => remove_special_char($validated['mobile_number']),
                'amount' => $validated['amount'],
            ];

            $api_response = $peyflex->airtimeTopup($topup_data);

            if(!$api_response['status']) {
                return Helpers::error([__($api_response['message'])]);
            }

            // Insert transaction record
            $trx_id = 'AT' . getTrxNum();
            $transaction_id = $this->insertTransaction(
                $trx_id,
                $sender_wallet,
                $charges,
                $validated,
                $api_response
            );

            // Send notifications
            if($this->basic_settings->email_notification == true) {
                try {
                    $notifyData = [
                        'trx_id' => $trx_id,
                        'network' => $validated['network'],
                        'mobile_number' => $validated['mobile_number'],
                        'amount' => get_amount($validated['amount'], $sender_wallet->currency->code),
                        'charges' => get_amount($charges['total_charge'], $sender_wallet->currency->code),
                        'payable' => get_amount($charges['payable'], $sender_wallet->currency->code),
                        'balance' => get_amount($sender_wallet->balance - $charges['payable'], $sender_wallet->currency->code),
                        'status' => __('Successful'),
                    ];
                    $user->notify(new TopupAutomaticMail($user, (object)$notifyData));
                } catch(Exception $e) {}
            }

            $message = ['success' => [__('Airtime purchase successful')]];
            return Helpers::success([
                'trx_id' => $trx_id,
                'transaction_id' => $api_response['transaction_id'] ?? null,
                'status' => 'successful'
            ], $message);
        } catch(Exception $e) {
            return Helpers::error([__('Transaction failed. Please try again.')], [], 500);
        }
    }

    /**
     * Calculate charges for airtime purchase
     */
    private function calculateCharges($amount, $topupCharge, $wallet)
    {
        $percent_charge = ($amount * ($topupCharge->percent_charge ?? 0)) / 100;
        $fixed_charge = $topupCharge->fixed_charge ?? 0;
        $total_charge = $percent_charge + $fixed_charge;
        $payable = $amount + $total_charge;

        return [
            'amount' => $amount,
            'percent_charge' => $percent_charge,
            'fixed_charge' => $fixed_charge,
            'total_charge' => $total_charge,
            'payable' => $payable,
            'currency' => $wallet->currency->code,
        ];
    }

    /**
     * Insert transaction record
     */
    private function insertTransaction($trx_id, $sender_wallet, $charges, $validated, $api_response)
    {
        $status = $api_response['response']['status'] ?? 'PROCESSING';
        $transaction_status = strtoupper($status) === 'SUCCESSFUL' 
            ? PaymentGatewayConst::STATUSSUCCESS 
            : PaymentGatewayConst::STATUSPROCESSING;

        $details = [
            'network' => $validated['network'],
            'mobile_number' => $validated['mobile_number'],
            'api_transaction_id' => $api_response['transaction_id'] ?? null,
            'api_response' => $api_response['response'] ?? [],
        ];

        $new_balance = $sender_wallet->balance - $charges['payable'];

        DB::beginTransaction();
        try {
            $transaction_id = DB::table('transactions')->insertGetId([
                'user_id' => $sender_wallet->user_id,
                'wallet_id' => $sender_wallet->id,
                'type' => 'PEYFLEX_AIRTIME',
                'trx_id' => $trx_id,
                'request_amount' => $charges['amount'],
                'exchange_rate' => 1,
                'percent_charge' => $charges['percent_charge'],
                'fixed_charge' => $charges['fixed_charge'],
                'total_charge' => $charges['total_charge'],
                'request_currency' => $charges['currency'],
                'total_payable' => $charges['payable'],
                'payment_currency' => $charges['currency'],
                'available_balance' => $new_balance,
                'remark' => 'Peyflex Airtime Purchase Successful',
                'details' => json_encode($details),
                'status' => $transaction_status,
                'created_at' => now(),
            ]);

            // Update wallet balance
            $sender_wallet->update(['balance' => $new_balance]);

            DB::commit();
            return $transaction_id;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
