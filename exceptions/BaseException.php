<?php
/**
 * Created by PhpStorm.
 * User: alfred
 * Date: 16.03.18
 * Time: 8:42
 */

namespace tina\esia\exceptions;

/**
 * Class BaseException
 *
 * @package tina\esia\exceptions
 */
class BaseException extends \Exception
{
    /**
     * @var array
     */
    protected static $codeLabels = [];

    /**
     * BaseException constructor.
     *
     * @param int $code
     * @param string $message
     * @param \Exception|null $previous
     */
    public function __construct($code = 0, $message = '', \Exception $previous = null)
    {
        if (isset(static::$codeLabels[$code])) {
            $codeMessage = static::$codeLabels[$code];
        } else {
            $codeMessage = 'Unknown error';
        }
        if ($message) {
            $codeMessage .= "\n" . $message;
        }
        parent::__construct($codeMessage, $code, $previous);
    }
}
