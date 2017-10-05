<?php

namespace Sfadless\Sms\Provider;

use Sfadless\Sms\Message\Message;
use Sfadless\Sms\Message\Status;
use Sfadless\Utils\Http\Http;

/**
 * SmsRu
 *
 * @author Pavel Golikov <pgolikov327@gmail.com>
 */
class SmsRu implements SmsProviderInterface
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var boolean
     */
    protected $test;

    /**
     * @var string
     */
    protected $url;

    /**
     * @var string
     */
    protected $ipId;

    /**
     * @var Http
     */
    protected $http;

    /**
     * @var array
     */
    protected $responseCodes = [
        '-1' => 'Сообщение не найдено',
        '100' => 'Запрос выполнен или сообщение находится в нашей очереди',
        '101' => 'Сообщение передается оператору',
        '102' => 'Сообщение отправлено (в пути)',
        '103' => 'Сообщение доставлено',
        '104' => 'Не может быть доставлено: время жизни истекло',
        '105' => 'Не может быть доставлено: удалено оператором',
        '106' => 'Не может быть доставлено: сбой в телефоне',
        '107' => 'Не может быть доставлено: неизвестная причина',
        '108' => 'Не может быть доставлено: отклонено',
        '110' => 'Сообщение прочитано',
        '200' => 'Неправильный api_id',
        '201' => 'Не хватает средств на лицевом счету',
        '202' => 'Неправильно указан номер телефона получателя, либо на него нет маршрута',
        '203' => 'Нет текста сообщения',
        '204' => 'Имя отправителя не согласовано с администрацией',
        '205' => 'Сообщение слишком длинное (превышает 8 СМС)',
        '206' => 'Будет превышен или уже превышен дневной лимит на отправку сообщений',
        '207' => 'На этот номер нет маршрута для доставки сообщений',
        '208' => 'Параметр time указан неправильно',
        '209' => 'Вы добавили этот номер (или один из номеров) в стоп-лист',
        '210' => 'Используется GET, где необходимо использовать POST',
        '211' => 'Метод не найден',
        '212' => 'Текст сообщения необходимо передать в кодировке UTF-8 (вы передали в другой кодировке)',
        '213' => 'Указано более 100 номеров в списке получателей',
        '220' => 'Сервис временно недоступен, попробуйте чуть позже',
        '230' => 'Превышен общий лимит количества сообщений на этот номер в день',
        '231' => 'Превышен лимит одинаковых сообщений на этот номер в минуту',
        '232' => 'Превышен лимит одинаковых сообщений на этот номер в день',
        '300' => 'Неправильный token (возможно истек срок действия, либо ваш IP изменился)',
        '301' => 'Неправильный пароль, либо пользователь не найден',
        '302' => 'Пользователь авторизован, но аккаунт не подтвержден (пользователь не ввел код, присланный в регистрационной смс)',
        '303' => 'Код подтверждения неверен',
        '304' => 'Отправлено слишком много кодов подтверждения. Пожалуйста, повторите запрос позднее',
        '305' => 'Слишком много неверных вводов кода, повторите попытку позднее',
        '500' => 'Ошибка на сервере. Повторите запрос',
        '901' => 'Callback: URL неверный (не начинается на http://)',
        '902' => 'Callback: Обработчик не найден (возможно был удален ранее)',
    ];

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getBalance()
    {
        $response = $this->request('my/balance');

        if (!isset($response['balance'])) {
            throw new SmsRuException('Failed get balance. Message: ' . $this->responseCodes[$response['status_code']]);
        }

        return (float) $response['balance'];
    }

    /**
     * {@inheritdoc}
     */
    public function getSmsCost(Message $message)
    {
        $response = $this->request('sms/cost', [
            'to' => $message->getPhone()->getNumber(),
            'msg' => $message->getText()
        ]);

        return (float) $response['total_cost'];
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus(Message $message)
    {
        try {
            $response = $this->request('sms/status', [
                'sms_id' => $message->getSmsId(),
            ]);

            $status = $response['sms'][$message->getSmsId()];
            $message->setCost((float) $status['cost']);
            $status = new Status($status['status_code'], $this->responseCodes[$status['status_code']]);
            $status->setState($status->getCode() == '103' ? 'sent' : 'processing');
            $message->setStatus($status);
            $message->setProvider($this);

            return $message;
        } catch (SmsRuException $e) {
            return $e->getMessage();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function send(Message $message)
    {
        try {
            $message->setCost($this->test ? 0 : $this->getSmsCost($message));

            $response = $this->request('sms/send', [
                'to' => $message->getPhone()->getNumber(),
                'msg' => $message->getText(),
                'test' => $this->test ? 1 : 0
            ]);

            $status = new Status('100', $this->responseCodes['100'], false);
            $status->setState('processing');

            $message
                ->setStatus($status)
                ->setSmsId($response['sms'][$message->getPhone()->getNumber()]['sms_id'])
                ->setProvider($this)
            ;

            return $message;
        } catch (SmsRuException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @return bool
     * @throws SmsRuException
     */
    public function checkIpId()
    {
        $response = $this->request('auth/check');

        if ((int) $response["status_code"] !== 100) {
            throw new SmsRuException('Wrong ip_ip');
        }

        return true;
    }

    /**
     * SmsRu constructor.
     * @param $ipId string
     * @param $test boolean
     */
    public function __construct($ipId, $test = false)
    {
        $this->name = 'Sms-ru';
        $this->ipId = (string) $ipId;
        $this->url = 'https://sms.ru/';
        $this->http = new Http();
        $this->test = !!$test;

        $this->checkIpId();
    }

    /**
     * @param $method
     * @param array $params
     * @return mixed
     * @throws SmsRuException
     */
    protected function request($method, $params = [])
    {
        $params = array_merge($params, [
            'json' => 1,
            'api_id' => $this->ipId
        ]);

        $url = $this->url . $method;

        $response = $this->http->post($url, $params);
        $response = json_decode($response, true);

        if (!$response) {
            throw new SmsRuException('cant get response from ' . $this->url);
        }

        if ($response['status_code'] !== 100) {
            throw new SmsRuException('Error: ' . $this->responseCodes[$response['status_code']]);
        }

        return $response;
    }
}