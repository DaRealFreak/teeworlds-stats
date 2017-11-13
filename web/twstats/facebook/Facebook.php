<?php
/**
 * Created by PhpStorm.
 * User: Steffen
 * Date: 13/11/2017
 * Time: 11:27
 */

namespace TwStats\Ext\Facebook;

use TwStats\Core\Utility\SingletonInterface;


class Facebook implements SingletonInterface
{
    /**
     * @var \Facebook\Facebook|null
     */
    protected $facebook = null;

    /**
     * Facebook constructor.
     */
    public function __construct()
    {
        // ToDo: extract to .env file
        $this->facebook = new \Facebook\Facebook(array(
            'app_id' => '133904623933394',
            'app_secret' => '39b31cc4a948b8494fc3e435d42061ad',
        ));
    }

}