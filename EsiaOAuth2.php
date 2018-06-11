<?php

namespace tina\esia;

use tina\esia\exceptions\SignFailException;
use yii\authclient\OAuth2;
use yii\helpers\Url;
use yii\httpclient\Request;
use yii\log\Logger;

/**
 * Class EsiaOAuth2
 *
 * @package tina\esia
 */
class EsiaOAuth2 extends OAuth2
{
    const STATUS_VERIFIED = 'VERIFIED';
    const TYPE_PHONE_MOBILE = 'MBT';
    const TYPE_EMAIL = 'EML';
    const ESIA_SUFFIX = '@esia.gosuslugi.ru';
    const CLIENT_NAME = 'esia';

    /**
     * @var string
     */
    public $portalUrl = '';

    /**
     * @var string
     */
    public $privateKeyPath = '';

    /**
     * @var string
     */
    public $privateKeyPassword = '';

    /**
     * @var string
     */
    public $certPath = '';

    /**
     * @var string
     */
    public $tmpPath = '';

    /**
     * @var array
     */
    public $scopes = [];

    /**
     * @var string
     */
    public $timestamp;

    /**
     * @var string
     */
    public $state;

    /**
     * @var string
     */
    public $codeUrl = 'aas/oauth2/ac';

    /**
     * @var string
     */
    public $tokenUrl = 'aas/oauth2/te';

    /**
     * @var string
     */
    public $personUrl = 'rs/prns';

    /**
     * @var string
     */
    public $responseType = 'code';

    /**
     * @var string
     */
    public $accessType = 'offline';

    /**
     * EsiaOAuth2 constructor.
     *
     * @param array $config
     *
     * @throws SignFailException
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->privateKeyPath = \Yii::getAlias($this->privateKeyPath);
        $this->certPath = \Yii::getAlias($this->certPath);
        $this->returnUrl = Url::to($this->returnUrl, true);
        $this->checkFilesExists();
    }

    /**
     * @param array $params
     *
     * @return bool|string
     * @throws SignFailException
     */
    public function buildAuthUrl(array $params = [])
    {
        $this->timestamp = $this->getTimeStamp();
        $this->state = $this->getOpenIdState();
        $scope = implode(' ', $this->scopes);
        $this->clientSecret = $scope . $this->timestamp . $this->clientId . $this->state;
        $this->clientSecret = $this->signPKCS7($this->clientSecret);
        if ($this->clientSecret === false) {
            return false;
        }
        $url = $this->getCodeUrl() . '?%s';
        $params = array_merge($params, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->getReturnUrl() . '?authclient=' . $this->id,
            'scope' => $scope,
            'response_type' => $this->responseType,
            'state' => $this->state,
            'access_type' => $this->accessType,
            'timestamp' => $this->timestamp,
        ]);
        $request = http_build_query($params);

        return sprintf($url, $request);
    }

    /**
     * @param string $authCode
     * @param array $params
     *
     * @return \yii\authclient\OAuthToken
     * @throws SignFailException
     * @throws \yii\base\Exception
     */
    public function fetchAccessToken($authCode, array $params = [])
    {
        $this->timestamp = $this->getTimeStamp();
        $this->state = $this->getOpenIdState();
        $scope = implode(' ', $this->scopes);
        $clientSecret = $this->signPKCS7($scope . $this->timestamp . $this->clientId . $this->state);
        if ($clientSecret === false) {
            throw new SignFailException(SignFailException::CODE_SIGN_FAIL);
        }
        $defaultParams = [
            'client_id' => $this->clientId,
            'grant_type' => 'authorization_code',
            'client_secret' => $clientSecret,
            'code' => $authCode,
            'state' => $this->state,
            'redirect_uri' => $this->getReturnUrl(),
            'scope' => $scope,
            'timestamp' => $this->timestamp,
            'token_type' => 'Bearer',
            'refresh_token' => $this->state,
        ];
        /** @var Request $request */
        $request = $this->createRequest()
            ->setUrl($this->getTokenUrl())
            ->setMethod('POST')
            ->setData(array_merge($defaultParams, $params));

        $response = $this->sendRequest($request);
        $token = $this->createToken(['params' => $response]);
        $this->setAccessToken($token);

        return $token;
    }

    /**
     * @return array
     * @throws \yii\base\Exception
     */
    protected function initUserAttributes()
    {
        $token = $this->getAccessToken()->getToken();

        $chunks = explode('.', (string)$token);
        if (empty($token) || count($chunks) < 2) {
            return [];
        }
        list(, $payload) = $chunks;
        $payload = json_decode($this->base64UrlSafeDecode($payload));

        $oid = $payload->{'urn:esia:sbj_id'};

        $userContacts = [];
        $email = ['address' => '', 'status' => ''];
        $phone = ['number' => '', 'status' => ''];
        $contacts = $this->api($this->getContactUrlByOid($oid));
        if (($contacts['size'] ?? 0) > 0) {
            foreach ($contacts['elements'] as $element) {
                $contact = $this->api($element);
                if ($contact && isset($contact['type'])) {
                    if ($contact['type'] == self::TYPE_EMAIL
                        && $email['status'] == self::STATUS_VERIFIED) {
                        $email['address'] = $contact['value'];
                        $email['status'] = $contact['vrfStu'];
                    }
                    if ($contact['type'] == self::TYPE_PHONE_MOBILE
                        && $phone['status'] != self::STATUS_VERIFIED) {
                        $phone['number'] = $contact['value'];
                        $phone['status'] = $contact['vrfStu'];
                    }
                    $userContacts[] = $contact;
                }
            }
        }
        $userInfo = $this->api($this->getPersonUrlByOid($oid));

        $userInfo['oid'] = $oid;
        $userInfo['contacts'] = $userContacts;
        $userInfo['login'] = ($email['address'] ? $email['address'] : $oid . self::ESIA_SUFFIX);
        $userInfo['phone'] = $phone['number'];

        return $userInfo;
    }

    /**
     * @param string $apiSubUrl
     * @param string $method
     * @param array|string $data
     * @param array $headers
     * @return array
     */
    public function api($apiSubUrl, $method = 'GET', $data = [], $headers = [])
    {

        $headers = array_merge([
            'Authorization' => 'Bearer ' . $this->getAccessToken()->getToken(),
        ], $headers);

        $request = $this->createApiRequest()
            ->setMethod($method)
            ->setUrl($apiSubUrl)
            ->addHeaders($headers);

        if (!empty($data)) {
            if (is_array($data)) {
                $request->setData($data);
            } else {
                $request->setContent($data);
            }
        }

        return $this->sendRequest($request);
    }

    /**
     * Url safe for base64
     *
     * @param string $string
     *
     * @return string
     */
    public function base64UrlSafeDecode($string)
    {
        $base64 = strtr($string, '-_', '+/');

        return base64_decode($base64);
    }

    /**
     * @param string $oid
     *
     * @return string
     */
    protected function getPersonUrlByOid($oid)
    {
        return $this->portalUrl . $this->personUrl . '/' . $oid;
    }

    /**
     * @param string $oid
     *
     * @return string
     */
    protected function getContactUrlByOid($oid)
    {
        return $this->portalUrl . $this->personUrl . '/' . $oid . '/ctts';
    }

    /**
     * Return an url for request to get token
     *
     * @return string
     */
    protected function getTokenUrl()
    {
        return $this->portalUrl . $this->tokenUrl;
    }

    /**
     * Return an url for request to get an authorization code
     *
     * @return string
     */
    protected function getCodeUrl()
    {
        return $this->portalUrl . $this->codeUrl;
    }

    /**
     * @param $message
     *
     * @return mixed
     * @throws SignFailException
     */
    protected function signPKCS7($message)
    {
        $certContent = file_get_contents($this->certPath);
        $keyContent = file_get_contents($this->privateKeyPath);
        $cert = openssl_x509_read($certContent);
        if ($cert === false) {
            throw new SignFailException(SignFailException::CODE_CANT_READ_CERT);
        }
        $privateKey = openssl_pkey_get_private($keyContent, $this->privateKeyPassword);
        if ($privateKey === false) {
            throw new SignFailException(SignFailException::CODE_CANT_READ_PRIVATE_KEY);
        }
        // random unique directories for sign
        $messageFile = $this->tmpPath . DIRECTORY_SEPARATOR . $this->getRandomString();
        $signFile = $this->tmpPath . DIRECTORY_SEPARATOR . $this->getRandomString();
        file_put_contents($messageFile, $message);
        $signResult = openssl_pkcs7_sign(
            $messageFile,
            $signFile,
            $cert,
            $privateKey,
            []
        );
        if (!$signResult) {
            \Yii::getLogger()->log('SSH error: ' . openssl_error_string(), Logger::LEVEL_ERROR);
            throw new SignFailException(SignFailException::CODE_SIGN_FAIL);
        }
        $signed = file_get_contents($signFile);
        # split by section
        $signed = explode("\n\n", $signed);
        # get third section which contains sign and join into one line
        $sign = str_replace("\n", "", $this->urlSafe($signed[3]));
        unlink($signFile);
        unlink($messageFile);

        return $sign;
    }

    /**
     * @throws SignFailException
     */
    protected function checkFilesExists()
    {
        if (!file_exists($this->certPath)) {
            throw new SignFailException(
                SignFailException::CODE_NO_SUCH_CERT_FILE,
                'certPath: ' . $this->certPath
            );
        }
        if (!file_exists($this->privateKeyPath)) {
            throw new SignFailException(
                SignFailException::CODE_NO_SUCH_KEY_FILE,
                'privateKeyPath: ' . $this->privateKeyPath
            );
        }
        if (!file_exists($this->tmpPath)) {
            throw new SignFailException(
                SignFailException::CODE_NO_TEMP_DIRECTORY,
                'tmpPath: ' . $this->tmpPath
            );
        }
    }

    /**
     * Url safe for base64
     *
     * @param string $string
     *
     * @return string
     */
    protected function urlSafe($string)
    {
        return rtrim(strtr(trim($string), '+/', '-_'), '=');
    }

    /**
     * @return string
     */
    protected function getTimeStamp()
    {
        return date("Y.m.d H:i:s O");
    }

    /**
     * @return string
     */
    protected function getOpenIdState()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * @return string
     */
    protected function getRandomString()
    {
        return md5(uniqid(mt_rand(), true));
    }

    /**
     * @return array
     */
    protected function defaultNormalizeUserAttributeMap()
    {
        return [
            'id' => 'oid',
        ];
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        return 'ЕСИА';
    }

    /**
     * @return string
     */
    public function getName()
    {
        return static::CLIENT_NAME;
    }
}
