<?php
/**
 * Created by PhpStorm.
 * User: alfred
 * Date: 15.03.18
 * Time: 19:31
 */

namespace tina\esia\widgets;

use tina\esia\EsiaOAuth2;
use Yii;
use yii\base\Widget;
use yii\log\Logger;

/**
 * Class EsiaAuthWidget
 *
 * @package app\modules\esia\widgets
 */
class AuthWidget extends Widget
{
    /** @var string */
    public $name = 'Авторизация ЕСИА';

    /** @var string $template */
    public $template = 'button';

    /** @inheritdoc */
    public function run()
    {
        parent::run();

        if (!Yii::$app->user->getIsGuest()) {
            return '';
        }

        return $this->renderButton();
    }

    /**
     * @return string
     */
    public function renderButton()
    {
        try {
            /** @var EsiaOAuth2 $esia */
            $esia = \Yii::$app->get('authClientCollection')->getClient(EsiaOAuth2::CLIENT_NAME);
            $authUrl = $esia->buildAuthUrl();
        } catch (\Exception $ex) {
            $authUrl = '#';
            \Yii::getLogger()->log(
                'Message: ' . $ex->getMessage()
                . "\nLine: " . $ex->getLine()
                . "\nFile: " . $ex->getFile(),
                Logger::LEVEL_ERROR
            );
        }

        return $this->render($this->template, ['url' => $authUrl, 'name' => $this->name]);
    }
}
