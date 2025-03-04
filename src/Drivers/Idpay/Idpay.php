<?php

namespace Shetabit\Multipay\Drivers\Idpay;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Idpay extends Driver
{
    /**
     * Idpay Client.
     *
     * @var object
     */
    protected \GuzzleHttp\Client $client;

    /**
     * Invoice
     *
     * @var Invoice
     */
    protected $invoice;

    /**
     * Driver settings
     *
     * @var object
     */
    protected $settings;

    /**
     * Idpay constructor.
     * Construct the class with the relevant settings.
     *
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase()
    {
        $details = $this->invoice->getDetails();

        $phone = null;
        if (!empty($details['phone'])) {
            $phone = $details['phone'];
        } elseif (!empty($details['mobile'])) {
            $phone = $details['mobile'];
        }

        $mail = null;
        if (!empty($details['mail'])) {
            $mail = $details['mail'];
        } elseif (!empty($details['email'])) {
            $mail = $details['email'];
        }

        $desc = $this->settings->description;
        if (!empty($details['desc'])) {
            $desc = $details['desc'];
        } elseif (!empty($details['description'])) {
            $desc = $details['description'];
        }

        $data = [
            'order_id' => $this->invoice->getUuid(),
            'amount' => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
            'name' => $details['name'] ?? null,
            'phone' => $phone,
            'mail' => $mail,
            'desc' => $desc,
            'callback' => $this->settings->callbackUrl,
            'reseller' => $details['reseller'] ?? null,
        ];

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    "json" => $data,
                    "headers" => [
                        'X-API-KEY' => $this->settings->merchantId,
                        'Content-Type' => 'application/json',
                        'X-SANDBOX' => (int) $this->settings->sandbox,
                    ],
                    "http_errors" => false,
                ]
            );

        $body = json_decode($response->getBody()->getContents(), true);
        if (empty($body['id'])) {
            // error has happened
            $message = $body['error_message'] ?? 'خطا در هنگام درخواست برای پرداخت رخ داده است.';
            throw new PurchaseFailedException($message);
        }

        $this->invoice->transactionId($body['id']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     */
    public function pay() : RedirectionForm
    {
        $apiUrl = $this->settings->apiPaymentUrl;

        // use sandbox url if we are in sandbox mode
        if (!empty($this->settings->sandbox)) {
            $apiUrl = $this->settings->apiSandboxPaymentUrl;
        }

        $payUrl = $apiUrl.$this->invoice->getTransactionId();

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @return mixed|void
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify() : ReceiptInterface
    {
        $data = [
            'id' => $this->invoice->getTransactionId() ?? Request::input('id'),
            'order_id' => Request::input('order_id'),
        ];

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            [
                'json' => $data,
                "headers" => [
                    'X-API-KEY' => $this->settings->merchantId,
                    'Content-Type' => 'application/json',
                    'X-SANDBOX' => (int) $this->settings->sandbox,
                ],
                "http_errors" => false,
            ]
        );
        $body = json_decode($response->getBody()->getContents(), true);

        if (isset($body['error_code']) || $body['status'] != 100) {
            $errorCode = $body['status'] ?? $body['error_code'];

            $this->notVerified($errorCode);
        }

        $receipt = $this->createReceipt($body['track_id']);
        $receipt->detail($body);

        return $receipt;
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     */
    protected function createReceipt($referenceId): \Shetabit\Multipay\Receipt
    {
        return new Receipt('idpay', $referenceId);
    }

    /**
     * Trigger an exception
     *
     * @param $status
     *
     * @throws InvalidPaymentException
     */
    private function notVerified($status): void
    {
        $translations = [
            "1" => "پرداخت انجام نشده است.",
            "2" => "پرداخت ناموفق بوده است.",
            "3" => "خطا رخ داده است.",
            "4" => "بلوکه شده.",
            "5" => "برگشت به پرداخت کننده.",
            "6" => "برگشت خورده سیستمی.",
            "10" => "در انتظار تایید پرداخت.",
            "100" => "پرداخت تایید شده است.",
            "101" => "پرداخت قبلا تایید شده است.",
            "200" => "به دریافت کننده واریز شد.",
            "11" => "کاربر مسدود شده است.",
            "12" => "API Key یافت نشد.",
            "13" => "درخواست شما از {ip} ارسال شده است. این IP با IP های ثبت شده در وب سرویس همخوانی ندارد.",
            "14" => "وب سرویس تایید نشده است.",
            "21" => "حساب بانکی متصل به وب سرویس تایید نشده است.",
            "31" => "کد تراکنش id نباید خالی باشد.",
            "32" => "شماره سفارش order_id نباید خالی باشد.",
            "33" => "مبلغ amount نباید خالی باشد.",
            "34" => "مبلغ amount باید بیشتر از {min-amount} ریال باشد.",
            "35" => "مبلغ amount باید کمتر از {max-amount} ریال باشد.",
            "36" => "مبلغ amount بیشتر از حد مجاز است.",
            "37" => "آدرس بازگشت callback نباید خالی باشد.",
            "38" => "درخواست شما از آدرس {domain} ارسال شده است. دامنه آدرس بازگشت callback با آدرس ثبت شده در وب سرویس همخوانی ندارد.",
            "51" => "تراکنش ایجاد نشد.",
            "52" => "استعلام نتیجه ای نداشت.",
            "53" => "تایید پرداخت امکان پذیر نیست.",
            "54" => "مدت زمان تایید پرداخت سپری شده است.",
        ];
        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status], (int)$status);
        }
        throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.', (int)$status);
    }
}
