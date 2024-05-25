<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
	public function notification(Request $request)
	{
		\Log::info('Payment notification received: ');
		$payload = $request->getContent();
		$notification = json_decode($payload, true);

		if (!$notification) {
			\Log::error('Invalid JSON');
			return response(['message' => 'Invalid JSON'], 400);
		}


		\Log::info('Notification payload: ' . json_encode($notification));

		if (!isset($notification['order_id']) || !isset($notification['status_code']) || !isset($notification['gross_amount'])) {
			\Log::error('Required fields are missing in JSON');
			return response(['message' => 'Required fields are missing in JSON'], 400);
		}

		$orderId = $notification['order_id'];
		$statusCode = $notification['status_code'];
		$grossAmount = $notification['gross_amount'];
		$receivedSignatureKey = $notification['signature_key'];

		$validSignatureKey = hash("sha512", $orderId . $statusCode . $grossAmount . 'SB-Mid-server-6EVFX174A0ka_IMGn8gg3Nsv');
		\Log::info('Generate Signature key: ' . $validSignatureKey);
		\Log::info('Received Signature key: ' . $receivedSignatureKey);

		if ($receivedSignatureKey != $validSignatureKey) {
			\Log::error('Invalid signature');
			return response(['message' => 'Invalid signature'], 403);
		}

		$this->initPaymentGateway();
		$statusCode = null;

		$paymentNotification = new \Midtrans\Notification();

		\Log::info('Transaction Status: ' . $paymentNotification->transaction_status);
		\Log::info('Transaction ID: ' . $paymentNotification->transaction_id);
		\Log::info('Payment Type: ' . $paymentNotification->payment_type);
		\Log::info('Fraud Status: ' . $paymentNotification->fraud_status);

		try {
			$order = Order::where('code', $paymentNotification->order_id)->firstOrFail();
		} catch (\Exception $e) {
			\Log::error('Order not found: ' . $paymentNotification->order_id);
			return response(['message' => 'Order not found'], 404);
		}

		if ($order->isPaid()) {
			\Log::info('Order already paid: ' . $order->id);
			return response(['message' => 'The order has been paid before'], 422);
		}

		$transaction = $paymentNotification->transaction_status;
		$type = $paymentNotification->payment_type;
		$orderId = $paymentNotification->order_id;
		$fraud = $paymentNotification->fraud_status;

		$vaNumber = null;
		$vendorName = null;
		if (!empty($paymentNotification->va_numbers[0])) {
			$vaNumber = $paymentNotification->va_numbers[0]->va_number;
			$vendorName = $paymentNotification->va_numbers[0]->bank;
		}

		$paymentStatus = null;
		if ($transaction == 'capture') {
			// For credit card transaction, we need to check whether transaction is challenge by FDS or not
			if ($type == 'credit_card') {
				if ($fraud == 'challenge') {
					// TODO set payment status in merchant's database to 'Challenge by FDS'
					// TODO merchant should decide whether this transaction is authorized or not in MAP
					$paymentStatus = Payment::CHALLENGE;
				} else {
					// TODO set payment status in merchant's database to 'Success'
					$paymentStatus = Payment::SUCCESS;
				}
			}
		} else if ($transaction == 'settlement') {
			// TODO set payment status in merchant's database to 'Settlement'
			$paymentStatus = Payment::SETTLEMENT;
		} else if ($transaction == 'pending') {
			// TODO set payment status in merchant's database to 'Pending'
			$paymentStatus = Payment::PENDING;
		} else if ($transaction == 'deny') {
			// TODO set payment status in merchant's database to 'Denied'
			$paymentStatus = PAYMENT::DENY;
		} else if ($transaction == 'expire') {
			// TODO set payment status in merchant's database to 'expire'
			$paymentStatus = PAYMENT::EXPIRE;
		} else if ($transaction == 'cancel') {
			// TODO set payment status in merchant's database to 'Denied'
			$paymentStatus = PAYMENT::CANCEL;
		}

		$paymentParams = [
			'order_id' => $order->id,
			'number' => Payment::generateCode(),
			'amount' => $paymentNotification->gross_amount,
			'method' => 'midtrans',
			'status' => $paymentStatus,
			'token' => $paymentNotification->transaction_id,
			'payloads' => $payload,
			'payment_type' => $paymentNotification->payment_type,
			'va_number' => $vaNumber,
			'vendor_name' => $vendorName,
			'biller_code' => $paymentNotification->biller_code,
			'bill_key' => $paymentNotification->bill_key,
		];

		try {
			$payment = Payment::create($paymentParams);
			\Log::info('Payment created: ' . $payment->id);
		} catch (\Exception $e) {
			\Log::error('Payment creation failed: ' . $e->getMessage());
			return response(['message' => 'Payment creation failed'], 500);
		}


		if ($paymentStatus && $payment) {
			\DB::transaction(
				function () use ($order, $payment) {
					if (in_array($payment->status, [Payment::SUCCESS, Payment::SETTLEMENT])) {
						$order->payment_status = Order::PAID;
						$order->status = Order::CONFIRMED;
						$order->save();
						\Log::info('Order updated to PAID and CONFIRMED: ' . $order->id);
					}
				}
			);
		}

		$message = 'Payment status is : ' . $paymentStatus;

		$response = [
			'code' => 200,
			'message' => $message,
		];

		return response($response, 200);
	}

	public function completed(Request $request)
	{
		$code = $request->query('order_id');
		$order = Order::where('code', $code)->firstOrFail();

		\Log::info('Completed payment check for order: ' . $order->id . ' with status: ' . $order->payment_status);

		if ($order->payment_status == Order::UNPAID) {
			return redirect('payments/failed?order_id=' . $code);
		}

		return view('frontend.payments.success');
	}

	public function failed(Request $request)
	{
		$code = $request->query('order_id');
		$order = Order::where('code', $code)->firstOrFail();

		\Log::info('Failed payment check for order: ' . $order->id);

		return redirect('orders/received/' . $order->id);
	}

	public function unfinish(Request $request)
	{
		$code = $request->query('order_id');
		$order = Order::where('code', $code)->firstOrFail();


		\Log::info('Unfinished payment check for order: ' . $order->id);

		return redirect('orders/received/' . $order->id);
	}
}
