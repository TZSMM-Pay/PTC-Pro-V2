<?php

namespace App\Services\Gateway\TZSMMPAY;

use App\Models\Deposit;
use Facades\App\Services\BasicService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Payment
{
public static function prepareData($deposit, $gateway)
    {


        $basic = basicControl();
        $credentials = $gateway->parameters;


        $paymentData = [
            'api_key' => $credentials->apiKey,
            'cus_name' => $deposit->user->firstname . ' ' . $deposit->user->lastname,
            'cus_email' => 'demo@gmail.com',
            'cus_number' => 017000000,
            'amount' => $deposit->payable_amount,
            'currency' => $deposit->method_currency,
            'success_url' =>  route('success'),
            'cancel_url' => route('failed'),
            'callback_url' => route('ipn', ['code' => $gateway->code, 'trx' => $deposit->trx_id]), 
            'currency' => $deposit->payment_method_currency,
        ];

        $url = 'https://tzsmmpay.com/api/payment/create';
        $options = [
            'http' => [
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($paymentData),
            ],
        ];


        try {
            $context = stream_context_create($options);
            $response = file_get_contents($url, false, $context);
            $responseData = json_decode($response, true);

            $send = [];
            if ($responseData && $responseData['success']) {
                $send['redirect'] = true;
                $send['redirect_url'] = $responseData['payment_url'];

              
            } else {
                $send['error'] = true;
                $send['message'] = $responseData['messages'] ?? 'An error occurred during payment initiation.';

            }
        } catch (\Exception $e) {

            $send['error'] = true;
            $send['message'] =  $e->getMessage();

        }


        return json_encode($send);
    }
    public static function ipn($request, $gateway, $deposit = null, $trx = null, $type = null)
    {
        
        
         if (!$deposit) {
                $data['status'] = 'error';
                $data['msg'] = 'Deposit not found for track number: ' . $request->cus_number;
                $data['redirect'] = route('failed');
                return $data;
            }
            
                   $credentials = $gateway->parameters;

        $data = [];

        try {
            // Validate the request inputs
            $validator = \Validator::make($request->all(), [
                'amount' => 'required|numeric',
                'cus_name' => 'required',
                'cus_email' => 'required|email',
                'cus_number' => 'required',
                'trx_id' => 'required',
                'status' => 'required',
                'extra' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                $data['status'] = 'error';
                $data['msg'] = implode(', ', $validator->errors()->all());
                return $data;
            }

           

            // Check the payment status
            if ($request->status === 'Completed') {
                // Get credentials
                $credentials = $gateway->parameters;
                if (!isset($credentials->apiKey)) {
                   
                    $data['status'] = 'error';
                    $data['msg'] = 'Invalid gateway configuration: API key missing.';
                } else {
                 
                    $url = "https://tzsmmpay.com/api/payment/verify?api_key={$credentials->apiKey}&trx_id={$request->trx_id}";

            
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $response = curl_exec($ch);
                    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
            
      
            
                    if ($response === false || $http_status !== 200) {

                        $data['status'] = 'error';
                        $data['msg'] = 'Error verifying transaction with TZSMMPAY API.';
                    } else {
                        $result = json_decode($response, true);
                        if (isset($result['status']) && $result['status'] === 'Completed') {
           
                            BasicService::preparePaymentUpgradation($deposit);
            
                            $data['status'] = 'success';
                            $data['msg'] = 'Transaction was successful.';
                        } else {
                            Log::error('TZSMMPAY IPN verification status not completed', [
                                'verification_status' => $result['status'] ?? 'unknown',
                                'deposit_id' => $deposit->id,
                                'result' => $result,
                                'request' => $request->all(),
                                'trx' => $deposit->trx,
                            ]);
                            $data['status'] = 'error';
                            $data['msg'] = 'Transaction verification failed: ' . ($result['message'] ?? 'Invalid status.' . $result);
                        }
                    }
                }
            } else {
               
                $data['status'] = 'error';
                $data['msg'] = 'Payment status not completed.';
            }
        } catch (\Exception $e) {
            $data['status'] = 'error';
            $data['msg'] = 'An error occurred: ' . $e->getMessage();
        }

        return $data;
    }
}
