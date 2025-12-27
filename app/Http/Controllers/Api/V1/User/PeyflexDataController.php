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

class PeyflexDataController extends Controller
{
    protected $basic_settings;

    public function __construct()
    {
        $this->basic_settings = BasicSettingsProvider::get();
    }

    /**
     * Get available data networks
     */
    public function getNetworks(Request $request)
    {
        try {
            $peyflex = new PeyflexHelper();
            $response = $peyflex->getDataNetworks();

            if(!$response['status']) {
                return Helpers::error([__($response['message'])]);
            }

            $message = ['success' => [__('Data networks fetched successfully')]];
            return Helpers::success($response['data'] ?? [], $message);
        } catch (Exception $e) {
            $message = app()->environment() == "production" ? __("Failed to fetch networks") : $e->getMessage();
            return Helpers::error([$message], [], 500);
        }
    }

    /**
     * Get plans for a network
     */
    public function getPlans(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'network' => 'required|string',
        ]);

        if($validator->fails()) {
            $error = ['error' => $validator->errors()->all()];
            return Helpers::validation($error);
        }

        try {
            $peyflex = new PeyflexHelper();
            $response = $peyflex->getDataPlans($request->network);

            if(!$response['status']) {
                return Helpers::error([__($response['message'])]);
            }

            $message = ['success' => [__('Data plans fetched successfully')]];
            return Helpers::success($response['data'] ?? [], $message);
        } catch (Exception $e) {
            $message = app()->environment() == "production" ? __("Failed to fetch plans") : $e->getMessage();
            return Helpers::error([$message], [], 500);
        }
    }

    /**
     * Calculate purchase charges
     */
    public function calculateCharges(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'network' => 'required|string',
            'plan_code' => 'required|string',
            'amount' => 'required|numeric|gt:0',
        ]);

        if($validator->fails()) {
            $error = ['error' => $validator->errors()->all()];
            return Helpers::validation($error);
        }

        try {
            $data_charge = TransactionSetting::where('slug', 'data_bundle')->where('status', 1)->first();
            
            if(!$data_charge) {
                return Helpers::error([__('Service temporarily unavailable')]);
            }

            $amount = $request->amount;
            $percent_charge = ($amount * ($data_charge->percent_charge ?? 0)) / 100;
            $fixed_charge = $data_charge->fixed_charge ?? 0;
            $total_charge = $percent_charge + $fixed_charge;
            $payable = $amount + $total_charge;

            $message = ['success' => [__('Charges calculated successfully')]];
            return Helpers::success([
                'amount' => get_amount($amount),
                'percent_charge' => get_amount($percent_charge),
                'fixed_charge' => get_amount($fixed_charge),
                'total_charge' => get_amount($total_charge),
                'payable' => get_amount($payable),
            ], $message);
        } catch (Exception $e) {
            return Helpers::error([__('Failed to calculate charges')], [], 500);
        }
    }

    /**
     * Purchase data bundle
     */
    public function purchase(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'network' => 'required|string',
            'mobile_number' => 'required|string|min:10|max:15',
            'plan_code' => 'required|string',
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
        $data_charge = TransactionSetting::where('slug', 'data_bundle')->where('status', 1)->first();
        
        if(!$data_charge) {
            return Helpers::error([__('Service temporarily unavailable')]);
        }

        // Calculate charges
        $charges = $this->calculateTransactionCharges(
            $validated['amount'],
            $data_charge,
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
            $purchase_data = [
                'network' => $validated['network'],
                'mobile_number' => remove_special_char($validated['mobile_number']),
                'plan_code' => $validated['plan_code'],
            ];

            $api_response = $peyflex->dataPurchase($purchase_data);

            if(!$api_response['status']) {
                return Helpers::error([__($api_response['message'])]);
            }

            // Insert transaction record
            $trx_id = 'DP' . getTrxNum();
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
                        'plan_code' => $validated['plan_code'],
                        'amount' => get_amount($validated['amount'], $sender_wallet->currency->code),
                        'charges' => get_amount($charges['total_charge'], $sender_wallet->currency->code),
                        'payable' => get_amount($charges['payable'], $sender_wallet->currency->code),
                        'balance' => get_amount($sender_wallet->balance - $charges['payable'], $sender_wallet->currency->code),
                        'status' => __('Successful'),
                    ];
                    $user->notify(new TopupAutomaticMail($user, (object)$notifyData));
                } catch(Exception $e) {}
            }

            $message = ['success' => [__('Data purchase successful')]];
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
     * Calculate charges for data purchase
     */
    private function calculateTransactionCharges($amount, $data_charge, $wallet)
    {
        $percent_charge = ($amount * ($data_charge->percent_charge ?? 0)) / 100;
        $fixed_charge = $data_charge->fixed_charge ?? 0;
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
            'plan_code' => $validated['plan_code'],
            'api_transaction_id' => $api_response['transaction_id'] ?? null,
            'api_response' => $api_response['response'] ?? [],
        ];

        $new_balance = $sender_wallet->balance - $charges['payable'];

        DB::beginTransaction();
        try {
            $transaction_id = DB::table('transactions')->insertGetId([
                'user_id' => $sender_wallet->user_id,
                'wallet_id' => $sender_wallet->id,
                'type' => 'PEYFLEX_DATA',
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
                'remark' => 'Peyflex Data Purchase Successful',
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
