<?php
/**
 * Created by PhpStorm.
 * User: elfuvo
 * Date: 17.12.19
 * Time: 13:09
 */

namespace tina\esia;

use tina\esia\exceptions\SignFailException;
use yii\log\Logger;

/**
 * Class EsiaGostOAuth2
 * @package app\modules\cabinet\clients
 */
class EsiaGostOAuth2 extends EsiaOAuth2
{
    /**
     * @var string
     */
    public $opensslBinPath = '/usr/local/gost/bin/openssl';

    /**
     * @param $message
     *
     * @return mixed
     * @throws SignFailException
     */
    protected function signPKCS7($message)
    {
        // random unique directories for sign
        $messageFile = $this->tmpPath . DIRECTORY_SEPARATOR . $this->getRandomString();
        $signFile = $this->tmpPath . DIRECTORY_SEPARATOR . $this->getRandomString();
        file_put_contents($messageFile, $message);

        $result = exec(
            $this->opensslBinPath . ' ' .
            'smime -sign ' .
            ' -engine gost ' .
            '-binary -outform DER -noattr ' .
            '-signer ' . escapeshellarg($this->certPath) . ' ' .
            '-inkey ' . escapeshellarg($this->privateKeyPath) . ' ' .
            ($this->privateKeyPassword ? '-passin ' . escapeshellarg('pass:' . $this->privateKeyPassword) . ' ' : '') .
            '-in ' . escapeshellarg($messageFile) . ' ' .
            '-out ' . escapeshellarg($signFile)
        );

        if (preg_match('#error#', $result)) {
            \Yii::getLogger()->log('SSH error: ' . $result, Logger::LEVEL_ERROR);
            throw new SignFailException(SignFailException::CODE_SIGN_FAIL);
        }
        $signed = file_get_contents($signFile);
        $sign = $this->urlSafe(base64_encode($signed));
        unlink($signFile);
        unlink($messageFile);

        return $sign;
    }
}
