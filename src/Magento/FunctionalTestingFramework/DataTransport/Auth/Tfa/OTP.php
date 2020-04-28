<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\FunctionalTestingFramework\DataTransport\Auth\Tfa;

use Magento\FunctionalTestingFramework\Exceptions\TestFrameworkException;
use OTPHP\TOTP;

/**
 * Class OTP
 */
class OTP
{
    /**
     * TOTP object
     *
     * @var TOTP
     */
    private static $totp = null;

    /**
     * Return OTP for custom secret `OTP_SHARED_SECRET` defined in env
     *
     * @return string
     * @throws TestFrameworkException
     */
    public static function getOTP()
    {
        return self::create()->now();
    }

    /**
     * Create TOTP object
     *
     * @return TOTP
     * @throws TestFrameworkException
     */
    private static function create()
    {
        if (self::$totp === null) {
            $secret = getenv('OTP_SHARED_SECRET');
            if (!$secret) {
                throw new TestFrameworkException(
                    'Unable to get OTP, please make sure environment variable "OTP_SHARED_SECRET" is set.'
                );
            }

            self::$totp = TOTP::create($secret);
            self::$totp->setIssuer('MFTF');
            self::$totp->setLabel('MFTF Testing');
        }
        return self::$totp;
    }
}
