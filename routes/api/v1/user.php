<?php

use App\Http\Controllers\Api\V1\User\GiftCardController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\User\ProfileController;
use App\Http\Controllers\Api\V1\User\AddMoneyController;
use App\Http\Controllers\Api\V1\User\DashboardController;
use App\Http\Controllers\Api\V1\User\DataBundleController;
use App\Http\Controllers\Api\V1\User\MobileTopupController;
use App\Http\Controllers\Api\V1\User\TransactionController;
use App\Http\Controllers\Api\V1\User\PeyflexAirtimeController;
use App\Http\Controllers\Api\V1\User\PeyflexDataController;

Route::prefix("user")->name("api.user.")->group(function(){

    Route::controller(ProfileController::class)->prefix('profile')->group(function(){
        Route::get('info','profileInfo');
        Route::post('info/update','profileInfoUpdate');
        Route::post('password/update','profilePasswordUpdate');
        Route::post('delete-account','deleteProfile');
    });

    // Logout Route
    Route::post('logout',[ProfileController::class,'logout']);

    // // Add Money Routes
    Route::controller(AddMoneyController::class)->prefix("add-money")->name('add.money.')->group(function(){
        Route::get("payment-gateways","getPaymentGateways");

        // Submit with automatic gateway
        Route::post("automatic/submit","automaticSubmit");

        // Automatic Gateway Response Routes
        Route::get('success/response/{gateway}','success')->withoutMiddleware(['auth:api'])->name("payment.success");
        Route::get("cancel/response/{gateway}",'cancel')->withoutMiddleware(['auth:api'])->name("payment.cancel");

        // POST Route For Unauthenticated Request
        Route::post('success/response/{gateway}', 'postSuccess')->name('payment.success')->withoutMiddleware(['auth:api']);
        Route::post('cancel/response/{gateway}', 'postCancel')->name('payment.cancel')->withoutMiddleware(['auth:api']);

        //redirect with Btn Pay
        Route::get('redirect/btn/checkout/{gateway}', 'redirectBtnPay')->name('payment.btn.pay')->withoutMiddleware(['auth:api']);

        Route::get('manual/input-fields','manualInputFields');

        // Submit with manual gateway
        Route::post("manual/submit","manualSubmit");

        // Automatic gateway additional fields
        Route::get('payment-gateway/additional-fields','gatewayAdditionalFields');

        Route::prefix('payment')->name('payment.')->group(function() {
            Route::post('crypto/confirm/{trx_id}','cryptoPaymentConfirm')->name('crypto.confirm');
        });

    });

    // // Dashboard, Notification,
    Route::controller(DashboardController::class)->group(function(){
        Route::get("dashboard","dashboard");
        Route::get("notifications","notifications");
    });

    // // Transaction
    Route::controller(TransactionController::class)->prefix("transaction")->group(function(){
        Route::get("log","log");
    });

    //gift card
    Route::controller(GiftCardController::class)->prefix('gift-card')->group(function(){
        Route::get('/', 'index');
        Route::get('all', 'allGiftCard');
        Route::get('search/', 'searchGiftCard');
        Route::get('details', 'giftCardDetails');
        Route::post('order', 'orderPlace')->middleware('api.kyc');
    });


    Route::controller(MobileTopupController::class)->prefix('mobile-topup')->group(function(){
        //automatic method
        Route::prefix('automatic')->group(function(){
            Route::get('check-operator','checkOperator');
            Route::post('pay','payAutomatic')->middleware('api.kyc');
        });
    });

    //Bundle TopUp
    Route::controller(DataBundleController::class)->prefix('data-bundle')->name('data.bundle.')->group(function () {
        Route::get('get/operators', 'getReloadlyOperators');
        Route::post('get/bundle/charges', 'getCharges');
        Route::post('get/bundle/buy', 'buyBundle')->name('buy');
        // Route::post('bundle/webhook', 'receiveWebhook')->name('webhook')->withoutMiddleware(['web', 'auth', 'verification.guard', 'user.google.two.factor']);
    });

    // Peyflex Airtime Routes
    Route::controller(PeyflexAirtimeController::class)->prefix('peyflex/airtime')->name('peyflex.airtime.')->group(function(){
        Route::get('networks', 'getNetworks')->name('networks');
        Route::post('check-network', 'checkNetwork')->name('check');
        Route::post('purchase', 'purchase')->middleware('api.kyc')->name('purchase');
    });

    // Peyflex Data Routes
    Route::controller(PeyflexDataController::class)->prefix('peyflex/data')->name('peyflex.data.')->group(function(){
        Route::get('networks', 'getNetworks')->name('networks');
        Route::post('plans', 'getPlans')->name('plans');
        Route::post('calculate-charges', 'calculateCharges')->name('charges');
        Route::post('purchase', 'purchase')->middleware('api.kyc')->name('purchase');
    });
});

