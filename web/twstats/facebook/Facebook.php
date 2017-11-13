<?php
/**
 * Created by PhpStorm.
 * User: Steffen
 * Date: 13/11/2017
 * Time: 11:27
 */

namespace TwStats\Ext\Facebook;

use Facebook\Exceptions\FacebookResponseException;
use Facebook\Exceptions\FacebookSDKException;
use TwStats\Core\Backend\Database;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Core\Utility\SingletonInterface;


class Facebook implements SingletonInterface
{
    /**
     * @var \Facebook\Facebook|null
     */
    protected $facebook = null;

    /**
     * @var Database|null
     */
    protected $databaseConnection = null;

    /**
     * Facebook constructor.
     */
    public function __construct()
    {
        $this->facebook = new \Facebook\Facebook(array(
            'app_id' => getenv('TWSTATS_FACEBOOK_APP_ID'),
            'app_secret' => getenv('TWSTATS_FACEBOOK_APP_SECRET'),
        ));
        $this->databaseConnection = GeneralUtility::makeInstance(Database::class);
    }

    /**
     * get the facebook id from the facebook sdk and optionally verify this id
     *
     * @param bool $verify
     * @return int|string
     */
    public function getFacebookID($verify = false)
    {
        # Facebook PHP SDK v5: Check Login Status Example
        $helper = $this->facebook->getCanvasHelper();

        // Grab the signed request entity
        $sr = $helper->getSignedRequest();

        // Get the user ID if signed request exists
        $user = $sr ? $sr->getUserId() : 0;

        if ($user && $verify) {
            try {
                // Get the access token
                $accessToken = $helper->getAccessToken();
                // Returns a `Facebook\FacebookResponse` object
                $this->facebook->get('/me?fields=id,name', $accessToken);
            } catch (FacebookResponseException $e) {
                // echo 'Graph returned an error: ' . $e->getMessage();
                $user = 0;
            } catch (FacebookSDKException $e) {
                // echo 'Facebook SDK returned an error: ' . $e->getMessage();
                $user = 0;
            }
        }
        return $user;
    }

    /**
     * get the corresponding account to the facebook id
     *
     * @param int $facebookId
     * @return array
     */
    function getAccountDetails($facebookId)
    {
        $req = $this->databaseConnection->sqlquery("SELECT * FROM accounts WHERE facebookid = ?", array($facebookId));
        return $this->databaseConnection->sqlfetch($req);
    }

}