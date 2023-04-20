<?php

namespace Drupal\seven_sms\Plugin\SmsGateway;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\sms\Direction;
use Drupal\sms\Entity\SmsGatewayInterface;
use Drupal\sms\Message\SmsDeliveryReport;
use Drupal\sms\Message\SmsMessage;
use Drupal\sms\Message\SmsMessageInterface;
use Drupal\sms\Message\SmsMessageReportStatus;
use Drupal\sms\Message\SmsMessageResult;
use Drupal\sms\Message\SmsMessageResultStatus;
use Drupal\sms\Plugin\SmsGatewayPluginBase;
use Drupal\sms\SmsProcessingResponse;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @SmsGateway(
 *   credit_balance_available = TRUE,
 *   id = "seven",
 *   incoming = TRUE,
 *   incoming_route = TRUE,
 *   label = @Translation("Seven"),
 *   outgoing_message_max_recipients = 10000,
 *   reports_pull = TRUE,
 *   reports_push = TRUE,
 * )
 */
class Seven extends SmsGatewayPluginBase implements ContainerFactoryPluginInterface {
    /** @var Client $httpClient */
    protected $httpClient;
    /** @var MessengerInterface $messenger */
    protected $messenger;

    /** {@inheritdoc} */
    public function __construct(
        array $configuration,
        $plugin_id,
        $plugin_definition,
        MessengerInterface $messenger,
        Client $client
    ) {
        parent::__construct($configuration, $plugin_id, $plugin_definition);

        $this->messenger = $messenger;
        $this->httpClient = $client;
    }

    /** {@inheritdoc} */
    public static function create(
        ContainerInterface $container,
        array $configuration,
        $plugin_id,
        $plugin_definition
    ) {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->get('messenger'),
            $container->get('http_client')
        );
    }

    /** {@inheritdoc} */
    public function defaultConfiguration() {
        return ['api_key' => '', 'from' => ''];
    }

    /** {@inheritdoc} */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
        $form = parent::buildConfigurationForm($form, $form_state);
        $cfg = $this->getConfiguration();

        $form['seven'] = [
            '#open' => true,
            '#title' => 'seven',
            '#type' => 'details',
        ];

        $form['seven']['help'] = [
            '#tag' => 'p',
            '#type' => 'html_tag',
            '#value' => $this->t('API keys can be found at 
<a href="https://app.seven.io/developer">Dashboard->Developer</a>.'),
        ];

        $form['seven']['api_key'] = [
            '#default_value' => $cfg['api_key'],
            '#required' => true,
            '#title' => $this->t('API Key'),
            '#type' => 'textfield',
        ];

        $form['seven']['from'] = [
            '#default_value' => $cfg['from'],
            '#title' => t('Sender name'),
            '#type' => 'textfield',
        ];

        return $form;
    }

    /** {@inheritdoc} */
    public function submitConfigurationForm(
        array &$form, FormStateInterface $form_state) {
        $this->configuration['api_key'] = trim($form_state->getValue('api_key'));

        $this->configuration['from'] = $form_state->getValue('from');
    }

    public function send(SmsMessageInterface $sms) {
        $text = $sms->getMessage();
        $to = implode(',', $sms->getRecipients());
        $result = new SmsMessageResult;

        if (mb_strlen($text) > 1520) return $result->addReport((new SmsDeliveryReport)
            ->setRecipient($to)
            ->setStatus(SmsMessageReportStatus::CONTENT_INVALID)
            ->setStatusMessage((string)$this->t(
                'Maximum message length is 1520 characters.')));

        try {
            $response = $this->request('post', 'sms', [
                'from' => $this->configuration['from'],
                'json' => 1,
                'to' => $to,
                'text' => $text,
            ]);
        } catch (RequestException $e) {
            return $result
                ->setError(SmsMessageResultStatus::ERROR)
                ->setErrorMessage($e->getMessage());
        }

        $resultError = SmsMessageResultStatus::ERROR;
        $resultErrorMessage = 'UNHANDLED_ERROR';
        switch ((int)$response['success']) {
            case 100:
                $resultError = null;
                $resultErrorMessage = 'DELIVERED';
                break;
            case 101:
                $resultError = null;
                $resultErrorMessage = 'PARTIALLY_DELIVERED';
                break;
            case 201:
                $resultError = SmsMessageResultStatus::INVALID_SENDER;
                $resultErrorMessage = 'INVALID_SENDER';
                break;
            case 202:
            case 301:
                $resultErrorMessage = 'INVALID_RECIPIENT';
                $resultError = SmsMessageResultStatus::PARAMETERS;
                break;
            case 305:
                $resultErrorMessage = 'CONTENT_INVALID';
                $resultError = SmsMessageResultStatus::PARAMETERS;
                break;
            case 401:
                $resultErrorMessage = 'CONTENT_TOO_LONG';
                $resultError = SmsMessageResultStatus::PARAMETERS;
                break;
            case 402:
                $resultError = SmsMessageResultStatus::PARAMETERS;
                $resultErrorMessage = 'PREVENTED_BY_RELOAD_LOCK';
                break;
            case 403:
                $resultError = SmsMessageResultStatus::EXCESSIVE_REQUESTS;
                $resultErrorMessage = 'DAILY_NUMBER_LIMIT_REACHED';
                break;
            case 500:
                $resultError = SmsMessageResultStatus::NO_CREDIT;
                $resultErrorMessage = 'INSUFFICIENT_BALANCE';
                break;
            case 600:
                $resultError = SmsMessageResultStatus::ERROR;
                $resultErrorMessage = 'CARRIER_DELIVERY_FAILED';
                break;
            case 700:
                $resultError = SmsMessageResultStatus::ERROR;
                $resultErrorMessage = 'UNKNOWN_ERROR';
                break;
            case 900:
                $resultError = SmsMessageResultStatus::AUTHENTICATION;
                $resultErrorMessage = 'AUTHENTICATION_ERROR';
                break;
            case 903:
                $resultError = SmsMessageResultStatus::ACCOUNT_ERROR;
                $resultErrorMessage = 'SERVER_IP_IS_WRONG';
                break;
        }

        foreach ($response['messages'] as $msg) {
            if (202 === $msg['error'] || 301 === $msg['error'])
                $status = SmsMessageReportStatus::INVALID_RECIPIENT;
            elseif (305 === $msg['error'] || 401 === $msg['error'])
                $status = SmsMessageReportStatus::CONTENT_INVALID;
            elseif (402 === $msg['error'])
                $status = SmsMessageReportStatus::REJECTED;
            elseif (403 === $msg['error'])
                $status = SmsMessageReportStatus::REJECTED;
            elseif (500 === $msg['error'])
                $status = SmsMessageReportStatus::REJECTED;
            elseif (600 === $msg['error'])
                $status = SmsMessageReportStatus::REJECTED;
            elseif ($msg['success']) $status = SmsMessageReportStatus::QUEUED;
            else $status = SmsMessageReportStatus::ERROR;

            $recipient = (string)$msg['recipient'];
            $prefixRecipient = "+$recipient";
            if (false !== strpos($to, $prefixRecipient)) $recipient = $prefixRecipient;

            $result->addReport((new SmsDeliveryReport)
                ->setMessageId($msg['id'])
                ->setRecipient($recipient)
                ->setStatus($status)
                ->setStatusMessage(null === $msg['error_text'] ? '' : $msg['error_text'])
            );
        }

        return $result
            ->setCreditsBalance($response['balance'])
            ->setCreditsUsed($response['total_price'])
            ->setError($resultError)
            ->setErrorMessage($resultErrorMessage);
    }

    private function request($method, $endpoint, $json = null) {
        return Json::decode($this->httpClient->request('post',
            "https://gateway.seven.io/api/$endpoint", [
                'connect_timeout' => 10,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'SentWith' => 'drupal-sms-framework',
                    'X-Api-Key' => $this->configuration['api_key'],
                ],
                'json' => $json,
            ])->getBody());
    }

    public function getCreditBalance() {
        return $this->request('get', 'balance');
    }

    /**
     * Process an incoming message POST request.
     * seven passes only one message per request.
     * API documentation:
     * https://app.seven.io/settings#inboundsms
     * @param Request $request
     *   The request object.
     * @param SmsGatewayInterface $sms_gateway
     *   The gateway instance.
     * @return SmsProcessingResponse
     *   A SMS processing response task.
     */
    function processIncoming(Request $request, SmsGatewayInterface $sms_gateway) {
        $json = Json::decode($request->getContent());
        $res = (new SmsProcessingResponse)->setResponse(new Response('', 204));

        if ('sms_mo' === $json['webhook_event'])
            $res->setMessages([(new SmsMessage)
                ->setDirection(Direction::INCOMING)
                ->setGateway($sms_gateway)
                ->setMessage($json['data']['text'])
                ->setResult((new SmsMessageResult)->setReports([(new SmsDeliveryReport)
                    ->setMessageId((string)$json['data']['id'])
                    ->setTimeDelivered(DrupalDateTime::createFromTimestamp(
                        (int)$json['data']['time'])->format('U'))]))]);

        return $res;
    }
}
